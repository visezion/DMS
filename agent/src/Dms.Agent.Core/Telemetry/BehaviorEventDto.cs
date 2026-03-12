namespace Dms.Agent.Core.Telemetry;

public sealed class BehaviorEventDto
{
    public required string EventType { get; init; }
    public required DateTimeOffset OccurredAt { get; init; }
    public string? UserName { get; init; }
    public string? ProcessName { get; init; }
    public string? FilePath { get; init; }
    public Dictionary<string, object?> Metadata { get; init; } = new(StringComparer.OrdinalIgnoreCase);
}
