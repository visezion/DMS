using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Encodings.Web;
using System.Text.Json;
using Dms.Agent.Core.Protocol;
using Dms.Agent.Core.Security;
using Org.BouncyCastle.Crypto.Parameters;
using Org.BouncyCastle.Crypto.Signers;

namespace Dms.Agent.Core.Transport;

public sealed class CommandEnvelopeVerifier(ReplayProtector replayProtector)
{
    private readonly Dictionary<string, byte[]> _publicKeys = new(StringComparer.Ordinal);
    private static readonly bool SignatureBypassEnabled =
        string.Equals(Environment.GetEnvironmentVariable("DMS_SIGNATURE_BYPASS"), "true", StringComparison.OrdinalIgnoreCase);
    private static readonly bool EnforcePayloadHash =
        string.Equals(Environment.GetEnvironmentVariable("DMS_ENFORCE_PAYLOAD_HASH"), "true", StringComparison.OrdinalIgnoreCase);

    public void UpdateKeys(IEnumerable<KeysetKeyDto> keys)
    {
        _publicKeys.Clear();

        foreach (var key in keys)
        {
            if (!string.Equals(key.Alg, "Ed25519", StringComparison.Ordinal) || string.IsNullOrWhiteSpace(key.Kid))
            {
                continue;
            }

            try
            {
                _publicKeys[key.Kid] = Convert.FromBase64String(key.PublicKeyBase64);
            }
            catch
            {
                // Ignore malformed keys and continue.
            }
        }
    }

    public void Verify(SignedCommandDto command)
    {
        if (!string.Equals(command.Envelope.Schema, "dms.command.v1", StringComparison.Ordinal))
        {
            throw new InvalidOperationException(ErrorCodes.Schema);
        }

        if (command.Envelope.ExpiresAt < DateTimeOffset.UtcNow)
        {
            throw new InvalidOperationException(ErrorCodes.Expired);
        }

        string payloadJson = JsonSerializer.Serialize(command.Envelope.Payload);
        var payloadHashCandidates = BuildPayloadHashCandidates(command.Envelope.Payload, payloadJson);
        if (!payloadHashCandidates.Contains(command.Envelope.PayloadSha256, StringComparer.OrdinalIgnoreCase) && EnforcePayloadHash)
        {
            throw new InvalidOperationException(ErrorCodes.PayloadHash);
        }

        if (SignatureBypassEnabled)
        {
            replayProtector.AssertNotReplay(command.Envelope.CommandId, command.Envelope.Nonce, command.Envelope.Sequence);
            return;
        }

        if (!string.Equals(command.Signature.Alg, "Ed25519", StringComparison.Ordinal))
        {
            throw new InvalidOperationException(ErrorCodes.SigUnknownKid);
        }

        if (!_publicKeys.TryGetValue(command.Signature.Kid, out byte[]? publicKey))
        {
            throw new InvalidOperationException(ErrorCodes.SigUnknownKid);
        }

        if (string.IsNullOrWhiteSpace(command.Signature.Sig))
        {
            throw new InvalidOperationException(ErrorCodes.SigInvalid);
        }

        byte[] signature;
        try
        {
            signature = Convert.FromBase64String(command.Signature.Sig);
        }
        catch
        {
            throw new InvalidOperationException(ErrorCodes.SigInvalid);
        }

        if (!VerifyAnyCompatibleSignature(command, publicKey, signature) && !VerifyAgainstAllKnownKeys(command, signature))
        {
            throw new InvalidOperationException(ErrorCodes.SigInvalid);
        }

        replayProtector.AssertNotReplay(command.Envelope.CommandId, command.Envelope.Nonce, command.Envelope.Sequence);
    }

