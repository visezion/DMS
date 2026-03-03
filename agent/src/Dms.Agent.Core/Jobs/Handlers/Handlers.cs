using System.Diagnostics;
using System.IO.Compression;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;
using System.Runtime.InteropServices;
using System.Security.Principal;
using Dms.Agent.Core.Protocol;
using Microsoft.Win32;

namespace Dms.Agent.Core.Jobs.Handlers;

internal static class ProcessRunner
{
    private const int MinDownloadTimeoutMinutes = 120;
    private const int MaxDownloadTimeoutMinutes = 400;

    public static async Task<(int ExitCode, string StdOut, string StdErr)> RunAsync(string fileName, string arguments, CancellationToken cancellationToken)
    {
        using var process = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = fileName,
                Arguments = arguments,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
            }
        };

        process.Start();
        string stdout = await process.StandardOutput.ReadToEndAsync(cancellationToken);
        string stderr = await process.StandardError.ReadToEndAsync(cancellationToken);
        await process.WaitForExitAsync(cancellationToken);
        return (process.ExitCode, stdout, stderr);
    }

    public static async Task<(string Path, string Sha256)> DownloadArtifactAsync(
        string downloadUrl,
        string fileName,
        string expectedSha256,
        CancellationToken cancellationToken)
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
        string root = Path.Combine(programData, "DMS", "Packages", DateTime.UtcNow.ToString("yyyyMMddHHmmss"));
        Directory.CreateDirectory(root);

        string safeName = string.IsNullOrWhiteSpace(fileName) ? "package.bin" : fileName;
        string artifactPath = Path.Combine(root, safeName);
        using (var client = CreateArtifactDownloadHttpClient())
        using (var response = await client.GetAsync(downloadUrl, cancellationToken))
        {
            response.EnsureSuccessStatusCode();
            await using var fs = new FileStream(artifactPath, FileMode.Create, FileAccess.Write, FileShare.Read);
            await response.Content.CopyToAsync(fs, cancellationToken);
        }

        string actualSha256 = Convert.ToHexString(SHA256.HashData(await File.ReadAllBytesAsync(artifactPath, cancellationToken))).ToLowerInvariant();
        if (!string.IsNullOrWhiteSpace(expectedSha256) && !string.Equals(actualSha256, expectedSha256, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException($"sha256 mismatch expected={expectedSha256} actual={actualSha256}");
        }

        return (artifactPath, actualSha256);
    }

    public static HttpClient CreateArtifactDownloadHttpClient()
    {
        int timeoutMinutes = MinDownloadTimeoutMinutes;
        string? configured = Environment.GetEnvironmentVariable("DMS_DOWNLOAD_TIMEOUT_MINUTES");
        if (!string.IsNullOrWhiteSpace(configured) && int.TryParse(configured, out int parsed))
        {
            timeoutMinutes = Math.Clamp(parsed, MinDownloadTimeoutMinutes, MaxDownloadTimeoutMinutes);
        }

        return new HttpClient
        {
            Timeout = TimeSpan.FromMinutes(timeoutMinutes),
        };
    }

    public static Task<(int ExitCode, string StdOut, string StdErr)> RunShellCommandAsync(string command, CancellationToken cancellationToken)
    {
        if (RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            return RunAsync("cmd.exe", $"/c {command}", cancellationToken);
        }
        if (RuntimeInformation.IsOSPlatform(OSPlatform.OSX))
        {
            return RunAsync("/bin/zsh", $"-lc \"{command.Replace("\"", "\\\"")}\"", cancellationToken);
        }
        return RunAsync("/bin/bash", $"-lc \"{command.Replace("\"", "\\\"")}\"", cancellationToken);
    }

    public static Task<(int ExitCode, string StdOut, string StdErr)> RunPolicyCommandAsync(string command, CancellationToken cancellationToken)
    {
        if (!RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            return RunShellCommandAsync(command, cancellationToken);
        }

        if (TrySplitCommand(command, out var fileName, out var arguments) && IsPowerShellExecutable(fileName))
        {
            return RunAsync(fileName, arguments, cancellationToken);
        }

        return RunAsync("cmd.exe", $"/c {command}", cancellationToken);
    }

    private static bool IsPowerShellExecutable(string fileName)
    {
        string exe = Path.GetFileName(fileName).Trim();
        return exe.Equals("powershell.exe", StringComparison.OrdinalIgnoreCase)
            || exe.Equals("pwsh.exe", StringComparison.OrdinalIgnoreCase)
            || exe.Equals("powershell", StringComparison.OrdinalIgnoreCase)
            || exe.Equals("pwsh", StringComparison.OrdinalIgnoreCase);
    }

    private static bool TrySplitCommand(string command, out string fileName, out string arguments)
    {
        fileName = string.Empty;
        arguments = string.Empty;
        if (string.IsNullOrWhiteSpace(command))
        {
            return false;
        }

        string text = command.Trim();
        if (text.StartsWith('"'))
        {
            int endQuote = text.IndexOf('"', 1);
            if (endQuote <= 1)
            {
                return false;
            }

            fileName = text[1..endQuote];
            arguments = text[(endQuote + 1)..].TrimStart();
            return !string.IsNullOrWhiteSpace(fileName);
        }

        int firstSpace = text.IndexOf(' ');
        if (firstSpace < 0)
        {
            fileName = text;
            return true;
        }

        fileName = text[..firstSpace];
        arguments = text[(firstSpace + 1)..];
        return !string.IsNullOrWhiteSpace(fileName);
    }

    public static async Task<bool> CommandExistsAsync(string command, CancellationToken cancellationToken)
    {
        if (RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            var r = await RunAsync("where", command, cancellationToken);
            return r.ExitCode == 0;
        }

        var linuxResult = await RunAsync("/bin/bash", $"-lc \"command -v {command}\"", cancellationToken);
        return linuxResult.ExitCode == 0;
    }
}

public sealed class WingetInstallHandler : IJobHandler
{
    public string JobType => "install_package";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        if (!envelope.Payload.TryGetValue("winget_id", out var wingetIdObj) || wingetIdObj is null)
        {
            return ("failed", 1, new { error = "winget_id missing" });
        }
        string packageId = wingetIdObj.ToString() ?? string.Empty;

        bool hasDetection = PolicyApplyHandler.HasDetection(envelope.Payload);
        if (hasDetection && PolicyApplyHandler.EvaluateDetection(envelope.Payload))
        {
            return ("success", 0, new { skipped = true, already_installed = true, reason = "detection_precheck_matched" });
        }

        string args = $"install --id {packageId} --exact --silent --accept-package-agreements --accept-source-agreements --disable-interactivity";
        var result = await ProcessRunner.RunAsync("winget", args, cancellationToken);

        bool detected = !hasDetection || PolicyApplyHandler.EvaluateDetection(envelope.Payload);
        string status = (result.ExitCode is 0 or 3010) && detected ? "success" : "failed";
        if (status == "failed")
        {
            var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
            return ("failed", result.ExitCode, new { result.StdOut, result.StdErr, skipped = false, detection_ok = detected, rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
        }
        return (status, result.ExitCode, new { result.StdOut, result.StdErr, skipped = false, detection_ok = detected });
    }
}

public sealed class WingetUninstallHandler : IJobHandler
{
    public string JobType => "uninstall_package";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        if (!envelope.Payload.TryGetValue("winget_id", out var wingetIdObj) || wingetIdObj is null)
        {
            return ("failed", 1, new { error = "winget_id missing" });
        }
        string packageId = wingetIdObj.ToString() ?? string.Empty;
        string args = $"uninstall --id {packageId} --exact --silent --accept-source-agreements --disable-interactivity";
        var result = await ProcessRunner.RunAsync("winget", args, cancellationToken);

        string status = result.ExitCode == 0 ? "success" : "failed";
        return (status, result.ExitCode, new { result.StdOut, result.StdErr });
    }
}

public sealed class MsiInstallHandler : IJobHandler
{
    public string JobType => "install_msi";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        string path = envelope.Payload.TryGetValue("path", out var pathObj) ? pathObj?.ToString() ?? string.Empty : string.Empty;
        string downloadUrl = envelope.Payload.TryGetValue("download_url", out var downloadObj) ? downloadObj?.ToString() ?? string.Empty : string.Empty;
        string expectedSha256 = envelope.Payload.TryGetValue("sha256", out var shaObj) ? shaObj?.ToString() ?? string.Empty : string.Empty;
        string fileName = envelope.Payload.TryGetValue("file_name", out var fileObj) ? fileObj?.ToString() ?? "package.msi" : "package.msi";
        string logPath = envelope.Payload.TryGetValue("log_path", out var logObj) ? logObj?.ToString() ?? "C:\\ProgramData\\DMS\\Logs\\msi.log" : "C:\\ProgramData\\DMS\\Logs\\msi.log";
        string msiArgs = envelope.Payload.TryGetValue("msi_args", out var argsObj) ? argsObj?.ToString() ?? "/qn /norestart" : "/qn /norestart";
        bool hasDetection = PolicyApplyHandler.HasDetection(envelope.Payload);
        if (hasDetection && PolicyApplyHandler.EvaluateDetection(envelope.Payload))
        {
            return ("success", 0, new { skipped = true, already_installed = true, reason = "detection_precheck_matched" });
        }

        string sha256 = string.Empty;
        bool downloadedArtifact = false;
        bool keepArtifact = envelope.Payload.TryGetValue("keep_artifact", out var keepObj)
            && bool.TryParse(keepObj?.ToString(), out var keepParsed)
            && keepParsed;
        if (string.IsNullOrWhiteSpace(path) && !string.IsNullOrWhiteSpace(downloadUrl))
        {
            var downloaded = await ProcessRunner.DownloadArtifactAsync(downloadUrl, fileName, expectedSha256, cancellationToken);
            path = downloaded.Path;
            sha256 = downloaded.Sha256;
            downloadedArtifact = true;
        }

        if (string.IsNullOrWhiteSpace(path) || !File.Exists(path))
        {
            return ("failed", 1, new { error = "path missing and download_url missing/failed" });
        }

        string args = $"/i \"{path}\" {msiArgs} /L*v \"{logPath}\"";
        var result = await ProcessRunner.RunAsync("msiexec", args, cancellationToken);
        bool detected = !hasDetection || PolicyApplyHandler.EvaluateDetection(envelope.Payload);
        string status = (result.ExitCode is 0 or 3010) && detected ? "success" : "failed";
        if (status == "failed")
        {
            var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
            return (status, result.ExitCode, new { result.StdOut, result.StdErr, logPath, path, sha256, skipped = false, detection_ok = detected, rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
        }

        bool artifactRemoved = false;
        if (downloadedArtifact && !keepArtifact)
        {
            artifactRemoved = JobHandlerSupport.TryRemoveDownloadedArtifact(path);
        }

        return (status, result.ExitCode, new { result.StdOut, result.StdErr, logPath, path, sha256, skipped = false, detection_ok = detected, downloaded_artifact_removed = artifactRemoved });
    }
}

public sealed class ExeInstallHandler : IJobHandler
{
    public string JobType => "install_exe";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        string path = envelope.Payload.TryGetValue("path", out var pathObj) ? pathObj?.ToString() ?? string.Empty : string.Empty;
        string downloadUrl = envelope.Payload.TryGetValue("download_url", out var downloadObj) ? downloadObj?.ToString() ?? string.Empty : string.Empty;
        string expectedSha256 = envelope.Payload.TryGetValue("sha256", out var shaObj) ? shaObj?.ToString() ?? string.Empty : string.Empty;
        string fileName = envelope.Payload.TryGetValue("file_name", out var fileObj) ? fileObj?.ToString() ?? "package.exe" : "package.exe";
        string args = envelope.Payload.TryGetValue("silent_args", out var argsObj) ? argsObj?.ToString() ?? "/S" : "/S";
        bool hasDetection = PolicyApplyHandler.HasDetection(envelope.Payload);
        if (hasDetection && PolicyApplyHandler.EvaluateDetection(envelope.Payload))
        {
            return ("success", 0, new { skipped = true, already_installed = true, reason = "detection_precheck_matched" });
        }

        string sha256 = string.Empty;
        bool downloadedArtifact = false;
        bool keepArtifact = envelope.Payload.TryGetValue("keep_artifact", out var keepObj)
            && bool.TryParse(keepObj?.ToString(), out var keepParsed)
            && keepParsed;
        if (string.IsNullOrWhiteSpace(path) && !string.IsNullOrWhiteSpace(downloadUrl))
        {
            var downloaded = await ProcessRunner.DownloadArtifactAsync(downloadUrl, fileName, expectedSha256, cancellationToken);
            path = downloaded.Path;
            sha256 = downloaded.Sha256;
            downloadedArtifact = true;
        }

        if (string.IsNullOrWhiteSpace(path))
        {
            return ("failed", 1, new { error = "path missing and download_url missing/failed" });
        }

        bool looksLikeFilesystemPath =
            Path.IsPathRooted(path)
            || path.Contains(Path.DirectorySeparatorChar)
            || path.Contains(Path.AltDirectorySeparatorChar);

        if (looksLikeFilesystemPath)
        {
            if (!File.Exists(path))
            {
                return ("failed", 1, new { error = "installer path not found", path });
            }
        }
        else
        {
            bool existsOnPath = await ProcessRunner.CommandExistsAsync(path, cancellationToken);
            if (!existsOnPath)
            {
                return ("failed", 1, new { error = "installer executable not found in PATH", path });
            }
        }

        var result = await ProcessRunner.RunAsync(path, args, cancellationToken);
        bool detected = !hasDetection || PolicyApplyHandler.EvaluateDetection(envelope.Payload);
        string status = (result.ExitCode is 0 or 3010) && detected ? "success" : "failed";
        if (status == "failed")
        {
            var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
            return (status, result.ExitCode, new { result.StdOut, result.StdErr, path, sha256, skipped = false, detection_ok = detected, rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
        }

        bool artifactRemoved = false;
        if (downloadedArtifact && !keepArtifact)
        {
            artifactRemoved = JobHandlerSupport.TryRemoveDownloadedArtifact(path);
        }

        return (status, result.ExitCode, new { result.StdOut, result.StdErr, path, sha256, skipped = false, detection_ok = detected, downloaded_artifact_removed = artifactRemoved });
    }
}

public sealed class CustomInstallHandler : IJobHandler
{
    public string JobType => "install_custom";

    public Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        // Backward-compatible alias for custom installers using the EXE flow.
        return new ExeInstallHandler().ExecuteAsync(envelope, cancellationToken);
    }
}

public sealed class ArchiveInstallHandler : IJobHandler
{
    public string JobType => "install_archive";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        string downloadUrl = envelope.Payload.TryGetValue("download_url", out var downloadObj) ? downloadObj?.ToString() ?? string.Empty : string.Empty;
        string expectedSha256 = envelope.Payload.TryGetValue("sha256", out var shaObj) ? shaObj?.ToString() ?? string.Empty : string.Empty;
        string fileName = envelope.Payload.TryGetValue("file_name", out var fileObj) ? fileObj?.ToString() ?? "package.zip" : "package.zip";
        string extractTo = envelope.Payload.TryGetValue("extract_to", out var extractObj) ? extractObj?.ToString() ?? string.Empty : string.Empty;
        string postInstallCommand = envelope.Payload.TryGetValue("post_install_command", out var postObj) ? postObj?.ToString() ?? string.Empty : string.Empty;
        bool stripTopLevel = envelope.Payload.TryGetValue("strip_top_level", out var stripObj)
            && bool.TryParse(stripObj?.ToString(), out var stripParsed)
            && stripParsed;
        bool cleanTarget = envelope.Payload.TryGetValue("clean_target", out var cleanObj)
            && bool.TryParse(cleanObj?.ToString(), out var cleanParsed)
            && cleanParsed;
        bool keepArtifact = envelope.Payload.TryGetValue("keep_artifact", out var keepObj)
            && bool.TryParse(keepObj?.ToString(), out var keepParsed)
            && keepParsed;
        bool hasDetection = PolicyApplyHandler.HasDetection(envelope.Payload);
        if (hasDetection && PolicyApplyHandler.EvaluateDetection(envelope.Payload))
        {
            return ("success", 0, new { skipped = true, already_installed = true, reason = "detection_precheck_matched" });
        }

        if (string.IsNullOrWhiteSpace(downloadUrl))
        {
            return ("failed", 1, new { error = "download_url missing" });
        }
        if (string.IsNullOrWhiteSpace(extractTo))
        {
            return ("failed", 1, new { error = "extract_to missing" });
        }

        string archivePath = string.Empty;
        string sha256 = string.Empty;
        bool downloadedArtifact = false;
        try
        {
            var downloaded = await ProcessRunner.DownloadArtifactAsync(downloadUrl, fileName, expectedSha256, cancellationToken);
            archivePath = downloaded.Path;
            sha256 = downloaded.Sha256;
            downloadedArtifact = true;

            string extension = Path.GetExtension(archivePath).Trim().ToLowerInvariant();
            if (extension != ".zip")
            {
                return ("failed", 1, new { error = "unsupported archive format; only .zip is supported", archivePath });
            }

            string targetDirectory = extractTo;
            if (cleanTarget && Directory.Exists(targetDirectory))
            {
                Directory.Delete(targetDirectory, true);
            }
            Directory.CreateDirectory(targetDirectory);

            string tempExtractDirectory = Path.Combine(Path.GetTempPath(), "dms-archive-extract-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(tempExtractDirectory);
            ZipFile.ExtractToDirectory(archivePath, tempExtractDirectory, true);

            string sourceDirectory = tempExtractDirectory;
            if (stripTopLevel)
            {
                var dirs = Directory.GetDirectories(tempExtractDirectory);
                var files = Directory.GetFiles(tempExtractDirectory);
                if (dirs.Length == 1 && files.Length == 0)
                {
                    sourceDirectory = dirs[0];
                }
            }

            CopyDirectoryContents(sourceDirectory, targetDirectory);
            TryDeleteDirectory(tempExtractDirectory);

            int postExitCode = 0;
            string postStdOut = string.Empty;
            string postStdErr = string.Empty;
            if (!string.IsNullOrWhiteSpace(postInstallCommand))
            {
                var post = await ProcessRunner.RunShellCommandAsync(postInstallCommand, cancellationToken);
                postExitCode = post.ExitCode;
                postStdOut = post.StdOut;
                postStdErr = post.StdErr;
                if (postExitCode != 0 && postExitCode != 3010)
                {
                    return ("failed", postExitCode, new
                    {
                        error = "post_install_command failed",
                        post_install_exit_code = postExitCode,
                        post_install_stdout = postStdOut,
                        post_install_stderr = postStdErr,
                        extract_to = targetDirectory,
                        archive_path = archivePath,
                        sha256,
                    });
                }
            }

            bool detected = !hasDetection || PolicyApplyHandler.EvaluateDetection(envelope.Payload);
            if (!detected)
            {
                return ("failed", 2, new
                {
                    error = "detection check failed after archive install",
                    extract_to = targetDirectory,
                    archive_path = archivePath,
                    sha256,
                });
            }

            bool artifactRemoved = false;
            if (downloadedArtifact && !keepArtifact)
            {
                artifactRemoved = JobHandlerSupport.TryRemoveDownloadedArtifact(archivePath);
            }

            return ("success", 0, new
            {
                extract_to = targetDirectory,
                archive_path = archivePath,
                sha256,
                strip_top_level = stripTopLevel,
                clean_target = cleanTarget,
                downloaded_artifact_removed = artifactRemoved,
                post_install_exit_code = postExitCode,
                post_install_stdout = postStdOut,
                post_install_stderr = postStdErr,
                skipped = false,
                detection_ok = true,
            });
        }
        catch (Exception ex)
        {
            return ("failed", 1, new
            {
                error = ex.Message,
                archive_path = archivePath,
                sha256,
            });
        }
    }

    private static void CopyDirectoryContents(string sourceDirectory, string destinationDirectory)
    {
        foreach (string dirPath in Directory.GetDirectories(sourceDirectory, "*", SearchOption.AllDirectories))
        {
            string relative = Path.GetRelativePath(sourceDirectory, dirPath);
            string targetDir = Path.Combine(destinationDirectory, relative);
            Directory.CreateDirectory(targetDir);
        }

        foreach (string filePath in Directory.GetFiles(sourceDirectory, "*", SearchOption.AllDirectories))
        {
            string relative = Path.GetRelativePath(sourceDirectory, filePath);
            string targetFile = Path.Combine(destinationDirectory, relative);
            string? parent = Path.GetDirectoryName(targetFile);
            if (!string.IsNullOrWhiteSpace(parent))
            {
                Directory.CreateDirectory(parent);
            }
            File.Copy(filePath, targetFile, true);
        }
    }

    private static void TryDeleteDirectory(string path)
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(path) && Directory.Exists(path))
            {
                Directory.Delete(path, true);
            }
        }
        catch
        {
            // Ignore non-critical cleanup errors.
        }
    }
}

public sealed class ArchiveUninstallHandler : IJobHandler
{
    public string JobType => "uninstall_archive";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        string removePath = envelope.Payload.TryGetValue("remove_path", out var removeObj) ? removeObj?.ToString() ?? string.Empty : string.Empty;
        string uninstallCommand = envelope.Payload.TryGetValue("command", out var commandObj) ? commandObj?.ToString() ?? string.Empty : string.Empty;

        if (string.IsNullOrWhiteSpace(removePath) && string.IsNullOrWhiteSpace(uninstallCommand))
        {
            return ("failed", 1, new { error = "remove_path or command required" });
        }

        bool removed = false;
        string removeError = string.Empty;
        if (!string.IsNullOrWhiteSpace(removePath))
        {
            try
            {
                if (Directory.Exists(removePath))
                {
                    Directory.Delete(removePath, true);
                    removed = true;
                }
                else if (File.Exists(removePath))
                {
                    File.Delete(removePath);
                    removed = true;
                }
            }
            catch (Exception ex)
            {
                removeError = ex.Message;
            }
        }

