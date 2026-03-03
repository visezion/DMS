using System;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using Dms.Agent.Core.Jobs.Handlers;
using Dms.Agent.Core.Protocol;
using Xunit;

namespace Dms.Agent.Tests;

public class UwfPolicyRuleTests
{
    [Fact]
    public async Task ApplyPolicyUwfDryRunReturnsCompliant()
    {
        var handler = new PolicyApplyHandler();
        var envelope = new CommandEnvelopeDto
        {
            CommandId = Guid.NewGuid().ToString(),
            DeviceId = Guid.NewGuid().ToString(),
            Type = "apply_policy",
            Payload = new()
            {
                ["rules"] = new object[]
                {
                    new
                    {
                        type = "uwf",
                        config = new
                        {
                            ensure = "present",
                            volume = "C:",
                            dry_run = true,
                        },
                        enforce = true,
                    },
                },
            },
        };

        var result = await handler.ExecuteAsync(envelope, CancellationToken.None);
        Assert.Equal("success", result.Status);
        Assert.Equal(0, result.ExitCode);

        using var doc = JsonDocument.Parse(JsonSerializer.Serialize(result.Result));
        var rules = doc.RootElement.GetProperty("rules");
        Assert.Equal(JsonValueKind.Array, rules.ValueKind);
        Assert.True(rules.GetArrayLength() > 0);
        Assert.True(rules[0].GetProperty("compliant").GetBoolean());
        Assert.Contains("dry-run", rules[0].GetProperty("message").GetString(), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task ApplyPolicyUwfDryRunIncludesRebootGuardOptions()
    {
        var handler = new PolicyApplyHandler();
        var envelope = new CommandEnvelopeDto
        {
            CommandId = Guid.NewGuid().ToString(),
            DeviceId = Guid.NewGuid().ToString(),
            Type = "apply_policy",
            Payload = new()
            {
                ["rules"] = new object[]
                {
                    new
                    {
                        type = "uwf",
                        config = new
                        {
                            ensure = "present",
                            volume = "C:",
                            dry_run = true,
                            reboot_now = true,
                            reboot_if_pending = true,
                            max_reboot_attempts = 2,
                            reboot_cooldown_minutes = 30,
                        },
                        enforce = true,
                    },
                },
            },
        };

        var result = await handler.ExecuteAsync(envelope, CancellationToken.None);
        Assert.Equal("success", result.Status);
        Assert.Equal(0, result.ExitCode);

        using var doc = JsonDocument.Parse(JsonSerializer.Serialize(result.Result));
        string message = doc.RootElement.GetProperty("rules")[0].GetProperty("message").GetString() ?? string.Empty;
        Assert.Contains("max_reboot_attempts=2", message, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("reboot_cooldown_minutes=30", message, StringComparison.OrdinalIgnoreCase);
    }
}
