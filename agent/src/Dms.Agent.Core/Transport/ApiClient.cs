using System.Net.Http.Json;
using System.Diagnostics;
using System.Linq;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Net;
using System.Text.RegularExpressions;
using System.Text.Json;
using Dms.Agent.Core.Protocol;
using Dms.Agent.Core.Runtime;
using Dms.Agent.Core.Telemetry;

namespace Dms.Agent.Core.Transport;

public sealed class ApiClient
{
    private readonly HttpClient _httpClient;
    private readonly AgentBootstrapConfiguration _bootstrapConfiguration;
    private readonly string _deviceIdPath;
    private readonly string _enrollmentTokenPath;
    private readonly string _behaviorIngestTokenPath;
    private string? _behaviorIngestToken;
    private string? _lastCheckinId;
    private const string EmptyDeviceId = "00000000-0000-0000-0000-000000000000";
    private static readonly HttpStatusCode[] RetryableStatusCodes =
    [
        HttpStatusCode.RequestTimeout,
        HttpStatusCode.TooManyRequests,
        HttpStatusCode.BadGateway,
        HttpStatusCode.ServiceUnavailable,
        HttpStatusCode.GatewayTimeout,
    ];

    public ApiClient()
    {
        _bootstrapConfiguration = AgentBootstrapConfiguration.Load();
        Directory.CreateDirectory(_bootstrapConfiguration.DmsDirectory);

        _deviceIdPath = _bootstrapConfiguration.DeviceIdPath;
        _enrollmentTokenPath = _bootstrapConfiguration.EnrollmentTokenPath;
        _behaviorIngestTokenPath = Path.Combine(_bootstrapConfiguration.DmsDirectory, "behavior-ingest-token.txt");
        _behaviorIngestToken = ResolveBehaviorIngestToken();

        _httpClient = CreateHttpClient(_bootstrapConfiguration.ResolvedApiBaseUrl);
        _bootstrapConfiguration.WriteBootstrapState();
    }

    public async Task<CheckinResponseDto> CheckinAsync(CancellationToken cancellationToken)
    {
        string deviceId = await EnsureEnrolledAsync(cancellationToken);
        string checkinId = Guid.NewGuid().ToString("D");
        _lastCheckinId = checkinId;
        string agentVersion = ResolveAgentVersion();
        string agentBuild = ResolveAgentBuild();
        var inventory = await DeviceInventoryCollector.CollectAsync(cancellationToken);
        string? serialNumber = ResolveSerialNumber(inventory);
        var runtimeDiagnostics = CollectRuntimeDiagnostics();
        HttpResponseMessage response = await SendWithRetryAsync(ct => _httpClient.PostAsJsonAsync("device/checkin", new
        {
            device_id = deviceId,
            agent_version = agentVersion,
            agent_build = agentBuild,
            hostname = Environment.MachineName,
            os_name = RuntimeInformation.OSDescription,
            os_version = Environment.OSVersion.VersionString,
            serial_number = serialNumber,
            checkin_id = checkinId,
            inventory,
            runtime_diagnostics = runtimeDiagnostics,
            uwf_status = BuildUwfStatus(runtimeDiagnostics),
        }, ct), cancellationToken);

        if (response.StatusCode == System.Net.HttpStatusCode.NotFound && !string.IsNullOrWhiteSpace(ResolveEnrollmentToken()))
        {
            response.Dispose();
            ClearPersistedDeviceId();
            deviceId = await EnsureEnrolledAsync(cancellationToken);
            runtimeDiagnostics = CollectRuntimeDiagnostics();
            response = await SendWithRetryAsync(ct => _httpClient.PostAsJsonAsync("device/checkin", new
            {
                device_id = deviceId,
                agent_version = agentVersion,
                agent_build = agentBuild,
                hostname = Environment.MachineName,
                os_name = RuntimeInformation.OSDescription,
                os_version = Environment.OSVersion.VersionString,
                serial_number = serialNumber,
                checkin_id = checkinId,
                inventory,
                runtime_diagnostics = runtimeDiagnostics,
                uwf_status = BuildUwfStatus(runtimeDiagnostics),
            }, ct), cancellationToken);
        }

        response.EnsureSuccessStatusCode();

        CheckinResponseDto? payload = await response.Content.ReadFromJsonAsync<CheckinResponseDto>(cancellationToken: cancellationToken);
        _bootstrapConfiguration.PersistCheckinInterval(payload?.Bootstrap?.CheckinIntervalSeconds);
        PersistBehaviorIngestToken(payload?.Bootstrap?.BehaviorIngestToken);
        return payload ?? new CheckinResponseDto();
    }

