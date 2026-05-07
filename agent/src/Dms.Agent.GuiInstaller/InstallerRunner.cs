using System.Diagnostics;
using System.Net.Http;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using System.IO.Compression;

namespace Dms.Agent.GuiInstaller;

internal sealed class InstallerRunner
{
    private readonly InstallerConfig _config;
    private readonly Action<string> _log;

    public InstallerRunner(InstallerConfig config, Action<string> log)
    {
        _config = config;
        _log = log;
    }

    public async Task RunAsync(IProgress<InstallerProgress> progress, CancellationToken cancellationToken)
    {
        EnsureAdmin();

        progress.Report(new InstallerProgress(5, "Preparing installer..."));
        _log("Preparing installer...");

        string workDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "DMS");
        string diagnosticsDir = Path.Combine(workDir, "Diagnostics");
        Directory.CreateDirectory(workDir);
        Directory.CreateDirectory(diagnosticsDir);

        string tokenFile = Path.Combine(workDir, "enrollment-token.txt");
        string apiFile = Path.Combine(workDir, "api-base-url.txt");
        Environment.SetEnvironmentVariable("DMS_API_BASE_URL", _config.ApiBaseUrl, EnvironmentVariableTarget.Machine);
        Environment.SetEnvironmentVariable("DMS_ENROLLMENT_TOKEN", _config.Token, EnvironmentVariableTarget.Machine);
        File.WriteAllText(tokenFile, _config.Token, Encoding.UTF8);
        File.WriteAllText(apiFile, _config.ApiBaseUrl, Encoding.UTF8);

        string fileName = string.IsNullOrWhiteSpace(_config.FileName)
            ? Path.GetFileName(new Uri(_config.DownloadUrl).AbsolutePath)
            : _config.FileName;
        if (string.IsNullOrWhiteSpace(fileName))
        {
            fileName = "dms-agent.zip";
        }

        string installerPath = Path.Combine(workDir, fileName);
        await DownloadAsync(_config.DownloadUrl, installerPath, progress, cancellationToken);

        progress.Report(new InstallerProgress(85, "Running installer..."));
        _log("Launching installer...");
        await InstallAsync(installerPath, workDir, cancellationToken);

        progress.Report(new InstallerProgress(100, "Installation complete."));
        _log("Installation complete.");
    }

    private static void EnsureAdmin()
    {
        using WindowsIdentity identity = WindowsIdentity.GetCurrent();
        WindowsPrincipal principal = new(identity);
        if (principal.IsInRole(WindowsBuiltInRole.Administrator))
        {
            return;
        }

        string exePath = Environment.ProcessPath ?? string.Empty;
        if (string.IsNullOrWhiteSpace(exePath))
        {
            throw new InvalidOperationException("Installer must run as Administrator.");
        }

        var startInfo = new ProcessStartInfo(exePath)
        {
            UseShellExecute = true,
            Verb = "runas"
        };
        Process.Start(startInfo);
        Environment.Exit(0);
    }

    private async Task DownloadAsync(string url, string destination, IProgress<InstallerProgress> progress, CancellationToken cancellationToken)
    {
        _log("Downloading agent package...");
        progress.Report(new InstallerProgress(10, "Downloading agent package..."));

        using HttpClient client = new();
        using HttpResponseMessage response = await client.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
        response.EnsureSuccessStatusCode();
        long? total = response.Content.Headers.ContentLength;

        await using Stream content = await response.Content.ReadAsStreamAsync(cancellationToken);
        await using FileStream file = new(destination, FileMode.Create, FileAccess.Write, FileShare.None);
        byte[] buffer = new byte[81920];
        long readTotal = 0;
        int read;
        while ((read = await content.ReadAsync(buffer, 0, buffer.Length, cancellationToken)) > 0)
        {
            await file.WriteAsync(buffer.AsMemory(0, read), cancellationToken);
            readTotal += read;
            if (total.HasValue && total.Value > 0)
            {
                int pct = 10 + (int) ((readTotal * 70) / total.Value);
                progress.Report(new InstallerProgress(Math.Min(80, Math.Max(10, pct)), "Downloading agent package..."));
            }
        }
    }

    private async Task InstallAsync(string installerPath, string workDir, CancellationToken cancellationToken)
    {
        string ext = Path.GetExtension(installerPath).ToLowerInvariant();
        if (ext == ".msi")
        {
            await RunProcessAsync("msiexec.exe", $"/i \"{installerPath}\" /norestart", true, cancellationToken);
            return;
        }

        if (ext == ".exe")
        {
            await RunProcessAsync(installerPath, string.Empty, true, cancellationToken);
            return;
        }

        if (ext == ".zip")
        {
            string extractPath = Path.Combine(workDir, "agent");
            if (Directory.Exists(extractPath))
            {
                Directory.Delete(extractPath, true);
            }
            Directory.CreateDirectory(extractPath);
            ZipFile.ExtractToDirectory(installerPath, extractPath, true);

            string installScript = Path.Combine(extractPath, "installer", "windows-service-install.ps1");
            if (!File.Exists(installScript))
            {
                throw new InvalidOperationException("Installer zip does not contain installer\\windows-service-install.ps1");
            }
            await RunProcessAsync("powershell.exe", $"-NoProfile -ExecutionPolicy Bypass -File \"{installScript}\"", false, cancellationToken);
            return;
        }

        throw new InvalidOperationException($"Unsupported installer type: {ext}");
    }

    private async Task RunProcessAsync(string fileName, string arguments, bool useShellExecute, CancellationToken cancellationToken)
    {
        var info = new ProcessStartInfo(fileName, arguments)
        {
            UseShellExecute = useShellExecute,
            CreateNoWindow = !useShellExecute,
            WindowStyle = useShellExecute ? ProcessWindowStyle.Normal : ProcessWindowStyle.Hidden
        };

        using Process? process = Process.Start(info);
        if (process == null)
        {
            throw new InvalidOperationException($"Failed to start process: {fileName}");
        }

        await Task.Run(() => process.WaitForExit(), cancellationToken);
        if (process.ExitCode != 0)
        {
            throw new InvalidOperationException($"{fileName} failed with exit code {process.ExitCode}");
        }
    }
}

internal readonly record struct InstallerProgress(int Percent, string Message);