        int commandExitCode = 0;
        string commandStdOut = string.Empty;
        string commandStdErr = string.Empty;
        if (!string.IsNullOrWhiteSpace(uninstallCommand))
        {
            var commandResult = await ProcessRunner.RunShellCommandAsync(uninstallCommand, cancellationToken);
            commandExitCode = commandResult.ExitCode;
            commandStdOut = commandResult.StdOut;
            commandStdErr = commandResult.StdErr;
            if (commandExitCode != 0 && commandExitCode != 3010)
            {
                return ("failed", commandExitCode, new
                {
                    error = "uninstall command failed",
                    command = uninstallCommand,
                    remove_path = removePath,
                    remove_error = removeError,
                    removed,
                    command_stdout = commandStdOut,
                    command_stderr = commandStdErr,
                });
            }
        }

        if (!string.IsNullOrWhiteSpace(removePath) && !removed && string.IsNullOrWhiteSpace(removeError))
        {
            removeError = "path not found";
        }

        bool success = string.IsNullOrWhiteSpace(removeError) || string.Equals(removeError, "path not found", StringComparison.OrdinalIgnoreCase);
        return (success ? "success" : "failed", success ? 0 : 1, new
        {
            command = uninstallCommand,
            remove_path = removePath,
            removed,
            remove_error = removeError,
            command_stdout = commandStdOut,
            command_stderr = commandStdErr,
        });
    }
}

public sealed class MsiUninstallHandler : IJobHandler
{
    public string JobType => "uninstall_msi";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        if (!envelope.Payload.TryGetValue("product_code", out var productCodeObj) || productCodeObj is null)
        {
            return ("failed", 1, new { error = "product_code missing" });
        }

        string productCode = productCodeObj.ToString() ?? string.Empty;
        if (string.IsNullOrWhiteSpace(productCode))
        {
            return ("failed", 1, new { error = "product_code empty" });
        }

        string msiArgs = envelope.Payload.TryGetValue("msi_args", out var argsObj) ? argsObj?.ToString() ?? "/qn /norestart" : "/qn /norestart";
        string args = $"/x \"{productCode}\" {msiArgs}";
        var result = await ProcessRunner.RunAsync("msiexec", args, cancellationToken);
        string status = result.ExitCode is 0 or 3010 ? "success" : "failed";
        return (status, result.ExitCode, new { result.StdOut, result.StdErr, product_code = productCode });
    }
}

internal static class AgentUninstallAuthorization
{
    public static (bool Allowed, string? Error, Dictionary<string, object?>? Details) Validate(Dictionary<string, object?> payload)
    {
        bool enforcementEnabled = !string.Equals(
            Environment.GetEnvironmentVariable("DMS_AGENT_UNINSTALL_REQUIRE_CONFIRMATION"),
            "false",
            StringComparison.OrdinalIgnoreCase);

        if (!enforcementEnabled)
        {
            return (true, null, new Dictionary<string, object?>
            {
                ["enforcement_enabled"] = false,
            });
        }

        bool adminConfirmed = ReadBool(payload, "admin_confirmed");
        string authorizedAtRaw = ReadString(payload, "admin_confirmed_at");
        int ttlMinutes = ReadInt(payload, "admin_confirmation_ttl_minutes", 30);
        ttlMinutes = Math.Clamp(ttlMinutes, 1, 240);
        string confirmationNonce = ReadString(payload, "admin_confirmation_nonce");

        if (!adminConfirmed)
        {
            return (false, "agent uninstall blocked: admin_confirmed missing/false", null);
        }

        if (string.IsNullOrWhiteSpace(authorizedAtRaw) || !DateTimeOffset.TryParse(authorizedAtRaw, out var authorizedAt))
        {
            return (false, "agent uninstall blocked: admin_confirmed_at missing/invalid", null);
        }

        if (DateTimeOffset.UtcNow - authorizedAt.ToUniversalTime() > TimeSpan.FromMinutes(ttlMinutes))
        {
            return (false, "agent uninstall blocked: admin confirmation expired", new Dictionary<string, object?>
            {
                ["authorized_at"] = authorizedAtRaw,
                ["ttl_minutes"] = ttlMinutes,
            });
        }

        if (string.IsNullOrWhiteSpace(confirmationNonce))
        {
            return (false, "agent uninstall blocked: admin_confirmation_nonce missing", null);
        }

        return (true, null, new Dictionary<string, object?>
        {
            ["enforcement_enabled"] = true,
            ["authorized_at"] = authorizedAtRaw,
            ["ttl_minutes"] = ttlMinutes,
            ["confirmation_nonce"] = confirmationNonce,
        });
    }

    private static bool ReadBool(Dictionary<string, object?> payload, string key)
    {
        if (!payload.TryGetValue(key, out var value) || value is null)
        {
            return false;
        }

        return value switch
        {
            bool b => b,
            JsonElement je when je.ValueKind is JsonValueKind.True or JsonValueKind.False => je.GetBoolean(),
            JsonElement je when je.ValueKind == JsonValueKind.String && bool.TryParse(je.GetString(), out bool parsed) => parsed,
            _ => bool.TryParse(value.ToString(), out bool parsed) && parsed,
        };
    }

    private static string ReadString(Dictionary<string, object?> payload, string key)
    {
        if (!payload.TryGetValue(key, out var value) || value is null)
        {
            return string.Empty;
        }

        return value switch
        {
            string s => s,
            JsonElement je when je.ValueKind == JsonValueKind.String => je.GetString() ?? string.Empty,
            _ => value.ToString() ?? string.Empty,
        };
    }

    private static int ReadInt(Dictionary<string, object?> payload, string key, int fallback)
    {
        if (!payload.TryGetValue(key, out var value) || value is null)
        {
            return fallback;
        }

        return value switch
        {
            int i => i,
            long l => (int)l,
            JsonElement je when je.ValueKind == JsonValueKind.Number && je.TryGetInt32(out int i) => i,
            JsonElement je when je.ValueKind == JsonValueKind.String && int.TryParse(je.GetString(), out int i) => i,
            _ when int.TryParse(value.ToString(), out int i) => i,
            _ => fallback,
        };
    }
}

public sealed class ExeUninstallHandler : IJobHandler
{
    public string JobType => "uninstall_exe";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        if (!envelope.Payload.TryGetValue("command", out var commandObj) || commandObj is null)
        {
            return ("failed", 1, new { error = "command missing" });
        }

        string command = commandObj.ToString() ?? string.Empty;
        if (string.IsNullOrWhiteSpace(command))
        {
            return ("failed", 1, new { error = "command empty" });
        }

        bool agentUninstall = envelope.Payload.TryGetValue("agent_uninstall", out var uninstallObj)
            && uninstallObj is not null
            && bool.TryParse(uninstallObj.ToString(), out bool uninstallParsed)
            && uninstallParsed;
        if (agentUninstall)
        {
            var auth = AgentUninstallAuthorization.Validate(envelope.Payload);
            if (!auth.Allowed)
            {
                return ("failed", 1, new { error = auth.Error ?? "agent uninstall blocked", authorization = auth.Details });
            }
        }

        var result = await ProcessRunner.RunAsync("cmd.exe", $"/c {command}", cancellationToken);
        string status = result.ExitCode is 0 or 3010 ? "success" : "failed";
        return (status, result.ExitCode, new { result.StdOut, result.StdErr, command });
    }
}

public sealed class PolicyApplyHandler : IJobHandler
{
    public string JobType => "apply_policy";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        if (!envelope.Payload.TryGetValue("rules", out var rulesObj) || rulesObj is null)
        {
            return ("failed", 1, new { error = "rules missing" });
        }

        JsonElement rulesElement = rulesObj is JsonElement je ? je : JsonSerializer.Deserialize<JsonElement>(JsonSerializer.Serialize(rulesObj));
        if (rulesElement.ValueKind != JsonValueKind.Array)
        {
            return ("failed", 1, new { error = "rules must be an array" });
        }

        var results = new List<Dictionary<string, object?>>();
        bool hasNonCompliant = false;
        bool hasRetryableFailure = false;

        foreach (var rule in rulesElement.EnumerateArray())
        {
            string type = rule.TryGetProperty("type", out var t) ? (t.GetString() ?? string.Empty) : string.Empty;
            bool enforce = !rule.TryGetProperty("enforce", out var enf) || enf.GetBoolean();
            JsonElement config = rule.TryGetProperty("config", out var cfg) ? cfg : default;

            try
            {
                Dictionary<string, object?>? extra = null;
                (bool Compliant, string Message) applied;

                if (string.Equals(type, "baseline_profile", StringComparison.OrdinalIgnoreCase))
                {
                    var baseline = await BuildBaselineProfileReportAsync(config, cancellationToken);
                    applied = (baseline.Compliant, baseline.Message);
                    extra = new Dictionary<string, object?>
                    {
                        ["baseline_report"] = baseline.Report,
                        ["drift_count"] = baseline.DriftCount,
                    };
                }
                else
                {
                    applied = type.ToLowerInvariant() switch
                    {
                        "firewall" => await ApplyFirewallAsync(config, enforce, cancellationToken),
                        "registry" => ApplyRegistry(config, enforce),
                        "local_group" => await ApplyLocalGroupAsync(config, enforce, cancellationToken),
                        "windows_update" => ApplyWindowsUpdate(config, enforce),
                        "bitlocker" => await CheckBitLockerAsync(config, enforce, cancellationToken),
                        "scheduled_task" => await ApplyScheduledTaskAsync(config, enforce, cancellationToken),
                        "command" => await ApplyCommandAsync(config, enforce, cancellationToken),
                        "uwf" => await ApplyUwfAsync(config, enforce, cancellationToken),
                        "reboot_restore_mode" => await ApplyRebootRestoreModeAsync(config, enforce, cancellationToken),
                        _ => (Compliant: false, Message: $"unsupported rule type: {type}"),
                    };
                }

                if (!applied.Compliant)
                {
                    hasNonCompliant = true;
                }

                string message = applied.Message;
                if (string.Equals(type, "uwf", StringComparison.OrdinalIgnoreCase)
                    && TryExtractRetryableMessage(message, out var retryableMessage))
                {
                    hasRetryableFailure = true;
                    message = retryableMessage;
                }
                else if (string.Equals(type, "uwf", StringComparison.OrdinalIgnoreCase)
                    && IsUwfRetryableState(config, enforce, applied.Compliant, message))
                {
                    hasRetryableFailure = true;
                }

                var resultRow = new Dictionary<string, object?>
                {
                    ["type"] = type,
                    ["enforce"] = enforce,
                    ["compliant"] = applied.Compliant,
                    ["message"] = message,
                };
                if (extra is not null)
                {
                    foreach (var item in extra)
                    {
                        resultRow[item.Key] = item.Value;
                    }
                }

                results.Add(resultRow);
            }
            catch (Exception ex)
            {
                hasNonCompliant = true;
                results.Add(new Dictionary<string, object?>
                {
                    ["type"] = type,
                    ["enforce"] = enforce,
                    ["compliant"] = false,
                    ["message"] = ex.Message,
                });
            }
        }

        if (hasRetryableFailure)
        {
            return ("failed", 1, new
            {
                error = ErrorCodes.Transient,
                compliance_status = "non_compliant",
                rules = results,
            });
        }

