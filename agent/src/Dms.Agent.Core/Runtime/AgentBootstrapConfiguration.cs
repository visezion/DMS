using System.Text.Json;

namespace Dms.Agent.Core.Runtime;

public sealed class AgentBootstrapConfiguration
{
    private const string DefaultApiBaseUrl = "http://localhost/api/v1/";
    private const int FallbackCheckinIntervalSeconds = 60;
    private const int MinCheckinIntervalSeconds = 1;
    private const int MaxCheckinIntervalSeconds = 300;

    private AgentBootstrapConfiguration(
        string programDataRoot,
        string baseDirectory,
        string apiBaseUrl,
        string apiBaseUrlSource,
        string? enrollmentToken,
        string enrollmentTokenSource,
        int checkinIntervalSeconds,
        string checkinIntervalSource)
    {
        ProgramDataRoot = programDataRoot;
        BaseDirectory = baseDirectory;
        DmsDirectory = Path.Combine(programDataRoot, "DMS");
        DiagnosticsDirectory = Path.Combine(DmsDirectory, "Diagnostics");
        ApiBaseUrlPath = Path.Combine(DmsDirectory, "api-base-url.txt");
        EnrollmentTokenPath = Path.Combine(DmsDirectory, "enrollment-token.txt");
        DeviceIdPath = Path.Combine(DmsDirectory, "device-id.txt");
        CheckinIntervalPath = Path.Combine(DmsDirectory, "checkin-interval-seconds.txt");
        BootstrapStatePath = Path.Combine(DiagnosticsDirectory, "bootstrap-state.json");
        EnrollmentErrorPath = Path.Combine(DiagnosticsDirectory, "last-enrollment-error.txt");
        ResolvedApiBaseUrl = apiBaseUrl;
        ApiBaseUrlSource = apiBaseUrlSource;
        EnrollmentToken = enrollmentToken;
        EnrollmentTokenSource = enrollmentTokenSource;
        CheckinIntervalSeconds = checkinIntervalSeconds;
        CheckinIntervalSource = checkinIntervalSource;
    }

    public string ProgramDataRoot { get; }
    public string BaseDirectory { get; }
    public string DmsDirectory { get; }
    public string DiagnosticsDirectory { get; }
    public string ApiBaseUrlPath { get; }
    public string EnrollmentTokenPath { get; }
    public string DeviceIdPath { get; }
    public string CheckinIntervalPath { get; }
    public string BootstrapStatePath { get; }
    public string EnrollmentErrorPath { get; }
    public string ResolvedApiBaseUrl { get; }
    public string ApiBaseUrlSource { get; }
    public string? EnrollmentToken { get; }
    public string EnrollmentTokenSource { get; }
    public int CheckinIntervalSeconds { get; }
    public string CheckinIntervalSource { get; }

    public static AgentBootstrapConfiguration Load(string? programDataRoot = null, string? baseDirectory = null)
    {
        string resolvedProgramData = programDataRoot
            ?? Environment.GetEnvironmentVariable("ProgramData")
            ?? @"C:\ProgramData";
        string resolvedBaseDirectory = string.IsNullOrWhiteSpace(baseDirectory)
            ? AppContext.BaseDirectory
            : baseDirectory;

        Directory.CreateDirectory(Path.Combine(resolvedProgramData, "DMS"));
        Directory.CreateDirectory(Path.Combine(resolvedProgramData, "DMS", "Diagnostics"));

        string dmsDirectory = Path.Combine(resolvedProgramData, "DMS");
        string apiBaseUrlPath = Path.Combine(dmsDirectory, "api-base-url.txt");
        string enrollmentTokenPath = Path.Combine(dmsDirectory, "enrollment-token.txt");
        string checkinIntervalPath = Path.Combine(dmsDirectory, "checkin-interval-seconds.txt");
        JsonElement? appSettings = LoadAppSettings(resolvedBaseDirectory);

        (string apiBaseUrl, string apiSource) = ResolveApiBaseUrl(apiBaseUrlPath, appSettings);
        (string? enrollmentToken, string enrollmentTokenSource) = ResolveEnrollmentToken(enrollmentTokenPath, appSettings);
        (int checkinIntervalSeconds, string checkinIntervalSource) = ResolveCheckinIntervalSeconds(checkinIntervalPath, appSettings);

        return new AgentBootstrapConfiguration(
            resolvedProgramData,
            resolvedBaseDirectory,
            apiBaseUrl,
            apiSource,
            enrollmentToken,
            enrollmentTokenSource,
            checkinIntervalSeconds,
            checkinIntervalSource);
    }