    public async Task<List<KeysetKeyDto>> GetKeysetAsync(CancellationToken cancellationToken)
    {
        HttpResponseMessage response = await SendWithRetryAsync(ct => _httpClient.GetAsync("device/keyset", ct), cancellationToken);
        response.EnsureSuccessStatusCode();

        KeysetResponseDto? keyset = await response.Content.ReadFromJsonAsync<KeysetResponseDto>(cancellationToken: cancellationToken);
        return keyset?.Keys ?? [];
    }

    public async Task AckAsync(string jobRunId, CancellationToken cancellationToken)
    {
        string deviceId = ResolveDeviceId();
        using HttpResponseMessage response = await SendWithRetryAsync(
            ct => _httpClient.PostAsJsonAsync("device/job-ack", new { job_run_id = jobRunId, device_id = deviceId }, ct),
            cancellationToken);
        response.EnsureSuccessStatusCode();
    }

    public async Task ResultAsync(
        string jobRunId,
        string status,
        int? exitCode,
        object? resultPayload,
        Dictionary<string, object?>? resultMeta,
        CancellationToken cancellationToken)
    {
        string deviceId = ResolveDeviceId();
        using HttpResponseMessage response = await SendWithRetryAsync(ct => _httpClient.PostAsJsonAsync("device/job-result", new
        {
            job_run_id = jobRunId,
            device_id = deviceId,
            status,
            exit_code = exitCode,
            result_payload = resultPayload,
            execution_duration_ms = resultMeta?.GetValueOrDefault("execution_duration_ms"),
            stdout_sha256 = resultMeta?.GetValueOrDefault("stdout_sha256"),
            stderr_sha256 = resultMeta?.GetValueOrDefault("stderr_sha256"),
            rollback_hint = resultMeta?.GetValueOrDefault("rollback_hint"),
            artifacts = resultMeta?.GetValueOrDefault("artifacts"),
            action_token = resultMeta?.GetValueOrDefault("action_token"),
            idempotency_key = resultMeta?.GetValueOrDefault("idempotency_key"),
            checkin_id = _lastCheckinId,
        }, ct), cancellationToken);
        response.EnsureSuccessStatusCode();
    }

    public async Task PostBehaviorEventsAsync(IReadOnlyList<BehaviorEventDto> events, CancellationToken cancellationToken)
    {
        if (events.Count == 0)
        {
            return;
        }

        string deviceId = await EnsureEnrolledAsync(cancellationToken);
        string? checkinId = _lastCheckinId;
        var payload = new
        {
            device_id = deviceId,
            checkin_id = checkinId,
            events = events.Select(e => new
            {
                event_type = e.EventType,
                occurred_at = e.OccurredAt.ToUniversalTime().ToString("O"),
                user_name = e.UserName,
                process_name = e.ProcessName,
                file_path = e.FilePath,
                event_uid = e.EventUid,
                session_uid = e.SessionUid,
                process_uid = e.ProcessUid,
                parent_process_uid = e.ParentProcessUid,
                checkin_id = e.CheckinId ?? checkinId,
                metadata = e.Metadata,
            }),
        };

        string token = ResolveBehaviorIngestToken();
        HttpResponseMessage response = await SendWithRetryAsync(ct =>
        {
            HttpRequestMessage request = new(HttpMethod.Post, "device/behavior-log")
            {
                Content = JsonContent.Create(payload),
            };
            if (!string.IsNullOrWhiteSpace(token))
            {
                request.Headers.TryAddWithoutValidation("X-DMS-Behavior-Token", token);
            }

            return _httpClient.SendAsync(request, ct);
        }, cancellationToken);

        response.EnsureSuccessStatusCode();
    }