        string status = hasNonCompliant ? "non_compliant" : "success";
        return (status, hasNonCompliant ? 2 : 0, new
        {
            compliance_status = hasNonCompliant ? "non_compliant" : "compliant",
            rules = results,
        });
    }

    private static bool TryExtractRetryableMessage(string message, out string cleaned)
    {
        const string prefix = "retryable:";
        cleaned = message;
        if (string.IsNullOrWhiteSpace(message) || !message.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        cleaned = message[prefix.Length..].TrimStart();
        if (string.IsNullOrWhiteSpace(cleaned))
        {
            cleaned = "transient uwf state; retry required";
        }
        return true;
    }

    private static bool IsUwfRetryableState(JsonElement config, bool enforce, bool compliant, string message)
    {
        if (!enforce || compliant)
        {
            return false;
        }

        bool rebootNow = config.TryGetProperty("reboot_now", out var rebootNowNode) && rebootNowNode.ValueKind == JsonValueKind.True;
        bool rebootIfPending = !config.TryGetProperty("reboot_if_pending", out var rebootPendingNode) || rebootPendingNode.ValueKind != JsonValueKind.False;
        if (!rebootNow || !rebootIfPending)
        {
            return false;
        }

        if (string.IsNullOrWhiteSpace(message))
        {
            return false;
        }

        string lower = message.ToLowerInvariant();
        return lower.Contains("uwf pending reboot", StringComparison.Ordinal)
            || lower.Contains("reboot cooldown active", StringComparison.Ordinal)
            || lower.Contains("reboot queued", StringComparison.Ordinal);
    }

    private static async Task<(bool Compliant, string Message)> ApplyFirewallAsync(JsonElement config, bool enforce, CancellationToken cancellationToken)
    {
        bool enabled = !config.TryGetProperty("enabled", out var enabledNode) || enabledNode.GetBoolean();
        string state = config.TryGetProperty("state", out var stateNode)
            ? (stateNode.GetString() ?? string.Empty).Trim().ToLowerInvariant()
            : (enabled ? "on" : "off");
        if (state is not ("on" or "off"))
        {
            state = enabled ? "on" : "off";
        }

        if (!enabled && !config.TryGetProperty("state", out _))
        {
            return (true, "firewall rule disabled in policy");
        }

        if (enforce)
        {
            var setResult = await ProcessRunner.RunAsync("netsh", $"advfirewall set allprofiles state {state}", cancellationToken);
            if (setResult.ExitCode != 0)
            {
                return (false, $"failed to enforce firewall state {state}");
            }

            if (config.TryGetProperty("rules", out var rulesNode) && rulesNode.ValueKind == JsonValueKind.Array)
            {
                foreach (var rule in rulesNode.EnumerateArray())
                {
                    string name = rule.TryGetProperty("name", out var nameNode) ? (nameNode.GetString() ?? string.Empty).Trim() : string.Empty;
                    if (string.IsNullOrWhiteSpace(name))
                    {
                        continue;
                    }

                    string ensure = rule.TryGetProperty("ensure", out var ensureNode)
                        ? (ensureNode.GetString() ?? "present").Trim().ToLowerInvariant()
                        : "present";
                    if (ensure == "absent")
                    {
                        await ProcessRunner.RunAsync("netsh", $"advfirewall firewall delete rule name=\"{name}\"", cancellationToken);
                        continue;
                    }

                    string direction = rule.TryGetProperty("direction", out var dirNode) ? (dirNode.GetString() ?? "in").Trim().ToLowerInvariant() : "in";
                    if (direction is not ("in" or "out"))
                    {
                        direction = "in";
                    }
                    string action = rule.TryGetProperty("action", out var actionNode) ? (actionNode.GetString() ?? "allow").Trim().ToLowerInvariant() : "allow";
                    if (action is not ("allow" or "block"))
                    {
                        action = "allow";
                    }
                    string protocol = rule.TryGetProperty("protocol", out var protoNode) ? (protoNode.GetString() ?? "any").Trim() : "any";
                    string localPort = rule.TryGetProperty("local_port", out var localPortNode) ? (localPortNode.GetString() ?? "any").Trim() : "any";
                    string remotePort = rule.TryGetProperty("remote_port", out var remotePortNode) ? (remotePortNode.GetString() ?? "any").Trim() : "any";
                    string profile = rule.TryGetProperty("profile", out var profileNode) ? (profileNode.GetString() ?? "any").Trim().ToLowerInvariant() : "any";
                    string program = rule.TryGetProperty("program", out var programNode) ? (programNode.GetString() ?? string.Empty).Trim() : string.Empty;

                    var builder = new StringBuilder();
                    builder.Append($"advfirewall firewall add rule name=\"{name}\" dir={direction} action={action}");
                    builder.Append($" protocol={protocol}");
                    builder.Append($" localport={localPort}");
                    builder.Append($" remoteport={remotePort}");
                    builder.Append($" profile={profile}");
                    if (!string.IsNullOrWhiteSpace(program))
                    {
                        builder.Append($" program=\"{program}\"");
                    }
                    await ProcessRunner.RunAsync("netsh", builder.ToString(), cancellationToken);
                }
            }
        }

        var check = await ProcessRunner.RunAsync("netsh", "advfirewall show allprofiles", cancellationToken);
        bool stateMatched = state == "off"
            ? check.StdOut.Contains("State OFF", StringComparison.OrdinalIgnoreCase)
            : check.StdOut.Contains("State ON", StringComparison.OrdinalIgnoreCase);
        return (stateMatched, stateMatched ? $"firewall state {state}" : $"firewall state not {state}");
    }

    private static (bool Compliant, string Message) ApplyRegistry(JsonElement config, bool enforce)
    {
        string path = config.TryGetProperty("path", out var pathNode) ? (pathNode.GetString() ?? string.Empty) : string.Empty;
        string name = config.TryGetProperty("name", out var nameNode) ? (nameNode.GetString() ?? string.Empty) : string.Empty;
        string type = config.TryGetProperty("type", out var typeNode) ? (typeNode.GetString() ?? "STRING") : "STRING";
        string ensure = config.TryGetProperty("ensure", out var ensureNode) ? (ensureNode.GetString() ?? "present") : "present";
        bool ensureAbsent = string.Equals(ensure, "absent", StringComparison.OrdinalIgnoreCase);
        if (!ensureAbsent && !string.Equals(ensure, "present", StringComparison.OrdinalIgnoreCase))
        {
            return (false, "invalid ensure value");
        }
        if (string.IsNullOrWhiteSpace(path) || string.IsNullOrWhiteSpace(name))
        {
            return (false, "path/name missing");
        }

        object value = ExtractRegistryValue(config);

        var (hive, subKey) = SplitRegistryPath(path);
        if (hive is null || string.IsNullOrWhiteSpace(subKey))
        {
            return (false, "invalid registry path");
        }

        if (enforce)
        {
            if (ensureAbsent)
            {
                using var key = hive.OpenSubKey(subKey, true);
                key?.DeleteValue(name, false);
            }
            else
            {
                using var key = hive.CreateSubKey(subKey, true);
                if (key is null)
                {
                    return (false, "failed to open registry path");
                }
                key.SetValue(name, value, ParseRegistryValueKind(type));
            }
        }

        using var readKey = hive.OpenSubKey(subKey, false);
        object? current = readKey?.GetValue(name);
        if (ensureAbsent)
        {
            bool absent = current is null;
            return (absent, absent ? "registry value absent" : "registry value still present");
        }

        bool match = current != null && string.Equals(current.ToString(), value.ToString(), StringComparison.OrdinalIgnoreCase);
        return (match, match ? "registry value matches" : "registry value mismatch");
    }

    private static async Task<(bool Compliant, string Message)> ApplyLocalGroupAsync(JsonElement config, bool enforce, CancellationToken cancellationToken)
    {
        string group = config.TryGetProperty("group", out var groupNode) ? (groupNode.GetString() ?? "Administrators") : "Administrators";
        string ensure = config.TryGetProperty("ensure", out var ensureNode) ? (ensureNode.GetString() ?? "present") : "present";
        ensure = ensure.Trim().ToLowerInvariant();
        bool ensureAbsent = string.Equals(ensure, "absent", StringComparison.OrdinalIgnoreCase);
        bool restorePrevious = !config.TryGetProperty("restore_previous", out var restoreNode) || restoreNode.ValueKind != JsonValueKind.False;
        bool strictRestore = !config.TryGetProperty("strict_restore", out var strictNode) || strictNode.ValueKind != JsonValueKind.False;
        var allowed = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        if (config.TryGetProperty("allowed_members", out var membersNode) && membersNode.ValueKind == JsonValueKind.Array)
        {
            foreach (var m in membersNode.EnumerateArray())
            {
                var value = m.GetString();
                if (!string.IsNullOrWhiteSpace(value))
                {
                    allowed.Add(value);
                }
            }
        }

        var listResult = await ProcessRunner.RunAsync("net", $"localgroup \"{group}\"", cancellationToken);
        if (listResult.ExitCode != 0)
        {
            return (false, "cannot list local group members");
        }

        var actual = ParseNetLocalGroupMembers(listResult.StdOut);
        var actualSet = new HashSet<string>(actual, StringComparer.OrdinalIgnoreCase);
        string statePath = ResolveLocalGroupStatePath(config, group);

        if (ensureAbsent)
        {
            if (!restorePrevious)
            {
                return (true, "local group cleanup skipped (restore_previous=false)");
            }

            var snapshotMembers = ReadLocalGroupSnapshotMembers(statePath);
            if (snapshotMembers.Count == 0)
            {
                return (true, "no previous local group snapshot found");
            }

            if (enforce)
            {
                foreach (var member in snapshotMembers)
                {
                    if (!actualSet.Contains(member))
                    {
                        await ProcessRunner.RunAsync("net", $"localgroup \"{group}\" \"{member}\" /add", cancellationToken);
                    }
                }
            }

            var verifyResult = await ProcessRunner.RunAsync("net", $"localgroup \"{group}\"", cancellationToken);
            if (verifyResult.ExitCode != 0)
            {
                return (false, "cannot verify local group members after restore");
            }

            var current = ParseNetLocalGroupMembers(verifyResult.StdOut);
            var currentSet = new HashSet<string>(current, StringComparer.OrdinalIgnoreCase);

            var removed = new List<string>();
            if (enforce && strictRestore)
            {
                var extras = current
                    .Where(member => !snapshotMembers.Contains(member))
                    .Distinct(StringComparer.OrdinalIgnoreCase)
                    .ToList();
                foreach (var extra in extras)
                {
                    var deleteResult = await ProcessRunner.RunAsync("net", $"localgroup \"{group}\" \"{extra}\" /delete", cancellationToken);
                    if (deleteResult.ExitCode == 0)
                    {
                        removed.Add(extra);
                    }
                }

                var strictVerify = await ProcessRunner.RunAsync("net", $"localgroup \"{group}\"", cancellationToken);
                if (strictVerify.ExitCode != 0)
                {
                    return (false, "cannot verify local group members after strict restore");
                }
                current = ParseNetLocalGroupMembers(strictVerify.StdOut);
                currentSet = new HashSet<string>(current, StringComparer.OrdinalIgnoreCase);
            }

            var missing = snapshotMembers.Where(member => !currentSet.Contains(member)).ToList();
            var remainingExtras = strictRestore
                ? current.Where(member => !snapshotMembers.Contains(member)).Distinct(StringComparer.OrdinalIgnoreCase).ToList()
                : new List<string>();
            bool restored = missing.Count == 0 && (!strictRestore || remainingExtras.Count == 0);
            if (restored)
            {
                TryDeleteLocalGroupSnapshot(statePath);
            }

            return (restored, restored
                ? $"local group restored ({snapshotMembers.Count} member(s), removed {removed.Count} extra member(s))"
                : $"local group restore incomplete, missing: {string.Join(", ", missing)}{(strictRestore ? $"; remaining extras: {string.Join(", ", remainingExtras)}" : string.Empty)}");
        }

        if (enforce && !File.Exists(statePath))
        {
            TryWriteLocalGroupSnapshot(statePath, group, actual);
        }

        var unauthorized = actual.Where(x => !allowed.Contains(x)).ToList();

        if (enforce)
        {
            foreach (var member in unauthorized)
            {
                await ProcessRunner.RunAsync("net", $"localgroup \"{group}\" \"{member}\" /delete", cancellationToken);
            }
        }

        bool compliant = unauthorized.Count == 0;
        return (compliant, compliant ? "local group compliant" : $"unauthorized members: {string.Join(", ", unauthorized)}");
    }

    private static (bool Compliant, string Message) ApplyWindowsUpdate(JsonElement config, bool enforce)
    {
        int start = config.TryGetProperty("active_hours_start", out var startNode) ? startNode.GetInt32() : 8;
        int end = config.TryGetProperty("active_hours_end", out var endNode) ? endNode.GetInt32() : 17;
        const string wuPath = @"HKLM\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings";
        const string wuPolicyRoot = @"HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate";
        const string wuPolicyAu = @"HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU";

        if (enforce)
        {
            ApplyRegistry(JsonSerializer.Deserialize<JsonElement>($$"""{"path":"{{wuPath}}","name":"ActiveHoursStart","type":"DWORD","value":{{start}}}"""), true);
            ApplyRegistry(JsonSerializer.Deserialize<JsonElement>($$"""{"path":"{{wuPath}}","name":"ActiveHoursEnd","type":"DWORD","value":{{end}}}"""), true);
            if (config.TryGetProperty("au_options", out var auNode) && auNode.ValueKind == JsonValueKind.Number && auNode.TryGetInt32(out var auOptions))
            {
                ApplyRegistry(JsonSerializer.Deserialize<JsonElement>($$"""{"path":"{{wuPolicyAu}}","name":"AUOptions","type":"DWORD","value":{{auOptions}}}"""), true);
            }
            if (config.TryGetProperty("no_auto_reboot_with_logged_on_users", out var noRebootNode) && (noRebootNode.ValueKind is JsonValueKind.True or JsonValueKind.False))
            {
                int noReboot = noRebootNode.GetBoolean() ? 1 : 0;
                ApplyRegistry(JsonSerializer.Deserialize<JsonElement>($$"""{"path":"{{wuPolicyAu}}","name":"NoAutoRebootWithLoggedOnUsers","type":"DWORD","value":{{noReboot}}}"""), true);
            }
            if (config.TryGetProperty("pause_updates_days", out var pauseNode) && pauseNode.ValueKind == JsonValueKind.Number && pauseNode.TryGetInt32(out var pauseDays))
            {
                if (pauseDays > 0)
                {
                    ApplyRegistry(JsonSerializer.Deserialize<JsonElement>($$"""{"path":"{{wuPolicyRoot}}","name":"DeferQualityUpdatesPeriodInDays","type":"DWORD","value":{{pauseDays}}}"""), true);
                    ApplyRegistry(JsonSerializer.Deserialize<JsonElement>($$"""{"path":"{{wuPolicyRoot}}","name":"DeferFeatureUpdatesPeriodInDays","type":"DWORD","value":{{pauseDays}}}"""), true);
                }
            }
        }

        return (true, $"windows update active hours {start}-{end}");
    }

    private static async Task<(bool Compliant, string Message)> CheckBitLockerAsync(JsonElement config, bool enforce, CancellationToken cancellationToken)
    {
        string drive = config.TryGetProperty("drive", out var driveNode) ? (driveNode.GetString() ?? "C:") : "C:";
        if (string.IsNullOrWhiteSpace(drive))
        {
            drive = "C:";
        }
        bool required = !config.TryGetProperty("required", out var requiredNode) || requiredNode.ValueKind != JsonValueKind.False;
        bool autoEnable = config.TryGetProperty("auto_enable", out var autoNode) && autoNode.ValueKind == JsonValueKind.True;

        var result = await ProcessRunner.RunAsync("manage-bde", $"-status {drive}", cancellationToken);
        if (result.ExitCode != 0)
        {
            return (false, "bitlocker status check failed");
        }

        bool enabled = result.StdOut.Contains("Protection On", StringComparison.OrdinalIgnoreCase);
        if (enabled)
        {
            return (true, "bitlocker protection on");
        }

        if (!required)
        {
            return (true, "bitlocker not required by policy");
        }

        if (enforce && autoEnable)
        {
            var enableResult = await ProcessRunner.RunAsync("manage-bde", $"-on {drive} -usedspaceonly -skiphardwaretest", cancellationToken);
            var verify = await ProcessRunner.RunAsync("manage-bde", $"-status {drive}", cancellationToken);
            bool onAfter = verify.ExitCode == 0 && verify.StdOut.Contains("Protection On", StringComparison.OrdinalIgnoreCase);
            if (onAfter)
            {
                return (true, "bitlocker auto-enable initiated");
            }
            return (false, $"bitlocker auto-enable failed (exit {enableResult.ExitCode})");
        }

        if (enforce)
        {
            return (false, "bitlocker is off (auto-enable not attempted by agent)");
        }

        return (false, "bitlocker is off");
    }

    private static async Task<(bool Compliant, string Message)> ApplyScheduledTaskAsync(JsonElement config, bool enforce, CancellationToken cancellationToken)
    {
        string taskName = config.TryGetProperty("task_name", out var taskNameNode) ? taskNameNode.GetString() ?? string.Empty : string.Empty;
        if (string.IsNullOrWhiteSpace(taskName))
        {
            return (false, "scheduled_task: task_name missing");
        }

        string ensure = config.TryGetProperty("ensure", out var ensureNode) ? (ensureNode.GetString() ?? "present") : "present";
        ensure = ensure.Trim().ToLowerInvariant();
        if (ensure is not ("present" or "absent"))
        {
            return (false, "scheduled_task: ensure must be present or absent");
        }

        if (ensure == "absent")
        {
            if (enforce)
            {
                await ProcessRunner.RunAsync("schtasks", $"/Delete /TN \"{taskName}\" /F", cancellationToken);
            }

            var queryAfterDelete = await ProcessRunner.RunAsync("schtasks", $"/Query /TN \"{taskName}\"", cancellationToken);
            bool exists = queryAfterDelete.ExitCode == 0;
            return (!exists, exists ? "scheduled task still exists" : "scheduled task absent");
        }

        string schedule = config.TryGetProperty("schedule", out var scheduleNode) ? (scheduleNode.GetString() ?? "daily") : "daily";
        schedule = schedule.Trim().ToLowerInvariant();
        string command = config.TryGetProperty("command", out var commandNode) ? commandNode.GetString() ?? string.Empty : string.Empty;
        string time = config.TryGetProperty("time", out var timeNode) ? timeNode.GetString() ?? string.Empty : string.Empty;
        if (string.IsNullOrWhiteSpace(command))
        {
            return (false, "scheduled_task: command missing");
        }

        string scheduleArgs = schedule switch
        {
            "daily" => "/SC DAILY",
            "weekly" => "/SC WEEKLY",
            "hourly" => "/SC HOURLY /MO 1",
            "onstart" => "/SC ONSTART",
            "onlogon" => "/SC ONLOGON",
            _ => string.Empty,
        };
        if (string.IsNullOrWhiteSpace(scheduleArgs))
        {
            return (false, $"scheduled_task: unsupported schedule {schedule}");
        }

        string timeArg = string.Empty;
        if (schedule is not ("onstart" or "onlogon") && !string.IsNullOrWhiteSpace(time))
        {
            timeArg = $" /ST {time}";
        }

        if (enforce)
        {
            string createArgs = $"/Create /TN \"{taskName}\" /TR \"{command}\" {scheduleArgs}{timeArg} /F";
            var createResult = await ProcessRunner.RunAsync("schtasks", createArgs, cancellationToken);
            if (createResult.ExitCode != 0)
            {
                return (false, $"scheduled task create failed: {createResult.StdErr}");
            }
        }

        var query = await ProcessRunner.RunAsync("schtasks", $"/Query /TN \"{taskName}\"", cancellationToken);
        bool present = query.ExitCode == 0;
        return (present, present ? "scheduled task present" : "scheduled task missing");
    }

    private static async Task<(bool Compliant, string Message)> ApplyCommandAsync(JsonElement config, bool enforce, CancellationToken cancellationToken)
    {
        string command = config.TryGetProperty("command", out var commandNode) ? commandNode.GetString() ?? string.Empty : string.Empty;
        if (string.IsNullOrWhiteSpace(command))
        {
            return (false, "command: command missing");
        }

        string runAs = config.TryGetProperty("run_as", out var runAsNode)
            ? (runAsNode.GetString() ?? "default").Trim().ToLowerInvariant()
            : "default";
        if (runAs is not ("default" or "elevated" or "system"))
        {
            return (false, "command: run_as must be one of default|elevated|system");
        }

        int timeoutSeconds = 300;
        if (config.TryGetProperty("timeout_seconds", out var timeoutNode)
            && timeoutNode.ValueKind == JsonValueKind.Number
            && timeoutNode.TryGetInt32(out int parsedTimeout))
        {
            timeoutSeconds = Math.Clamp(parsedTimeout, 30, 3600);
        }

        if (!enforce)
        {
            return (true, $"command skipped in audit mode (run_as={runAs})");
        }

        if (RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            if (runAs == "elevated" && !IsWindowsAdministrator(out string elevatedIdentity))
            {
                return (false, $"command requires elevated context; current_identity={elevatedIdentity}");
            }

            if (runAs == "system")
            {
                if (IsLocalSystemIdentity())
                {
                    var directSystemRun = await ProcessRunner.RunPolicyCommandAsync(command, cancellationToken);
                    bool directOk = directSystemRun.ExitCode is 0 or 3010;
                    if (directOk)
                    {
                        return (true, "command executed as system");
                    }

                    string directDetail = ExtractProcessFailureDetail(directSystemRun.StdErr, directSystemRun.StdOut);
                    return (false, string.IsNullOrWhiteSpace(directDetail)
                        ? $"command failed exit code {directSystemRun.ExitCode}"
                        : $"command failed exit code {directSystemRun.ExitCode}: {directDetail}");
                }

                if (!IsWindowsAdministrator(out string adminIdentity))
                {
                    return (false, $"command run_as=system requires Administrator/SYSTEM context; current_identity={adminIdentity}");
                }

                var systemRun = await RunCommandAsSystemViaScheduledTaskAsync(command, timeoutSeconds, cancellationToken);
                bool systemOk = systemRun.ExitCode is 0 or 3010;
                if (systemOk)
                {
                    return (true, "command executed as system");
                }

                string systemDetail = ExtractProcessFailureDetail(systemRun.StdErr, systemRun.StdOut);
                return (false, string.IsNullOrWhiteSpace(systemDetail)
                    ? $"command failed exit code {systemRun.ExitCode}"
                    : $"command failed exit code {systemRun.ExitCode}: {systemDetail}");
            }
        }

        var run = await ProcessRunner.RunPolicyCommandAsync(command, cancellationToken);
        bool ok = run.ExitCode is 0 or 3010;
        if (ok)
        {
            return (true, runAs == "elevated" ? "command executed (elevated)" : "command executed");
        }

        string detail = ExtractProcessFailureDetail(run.StdErr, run.StdOut);
        return (false, string.IsNullOrWhiteSpace(detail)
            ? $"command failed exit code {run.ExitCode}"
            : $"command failed exit code {run.ExitCode}: {detail}");
    }

    private static async Task<(int ExitCode, string StdOut, string StdErr)> RunCommandAsSystemViaScheduledTaskAsync(
        string command,
        int timeoutSeconds,
        CancellationToken cancellationToken)
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData")
            ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
            ?? @"C:\ProgramData";
        string root = Path.Combine(programData, "DMS", "PolicyCommand");
        Directory.CreateDirectory(root);

        string token = Guid.NewGuid().ToString("N");
        string taskName = $"DMS-PolicyCmd-{token}";
        string scriptPath = Path.Combine(root, $"{token}.ps1");
        string stdoutPath = Path.Combine(root, $"{token}.stdout.log");
        string exitPath = Path.Combine(root, $"{token}.exitcode.txt");

        string escapedStdOutPath = stdoutPath.Replace("'", "''", StringComparison.Ordinal);
        string escapedExitPath = exitPath.Replace("'", "''", StringComparison.Ordinal);
        string script = string.Join(Environment.NewLine, new[]
        {
            "$ErrorActionPreference='Continue'",
            "$cmd = @'",
            command,
            "'@",
            $"cmd.exe /c $cmd > '{escapedStdOutPath}' 2>&1",
            "$code = $LASTEXITCODE",
            $"Set-Content -Path '{escapedExitPath}' -Value ([string]$code) -Encoding ascii -Force",
            "exit $code",
        });
        await File.WriteAllTextAsync(scriptPath, script, Encoding.UTF8, cancellationToken);

        string taskRunCommand = $"powershell.exe -NoProfile -ExecutionPolicy Bypass -File {scriptPath}";
        try
        {
            var create = await ProcessRunner.RunAsync(
                "schtasks",
                $"/Create /TN \"{taskName}\" /SC ONCE /ST 00:00 /RU SYSTEM /RL HIGHEST /TR \"{taskRunCommand}\" /F",
                cancellationToken);
            if (create.ExitCode != 0)
            {
                return (create.ExitCode, create.StdOut, create.StdErr);
            }

            var run = await ProcessRunner.RunAsync("schtasks", $"/Run /TN \"{taskName}\"", cancellationToken);
            if (run.ExitCode != 0)
            {
                return (run.ExitCode, run.StdOut, run.StdErr);
            }

            DateTime deadlineUtc = DateTime.UtcNow.AddSeconds(timeoutSeconds);
            while (DateTime.UtcNow < deadlineUtc)
            {
                cancellationToken.ThrowIfCancellationRequested();
                if (File.Exists(exitPath))
                {
                    string stdout = File.Exists(stdoutPath) ? await File.ReadAllTextAsync(stdoutPath, cancellationToken) : string.Empty;
                    string exitRaw = (await File.ReadAllTextAsync(exitPath, cancellationToken)).Trim();
                    if (!int.TryParse(exitRaw, out int exitCode))
                    {
                        exitCode = 1;
                    }
                    return (exitCode, stdout, string.Empty);
                }

                await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken);
            }

            string timeoutStdOut = File.Exists(stdoutPath) ? await File.ReadAllTextAsync(stdoutPath, cancellationToken) : string.Empty;
            return (1, timeoutStdOut, $"system command timed out after {timeoutSeconds} seconds");
        }
        finally
        {
            try
            {
                await ProcessRunner.RunAsync("schtasks", $"/Delete /TN \"{taskName}\" /F", cancellationToken);
            }
            catch
            {
                // Best-effort task cleanup.
            }

            TryDeletePolicyCommandFile(scriptPath);
            TryDeletePolicyCommandFile(stdoutPath);
            TryDeletePolicyCommandFile(exitPath);
        }
    }

    private static bool IsLocalSystemIdentity()
    {
        try
        {
            using var identity = WindowsIdentity.GetCurrent();
            return identity?.User?.IsWellKnown(WellKnownSidType.LocalSystemSid) == true;
        }
        catch
        {
            return false;
        }
    }

    private static void TryDeletePolicyCommandFile(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // Best-effort file cleanup.
        }
    }

    private static string ExtractProcessFailureDetail(string stdErr, string stdOut)
    {
        static string Normalize(string value)
        {
            if (string.IsNullOrWhiteSpace(value))
            {
                return string.Empty;
            }

            string cleaned = value.Replace("\r", " ").Replace("\n", " ").Trim();
            return cleaned.Length > 280 ? cleaned[..280] + "..." : cleaned;
        }

        string err = Normalize(stdErr);
        if (!string.IsNullOrWhiteSpace(err))
        {
            return err;
        }

        return Normalize(stdOut);
    }

    private static async Task<(bool Compliant, string Message)> ApplyUwfAsync(JsonElement config, bool enforce, CancellationToken cancellationToken)
    {
        string ensure = config.TryGetProperty("ensure", out var ensureNode)
            ? (ensureNode.GetString() ?? "present").Trim().ToLowerInvariant()
            : "present";
        bool ensureAbsent = ensure == "absent";
        bool dryRun = config.TryGetProperty("dry_run", out var dryRunNode) && dryRunNode.ValueKind == JsonValueKind.True;
        bool enableFeature = !config.TryGetProperty("enable_feature", out var enableFeatureNode) || enableFeatureNode.ValueKind != JsonValueKind.False;
        bool enableFilter = !config.TryGetProperty("enable_filter", out var enableFilterNode) || enableFilterNode.ValueKind != JsonValueKind.False;
        bool protectVolume = !config.TryGetProperty("protect_volume", out var protectVolumeNode) || protectVolumeNode.ValueKind != JsonValueKind.False;
        bool rebootNow = config.TryGetProperty("reboot_now", out var rebootNowNode) && rebootNowNode.ValueKind == JsonValueKind.True;
        bool rebootIfPending = !config.TryGetProperty("reboot_if_pending", out var rebootPendingNode) || rebootPendingNode.ValueKind != JsonValueKind.False;
        string overlayType = config.TryGetProperty("overlay_type", out var overlayTypeNode)
            ? (overlayTypeNode.GetString() ?? string.Empty).Trim().ToLowerInvariant()
            : string.Empty;
        if (overlayType is not ("ram" or "disk"))
        {
            overlayType = string.Empty;
        }

        int? overlayMaxSizeMb = TryReadUwfPositiveInt(config, "overlay_max_size_mb", min: 128, max: 1048576);
        int? overlayWarningThresholdMb = TryReadUwfPositiveInt(config, "overlay_warning_threshold_mb", min: 64, max: 1048576);
        int? overlayCriticalThresholdMb = TryReadUwfPositiveInt(config, "overlay_critical_threshold_mb", min: 64, max: 1048576);
        if (overlayWarningThresholdMb.HasValue && overlayCriticalThresholdMb.HasValue
            && overlayCriticalThresholdMb.Value <= overlayWarningThresholdMb.Value)
        {
            overlayCriticalThresholdMb = overlayWarningThresholdMb.Value + 1;
        }

        int maxRebootAttempts = 2;
        if (config.TryGetProperty("max_reboot_attempts", out var attemptsNode) && attemptsNode.ValueKind == JsonValueKind.Number && attemptsNode.TryGetInt32(out int parsedAttempts))
        {
            maxRebootAttempts = Math.Clamp(parsedAttempts, 1, 10);
        }

        int rebootCooldownMinutes = 30;
        if (config.TryGetProperty("reboot_cooldown_minutes", out var cooldownNode) && cooldownNode.ValueKind == JsonValueKind.Number && cooldownNode.TryGetInt32(out int parsedCooldown))
        {
            rebootCooldownMinutes = Math.Clamp(parsedCooldown, 1, 240);
        }

        string volume = config.TryGetProperty("volume", out var volumeNode) ? (volumeNode.GetString() ?? "C:").Trim() : "C:";
        if (string.IsNullOrWhiteSpace(volume))
        {
            volume = "C:";
        }
        if (volume.Length == 1 && char.IsLetter(volume[0]))
        {
            volume += ":";
        }

        string rebootCommand = "shutdown.exe /r /t 30 /c \"DMS UWF policy reboot\"";
        if (config.TryGetProperty("reboot_command", out var rebootCommandNode) && rebootCommandNode.ValueKind == JsonValueKind.String)
        {
            rebootCommand = (rebootCommandNode.GetString() ?? rebootCommand).Trim();
            if (string.IsNullOrWhiteSpace(rebootCommand))
            {
                rebootCommand = "shutdown.exe /r /t 30 /c \"DMS UWF policy reboot\"";
            }
        }

        string configSignature = BuildUwfConfigSignature(
            ensure,
            enableFeature,
            enableFilter,
            protectVolume,
            volume,
            rebootCommand,
            rebootIfPending,
            maxRebootAttempts,
            rebootCooldownMinutes,
            overlayType,
            overlayMaxSizeMb,
            overlayWarningThresholdMb,
            overlayCriticalThresholdMb);

        if (dryRun)
        {
            return (true,
                $"uwf dry-run ensure={ensure} volume={volume} enable_feature={enableFeature} enable_filter={enableFilter} protect_volume={protectVolume} reboot_now={rebootNow} reboot_if_pending={rebootIfPending} max_reboot_attempts={maxRebootAttempts} reboot_cooldown_minutes={rebootCooldownMinutes} overlay_type={(string.IsNullOrWhiteSpace(overlayType) ? "unchanged" : overlayType)} overlay_max_size_mb={(overlayMaxSizeMb.HasValue ? overlayMaxSizeMb.Value.ToString() : "unchanged")} overlay_warning_threshold_mb={(overlayWarningThresholdMb.HasValue ? overlayWarningThresholdMb.Value.ToString() : "unchanged")} overlay_critical_threshold_mb={(overlayCriticalThresholdMb.HasValue ? overlayCriticalThresholdMb.Value.ToString() : "unchanged")}");
        }

        if (!RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            return ensureAbsent
                ? (true, "uwf not applicable on non-Windows endpoint")
                : (false, "uwf policy requires Windows endpoint");
        }

        string windowsEdition = ReadWindowsEditionSummary();
        bool supportedEdition = IsLikelyUwfSupportedEdition(windowsEdition);
        bool failOnUnsupportedEdition = config.TryGetProperty("fail_on_unsupported_edition", out var failOnUnsupportedNode)
            && failOnUnsupportedNode.ValueKind == JsonValueKind.True;
        if (!supportedEdition)
        {
            string unsupportedMessage = $"uwf not supported on this Windows edition ({windowsEdition}); requires Enterprise/Education/IoT Enterprise";
            if (failOnUnsupportedEdition)
            {
                return (false, unsupportedMessage);
            }

            TryClearUwfRebootGuardState(volume);
            return (true, $"uwf skipped: {unsupportedMessage}");
        }

        if (!IsWindowsAdministrator(out string currentIdentity))
        {
            return (false, $"uwf requires elevated context (Administrator/SYSTEM). current_identity={currentIdentity}");
        }

        string windowsDir = Environment.GetEnvironmentVariable("WINDIR") ?? @"C:\Windows";
        string uwfPath = Path.Combine(windowsDir, "System32", "uwfmgr.exe");
        bool toolAvailable = File.Exists(uwfPath);
        var featureState = await GetUwfFeatureStateAsync(cancellationToken);
        bool featureEnabled = featureState.Enabled;

        if (ensureAbsent && !toolAvailable && !featureEnabled)
        {
            TryClearUwfRebootGuardState(volume);
            return (true, "uwf already absent (uwfmgr.exe not found)");
        }

        if (!featureEnabled)
        {
            if (!enableFeature)
            {
                return (false, "uwf feature is not installed and enable_feature=false");
            }
            if (!enforce)
            {
                return (false, "uwf feature is not installed (audit mode)");
            }

            var enableResult = await ProcessRunner.RunAsync("dism.exe", "/online /Enable-Feature /FeatureName:Client-UnifiedWriteFilter /All /NoRestart", cancellationToken);
            if (enableResult.ExitCode is not (0 or 3010))
            {
                string detail = ExtractProcessFailureDetail(enableResult.StdErr, enableResult.StdOut);
                return (false, string.IsNullOrWhiteSpace(detail)
                    ? $"uwf feature enable failed exit code {enableResult.ExitCode}"
                    : $"uwf feature enable failed exit code {enableResult.ExitCode}: {detail}");
            }

            toolAvailable = File.Exists(uwfPath);
            featureEnabled = enableResult.ExitCode == 0 && featureState.Enabled
                ? featureState.Enabled
                : (await GetUwfFeatureStateAsync(cancellationToken)).Enabled;
            if (!toolAvailable || !featureEnabled || enableResult.ExitCode == 3010)
            {
                if (!rebootNow || !rebootIfPending)
                {
                    return (false, "uwf feature enable requires reboot; set reboot_now=true to continue");
                }

                var rebootOutcome = await TryQueueUwfRebootAsync(
                    volume,
                    configSignature,
                    "feature_pending_reboot",
                    maxRebootAttempts,
                    rebootCooldownMinutes,
                    rebootCommand,
                    "uwf feature enable pending reboot",
                    cancellationToken);
                if (rebootOutcome.Compliant)
                {
                    return (true, rebootOutcome.Message);
                }

                return (false, rebootOutcome.Message);
            }
        }

        if (!toolAvailable)
        {
            return (false, "uwf tool unavailable after feature check (uwfmgr.exe not found)");
        }

        if (enforce)
        {
            string? commandFailureMessage = null;
            string[] fileExclusions = ReadUwfStringListConfig(config, "file_exclusions");
            string[] registryExclusions = ReadUwfStringListConfig(config, "registry_exclusions");

            if (!ensureAbsent)
            {
                string? overlayError = await ApplyUwfOverlaySettingsAsync(
                    uwfPath,
                    overlayType,
                    overlayMaxSizeMb,
                    overlayWarningThresholdMb,
                    overlayCriticalThresholdMb,
                    cancellationToken);
                if (!string.IsNullOrWhiteSpace(overlayError))
                {
                    commandFailureMessage = overlayError;
                }

                string? fileExclusionError = await ApplyUwfExclusionsAsync(uwfPath, "file", fileExclusions, cancellationToken);
                if (!string.IsNullOrWhiteSpace(fileExclusionError))
                {
                    commandFailureMessage = string.IsNullOrWhiteSpace(commandFailureMessage)
                        ? fileExclusionError
                        : $"{commandFailureMessage}; {fileExclusionError}";
                }

                string? registryExclusionError = await ApplyUwfExclusionsAsync(uwfPath, "registry", registryExclusions, cancellationToken);
                if (!string.IsNullOrWhiteSpace(registryExclusionError))
                {
                    commandFailureMessage = string.IsNullOrWhiteSpace(commandFailureMessage)
                        ? registryExclusionError
                        : $"{commandFailureMessage}; {registryExclusionError}";
                }
            }

            var volumeResult = await RunUwfCommandAsync(
                uwfPath,
                protectVolume ? $"volume protect {volume}" : $"volume unprotect {volume}",
                allowSystemFallback: true,
                cancellationToken);
            if (volumeResult.ExitCode != 0)
            {
                string detail = ExtractProcessFailureDetail(volumeResult.StdErr, volumeResult.StdOut);
                if (IsWindowsAccessDeniedExitCode(volumeResult.ExitCode))
                {
                    commandFailureMessage = await BuildUwfAccessDeniedMessageAsync("volume", volumeResult.ExitCode, cancellationToken);
                }
                else
                {
                    commandFailureMessage = string.IsNullOrWhiteSpace(detail)
                        ? $"uwf volume command failed exit code {volumeResult.ExitCode}"
                        : $"uwf volume command failed exit code {volumeResult.ExitCode}: {detail}";
                }
            }

            var filterResult = await RunUwfCommandAsync(
                uwfPath,
                enableFilter ? "filter enable" : "filter disable",
                allowSystemFallback: true,
                cancellationToken);
            if (filterResult.ExitCode != 0)
            {
                string detail = ExtractProcessFailureDetail(filterResult.StdErr, filterResult.StdOut);
                if (IsWindowsAccessDeniedExitCode(filterResult.ExitCode))
                {
                    string message = await BuildUwfAccessDeniedMessageAsync("filter", filterResult.ExitCode, cancellationToken);
                    commandFailureMessage = string.IsNullOrWhiteSpace(commandFailureMessage)
                        ? message
                        : $"{commandFailureMessage}; {message}";
                }
                else
                {
                    string message = string.IsNullOrWhiteSpace(detail)
                        ? $"uwf filter command failed exit code {filterResult.ExitCode}"
                        : $"uwf filter command failed exit code {filterResult.ExitCode}: {detail}";
                    commandFailureMessage = string.IsNullOrWhiteSpace(commandFailureMessage)
                        ? message
                        : $"{commandFailureMessage}; {message}";
                }
            }

            if (!string.IsNullOrWhiteSpace(commandFailureMessage))
            {
                // Continue to verification; if state is already desired we still report compliant.
                // If next-session is already desired, treat as pending-reboot convergence.
                // Only fail with command message if state cannot converge.
                var verifyAfterCommandError = await ReadUwfStateAsync(uwfPath, volume, cancellationToken);
                bool desiredFilterEnabledAfterError = !ensureAbsent && enableFilter;
                bool desiredVolumeProtectedAfterError = !ensureAbsent && protectVolume;
                bool filterCurrentMatchAfterError = desiredFilterEnabledAfterError ? verifyAfterCommandError.FilterCurrent : !verifyAfterCommandError.FilterCurrent;
                bool filterNextMatchAfterError = desiredFilterEnabledAfterError ? verifyAfterCommandError.FilterNext : !verifyAfterCommandError.FilterNext;
                bool volumeCurrentMatchAfterError = desiredVolumeProtectedAfterError ? verifyAfterCommandError.VolumeCurrent : !verifyAfterCommandError.VolumeCurrent;
                bool volumeNextMatchAfterError = desiredVolumeProtectedAfterError ? verifyAfterCommandError.VolumeNext : !verifyAfterCommandError.VolumeNext;
                bool alreadyCompliantAfterError = filterCurrentMatchAfterError && volumeCurrentMatchAfterError;
                bool pendingRebootAfterError = !alreadyCompliantAfterError && filterNextMatchAfterError && volumeNextMatchAfterError;
                if (alreadyCompliantAfterError)
                {
                    TryClearUwfRebootGuardState(volume);
                    string summary = $"filter_current={verifyAfterCommandError.FilterCurrent}, filter_next={verifyAfterCommandError.FilterNext}, volume_current={verifyAfterCommandError.VolumeCurrent}, volume_next={verifyAfterCommandError.VolumeNext}";
                    return (true, $"uwf state compliant ({summary})");
                }

                if (pendingRebootAfterError)
                {
                    string summary = $"filter_current={verifyAfterCommandError.FilterCurrent}, filter_next={verifyAfterCommandError.FilterNext}, volume_current={verifyAfterCommandError.VolumeCurrent}, volume_next={verifyAfterCommandError.VolumeNext}";
                    if (!rebootNow || !rebootIfPending)
                    {
                        return (false, $"uwf pending reboot to finalize state ({summary})");
                    }

                    string stateSignatureAfterError = BuildUwfStateSignature(
                        verifyAfterCommandError.FilterCurrent,
                        verifyAfterCommandError.FilterNext,
                        verifyAfterCommandError.VolumeCurrent,
                        verifyAfterCommandError.VolumeNext);
                    var rebootOutcomeAfterError = await TryQueueUwfRebootAsync(
                        volume,
                        configSignature,
                        stateSignatureAfterError,
                        maxRebootAttempts,
                        rebootCooldownMinutes,
                        rebootCommand,
                        $"uwf pending reboot to finalize state ({summary})",
                        cancellationToken);
                    if (rebootOutcomeAfterError.Compliant)
                    {
                        return (true, rebootOutcomeAfterError.Message);
                    }
                    if (rebootOutcomeAfterError.Message.Contains("cooldown active", StringComparison.OrdinalIgnoreCase))
                    {
                        return (true, rebootOutcomeAfterError.Message);
                    }
                    return (false, rebootOutcomeAfterError.Message);
                }

                return (false, commandFailureMessage);
            }
        }

        var verifyState = await ReadUwfStateAsync(uwfPath, volume, cancellationToken);
        bool desiredFilterEnabled = !ensureAbsent && enableFilter;
        bool desiredVolumeProtected = !ensureAbsent && protectVolume;
        bool filterCurrentMatch = desiredFilterEnabled ? verifyState.FilterCurrent : !verifyState.FilterCurrent;
        bool filterNextMatch = desiredFilterEnabled ? verifyState.FilterNext : !verifyState.FilterNext;
        bool volumeCurrentMatch = desiredVolumeProtected ? verifyState.VolumeCurrent : !verifyState.VolumeCurrent;
        bool volumeNextMatch = desiredVolumeProtected ? verifyState.VolumeNext : !verifyState.VolumeNext;
        bool currentCompliant = filterCurrentMatch && volumeCurrentMatch;
        bool nextSessionCompliant = filterNextMatch && volumeNextMatch;
        bool pendingReboot = !currentCompliant && nextSessionCompliant;
        string stateSummary = $"filter_current={verifyState.FilterCurrent}, filter_next={verifyState.FilterNext}, volume_current={verifyState.VolumeCurrent}, volume_next={verifyState.VolumeNext}";
        string stateSignature = BuildUwfStateSignature(verifyState.FilterCurrent, verifyState.FilterNext, verifyState.VolumeCurrent, verifyState.VolumeNext);

        if (currentCompliant)
        {
            TryClearUwfRebootGuardState(volume);
            return (true, $"uwf state compliant ({stateSummary})");
        }

        if (pendingReboot)
        {
            if (!enforce || !rebootNow || !rebootIfPending)
            {
                return (false, $"uwf pending reboot to finalize state ({stateSummary})");
            }

            var rebootOutcome = await TryQueueUwfRebootAsync(
                volume,
                configSignature,
                stateSignature,
                maxRebootAttempts,
                rebootCooldownMinutes,
                rebootCommand,
                $"uwf pending reboot to finalize state ({stateSummary})",
                cancellationToken);
            if (rebootOutcome.Compliant)
            {
                return (true, rebootOutcome.Message);
            }

            if (rebootOutcome.Message.Contains("cooldown active", StringComparison.OrdinalIgnoreCase))
            {
                return (true, rebootOutcome.Message);
            }

            return (false, rebootOutcome.Message);
        }

        if (enforce && rebootNow && rebootIfPending)
        {
            var rebootOutcome = await TryQueueUwfRebootAsync(
                volume,
                configSignature,
                stateSignature,
                maxRebootAttempts,
                rebootCooldownMinutes,
                rebootCommand,
                $"uwf state drift ({stateSummary})",
                cancellationToken);
            if (rebootOutcome.Compliant)
            {
                return (true, rebootOutcome.Message);
            }
            if (rebootOutcome.Message.Contains("cooldown active", StringComparison.OrdinalIgnoreCase))
            {
                return (true, rebootOutcome.Message);
            }
            return (false, rebootOutcome.Message);
        }

        return (false, $"uwf state drift ({stateSummary})");
    }

    private sealed class UwfRebootGuardState
    {
        public string ConfigSignature { get; set; } = string.Empty;
        public string StateSignature { get; set; } = string.Empty;
        public int Attempts { get; set; }
        public string LastRequestedAtUtc { get; set; } = string.Empty;
    }

    private static async Task<(bool Compliant, string Message)> TryQueueUwfRebootAsync(
        string volume,
        string configSignature,
        string stateSignature,
        int maxRebootAttempts,
        int rebootCooldownMinutes,
        string rebootCommand,
        string reason,
        CancellationToken cancellationToken)
    {
        var guard = TryReadUwfRebootGuardState(volume) ?? new UwfRebootGuardState();
        DateTimeOffset now = DateTimeOffset.UtcNow;

        bool sameConfig = string.Equals(guard.ConfigSignature, configSignature, StringComparison.OrdinalIgnoreCase);
        bool sameState = string.Equals(guard.StateSignature, stateSignature, StringComparison.OrdinalIgnoreCase);
        if (!sameConfig || !sameState)
        {
            guard.Attempts = 0;
            guard.LastRequestedAtUtc = string.Empty;
        }
        else if (DateTimeOffset.TryParse(guard.LastRequestedAtUtc, out var previousRequestAt)
            && now - previousRequestAt > TimeSpan.FromHours(8))
        {
            guard.Attempts = 0;
            guard.LastRequestedAtUtc = string.Empty;
        }

        if (guard.Attempts >= maxRebootAttempts)
        {
            return (false, $"{reason}; reboot loop guard blocked after {guard.Attempts}/{maxRebootAttempts} attempts");
        }

        if (DateTimeOffset.TryParse(guard.LastRequestedAtUtc, out var lastRequestAt))
        {
            TimeSpan elapsed = now - lastRequestAt;
            TimeSpan cooldown = TimeSpan.FromMinutes(rebootCooldownMinutes);
            if (elapsed < cooldown)
            {
                int remaining = Math.Max(1, (int)Math.Ceiling((cooldown - elapsed).TotalMinutes));
                return (false, $"{reason}; reboot cooldown active ({remaining} minute(s) remaining)");
            }
        }

        string effectiveRebootCommand = rebootCommand.Trim();
        if (string.IsNullOrWhiteSpace(effectiveRebootCommand))
        {
            effectiveRebootCommand = "shutdown.exe /r /t 0";
        }

        var rebootResult = await ProcessRunner.RunAsync("cmd.exe", $"/c {effectiveRebootCommand}", cancellationToken);
        if (rebootResult.ExitCode != 0)
        {
            // Guard against malformed custom commands (for example "/c" with no message).
            // Fall back to a known-good reboot command so policy convergence is not blocked.
            const string fallbackRebootCommand = "shutdown.exe /r /t 0";
            var fallbackResult = await ProcessRunner.RunAsync("cmd.exe", $"/c {fallbackRebootCommand}", cancellationToken);
            if (fallbackResult.ExitCode != 0)
            {
                return (false, $"{reason}; reboot command failed (custom exit {rebootResult.ExitCode}, fallback exit {fallbackResult.ExitCode})");
            }
        }

        guard.ConfigSignature = configSignature;
        guard.StateSignature = stateSignature;
        guard.Attempts += 1;
        guard.LastRequestedAtUtc = now.ToString("O");
        TryWriteUwfRebootGuardState(volume, guard);

        return (true, $"{reason}; reboot queued (attempt {guard.Attempts}/{maxRebootAttempts})");
    }

    private static string BuildUwfConfigSignature(
        string ensure,
        bool enableFeature,
        bool enableFilter,
        bool protectVolume,
        string volume,
        string rebootCommand,
        bool rebootIfPending,
        int maxRebootAttempts,
        int rebootCooldownMinutes,
        string overlayType,
        int? overlayMaxSizeMb,
        int? overlayWarningThresholdMb,
        int? overlayCriticalThresholdMb)
    {
        string raw = string.Join("|", new[]
        {
            ensure,
            enableFeature ? "1" : "0",
            enableFilter ? "1" : "0",
            protectVolume ? "1" : "0",
            volume.Trim().ToUpperInvariant(),
            rebootCommand.Trim(),
            rebootIfPending ? "1" : "0",
            maxRebootAttempts.ToString(),
            rebootCooldownMinutes.ToString(),
            overlayType.Trim().ToLowerInvariant(),
            overlayMaxSizeMb?.ToString() ?? string.Empty,
            overlayWarningThresholdMb?.ToString() ?? string.Empty,
            overlayCriticalThresholdMb?.ToString() ?? string.Empty,
        });

        byte[] hash = SHA256.HashData(Encoding.UTF8.GetBytes(raw));
        return Convert.ToHexString(hash).ToLowerInvariant()[..24];
    }

    private static string BuildUwfStateSignature(bool filterCurrent, bool filterNext, bool volumeCurrent, bool volumeNext)
    {
        return $"fc:{(filterCurrent ? 1 : 0)}|fn:{(filterNext ? 1 : 0)}|vc:{(volumeCurrent ? 1 : 0)}|vn:{(volumeNext ? 1 : 0)}";
    }

    private static bool IsWindowsAdministrator(out string identityName)
    {
        identityName = "unknown";
        try
        {
            using var identity = WindowsIdentity.GetCurrent();
            identityName = identity?.Name ?? "unknown";
            var principal = new WindowsPrincipal(identity!);
            return principal.IsInRole(WindowsBuiltInRole.Administrator);
        }
        catch
        {
            return false;
        }
    }

    private static bool IsWindowsAccessDeniedExitCode(int exitCode)
    {
        const int AccessDeniedHResult = unchecked((int)0x80070005);
        return exitCode == 5 || exitCode == AccessDeniedHResult;
    }

    private static async Task<(int ExitCode, string StdOut, string StdErr)> RunUwfCommandAsync(
        string uwfPath,
        string arguments,
        bool allowSystemFallback,
        CancellationToken cancellationToken)
    {
        var direct = await ProcessRunner.RunAsync(uwfPath, arguments, cancellationToken);
        if (!allowSystemFallback || !RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            return direct;
        }

        if (!IsWindowsAccessDeniedExitCode(direct.ExitCode))
        {
            return direct;
        }

        if (IsLocalSystemIdentity())
        {
            return direct;
        }

        if (!IsWindowsAdministrator(out _))
        {
            return direct;
        }

        string command = $"\"{uwfPath}\" {arguments}";
        return await RunCommandAsSystemViaScheduledTaskAsync(command, 300, cancellationToken);
    }

    private static string[] ReadUwfStringListConfig(JsonElement config, string propertyName)
    {
        if (!config.TryGetProperty(propertyName, out var node) || node.ValueKind != JsonValueKind.Array)
        {
            return [];
        }

        var list = new List<string>();
        foreach (var item in node.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.String)
            {
                continue;
            }

            string value = (item.GetString() ?? string.Empty).Trim();
            if (value.Length == 0)
            {
                continue;
            }

            list.Add(value);
        }

        return list
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
    }

    private static int? TryReadUwfPositiveInt(JsonElement config, string propertyName, int min, int max)
    {
        if (!config.TryGetProperty(propertyName, out var node))
        {
            return null;
        }

        if (node.ValueKind == JsonValueKind.Number && node.TryGetInt32(out int asNumber))
        {
            return Math.Clamp(asNumber, min, max);
        }

        if (node.ValueKind == JsonValueKind.String && int.TryParse(node.GetString(), out int asString))
        {
            return Math.Clamp(asString, min, max);
        }

        return null;
    }

    private static async Task<string?> ApplyUwfOverlaySettingsAsync(
        string uwfPath,
        string overlayType,
        int? overlayMaxSizeMb,
        int? overlayWarningThresholdMb,
        int? overlayCriticalThresholdMb,
        CancellationToken cancellationToken)
    {
        var commands = new List<(string Args, string Label)>();

        if (!string.IsNullOrWhiteSpace(overlayType))
        {
            commands.Add(($"overlay set-type {overlayType}", "overlay type"));
        }
        if (overlayMaxSizeMb.HasValue)
        {
            commands.Add(($"overlay set-size {overlayMaxSizeMb.Value}", "overlay max size"));
        }
        if (overlayWarningThresholdMb.HasValue)
        {
            commands.Add(($"overlay set-warningthreshold {overlayWarningThresholdMb.Value}", "overlay warning threshold"));
        }
        if (overlayCriticalThresholdMb.HasValue)
        {
            commands.Add(($"overlay set-criticalthreshold {overlayCriticalThresholdMb.Value}", "overlay critical threshold"));
        }

        foreach (var (args, label) in commands)
        {
            var result = await RunUwfCommandAsync(uwfPath, args, allowSystemFallback: true, cancellationToken);
            if (result.ExitCode == 0)
            {
                continue;
            }

            if (IsWindowsAccessDeniedExitCode(result.ExitCode))
            {
                return await BuildUwfAccessDeniedMessageAsync(label, result.ExitCode, cancellationToken);
            }

            string detail = ExtractProcessFailureDetail(result.StdErr, result.StdOut);
            return string.IsNullOrWhiteSpace(detail)
                ? $"uwf {label} command failed exit code {result.ExitCode}"
                : $"uwf {label} command failed exit code {result.ExitCode}: {detail}";
        }

        return null;
    }

    private static async Task<string?> ApplyUwfExclusionsAsync(
        string uwfPath,
        string scope,
        IEnumerable<string> exclusions,
        CancellationToken cancellationToken)
    {
        foreach (string exclusion in exclusions)
        {
            string escaped = exclusion.Replace("\"", "\\\"", StringComparison.Ordinal);
            string args = $"{scope} add-exclusion \"{escaped}\"";
            var result = await RunUwfCommandAsync(uwfPath, args, allowSystemFallback: true, cancellationToken);
            if (result.ExitCode == 0)
            {
                continue;
            }

            string combined = $"{result.StdErr} {result.StdOut}";
            if (LooksLikeUwfAlreadyExistsMessage(combined))
            {
                continue;
            }

            if (IsWindowsAccessDeniedExitCode(result.ExitCode))
            {
                return await BuildUwfAccessDeniedMessageAsync($"{scope} exclusion", result.ExitCode, cancellationToken);
            }

            string detail = ExtractProcessFailureDetail(result.StdErr, result.StdOut);
            return string.IsNullOrWhiteSpace(detail)
                ? $"uwf {scope} exclusion command failed exit code {result.ExitCode} for {exclusion}"
                : $"uwf {scope} exclusion command failed exit code {result.ExitCode} for {exclusion}: {detail}";
        }

        return null;
    }

    private static bool LooksLikeUwfAlreadyExistsMessage(string text)
    {
        if (string.IsNullOrWhiteSpace(text))
        {
            return false;
        }

        string value = text.ToLowerInvariant();
        return value.Contains("already", StringComparison.Ordinal)
            || value.Contains("exists", StringComparison.Ordinal)
            || value.Contains("duplicate", StringComparison.Ordinal);
    }

    private static async Task<string> BuildUwfAccessDeniedMessageAsync(string commandScope, int exitCode, CancellationToken cancellationToken)
    {
        _ = IsWindowsAdministrator(out string identityName);
        bool isSystem = IsLocalSystemIdentity();
        string editionSummary = ReadWindowsEditionSummary();
        bool editionLikelySupportsUwf = IsLikelyUwfSupportedEdition(editionSummary);

        string featureState = "unknown";
        try
        {
            var featureInfo = await ProcessRunner.RunAsync(
                "dism.exe",
                "/online /Get-FeatureInfo /FeatureName:Client-UnifiedWriteFilter",
                cancellationToken);
            string combined = (featureInfo.StdOut + " " + featureInfo.StdErr).Replace("\r", " ").Replace("\n", " ");
            if (combined.IndexOf("State : Enabled", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                featureState = "enabled";
            }
            else if (combined.IndexOf("State : Disabled", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                featureState = "disabled";
            }
            else if (combined.IndexOf("not recognized", StringComparison.OrdinalIgnoreCase) >= 0
                || combined.IndexOf("unknown", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                featureState = "unsupported_or_unknown";
            }
        }
        catch
        {
            // Keep best-effort diagnostics only.
        }

        string editionHint = editionLikelySupportsUwf
            ? string.Empty
            : " this Windows edition may not support UWF (requires Enterprise/Education/IoT Enterprise).";
        string systemHint = isSystem
            ? " command ran as SYSTEM."
            : " command was not running as SYSTEM.";

        return $"uwf {commandScope} command access denied; current_identity={identityName}; edition={editionSummary}; uwf_feature_state={featureState};{systemHint}{editionHint} (exit {exitCode})";
    }

    private static string ReadWindowsEditionSummary()
    {
        if (!RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            return "non-windows";
        }

        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
            if (key is null)
            {
                return "unknown";
            }

            string editionId = key.GetValue("EditionID")?.ToString()?.Trim() ?? string.Empty;
            string productName = key.GetValue("ProductName")?.ToString()?.Trim() ?? string.Empty;
            if (!string.IsNullOrWhiteSpace(productName) && !string.IsNullOrWhiteSpace(editionId))
            {
                return $"{productName} ({editionId})";
            }

            if (!string.IsNullOrWhiteSpace(productName))
            {
                return productName;
            }

            if (!string.IsNullOrWhiteSpace(editionId))
            {
                return editionId;
            }
        }
        catch
        {
            // Best-effort diagnostics only.
        }

        return "unknown";
    }

    private static bool IsLikelyUwfSupportedEdition(string editionSummary)
    {
        if (string.IsNullOrWhiteSpace(editionSummary))
        {
            return false;
        }

        string value = editionSummary.ToLowerInvariant();
        return value.Contains("enterprise", StringComparison.Ordinal)
            || value.Contains("education", StringComparison.Ordinal)
            || value.Contains("iot", StringComparison.Ordinal);
    }

    private static UwfRebootGuardState? TryReadUwfRebootGuardState(string volume)
    {
        try
        {
            string path = GetUwfRebootGuardStatePath(volume);
            if (!File.Exists(path))
            {
                return null;
            }

            string json = File.ReadAllText(path);
            return JsonSerializer.Deserialize<UwfRebootGuardState>(json);
        }
        catch
        {
            return null;
        }
    }

    private static void TryWriteUwfRebootGuardState(string volume, UwfRebootGuardState state)
    {
        try
        {
            string path = GetUwfRebootGuardStatePath(volume);
            string? dir = Path.GetDirectoryName(path);
            if (!string.IsNullOrWhiteSpace(dir))
            {
                Directory.CreateDirectory(dir);
            }
            string json = JsonSerializer.Serialize(state);
            File.WriteAllText(path, json);
        }
        catch
        {
            // Ignore non-critical guard write failures.
        }
    }

    private static void TryClearUwfRebootGuardState(string volume)
    {
        try
        {
            string path = GetUwfRebootGuardStatePath(volume);
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // Ignore non-critical guard cleanup errors.
        }
    }

    private static string GetUwfRebootGuardStatePath(string volume)
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData")
            ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
            ?? @"C:\ProgramData";
        string safeToken = new string(volume
            .Trim()
            .ToLowerInvariant()
            .Select(ch => char.IsLetterOrDigit(ch) ? ch : '_')
            .ToArray());
        if (string.IsNullOrWhiteSpace(safeToken))
        {
            safeToken = "default";
        }
        return Path.Combine(programData, "DMS", "Uwf", $"reboot-guard-{safeToken}.json");
    }

    private static async Task<(bool FilterCurrent, bool FilterNext, bool VolumeCurrent, bool VolumeNext, int ConfigExitCode, int VolumeExitCode, string Error)> ReadUwfStateAsync(
        string uwfPath,
        string volume,
        CancellationToken cancellationToken)
    {
        var cfg = await RunUwfCommandAsync(uwfPath, "filter get-config", allowSystemFallback: true, cancellationToken);
        if (cfg.ExitCode != 0)
        {
            var legacyCfg = await RunUwfCommandAsync(uwfPath, "get-config", allowSystemFallback: true, cancellationToken);
            if (legacyCfg.ExitCode == 0 || !string.IsNullOrWhiteSpace(legacyCfg.StdOut))
            {
                cfg = legacyCfg;
            }
        }
        var filterCurrentProbe = await RunUwfCommandAsync(uwfPath, "filter get-current-session", allowSystemFallback: true, cancellationToken);
        var filterNextProbe = await RunUwfCommandAsync(uwfPath, "filter get-next-session", allowSystemFallback: true, cancellationToken);
        var vol = await RunUwfCommandAsync(uwfPath, $"volume get-config {volume}", allowSystemFallback: true, cancellationToken);
        string cfgText = (cfg.StdOut + Environment.NewLine + cfg.StdErr).Trim();
        string filterCurrentText = (filterCurrentProbe.StdOut + Environment.NewLine + filterCurrentProbe.StdErr).Trim();
        string filterNextText = (filterNextProbe.StdOut + Environment.NewLine + filterNextProbe.StdErr).Trim();
        string volText = (vol.StdOut + Environment.NewLine + vol.StdErr).Trim();

        bool? filterCurrentMaybe = ParseUwfNamedState(cfgText, "Filter state", "Filter", "Filter Enabled")
            ?? ParseUwfSessionStateNullable(cfgText, "current")
            ?? ParseUwfNamedState(filterCurrentText, "Current Session", "Current state", "Filter state")
            ?? ParseUwfAnyBooleanToken(filterCurrentText);
        bool? filterNextMaybe = ParseUwfNamedState(cfgText, "Next Session", "Next Filter state", "Filter state next")
            ?? ParseUwfSessionStateNullable(cfgText, "next")
            ?? ParseUwfNamedState(filterNextText, "Next Session", "Next state", "Filter state")
            ?? ParseUwfAnyBooleanToken(filterNextText);
        bool filterCurrent = filterCurrentMaybe ?? UwfOutputContainsAny(
            cfgText,
            "Filter state : ON",
            "Filter state: ON",
            "Filter state : Enabled",
            "Filter state: Enabled",
            "Current Session : ON",
            "Current Session: ON",
            "Current session: ON");
        bool filterNext = filterNextMaybe ?? UwfOutputContainsAny(
            cfgText,
            "Next Session : ON",
            "Next Session: ON",
            "Next session: ON",
            "Next Session : Enabled",
            "Next Session: Enabled",
            "Next session: Enabled");

        bool volumeCurrent = ParseUwfSessionState(volText, "current", defaultValue: UwfOutputContainsAny(
            volText,
            "Current Session : Protected",
            "Current Session: Protected",
            "Current session : Protected",
            "Current session: Protected",
            "Current Session : ON",
            "Current Session: ON"));
        bool volumeNext = ParseUwfSessionState(volText, "next", defaultValue: UwfOutputContainsAny(
            volText,
            "Next Session : Protected",
            "Next Session: Protected",
            "Next session: Protected",
            "Next Session : ON",
            "Next Session: ON"));

        int cfgExitCode = cfg.ExitCode == 0
            ? 0
            : (filterCurrentProbe.ExitCode == 0 || filterNextProbe.ExitCode == 0 ? 0 : cfg.ExitCode);

        string error = string.Join(" | ", new[]
        {
            cfgExitCode == 0 || string.IsNullOrWhiteSpace(cfg.StdErr) ? null : $"cfg: {cfg.StdErr.Trim()}",
            vol.ExitCode == 0 || string.IsNullOrWhiteSpace(vol.StdErr) ? null : $"vol: {vol.StdErr.Trim()}",
        }.Where(x => !string.IsNullOrWhiteSpace(x)));

        return (filterCurrent, filterNext, volumeCurrent, volumeNext, cfgExitCode, vol.ExitCode, error);
    }

    private static bool UwfOutputContainsAny(string source, params string[] patterns)
    {
        foreach (string pattern in patterns)
        {
            if (source.IndexOf(pattern, StringComparison.OrdinalIgnoreCase) >= 0)
            {
                return true;
            }
        }

        return false;
    }

    private static bool ParseUwfSessionState(string source, string sessionKind, bool defaultValue)
    {
        if (string.IsNullOrWhiteSpace(source))
        {
            return defaultValue;
        }

        string pattern = sessionKind.Equals("next", StringComparison.OrdinalIgnoreCase)
            ? @"next\s*session\s*[:=]\s*(on|off|enabled|disabled|protected|unprotected)"
            : @"current\s*session\s*[:=]\s*(on|off|enabled|disabled|protected|unprotected)";
        var match = Regex.Match(source, pattern, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        if (!match.Success)
        {
            return defaultValue;
        }

        string token = match.Groups[1].Value.Trim().ToLowerInvariant();
        return token is "on" or "enabled" or "protected";
    }

    private static bool? ParseUwfSessionStateNullable(string source, string sessionKind)
    {
        if (string.IsNullOrWhiteSpace(source))
        {
            return null;
        }

        string pattern = sessionKind.Equals("next", StringComparison.OrdinalIgnoreCase)
            ? @"next\s*session\s*[:=]\s*(on|off|enabled|disabled|protected|unprotected)"
            : @"current\s*session\s*[:=]\s*(on|off|enabled|disabled|protected|unprotected)";
        var match = Regex.Match(source, pattern, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        if (!match.Success)
        {
            return null;
        }

        string token = match.Groups[1].Value.Trim().ToLowerInvariant();
        return token is "on" or "enabled" or "protected";
    }

    private static bool? ParseUwfNamedState(string source, params string[] labels)
    {
        if (string.IsNullOrWhiteSpace(source))
        {
            return null;
        }

        foreach (string label in labels)
        {
            string pattern = Regex.Escape(label) + @"\s*[:=]\s*(on|off|enabled|disabled|true|false|protected|unprotected)";
            var match = Regex.Match(source, pattern, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
            if (!match.Success)
            {
                continue;
            }

            string token = match.Groups[1].Value.Trim().ToLowerInvariant();
            return token is "on" or "enabled" or "true" or "protected";
        }

        return null;
    }

    private static bool? ParseUwfAnyBooleanToken(string source)
    {
        if (string.IsNullOrWhiteSpace(source))
        {
            return null;
        }

        var direct = Regex.Match(source, @"\b(on|off|enabled|disabled|true|false|protected|unprotected)\b", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        if (!direct.Success)
        {
            return null;
        }

        string token = direct.Groups[1].Value.Trim().ToLowerInvariant();
        return token is "on" or "enabled" or "true" or "protected";
    }

    private static async Task<(bool Enabled, bool Disabled, int ExitCode, string Raw)> GetUwfFeatureStateAsync(CancellationToken cancellationToken)
    {
        var result = await ProcessRunner.RunAsync(
            "dism.exe",
            "/online /Get-FeatureInfo /FeatureName:Client-UnifiedWriteFilter",
            cancellationToken);
        string raw = (result.StdOut + Environment.NewLine + result.StdErr).Trim();
        bool enabled = raw.IndexOf("State : Enabled", StringComparison.OrdinalIgnoreCase) >= 0;
        bool disabled = raw.IndexOf("State : Disabled", StringComparison.OrdinalIgnoreCase) >= 0;
        return (enabled, disabled, result.ExitCode, raw);
    }

    private static async Task<(bool Compliant, string Message)> ApplyRebootRestoreModeAsync(JsonElement config, bool enforce, CancellationToken cancellationToken)
    {
        string ensure = config.TryGetProperty("ensure", out var ensureNode)
            ? (ensureNode.GetString() ?? "present").Trim().ToLowerInvariant()
            : "present";
        bool enabled = !config.TryGetProperty("enabled", out var enabledNode) || enabledNode.ValueKind != JsonValueKind.False;
        bool persistent = !config.TryGetProperty("persistent", out var persistentNode) || persistentNode.ValueKind != JsonValueKind.False;
        bool removePending = config.TryGetProperty("remove_pending", out var removePendingNode) && removePendingNode.ValueKind == JsonValueKind.True;

        bool disableMode = ensure == "absent" || !enabled || !persistent;
        string programData = Environment.GetEnvironmentVariable("ProgramData")
            ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
            ?? @"C:\ProgramData";
        string restoreRoot = Path.Combine(programData, "DMS", "Restore");
        string pendingPath = Path.Combine(restoreRoot, "pending-restore.json");
        string persistentPath = Path.Combine(restoreRoot, "persistent-restore.json");

        if (disableMode)
        {
            if (enforce)
            {
                TryDeleteFile(persistentPath);
                if (removePending)
                {
                    TryDeleteFile(pendingPath);
                }
            }

            bool persistentMissing = !File.Exists(persistentPath);
            bool pendingCleared = !removePending || !File.Exists(pendingPath);
            bool compliant = persistentMissing && pendingCleared;
            return (compliant, compliant
                ? "persistent reboot restore mode disabled"
                : "persistent reboot restore mode cleanup incomplete");
        }

        JsonObject manifest = BuildRestoreManifestFromConfig(config);
        if (!HasActionableRestoreEntries(manifest))
        {
            var generatedManifest = BuildProfileRestoreManifest(config, programData);
            if (HasActionableRestoreEntries(generatedManifest))
            {
                manifest = generatedManifest;
            }
        }
        if (!HasActionableRestoreEntries(manifest))
        {
            return (false, "reboot_restore_mode requires actions (steps/restore_steps/cleanup_paths) or a supported profile");
        }

        manifest["schema"] ??= "dms.restore-manifest.v1";
        manifest["created_at"] ??= DateTimeOffset.UtcNow.ToString("O");
        manifest["persistent_mode"] = true;
        manifest["managed_by"] = "dms.policy.reboot_restore_mode";

        if (enforce)
        {
            Directory.CreateDirectory(restoreRoot);
            string tempPath = Path.Combine(restoreRoot, $"persistent-restore-{Guid.NewGuid():N}.tmp");
            string manifestJson = manifest.ToJsonString();
            await File.WriteAllTextAsync(tempPath, manifestJson, cancellationToken);
            if (File.Exists(persistentPath))
            {
                File.Delete(persistentPath);
            }
            File.Move(tempPath, persistentPath);
        }

        bool exists = File.Exists(persistentPath);
        if (!enforce && !exists)
        {
            return (false, "persistent restore manifest missing");
        }

        bool rebootNow = config.TryGetProperty("reboot_now", out var rebootNowNode) && rebootNowNode.ValueKind == JsonValueKind.True;
        if (enforce && rebootNow)
        {
            string rebootCommand = "shutdown.exe /r /t 0";
            if (config.TryGetProperty("reboot_command", out var rebootCommandNode) && rebootCommandNode.ValueKind == JsonValueKind.String)
            {
                rebootCommand = (rebootCommandNode.GetString() ?? rebootCommand).Trim();
                if (string.IsNullOrWhiteSpace(rebootCommand))
                {
                    rebootCommand = "shutdown.exe /r /t 0";
                }
            }

            var rebootResult = await ProcessRunner.RunAsync("cmd.exe", $"/c {rebootCommand}", cancellationToken);
            if (rebootResult.ExitCode != 0)
            {
                return (false, $"persistent restore enabled, but reboot failed (exit {rebootResult.ExitCode})");
            }
        }

        return (true, "persistent reboot restore mode enabled");
    }

    private static JsonObject BuildRestoreManifestFromConfig(JsonElement config)
    {
        if (config.TryGetProperty("manifest", out var manifestNode) && manifestNode.ValueKind == JsonValueKind.Object)
        {
            return JsonNode.Parse(manifestNode.GetRawText())?.AsObject() ?? new JsonObject();
        }

        var manifest = new JsonObject();
        if (config.TryGetProperty("cleanup_paths", out var cleanupNode) && cleanupNode.ValueKind == JsonValueKind.Array)
        {
            manifest["cleanup_paths"] = JsonNode.Parse(cleanupNode.GetRawText());
        }
        if (config.TryGetProperty("steps", out var stepsNode) && stepsNode.ValueKind == JsonValueKind.Array)
        {
            manifest["steps"] = JsonNode.Parse(stepsNode.GetRawText());
        }
        if (config.TryGetProperty("restore_steps", out var restoreStepsNode) && restoreStepsNode.ValueKind == JsonValueKind.Array)
        {
            manifest["restore_steps"] = JsonNode.Parse(restoreStepsNode.GetRawText());
        }

        return manifest;
    }

    private static JsonObject BuildProfileRestoreManifest(JsonElement config, string programData)
    {
        string profile = ReadJsonString(config, "profile", string.Empty).Trim().ToLowerInvariant();
        if (string.IsNullOrWhiteSpace(profile))
        {
            return new JsonObject();
        }

        if (profile is not ("lab_fast" or "deepfreeze_fast" or "school_fast"))
        {
            return new JsonObject();
        }

        bool cleanDownloads = ReadJsonBool(config, "clean_downloads", true);
        bool cleanDesktop = ReadJsonBool(config, "clean_desktop", false);
        bool cleanDocuments = ReadJsonBool(config, "clean_documents", false);
        bool cleanUserTemp = ReadJsonBool(config, "clean_user_temp", true);
        bool cleanWindowsTemp = ReadJsonBool(config, "clean_windows_temp", true);
        bool cleanRecycleBin = ReadJsonBool(config, "clean_recycle_bin", false);
        bool cleanDmsStaging = ReadJsonBool(config, "clean_dms_staging", true);

        var cleanupPaths = new JsonArray();
        if (cleanDmsStaging)
        {
            cleanupPaths.Add(Path.Combine(programData, "DMS", "Packages", "staging"));
            cleanupPaths.Add(Path.Combine(programData, "DMS", "ConfigPush"));
        }

        string script = BuildFastLabProfileScript(
            cleanDownloads,
            cleanDesktop,
            cleanDocuments,
            cleanUserTemp,
            cleanWindowsTemp,
            cleanRecycleBin,
            programData);

        var steps = new JsonArray
        {
            new JsonObject
            {
                ["type"] = "shell",
                ["shell"] = "powershell",
                ["script"] = script,
            },
        };

        var manifest = new JsonObject
        {
            ["schema"] = "dms.restore-manifest.v1",
            ["generated_from_profile"] = profile,
            ["steps"] = steps,
        };
        if (cleanupPaths.Count > 0)
        {
            manifest["cleanup_paths"] = cleanupPaths;
        }

        return manifest;
    }

    private static string BuildFastLabProfileScript(
        bool cleanDownloads,
        bool cleanDesktop,
        bool cleanDocuments,
        bool cleanUserTemp,
        bool cleanWindowsTemp,
        bool cleanRecycleBin,
        string programData)
    {
        var lines = new List<string>
        {
            "$ErrorActionPreference='SilentlyContinue'",
            "$excluded=@('Public','Default','Default User','All Users','Administrator')",
            "$usersRoot='C:\\Users'",
            "if(Test-Path $usersRoot){",
            "  Get-ChildItem $usersRoot -Directory -ErrorAction SilentlyContinue | Where-Object { $excluded -notcontains $_.Name } | ForEach-Object {",
            "    $userRoot=$_.FullName",
        };

        if (cleanDownloads)
        {
            lines.Add("    $p=Join-Path $userRoot 'Downloads'; if(Test-Path $p){ Remove-Item (Join-Path $p '*') -Recurse -Force -ErrorAction SilentlyContinue }");
        }
        if (cleanDesktop)
        {
            lines.Add("    $p=Join-Path $userRoot 'Desktop'; if(Test-Path $p){ Remove-Item (Join-Path $p '*') -Recurse -Force -ErrorAction SilentlyContinue }");
        }
        if (cleanDocuments)
        {
            lines.Add("    $p=Join-Path $userRoot 'Documents'; if(Test-Path $p){ Remove-Item (Join-Path $p '*') -Recurse -Force -ErrorAction SilentlyContinue }");
        }
        if (cleanUserTemp)
        {
            lines.Add("    $p=Join-Path $userRoot 'AppData\\Local\\Temp'; if(Test-Path $p){ Remove-Item (Join-Path $p '*') -Recurse -Force -ErrorAction SilentlyContinue }");
        }

        lines.Add("  }");
        lines.Add("}");

        if (cleanWindowsTemp)
        {
            lines.Add("if(Test-Path 'C:\\Windows\\Temp'){ Remove-Item 'C:\\Windows\\Temp\\*' -Recurse -Force -ErrorAction SilentlyContinue }");
        }
        if (cleanRecycleBin)
        {
            lines.Add("if(Test-Path 'C:\\$Recycle.Bin'){ Remove-Item 'C:\\$Recycle.Bin\\*' -Recurse -Force -ErrorAction SilentlyContinue }");
        }

        string diagnosticsPath = Path.Combine(programData, "DMS", "Diagnostics");
        string markerPath = Path.Combine(programData, "DMS", "Diagnostics", "restore-last.txt");
        lines.Add($"New-Item -ItemType Directory -Force -Path '{diagnosticsPath.Replace("'", "''", StringComparison.Ordinal)}' | Out-Null");
        lines.Add($"Set-Content -Path '{markerPath.Replace("'", "''", StringComparison.Ordinal)}' -Value (Get-Date -Format o)");

        return string.Join(Environment.NewLine, lines);
    }

    private static string ReadJsonString(JsonElement config, string property, string fallback)
    {
        if (config.TryGetProperty(property, out var node) && node.ValueKind == JsonValueKind.String)
        {
            return node.GetString() ?? fallback;
        }

        return fallback;
    }

    private static bool ReadJsonBool(JsonElement config, string property, bool fallback)
    {
        if (!config.TryGetProperty(property, out var node))
        {
            return fallback;
        }

        return node.ValueKind switch
        {
            JsonValueKind.True => true,
            JsonValueKind.False => false,
            JsonValueKind.String when bool.TryParse(node.GetString(), out var parsed) => parsed,
            _ => fallback,
        };
    }

    private static bool HasActionableRestoreEntries(JsonObject manifest)
    {
        bool hasCleanupPaths = manifest["cleanup_paths"] is JsonArray cleanupPaths && cleanupPaths.Count > 0;
        bool hasSteps = manifest["steps"] is JsonArray steps && steps.Count > 0;
        bool hasRestoreSteps = manifest["restore_steps"] is JsonArray restoreSteps && restoreSteps.Count > 0;
        return hasCleanupPaths || hasSteps || hasRestoreSteps;
    }

    private static void TryDeleteFile(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // Best-effort cleanup.
        }
    }

    private static async Task<(bool Compliant, int DriftCount, string Message, Dictionary<string, object?> Report)> BuildBaselineProfileReportAsync(JsonElement config, CancellationToken cancellationToken)
    {
        var observedFiles = new List<Dictionary<string, object?>>();
        var observedRegistry = new List<Dictionary<string, object?>>();
        var observedServices = new List<Dictionary<string, object?>>();
        var observedPackages = new List<Dictionary<string, object?>>();
        var drifts = new List<Dictionary<string, object?>>();

        if (config.TryGetProperty("critical_files", out var filesNode) && filesNode.ValueKind == JsonValueKind.Array)
        {
            foreach (var fileNode in filesNode.EnumerateArray())
            {
                string path = fileNode.TryGetProperty("path", out var pathNode) ? (pathNode.GetString() ?? string.Empty).Trim() : string.Empty;
                if (string.IsNullOrWhiteSpace(path))
                {
                    continue;
                }

                bool expectedExists = !fileNode.TryGetProperty("exists", out var existsNode) || existsNode.ValueKind != JsonValueKind.False;
                string expectedSha256 = fileNode.TryGetProperty("sha256", out var shaNode) ? (shaNode.GetString() ?? string.Empty).Trim().ToLowerInvariant() : string.Empty;
                bool exists = File.Exists(path);
                string actualSha256 = string.Empty;
                if (exists)
                {
                    try
                    {
                        using var stream = File.OpenRead(path);
                        actualSha256 = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
                    }
                    catch
                    {
                        actualSha256 = string.Empty;
                    }
                }

                observedFiles.Add(new Dictionary<string, object?>
                {
                    ["path"] = path,
                    ["exists"] = exists,
                    ["sha256"] = actualSha256,
                });

                if (expectedExists && !exists)
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "file_exists",
                        ["path"] = path,
                        ["expected"] = true,
                        ["actual"] = false,
                    });
                    continue;
                }

                if (!expectedExists && exists)
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "file_exists",
                        ["path"] = path,
                        ["expected"] = false,
                        ["actual"] = true,
                    });
                    continue;
                }

                if (exists && !string.IsNullOrWhiteSpace(expectedSha256) && !string.Equals(expectedSha256, actualSha256, StringComparison.OrdinalIgnoreCase))
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "file_hash",
                        ["path"] = path,
                        ["expected"] = expectedSha256,
                        ["actual"] = actualSha256,
                    });
                }
            }
        }

        if (config.TryGetProperty("registry_values", out var regNode) && regNode.ValueKind == JsonValueKind.Array)
        {
            foreach (var regCheck in regNode.EnumerateArray())
            {
                string path = regCheck.TryGetProperty("path", out var pathNode) ? (pathNode.GetString() ?? string.Empty).Trim() : string.Empty;
                string name = regCheck.TryGetProperty("name", out var nameNode) ? (nameNode.GetString() ?? string.Empty).Trim() : string.Empty;
                if (string.IsNullOrWhiteSpace(path) || string.IsNullOrWhiteSpace(name))
                {
                    continue;
                }

                string ensure = regCheck.TryGetProperty("ensure", out var ensureNode) ? (ensureNode.GetString() ?? "present").Trim().ToLowerInvariant() : "present";
                string expectedValue = regCheck.TryGetProperty("value", out var expectedValueNode) ? expectedValueNode.ToString() : string.Empty;
                var (hive, subKey) = SplitRegistryPath(path);
                object? actualValue = null;
                bool exists = false;
                if (hive is not null && !string.IsNullOrWhiteSpace(subKey))
                {
                    using var key = hive.OpenSubKey(subKey, false);
                    actualValue = key?.GetValue(name);
                    exists = actualValue is not null;
                }

                observedRegistry.Add(new Dictionary<string, object?>
                {
                    ["path"] = path,
                    ["name"] = name,
                    ["exists"] = exists,
                    ["value"] = actualValue?.ToString(),
                });

                if (ensure == "absent")
                {
                    if (exists)
                    {
                        drifts.Add(new Dictionary<string, object?>
                        {
                            ["kind"] = "registry_absent",
                            ["path"] = path,
                            ["name"] = name,
                            ["expected"] = "absent",
                            ["actual"] = "present",
                        });
                    }
                    continue;
                }

                if (!exists)
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "registry_exists",
                        ["path"] = path,
                        ["name"] = name,
                        ["expected"] = "present",
                        ["actual"] = "missing",
                    });
                    continue;
                }

                if (regCheck.TryGetProperty("value", out _) && !string.Equals(actualValue?.ToString(), expectedValue, StringComparison.OrdinalIgnoreCase))
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "registry_value",
                        ["path"] = path,
                        ["name"] = name,
                        ["expected"] = expectedValue,
                        ["actual"] = actualValue?.ToString(),
                    });
                }
            }
        }

        if (config.TryGetProperty("services", out var servicesNode) && servicesNode.ValueKind == JsonValueKind.Array)
        {
            foreach (var serviceCheck in servicesNode.EnumerateArray())
            {
                string name = serviceCheck.TryGetProperty("name", out var nameNode) ? (nameNode.GetString() ?? string.Empty).Trim() : string.Empty;
                if (string.IsNullOrWhiteSpace(name))
                {
                    continue;
                }

                string ensure = serviceCheck.TryGetProperty("ensure", out var ensureNode) ? (ensureNode.GetString() ?? "present").Trim().ToLowerInvariant() : "present";
                string expectedStatus = serviceCheck.TryGetProperty("status", out var statusNode) ? (statusNode.GetString() ?? string.Empty).Trim().ToLowerInvariant() : string.Empty;
                string expectedStart = serviceCheck.TryGetProperty("start_mode", out var startNode) ? (startNode.GetString() ?? string.Empty).Trim().ToLowerInvariant() : string.Empty;

                var actual = await ReadServiceStatusAsync(name, cancellationToken);
                observedServices.Add(new Dictionary<string, object?>
                {
                    ["name"] = name,
                    ["exists"] = actual.Exists,
                    ["status"] = actual.Status,
                    ["start_mode"] = actual.StartMode,
                });

                if (ensure == "absent")
                {
                    if (actual.Exists)
                    {
                        drifts.Add(new Dictionary<string, object?>
                        {
                            ["kind"] = "service_absent",
                            ["name"] = name,
                            ["expected"] = "absent",
                            ["actual"] = "present",
                        });
                    }
                    continue;
                }

                if (!actual.Exists)
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "service_exists",
                        ["name"] = name,
                        ["expected"] = "present",
                        ["actual"] = "missing",
                    });
                    continue;
                }

                if (!string.IsNullOrWhiteSpace(expectedStatus) && !string.Equals(expectedStatus, actual.Status, StringComparison.OrdinalIgnoreCase))
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "service_status",
                        ["name"] = name,
                        ["expected"] = expectedStatus,
                        ["actual"] = actual.Status,
                    });
                }
                if (!string.IsNullOrWhiteSpace(expectedStart) && !string.Equals(NormalizeServiceStartMode(expectedStart), NormalizeServiceStartMode(actual.StartMode), StringComparison.OrdinalIgnoreCase))
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "service_start_mode",
                        ["name"] = name,
                        ["expected"] = expectedStart,
                        ["actual"] = actual.StartMode,
                    });
                }
            }
        }

        if (config.TryGetProperty("installed_packages", out var pkgNode) && pkgNode.ValueKind == JsonValueKind.Array)
        {
            var installed = GetInstalledPackageNames();
            foreach (var pkgCheck in pkgNode.EnumerateArray())
            {
                string expectedName = pkgCheck.TryGetProperty("name", out var nameNode) ? (nameNode.GetString() ?? string.Empty).Trim() : string.Empty;
                if (string.IsNullOrWhiteSpace(expectedName))
                {
                    continue;
                }

                string ensure = pkgCheck.TryGetProperty("ensure", out var ensureNode) ? (ensureNode.GetString() ?? "present").Trim().ToLowerInvariant() : "present";
                string matchMode = pkgCheck.TryGetProperty("match", out var matchNode) ? (matchNode.GetString() ?? "contains").Trim().ToLowerInvariant() : "contains";
                var matched = installed.Where(x => matchMode == "exact"
                        ? string.Equals(x, expectedName, StringComparison.OrdinalIgnoreCase)
                        : x.Contains(expectedName, StringComparison.OrdinalIgnoreCase))
                    .Take(3)
                    .ToList();
                bool present = matched.Count > 0;

                observedPackages.Add(new Dictionary<string, object?>
                {
                    ["name"] = expectedName,
                    ["match"] = matchMode,
                    ["present"] = present,
                    ["matched"] = matched,
                });

                if (ensure == "absent" && present)
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "package_absent",
                        ["name"] = expectedName,
                        ["expected"] = "absent",
                        ["actual"] = "present",
                    });
                }
                else if (ensure != "absent" && !present)
                {
                    drifts.Add(new Dictionary<string, object?>
                    {
                        ["kind"] = "package_present",
                        ["name"] = expectedName,
                        ["expected"] = "present",
                        ["actual"] = "missing",
                    });
                }
            }
        }

        var report = new Dictionary<string, object?>
        {
            ["collected_at"] = DateTimeOffset.UtcNow.ToString("O"),
            ["observed"] = new Dictionary<string, object?>
            {
                ["critical_files"] = observedFiles,
                ["registry_values"] = observedRegistry,
                ["services"] = observedServices,
                ["installed_packages"] = observedPackages,
            },
            ["drifts"] = drifts,
        };

        bool compliant = drifts.Count == 0;
        return (compliant, drifts.Count, compliant ? "baseline profile compliant" : $"baseline drift detected ({drifts.Count})", report);
    }

    private static async Task<(bool Exists, string Status, string StartMode)> ReadServiceStatusAsync(string serviceName, CancellationToken cancellationToken)
    {
        string escaped = serviceName.Replace("\"", string.Empty, StringComparison.Ordinal);
        var query = await ProcessRunner.RunAsync("sc.exe", $"query \"{escaped}\"", cancellationToken);
        if (query.ExitCode != 0)
        {
            return (false, "missing", "unknown");
        }

        string status = "unknown";
        foreach (var rawLine in query.StdOut.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
        {
            var line = rawLine.Trim();
            if (!line.StartsWith("STATE", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            int colonIdx = line.IndexOf(':');
            string tail = colonIdx >= 0 ? line[(colonIdx + 1)..].Trim() : line;
            var parts = tail.Split(' ', StringSplitOptions.RemoveEmptyEntries);
            if (parts.Length >= 2)
            {
                status = parts[1].Trim().ToLowerInvariant();
            }
            break;
        }

        var qc = await ProcessRunner.RunAsync("sc.exe", $"qc \"{escaped}\"", cancellationToken);
        string startMode = "unknown";
        if (qc.ExitCode == 0)
        {
            foreach (var rawLine in qc.StdOut.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
            {
                var line = rawLine.Trim();
                if (!line.StartsWith("START_TYPE", StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }
                if (line.Contains("AUTO_START", StringComparison.OrdinalIgnoreCase))
                {
                    startMode = "auto";
                }
                else if (line.Contains("DEMAND_START", StringComparison.OrdinalIgnoreCase))
                {
                    startMode = "manual";
                }
                else if (line.Contains("DISABLED", StringComparison.OrdinalIgnoreCase))
                {
                    startMode = "disabled";
                }
                break;
            }
        }

        return (true, status, startMode);
    }

    private static string NormalizeServiceStartMode(string input)
    {
        string value = (input ?? string.Empty).Trim().ToLowerInvariant();
        return value switch
        {
            "automatic" => "auto",
            "auto_start" => "auto",
            "delayed-auto" => "auto",
            _ => value,
        };
    }

    private static List<string> GetInstalledPackageNames()
    {
        string[] roots =
        [
            @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
            @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
        ];

        var installed = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var root in roots)
        {
            using var key = Registry.LocalMachine.OpenSubKey(root);
            if (key is null)
            {
                continue;
            }

            foreach (var subName in key.GetSubKeyNames())
            {
                using var sub = key.OpenSubKey(subName);
                string display = (sub?.GetValue("DisplayName")?.ToString() ?? string.Empty).Trim();
                if (!string.IsNullOrWhiteSpace(display))
                {
                    installed.Add(display);
                }
            }
        }

        return installed.OrderBy(x => x, StringComparer.OrdinalIgnoreCase).ToList();
    }

    private static RegistryValueKind ParseRegistryValueKind(string type)
    {
        return type.ToUpperInvariant() switch
        {
            "DWORD" => RegistryValueKind.DWord,
            "QWORD" => RegistryValueKind.QWord,
            "EXPANDSTRING" => RegistryValueKind.ExpandString,
            "MULTISTRING" => RegistryValueKind.MultiString,
            "BINARY" => RegistryValueKind.Binary,
            _ => RegistryValueKind.String,
        };
    }

    private static (RegistryKey? Hive, string SubKey) SplitRegistryPath(string path)
    {
        string normalized = path.Replace("/", "\\").Trim();
        if (normalized.StartsWith(@"HKLM\", StringComparison.OrdinalIgnoreCase) || normalized.StartsWith(@"HKEY_LOCAL_MACHINE\", StringComparison.OrdinalIgnoreCase))
        {
            return (Registry.LocalMachine, normalized.Split('\\', 2)[1]);
        }
        if (normalized.StartsWith(@"HKCU\", StringComparison.OrdinalIgnoreCase) || normalized.StartsWith(@"HKEY_CURRENT_USER\", StringComparison.OrdinalIgnoreCase))
        {
            return (Registry.CurrentUser, normalized.Split('\\', 2)[1]);
        }

        return (null, string.Empty);
    }

    private static object ExtractRegistryValue(JsonElement config)
    {
        if (!config.TryGetProperty("value", out var valueNode))
        {
            return string.Empty;
        }

        return valueNode.ValueKind switch
        {
            JsonValueKind.Number when valueNode.TryGetInt32(out var intValue) => intValue,
            JsonValueKind.Number when valueNode.TryGetInt64(out var longValue) => longValue,
            JsonValueKind.True => 1,
            JsonValueKind.False => 0,
            _ => valueNode.ToString(),
        };
    }

    private static string ResolveLocalGroupStatePath(JsonElement config, string group)
    {
        string stateSeed = config.TryGetProperty("state_key", out var stateNode)
            ? (stateNode.GetString() ?? string.Empty)
            : string.Empty;
        if (string.IsNullOrWhiteSpace(stateSeed))
        {
            stateSeed = group;
        }

        string normalized = stateSeed.Trim().ToUpperInvariant();
        string stateId = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(normalized))).ToLowerInvariant();
        string stateDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "DMS", "State", "LocalGroup");
        return Path.Combine(stateDir, stateId + ".json");
    }

    private static HashSet<string> ReadLocalGroupSnapshotMembers(string path)
    {
        try
        {
            if (!File.Exists(path))
            {
                return new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            }

            using var document = JsonDocument.Parse(File.ReadAllText(path));
            if (!document.RootElement.TryGetProperty("members", out var membersNode) || membersNode.ValueKind != JsonValueKind.Array)
            {
                return new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            }

            var members = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            foreach (var item in membersNode.EnumerateArray())
            {
                var value = item.GetString();
                if (!string.IsNullOrWhiteSpace(value))
                {
                    members.Add(value.Trim());
                }
            }
            return members;
        }
        catch
        {
            return new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        }
    }

    private static void TryWriteLocalGroupSnapshot(string path, string group, IEnumerable<string> members)
    {
        try
        {
            string? dir = Path.GetDirectoryName(path);
            if (!string.IsNullOrWhiteSpace(dir))
            {
                Directory.CreateDirectory(dir);
            }

            var payload = new
            {
                group,
                captured_at_utc = DateTimeOffset.UtcNow.ToString("o"),
                members = members
                    .Where(member => !string.IsNullOrWhiteSpace(member))
                    .Select(member => member.Trim())
                    .Distinct(StringComparer.OrdinalIgnoreCase)
                    .OrderBy(member => member, StringComparer.OrdinalIgnoreCase)
                    .ToArray(),
            };

            File.WriteAllText(path, JsonSerializer.Serialize(payload));
        }
        catch
        {
            // Ignore non-critical state snapshot failures.
        }
    }

    private static void TryDeleteLocalGroupSnapshot(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // Ignore cleanup failures for state file.
        }
    }

    private static List<string> ParseNetLocalGroupMembers(string stdout)
    {
        var lines = stdout.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries)
            .Select(x => x.Trim())
            .ToList();

        bool reading = false;
        var members = new List<string>();
        foreach (var line in lines)
        {
            if (line.StartsWith("---", StringComparison.Ordinal))
            {
                reading = true;
                continue;
            }
            if (!reading)
            {
                continue;
            }
            if (line.StartsWith("The command completed successfully", StringComparison.OrdinalIgnoreCase))
            {
                break;
            }
            members.Add(line);
        }

        return members;
    }

    public static bool EvaluateDetection(Dictionary<string, object?> payload)
    {
        if (!HasDetection(payload))
        {
            return true;
        }

        var detectionObj = payload["detection"];
        JsonElement detection = detectionObj is JsonElement je ? je : JsonSerializer.Deserialize<JsonElement>(JsonSerializer.Serialize(detectionObj));
        if (!detection.TryGetProperty("type", out var typeNode))
        {
            return true;
        }

        string type = typeNode.GetString() ?? string.Empty;
        return type.ToLowerInvariant() switch
        {
            "file" => CheckFileDetection(detection),
            "registry" => CheckRegistryDetection(detection),
            "product_code" => CheckProductCodeDetection(detection),
            "version" => CheckVersionDetection(detection),
            _ => true,
        };
    }

    public static bool HasDetection(Dictionary<string, object?> payload)
    {
        if (!payload.TryGetValue("detection", out var detectionObj) || detectionObj is null)
        {
            return false;
        }

        JsonElement detection = detectionObj is JsonElement je ? je : JsonSerializer.Deserialize<JsonElement>(JsonSerializer.Serialize(detectionObj));
        if (!detection.TryGetProperty("type", out var typeNode))
        {
            return false;
        }

        string type = typeNode.GetString() ?? string.Empty;
        return !string.IsNullOrWhiteSpace(type);
    }

    private static bool CheckFileDetection(JsonElement detection)
    {
        string path = detection.TryGetProperty("path", out var pathNode) ? pathNode.GetString() ?? string.Empty : string.Empty;
        return path != string.Empty && File.Exists(path);
    }

    private static bool CheckRegistryDetection(JsonElement detection)
    {
        string path = detection.TryGetProperty("path", out var pathNode) ? pathNode.GetString() ?? string.Empty : string.Empty;
        string name = detection.TryGetProperty("name", out var nameNode) ? nameNode.GetString() ?? string.Empty : string.Empty;
        string expected = detection.TryGetProperty("expected", out var expectedNode) ? expectedNode.ToString() : string.Empty;

        var (hive, subKey) = SplitRegistryPath(path);
        if (hive is null || subKey == string.Empty || name == string.Empty)
        {
            return false;
        }

        using var key = hive.OpenSubKey(subKey, false);
        object? value = key?.GetValue(name);
        return value != null && string.Equals(value.ToString(), expected, StringComparison.OrdinalIgnoreCase);
    }

    private static bool CheckProductCodeDetection(JsonElement detection)
    {
        string productCode = detection.TryGetProperty("product_code", out var node) ? node.GetString() ?? string.Empty : string.Empty;
        if (productCode == string.Empty)
        {
            return false;
        }

        string[] roots =
        [
            @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
            @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
        ];

        foreach (var root in roots)
        {
            using var key = Registry.LocalMachine.OpenSubKey(root);
            if (key is null)
            {
                continue;
            }

            foreach (var subName in key.GetSubKeyNames())
            {
                if (string.Equals(subName, productCode, StringComparison.OrdinalIgnoreCase))
                {
                    return true;
                }
            }
        }

        return false;
    }

    private static bool CheckVersionDetection(JsonElement detection)
    {
        string path = detection.TryGetProperty("path", out var pathNode) ? pathNode.GetString() ?? string.Empty : string.Empty;
        string minVersion = detection.TryGetProperty("min_version", out var versionNode) ? versionNode.GetString() ?? string.Empty : string.Empty;
        if (path == string.Empty || minVersion == string.Empty || !File.Exists(path))
        {
            return false;
        }

        var fileVersion = FileVersionInfo.GetVersionInfo(path).FileVersion;
        if (!Version.TryParse(fileVersion, out var current) || !Version.TryParse(minVersion, out var min))
        {
            return false;
        }

        return current >= min;
    }
}

internal static class JobHandlerSupport
{
    public static async Task<(bool Attempted, int ExitCode, string StdOut, string StdErr)> TryRunRollbackAsync(Dictionary<string, object?> payload, CancellationToken cancellationToken)
    {
        string rollbackCommand = payload.TryGetValue("rollback_command", out var rollbackObj) ? rollbackObj?.ToString() ?? string.Empty : string.Empty;
        if (string.IsNullOrWhiteSpace(rollbackCommand))
        {
            return (false, 0, string.Empty, string.Empty);
        }

        var rollback = await ProcessRunner.RunShellCommandAsync(rollbackCommand, cancellationToken);
        return (true, rollback.ExitCode, rollback.StdOut, rollback.StdErr);
    }

    public static bool TryRemoveDownloadedArtifact(string path)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path) || !File.Exists(path))
            {
                return false;
            }

            string? dir = Path.GetDirectoryName(path);
            File.Delete(path);
            if (!string.IsNullOrWhiteSpace(dir) && Directory.Exists(dir))
            {
                try
                {
                    if (!Directory.EnumerateFileSystemEntries(dir).Any())
                    {
                        Directory.Delete(dir, false);
                    }
                }
                catch
                {
                    // Ignore non-critical directory cleanup issues.
                }
            }

            return true;
        }
        catch
        {
            return false;
        }
    }

    public static async Task<Dictionary<string, object?>> CollectDeepSoftwareInventoryAsync(CancellationToken cancellationToken)
    {
        var result = new Dictionary<string, object?>
        {
            ["collected_at"] = DateTimeOffset.UtcNow.ToString("O"),
            ["platform"] = RuntimeInformation.OSDescription,
            ["sources"] = new Dictionary<string, object?>(),
        };
        var sources = (Dictionary<string, object?>) result["sources"]!;

        if (RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            sources["registry_uninstall"] = CollectWindowsRegistrySoftware();
            if (await ProcessRunner.CommandExistsAsync("winget", cancellationToken))
            {
                var winget = await ProcessRunner.RunAsync("winget", "list --accept-source-agreements --disable-interactivity", cancellationToken);
                sources["winget_list"] = ParseLineInventory(winget.StdOut);
            }
        }
        else if (RuntimeInformation.IsOSPlatform(OSPlatform.OSX))
        {
            if (await ProcessRunner.CommandExistsAsync("brew", cancellationToken))
            {
                var brew = await ProcessRunner.RunShellCommandAsync("brew list --versions", cancellationToken);
                sources["brew_list"] = ParseLineInventory(brew.StdOut);
            }
        }
        else
        {
            if (await ProcessRunner.CommandExistsAsync("dpkg-query", cancellationToken))
            {
                var dpkg = await ProcessRunner.RunShellCommandAsync("dpkg-query -W -f='${binary:Package} ${Version}\\n'", cancellationToken);
                sources["dpkg_list"] = ParseLineInventory(dpkg.StdOut);
            }
            if (await ProcessRunner.CommandExistsAsync("rpm", cancellationToken))
            {
                var rpm = await ProcessRunner.RunShellCommandAsync("rpm -qa", cancellationToken);
                sources["rpm_list"] = ParseLineInventory(rpm.StdOut);
            }
            if (await ProcessRunner.CommandExistsAsync("snap", cancellationToken))
            {
                var snap = await ProcessRunner.RunShellCommandAsync("snap list", cancellationToken);
                sources["snap_list"] = ParseLineInventory(snap.StdOut);
            }
        }

        int total = 0;
        foreach (var item in sources.Values)
        {
            if (item is List<Dictionary<string, object?>> rows)
            {
                total += rows.Count;
            }
        }
        result["total_packages"] = total;
        return result;
    }

    private static List<Dictionary<string, object?>> ParseLineInventory(string stdout)
    {
        return stdout.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries)
            .Select(x => x.Trim())
            .Where(x => x.Length > 0 && !x.StartsWith("Name", StringComparison.OrdinalIgnoreCase) && !x.StartsWith("---", StringComparison.Ordinal))
            .Take(1500)
            .Select(line => new Dictionary<string, object?> { ["entry"] = line })
            .ToList();
    }

    private static List<Dictionary<string, object?>> CollectWindowsRegistrySoftware()
    {
        var result = new List<Dictionary<string, object?>>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        string[] roots =
        {
            @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
            @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
        };

        foreach (var root in roots)
        {
            using var key = Registry.LocalMachine.OpenSubKey(root);
            if (key is null)
            {
                continue;
            }
            foreach (var subName in key.GetSubKeyNames())
            {
                using var sub = key.OpenSubKey(subName);
                string name = sub?.GetValue("DisplayName")?.ToString() ?? string.Empty;
                if (string.IsNullOrWhiteSpace(name) || !seen.Add(name))
                {
                    continue;
                }
                result.Add(new Dictionary<string, object?>
                {
                    ["name"] = name,
                    ["version"] = sub?.GetValue("DisplayVersion")?.ToString(),
                    ["publisher"] = sub?.GetValue("Publisher")?.ToString(),
                    ["uninstall"] = sub?.GetValue("UninstallString")?.ToString(),
                });
                if (result.Count >= 2000)
                {
                    return result;
                }
            }
        }
        return result;
    }
}

