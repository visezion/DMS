using System.Diagnostics;
using System.IO.Compression;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Runtime.InteropServices;
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
            artifactRemoved = TryRemoveDownloadedArtifact(path);
        }

        return (status, result.ExitCode, new { result.StdOut, result.StdErr, logPath, path, sha256, skipped = false, detection_ok = detected, downloaded_artifact_removed = artifactRemoved });
    }

    private static bool TryRemoveDownloadedArtifact(string path)
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
            artifactRemoved = TryRemoveDownloadedArtifact(path);
        }

        return (status, result.ExitCode, new { result.StdOut, result.StdErr, path, sha256, skipped = false, detection_ok = detected, downloaded_artifact_removed = artifactRemoved });
    }

    private static bool TryRemoveDownloadedArtifact(string path)
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
                artifactRemoved = TryRemoveDownloadedArtifact(archivePath);
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

    private static bool TryRemoveDownloadedArtifact(string path)
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

        var results = new List<object>();
        bool hasNonCompliant = false;

        foreach (var rule in rulesElement.EnumerateArray())
        {
            string type = rule.TryGetProperty("type", out var t) ? (t.GetString() ?? string.Empty) : string.Empty;
            bool enforce = !rule.TryGetProperty("enforce", out var enf) || enf.GetBoolean();
            JsonElement config = rule.TryGetProperty("config", out var cfg) ? cfg : default;

            try
            {
                var applied = type.ToLowerInvariant() switch
                {
                    "firewall" => await ApplyFirewallAsync(config, enforce, cancellationToken),
                    "registry" => ApplyRegistry(config, enforce),
                    "local_group" => await ApplyLocalGroupAsync(config, enforce, cancellationToken),
                    "windows_update" => ApplyWindowsUpdate(config, enforce),
                    "bitlocker" => await CheckBitLockerAsync(config, enforce, cancellationToken),
                    "scheduled_task" => await ApplyScheduledTaskAsync(config, enforce, cancellationToken),
                    "command" => await ApplyCommandAsync(config, enforce, cancellationToken),
                    _ => (Compliant: false, Message: $"unsupported rule type: {type}"),
                };

                if (!applied.Compliant)
                {
                    hasNonCompliant = true;
                }

                results.Add(new { type, enforce, compliant = applied.Compliant, message = applied.Message });
            }
            catch (Exception ex)
            {
                hasNonCompliant = true;
                results.Add(new { type, enforce, compliant = false, message = ex.Message });
            }
        }

        string status = hasNonCompliant ? "non_compliant" : "success";
        return (status, hasNonCompliant ? 2 : 0, new
        {
            compliance_status = hasNonCompliant ? "non_compliant" : "compliant",
            rules = results,
        });
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

        if (!enforce)
        {
            return (true, "command skipped in audit mode");
        }

        var run = await ProcessRunner.RunAsync("cmd.exe", $"/c {command}", cancellationToken);
        bool ok = run.ExitCode is 0 or 3010;
        return (ok, ok ? "command executed" : $"command failed exit code {run.ExitCode}");
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
