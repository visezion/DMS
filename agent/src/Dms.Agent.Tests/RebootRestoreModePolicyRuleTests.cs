using System;
using System.IO;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using Dms.Agent.Core.Jobs.Handlers;
using Dms.Agent.Core.Protocol;
using Xunit;

namespace Dms.Agent.Tests;

[Collection("EnvironmentSerial")]
public class RebootRestoreModePolicyRuleTests
{
    [Fact]
    public async Task ApplyPolicyCanEnableAndDisablePersistentRebootRestoreMode()
    {
        string tempRoot = Path.Combine(Path.GetTempPath(), "dms-reboot-restore-policy-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(tempRoot);
        string? oldProgramData = Environment.GetEnvironmentVariable("ProgramData");
        Environment.SetEnvironmentVariable("ProgramData", tempRoot);

        try
        {
            var handler = new PolicyApplyHandler();
            var enableEnvelope = new CommandEnvelopeDto
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
                            type = "reboot_restore_mode",
                            config = new
                            {
                                enabled = true,
                                persistent = true,
                                profile = "lab_fast",
                                clean_downloads = true,
                                clean_user_temp = true,
                                clean_windows_temp = true,
                                clean_dms_staging = true,
                                reboot_now = false,
                            },
                            enforce = true,
                        },
                    },
                },
            };

            var enableResult = await handler.ExecuteAsync(enableEnvelope, CancellationToken.None);
            Assert.True(string.Equals(enableResult.Status, "success", StringComparison.OrdinalIgnoreCase), JsonSerializer.Serialize(enableResult.Result));
            Assert.Equal(0, enableResult.ExitCode);

            string persistentPath = Path.Combine(tempRoot, "DMS", "Restore", "persistent-restore.json");
            Assert.True(File.Exists(persistentPath));
            using (var persistentDoc = JsonDocument.Parse(await File.ReadAllTextAsync(persistentPath)))
            {
                Assert.Equal("lab_fast", persistentDoc.RootElement.GetProperty("generated_from_profile").GetString());
                Assert.True(persistentDoc.RootElement.TryGetProperty("steps", out var steps));
                Assert.True(steps.ValueKind == JsonValueKind.Array && steps.GetArrayLength() > 0);
            }

            var disableEnvelope = new CommandEnvelopeDto
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
                            type = "reboot_restore_mode",
                            config = new
                            {
                                ensure = "absent",
                                enabled = false,
                                persistent = false,
                                remove_pending = true,
                            },
                            enforce = true,
                        },
                    },
                },
            };

            var disableResult = await handler.ExecuteAsync(disableEnvelope, CancellationToken.None);
            Assert.Equal("success", disableResult.Status);
            Assert.Equal(0, disableResult.ExitCode);
            Assert.False(File.Exists(persistentPath), JsonSerializer.Serialize(disableResult.Result));
        }
        finally
        {
            Environment.SetEnvironmentVariable("ProgramData", oldProgramData);
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
