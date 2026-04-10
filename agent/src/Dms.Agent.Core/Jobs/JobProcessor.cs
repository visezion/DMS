using Dms.Agent.Core.Protocol;
using Dms.Agent.Core.Security;
using Dms.Agent.Core.Transport;
using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Dms.Agent.Core.Jobs;

public interface IJobHandler
{
    string JobType { get; }
    Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken);
}

public sealed class JobProcessor
{
    private readonly ApiClient _apiClient;
    private readonly CommandEnvelopeVerifier _verifier;
    private readonly Dictionary<string, IJobHandler> _handlers;
    private static readonly bool SignatureDebugEnabled =
        string.Equals(Environment.GetEnvironmentVariable("DMS_SIGNATURE_DEBUG"), "true", StringComparison.OrdinalIgnoreCase);

    public JobProcessor(ApiClient apiClient)
    {
        _apiClient = apiClient;
        _verifier = new CommandEnvelopeVerifier(new ReplayProtector());
        _handlers = new IJobHandler[]
        {
            new Handlers.WingetInstallHandler(),
            new Handlers.WingetUninstallHandler(),
            new Handlers.MsiInstallHandler(),
            new Handlers.MsiUninstallHandler(),
            new Handlers.ExeInstallHandler(),
            new Handlers.CustomInstallHandler(),
            new Handlers.ArchiveInstallHandler(),
            new Handlers.ExeUninstallHandler(),
            new Handlers.ArchiveUninstallHandler(),
            new Handlers.SoftwareInventoryReconcileHandler(),
            new Handlers.PolicyApplyHandler(),
            new Handlers.ScriptHandler(),
            new Handlers.SnapshotCreateHandler(),
            new Handlers.SnapshotRestoreHandler(),
            new Handlers.AgentUpdateHandler(),
            new Handlers.AgentUninstallHandler(),
        }.ToDictionary(x => x.JobType, StringComparer.OrdinalIgnoreCase);
    }

    public async Task ProcessAsync(List<SignedCommandDto> commands, CancellationToken cancellationToken, DateTimeOffset? verificationNowUtc = null)
    {
        _verifier.UpdateKeys(await _apiClient.GetKeysetAsync(cancellationToken));

        foreach (var command in commands)
        {
            try
            {
                _verifier.Verify(command, verificationNowUtc);
                await _apiClient.AckAsync(command.Envelope.CommandId, cancellationToken);

                if (!_handlers.TryGetValue(command.Envelope.Type, out var handler))
                {
                    await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, new { error = ErrorCodes.Unsupported }, BuildResultMeta(command.Envelope, "failed", 1, new { error = ErrorCodes.Unsupported }, 0L), cancellationToken);
                    continue;
                }

                var stopwatch = Stopwatch.StartNew();
                var result = await handler.ExecuteAsync(command.Envelope, cancellationToken);
                stopwatch.Stop();
                await _apiClient.ResultAsync(command.Envelope.CommandId, result.Status, result.ExitCode, result.Result, BuildResultMeta(command.Envelope, result.Status, result.ExitCode, result.Result, stopwatch.ElapsedMilliseconds), cancellationToken);
            }
            catch (Exception ex)
            {
                if (string.Equals(ex.Message, ErrorCodes.Expired, StringComparison.Ordinal))
                {
                    var result = new
                    {
                        error = ex.Message,
                        now_utc = DateTimeOffset.UtcNow.ToString("O"),
                        verification_now_utc = (verificationNowUtc?.ToUniversalTime() ?? DateTimeOffset.UtcNow).ToString("O"),
                        issued_at_utc = command.Envelope.IssuedAt.ToUniversalTime().ToString("O"),
                        expires_at_utc = command.Envelope.ExpiresAt.ToUniversalTime().ToString("O"),
                    };
                    await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, result, BuildResultMeta(command.Envelope, "failed", 1, result, 0L), cancellationToken);
                    continue;
                }

                if (SignatureDebugEnabled && string.Equals(ex.Message, ErrorCodes.PayloadHash, StringComparison.Ordinal))
                {
                    var diagnostics = _verifier.BuildPayloadHashDiagnostics(command);
                    var result = new
                    {
                        error = ex.Message,
                        payload_hash_debug = diagnostics,
                    };
                    await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, result, BuildResultMeta(command.Envelope, "failed", 1, result, 0L), cancellationToken);
                    continue;
                }