    public async Task PostRemoteSupportLiveFrameAsync(
        string sessionId,
        string imageBase64,
        string mimeType,
        int byteSize,
        DateTimeOffset capturedAtUtc,
        CancellationToken cancellationToken)
    {
        string deviceId = ResolveDeviceId();
        if (deviceId == EmptyDeviceId)
        {
            deviceId = await EnsureEnrolledAsync(cancellationToken);
        }

        string token = ResolveBehaviorIngestToken();
        HttpResponseMessage response = await SendWithRetryAsync(ct =>
        {
            HttpRequestMessage request = new(HttpMethod.Post, "device/remote-support/live-frame")
            {
                Content = JsonContent.Create(new
                {
                    device_id = deviceId,
                    checkin_id = _lastCheckinId,
                    session_id = sessionId,
                    captured_at_utc = capturedAtUtc.ToUniversalTime().ToString("O"),
                    mime_type = mimeType,
                    image_base64 = imageBase64,
                    byte_size = byteSize,
                }),
            };
            if (!string.IsNullOrWhiteSpace(token))
            {
                request.Headers.TryAddWithoutValidation("X-DMS-Behavior-Token", token);
            }

            return _httpClient.SendAsync(request, ct);
        }, cancellationToken);
        response.EnsureSuccessStatusCode();
    }

    public async Task PostRemoteSupportWebRtcSignalAsync(
        string sessionId,
        string type,
        Dictionary<string, object?> payload,
        CancellationToken cancellationToken)
    {
        string deviceId = ResolveDeviceId();
        if (deviceId == EmptyDeviceId)
        {
            deviceId = await EnsureEnrolledAsync(cancellationToken);
        }

        string token = ResolveBehaviorIngestToken();
        HttpResponseMessage response = await SendWithRetryAsync(ct =>
        {
            HttpRequestMessage request = new(HttpMethod.Post, "device/remote-support/webrtc/signal")
            {
                Content = JsonContent.Create(new
                {
                    device_id = deviceId,
                    checkin_id = _lastCheckinId,
                    session_id = sessionId,
                    type,
                    payload,
                }),
            };
            if (!string.IsNullOrWhiteSpace(token))
            {
                request.Headers.TryAddWithoutValidation("X-DMS-Behavior-Token", token);
            }

            return _httpClient.SendAsync(request, ct);
        }, cancellationToken);
        response.EnsureSuccessStatusCode();
    }

    public async Task<RemoteSupportRealtimePollResponseDto> GetRemoteSupportWebRtcSignalsAsync(
        string sessionId,
        long since,
        CancellationToken cancellationToken)
    {
        return await GetRemoteSupportRealtimePollAsync("device/remote-support/webrtc/signals", sessionId, since, cancellationToken);
    }

    public async Task<RemoteSupportRealtimePollResponseDto> GetRemoteSupportWebRtcInputsAsync(
        string sessionId,
        long since,
        CancellationToken cancellationToken)
    {
        return await GetRemoteSupportRealtimePollAsync("device/remote-support/webrtc/inputs", sessionId, since, cancellationToken);
    }