    public void PersistCheckinInterval(int? seconds)
    {
        if (!seconds.HasValue)
        {
            return;
        }

        int clamped = Math.Clamp(seconds.Value, MinCheckinIntervalSeconds, MaxCheckinIntervalSeconds);
        WriteAtomically(CheckinIntervalPath, clamped.ToString());
    }

    public void WriteBootstrapState()
    {
        var payload = new Dictionary<string, object?>
        {
            ["captured_at_utc"] = DateTimeOffset.UtcNow.ToString("O"),
            ["api_base_url"] = ResolvedApiBaseUrl,
            ["api_base_url_source"] = ApiBaseUrlSource,
            ["enrollment_token_present"] = !string.IsNullOrWhiteSpace(EnrollmentToken),
            ["enrollment_token_source"] = EnrollmentTokenSource,
            ["checkin_interval_seconds"] = CheckinIntervalSeconds,
            ["checkin_interval_source"] = CheckinIntervalSource,
            ["device_id_path"] = DeviceIdPath,
        };

        WriteAtomically(BootstrapStatePath, JsonSerializer.Serialize(payload));
    }

    public void WriteEnrollmentError(string message)
    {
        Directory.CreateDirectory(DiagnosticsDirectory);
        WriteAtomically(EnrollmentErrorPath, message);
    }

    private static (string Value, string Source) ResolveApiBaseUrl(string filePath, JsonElement? appSettings)
    {
        string? envUrl = Environment.GetEnvironmentVariable("DMS_API_BASE_URL");
        if (!string.IsNullOrWhiteSpace(envUrl))
        {
            return (NormalizeApiBaseUrl(envUrl), "env");
        }

        if (TryReadFileValue(filePath, out string? fileUrl))
        {
            return (NormalizeApiBaseUrl(fileUrl!), "file");
        }

        if (TryReadAppSettingString(appSettings, out string? appUrl, "Dms", "ApiBaseUrl"))
        {
            return (NormalizeApiBaseUrl(appUrl!), "appsettings");
        }

        return (NormalizeApiBaseUrl(DefaultApiBaseUrl), "fallback");
    }

    private static (string? Value, string Source) ResolveEnrollmentToken(string filePath, JsonElement? appSettings)
    {
        string? envToken = Environment.GetEnvironmentVariable("DMS_ENROLLMENT_TOKEN");
        if (!string.IsNullOrWhiteSpace(envToken))
        {
            return (envToken.Trim(), "env");
        }

        if (TryReadFileValue(filePath, out string? fileToken))
        {
            return (fileToken!, "file");
        }

        if (TryReadAppSettingString(appSettings, out string? appToken, "Dms", "EnrollmentToken"))
        {
            return (appToken!.Trim(), "appsettings");
        }

        return (null, "missing");
    }

    private static (int Value, string Source) ResolveCheckinIntervalSeconds(string filePath, JsonElement? appSettings)
    {
        string? envValue = Environment.GetEnvironmentVariable("DMS_CHECKIN_INTERVAL_SECONDS");
        if (TryParseInterval(envValue, out int envInterval))
        {
            return (envInterval, "env");
        }

        if (TryReadFileValue(filePath, out string? fileValue) && TryParseInterval(fileValue, out int fileInterval))
        {
            return (fileInterval, "file");
        }

        if (TryReadAppSettingInt(appSettings, out int appInterval, "Dms", "CheckinIntervalSeconds"))
        {
            return (Math.Clamp(appInterval, MinCheckinIntervalSeconds, MaxCheckinIntervalSeconds), "appsettings");
        }

        return (FallbackCheckinIntervalSeconds, "fallback");
    }

