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

        return $active ?? $this->rotate();
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
        $key = $key ?? $this->ensureActiveKey();
        $secretPath = Arr::get($key->metadata, 'private_key_path');
        if (! $secretPath || ! is_file($secretPath)) {
            throw new \RuntimeException('Signing private key missing for key '.$key->kid);
        }

        $secretKey = base64_decode((string) file_get_contents($secretPath), true);
        if (! $secretKey) {
            throw new \RuntimeException('Invalid signing private key encoding for key '.$key->kid);
        }

        $canonical = $this->canonicalJson($envelope);
        $mode = strtolower((string) ($modeOverride ?? env('DMS_SIGNATURE_MODE', 'digest')));
        $message = match ($mode) {
            'canonical' => $canonical,
            default => hash('sha256', $canonical, true),
        };
        $signature = sodium_crypto_sign_detached($message, $secretKey);

        return [
            'kid' => $key->kid,
            'alg' => 'Ed25519',
            'sig' => base64_encode($signature),
        ];
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