public sealed class SoftwareInventoryReconcileHandler : IJobHandler
{
    public string JobType => "reconcile_software_inventory";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        var inventory = await JobHandlerSupport.CollectDeepSoftwareInventoryAsync(cancellationToken);
        return ("success", 0, new
        {
            inventory,
            source = "agent_reconcile",
        });
    }
}

public sealed class ScriptHandler : IJobHandler
{
    public string JobType => "run_command";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        // Enabled by default. Set DMS_RUN_COMMAND_ENABLED=false to hard-disable.
        if (string.Equals(Environment.GetEnvironmentVariable("DMS_RUN_COMMAND_ENABLED"), "false", StringComparison.OrdinalIgnoreCase))
        {
            return ("failed", 1, new { error = "Script execution disabled by configuration" });
        }

        string script = envelope.Payload.TryGetValue("script", out var scriptObj) ? scriptObj?.ToString() ?? string.Empty : string.Empty;
        if (string.IsNullOrWhiteSpace(script))
        {
            return ("failed", 1, new { error = "script missing" });
        }

        string scriptHash = envelope.Payload.TryGetValue("script_sha256", out var hashObj) ? hashObj?.ToString() ?? string.Empty : string.Empty;
        string computed = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(script))).ToLowerInvariant();
        if (!string.IsNullOrWhiteSpace(scriptHash) && !string.Equals(computed, scriptHash, StringComparison.OrdinalIgnoreCase))
        {
            return ("failed", 1, new { error = "script hash mismatch", expected_script_sha256 = computed });
        }
        scriptHash = computed;

        bool enforceAllowlist = string.Equals(Environment.GetEnvironmentVariable("DMS_RUN_COMMAND_ENFORCE_ALLOWLIST"), "true", StringComparison.OrdinalIgnoreCase);
        if (enforceAllowlist)
        {
            string allowedRaw = Environment.GetEnvironmentVariable("DMS_ALLOWED_SCRIPT_SHA256") ?? string.Empty;
            var allowed = allowedRaw.Split([',', ';', '\r', '\n'], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
                .Select(x => x.ToLowerInvariant())
                .ToHashSet(StringComparer.OrdinalIgnoreCase);

            if (!allowed.Contains(scriptHash.ToLowerInvariant()))
            {
                return ("failed", 1, new { error = "script hash not allowlisted", script_sha256 = scriptHash });
            }
        }

        bool approvalRequired = string.Equals(Environment.GetEnvironmentVariable("DMS_RUN_COMMAND_APPROVAL_REQUIRED"), "true", StringComparison.OrdinalIgnoreCase);
        if (approvalRequired)
        {
            bool approved = await IsScriptApprovedAsync(scriptHash, script, cancellationToken);
            if (!approved)
            {
                return ("failed", 1, new { error = "script awaiting runtime approval", script_sha256 = scriptHash });
            }
        }

        var result = await ProcessRunner.RunShellCommandAsync(script, cancellationToken);
        string status = result.ExitCode == 0 ? "success" : "failed";
        return (status, result.ExitCode, new { result.StdOut, result.StdErr, script_sha256 = scriptHash });
    }

    private static async Task<bool> IsScriptApprovedAsync(string scriptHash, string script, CancellationToken cancellationToken)
    {
        bool interactive = string.Equals(Environment.GetEnvironmentVariable("DMS_RUN_COMMAND_INTERACTIVE_APPROVAL"), "true", StringComparison.OrdinalIgnoreCase);
        if (interactive && Environment.UserInteractive)
        {
            Console.WriteLine("DMS Script Approval Required");
            Console.WriteLine($"SHA256: {scriptHash}");
            Console.WriteLine("Approve execution? [y/N]");
            string? input = await Task.Run(Console.ReadLine, cancellationToken);
            if (string.Equals(input?.Trim(), "y", StringComparison.OrdinalIgnoreCase) || string.Equals(input?.Trim(), "yes", StringComparison.OrdinalIgnoreCase))
            {
                return true;
            }
        }

        string baseDir = Environment.GetEnvironmentVariable("ProgramData")
            ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
            ?? @"C:\ProgramData";
        string dmsDir = Path.Combine(baseDir, "DMS");
        Directory.CreateDirectory(dmsDir);

        string approvedFile = Path.Combine(dmsDir, "script-approvals.txt");
        if (File.Exists(approvedFile))
        {
            var approved = (await File.ReadAllLinesAsync(approvedFile, cancellationToken))
                .Select(x => x.Trim().ToLowerInvariant())
                .Where(x => x.Length == 64)
                .ToHashSet(StringComparer.OrdinalIgnoreCase);
            if (approved.Contains(scriptHash.ToLowerInvariant()))
            {
                return true;
            }
        }

        string pendingFile = Path.Combine(dmsDir, "script-approval-pending.log");
        string pendingLine = $"{DateTimeOffset.UtcNow:O}\t{scriptHash}\t{script.Replace('\r', ' ').Replace('\n', ' ')}";
        await File.AppendAllTextAsync(pendingFile, pendingLine + Environment.NewLine, cancellationToken);
        return false;
    }
}

