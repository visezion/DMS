<?php

namespace App\Services;

use App\Models\KeyMaterial;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CommandEnvelopeSigner
{
    public function ensureActiveKey(): KeyMaterial
    {
        $active = KeyMaterial::query()
            ->where('purpose', 'command_signing')
            ->where('status', 'active')
            ->where('not_before', '<=', now())
            ->where('not_after', '>', now())
            ->orderByDesc('not_before')
            ->first();

        if ($active && $this->isUsableSigningKey($active)) {
            return $active;
        }

        if ($active) {
            $active->update(['status' => 'retired']);
        }

        return $this->rotate();
    }

    public function rotate(?string $kid = null): KeyMaterial
    {
        $kid = $kid ?? 'cmd-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $privatePath = storage_path('app/keys/'.$kid.'.sk');
        if (! is_dir(dirname($privatePath))) {
            mkdir(dirname($privatePath), 0700, true);
        }

        file_put_contents($privatePath, base64_encode($secretKey));

        KeyMaterial::query()
            ->where('purpose', 'command_signing')
            ->where('status', 'active')
            ->update(['status' => 'retiring']);

        return KeyMaterial::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'kid' => $kid,
            'purpose' => 'command_signing',
            'alg' => 'Ed25519',
            'status' => 'active',
            'not_before' => now(),
            'not_after' => now()->addYear(),
            'public_fingerprint_sha256' => hash('sha256', $publicKey),
            'public_key_pem' => base64_encode($publicKey),
            'metadata' => [
                'private_key_path' => $privatePath,
                'public_key_base64' => base64_encode($publicKey),
            ],
        ]);
    }

    public function signEnvelope(array $envelope, ?string $modeOverride = null, ?string $kidOverride = null): array
    {
        $key = $kidOverride
            ? KeyMaterial::query()
                ->where('purpose', 'command_signing')
                ->whereIn('status', ['active', 'retiring'])
                ->where('kid', $kidOverride)
                ->where('not_before', '<=', now())
                ->where('not_after', '>', now()->subDay())
                ->orderByDesc('not_before')
                ->first()
            : null;
        $secretKey = $key ? $this->readSecretKey($key) : null;
        if (! $secretKey) {
            $key = $this->ensureActiveKey();
            $secretKey = $this->readSecretKey($key);
        }
        if (! $secretKey) {
            throw new \RuntimeException('Signing private key missing/invalid for key '.$key->kid);
        }

        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        $this->syncPublicKeyMetadata($key, $publicKey);

        $canonical = $this->canonicalJson($envelope);
        $mode = strtolower((string) ($modeOverride ?? env('DMS_SIGNATURE_MODE', 'digest')));
        $message = match ($mode) {
            'canonical' => $canonical,
            default => hash('sha256', $canonical, true),
        };
        $signature = sodium_crypto_sign_detached($message, $secretKey);
        if (! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new \RuntimeException('Generated signature self-check failed for key '.$key->kid);
        }

        return [
            'kid' => $key->kid,
            'alg' => 'Ed25519',
            'sig' => base64_encode($signature),
        ];
    }

    private function readSecretKey(KeyMaterial $key): ?string
    {
        $secretPath = Arr::get($key->metadata, 'private_key_path');
        if (! is_string($secretPath) || trim($secretPath) === '' || ! is_file($secretPath)) {
            return null;
        }

        $decoded = base64_decode((string) file_get_contents($secretPath), true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        return $decoded;
    }

    private function isUsableSigningKey(KeyMaterial $key): bool
    {
        $secret = $this->readSecretKey($key);
        if (! $secret) {
            return false;
        }

        $derivedPublic = sodium_crypto_sign_publickey_from_secretkey($secret);
        $this->syncPublicKeyMetadata($key, $derivedPublic);

        return true;
    }

    private function hasUsablePublicKey(KeyMaterial $key): bool
    {
        $metadata = is_array($key->metadata) ? $key->metadata : [];
        $publicBase64 = (string) Arr::get($metadata, 'public_key_base64', $key->public_key_pem);
        if ($publicBase64 === '') {
            return false;
        }

        $decoded = base64_decode($publicBase64, true);

        return is_string($decoded) && $decoded !== '';
    }

    private function syncPublicKeyMetadata(KeyMaterial $key, string $publicKey): void
    {
        $derivedBase64 = base64_encode($publicKey);
        $derivedFingerprint = hash('sha256', $publicKey);
        $metadata = is_array($key->metadata) ? $key->metadata : [];
        $storedBase64 = (string) Arr::get($metadata, 'public_key_base64', '');

        if ($storedBase64 === $derivedBase64 && (string) $key->public_fingerprint_sha256 === $derivedFingerprint) {
            return;
        }

        $metadata['public_key_base64'] = $derivedBase64;
        $key->forceFill([
            'metadata' => $metadata,
            'public_key_pem' => $derivedBase64,
            'public_fingerprint_sha256' => $derivedFingerprint,
        ])->save();
    }

    public function keyset(): array
    {
        return KeyMaterial::query()
            ->where('purpose', 'command_signing')
            ->whereIn('status', ['active', 'retiring'])
            ->where('not_before', '<=', now())
            ->where('not_after', '>', now()->subDay())
            ->orderByDesc('not_before')
            ->get()
            ->filter(fn (KeyMaterial $key) => $this->hasUsablePublicKey($key))
            ->map(fn (KeyMaterial $key) => [
                'kid' => $key->kid,
                'alg' => $key->alg,
                'status' => $key->status,
                'not_before' => $key->not_before?->toIso8601String(),
                'not_after' => $key->not_after?->toIso8601String(),
                'public_key_base64' => Arr::get($key->metadata, 'public_key_base64', $key->public_key_pem),
                'public_fingerprint_sha256' => $key->public_fingerprint_sha256,
            ])->values()->all();
    }

    public function canonicalJson(mixed $value): string
    {
        $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

        if (is_array($value)) {
            if ($this->isList($value)) {
                $items = array_map(fn ($v) => $this->canonicalJson($v), $value);
                return '['.implode(',', $items).']';
            }

            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[(string) $k] = $v;
            }
            ksort($normalized, SORT_STRING);

            $pairs = [];
            foreach ($normalized as $k => $v) {
                $pairs[] = $this->encodeJsonString((string) $k).':'.$this->canonicalJson($v);
            }

            return '{'.implode(',', $pairs).'}';
        }

        if (is_object($value)) {
            return $this->canonicalJson((array) $value);
        }

        if (is_string($value)) {
            return $this->encodeJsonString($value);
        }

        return json_encode($value, $jsonFlags);
    }

    private function encodeJsonString(string $value): string
    {
        // Mirror System.Text.Json default escaping used by agent canonicalization.
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return str_replace(
            ['+', '<', '>', '&', "'"],
            ['\\u002B', '\\u003C', '\\u003E', '\\u0026', '\\u0027'],
            $encoded
        );
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
