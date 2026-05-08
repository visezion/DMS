<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Common\DeviceIngestionAuthService;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\EnrollmentToken;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnrollmentController extends Controller
{
    public function __construct(
        private readonly DeviceIngestionAuthService $ingestionAuth
    ) {
    }

    public function enroll(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate([
            'enrollment_token' => ['required', 'string'],
            'csr_pem' => ['nullable', 'string'],
            'request_signing_public_key' => ['nullable', 'string'],
            'device_facts' => ['required', 'array'],
            'device_facts.hostname' => ['required', 'string'],
            'device_facts.os_name' => ['required', 'string'],
            'device_facts.os_version' => ['nullable', 'string'],
            'device_facts.serial_number' => ['nullable', 'string'],
            'device_facts.agent_version' => ['required', 'string'],
            'device_facts.agent_build' => ['nullable', 'string', 'max:128'],
            'device_facts.inventory' => ['nullable', 'array'],
        ]);

        $token = EnrollmentToken::query()
            ->where('token_hash', hash('sha256', $payload['enrollment_token']))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Enrollment token invalid or expired'], 422);
        }

        $requestSigningPublicKey = trim((string) ($payload['request_signing_public_key'] ?? ''));
        if ($requestSigningPublicKey !== '') {
            $decoded = base64_decode($requestSigningPublicKey, true);
            if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return response()->json(['message' => 'request_signing_public_key is invalid'], 422);
            }
        }

        $effectiveTenantId = (bool) config('dms.standalone_mode', true)
            ? null
            : $token->tenant_id;

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $effectiveTenantId,
            'hostname' => $payload['device_facts']['hostname'],
            'os_name' => $payload['device_facts']['os_name'],
            'os_version' => $payload['device_facts']['os_version'] ?? null,
            'serial_number' => $payload['device_facts']['serial_number'] ?? null,
            'agent_version' => $payload['device_facts']['agent_version'],
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => array_filter([
                'agent_build' => (string) ($payload['device_facts']['agent_build'] ?? ''),
                'inventory' => is_array($payload['device_facts']['inventory'] ?? null) ? $payload['device_facts']['inventory'] : null,
                'inventory_updated_at' => isset($payload['device_facts']['inventory']) ? now()->toIso8601String() : null,
            ], fn ($value) => $value !== ''),
        ]);

        if (! empty($payload['csr_pem'])) {
            DeviceIdentity::query()->create([
                'id' => (string) Str::uuid(),
                'device_id' => $device->id,
                'identity_type' => 'mtls_cert',
                'public_key_pem' => $payload['csr_pem'] ?? null,
                'valid_from' => now(),
                'valid_to' => now()->addYear(),
                'revoked' => false,
            ]);
        }

        if ($requestSigningPublicKey !== '') {
            DeviceIdentity::query()->create([
                'id' => (string) Str::uuid(),
                'device_id' => $device->id,
                'identity_type' => 'request_signing_ed25519',
                'public_key_pem' => $requestSigningPublicKey,
                'cert_thumbprint_sha256' => hash('sha256', (string) base64_decode($requestSigningPublicKey, true)),
                'valid_from' => now(),
                'valid_to' => now()->addYear(),
                'revoked' => false,
            ]);
        }

        $token->update([
            'used_at' => now(),
            'used_by_device_id' => $device->id,
        ]);

        $auditLogger->log('device.enroll', 'device', $device->id, null, $device->toArray(), null, $device->id);
        $behaviorIngestToken = $this->ingestionAuth->ensureBehaviorIngestToken($device);

        return response()->json([
            'device_id' => $device->id,
            'identity' => [
                'type' => 'mtls_cert',
                'certificate_pem' => null,
                'ca_chain_pem' => [],
                'expires_at' => now()->addYear()->toIso8601String(),
            ],
            'bootstrap' => [
                'checkin_interval_seconds' => max(15, min(300, (int) config('services.endpoint_intelligence.checkin_interval_seconds', 60))),
                'nonce_window_seconds' => max(60, (int) config('services.endpoint_intelligence.nonce_window_seconds', 300)),
                'behavior_ingest_token' => $behaviorIngestToken,
            ],
        ], 201);
    }
}