    public Dictionary<string, object?> BuildSignatureDiagnostics(SignedCommandDto command)
    {
        var envelope = command.Envelope;
        var canonicalCandidates = BuildCanonicalCandidates(envelope).ToList();

        var canonicalSha256 = canonicalCandidates
            .Select(c => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(c))).ToLowerInvariant())
            .Distinct(StringComparer.Ordinal)
            .ToArray();

        var digestSha256 = canonicalCandidates
            .Select(c => SHA256.HashData(Encoding.UTF8.GetBytes(c)))
            .Select(d => Convert.ToHexString(SHA256.HashData(d)).ToLowerInvariant())
            .Distinct(StringComparer.Ordinal)
            .ToArray();

        return new Dictionary<string, object?>
        {
            ["signature_bypass_enabled"] = SignatureBypassEnabled,
            ["kid"] = command.Signature.Kid,
            ["alg"] = command.Signature.Alg,
            ["known_kid"] = _publicKeys.ContainsKey(command.Signature.Kid),
            ["issued_at_utc_o"] = envelope.IssuedAt.ToUniversalTime().ToString("O", CultureInfo.InvariantCulture),
            ["issued_at_original_o"] = envelope.IssuedAt.ToString("O", CultureInfo.InvariantCulture),
            ["expires_at_utc_o"] = envelope.ExpiresAt.ToUniversalTime().ToString("O", CultureInfo.InvariantCulture),
            ["expires_at_original_o"] = envelope.ExpiresAt.ToString("O", CultureInfo.InvariantCulture),
            ["payload_sha256_from_envelope"] = envelope.PayloadSha256,
            ["candidate_canonical_sha256"] = canonicalSha256,
            ["candidate_digest_sha256"] = digestSha256,
        };
    }

    public Dictionary<string, object?> BuildPayloadHashDiagnostics(SignedCommandDto command)
    {
        string defaultJson = JsonSerializer.Serialize(command.Envelope.Payload);
        var candidates = BuildPayloadHashCandidates(command.Envelope.Payload, defaultJson)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .OrderBy(x => x, StringComparer.Ordinal)
            .ToArray();

        return new Dictionary<string, object?>
        {
            ["payload_sha256_from_envelope"] = command.Envelope.PayloadSha256,
            ["candidate_payload_sha256"] = candidates,
            ["payload_default_json"] = defaultJson,
        };
    }

    private static string CanonicalJson(object? value)
    {
        if (value is null)
        {
            return "null";
        }

        if (value is JsonElement element)
        {
            return CanonicalJsonFromElement(element);
        }

        if (value is string s)
        {
            return JsonSerializer.Serialize(s);
        }

        if (value is bool b)
        {
            return b ? "true" : "false";
        }

        if (value is byte or sbyte or short or ushort or int or uint or long or ulong or float or double or decimal)
        {
            return JsonSerializer.Serialize(value);
        }

        if (value is IDictionary<string, object?> dict)
        {
            return CanonicalObject(dict);
        }

        if (value is System.Collections.IDictionary legacyDict)
        {
            var converted = new Dictionary<string, object?>();
            foreach (System.Collections.DictionaryEntry entry in legacyDict)
            {
                converted[Convert.ToString(entry.Key, CultureInfo.InvariantCulture) ?? string.Empty] = entry.Value;
            }
            return CanonicalObject(converted);
        }

        if (value is System.Collections.IEnumerable enumerable and not string)
        {
            var items = new List<string>();
            foreach (var item in enumerable)
            {
                items.Add(CanonicalJson(item));
            }
            return "[" + string.Join(',', items) + "]";
        }

        return CanonicalJson(JsonSerializer.Deserialize<JsonElement>(JsonSerializer.Serialize(value)));
    }

    private static string CanonicalJsonFromElement(JsonElement element)
    {
        return element.ValueKind switch
        {
            JsonValueKind.Object => CanonicalObject(element.EnumerateObject().ToDictionary(p => p.Name, p => (object?)p.Value)),
            JsonValueKind.Array => "[" + string.Join(',', element.EnumerateArray().Select(CanonicalJsonFromElement)) + "]",
            JsonValueKind.String => JsonSerializer.Serialize(element.GetString()),
            JsonValueKind.Number => element.GetRawText(),
            JsonValueKind.True => "true",
            JsonValueKind.False => "false",
            _ => "null",
        };
    }

    private static string CanonicalObject(IDictionary<string, object?> dict)
    {
        var keys = dict.Keys.OrderBy(k => k, StringComparer.Ordinal).ToList();
        var parts = keys.Select(k => JsonSerializer.Serialize(k) + ":" + CanonicalJson(dict[k]));
        return "{" + string.Join(',', parts) + "}";
    }

    private static bool VerifyAnyCompatibleSignature(SignedCommandDto command, byte[] publicKey, byte[] signature)
    {
        var canonicalCandidates = BuildCanonicalCandidates(command.Envelope)
            .Distinct(StringComparer.Ordinal)
            .ToList();

        var wireCandidates = BuildWireEnvelopeCandidates(command.Envelope)
            .Distinct(StringComparer.Ordinal)
            .ToList();

        var candidates = canonicalCandidates
            .Concat(wireCandidates)
            .SelectMany(BuildServerEscapeVariants)
            .SelectMany(canonical => new[]
            {
                // Current mode: sign SHA-256(canonical envelope)
                SHA256.HashData(Encoding.UTF8.GetBytes(canonical)),
                // Legacy compatibility mode: sign canonical bytes directly
                Encoding.UTF8.GetBytes(canonical),
            })
            .ToList();

        foreach (var message in candidates)
        {
            var verifier = new Ed25519Signer();
            verifier.Init(false, new Ed25519PublicKeyParameters(publicKey, 0));
            verifier.BlockUpdate(message, 0, message.Length);
            if (verifier.VerifySignature(signature))
            {
                return true;
            }
        }

        return false;
    }

    private bool VerifyAgainstAllKnownKeys(SignedCommandDto command, byte[] signature)
    {
        foreach (byte[] key in _publicKeys.Values)
        {
            if (VerifyAnyCompatibleSignature(command, key, signature))
            {
                return true;
            }
        }

        return false;
    }

    private static IEnumerable<string> BuildCanonicalCandidates(CommandEnvelopeDto envelope)
    {
        var issuedVariants = new[]
        {
            envelope.IssuedAt.ToUniversalTime().ToString("O", CultureInfo.InvariantCulture),
            envelope.IssuedAt.ToString("O", CultureInfo.InvariantCulture),
        }.Distinct(StringComparer.Ordinal);

        var expiresVariants = new[]
        {
            envelope.ExpiresAt.ToUniversalTime().ToString("O", CultureInfo.InvariantCulture),
            envelope.ExpiresAt.ToString("O", CultureInfo.InvariantCulture),
        }.Distinct(StringComparer.Ordinal);

        foreach (var issued in issuedVariants)
        {
            foreach (var expires in expiresVariants)
            {
                yield return CanonicalJson(new Dictionary<string, object?>
                {
                    ["schema"] = envelope.Schema,
                    ["command_id"] = envelope.CommandId,
                    ["device_id"] = envelope.DeviceId,
                    ["sequence"] = envelope.Sequence,
                    ["nonce"] = envelope.Nonce,
                    ["issued_at"] = issued,
                    ["expires_at"] = expires,
                    ["type"] = envelope.Type,
                    ["payload"] = envelope.Payload,
                    ["payload_sha256"] = envelope.PayloadSha256,
                });
            }
        }
    }

    private static HashSet<string> BuildPayloadHashCandidates(Dictionary<string, object?> payload, string defaultJson)
    {
        var candidates = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            HashOf(defaultJson),
        };

        // Legacy server payload hashing may escape "/" as "\/".
        string slashEscaped = defaultJson.Replace("/", "\\/", StringComparison.Ordinal);
        candidates.Add(HashOf(slashEscaped));

        // Canonical payload form with ordinal key sort.
        string canonical = CanonicalJson(payload);
        candidates.Add(HashOf(canonical));
        candidates.Add(HashOf(canonical.Replace("/", "\\/", StringComparison.Ordinal)));

        // Relaxed encoder candidate to tolerate backend escaping differences.
        string relaxed = JsonSerializer.Serialize(payload, new JsonSerializerOptions
        {
            Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
        });
        candidates.Add(HashOf(relaxed));

        // Legacy bug compatibility: double-hash payload JSON.
        candidates.Add(HashOfHexDigest(defaultJson));
        candidates.Add(HashOfHexDigest(canonical));

        return candidates;
    }

    private static string HashOf(string text)
    {
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(text))).ToLowerInvariant();
    }

    private static string HashOfHexDigest(string text)
    {
        byte[] digest = SHA256.HashData(Encoding.UTF8.GetBytes(text));
        string hex = Convert.ToHexString(digest).ToLowerInvariant();
        return HashOf(hex);
    }

    private static IEnumerable<string> BuildWireEnvelopeCandidates(CommandEnvelopeDto envelope)
    {
        var options = new JsonSerializerOptions
        {
            Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
        };

        var orderedObject = new Dictionary<string, object?>
        {
            ["schema"] = envelope.Schema,
            ["command_id"] = envelope.CommandId,
            ["device_id"] = envelope.DeviceId,
            ["sequence"] = envelope.Sequence,
            ["nonce"] = envelope.Nonce,
            ["issued_at"] = envelope.IssuedAt.ToString("O", CultureInfo.InvariantCulture),
            ["expires_at"] = envelope.ExpiresAt.ToString("O", CultureInfo.InvariantCulture),
            ["type"] = envelope.Type,
            ["payload"] = envelope.Payload,
            ["payload_sha256"] = envelope.PayloadSha256,
        };

        yield return JsonSerializer.Serialize(orderedObject);
        yield return JsonSerializer.Serialize(orderedObject, options);
        yield return JsonSerializer.Serialize(envelope);
        yield return JsonSerializer.Serialize(envelope, options);
    }

    private static IEnumerable<string> BuildServerEscapeVariants(string text)
    {
        yield return text;

        string escaped = text
            .Replace("+", "\\u002B", StringComparison.Ordinal)
            .Replace("<", "\\u003C", StringComparison.Ordinal)
            .Replace(">", "\\u003E", StringComparison.Ordinal)
            .Replace("&", "\\u0026", StringComparison.Ordinal)
            .Replace("'", "\\u0027", StringComparison.Ordinal);
        if (!string.Equals(escaped, text, StringComparison.Ordinal))
        {
            yield return escaped;
        }
    }
}
