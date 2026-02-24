using System.Text.Json.Serialization;

namespace Dms.Agent.Core.Protocol;

public sealed class SignatureDto
{
    [JsonPropertyName("kid")]
    public string Kid { get; init; } = string.Empty;

    [JsonPropertyName("alg")]
    public string Alg { get; init; } = string.Empty;

    [JsonPropertyName("sig")]
    public string? Sig { get; init; }
}

public sealed class CommandEnvelopeDto
{
    [JsonPropertyName("schema")]
    public string Schema { get; init; } = string.Empty;

    [JsonPropertyName("command_id")]
    public string CommandId { get; init; } = string.Empty;

    [JsonPropertyName("device_id")]
    public string DeviceId { get; init; } = string.Empty;

    [JsonPropertyName("sequence")]
    public long Sequence { get; init; }

    [JsonPropertyName("nonce")]
    public string Nonce { get; init; } = string.Empty;

    [JsonPropertyName("issued_at")]
    public DateTimeOffset IssuedAt { get; init; }

    [JsonPropertyName("expires_at")]
    public DateTimeOffset ExpiresAt { get; init; }

    [JsonPropertyName("type")]
    public string Type { get; init; } = string.Empty;

    [JsonPropertyName("payload")]
    public Dictionary<string, object?> Payload { get; init; } = [];

    [JsonPropertyName("payload_sha256")]
    public string PayloadSha256 { get; init; } = string.Empty;
}

public sealed class SignedCommandDto
{
    [JsonPropertyName("envelope")]
    public CommandEnvelopeDto Envelope { get; init; } = new();

    [JsonPropertyName("signature")]
    public SignatureDto Signature { get; init; } = new();
}

public sealed class CheckinResponseDto
{
    [JsonPropertyName("server_time")]
    public DateTimeOffset ServerTime { get; init; }

    [JsonPropertyName("commands")]
    public List<SignedCommandDto> Commands { get; init; } = [];
}

public sealed class KeysetResponseDto
{
    [JsonPropertyName("schema")]
    public string Schema { get; init; } = string.Empty;

    [JsonPropertyName("generated_at")]
    public DateTimeOffset GeneratedAt { get; init; }

    [JsonPropertyName("keys")]
    public List<KeysetKeyDto> Keys { get; init; } = [];
}

public sealed class EnrollmentResponseDto
{
    [JsonPropertyName("device_id")]
    public string DeviceId { get; init; } = string.Empty;
}

public sealed class KeysetKeyDto
{
    [JsonPropertyName("kid")]
    public string Kid { get; init; } = string.Empty;

    [JsonPropertyName("alg")]
    public string Alg { get; init; } = string.Empty;

    [JsonPropertyName("status")]
    public string Status { get; init; } = string.Empty;

    [JsonPropertyName("public_key_base64")]
    public string PublicKeyBase64 { get; init; } = string.Empty;
}

public static class ErrorCodes
{
    public const string SigInvalid = "E_SIG_INVALID";
    public const string SigUnknownKid = "E_SIG_UNKNOWN_KID";
    public const string Expired = "E_EXPIRED";
    public const string Replay = "E_REPLAY";
    public const string Schema = "E_SCHEMA";
    public const string PayloadHash = "E_PAYLOAD_HASH";
    public const string Unsupported = "E_UNSUPPORTED";
    public const string ExecFailed = "E_EXEC_FAILED";
    public const string Transient = "E_TRANSIENT";
}