internal static class SnapshotJobSupport
{
    public static JsonElement ToPayloadElement(Dictionary<string, object?> payload)
        => JsonSerializer.Deserialize<JsonElement>(JsonSerializer.Serialize(payload));

    public static string ReadString(JsonElement payload, string key, string fallback = "")
    {
        if (payload.ValueKind == JsonValueKind.Object
            && payload.TryGetProperty(key, out var node)
            && node.ValueKind == JsonValueKind.String)
        {
            return node.GetString() ?? fallback;
        }

        return fallback;
    }

    public static bool ReadBool(JsonElement payload, string key, bool fallback = false)
    {
        if (payload.ValueKind == JsonValueKind.Object && payload.TryGetProperty(key, out var node))
        {
            if (node.ValueKind is JsonValueKind.True or JsonValueKind.False)
            {
                return node.GetBoolean();
            }

            if (node.ValueKind == JsonValueKind.String && bool.TryParse(node.GetString(), out bool parsed))
            {
                return parsed;
            }
        }

        return fallback;
    }

    public static int? ReadInt(JsonElement payload, params string[] keys)
    {
        if (payload.ValueKind != JsonValueKind.Object)
        {
            return null;
        }

        foreach (string key in keys)
        {
            if (!payload.TryGetProperty(key, out var node))
            {
                continue;
            }

            if (node.ValueKind == JsonValueKind.Number && node.TryGetInt32(out int value))
            {
                return value;
            }

            if (node.ValueKind == JsonValueKind.String && int.TryParse(node.GetString(), out value))
            {
                return value;
            }
        }

        return null;
    }

