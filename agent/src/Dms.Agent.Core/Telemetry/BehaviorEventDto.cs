namespace Dms.Agent.Core.Telemetry;

public sealed class BehaviorEventDto
{
    public string? EventUid { get; init; }
    public string? SessionUid { get; init; }
    public string? ProcessUid { get; init; }
    public string? ParentProcessUid { get; init; }
    public string? CheckinId { get; set; }
    public required string EventType { get; init; }
    public required DateTimeOffset OccurredAt { get; init; }
    public string? UserName { get; init; }
    public string? ProcessName { get; init; }
    public string? FilePath { get; init; }
    public Dictionary<string, object?> Metadata { get; init; } = new(StringComparer.OrdinalIgnoreCase);
}
