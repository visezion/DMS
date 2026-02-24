namespace Dms.Agent.Core.Policies;

public sealed class PolicyEngine
{
    public Task<object> ApplyAsync(object policyPayload, CancellationToken cancellationToken)
    {
        return Task.FromResult<object>(new { status = "success", applied = true });
    }
}