    public static List<string> ReadStringList(JsonElement payload, string key)
    {
        var result = new List<string>();
        if (payload.ValueKind != JsonValueKind.Object || !payload.TryGetProperty(key, out var node))
        {
            return result;
        }

        if (node.ValueKind == JsonValueKind.Array)
        {
            foreach (var item in node.EnumerateArray())
            {
                if (item.ValueKind == JsonValueKind.String)
                {
                    string value = (item.GetString() ?? string.Empty).Trim();
                    if (value.Length > 0)
                    {
                        result.Add(value);
                    }
                }
            }

            return result;
        }

        if (node.ValueKind == JsonValueKind.String)
        {
            string value = (node.GetString() ?? string.Empty).Trim();
            if (value.Length > 0)
            {
                result.Add(value);
            }
        }

        return result;
    }

    public static string EscapePowerShellSingleQuotedString(string input)
        => (input ?? string.Empty).Replace("'", "''");

    public static async Task<(int ExitCode, string StdOut, string StdErr)> RunPowerShellScriptAsync(string script, CancellationToken cancellationToken)
    {
        string tempPath = Path.Combine(Path.GetTempPath(), $"dms-snapshot-{Guid.NewGuid():N}.ps1");
        await File.WriteAllTextAsync(tempPath, script, cancellationToken);
        try
        {
            return await ProcessRunner.RunAsync("powershell.exe", $"-NoProfile -ExecutionPolicy Bypass -File \"{tempPath}\"", cancellationToken);
        }
        finally
        {
            try
            {
                if (File.Exists(tempPath))
                {
                    File.Delete(tempPath);
                }
            }
            catch
            {
                // Ignore cleanup failures for temp script.
            }
        }
    }

