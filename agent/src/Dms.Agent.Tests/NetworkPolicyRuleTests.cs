using System;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using Dms.Agent.Core.Jobs.Handlers;
using Dms.Agent.Core.Protocol;
using Xunit;

namespace Dms.Agent.Tests;

public class NetworkPolicyRuleTests
{
    [Fact]
    public async Task ApplyPolicyDnsDryRunReturnsCompliant()
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
                        type = "dns",
                        config = new
                        {
                            interface_alias = "Ethernet",
                            mode = "static",
                            servers = new[] { "10.0.0.10", "10.0.0.11" },
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
        string message = doc.RootElement.GetProperty("rules")[0].GetProperty("message").GetString() ?? string.Empty;
        Assert.Contains("dry-run", message, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("dns", message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task ApplyPolicyNetworkAdapterDryRunReturnsCompliant()
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
                        type = "network_adapter",
                        config = new
                        {
                            interface_alias = "Ethernet",
                            ipv4_mode = "static",
                            address = "10.0.0.25",
                            prefix_length = 24,
                            gateway = "10.0.0.1",
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
        string message = doc.RootElement.GetProperty("rules")[0].GetProperty("message").GetString() ?? string.Empty;
        Assert.Contains("dry-run", message, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("static", message, StringComparison.OrdinalIgnoreCase);
    }
}
