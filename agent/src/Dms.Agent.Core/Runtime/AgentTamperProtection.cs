using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json;
using Dms.Agent.Core.Jobs.Handlers;
using System.Runtime.InteropServices;

namespace Dms.Agent.Core.Runtime;

public sealed class AgentTamperProtection
{
    private const string DefaultServiceName = "DMSAgent";
    private static readonly string ProgramData = Environment.GetEnvironmentVariable("ProgramData")
        ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
        ?? @"C:\ProgramData";

    private readonly string _dmsRoot = Path.Combine(ProgramData, "DMS");
    private readonly string _securityRoot = Path.Combine(ProgramData, "DMS", "Security");
    private readonly string _manifestPath = Path.Combine(ProgramData, "DMS", "Security", "integrity-manifest.json");
    private readonly string _backupRoot = Path.Combine(ProgramData, "DMS", "Security", "backup");
    private readonly string _watchdogScriptPath = Path.Combine(ProgramData, "DMS", "Security", "watchdog-check.ps1");
    private readonly string _watchdogTaskName = "DMSAgent-Watchdog";

    public async Task<Dictionary<string, object?>> ApplyStartupHardeningAsync(CancellationToken cancellationToken)
    {
        if (string.Equals(Environment.GetEnvironmentVariable("DMS_TAMPER_PROTECTION_ENABLED"), "false", StringComparison.OrdinalIgnoreCase))
        {
            return new Dictionary<string, object?>
            {
                ["enabled"] = false,
                ["status"] = "disabled",
            };
        }

        if (!RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            return new Dictionary<string, object?>
            {
                ["enabled"] = true,
                ["status"] = "skipped_non_windows",
            };
        }

        Directory.CreateDirectory(_securityRoot);
        Directory.CreateDirectory(_backupRoot);

        var aclResult = await HardenSensitiveAclAsync(cancellationToken);
        var watchdogResult = await EnsureWatchdogTaskAsync(cancellationToken);
        var integrityResult = await ValidateAndRepairCriticalFilesAsync(cancellationToken);

        return new Dictionary<string, object?>
        {
            ["enabled"] = true,
            ["status"] = "applied",
            ["acl"] = aclResult,
            ["watchdog"] = watchdogResult,
            ["integrity"] = integrityResult,
        };
    }

    private async Task<Dictionary<string, object?>> HardenSensitiveAclAsync(CancellationToken cancellationToken)
    {
        var targets = new List<string>
        {
            Path.Combine(_dmsRoot, "enrollment-token.txt"),
            Path.Combine(_dmsRoot, "device-id.txt"),
            Path.Combine(_dmsRoot, "device-hmac-secret.txt"),
            Path.Combine(_dmsRoot, "api-base-url.txt"),
            _securityRoot,
        };

        var entries = new List<object>();
        int failures = 0;

        foreach (string target in targets.Distinct(StringComparer.OrdinalIgnoreCase))
        {
            bool exists = File.Exists(target) || Directory.Exists(target);
            if (!exists)
            {
                entries.Add(new { target, status = "missing" });
                continue;
            }

            string args = $"\"{target}\" /inheritance:r /grant:r SYSTEM:(F) Administrators:(F)";
            var result = await ProcessRunner.RunAsync("icacls.exe", args, cancellationToken);
            bool success = result.ExitCode == 0;
            if (!success)
            {
                failures++;
            }

            entries.Add(new
            {
                target,
                status = success ? "hardened" : "failed",
                exit_code = result.ExitCode,
                stderr = Truncate(result.StdErr, 512),
            });
        }

        return new Dictionary<string, object?>
        {
            ["targets"] = entries,
            ["failures"] = failures,
            ["success"] = failures == 0,
        };
    }