    public static string Truncate(string? value, int maxLength = 4096)
    {
        string text = value ?? string.Empty;
        return text.Length <= maxLength ? text : text[..maxLength];
    }

    public static bool IsWindows()
        => RuntimeInformation.IsOSPlatform(OSPlatform.Windows);

    public static HttpClient CreateHookHttpClient()
    {
        int timeoutSeconds = 120;
        string? raw = Environment.GetEnvironmentVariable("DMS_SNAPSHOT_HOOK_TIMEOUT_SECONDS");
        if (!string.IsNullOrWhiteSpace(raw) && int.TryParse(raw, out int parsed))
        {
            timeoutSeconds = Math.Clamp(parsed, 5, 600);
        }

        return new HttpClient
        {
            Timeout = TimeSpan.FromSeconds(timeoutSeconds),
        };
    }
}

public sealed class SnapshotCreateHandler : IJobHandler
{
    public string JobType => "create_snapshot";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        JsonElement payload = SnapshotJobSupport.ToPayloadElement(envelope.Payload);
        string provider = SnapshotJobSupport.ReadString(payload, "provider", "windows_restore_point").Trim().ToLowerInvariant();
        if (string.IsNullOrWhiteSpace(provider))
        {
            provider = "windows_restore_point";
        }

        bool dryRun = SnapshotJobSupport.ReadBool(payload, "dry_run", false);
        if (provider == "external_hook")
        {
            return await ExecuteExternalHookAsync(envelope, payload, dryRun, cancellationToken);
        }

        if (!SnapshotJobSupport.IsWindows())
        {
            return ("failed", 1, new { error = "create_snapshot currently supports Windows endpoints or provider=external_hook" });
        }

        string label = SnapshotJobSupport.ReadString(payload, "label", $"DMS-{DateTime.UtcNow:yyyyMMddHHmmss}").Trim();
        if (string.IsNullOrWhiteSpace(label))
        {
            label = $"DMS-{DateTime.UtcNow:yyyyMMddHHmmss}";
        }
        if (label.Length > 250)
        {
            label = label[..250];
        }

        string restorePointType = SnapshotJobSupport.ReadString(payload, "restore_point_type", "MODIFY_SETTINGS").Trim().ToUpperInvariant();
        if (string.IsNullOrWhiteSpace(restorePointType))
        {
            restorePointType = "MODIFY_SETTINGS";
        }
        string restoreDrive = NormalizeRestorePointDrive(
            SnapshotJobSupport.ReadString(payload, "restore_drive", string.Empty),
            Environment.GetEnvironmentVariable("SystemDrive") ?? "C:"
        );

        bool includeVss = SnapshotJobSupport.ReadBool(payload, "include_vss", false);
        bool failOnVssError = SnapshotJobSupport.ReadBool(payload, "fail_on_vss_error", false);
        var vssVolumes = SnapshotJobSupport.ReadStringList(payload, "vss_volumes");
        if (includeVss && vssVolumes.Count == 0)
        {
            vssVolumes.Add("C:");
        }

        if (dryRun)
        {
            return ("success", 0, new
            {
                dry_run = true,
                provider,
                label,
                restore_point_type = restorePointType,
                include_vss = includeVss,
                vss_volumes = vssVolumes,
                fail_on_vss_error = failOnVssError,
            });
        }

        string escapedLabel = SnapshotJobSupport.EscapePowerShellSingleQuotedString(label);
        string escapedType = SnapshotJobSupport.EscapePowerShellSingleQuotedString(restorePointType);
        string escapedDrive = SnapshotJobSupport.EscapePowerShellSingleQuotedString(restoreDrive);
        string ps = string.Join(Environment.NewLine, new[]
        {
            "$ErrorActionPreference='Stop'",
            $"$label='{escapedLabel}'",
            $"$rtype='{escapedType}'",
            $"$drive='{escapedDrive}'",
            "if ($drive -notmatch '^[A-Za-z]:\\\\$') { throw \"restore_drive must be like C:\\\\\" }",
            "$svcList = @('VSS','swprv')",
            "foreach ($svc in $svcList) {",
            "  try { Set-Service -Name $svc -StartupType Manual -ErrorAction SilentlyContinue } catch {}",
            "  try { Start-Service -Name $svc -ErrorAction SilentlyContinue } catch {}",
            "}",
            "try { Enable-ComputerRestore -Drive $drive -ErrorAction SilentlyContinue } catch {}",
            "Checkpoint-Computer -Description $label -RestorePointType $rtype | Out-Null",
            "$rp = Get-ComputerRestorePoint | Sort-Object SequenceNumber -Descending | Select-Object -First 1",
            "if ($null -eq $rp) { throw 'Restore point created but not found in Get-ComputerRestorePoint output.' }",
            "$result = [ordered]@{",
            "  sequence_number = [int]$rp.SequenceNumber",
            "  description = [string]$rp.Description",
            "  creation_time = [string]$rp.CreationTime",
            "  event_type = [int]$rp.EventType",
            "  restore_point_type = [int]$rp.RestorePointType",
            "}",
            "$result | ConvertTo-Json -Compress",
        });

        var createResult = await SnapshotJobSupport.RunPowerShellScriptAsync(ps, cancellationToken);
        if (createResult.ExitCode != 0)
        {
            return ("failed", createResult.ExitCode, new
            {
                error = "restore point creation failed",
                provider,
                label,
                restore_point_type = restorePointType,
                restore_drive = restoreDrive,
                stdout = SnapshotJobSupport.Truncate(createResult.StdOut),
                stderr = SnapshotJobSupport.Truncate(createResult.StdErr),
            });
        }

        JsonElement? restorePointInfo = null;
        try
        {
            string output = (createResult.StdOut ?? string.Empty).Trim();
            if (!string.IsNullOrWhiteSpace(output))
            {
                restorePointInfo = JsonSerializer.Deserialize<JsonElement>(output);
            }
        }
        catch
        {
            // Keep operation successful even if parser fails.
        }