    private static bool TryParseInterval(string? raw, out int seconds)
    {
        if (int.TryParse(raw, out int parsed))
        {
            seconds = Math.Clamp(parsed, MinCheckinIntervalSeconds, MaxCheckinIntervalSeconds);
            return true;
        }

        seconds = default;
        return false;
    }

    private static bool TryReadFileValue(string path, out string? value)
    {
        value = null;
        try
        {
            if (!File.Exists(path))
            {
                return false;
            }

            string raw = File.ReadAllText(path).Trim();
            if (string.IsNullOrWhiteSpace(raw))
            {
                return false;
            }

            value = raw;
            return true;
        }
        catch
        {
            return false;
        }
    }

    private static JsonElement? LoadAppSettings(string baseDirectory)
    {
        try
        {
            string path = Path.Combine(baseDirectory, "appsettings.json");
            if (!File.Exists(path))
            {
                return null;
            }

            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(path));
            return document.RootElement.Clone();
        }
        catch
        {
            return null;
        }
    }

    private static bool TryReadAppSettingString(JsonElement? root, out string? value, params string[] path)
    {
        value = null;
        if (!TryWalkJson(root, out JsonElement node, path))
        {
            return false;
        }

        if (node.ValueKind != JsonValueKind.String)
        {
            return false;
        }

        string? raw = node.GetString()?.Trim();
        if (string.IsNullOrWhiteSpace(raw))
        {
            return false;
        }

        value = raw;
        return true;
    }

    private static bool TryReadAppSettingInt(JsonElement? root, out int value, params string[] path)
    {
        value = default;
        if (!TryWalkJson(root, out JsonElement node, path))
        {
            return false;
        }

        if (node.ValueKind == JsonValueKind.Number && node.TryGetInt32(out int parsed))
        {
            value = parsed;
            return true;
        }

        if (node.ValueKind == JsonValueKind.String && int.TryParse(node.GetString(), out parsed))
        {
            value = parsed;
            return true;
        }

        return false;
    }

    private static bool TryWalkJson(JsonElement? root, out JsonElement node, params string[] path)
    {
        node = default;
        if (!root.HasValue)
        {
            return false;
        }

        JsonElement current = root.Value;
        foreach (string segment in path)
        {
            if (current.ValueKind != JsonValueKind.Object || !current.TryGetProperty(segment, out current))
            {
                return false;
            }
        }

        node = current;
        return true;
    }

    private static string NormalizeApiBaseUrl(string value)
    {
        string trimmed = value.Trim();
        return trimmed.EndsWith("/", StringComparison.Ordinal) ? trimmed : trimmed + "/";
    }

    private static void WriteAtomically(string path, string contents)
    {
        string? directory = Path.GetDirectoryName(path);
        if (string.IsNullOrWhiteSpace(directory))
        {
            return;
        }

        try
        {
            Directory.CreateDirectory(directory);
        }
        catch
        {
            return;
        }

        string tempPath = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            File.WriteAllText(tempPath, contents);

            try
            {
                File.Move(tempPath, path, true);
                return;
            }
            catch (UnauthorizedAccessException)
            {
                if (!TryClearReadOnlyAttribute(path))
                {
                    return;
                }

                try
                {
                    File.Move(tempPath, path, true);
                    return;
                }
                catch
                {
                    // Fall through to direct write fallback.
                }
            }
            catch (IOException)
            {
                // Fall through to direct write fallback.
            }

            TryWriteText(path, contents);
        }
        catch
        {
            // Never block agent startup/check-in on diagnostics/state persistence.
        }
        finally
        {
            TryDeleteFile(tempPath);
        }
    }

    private static bool TryWriteText(string path, string contents)
    {
        try
        {
            File.WriteAllText(path, contents);
            return true;
        }
        catch
        {
            return false;
        }
    }

    private static bool TryClearReadOnlyAttribute(string path)
    {
        try
        {
            if (!File.Exists(path))
            {
                return true;
            }

            FileAttributes attributes = File.GetAttributes(path);
            if ((attributes & FileAttributes.ReadOnly) == 0)
            {
                return true;
            }

            File.SetAttributes(path, attributes & ~FileAttributes.ReadOnly);
            return true;
        }
        catch
        {
            return false;
        }
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
}
