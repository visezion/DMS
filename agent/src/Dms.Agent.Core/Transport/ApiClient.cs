using System.Net.Http.Json;
using System.Diagnostics;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using Dms.Agent.Core.Protocol;

namespace Dms.Agent.Core.Transport;

public sealed class ApiClient
{
    private readonly HttpClient _httpClient;
    private readonly string _deviceIdPath;
    private readonly string _enrollmentTokenPath;
    private readonly string _apiBaseUrlPath;
    private const string EmptyDeviceId = "00000000-0000-0000-0000-000000000000";

    public ApiClient()
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
        string dmsDir = Path.Combine(programData, "DMS");
        Directory.CreateDirectory(dmsDir);

        _deviceIdPath = Path.Combine(dmsDir, "device-id.txt");
        _enrollmentTokenPath = Path.Combine(dmsDir, "enrollment-token.txt");
        _apiBaseUrlPath = Path.Combine(dmsDir, "api-base-url.txt");

        string configuredBase = ResolveApiBaseUrl();
        string normalizedBase = configuredBase.EndsWith('/') ? configuredBase : configuredBase + "/";

        _httpClient = new HttpClient
        {
            BaseAddress = new Uri(normalizedBase)
        };
    }

    public async Task<CheckinResponseDto> CheckinAsync(CancellationToken cancellationToken)
    {
        string deviceId = await EnsureEnrolledAsync(cancellationToken);
        string agentVersion = ResolveAgentVersion();
        string agentBuild = ResolveAgentBuild();
        var inventory = await DeviceInventoryCollector.CollectAsync(cancellationToken);
        HttpResponseMessage response = await _httpClient.PostAsJsonAsync("device/checkin", new
        {
            device_id = deviceId,
            agent_version = agentVersion,
            agent_build = agentBuild,
            inventory,
            runtime_diagnostics = CollectRuntimeDiagnostics(),
        }, cancellationToken);

        if (response.StatusCode == System.Net.HttpStatusCode.NotFound && !string.IsNullOrWhiteSpace(ResolveEnrollmentToken()))
        {
            ClearPersistedDeviceId();
            deviceId = await EnsureEnrolledAsync(cancellationToken);
            response = await _httpClient.PostAsJsonAsync("device/checkin", new
            {
                device_id = deviceId,
                agent_version = agentVersion,
                agent_build = agentBuild,
                inventory,
                runtime_diagnostics = CollectRuntimeDiagnostics(),
            }, cancellationToken);
        }

        response.EnsureSuccessStatusCode();

        CheckinResponseDto? payload = await response.Content.ReadFromJsonAsync<CheckinResponseDto>(cancellationToken: cancellationToken);
        return payload ?? new CheckinResponseDto();
    }

    public async Task<List<KeysetKeyDto>> GetKeysetAsync(CancellationToken cancellationToken)
    {
        HttpResponseMessage response = await _httpClient.GetAsync("device/keyset", cancellationToken);
        response.EnsureSuccessStatusCode();

        KeysetResponseDto? keyset = await response.Content.ReadFromJsonAsync<KeysetResponseDto>(cancellationToken: cancellationToken);
        return keyset?.Keys ?? [];
    }

    public async Task AckAsync(string jobRunId, CancellationToken cancellationToken)
    {
        string deviceId = ResolveDeviceId();
        await _httpClient.PostAsJsonAsync("device/job-ack", new { job_run_id = jobRunId, device_id = deviceId }, cancellationToken);
    }

    public async Task ResultAsync(string jobRunId, string status, int? exitCode, object? resultPayload, CancellationToken cancellationToken)
    {
        string deviceId = ResolveDeviceId();
        await _httpClient.PostAsJsonAsync("device/job-result", new
        {
            job_run_id = jobRunId,
            device_id = deviceId,
            status,
            exit_code = exitCode,
            result_payload = resultPayload,
        }, cancellationToken);
    }

    private async Task<string> EnsureEnrolledAsync(CancellationToken cancellationToken)
    {
        string current = ResolveDeviceId();
        if (current != EmptyDeviceId)
        {
            return current;
        }

        string? token = ResolveEnrollmentToken();
        if (string.IsNullOrWhiteSpace(token))
        {
            throw new InvalidOperationException("DMS_ENROLLMENT_TOKEN is missing. Agent cannot enroll. Set env var or C:\\ProgramData\\DMS\\enrollment-token.txt");
        }

        var enrollPayload = new
        {
            enrollment_token = token,
            csr_pem = (string?)null,
            device_facts = new
            {
                hostname = Environment.MachineName,
                os_name = RuntimeInformation.OSDescription,
                os_version = Environment.OSVersion.VersionString,
                serial_number = (string?)null,
                agent_version = ResolveAgentVersion(),
                agent_build = ResolveAgentBuild(),
                inventory = await DeviceInventoryCollector.CollectAsync(cancellationToken),
                runtime_diagnostics = CollectRuntimeDiagnostics(),
            }
        };

        HttpResponseMessage enrollResponse = await _httpClient.PostAsJsonAsync("device/enroll", enrollPayload, cancellationToken);
        enrollResponse.EnsureSuccessStatusCode();

        EnrollmentResponseDto? enrolled = await enrollResponse.Content.ReadFromJsonAsync<EnrollmentResponseDto>(cancellationToken: cancellationToken);
        if (enrolled is null || string.IsNullOrWhiteSpace(enrolled.DeviceId))
        {
            throw new InvalidOperationException("Enrollment succeeded but device_id was not returned.");
        }

        PersistDeviceId(enrolled.DeviceId);
        return enrolled.DeviceId;
    }

    private string ResolveDeviceId()
    {
        string? envDeviceId = Environment.GetEnvironmentVariable("DMS_DEVICE_ID");
        if (IsValidNonEmptyGuid(envDeviceId))
        {
            return envDeviceId!;
        }

        if (File.Exists(_deviceIdPath))
        {
            string fileId = File.ReadAllText(_deviceIdPath).Trim();
            if (IsValidNonEmptyGuid(fileId))
            {
                return fileId;
            }
        }

        return EmptyDeviceId;
    }

    private void PersistDeviceId(string deviceId)
    {
        File.WriteAllText(_deviceIdPath, deviceId);
        Environment.SetEnvironmentVariable("DMS_DEVICE_ID", deviceId, EnvironmentVariableTarget.Process);
        try
        {
            Environment.SetEnvironmentVariable("DMS_DEVICE_ID", deviceId, EnvironmentVariableTarget.Machine);
        }
        catch
        {
            // Non-admin context may fail machine env set; file persistence still works.
        }
    }

    private void ClearPersistedDeviceId()
    {
        if (File.Exists(_deviceIdPath))
        {
            File.Delete(_deviceIdPath);
        }

        Environment.SetEnvironmentVariable("DMS_DEVICE_ID", EmptyDeviceId, EnvironmentVariableTarget.Process);
    }

    private static bool IsValidNonEmptyGuid(string? value)
    {
        return Guid.TryParse(value, out Guid parsed) && parsed != Guid.Empty;
    }

    private string ResolveApiBaseUrl()
    {
        string? envUrl = Environment.GetEnvironmentVariable("DMS_API_BASE_URL");
        if (!string.IsNullOrWhiteSpace(envUrl))
        {
            return envUrl;
        }

        if (File.Exists(_apiBaseUrlPath))
        {
            string fileUrl = File.ReadAllText(_apiBaseUrlPath).Trim();
            if (!string.IsNullOrWhiteSpace(fileUrl))
            {
                return fileUrl;
            }
        }

        return "http://localhost/api/v1/";
    }

    private string? ResolveEnrollmentToken()
    {
        string? envToken = Environment.GetEnvironmentVariable("DMS_ENROLLMENT_TOKEN");
        if (!string.IsNullOrWhiteSpace(envToken))
        {
            return envToken;
        }

        if (File.Exists(_enrollmentTokenPath))
        {
            string fileToken = File.ReadAllText(_enrollmentTokenPath).Trim();
            if (!string.IsNullOrWhiteSpace(fileToken))
            {
                return fileToken;
            }
        }

        return null;
    }

    private static string ResolveAgentVersion()
    {
        var entry = Assembly.GetEntryAssembly();
        var informational = entry?
            .GetCustomAttributes<AssemblyInformationalVersionAttribute>()
            .FirstOrDefault()?
            .InformationalVersion;
        if (!string.IsNullOrWhiteSpace(informational))
        {
            string normalized = informational.Split('+', 2)[0].Trim();
            if (!string.IsNullOrWhiteSpace(normalized))
            {
                return normalized;
            }
        }

        try
        {
            string? processPath = Environment.ProcessPath;
            if (!string.IsNullOrWhiteSpace(processPath) && File.Exists(processPath))
            {
                var fvi = FileVersionInfo.GetVersionInfo(processPath);
                if (!string.IsNullOrWhiteSpace(fvi.ProductVersion))
                {
                    return fvi.ProductVersion;
                }
                if (!string.IsNullOrWhiteSpace(fvi.FileVersion))
                {
                    return fvi.FileVersion;
                }
            }
        }
        catch
        {
            // Fall back to assembly metadata below.
        }

        return entry?.GetName().Version?.ToString() ?? "1.0.0";
    }

    private static string ResolveAgentBuild()
    {
        var entry = Assembly.GetEntryAssembly();
        var informational = entry?
            .GetCustomAttributes<AssemblyInformationalVersionAttribute>()
            .FirstOrDefault()?
            .InformationalVersion;
        if (!string.IsNullOrWhiteSpace(informational) && informational.Contains('+'))
        {
            string buildPart = informational.Split('+', 2)[1].Trim();
            if (!string.IsNullOrWhiteSpace(buildPart))
            {
                return buildPart;
            }
        }

        try
        {
            string? processPath = Environment.ProcessPath;
            if (string.IsNullOrWhiteSpace(processPath) || !File.Exists(processPath))
            {
                processPath = Assembly.GetEntryAssembly()?.Location;
            }
            if (string.IsNullOrWhiteSpace(processPath) || !File.Exists(processPath))
            {
                return "unknown";
            }

            byte[] hash = SHA256.HashData(File.ReadAllBytes(processPath));
            return Convert.ToHexString(hash).ToLowerInvariant()[..16];
        }
        catch
        {
            return "unknown";
        }
    }

    private static Dictionary<string, object?> CollectRuntimeDiagnostics()
    {
        static bool ReadBool(string name)
        {
            return string.Equals(Environment.GetEnvironmentVariable(name), "true", StringComparison.OrdinalIgnoreCase);
        }

        return new Dictionary<string, object?>
        {
            ["collected_at"] = DateTimeOffset.UtcNow.ToString("O"),
            ["signature_bypass_enabled"] = ReadBool("DMS_SIGNATURE_BYPASS"),
            ["signature_debug_enabled"] = ReadBool("DMS_SIGNATURE_DEBUG"),
            ["process_id"] = Environment.ProcessId,
            ["process_path"] = Environment.ProcessPath,
            ["machine_name"] = Environment.MachineName,
        };
    }
}
