using System.Text.Json;
using Dms.Agent.Core.Jobs.Handlers;

namespace Dms.Agent.Core.Runtime;

public sealed class AutonomousRemediationLoop
{
    private DateTimeOffset _lastRun = DateTimeOffset.MinValue;

    public async Task<Dictionary<string, object?>> RunOnceAsync(CancellationToken cancellationToken)
    {
        bool enabled = string.Equals(Environment.GetEnvironmentVariable("DMS_AUTONOMOUS_REMEDIATION"), "true", StringComparison.OrdinalIgnoreCase);
        if (!enabled)
        {
            return new Dictionary<string, object?> { ["enabled"] = false, ["executed"] = 0 };
        }

        int intervalSeconds = 300;
        if (int.TryParse(Environment.GetEnvironmentVariable("DMS_AUTONOMOUS_REMEDIATION_INTERVAL_SECONDS"), out var parsed) && parsed > 0)
        {
            intervalSeconds = parsed;
        }
        if (DateTimeOffset.UtcNow - _lastRun < TimeSpan.FromSeconds(intervalSeconds))
        {
            return new Dictionary<string, object?> { ["enabled"] = true, ["executed"] = 0, ["skipped"] = "interval" };
        }
        _lastRun = DateTimeOffset.UtcNow;

        string baseDir = Environment.GetEnvironmentVariable("ProgramData")
            ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
            ?? @"C:\ProgramData";
        string dmsDir = Path.Combine(baseDir, "DMS");
        Directory.CreateDirectory(dmsDir);
        string rulesPath = Environment.GetEnvironmentVariable("DMS_AUTONOMOUS_REMEDIATION_FILE") ?? Path.Combine(dmsDir, "autonomous-remediation.json");
        if (!File.Exists(rulesPath))
        {
            return new Dictionary<string, object?> { ["enabled"] = true, ["executed"] = 0, ["rules_file"] = rulesPath, ["skipped"] = "missing_rules_file" };
        }

        JsonDocument doc;
        try
        {
            doc = JsonDocument.Parse(await File.ReadAllTextAsync(rulesPath, cancellationToken));
        }
        catch
        {
            return new Dictionary<string, object?> { ["enabled"] = true, ["executed"] = 0, ["rules_file"] = rulesPath, ["skipped"] = "invalid_rules_json" };
        }

        if (!doc.RootElement.TryGetProperty("rules", out var rulesNode) || rulesNode.ValueKind != JsonValueKind.Array)
        {
            return new Dictionary<string, object?> { ["enabled"] = true, ["executed"] = 0, ["rules_file"] = rulesPath, ["skipped"] = "no_rules" };
        }

        var executions = new List<Dictionary<string, object?>>();
        foreach (var rule in rulesNode.EnumerateArray())
        {
            string id = rule.TryGetProperty("id", out var idNode) ? (idNode.GetString() ?? string.Empty) : string.Empty;
            string condition = rule.TryGetProperty("condition", out var condNode) ? (condNode.GetString() ?? string.Empty).Trim().ToLowerInvariant() : string.Empty;
            string command = rule.TryGetProperty("command", out var cmdNode) ? (cmdNode.GetString() ?? string.Empty) : string.Empty;
            if (string.IsNullOrWhiteSpace(command) || string.IsNullOrWhiteSpace(condition))
            {
                continue;
            }

            bool shouldRun = condition switch
            {
                "disk_free_percent_below" => CheckDiskFreeThreshold(rule),
                "always" => true,
                _ => false,
            };
            if (!shouldRun)
            {
                continue;
            }

            var run = await ProcessRunner.RunShellCommandAsync(command, cancellationToken);
            executions.Add(new Dictionary<string, object?>
            {
                ["id"] = id,
                ["condition"] = condition,
                ["exit_code"] = run.ExitCode,
                ["stdout"] = run.StdOut,
                ["stderr"] = run.StdErr,
            });
        }

        return new Dictionary<string, object?>
        {
            ["enabled"] = true,
            ["rules_file"] = rulesPath,
            ["executed"] = executions.Count,
            ["runs"] = executions,
        };
    }

    private static bool CheckDiskFreeThreshold(JsonElement rule)
    {
        string drive = rule.TryGetProperty("drive", out var driveNode) ? (driveNode.GetString() ?? "C") : "C";
        drive = drive.Trim().TrimEnd(':', '\\', '/');
        if (string.IsNullOrWhiteSpace(drive))
        {
            drive = "C";
        }
        if (!rule.TryGetProperty("threshold_percent", out var thresholdNode) || thresholdNode.ValueKind != JsonValueKind.Number || !thresholdNode.TryGetInt32(out var threshold))
        {
            threshold = 10;
        }
        try
        {
            var target = DriveInfo.GetDrives().FirstOrDefault(d => d.IsReady && d.Name.StartsWith(drive, StringComparison.OrdinalIgnoreCase));
            if (target is null || target.TotalSize <= 0)
            {
                return false;
            }
            var freePercent = (int) Math.Round((double) target.AvailableFreeSpace / target.TotalSize * 100.0, MidpointRounding.AwayFromZero);
            return freePercent < threshold;
        }
        catch
        {
            return false;
        }
    }
}

