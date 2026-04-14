using System.Text.Json;

namespace Dms.Agent.Core.Runtime;

public sealed class AgentSelfDiagnosticsReport
{
    public string BaseDirectory { get; init; } = string.Empty;
    public string AppSettingsPath { get; init; } = string.Empty;
    public bool AppSettingsExists { get; init; }
    public bool IsSelfContainedPackage { get; init; }
    public bool PackageComplete { get; init; }
    public string[] MissingFiles { get; init; } = [];
    public bool Healthy { get; init; }
    public string[] Notes { get; init; } = [];
}

public static class AgentSelfDiagnostics
{
    private static readonly string[] BaseRequiredFiles =
    [
        "Dms.Agent.Service.exe",
        "Dms.Agent.Service.dll",
        "Dms.Agent.Core.dll",
        "Dms.Agent.Service.deps.json",
        "Dms.Agent.Service.runtimeconfig.json",
        "appsettings.json",
    ];

    private static readonly string[] SelfContainedRuntimeFiles =
    [
        "hostfxr.dll",
        "hostpolicy.dll",
        "coreclr.dll",
    ];

    public static AgentSelfDiagnosticsReport Generate(string? baseDirectory = null, string? envValue = null, string? appSettingsPath = null)
    {
        string effectiveBaseDirectory = string.IsNullOrWhiteSpace(baseDirectory)
            ? AppContext.BaseDirectory
            : Path.GetFullPath(baseDirectory);
        string effectiveAppSettingsPath = string.IsNullOrWhiteSpace(appSettingsPath)
            ? Path.Combine(effectiveBaseDirectory, "appsettings.json")
            : appSettingsPath;

        bool isSelfContained = SelfContainedRuntimeFiles.Any(name => File.Exists(Path.Combine(effectiveBaseDirectory, name)));
        List<string> requiredFiles = [.. BaseRequiredFiles];
        if (isSelfContained)
        {
            requiredFiles.AddRange(SelfContainedRuntimeFiles);
        }

        string[] missingFiles = requiredFiles
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Where(name => !File.Exists(Path.Combine(effectiveBaseDirectory, name)))
            .OrderBy(name => name, StringComparer.OrdinalIgnoreCase)
            .ToArray();

        bool packageComplete = missingFiles.Length == 0;

        List<string> notes = [];
        if (!File.Exists(effectiveAppSettingsPath))
        {
            notes.Add("appsettings.json missing");
        }
        if (!packageComplete)
        {
            notes.Add("Agent package layout incomplete");
        }

        bool healthy = packageComplete;

        return new AgentSelfDiagnosticsReport
        {
            BaseDirectory = effectiveBaseDirectory,
            AppSettingsPath = effectiveAppSettingsPath,
            AppSettingsExists = File.Exists(effectiveAppSettingsPath),
            IsSelfContainedPackage = isSelfContained,
            PackageComplete = packageComplete,
            MissingFiles = missingFiles,
            Healthy = healthy,
            Notes = notes.ToArray(),
        };
    }

    public static int TryRunCli(string[] args)
    {
        if (args.Length == 0)
        {
            return int.MinValue;
        }

        string command = args[0].Trim();
        if (!string.Equals(command, "--diagnostics", StringComparison.OrdinalIgnoreCase)
            && !string.Equals(command, "doctor", StringComparison.OrdinalIgnoreCase))
        {
            return int.MinValue;
        }

        AgentSelfDiagnosticsReport report = Generate();
        JsonSerializerOptions options = new()
        {
            WriteIndented = true,
        };
        Console.Out.WriteLine(JsonSerializer.Serialize(report, options));
        return report.Healthy ? 0 : 1;
    }
}
