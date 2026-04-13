using System.Reflection;
using System.Text.Json;

namespace Dms.Agent.Core.Runtime;

public sealed class AgentSelfDiagnosticsReport
{
    public string BaseDirectory { get; init; } = string.Empty;
    public string AppSettingsPath { get; init; } = string.Empty;
    public bool AppSettingsExists { get; init; }
    public bool BuildImplemented { get; init; }
    public bool ConfiguredEnabled { get; init; }
    public bool AdvertisedEnabled { get; init; }
    public bool IsSelfContainedPackage { get; init; }
    public bool PackageComplete { get; init; }
    public string[] MissingFiles { get; init; } = [];
    public bool ManagedWebRtcAssemblyLoadable { get; init; }
    public string? ManagedWebRtcAssemblyVersion { get; init; }
    public bool NativeWebRtcPresent { get; init; }
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
        "Microsoft.MixedReality.WebRTC.dll",
        "mrwebrtc.dll",
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

        bool managedAssemblyLoadable = false;
        string? managedAssemblyVersion = null;
        string managedAssemblyPath = Path.Combine(effectiveBaseDirectory, "Microsoft.MixedReality.WebRTC.dll");
        if (File.Exists(managedAssemblyPath))
        {
            try
            {
                AssemblyName assemblyName = AssemblyName.GetAssemblyName(managedAssemblyPath);
                managedAssemblyVersion = assemblyName.Version?.ToString();
                Assembly.LoadFrom(managedAssemblyPath);
                managedAssemblyLoadable = true;
            }
            catch
            {
                managedAssemblyLoadable = false;
            }
        }

        bool configuredEnabled = WebRtcMediaPipelineCapability.IsConfiguredEnabled(envValue, effectiveAppSettingsPath);
        bool advertisedEnabled = WebRtcMediaPipelineCapability.IsAdvertisedEnabled(envValue, effectiveAppSettingsPath);
        bool packageComplete = missingFiles.Length == 0;

        List<string> notes = [];
        if (!File.Exists(effectiveAppSettingsPath))
        {
            notes.Add("appsettings.json missing");
        }
        if (!configuredEnabled)
        {
            notes.Add("WebRTC media pipeline not enabled in runtime configuration");
        }
        if (!managedAssemblyLoadable)
        {
            notes.Add("Microsoft.MixedReality.WebRTC.dll failed to load");
        }
        if (!File.Exists(Path.Combine(effectiveBaseDirectory, "mrwebrtc.dll")))
        {
            notes.Add("mrwebrtc.dll missing");
        }
        if (!packageComplete)
        {
            notes.Add("Agent package layout incomplete");
        }

        bool healthy = WebRtcMediaPipelineCapability.IsBuildImplemented()
            && configuredEnabled
            && packageComplete
            && managedAssemblyLoadable
            && File.Exists(Path.Combine(effectiveBaseDirectory, "mrwebrtc.dll"));

        return new AgentSelfDiagnosticsReport
        {
            BaseDirectory = effectiveBaseDirectory,
            AppSettingsPath = effectiveAppSettingsPath,
            AppSettingsExists = File.Exists(effectiveAppSettingsPath),
            BuildImplemented = WebRtcMediaPipelineCapability.IsBuildImplemented(),
            ConfiguredEnabled = configuredEnabled,
            AdvertisedEnabled = advertisedEnabled,
            IsSelfContainedPackage = isSelfContained,
            PackageComplete = packageComplete,
            MissingFiles = missingFiles,
            ManagedWebRtcAssemblyLoadable = managedAssemblyLoadable,
            ManagedWebRtcAssemblyVersion = managedAssemblyVersion,
            NativeWebRtcPresent = File.Exists(Path.Combine(effectiveBaseDirectory, "mrwebrtc.dll")),
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
