using System;
using System.IO;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using Dms.Agent.Core.Runtime;
using Xunit;

namespace Dms.Agent.Tests;

[Collection("EnvironmentSerial")]
public class StartupRestoreApplierTests
{
    [Fact]
    public async Task AppliesPendingManifestAndArchivesOnSuccess()
    {
        string tempRoot = Path.Combine(Path.GetTempPath(), "dms-startup-restore-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(tempRoot);
        string? oldProgramData = Environment.GetEnvironmentVariable("ProgramData");
        string? oldRestoreToggle = Environment.GetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY");
        Environment.SetEnvironmentVariable("ProgramData", tempRoot);
        Environment.SetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY", "true");

        try
        {
            string restoreRoot = Path.Combine(tempRoot, "DMS", "Restore");
            Directory.CreateDirectory(restoreRoot);

            string deleteFile = Path.Combine(tempRoot, "delete-me.txt");
            await File.WriteAllTextAsync(deleteFile, "x");

            string pendingPath = Path.Combine(restoreRoot, "pending-restore.json");
            var manifest = new
            {
                schema = "dms.restore-manifest.v1",
                cleanup_paths = new[] { deleteFile },
            };
            await File.WriteAllTextAsync(pendingPath, JsonSerializer.Serialize(manifest));

            var applier = new StartupRestoreApplier();
            var result = await applier.ApplyPendingAsync(CancellationToken.None);

            Assert.True((bool?) result["applied"] ?? false, JsonSerializer.Serialize(result));
            Assert.False(File.Exists(deleteFile));
            Assert.False(File.Exists(pendingPath));
            Assert.True(Directory.Exists(Path.Combine(restoreRoot, "archive")));
            Assert.True(File.Exists(Path.Combine(restoreRoot, "last-apply.json")));
        }
        finally
        {
            Environment.SetEnvironmentVariable("ProgramData", oldProgramData);
            Environment.SetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY", oldRestoreToggle);
            try
            {
                if (Directory.Exists(tempRoot))
                {
                    Directory.Delete(tempRoot, true);
                }
            }
            catch
            {
                // Cleanup best-effort.
            }
        }
    }

    [Fact]
    public async Task AppliesPersistentManifestAndKeepsItForNextBoot()
    {
        string tempRoot = Path.Combine(Path.GetTempPath(), "dms-startup-restore-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(tempRoot);
        string? oldProgramData = Environment.GetEnvironmentVariable("ProgramData");
        string? oldRestoreToggle = Environment.GetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY");
        Environment.SetEnvironmentVariable("ProgramData", tempRoot);
        Environment.SetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY", "true");

        try
        {
            string restoreRoot = Path.Combine(tempRoot, "DMS", "Restore");
            Directory.CreateDirectory(restoreRoot);

            string deleteFile = Path.Combine(tempRoot, "delete-me-persistent.txt");
            await File.WriteAllTextAsync(deleteFile, "x");

            string persistentPath = Path.Combine(restoreRoot, "persistent-restore.json");
            var manifest = new
            {
                schema = "dms.restore-manifest.v1",
                cleanup_paths = new[] { deleteFile },
            };
            await File.WriteAllTextAsync(persistentPath, JsonSerializer.Serialize(manifest));

            var applier = new StartupRestoreApplier();
            var result = await applier.ApplyPendingAsync(CancellationToken.None);

            Assert.True((bool?)result["applied"] ?? false, JsonSerializer.Serialize(result));
            Assert.False(File.Exists(deleteFile));
            Assert.True(File.Exists(persistentPath));
            Assert.True(File.Exists(Path.Combine(restoreRoot, "last-apply.json")));

            using var doc = JsonDocument.Parse(await File.ReadAllTextAsync(Path.Combine(restoreRoot, "last-apply.json")));
            Assert.Equal("success", doc.RootElement.GetProperty("status").GetString());
            Assert.True(doc.RootElement.TryGetProperty("runs", out var runsNode));
            Assert.True(runsNode.ValueKind == JsonValueKind.Array && runsNode.GetArrayLength() >= 1);
        }
        finally
        {
            Environment.SetEnvironmentVariable("ProgramData", oldProgramData);
            Environment.SetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY", oldRestoreToggle);
            try
            {
                if (Directory.Exists(tempRoot))
                {
                    Directory.Delete(tempRoot, true);
                }
            }
            catch
            {
                // Cleanup best-effort.
            }
        }
    }
}