        var vssResults = new List<object>();
        int vssFailures = 0;
        if (includeVss)
        {
            foreach (string rawVolume in vssVolumes)
            {
                string volume = NormalizeVssVolume(rawVolume);
                if (string.IsNullOrWhiteSpace(volume))
                {
                    vssResults.Add(new { volume = rawVolume, success = false, error = "invalid volume" });
                    vssFailures++;
                    continue;
                }

                var vssResult = await ProcessRunner.RunAsync("vssadmin.exe", $"create shadow /for={volume}", cancellationToken);
                bool success = vssResult.ExitCode == 0;
                if (!success)
                {
                    vssFailures++;
                }

                vssResults.Add(new
                {
                    volume,
                    success,
                    exit_code = vssResult.ExitCode,
                    stdout = SnapshotJobSupport.Truncate(vssResult.StdOut),
                    stderr = SnapshotJobSupport.Truncate(vssResult.StdErr),
                });
            }
        }

        if (includeVss && failOnVssError && vssFailures > 0)
        {
            return ("failed", 1, new
            {
                error = "restore point created, but one or more VSS shadow-copy steps failed",
                provider,
                label,
                restore_point = restorePointInfo,
                vss_results = vssResults,
                vss_failures = vssFailures,
            });
        }

        return ("success", 0, new
        {
            provider,
            label,
            restore_point_type = restorePointType,
            restore_drive = restoreDrive,
            restore_point = restorePointInfo,
            include_vss = includeVss,
            vss_results = vssResults,
            vss_failures = vssFailures,
        });
    }

    private static string NormalizeRestorePointDrive(string rawDrive, string fallbackDrive)
    {
        static string normalize(string value)
        {
            string trimmed = (value ?? string.Empty).Trim();
            if (trimmed.Length >= 2 && char.IsLetter(trimmed[0]) && trimmed[1] == ':')
            {
                return $"{char.ToUpperInvariant(trimmed[0])}:\\";
            }
            return string.Empty;
        }

        string normalized = normalize(rawDrive);
        if (!string.IsNullOrWhiteSpace(normalized))
        {
            return normalized;
        }

        normalized = normalize(fallbackDrive);
        return string.IsNullOrWhiteSpace(normalized) ? "C:\\" : normalized;
    }

    private static string NormalizeVssVolume(string raw)
    {
        string value = (raw ?? string.Empty).Trim();
        if (value.Length == 0)
        {
            return string.Empty;
        }

        if (value.Length >= 2 && char.IsLetter(value[0]) && value[1] == ':')
        {
            return value[..2].ToUpperInvariant();
        }

        return string.Empty;
    }

    private static async Task<(string Status, int ExitCode, object? Result)> ExecuteExternalHookAsync(
        CommandEnvelopeDto envelope,
        JsonElement payload,
        bool dryRun,
        CancellationToken cancellationToken)
    {
        string hookUrl = SnapshotJobSupport.ReadString(payload, "hook_url", string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(hookUrl))
        {
            return ("failed", 1, new { error = "provider=external_hook requires payload.hook_url" });
        }

        string methodRaw = SnapshotJobSupport.ReadString(payload, "hook_method", "POST").Trim().ToUpperInvariant();
        HttpMethod method = methodRaw switch
        {
            "GET" => HttpMethod.Get,
            "PUT" => HttpMethod.Put,
            "PATCH" => HttpMethod.Patch,
            _ => HttpMethod.Post,
        };

        if (dryRun)
        {
            return ("success", 0, new
            {
                dry_run = true,
                provider = "external_hook",
                hook_url = hookUrl,
                hook_method = method.Method,
                action = "create_snapshot",
            });
        }

        using var client = SnapshotJobSupport.CreateHookHttpClient();
        using var request = new HttpRequestMessage(method, hookUrl);
        var body = new Dictionary<string, object?>
        {
            ["action"] = "create_snapshot",
            ["command_id"] = envelope.CommandId,
            ["device_id"] = envelope.DeviceId,
            ["requested_at"] = DateTimeOffset.UtcNow.ToString("O"),
        };
        if (payload.ValueKind == JsonValueKind.Object && payload.TryGetProperty("hook_body", out var hookBody))
        {
            body["hook_body"] = JsonSerializer.Deserialize<object?>(hookBody.GetRawText());
        }
        else
        {
            body["payload"] = JsonSerializer.Deserialize<object?>(payload.GetRawText());
        }

        if (method != HttpMethod.Get)
        {
            request.Content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json");
        }

        if (payload.ValueKind == JsonValueKind.Object
            && payload.TryGetProperty("hook_headers", out var headersNode)
            && headersNode.ValueKind == JsonValueKind.Object)
        {
            foreach (var property in headersNode.EnumerateObject())
            {
                string headerValue = property.Value.ValueKind == JsonValueKind.String
                    ? (property.Value.GetString() ?? string.Empty)
                    : property.Value.GetRawText();
                if (!string.IsNullOrWhiteSpace(property.Name) && !string.IsNullOrWhiteSpace(headerValue))
                {
                    request.Headers.TryAddWithoutValidation(property.Name, headerValue);
                }
            }
        }

        using var response = await client.SendAsync(request, cancellationToken);
        string responseBody = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            return ("failed", 1, new
            {
                error = "external_hook request failed",
                status_code = (int)response.StatusCode,
                hook_url = hookUrl,
                response = SnapshotJobSupport.Truncate(responseBody),
            });
        }

        return ("success", 0, new
        {
            provider = "external_hook",
            hook_url = hookUrl,
            hook_method = method.Method,
            status_code = (int)response.StatusCode,
            response = SnapshotJobSupport.Truncate(responseBody),
        });
    }
}

public sealed class SnapshotRestoreHandler : IJobHandler
{
    public string JobType => "restore_snapshot";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        JsonElement payload = SnapshotJobSupport.ToPayloadElement(envelope.Payload);
        string provider = SnapshotJobSupport.ReadString(payload, "provider", "windows_restore_point").Trim().ToLowerInvariant();
        if (string.IsNullOrWhiteSpace(provider))
        {
            provider = "windows_restore_point";
        }
        bool dryRun = SnapshotJobSupport.ReadBool(payload, "dry_run", false);

        if (provider == "external_hook")
        {
            return await ExecuteExternalHookAsync(envelope, payload, dryRun, cancellationToken);
        }

        if (!SnapshotJobSupport.IsWindows())
        {
            return ("failed", 1, new { error = "restore_snapshot currently supports Windows endpoints or provider=external_hook" });
        }

        int? sequence = SnapshotJobSupport.ReadInt(payload, "restore_point_sequence", "sequence_number", "sequence");
        string description = SnapshotJobSupport.ReadString(payload, "restore_point_description", SnapshotJobSupport.ReadString(payload, "label", string.Empty)).Trim();
        bool rebootNow = SnapshotJobSupport.ReadBool(payload, "reboot_now", true);
        string rebootCommand = SnapshotJobSupport.ReadString(payload, "reboot_command", "shutdown.exe /r /t 0").Trim();
        if (string.IsNullOrWhiteSpace(rebootCommand))
        {
            rebootCommand = "shutdown.exe /r /t 0";
        }

        if (!sequence.HasValue && string.IsNullOrWhiteSpace(description))
        {
            return ("failed", 1, new { error = "restore_snapshot requires payload.restore_point_sequence or payload.restore_point_description" });
        }

        if (dryRun)
        {
            return ("success", 0, new
            {
                dry_run = true,
                provider,
                restore_point_sequence = sequence,
                restore_point_description = description,
                reboot_now = rebootNow,
                reboot_command = rebootCommand,
            });
        }

        string restoreScript;
        if (sequence.HasValue)
        {
            restoreScript = string.Join(Environment.NewLine, new[]
            {
                "$ErrorActionPreference='Stop'",
                $"$seq = {sequence.Value}",
                "Restore-Computer -RestorePoint $seq -Confirm:$false",
                "$result = [ordered]@{ restored_sequence = $seq }",
                "$result | ConvertTo-Json -Compress",
            });
        }
        else
        {
            string escapedDescription = SnapshotJobSupport.EscapePowerShellSingleQuotedString(description);
            restoreScript = string.Join(Environment.NewLine, new[]
            {
                "$ErrorActionPreference='Stop'",
                $"$desc = '{escapedDescription}'",
                "$rp = Get-ComputerRestorePoint | Where-Object { $_.Description -eq $desc } | Sort-Object SequenceNumber -Descending | Select-Object -First 1",
                "if ($null -eq $rp) { throw \"No restore point found with description: $desc\" }",
                "Restore-Computer -RestorePoint $rp.SequenceNumber -Confirm:$false",
                "$result = [ordered]@{ restored_sequence = [int]$rp.SequenceNumber; description = [string]$rp.Description }",
                "$result | ConvertTo-Json -Compress",
            });
        }

        var restoreResult = await SnapshotJobSupport.RunPowerShellScriptAsync(restoreScript, cancellationToken);
        if (restoreResult.ExitCode != 0)
        {
            return ("failed", restoreResult.ExitCode, new
            {
                error = "restore snapshot command failed",
                provider,
                restore_point_sequence = sequence,
                restore_point_description = description,
                stdout = SnapshotJobSupport.Truncate(restoreResult.StdOut),
                stderr = SnapshotJobSupport.Truncate(restoreResult.StdErr),
            });
        }

        JsonElement? restoreInfo = null;
        try
        {
            string output = (restoreResult.StdOut ?? string.Empty).Trim();
            if (!string.IsNullOrWhiteSpace(output))
            {
                restoreInfo = JsonSerializer.Deserialize<JsonElement>(output);
            }
        }
        catch
        {
            // Keep operation successful even if output parsing fails.
        }

        if (!rebootNow)
        {
            return ("success", 0, new
            {
                provider,
                restored = restoreInfo,
                reboot_queued = false,
                message = "snapshot restore executed; reboot deferred by payload",
            });
        }

        var rebootResult = await ProcessRunner.RunAsync("cmd.exe", $"/c {rebootCommand}", cancellationToken);
        if (rebootResult.ExitCode != 0)
        {
            return ("failed", rebootResult.ExitCode, new
            {
                error = "snapshot restore executed but reboot command failed",
                provider,
                restored = restoreInfo,
                reboot_command = rebootCommand,
                reboot_stdout = SnapshotJobSupport.Truncate(rebootResult.StdOut),
                reboot_stderr = SnapshotJobSupport.Truncate(rebootResult.StdErr),
            });
        }

        return ("success", 0, new
        {
            provider,
            restored = restoreInfo,
            reboot_queued = true,
            reboot_command = rebootCommand,
        });
    }

    private static async Task<(string Status, int ExitCode, object? Result)> ExecuteExternalHookAsync(
        CommandEnvelopeDto envelope,
        JsonElement payload,
        bool dryRun,
        CancellationToken cancellationToken)
    {
        string hookUrl = SnapshotJobSupport.ReadString(payload, "hook_url", string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(hookUrl))
        {
            return ("failed", 1, new { error = "provider=external_hook requires payload.hook_url" });
        }

        string methodRaw = SnapshotJobSupport.ReadString(payload, "hook_method", "POST").Trim().ToUpperInvariant();
        HttpMethod method = methodRaw switch
        {
            "GET" => HttpMethod.Get,
            "PUT" => HttpMethod.Put,
            "PATCH" => HttpMethod.Patch,
            _ => HttpMethod.Post,
        };

        if (dryRun)
        {
            return ("success", 0, new
            {
                dry_run = true,
                provider = "external_hook",
                hook_url = hookUrl,
                hook_method = method.Method,
                action = "restore_snapshot",
            });
        }

        using var client = SnapshotJobSupport.CreateHookHttpClient();
        using var request = new HttpRequestMessage(method, hookUrl);
        var body = new Dictionary<string, object?>
        {
            ["action"] = "restore_snapshot",
            ["command_id"] = envelope.CommandId,
            ["device_id"] = envelope.DeviceId,
            ["requested_at"] = DateTimeOffset.UtcNow.ToString("O"),
        };
        if (payload.ValueKind == JsonValueKind.Object && payload.TryGetProperty("hook_body", out var hookBody))
        {
            body["hook_body"] = JsonSerializer.Deserialize<object?>(hookBody.GetRawText());
        }
        else
        {
            body["payload"] = JsonSerializer.Deserialize<object?>(payload.GetRawText());
        }

        if (method != HttpMethod.Get)
        {
            request.Content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json");
        }

        if (payload.ValueKind == JsonValueKind.Object
            && payload.TryGetProperty("hook_headers", out var headersNode)
            && headersNode.ValueKind == JsonValueKind.Object)
        {
            foreach (var property in headersNode.EnumerateObject())
            {
                string headerValue = property.Value.ValueKind == JsonValueKind.String
                    ? (property.Value.GetString() ?? string.Empty)
                    : property.Value.GetRawText();
                if (!string.IsNullOrWhiteSpace(property.Name) && !string.IsNullOrWhiteSpace(headerValue))
                {
                    request.Headers.TryAddWithoutValidation(property.Name, headerValue);
                }
            }
        }

        using var response = await client.SendAsync(request, cancellationToken);
        string responseBody = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            return ("failed", 1, new
            {
                error = "external_hook request failed",
                status_code = (int)response.StatusCode,
                hook_url = hookUrl,
                response = SnapshotJobSupport.Truncate(responseBody),
            });
        }

        return ("success", 0, new
        {
            provider = "external_hook",
            hook_url = hookUrl,
            hook_method = method.Method,
            status_code = (int)response.StatusCode,
            response = SnapshotJobSupport.Truncate(responseBody),
        });
    }
}

public sealed class AgentUpdateHandler : IJobHandler
{
    public string JobType => "update_agent";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        string downloadUrl = envelope.Payload.TryGetValue("download_url", out var urlObj) ? urlObj?.ToString() ?? string.Empty : string.Empty;
        string expectedSha256 = envelope.Payload.TryGetValue("sha256", out var shaObj) ? shaObj?.ToString() ?? string.Empty : string.Empty;
        string fileName = envelope.Payload.TryGetValue("file_name", out var fileObj) ? fileObj?.ToString() ?? string.Empty : string.Empty;

        if (string.IsNullOrWhiteSpace(downloadUrl))
        {
            var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
            return ("failed", 1, new { error = "download_url missing", rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
        }

        string programData = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
        string root = Path.Combine(programData, "DMS", "Updates", DateTime.UtcNow.ToString("yyyyMMddHHmmss"));
        Directory.CreateDirectory(root);

        string inferredName = fileName;
        if (string.IsNullOrWhiteSpace(inferredName))
        {
            try
            {
                inferredName = Path.GetFileName(new Uri(downloadUrl).AbsolutePath);
            }
            catch
            {
                inferredName = string.Empty;
            }
        }
        if (string.IsNullOrWhiteSpace(inferredName))
        {
            inferredName = "dms-agent-update.zip";
        }

        string artifactPath = Path.Combine(root, inferredName);
        using (var client = ProcessRunner.CreateArtifactDownloadHttpClient())
        using (var response = await client.GetAsync(downloadUrl, cancellationToken))
        {
            response.EnsureSuccessStatusCode();
            await using var fs = new FileStream(artifactPath, FileMode.Create, FileAccess.Write, FileShare.Read);
            await response.Content.CopyToAsync(fs, cancellationToken);
        }

        string actualSha256 = Convert.ToHexString(SHA256.HashData(await File.ReadAllBytesAsync(artifactPath, cancellationToken))).ToLowerInvariant();
        if (!string.IsNullOrWhiteSpace(expectedSha256) && !string.Equals(actualSha256, expectedSha256, StringComparison.OrdinalIgnoreCase))
        {
            var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
            return ("failed", 1, new { error = "sha256 mismatch", expected = expectedSha256, actual = actualSha256, rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
        }

        string ext = Path.GetExtension(artifactPath).ToLowerInvariant();
        if (ext == ".msi")
        {
            var result = await ProcessRunner.RunAsync("msiexec", $"/i \"{artifactPath}\" /qn /norestart", cancellationToken);
            string status = result.ExitCode is 0 or 3010 ? "success" : "failed";
            if (status == "failed")
            {
                var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
                return (status, result.ExitCode, new { result.StdOut, result.StdErr, sha256 = actualSha256, rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
            }
            return (status, result.ExitCode, new { result.StdOut, result.StdErr, sha256 = actualSha256 });
        }

        if (ext == ".exe")
        {
            var result = await ProcessRunner.RunAsync(artifactPath, "/quiet /norestart", cancellationToken);
            string status = result.ExitCode is 0 or 3010 ? "success" : "failed";
            if (status == "failed")
            {
                var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
                return (status, result.ExitCode, new { result.StdOut, result.StdErr, sha256 = actualSha256, rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
            }
            return (status, result.ExitCode, new { result.StdOut, result.StdErr, sha256 = actualSha256 });
        }

        if (ext != ".zip")
        {
            var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
            return ("failed", 1, new { error = $"unsupported update artifact type: {ext}", rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
        }

        string extractDir = Path.Combine(root, "bundle");
        Directory.CreateDirectory(extractDir);
        ZipFile.ExtractToDirectory(artifactPath, extractDir, true);

        string installScript = Path.Combine(extractDir, "installer", "windows-service-install.ps1");
        if (!File.Exists(installScript))
        {
            var rollback = await JobHandlerSupport.TryRunRollbackAsync(envelope.Payload, cancellationToken);
            return ("failed", 1, new { error = "installer/windows-service-install.ps1 missing in update zip", rollback_attempted = rollback.Attempted, rollback_exit_code = rollback.ExitCode, rollback_stdout = rollback.StdOut, rollback_stderr = rollback.StdErr });
        }

        string updaterScript = Path.Combine(root, "apply-update.ps1");
        string updaterLog = Path.Combine(root, "apply-update.log");
        string escapedInstallScript = installScript.Replace("'", "''");
        string escapedLog = updaterLog.Replace("'", "''");
        string updaterContent = string.Join(Environment.NewLine, new[]
        {
            "$ErrorActionPreference = 'Stop'",
            "Start-Sleep -Seconds 2",
            $"powershell -NoProfile -ExecutionPolicy Bypass -File '{escapedInstallScript}' *>> '{escapedLog}' 2>&1"
        });
        await File.WriteAllTextAsync(updaterScript, updaterContent, cancellationToken);

        Process.Start(new ProcessStartInfo
        {
            FileName = "powershell",
            Arguments = $"-NoProfile -ExecutionPolicy Bypass -File \"{updaterScript}\"",
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden,
        });

        return ("success", 0, new
        {
            message = "update launched",
            artifact = artifactPath,
            sha256 = actualSha256,
            updater_script = updaterScript,
            updater_log = updaterLog,
        });
    }
}

public sealed class AgentUninstallHandler : IJobHandler
{
    public string JobType => "uninstall_agent";

    public async Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken)
    {
        if (!RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            return ("failed", 1, new { error = "uninstall_agent currently supports Windows endpoints only" });
        }

        var authorization = AgentUninstallAuthorization.Validate(envelope.Payload);
        if (!authorization.Allowed)
        {
            return ("failed", 1, new { error = authorization.Error ?? "agent uninstall blocked", authorization = authorization.Details });
        }

        string serviceName = envelope.Payload.TryGetValue("service_name", out var serviceObj)
            ? serviceObj?.ToString() ?? "DMSAgent"
            : "DMSAgent";
        if (string.IsNullOrWhiteSpace(serviceName))
        {
            serviceName = "DMSAgent";
        }

        string installDir = envelope.Payload.TryGetValue("install_dir", out var dirObj)
            ? dirObj?.ToString() ?? @"C:\Program Files\DMS Agent"
            : @"C:\Program Files\DMS Agent";
        if (string.IsNullOrWhiteSpace(installDir))
        {
            installDir = @"C:\Program Files\DMS Agent";
        }

        string programData = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
        string dataDir = envelope.Payload.TryGetValue("data_dir", out var dataObj)
            ? dataObj?.ToString() ?? Path.Combine(programData, "DMS")
            : Path.Combine(programData, "DMS");

        string workDir = Path.Combine(programData, "DMS", "SelfUninstall");
        Directory.CreateDirectory(workDir);

        string scriptPath = Path.Combine(workDir, $"uninstall-agent-{DateTimeOffset.UtcNow:yyyyMMddHHmmss}.cmd");
        string logPath = Path.Combine(workDir, "uninstall-agent.log");

        string escapedService = serviceName.Replace("\"", "\"\"");
        string escapedInstall = installDir.Replace("\"", "\"\"");
        string escapedData = dataDir.Replace("\"", "\"\"");
        string escapedScript = scriptPath.Replace("\"", "\"\"");

        // Launch delayed cleanup so job result can be posted before service is stopped/deleted.
        string cmdBody = string.Join(Environment.NewLine, new[]
        {
            "@echo off",
            "setlocal",
            "timeout /t 8 /nobreak >nul",
            $"echo [%date% %time%] stopping service {escapedService}>>\"{logPath}\"",
            $"sc stop \"{escapedService}\" >>\"{logPath}\" 2>&1",
            "timeout /t 3 /nobreak >nul",
            $"echo [%date% %time%] deleting service {escapedService}>>\"{logPath}\"",
            $"sc delete \"{escapedService}\" >>\"{logPath}\" 2>&1",
            $"echo [%date% %time%] deleting install dir {escapedInstall}>>\"{logPath}\"",
            $"rmdir /s /q \"{escapedInstall}\" >>\"{logPath}\" 2>&1",
            $"if /I not \"{escapedData}\"==\"{escapedInstall}\" (",
            $"  echo [%date% %time%] deleting data dir {escapedData}>>\"{logPath}\"",
            $"  rmdir /s /q \"{escapedData}\" >>\"{logPath}\" 2>&1",
            ")",
            $"del /f /q \"{escapedScript}\" >nul 2>&1",
            "exit /b 0",
        });

        await File.WriteAllTextAsync(scriptPath, cmdBody, cancellationToken);

        Process.Start(new ProcessStartInfo
        {
            FileName = "cmd.exe",
            Arguments = $"/c start \"\" /min \"{scriptPath}\"",
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden,
            WorkingDirectory = workDir,
        });

        return ("success", 0, new
        {
            message = "agent uninstall scheduled",
            service_name = serviceName,
            install_dir = installDir,
            data_dir = dataDir,
            script_path = scriptPath,
            log_path = logPath,
        });
    }
}
