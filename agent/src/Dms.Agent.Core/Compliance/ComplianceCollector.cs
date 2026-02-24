namespace Dms.Agent.Core.Compliance;

public sealed class ComplianceCollector
{
    public object Collect() => new { collectedAt = DateTimeOffset.UtcNow };
}