    private async Task<RemoteSupportRealtimePollResponseDto> GetRemoteSupportRealtimePollAsync(
        string endpoint,
        string sessionId,
        long since,
        CancellationToken cancellationToken)
    {
        string deviceId = ResolveDeviceId();
        if (deviceId == EmptyDeviceId)
        {
            deviceId = await EnsureEnrolledAsync(cancellationToken);
        }

        string checkinId = _lastCheckinId ?? string.Empty;
        string path = endpoint
            + "?device_id=" + Uri.EscapeDataString(deviceId)
            + "&session_id=" + Uri.EscapeDataString(sessionId)
            + "&since=" + since.ToString(System.Globalization.CultureInfo.InvariantCulture)
            + "&checkin_id=" + Uri.EscapeDataString(checkinId);

        string token = ResolveBehaviorIngestToken();
        HttpResponseMessage response = await SendWithRetryAsync(ct =>
        {
            HttpRequestMessage request = new(HttpMethod.Get, path);
            if (!string.IsNullOrWhiteSpace(token))
            {
                request.Headers.TryAddWithoutValidation("X-DMS-Behavior-Token", token);
            }

            return _httpClient.SendAsync(request, ct);
        }, cancellationToken);
        response.EnsureSuccessStatusCode();

        RemoteSupportRealtimePollResponseDto? payload = await response.Content.ReadFromJsonAsync<RemoteSupportRealtimePollResponseDto>(cancellationToken: cancellationToken);
        return payload ?? new RemoteSupportRealtimePollResponseDto();
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

        var runtimeDiagnostics = CollectRuntimeDiagnostics();
        var inventory = await DeviceInventoryCollector.CollectAsync(cancellationToken);
        string? serialNumber = ResolveSerialNumber(inventory);
        var enrollPayload = new
        {
            enrollment_token = token,
            csr_pem = (string?)null,
            device_facts = new
            {
                hostname = Environment.MachineName,
                os_name = RuntimeInformation.OSDescription,
                os_version = Environment.OSVersion.VersionString,
                serial_number = serialNumber,
                agent_version = ResolveAgentVersion(),
                agent_build = ResolveAgentBuild(),
                inventory,
                runtime_diagnostics = runtimeDiagnostics,
                uwf_status = BuildUwfStatus(runtimeDiagnostics),
            }
        };

        HttpResponseMessage enrollResponse = await SendWithRetryAsync(
            ct => _httpClient.PostAsJsonAsync("device/enroll", enrollPayload, ct),
            cancellationToken);
        if (!enrollResponse.IsSuccessStatusCode)
        {
            string responseBody = await SafeReadResponseContentAsync(enrollResponse, cancellationToken);
            _bootstrapConfiguration.WriteEnrollmentError(string.Join(Environment.NewLine, new[]
            {
                $"utc={DateTimeOffset.UtcNow:O}",
                $"api_base_url={_bootstrapConfiguration.ResolvedApiBaseUrl}",
                $"api_base_url_source={_bootstrapConfiguration.ApiBaseUrlSource}",
                $"enrollment_token_source={_bootstrapConfiguration.EnrollmentTokenSource}",
                $"status_code={(int) enrollResponse.StatusCode} {enrollResponse.StatusCode}",
                $"response_body={responseBody}",
            }));
            enrollResponse.EnsureSuccessStatusCode();
        }

        EnrollmentResponseDto? enrolled = await enrollResponse.Content.ReadFromJsonAsync<EnrollmentResponseDto>(cancellationToken: cancellationToken);
        if (enrolled is null || string.IsNullOrWhiteSpace(enrolled.DeviceId))
        {
            throw new InvalidOperationException("Enrollment succeeded but device_id was not returned.");
        }

        PersistDeviceId(enrolled.DeviceId);
        _bootstrapConfiguration.PersistCheckinInterval(enrolled.Bootstrap?.CheckinIntervalSeconds);
        PersistBehaviorIngestToken(enrolled.Bootstrap?.BehaviorIngestToken);
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

    private static string? ResolveSerialNumber(IReadOnlyDictionary<string, object?> inventory)
    {
        if (TryReadNestedString(inventory, out string? serial, "windows_telemetry", "basic_device_identity", "serial_number")
            && !string.IsNullOrWhiteSpace(serial))
        {
            return serial;
        }

        if (TryReadNestedString(inventory, out serial, "basic_device_identity", "serial_number")
            && !string.IsNullOrWhiteSpace(serial))
        {
            return serial;
        }

        return null;
    }

    private static bool TryReadNestedString(
        IReadOnlyDictionary<string, object?> root,
        out string? value,
        params string[] path)
    {
        value = null;
        if (path.Length == 0)
        {
            return false;
        }

        object? current = root;
        foreach (string segment in path)
        {
            if (current is not IReadOnlyDictionary<string, object?> readOnlyNode)
            {
                if (current is Dictionary<string, object?> mutableNode)
                {
                    if (!mutableNode.TryGetValue(segment, out current))
                    {
                        return false;
                    }
                    continue;
                }

                return false;
            }

            if (!readOnlyNode.TryGetValue(segment, out current))
            {
                return false;
            }
        }

        if (current is string s)
        {
            value = s.Trim();
            return true;
        }

        return false;
    }

    private string? ResolveEnrollmentToken()
    {
        if (!string.IsNullOrWhiteSpace(_bootstrapConfiguration.EnrollmentToken))
        {
            return _bootstrapConfiguration.EnrollmentToken;
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

    private string ResolveBehaviorIngestToken()
    {
        if (!string.IsNullOrWhiteSpace(_behaviorIngestToken))
        {
            return _behaviorIngestToken;
        }

        string? envToken = Environment.GetEnvironmentVariable("DMS_BEHAVIOR_INGEST_TOKEN");
        if (!string.IsNullOrWhiteSpace(envToken))
        {
            _behaviorIngestToken = envToken.Trim();
            return _behaviorIngestToken;
        }

        if (File.Exists(_behaviorIngestTokenPath))
        {
            string fileToken = File.ReadAllText(_behaviorIngestTokenPath).Trim();
            if (!string.IsNullOrWhiteSpace(fileToken))
            {
                _behaviorIngestToken = fileToken;
                return _behaviorIngestToken;
            }
        }

        return string.Empty;
    }

    private void PersistBehaviorIngestToken(string? token)
    {
        if (string.IsNullOrWhiteSpace(token))
        {
            return;
        }

        string normalized = token.Trim();
        _behaviorIngestToken = normalized;
        Directory.CreateDirectory(_bootstrapConfiguration.DmsDirectory);
        File.WriteAllText(_behaviorIngestTokenPath, normalized);
    }

    private static HttpClient CreateHttpClient(string baseAddress)
    {
        SocketsHttpHandler handler = new()
        {
            AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate,
            ConnectTimeout = TimeSpan.FromSeconds(10),
            PooledConnectionLifetime = TimeSpan.FromMinutes(10),
            PooledConnectionIdleTimeout = TimeSpan.FromMinutes(2),
            UseCookies = false,
        };

        return new HttpClient(handler)
        {
            BaseAddress = new Uri(baseAddress),
            Timeout = TimeSpan.FromSeconds(30),
        };
    }

    private static async Task<HttpResponseMessage> SendWithRetryAsync(
        Func<CancellationToken, Task<HttpResponseMessage>> send,
        CancellationToken cancellationToken,
        int maxAttempts = 3)
    {
        HttpResponseMessage? lastResponse = null;
        Exception? lastException = null;

        for (int attempt = 1; attempt <= Math.Max(1, maxAttempts); attempt++)
        {
            cancellationToken.ThrowIfCancellationRequested();

            try
            {
                HttpResponseMessage response = await send(cancellationToken);
                if (!ShouldRetry(response.StatusCode) || attempt >= maxAttempts)
                {
                    return response;
                }

                lastResponse?.Dispose();
                lastResponse = response;
            }
            catch (Exception ex) when (IsTransientException(ex, cancellationToken) && attempt < maxAttempts)
            {
                lastException = ex;
            }

            if (attempt < maxAttempts)
            {
                await Task.Delay(ResolveRetryDelay(attempt), cancellationToken);
            }
        }

        if (lastResponse is not null)
        {
            return lastResponse;
        }

        throw lastException ?? new HttpRequestException("HTTP operation failed after retries.");
    }

    private static bool ShouldRetry(HttpStatusCode statusCode)
    {
        if ((int)statusCode >= 500)
        {
            return true;
        }

        return RetryableStatusCodes.Contains(statusCode);
    }

    private static bool IsTransientException(Exception exception, CancellationToken cancellationToken)
    {
        return exception switch
        {
            HttpRequestException => true,
            TaskCanceledException when !cancellationToken.IsCancellationRequested => true,
            OperationCanceledException when !cancellationToken.IsCancellationRequested => true,
            _ => false,
        };
    }

    private static TimeSpan ResolveRetryDelay(int attempt)
    {
        int seconds = Math.Min(8, 1 << Math.Max(0, attempt - 1));
        return TimeSpan.FromSeconds(seconds);
    }

    private static async Task<string> SafeReadResponseContentAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        try
        {
            string body = await response.Content.ReadAsStringAsync(cancellationToken);
            if (string.IsNullOrWhiteSpace(body))
            {
                return "<empty>";
            }

            string singleLine = body.Replace(Environment.NewLine, " ").Replace('\n', ' ').Replace('\r', ' ').Trim();
            return singleLine.Length <= 1000 ? singleLine : singleLine[..1000];
        }
        catch (Exception ex)
        {
            return $"<unavailable: {ex.Message}>";
        }
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
        static (int ExitCode, string StdOut, string StdErr) RunProcess(string fileName, string arguments, int timeoutMs = 12000)
        {
            try
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

                if (!process.Start())
                {
                    return (-1, string.Empty, "failed to start process");
                }

                if (!process.WaitForExit(timeoutMs))
                {
                    try
                    {
                        process.Kill(entireProcessTree: true);
                    }
                    catch
                    {
                        // Ignore kill failures.
                    }

                    string timeoutStdOut = process.StandardOutput.ReadToEnd();
                    string timeoutStdErr = process.StandardError.ReadToEnd();
                    return (-1, timeoutStdOut, string.IsNullOrWhiteSpace(timeoutStdErr) ? "timeout" : timeoutStdErr);
                }

                string stdOut = process.StandardOutput.ReadToEnd();
                string stdErr = process.StandardError.ReadToEnd();
                return (process.ExitCode, stdOut, stdErr);
            }
            catch (Exception ex)
            {
                return (-1, string.Empty, ex.Message);
            }
        }
        static Dictionary<string, object?> ReadUwfDiagnostics()
        {
            var result = new Dictionary<string, object?>
            {
                ["uwf_supported"] = RuntimeInformation.IsOSPlatform(OSPlatform.Windows),
                ["uwf_feature_enabled"] = false,
                ["uwf_tool_available"] = false,
                ["uwf_filter_enabled"] = null,
                ["uwf_filter_next_enabled"] = null,
                ["uwf_volume_c_protected"] = null,
                ["uwf_volume_c_next_protected"] = null,
            };

            if (!RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
            {
                return result;
            }

            string windowsDir = Environment.GetEnvironmentVariable("WINDIR") ?? @"C:\Windows";
            string uwfPath = Path.Combine(windowsDir, "System32", "uwfmgr.exe");
            bool toolAvailable = File.Exists(uwfPath);
            result["uwf_tool_available"] = toolAvailable;
            var feature = RunProcess("dism.exe", "/online /Get-FeatureInfo /FeatureName:Client-UnifiedWriteFilter");
            string featureText = (feature.StdOut + Environment.NewLine + feature.StdErr).Trim();
            bool featureEnabled = featureText.IndexOf("State : Enabled", StringComparison.OrdinalIgnoreCase) >= 0;
            result["uwf_feature_enabled"] = featureEnabled;
            result["uwf_feature_state"] = featureEnabled
                ? "enabled"
                : (featureText.IndexOf("State : Disabled", StringComparison.OrdinalIgnoreCase) >= 0 ? "disabled" : "unknown");

            if (!toolAvailable)
            {
                result["uwf_last_check_error"] = "uwfmgr.exe not found";
                return result;
            }

            // Prefer scoped commands. On some builds `get-config` can intermittently time out.
            // We probe multiple filter commands and parse the first trustworthy state.
            var filterCfg = RunProcess(uwfPath, "filter get-config", 12000);
            if (filterCfg.ExitCode != 0)
            {
                var legacyCfg = RunProcess(uwfPath, "get-config", 12000);
                if (legacyCfg.ExitCode == 0 || !string.IsNullOrWhiteSpace(legacyCfg.StdOut))
                {
                    filterCfg = legacyCfg;
                }
            }
            var filterCurrentProbe = RunProcess(uwfPath, "filter get-current-session", 8000);
            var filterNextProbe = RunProcess(uwfPath, "filter get-next-session", 8000);
            var vol = RunProcess(uwfPath, "volume get-config C:", 25000);
            string cfgText = (filterCfg.StdOut + Environment.NewLine + filterCfg.StdErr).Trim();
            string filterCurrentText = (filterCurrentProbe.StdOut + Environment.NewLine + filterCurrentProbe.StdErr).Trim();
            string filterNextText = (filterNextProbe.StdOut + Environment.NewLine + filterNextProbe.StdErr).Trim();
            string volText = (vol.StdOut + Environment.NewLine + vol.StdErr).Trim();

            static bool? ParseSessionState(string source, string sessionKind, bool? fallback = null)
            {
                if (string.IsNullOrWhiteSpace(source))
                {
                    return fallback;
                }

                string pattern = sessionKind.Equals("next", StringComparison.OrdinalIgnoreCase)
                    ? @"next\s*session\s*[:=]\s*(on|off|enabled|disabled|protected|unprotected)"
                    : @"current\s*session\s*[:=]\s*(on|off|enabled|disabled|protected|unprotected)";
                var match = Regex.Match(source, pattern, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
                if (!match.Success)
                {
                    return fallback;
                }

                string token = match.Groups[1].Value.Trim().ToLowerInvariant();
                return token is "on" or "enabled" or "protected";
            }

            static bool? ParseNamedState(string source, params string[] labels)
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

            static bool? ParseAnyBooleanToken(string source)
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

            bool? filterCurrent = ParseNamedState(cfgText, "Filter state", "Filter", "Filter Enabled")
                ?? ParseSessionState(cfgText, "current", null)
                ?? ParseNamedState(filterCurrentText, "Current Session", "Current state", "Filter state")
                ?? ParseAnyBooleanToken(filterCurrentText);
            bool? filterNext = ParseNamedState(cfgText, "Next Session", "Next Filter state", "Filter state next")
                ?? ParseSessionState(cfgText, "next", null)
                ?? ParseNamedState(filterNextText, "Next Session", "Next state", "Filter state")
                ?? ParseAnyBooleanToken(filterNextText);

            bool? volumeCurrent = ParseNamedState(volText, "Current Session", "Current state", "Current")
                ?? ParseSessionState(volText, "current", null);
            bool? volumeNext = ParseNamedState(volText, "Next Session", "Next state", "Next")
                ?? ParseSessionState(volText, "next", null);

            result["uwf_filter_enabled"] = filterCurrent;
            result["uwf_filter_next_enabled"] = filterNext;
            result["uwf_volume_c_protected"] = volumeCurrent;
            result["uwf_volume_c_next_protected"] = volumeNext;
            int cfgExitCode = filterCfg.ExitCode == 0
                ? 0
                : (filterCurrentProbe.ExitCode == 0 || filterNextProbe.ExitCode == 0 ? 0 : filterCfg.ExitCode);
            result["uwf_get_config_exit_code"] = cfgExitCode;
            result["uwf_volume_c_exit_code"] = vol.ExitCode;

            if (cfgExitCode != 0 || vol.ExitCode != 0)
            {
                string error = string.Join(" | ", new[]
                {
                    (cfgExitCode != 0 && string.IsNullOrWhiteSpace(filterCfg.StdErr))
                        ? "cfg: unknown error"
                        : (cfgExitCode != 0 && !string.IsNullOrWhiteSpace(filterCfg.StdErr) ? $"cfg: {filterCfg.StdErr.Trim()}" : null),
                    string.IsNullOrWhiteSpace(vol.StdErr) ? null : $"vol: {vol.StdErr.Trim()}",
                }.Where(x => !string.IsNullOrWhiteSpace(x)));
                if (!string.IsNullOrWhiteSpace(error))
                {
                    result["uwf_last_check_error"] = error;
                }
            }

            // If we resolved filter state through probe commands, don't retain cfg timeout noise.
            if (result.ContainsKey("uwf_last_check_error")
                && filterCurrent is not null
                && filterNext is not null
                && vol.ExitCode == 0
                && ((string?)result["uwf_last_check_error"])?.Contains("cfg: timeout", StringComparison.OrdinalIgnoreCase) == true)
            {
                result.Remove("uwf_last_check_error");
            }

            return result;
        }
        static Dictionary<string, object?> ReadRestoreDiagnostics()
        {
            string programData = Environment.GetEnvironmentVariable("ProgramData")
                ?? Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData)
                ?? @"C:\ProgramData";
            string restoreRoot = Path.Combine(programData, "DMS", "Restore");
            string pendingPath = Path.Combine(restoreRoot, "pending-restore.json");
            string lastApplyPath = Path.Combine(restoreRoot, "last-apply.json");

            string? status = null;
            string? updatedAt = null;
            try
            {
                if (File.Exists(lastApplyPath))
                {
                    using var doc = JsonDocument.Parse(File.ReadAllText(lastApplyPath));
                    if (doc.RootElement.TryGetProperty("status", out var statusNode))
                    {
                        status = statusNode.GetString();
                    }
                    if (doc.RootElement.TryGetProperty("updated_at", out var updatedAtNode))
                    {
                        updatedAt = updatedAtNode.GetString();
                    }
                }
            }
            catch
            {
                status = "invalid";
            }

            return new Dictionary<string, object?>
            {
                ["restore_startup_apply_enabled"] = !string.Equals(Environment.GetEnvironmentVariable("DMS_RESTORE_STARTUP_APPLY"), "false", StringComparison.OrdinalIgnoreCase),
                ["restore_manifest_pending"] = File.Exists(pendingPath),
                ["restore_last_apply_status"] = status,
                ["restore_last_apply_updated_at"] = updatedAt,
            };
        }

        var diagnostics = new Dictionary<string, object?>
        {
            ["collected_at"] = DateTimeOffset.UtcNow.ToString("O"),
            ["signature_bypass_enabled"] = ReadBool("DMS_SIGNATURE_BYPASS"),
            ["signature_debug_enabled"] = ReadBool("DMS_SIGNATURE_DEBUG"),
            ["webrtc_media_pipeline"] = WebRtcMediaPipelineCapability.IsAdvertisedEnabled(),
            ["webrtc_media_pipeline_build"] = WebRtcMediaPipelineCapability.IsBuildImplemented(),
            ["webrtc_media_pipeline_configured"] = WebRtcMediaPipelineCapability.IsConfiguredEnabled(),
            ["webrtc_signaling_enabled"] = true,
            ["process_id"] = Environment.ProcessId,
            ["process_path"] = Environment.ProcessPath,
            ["machine_name"] = Environment.MachineName,
        };
        foreach (var item in ReadRestoreDiagnostics())
        {
            diagnostics[item.Key] = item.Value;
        }
        foreach (var item in ReadUwfDiagnostics())
        {
            diagnostics[item.Key] = item.Value;
        }

        return diagnostics;
    }

    private static Dictionary<string, object?> BuildUwfStatus(IReadOnlyDictionary<string, object?> runtimeDiagnostics)
    {
        object? Get(string key) => runtimeDiagnostics.TryGetValue(key, out var value) ? value : null;

        return new Dictionary<string, object?>
        {
            ["feature_enabled"] = Get("uwf_feature_enabled"),
            ["feature_state"] = Get("uwf_feature_state"),
            ["filter_enabled"] = Get("uwf_filter_enabled"),
            ["filter_next_enabled"] = Get("uwf_filter_next_enabled"),
            ["volume_c_protected"] = Get("uwf_volume_c_protected"),
            ["volume_c_next_protected"] = Get("uwf_volume_c_next_protected"),
            ["supported"] = Get("uwf_supported"),
            ["tool_available"] = Get("uwf_tool_available"),
            ["last_check_error"] = Get("uwf_last_check_error"),
            ["get_config_exit_code"] = Get("uwf_get_config_exit_code"),
            ["volume_c_exit_code"] = Get("uwf_volume_c_exit_code"),
            ["collected_at"] = Get("collected_at"),
        };
    }
}
