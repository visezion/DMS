using System;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using Dms.Agent.Core.Jobs.Handlers;
using Dms.Agent.Core.Protocol;
using Xunit;

namespace Dms.Agent.Tests;

public class SnapshotHandlersTests
{
    [Fact]
    public async Task CreateSnapshot_DryRun_WindowsProvider_ReturnsSuccess()
    {
        var handler = new SnapshotCreateHandler();
        var envelope = new CommandEnvelopeDto
        {
            CommandId = Guid.NewGuid().ToString(),
            DeviceId = Guid.NewGuid().ToString(),
            Type = "create_snapshot",
            Payload = new()
            {
                ["provider"] = "windows_restore_point",
                ["label"] = "Lab-Before-Exam",
                ["restore_point_type"] = "MODIFY_SETTINGS",
                ["include_vss"] = true,
                ["vss_volumes"] = new[] { "C:" },
                ["dry_run"] = true,
            },
        };

        var result = await handler.ExecuteAsync(envelope, CancellationToken.None);
        Assert.Equal("success", result.Status);
        Assert.Equal(0, result.ExitCode);

        using var doc = JsonDocument.Parse(JsonSerializer.Serialize(result.Result));
        Assert.True(doc.RootElement.GetProperty("dry_run").GetBoolean());
        Assert.Equal("windows_restore_point", doc.RootElement.GetProperty("provider").GetString());
        Assert.Equal("Lab-Before-Exam", doc.RootElement.GetProperty("label").GetString());
    }

    [Fact]
    public async Task CreateSnapshot_ExternalHook_RequiresHookUrl()
    {
        var handler = new SnapshotCreateHandler();
        var envelope = new CommandEnvelopeDto
        {
            CommandId = Guid.NewGuid().ToString(),
            DeviceId = Guid.NewGuid().ToString(),
            Type = "create_snapshot",
            Payload = new()
            {
                ["provider"] = "external_hook",
            },
        };

        var result = await handler.ExecuteAsync(envelope, CancellationToken.None);
        Assert.Equal("failed", result.Status);
        Assert.Equal(1, result.ExitCode);

        using var doc = JsonDocument.Parse(JsonSerializer.Serialize(result.Result));
        Assert.Contains("hook_url", doc.RootElement.GetProperty("error").GetString(), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task RestoreSnapshot_DryRun_WithDescription_ReturnsSuccess()
    {
        var handler = new SnapshotRestoreHandler();
        var envelope = new CommandEnvelopeDto
        {
            CommandId = Guid.NewGuid().ToString(),
            DeviceId = Guid.NewGuid().ToString(),
            Type = "restore_snapshot",
            Payload = new()
            {
                ["provider"] = "windows_restore_point",
                ["restore_point_description"] = "Lab-Before-Exam",
                ["reboot_now"] = false,
                ["dry_run"] = true,
            },
        };

        var result = await handler.ExecuteAsync(envelope, CancellationToken.None);
        Assert.Equal("success", result.Status);
        Assert.Equal(0, result.ExitCode);

        using var doc = JsonDocument.Parse(JsonSerializer.Serialize(result.Result));
        Assert.True(doc.RootElement.GetProperty("dry_run").GetBoolean());
        Assert.Equal("Lab-Before-Exam", doc.RootElement.GetProperty("restore_point_description").GetString());
    }

    [Fact]
    public async Task RestoreSnapshot_Fails_WhenSequenceAndDescriptionMissing()
    {
        var handler = new SnapshotRestoreHandler();
        var envelope = new CommandEnvelopeDto
        {
            CommandId = Guid.NewGuid().ToString(),
            DeviceId = Guid.NewGuid().ToString(),
            Type = "restore_snapshot",
            Payload = new()
            {
                ["provider"] = "windows_restore_point",
            },
        };

        var result = await handler.ExecuteAsync(envelope, CancellationToken.None);
        Assert.Equal("failed", result.Status);
        Assert.Equal(1, result.ExitCode);

        using var doc = JsonDocument.Parse(JsonSerializer.Serialize(result.Result));
        Assert.Contains("restore_point_sequence", doc.RootElement.GetProperty("error").GetString(), StringComparison.OrdinalIgnoreCase);
    }
}

