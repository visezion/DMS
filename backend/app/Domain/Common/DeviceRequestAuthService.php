<?php

namespace App\Domain\Common;

use App\Models\Device;
use App\Models\DeviceIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeviceRequestAuthService
{
    /**
     * @return array{
     *     allowed:bool,
     *     auth_mode:string,
     *     reason:string,
     *     device:?Device,
     *     identity:?DeviceIdentity,
     *     should_log_legacy:bool
     * }
     */
    public function authorize(Request $request): array
    {
        $mode = strtolower((string) config('services.endpoint_intelligence.device_request_auth_mode', 'required_for_signed_devices'));
        $claimedDeviceId = $this->resolveClaimedDeviceId($request);
        $device = $claimedDeviceId !== '' ? Device::query()->find($claimedDeviceId) : null;
        $identity = $device ? $this->resolveSigningIdentity($device->id) : null;
        $requiresSignature = match ($mode) {
            'required_for_all' => true,
            'required_for_signed_devices' => $identity !== null,
            default => false,
        };

        $signature = trim((string) $request->header('X-DMS-Signature', ''));
        $timestamp = trim((string) $request->header('X-DMS-Timestamp', ''));
        $nonce = trim((string) $request->header('X-DMS-Nonce', ''));
        $headerDeviceId = trim((string) $request->header('X-DMS-Device-Id', ''));
        $headersPresent = $signature !== '' || $timestamp !== '' || $nonce !== '' || $headerDeviceId !== '';
        $hasSignedHeaders = $signature !== '' && $timestamp !== '' && $nonce !== '' && $headerDeviceId !== '';

        if (! $headersPresent) {
            if ($requiresSignature) {
                return $this->deny('missing_signature', $device, $identity);
            }

            return $this->allowLegacy($device, $identity, 'legacy_unsigned');
        }

        if (! $hasSignedHeaders) {
            if ($requiresSignature) {
                return $this->deny('incomplete_signature_headers', $device, $identity);
            }

            return $this->allowLegacy($device, $identity, 'incomplete_signature_headers');
        }

        if (! Str::isUuid($headerDeviceId)) {
            return $requiresSignature
                ? $this->deny('invalid_header_device_id', $device, $identity)
                : $this->allowLegacy($device, $identity, 'invalid_header_device_id');
        }

        if ($claimedDeviceId !== '' && ! hash_equals($claimedDeviceId, $headerDeviceId)) {
            return $this->deny('device_id_mismatch', $device, $identity);
        }

        if (! $device || ! hash_equals((string) $device->id, $headerDeviceId)) {
            return $requiresSignature
                ? $this->deny('unknown_device', $device, $identity)
                : $this->allowLegacy($device, $identity, 'unknown_device');
        }

        if (! $identity) {
            return $requiresSignature
                ? $this->deny('missing_signing_identity', $device, $identity)
                : $this->allowLegacy($device, $identity, 'missing_signing_identity');
        }

        if (! ctype_digit($timestamp)) {
            return $this->deny('invalid_timestamp', $device, $identity);
        }

        $nonceWindow = max(60, (int) config('services.endpoint_intelligence.nonce_window_seconds', 300));
        $timestampValue = (int) $timestamp;
        if (abs(now()->timestamp - $timestampValue) > $nonceWindow) {
            return $this->deny('timestamp_out_of_window', $device, $identity);
        }

        $publicKey = base64_decode((string) $identity->public_key_pem, true);
        $signatureBytes = base64_decode($signature, true);
        if (! is_string($publicKey) || $publicKey === '' || ! is_string($signatureBytes) || $signatureBytes === '') {
            return $this->deny('invalid_key_material', $device, $identity);
        }

        $rawBody = (string) $request->getContent();
        $bodyHash = hash('sha256', $rawBody);
        $messages = $this->buildSignatureMessages($request, $timestamp, $nonce, $headerDeviceId, $bodyHash);
        $verified = collect($messages)->contains(
            fn (string $message): bool => sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKey)
        );

        if (! $verified) {
            return $this->deny('invalid_signature', $device, $identity);
        }

        $replayCacheKey = sprintf('device-request-signature:%s:%s', $headerDeviceId, hash('sha256', $nonce));
        if (! Cache::add($replayCacheKey, now()->toIso8601String(), now()->addSeconds($nonceWindow))) {
            return $this->deny('replay_detected', $device, $identity);
        }

        return [
            'allowed' => true,
            'auth_mode' => 'signature',
            'reason' => 'signature_verified',
            'device' => $device,
            'identity' => $identity,
            'should_log_legacy' => false,
        ];
    }

    private function resolveClaimedDeviceId(Request $request): string
    {
        $headerDeviceId = trim((string) $request->header('X-DMS-Device-Id', ''));
        if ($headerDeviceId !== '') {
            return $headerDeviceId;
        }

        $inputDeviceId = trim((string) $request->input('device_id', ''));

        return $inputDeviceId;
    }

    private function resolveSigningIdentity(string $deviceId): ?DeviceIdentity
    {
        return DeviceIdentity::query()
            ->where('device_id', $deviceId)
            ->where('identity_type', 'request_signing_ed25519')
            ->where('revoked', false)
            ->where(function ($query) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_to')->orWhere('valid_to', '>', now());
            })
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array<int,string>
     */
    private function buildSignatureMessages(
        Request $request,
        string $timestamp,
        string $nonce,
        string $deviceId,
        string $bodyHash
    ): array {
        $requestUri = $request->getRequestUri();
        $pathInfo = $request->getPathInfo();
        $candidates = collect([
            $requestUri,
            '/'.ltrim($requestUri, '/'),
            ltrim($requestUri, '/'),
            $pathInfo,
            '/'.ltrim($pathInfo, '/'),
            ltrim($pathInfo, '/'),
        ])->filter(fn (string $value): bool => trim($value) !== '')->unique()->values();

        return $candidates
            ->map(fn (string $path): string => implode("\n", [
                strtoupper($request->method()),
                $path,
                $timestamp,
                $nonce,
                $deviceId,
                $bodyHash,
            ]))
            ->all();
    }

    /**
     * @return array{
     *     allowed:false,
     *     auth_mode:string,
     *     reason:string,
     *     device:?Device,
     *     identity:?DeviceIdentity,
     *     should_log_legacy:bool
     * }
     */
    private function deny(string $reason, ?Device $device, ?DeviceIdentity $identity): array
    {
        return [
            'allowed' => false,
            'auth_mode' => 'rejected',
            'reason' => $reason,
            'device' => $device,
            'identity' => $identity,
            'should_log_legacy' => false,
        ];
    }

    /**
     * @return array{
     *     allowed:true,
     *     auth_mode:string,
     *     reason:string,
     *     device:?Device,
     *     identity:?DeviceIdentity,
     *     should_log_legacy:bool
     * }
     */
    private function allowLegacy(?Device $device, ?DeviceIdentity $identity, string $reason): array
    {
        return [
            'allowed' => true,
            'auth_mode' => 'legacy_unsigned',
            'reason' => $reason,
            'device' => $device,
            'identity' => $identity,
            'should_log_legacy' => true,
        ];
    }
}
