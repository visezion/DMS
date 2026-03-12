using System.Diagnostics.Eventing.Reader;
using System.Security;
using System.Text.Json;
using System.Xml.Linq;

namespace Dms.Agent.Core.Telemetry;

public sealed class BehaviorTelemetryCollector
{
    private static readonly XNamespace EventNs = "http://schemas.microsoft.com/win/2004/08/events/event";
    private readonly string _bookmarkPath;

    public BehaviorTelemetryCollector()
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
        string telemetryDir = Path.Combine(programData, "DMS", "Telemetry");
        Directory.CreateDirectory(telemetryDir);
        _bookmarkPath = Path.Combine(telemetryDir, "behavior-bookmark.json");
    }

    public Task<IReadOnlyList<BehaviorEventDto>> CollectAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
        {
            return Task.FromResult<IReadOnlyList<BehaviorEventDto>>([]);
        }

        var events = new List<BehaviorEventDto>();
        long lastRecordId = ReadBookmark();
        long maxRecordId = lastRecordId;

        try
        {
            string query = $"*[System[(EventRecordID > {Math.Max(0, lastRecordId)}) and (EventID=4624 or EventID=4688 or EventID=4663)]]";
            var logQuery = new EventLogQuery("Security", PathType.LogName, query)
            {
                ReverseDirection = false,
                TolerateQueryErrors = true,
            };

            using var reader = new EventLogReader(logQuery);
            int seen = 0;

            while (!cancellationToken.IsCancellationRequested)
            {
                using EventRecord? record = reader.ReadEvent();
                if (record is null)
                {
                    break;
                }

                seen++;
                if (seen > 500)
                {
                    break;
                }

                maxRecordId = Math.Max(maxRecordId, record.RecordId ?? maxRecordId);
                var mapped = MapRecord(record);
                if (mapped is not null)
                {
                    events.Add(mapped);
                }
            }
        }
        catch (EventLogException)
        {
            // Service account may lack Security log rights in hardened environments.
        }
        catch (SecurityException)
        {
            // Skip telemetry collection if event log access is denied.
        }
        catch
        {
            // Never fail agent command loop due to behavior telemetry.
        }

        if (maxRecordId > lastRecordId)
        {
            WriteBookmark(maxRecordId);
        }

        return Task.FromResult<IReadOnlyList<BehaviorEventDto>>(events);
    }

    private static BehaviorEventDto? MapRecord(EventRecord record)
    {
        if (record.Id is not (4624 or 4688 or 4663))
        {
            return null;
        }

        string xml;
        try
        {
            xml = record.ToXml();
        }
        catch
        {
            return null;
        }

        XDocument doc;
        try
        {
            doc = XDocument.Parse(xml);
        }
        catch
        {
            return null;
        }

        DateTimeOffset occurredAt = ResolveOccurredAt(doc, record.TimeCreated);
        string recordId = (record.RecordId ?? 0).ToString();

        if (record.Id == 4624)
        {
            string logonTypeRaw = ReadEventData(doc, "LogonType");
            if (!int.TryParse(logonTypeRaw, out int logonType) || logonType is not (2 or 7 or 10 or 11))
            {
                return null;
            }

            string user = ReadEventData(doc, "TargetUserName");
            if (string.IsNullOrWhiteSpace(user) || user.EndsWith("$", StringComparison.Ordinal))
            {
                return null;
            }

            return new BehaviorEventDto
            {
                EventType = "user_logon",
                OccurredAt = occurredAt,
                UserName = user,
                ProcessName = null,
                FilePath = null,
                Metadata = new Dictionary<string, object?>
                {
                    ["source_event_id"] = record.Id,
                    ["source_record_id"] = recordId,
                    ["logon_type"] = logonType,
                    ["source_log"] = "Security",
                },
            };
        }

        if (record.Id == 4688)
        {
            string process = ReadEventData(doc, "NewProcessName");
            if (string.IsNullOrWhiteSpace(process))
            {
                return null;
            }

            string user = ReadEventData(doc, "SubjectUserName");
            return new BehaviorEventDto
            {
                EventType = "app_launch",
                OccurredAt = occurredAt,
                UserName = string.IsNullOrWhiteSpace(user) ? null : user,
                ProcessName = process,
                FilePath = null,
                Metadata = new Dictionary<string, object?>
                {
                    ["source_event_id"] = record.Id,
                    ["source_record_id"] = recordId,
                    ["command_line"] = ReadEventData(doc, "CommandLine"),
                    ["source_log"] = "Security",
                },
            };
        }

        string filePath = ReadEventData(doc, "ObjectName");
        if (string.IsNullOrWhiteSpace(filePath) || (!filePath.Contains(':') && !filePath.StartsWith("\\\\", StringComparison.Ordinal)))
        {
            return null;
        }

        return new BehaviorEventDto
        {
            EventType = "file_access",
            OccurredAt = occurredAt,
            UserName = NullIfWhitespace(ReadEventData(doc, "SubjectUserName")),
            ProcessName = NullIfWhitespace(ReadEventData(doc, "ProcessName")),
            FilePath = filePath,
            Metadata = new Dictionary<string, object?>
            {
                ["source_event_id"] = record.Id,
                ["source_record_id"] = recordId,
                ["access_mask"] = ReadEventData(doc, "AccessMask"),
                ["source_log"] = "Security",
            },
        };
    }

    private static string ReadEventData(XDocument doc, string name)
    {
        return doc
            .Descendants(EventNs + "Data")
            .FirstOrDefault(x => string.Equals((string?)x.Attribute("Name"), name, StringComparison.OrdinalIgnoreCase))
            ?.Value
            ?.Trim() ?? string.Empty;
    }

    private static string? NullIfWhitespace(string? value)
    {
        return string.IsNullOrWhiteSpace(value) ? null : value;
    }

    private static DateTimeOffset ResolveOccurredAt(XDocument doc, DateTime? fallback)
    {
        string raw = doc
            .Descendants(EventNs + "TimeCreated")
            .Select(x => (string?)x.Attribute("SystemTime"))
            .FirstOrDefault() ?? string.Empty;

        if (DateTimeOffset.TryParse(raw, out var parsed))
        {
            return parsed.ToUniversalTime();
        }

        if (fallback.HasValue)
        {
            return new DateTimeOffset(fallback.Value.ToUniversalTime(), TimeSpan.Zero);
        }

        return DateTimeOffset.UtcNow;
    }

    private long ReadBookmark()
    {
        try
        {
            if (!File.Exists(_bookmarkPath))
            {
                return 0;
            }

            using var doc = JsonDocument.Parse(File.ReadAllText(_bookmarkPath));
            if (doc.RootElement.TryGetProperty("security_record_id", out var node) && node.TryGetInt64(out long id))
            {
                return id;
            }
        }
        catch
        {
            // ignore
        }

        return 0;
    }

    private void WriteBookmark(long recordId)
    {
        try
        {
            var payload = JsonSerializer.Serialize(new Dictionary<string, object?>
            {
                ["security_record_id"] = recordId,
                ["updated_at_utc"] = DateTimeOffset.UtcNow.ToString("O"),
            });
            File.WriteAllText(_bookmarkPath, payload);
        }
        catch
        {
            // ignore bookmark write failures
        }
    }
}