                if (SignatureDebugEnabled && string.Equals(ex.Message, ErrorCodes.SigInvalid, StringComparison.Ordinal))
                {
                    var diagnostics = _verifier.BuildSignatureDiagnostics(command);
                    var result = new
                    {
                        error = ex.Message,
                        signature_debug = diagnostics,
                    };
                    await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, result, BuildResultMeta(command.Envelope, "failed", 1, result, 0L), cancellationToken);
                    continue;
                }

                var failure = new { error = ex.Message };
                await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, failure, BuildResultMeta(command.Envelope, "failed", 1, failure, 0L), cancellationToken);
            }
        }
    }

    private static Dictionary<string, object?> BuildResultMeta(CommandEnvelopeDto envelope, string status, int exitCode, object? result, long durationMs)
    {
        string serialized = SerializeForHash(result);
        var rollbackHint = InferRollbackHint(envelope, result, status);

        return new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
        {
            ["execution_duration_ms"] = durationMs,
            ["stdout_sha256"] = ComputeSha256Hex(ExtractText(result, "stdout") ?? serialized),
            ["stderr_sha256"] = ComputeSha256Hex(ExtractText(result, "stderr") ?? string.Empty),
            ["rollback_hint"] = rollbackHint,
            ["artifacts"] = ExtractArtifacts(result),
            ["action_token"] = envelope.Payload.GetValueOrDefault("action_token"),
            ["idempotency_key"] = $"{envelope.CommandId}:{envelope.Sequence}:{status}:{exitCode}",
        };
    }

    private static object? InferRollbackHint(CommandEnvelopeDto envelope, object? result, string status)
    {
        if (string.Equals(status, "success", StringComparison.OrdinalIgnoreCase))
        {
            return ExtractObject(result, "rollback_hint");
        }

        if (TryGetPayloadDictionary(envelope.Payload, "rollback_payload", out var rollbackPayload))
        {
            return new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
            {
                ["possible"] = true,
                ["job_type"] = envelope.Payload.GetValueOrDefault("rollback_job_type") ?? envelope.Type,
                ["payload"] = rollbackPayload,
            };
        }

        if (TryGetPayloadString(envelope.Payload, "rollback_command", out var rollbackCommand))
        {
            return new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
            {
                ["possible"] = true,
                ["job_type"] = "run_command",
                ["payload"] = new Dictionary<string, object?> { ["command"] = rollbackCommand },
            };
        }

        return new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
        {
            ["possible"] = false,
            ["reason"] = "No rollback payload was supplied with the original command.",
        };
    }

    private static IReadOnlyList<Dictionary<string, object?>> ExtractArtifacts(object? result)
    {
        object? artifacts = ExtractObject(result, "artifacts");
        if (artifacts is IReadOnlyList<Dictionary<string, object?>> typed)
        {
            return typed;
        }

        if (artifacts is IEnumerable<object?> sequence)
        {
            return sequence
                .OfType<Dictionary<string, object?>>()
                .ToList();
        }

        return [];
    }

    private static string? ExtractText(object? source, string propertyName)
    {
        object? value = ExtractObject(source, propertyName);
        return value?.ToString();
    }

    private static object? ExtractObject(object? source, string propertyName)
    {
        if (source is null)
        {
            return null;
        }

        if (source is IDictionary<string, object?> dict && dict.TryGetValue(propertyName, out var dictValue))
        {
            return dictValue;
        }

        var property = source.GetType().GetProperty(propertyName);
        return property?.GetValue(source);
    }

    private static bool TryGetPayloadString(IReadOnlyDictionary<string, object?> payload, string key, out string value)
    {
        value = string.Empty;
        if (!payload.TryGetValue(key, out var raw) || raw is null)
        {
            return false;
        }

        value = raw.ToString() ?? string.Empty;
        return !string.IsNullOrWhiteSpace(value);
    }

    private static bool TryGetPayloadDictionary(IReadOnlyDictionary<string, object?> payload, string key, out Dictionary<string, object?> value)
    {
        value = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase);
        if (!payload.TryGetValue(key, out var raw) || raw is null)
        {
            return false;
        }

        if (raw is Dictionary<string, object?> dict)
        {
            value = dict;
            return true;
        }

        if (raw is JsonElement json && json.ValueKind == JsonValueKind.Object)
        {
            value = JsonSerializer.Deserialize<Dictionary<string, object?>>(json.GetRawText()) ?? value;
            return value.Count > 0;
        }

        return false;
    }

    private static string SerializeForHash(object? value)
    {
        if (value is null)
        {
            return string.Empty;
        }

        try
        {
            return JsonSerializer.Serialize(value);
        }
        catch
        {
            return value.ToString() ?? string.Empty;
        }
    }

    private static string ComputeSha256Hex(string value)
    {
        byte[] bytes = SHA256.HashData(Encoding.UTF8.GetBytes(value));
        return Convert.ToHexString(bytes).ToLowerInvariant();
    }
}
