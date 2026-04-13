using System.Text.Json;

namespace Dms.Agent.Core.Runtime;

public static class WebRtcMediaPipelineCapability
{
    public const bool BuildImplemented = true;

    public static bool IsBuildImplemented()
    {
        return BuildImplemented;
    }

    public static bool IsConfiguredEnabled(string? envValue = null, string? appSettingsPath = null)
    {
        string? effectiveEnvValue = envValue ?? Environment.GetEnvironmentVariable("DMS_WEBRTC_MEDIA_PIPELINE_ENABLED");
        if (!string.IsNullOrWhiteSpace(effectiveEnvValue))
        {
            return string.Equals(effectiveEnvValue.Trim(), "true", StringComparison.OrdinalIgnoreCase);
        }

        string effectiveAppSettingsPath = string.IsNullOrWhiteSpace(appSettingsPath)
            ? Path.Combine(AppContext.BaseDirectory, "appsettings.json")
            : appSettingsPath;

        try
        {
            if (!File.Exists(effectiveAppSettingsPath))
            {
                return false;
            }

            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(effectiveAppSettingsPath));
            if (!document.RootElement.TryGetProperty("Dms", out JsonElement dms) || dms.ValueKind != JsonValueKind.Object)
            {
                return false;
            }

            if (!dms.TryGetProperty("WebRtcMediaPipelineEnabled", out JsonElement enabledNode))
            {
                return false;
            }

            if (enabledNode.ValueKind == JsonValueKind.True)
            {
                return true;
            }

            if (enabledNode.ValueKind == JsonValueKind.False)
            {
                return false;
            }

            if (enabledNode.ValueKind == JsonValueKind.String)
            {
                return string.Equals(enabledNode.GetString()?.Trim(), "true", StringComparison.OrdinalIgnoreCase);
            }
        }
        catch
        {
            return false;
        }

        return false;
    }

    public static bool IsAdvertisedEnabled(string? envValue = null, string? appSettingsPath = null)
    {
        return BuildImplemented && IsConfiguredEnabled(envValue, appSettingsPath);
    }
}
