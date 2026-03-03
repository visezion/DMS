using System.Text.Json;
using System.Text.Json.Nodes;
using Dms.Agent.Core.Jobs.Handlers;

namespace Dms.Agent.Core.Runtime;

public sealed class StartupRestoreApplier
{
    private readonly string _restoreRoot;
    private readonly string _pendingManifestPath;
    private readonly string _persistentManifestPath;
    private readonly string _archiveDir;
    private readonly string _lastApplyPath;

    public StartupRestoreApplier()
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData")
            ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
            ?? @"C:\ProgramData";

        _restoreRoot = Path.Combine(programData, "DMS", "Restore");
        _pendingManifestPath = Path.Combine(_restoreRoot, "pending-restore.json");
        _persistentManifestPath = Path.Combine(_restoreRoot, "persistent-restore.json");
        _archiveDir = Path.Combine(_restoreRoot, "archive");
        _lastApplyPath = Path.Combine(_restoreRoot, "last-apply.json");
    }

    public async Task<Dictionary<string, object?>> ApplyPendingAsync(CancellationToken cancellationToken)
    {
        bool enabled = !string.Equals(Environment.GetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY"), "false", StringComparison.OrdinalIgnoreCase);
        if (!enabled)
        {
            return new Dictionary<string, object?>
            {
                ["enabled"] = false,
                ["applied"] = false,
                ["skipped"] = "disabled",
            };
        }

        Directory.CreateDirectory(_restoreRoot);
        bool hasPendingManifest = File.Exists(_pendingManifestPath);
        bool hasPersistentManifest = File.Exists(_persistentManifestPath);
        if (!hasPendingManifest && !hasPersistentManifest)
        {
            return new Dictionary<string, object?>
            {
                ["enabled"] = true,
                ["applied"] = false,
                ["skipped"] = "no_restore_manifest",
            };
        }

        var runs = new List<Dictionary<string, object?>>();
        int totalFailures = 0;

        if (hasPendingManifest)
        {
            var pendingRun = await ApplyManifestAsync(_pendingManifestPath, "pending", archiveOnSuccess: true, cancellationToken);
            runs.Add(pendingRun);
            totalFailures += ReadInt(pendingRun.GetValueOrDefault("failure_count"));
        }

        if (hasPersistentManifest)
        {
            var persistentRun = await ApplyManifestAsync(_persistentManifestPath, "persistent", archiveOnSuccess: false, cancellationToken);
            runs.Add(persistentRun);
            totalFailures += ReadInt(persistentRun.GetValueOrDefault("failure_count"));
        }

        bool applied = totalFailures == 0;
        string status = applied ? "success" : "failed";
        string? error = applied ? null : $"{totalFailures} restore step(s) failed across startup manifest runs.";

        await WriteLastApplyAsync(status, applied, totalFailures, runs, error, cancellationToken);

        return new Dictionary<string, object?>
        {
            ["enabled"] = true,
            ["applied"] = applied,
            ["status"] = status,
            ["failures"] = totalFailures,
            ["runs"] = runs,
            ["error"] = error,
        };
    }

    private async Task<Dictionary<string, object?>> ApplyManifestAsync(
        string manifestPath,
        string source,
        bool archiveOnSuccess,
        CancellationToken cancellationToken)
    {
        JsonObject manifest;
        try
        {
            string raw = await File.ReadAllTextAsync(manifestPath, cancellationToken);
            manifest = JsonNode.Parse(raw)?.AsObject() ?? new JsonObject();
        }
        catch (Exception ex)
        {
            return new Dictionary<string, object?>
            {
                ["source"] = source,
                ["status"] = "failed",
                ["applied"] = false,
                ["failure_count"] = 1,
                ["manifest_path"] = manifestPath,
                ["error"] = $"invalid manifest json: {ex.Message}",
                ["steps"] = Array.Empty<object>(),
            };
        }

        var stepResults = new List<Dictionary<string, object?>>();
        int failureCount = 0;

        foreach (string path in ReadStringArray(manifest["cleanup_paths"]))
        {
            var cleanupResult = ApplyDeletePath(path);
            stepResults.Add(cleanupResult);
            if (!(bool?)cleanupResult.GetValueOrDefault("success") ?? false)
            {
                failureCount++;
            }
        }

        JsonArray steps = manifest["steps"] as JsonArray
            ?? manifest["restore_steps"] as JsonArray
            ?? [];

        foreach (JsonNode? stepNode in steps)
        {
            if (stepNode is not JsonObject step)
            {
                stepResults.Add(new Dictionary<string, object?>
                {
                    ["type"] = "unknown",
                    ["success"] = false,
                    ["error"] = "invalid step payload",
                });
                failureCount++;
                continue;
            }

            var stepResult = await ExecuteStepAsync(step, cancellationToken);
            stepResults.Add(stepResult);
            if (!(bool?)stepResult.GetValueOrDefault("success") ?? false)
            {
                failureCount++;
            }
        }

        bool applied = failureCount == 0;
        string status = applied ? "success" : "failed";
        string? archivePath = null;
        string? error = null;
        if (applied && archiveOnSuccess)
        {
            archivePath = ArchiveManifest(manifestPath, source);
        }
        if (!applied)
        {
            error = $"{failureCount} step(s) failed; manifest kept for next startup retry.";
        }

        return new Dictionary<string, object?>
        {
            ["source"] = source,
            ["status"] = status,
            ["applied"] = applied,
            ["failure_count"] = failureCount,
            ["manifest_path"] = manifestPath,
            ["archived_to"] = archivePath,
            ["error"] = error,
            ["steps"] = stepResults,
        };
    }

    private async Task<Dictionary<string, object?>> ExecuteStepAsync(JsonObject step, CancellationToken cancellationToken)
    {
        string type = (step["type"]?.GetValue<string>() ?? string.Empty).Trim().ToLowerInvariant();
        if (type == string.Empty)
        {
            type = step["script"] is not null ? "shell" : "process";
        }

        return type switch
        {
            "shell" or "run_command" => await ExecuteShellStepAsync(step, cancellationToken),
            "process" or "command" => await ExecuteProcessStepAsync(step, cancellationToken),
            "delete_path" => ApplyDeletePath(step["path"]?.GetValue<string>() ?? string.Empty),
            _ => new Dictionary<string, object?>
            {
                ["type"] = type,
                ["success"] = false,
                ["error"] = $"unsupported restore step type: {type}",
            },
        };
    }

    private async Task<Dictionary<string, object?>> ExecuteShellStepAsync(JsonObject step, CancellationToken cancellationToken)
    {
        string script = (step["script"]?.GetValue<string>() ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(script))
        {
            return new Dictionary<string, object?>
            {
                ["type"] = "shell",
                ["success"] = false,
                ["error"] = "script is required",
            };
        }

        string shell = (step["shell"]?.GetValue<string>() ?? "cmd").Trim().ToLowerInvariant();
        (int ExitCode, string StdOut, string StdErr) result;
        string command;

        if (shell == "powershell")
        {
            string tempScriptPath = Path.Combine(_restoreRoot, $"restore-step-{Guid.NewGuid():N}.ps1");
            await File.WriteAllTextAsync(tempScriptPath, script, cancellationToken);
            try
            {
                command = "powershell.exe";
                result = await ProcessRunner.RunAsync(
                    "powershell.exe",
                    $"-NoProfile -ExecutionPolicy Bypass -File \"{tempScriptPath}\"",
                    cancellationToken);
            }
            finally
            {
                try
                {
                    if (File.Exists(tempScriptPath))
                    {
                        File.Delete(tempScriptPath);
                    }
                }
                catch
                {
                    // Ignore cleanup failures.
                }
            }
        }
        else
        {
            command = "cmd.exe";
            result = await ProcessRunner.RunAsync("cmd.exe", $"/c {script}", cancellationToken);
        }

        return new Dictionary<string, object?>
        {
            ["type"] = "shell",
            ["shell"] = shell,
            ["command"] = command,
            ["success"] = result.ExitCode == 0,
            ["exit_code"] = result.ExitCode,
            ["stdout"] = Truncate(result.StdOut),
            ["stderr"] = Truncate(result.StdErr),
        };
    }

    private static async Task<Dictionary<string, object?>> ExecuteProcessStepAsync(JsonObject step, CancellationToken cancellationToken)
    {
        string path = (step["path"]?.GetValue<string>() ?? string.Empty).Trim();
        string args = (step["args"]?.GetValue<string>() ?? step["arguments"]?.GetValue<string>() ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(path))
        {
            return new Dictionary<string, object?>
            {
                ["type"] = "process",
                ["success"] = false,
                ["error"] = "path is required",
            };
        }

        var result = await ProcessRunner.RunAsync(path, args, cancellationToken);
        return new Dictionary<string, object?>
        {
            ["type"] = "process",
            ["path"] = path,
            ["args"] = args,
            ["success"] = result.ExitCode == 0,
            ["exit_code"] = result.ExitCode,
            ["stdout"] = Truncate(result.StdOut),
            ["stderr"] = Truncate(result.StdErr),
        };
    }

    private static Dictionary<string, object?> ApplyDeletePath(string rawPath)
    {
        string path = (rawPath ?? string.Empty).Trim();
        if (path == string.Empty)
        {
            return new Dictionary<string, object?>
            {
                ["type"] = "delete_path",
                ["success"] = false,
                ["error"] = "path is required",
            };
        }

        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
                return new Dictionary<string, object?>
                {
                    ["type"] = "delete_path",
                    ["path"] = path,
                    ["success"] = true,
                    ["deleted"] = "file",
                };
            }
            if (Directory.Exists(path))
            {
                Directory.Delete(path, true);
                return new Dictionary<string, object?>
                {
                    ["type"] = "delete_path",
                    ["path"] = path,
                    ["success"] = true,
                    ["deleted"] = "directory",
                };
            }

            return new Dictionary<string, object?>
            {
                ["type"] = "delete_path",
                ["path"] = path,
                ["success"] = true,
                ["deleted"] = "missing",
            };
        }
        catch (Exception ex)
        {
            return new Dictionary<string, object?>
            {
                ["type"] = "delete_path",
                ["path"] = path,
                ["success"] = false,
                ["error"] = ex.Message,
            };
        }
    }

    private string? ArchiveManifest(string manifestPath, string source)
    {
        try
        {
            Directory.CreateDirectory(_archiveDir);
            string archivePath = Path.Combine(_archiveDir, $"{source}-restore-{DateTimeOffset.UtcNow:yyyyMMddHHmmss}-{Guid.NewGuid():N}.json");
            File.Move(manifestPath, archivePath, true);
            return archivePath;
        }
        catch
        {
            return null;
        }
    }

    private async Task WriteLastApplyAsync(
        string status,
        bool applied,
        int failureCount,
        IReadOnlyList<Dictionary<string, object?>> stepResults,
        string? error,
        CancellationToken cancellationToken)
    {
        var payload = new Dictionary<string, object?>
        {
            ["status"] = status,
            ["applied"] = applied,
            ["failure_count"] = failureCount,
            ["error"] = error,
            ["updated_at"] = DateTimeOffset.UtcNow.ToString("O"),
            ["manifest_path"] = _pendingManifestPath,
            ["runs"] = stepResults,
        };

        string json = JsonSerializer.Serialize(payload, new JsonSerializerOptions
        {
            WriteIndented = true,
        });
        await File.WriteAllTextAsync(_lastApplyPath, json, cancellationToken);
    }

    private static IEnumerable<string> ReadStringArray(JsonNode? node)
    {
        if (node is not JsonArray array)
        {
            yield break;
        }

        foreach (JsonNode? item in array)
        {
            string value = item?.GetValue<string>() ?? string.Empty;
            if (!string.IsNullOrWhiteSpace(value))
            {
                yield return value.Trim();
            }
        }
    }

    private static string Truncate(string? value, int maxLength = 4096)
    {
        string text = value ?? string.Empty;
        return text.Length <= maxLength ? text : text[..maxLength];
    }

    private static int ReadInt(object? value)
    {
        return value switch
        {
            int intValue => intValue,
            long longValue => (int)longValue,
            string strValue when int.TryParse(strValue, out int parsed) => parsed,
            _ => 0,
        };
    }
}