    private async Task<Dictionary<string, object?>> EnsureWatchdogTaskAsync(CancellationToken cancellationToken)
    {
        string serviceName = Environment.GetEnvironmentVariable("DMS_SERVICE_NAME") ?? DefaultServiceName;
        string escapedService = serviceName.Replace("'", "''");
        string scriptBody = string.Join(Environment.NewLine, new[]
        {
            "$ErrorActionPreference = 'SilentlyContinue'",
            $"$svc = Get-Service -Name '{escapedService}' -ErrorAction SilentlyContinue",
            "if ($null -eq $svc) { exit 0 }",
            "if ($svc.Status -ne 'Running') {",
            $"  Start-Service -Name '{escapedService}' -ErrorAction SilentlyContinue",
            "}",
        });
        await File.WriteAllTextAsync(_watchdogScriptPath, scriptBody, cancellationToken);

        string taskCommand = $"powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"{_watchdogScriptPath}\"";
        string args = $"/Create /TN \"{_watchdogTaskName}\" /SC MINUTE /MO 2 /RU SYSTEM /RL HIGHEST /TR \"{taskCommand}\" /F";
        var result = await ProcessRunner.RunAsync("schtasks.exe", args, cancellationToken);

        return new Dictionary<string, object?>
        {
            ["task_name"] = _watchdogTaskName,
            ["script_path"] = _watchdogScriptPath,
            ["success"] = result.ExitCode == 0,
            ["exit_code"] = result.ExitCode,
            ["stderr"] = Truncate(result.StdErr, 512),
        };
    }

