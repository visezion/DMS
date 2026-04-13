using System;
using System.IO;
using Dms.Agent.Core.Runtime;
using Xunit;

namespace Dms.Agent.Tests;

[Collection("EnvironmentSerial")]
public sealed class WebRtcMediaPipelineCapabilityTests : IDisposable
{
    private readonly string _baseDirectory = Path.Combine(Path.GetTempPath(), "dms-agent-tests", Guid.NewGuid().ToString("N"));
    private readonly string? _originalEnvValue;

    public WebRtcMediaPipelineCapabilityTests()
    {
        Directory.CreateDirectory(_baseDirectory);
        _originalEnvValue = Environment.GetEnvironmentVariable("DMS_WEBRTC_MEDIA_PIPELINE_ENABLED");
        Environment.SetEnvironmentVariable("DMS_WEBRTC_MEDIA_PIPELINE_ENABLED", null);
    }

    [Fact]
    public void IsAdvertisedEnabled_IsTrue_WhenBuildImplementedAndConfigured()
    {
        string appSettingsPath = Path.Combine(_baseDirectory, "appsettings.json");
        File.WriteAllText(
            appSettingsPath,
            """
            {
              "Dms": {
                "WebRtcMediaPipelineEnabled": true
              }
            }
            """);

        bool advertised = WebRtcMediaPipelineCapability.IsAdvertisedEnabled("true", appSettingsPath);

        Assert.True(WebRtcMediaPipelineCapability.IsBuildImplemented());
        Assert.True(WebRtcMediaPipelineCapability.IsConfiguredEnabled("true", appSettingsPath));
        Assert.True(advertised);
    }

    [Fact]
    public void IsConfiguredEnabled_ReadsFalse_WhenUnsetEverywhere()
    {
        string appSettingsPath = Path.Combine(_baseDirectory, "missing.json");

        bool configured = WebRtcMediaPipelineCapability.IsConfiguredEnabled(null, appSettingsPath);

        Assert.False(configured);
    }

    [Fact]
    public void AgentSelfDiagnostics_Generate_ReportsHealthyForCompleteConfiguredPackage()
    {
        string packageDir = Path.Combine(_baseDirectory, "package");
        Directory.CreateDirectory(packageDir);

        foreach (string name in new[]
                 {
                     "Dms.Agent.Service.exe",
                     "Dms.Agent.Service.dll",
                     "Dms.Agent.Core.dll",
                     "Dms.Agent.Service.deps.json",
                     "Dms.Agent.Service.runtimeconfig.json",
                     "appsettings.json",
                     "mrwebrtc.dll",
                 })
        {
            File.WriteAllText(Path.Combine(packageDir, name), "test");
        }

        File.WriteAllText(
            Path.Combine(packageDir, "appsettings.json"),
            """
            {
              "Dms": {
                "WebRtcMediaPipelineEnabled": true
              }
            }
            """);

        string currentManagedWebRtcPath = Path.Combine(AppContext.BaseDirectory, "Microsoft.MixedReality.WebRTC.dll");
        Assert.True(File.Exists(currentManagedWebRtcPath), "Expected Microsoft.MixedReality.WebRTC.dll in test output.");
        File.Copy(currentManagedWebRtcPath, Path.Combine(packageDir, "Microsoft.MixedReality.WebRTC.dll"));

        AgentSelfDiagnosticsReport report = AgentSelfDiagnostics.Generate(packageDir);

        Assert.True(report.PackageComplete);
        Assert.True(report.ConfiguredEnabled);
        Assert.True(report.ManagedWebRtcAssemblyLoadable);
        Assert.True(report.NativeWebRtcPresent);
        Assert.True(report.Healthy);
    }

    [Fact]
    public void AgentSelfDiagnostics_Generate_ReportsMissingFilesWhenPackageIncomplete()
    {
        string packageDir = Path.Combine(_baseDirectory, "incomplete");
        Directory.CreateDirectory(packageDir);
        File.WriteAllText(Path.Combine(packageDir, "appsettings.json"), "{}");

        AgentSelfDiagnosticsReport report = AgentSelfDiagnostics.Generate(packageDir);

        Assert.False(report.PackageComplete);
        Assert.False(report.Healthy);
        Assert.Contains("Dms.Agent.Service.exe", report.MissingFiles);
        Assert.Contains("Microsoft.MixedReality.WebRTC.dll", report.MissingFiles);
    }

    public void Dispose()
    {
        Environment.SetEnvironmentVariable("DMS_WEBRTC_MEDIA_PIPELINE_ENABLED", _originalEnvValue);

        try
        {
            if (Directory.Exists(_baseDirectory))
            {
                Directory.Delete(_baseDirectory, true);
            }
        }
        catch
        {
            // ignore cleanup failures
        }
    }
}
