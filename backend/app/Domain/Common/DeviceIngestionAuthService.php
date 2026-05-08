<?php

namespace App\Domain\Common;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DeviceIngestionAuthService
{
    public function ensureBehaviorIngestToken(Device $device): string
    {
        $device->refresh();
        $tags = is_array($device->tags) ? $device->tags : [];
        $existing = trim((string) data_get($tags, 'security.behavior_ingest_token', ''));
        if ($existing !== '') {
            return $existing;
        }

        $token = Str::random(64);
        data_set($tags, 'security.behavior_ingest_token', $token);
        data_set($tags, 'security.behavior_ingest_token_issued_at', now()->toIso8601String());
        $device->update(['tags' => $tags]);

        return $token;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{allowed:bool,reason:string,auth_mode:?string}
     */
    public function authorizeBehaviorLogRequest(Device $device, Request $request, array $payload): array
    {
        $tags = is_array($device->tags) ? $device->tags : [];
        $expectedToken = trim((string) data_get($tags, 'security.behavior_ingest_token', ''));
        $providedToken = trim((string) $request->header('X-DMS-Behavior-Token', ''));

        if ($expectedToken !== '' && $providedToken !== '' && hash_equals($expectedToken, $providedToken)) {
            return ['allowed' => true, 'reason' => 'validated by behavior ingestion token', 'auth_mode' => 'token'];
        }

        $allowFallback = (bool) config('services.endpoint_intelligence.allow_behavior_checkin_fallback', true);
        if (! $allowFallback) {
            return ['allowed' => false, 'reason' => 'behavior ingestion token is missing or invalid', 'auth_mode' => null];
        }

        $expectedCheckinId = trim((string) data_get($tags, 'last_checkin_id', ''));
        $providedCheckinId = $this->resolveProvidedCheckinId($payload);
        if ($expectedCheckinId === '' || $providedCheckinId === '') {
            return ['allowed' => false, 'reason' => 'behavior ingestion authentication checkin_id is missing', 'auth_mode' => null];
        }
        if (! hash_equals($expectedCheckinId, $providedCheckinId)) {
            return ['allowed' => false, 'reason' => 'behavior ingestion authentication checkin_id mismatch', 'auth_mode' => null];
        }

        $windowMinutes = max(1, (int) config('services.endpoint_intelligence.behavior_checkin_fallback_window_minutes', 15));
        if (! $device->last_seen_at instanceof Carbon || $device->last_seen_at->lt(now()->subMinutes($windowMinutes))) {
            return ['allowed' => false, 'reason' => 'behavior ingestion checkin_id fallback is stale', 'auth_mode' => null];
        }

        return ['allowed' => true, 'reason' => 'validated by recent checkin fallback', 'auth_mode' => 'checkin_fallback'];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveProvidedCheckinId(array $payload): string
    {
        $topLevel = trim((string) ($payload['checkin_id'] ?? ''));
        if ($topLevel !== '') {
            return $topLevel;
        }

        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        $checkinIds = collect($events)
            ->map(fn (mixed $event): string => trim((string) data_get($event, 'checkin_id', '')))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        if ($checkinIds->count() === 1) {
            return (string) $checkinIds->first();
        }

        return '';
    }
}