    private async Task<Dictionary<string, object?>> ValidateAndRepairCriticalFilesAsync(CancellationToken cancellationToken)
    {
        string installRoot = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        string[] criticalRelative = new[]
        {
            "Dms.Agent.Service.exe",
            "Dms.Agent.Service.dll",
            "Dms.Agent.Core.dll",
        };

        var criticalFiles = criticalRelative
            .Select(relative => Path.Combine(installRoot, relative))
            .Where(File.Exists)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();

        if (criticalFiles.Count == 0)
        {
            return new Dictionary<string, object?>
            {
                ["success"] = true,
                ["status"] = "no_critical_files_detected",
            };
        }

        var manifest = await LoadManifestAsync(cancellationToken);
        if (manifest.Count == 0)
        {
            foreach (string file in criticalFiles)
            {
                string fileName = Path.GetFileName(file);
                string hash = await ComputeSha256Async(file, cancellationToken);
                manifest[fileName] = hash;
                string backupPath = Path.Combine(_backupRoot, fileName);
                File.Copy(file, backupPath, true);
            }

            await SaveManifestAsync(manifest, cancellationToken);
            return new Dictionary<string, object?>
            {
                ["success"] = true,
                ["status"] = "manifest_initialized",
                ["manifest_path"] = _manifestPath,
                ["files"] = manifest.Keys.OrderBy(x => x).ToArray(),
            };
        }

        var mismatches = new List<Dictionary<string, object?>>();
        foreach (string file in criticalFiles)
        {
            string fileName = Path.GetFileName(file);
            if (!manifest.TryGetValue(fileName, out string? expectedHash) || string.IsNullOrWhiteSpace(expectedHash))
            {
                continue;
            }

            string actualHash = await ComputeSha256Async(file, cancellationToken);
            if (string.Equals(actualHash, expectedHash, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            string backupPath = Path.Combine(_backupRoot, fileName);
            bool backupExists = File.Exists(backupPath);
            bool repaired = false;
            string? repairError = null;

            if (backupExists)
            {
                string backupHash = await ComputeSha256Async(backupPath, cancellationToken);
                if (string.Equals(backupHash, expectedHash, StringComparison.OrdinalIgnoreCase))
                {
                    try
                    {
                        File.Copy(backupPath, file, true);
                        repaired = true;
                    }
                    catch (Exception ex)
                    {
                        repairError = ex.Message;
                    }
                }
                else
                {
                    repairError = "backup hash mismatch";
                }
            }
            else
            {
                repairError = "backup missing";
            }

            mismatches.Add(new Dictionary<string, object?>
            {
                ["file"] = file,
                ["expected_sha256"] = expectedHash,
                ["actual_sha256"] = actualHash,
                ["backup_exists"] = backupExists,
                ["repaired"] = repaired,
                ["repair_error"] = repairError,
            });
        }

        bool allGood = mismatches.All(x => (bool?)x["repaired"] == true);
        if (mismatches.Count == 0)
        {
            return new Dictionary<string, object?>
            {
                ["success"] = true,
                ["status"] = "verified",
                ["mismatch_count"] = 0,
            };
        }

        bool scheduleOnFailure = !string.Equals(Environment.GetEnvironmentVariable("DMS_TAMPER_SELF_HEAL_SCHEDULE_DISABLED"), "true", StringComparison.OrdinalIgnoreCase);
        string? scheduledScript = null;
        if (!allGood && scheduleOnFailure)
        {
            var reparable = mismatches
                .Where(x => (bool?)x["repaired"] != true && (bool?)x["backup_exists"] == true)
                .ToList();

            if (reparable.Count > 0)
            {
                scheduledScript = await ScheduleSelfHealScriptAsync(reparable, cancellationToken);
            }
        }

        return new Dictionary<string, object?>
        {
            ["success"] = allGood,
            ["status"] = allGood ? "repaired" : "tamper_detected",
            ["mismatch_count"] = mismatches.Count,
            ["mismatches"] = mismatches,
            ["self_heal_script"] = scheduledScript,
        };
    }

    private async Task<string?> ScheduleSelfHealScriptAsync(List<Dictionary<string, object?>> mismatches, CancellationToken cancellationToken)
    {
        string serviceName = Environment.GetEnvironmentVariable("DMS_SERVICE_NAME") ?? DefaultServiceName;
        string scriptPath = Path.Combine(_securityRoot, $"self-heal-{DateTimeOffset.UtcNow:yyyyMMddHHmmss}.cmd");
        var lines = new List<string>
        {
            "@echo off",
            "setlocal",
            "timeout /t 8 /nobreak >nul",
            $"sc stop \"{serviceName}\" >nul 2>&1",
            "timeout /t 3 /nobreak >nul",
        };

        foreach (var mismatch in mismatches)
        {
            string? target = mismatch.TryGetValue("file", out var targetObj) ? targetObj?.ToString() : null;
            if (string.IsNullOrWhiteSpace(target))
            {
                continue;
            }

            string backupPath = Path.Combine(_backupRoot, Path.GetFileName(target));
            lines.Add($"copy /y \"{backupPath}\" \"{target}\" >nul 2>&1");
        }

        lines.Add($"sc start \"{serviceName}\" >nul 2>&1");
        lines.Add("exit /b 0");

        await File.WriteAllLinesAsync(scriptPath, lines, cancellationToken);
        Process.Start(new ProcessStartInfo
        {
            FileName = "cmd.exe",
            Arguments = $"/c start \"\" /min \"{scriptPath}\"",
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden,
        });

        return scriptPath;
    }

    private async Task<Dictionary<string, string>> LoadManifestAsync(CancellationToken cancellationToken)
    {
        try
        {
            if (!File.Exists(_manifestPath))
            {
                return new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            }

            string raw = await File.ReadAllTextAsync(_manifestPath, cancellationToken);
            using JsonDocument doc = JsonDocument.Parse(raw);
            if (!doc.RootElement.TryGetProperty("files", out JsonElement files) || files.ValueKind != JsonValueKind.Object)
            {
                return new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            }

            var map = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (JsonProperty prop in files.EnumerateObject())
            {
                if (prop.Value.ValueKind == JsonValueKind.String)
                {
                    map[prop.Name] = prop.Value.GetString() ?? string.Empty;
                }
            }

            return map;
        }
        catch
        {
            return new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        }
    }

    private async Task SaveManifestAsync(Dictionary<string, string> files, CancellationToken cancellationToken)
    {
        var payload = new Dictionary<string, object?>
        {
            ["schema"] = "dms.integrity-manifest.v1",
            ["updated_at"] = DateTimeOffset.UtcNow.ToString("O"),
            ["files"] = files.OrderBy(x => x.Key).ToDictionary(x => x.Key, x => (object?)x.Value),
        };

        string json = JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true });
        await File.WriteAllTextAsync(_manifestPath, json, cancellationToken);
    }

    private static async Task<string> ComputeSha256Async(string path, CancellationToken cancellationToken)
    {
        byte[] bytes = await File.ReadAllBytesAsync(path, cancellationToken);
        return Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
    }

    private static string Truncate(string? value, int maxLength = 1024)
    {
        string text = value ?? string.Empty;
        return text.Length <= maxLength ? text : text[..maxLength];
    }
}
