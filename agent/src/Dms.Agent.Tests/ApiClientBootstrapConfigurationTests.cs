using System;
using System.Collections.Generic;
using System.IO;
using Dms.Agent.Core.Runtime;
using Xunit;

namespace Dms.Agent.Tests;

[Collection("EnvironmentSerial")]
public sealed class ApiClientBootstrapConfigurationTests : IDisposable
{
    private readonly string _programDataRoot = Path.Combine(Path.GetTempPath(), "dms-agent-tests", Guid.NewGuid().ToString("N"));
    private readonly string _baseDirectory = Path.Combine(Path.GetTempPath(), "dms-agent-tests", Guid.NewGuid().ToString("N"));
    private readonly Dictionary<string, string?> _originalValues = new();

    public ApiClientBootstrapConfigurationTests()
    {
        Directory.CreateDirectory(_programDataRoot);
        Directory.CreateDirectory(_baseDirectory);
        CaptureEnvironment("ProgramData");
        CaptureEnvironment("DMS_API_BASE_URL");
        CaptureEnvironment("DMS_ENROLLMENT_TOKEN");
        CaptureEnvironment("DMS_CHECKIN_INTERVAL_SECONDS");
        Environment.SetEnvironmentVariable("ProgramData", _programDataRoot);
        Environment.SetEnvironmentVariable("DMS_API_BASE_URL", null);
        Environment.SetEnvironmentVariable("DMS_ENROLLMENT_TOKEN", null);
        Environment.SetEnvironmentVariable("DMS_CHECKIN_INTERVAL_SECONDS", null);
    }

    [Fact]
    public void Load_UsesAppSettingsFallbackWhenProgramDataFilesAreMissing()
    {
        File.WriteAllText(
            Path.Combine(_baseDirectory, "appsettings.json"),
            """
            {
              "Dms": {
                "ApiBaseUrl": "http://172.16.43.163/DMS/backend/public/api/v1",
                "CheckinIntervalSeconds": 90
              }
            }
            """);

        AgentBootstrapConfiguration configuration = AgentBootstrapConfiguration.Load(_programDataRoot, _baseDirectory);

        Assert.Equal("http://172.16.43.163/DMS/backend/public/api/v1/", configuration.ResolvedApiBaseUrl);
        Assert.Equal("appsettings", configuration.ApiBaseUrlSource);
        Assert.Equal(90, configuration.CheckinIntervalSeconds);
        Assert.Equal("appsettings", configuration.CheckinIntervalSource);
    }

    [Fact]
    public void Load_PrefersExplicitEnvironmentValues()
    {
        Environment.SetEnvironmentVariable("DMS_API_BASE_URL", "http://env.example/api/v1");
        Environment.SetEnvironmentVariable("DMS_ENROLLMENT_TOKEN", "env-token");
        Environment.SetEnvironmentVariable("DMS_CHECKIN_INTERVAL_SECONDS", "120");

        AgentBootstrapConfiguration configuration = AgentBootstrapConfiguration.Load(_programDataRoot, _baseDirectory);

        Assert.Equal("http://env.example/api/v1/", configuration.ResolvedApiBaseUrl);
        Assert.Equal("env", configuration.ApiBaseUrlSource);
        Assert.Equal("env-token", configuration.EnrollmentToken);
        Assert.Equal("env", configuration.EnrollmentTokenSource);
        Assert.Equal(120, configuration.CheckinIntervalSeconds);
        Assert.Equal("env", configuration.CheckinIntervalSource);
    }

    [Fact]
    public void PersistCheckinInterval_WritesClampedValue()
    {
        AgentBootstrapConfiguration configuration = AgentBootstrapConfiguration.Load(_programDataRoot, _baseDirectory);

        configuration.PersistCheckinInterval(999);
        AgentBootstrapConfiguration reloaded = AgentBootstrapConfiguration.Load(_programDataRoot, _baseDirectory);

        Assert.Equal(300, reloaded.CheckinIntervalSeconds);
        Assert.Equal("file", reloaded.CheckinIntervalSource);
    }

    [Fact]
    public void WriteBootstrapState_DoesNotThrow_WhenTargetFileIsLocked()
    {
        AgentBootstrapConfiguration configuration = AgentBootstrapConfiguration.Load(_programDataRoot, _baseDirectory);
        Directory.CreateDirectory(configuration.DiagnosticsDirectory);
        File.WriteAllText(configuration.BootstrapStatePath, "{}");

        using FileStream locked = new(
            configuration.BootstrapStatePath,
            FileMode.Open,
            FileAccess.Read,
            FileShare.Read);

        Exception? error = Record.Exception(configuration.WriteBootstrapState);
        Assert.Null(error);
    }

    public void Dispose()
    {
        foreach ((string key, string? value) in _originalValues)
        {
            Environment.SetEnvironmentVariable(key, value);
        }

        try
        {
            if (Directory.Exists(_programDataRoot))
            {
                Directory.Delete(_programDataRoot, true);
            }
        }
        catch
        {
            // ignore cleanup failures
        }

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

    private void CaptureEnvironment(string key)
    {
        _originalValues[key] = Environment.GetEnvironmentVariable(key);
    }
}
