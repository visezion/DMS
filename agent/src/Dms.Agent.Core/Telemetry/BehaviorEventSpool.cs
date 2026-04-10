using System.Text.Json;
using Dms.Agent.Core.Transport;

namespace Dms.Agent.Core.Telemetry;

public sealed class BehaviorEventSpool
{
    private readonly string _statePath;
    private readonly JsonSerializerOptions _jsonOptions = new()
    {
        WriteIndented = false,
        PropertyNameCaseInsensitive = true,
    };

    public BehaviorEventSpool()
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
        string telemetryDir = Path.Combine(programData, "DMS", "Telemetry");
        Directory.CreateDirectory(telemetryDir);
        _statePath = Path.Combine(telemetryDir, "behavior-spool.json");
    }

    public void Enqueue(IReadOnlyList<BehaviorEventDto> events)
    {
        if (events.Count == 0)
        {
            return;
        }

        var state = ReadState();
        foreach (var item in events)
        {
            if (!string.IsNullOrWhiteSpace(item.EventUid) && state.Events.Any(x => string.Equals(x.EventUid, item.EventUid, StringComparison.OrdinalIgnoreCase)))
            {
                continue;
            }

            state.Events.Add(item);
        }

        if (state.Events.Count > 2000)
        {
            state.Events = state.Events
                .OrderByDescending(x => x.OccurredAt)
                .Take(2000)
                .OrderBy(x => x.OccurredAt)
                .ToList();
        }

        WriteState(state);
    }

    public async Task<int> FlushAsync(ApiClient apiClient, CancellationToken cancellationToken)
    {
        var state = ReadState();
        if (state.Events.Count == 0)
        {
            return 0;
        }

        if (state.NextAttemptUtc.HasValue && state.NextAttemptUtc.Value > DateTimeOffset.UtcNow)
        {
            return 0;
        }

        int batchSize = Math.Clamp(ResolveInt("DMS_BEHAVIOR_UPLOAD_BATCH_SIZE", 150), 10, 500);
        var batch = state.Events.Take(batchSize).ToList();

        try
        {
            await apiClient.PostBehaviorEventsAsync(batch, cancellationToken);
            state.Events = state.Events.Skip(batch.Count).ToList();
            state.FailureCount = 0;
            state.NextAttemptUtc = null;
            WriteState(state);
            return batch.Count;
        }
        catch
        {
            state.FailureCount++;
            int delaySeconds = Math.Min(1800, (int)Math.Pow(2, Math.Min(state.FailureCount, 8)) * 15);
            state.NextAttemptUtc = DateTimeOffset.UtcNow.AddSeconds(delaySeconds);
            WriteState(state);
            throw;
        }
    }

    private SpoolState ReadState()
    {
        try
        {
            if (!File.Exists(_statePath))
            {
                return new SpoolState();
            }

            string json = File.ReadAllText(_statePath);
            var state = JsonSerializer.Deserialize<SpoolState>(json, _jsonOptions);
            return state ?? new SpoolState();
        }
        catch
        {
            return new SpoolState();
        }
    }

    private void WriteState(SpoolState state)
    {
        try
        {
            string tempPath = _statePath + ".tmp";
            File.WriteAllText(tempPath, JsonSerializer.Serialize(state, _jsonOptions));
            File.Move(tempPath, _statePath, true);
        }
        catch
        {
            // Do not break the worker because spool state could not be written.
        }
    }

    private static int ResolveInt(string key, int fallback)
    {
        string? raw = Environment.GetEnvironmentVariable(key);
        return int.TryParse(raw, out int parsed) ? parsed : fallback;
    }

    private sealed class SpoolState
    {
        public int FailureCount { get; set; }
        public DateTimeOffset? NextAttemptUtc { get; set; }
        public List<BehaviorEventDto> Events { get; set; } = [];
    }
}
