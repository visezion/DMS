<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Common\DeviceIngestionAuthService;
use App\Domain\Common\DeviceIntelligenceDispatchService;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DeviceBehaviorLogController extends Controller
{
    public function __construct(
        private readonly DeviceIngestionAuthService $ingestionAuth,
        private readonly DeviceIntelligenceDispatchService $dispatchService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['required', 'uuid'],
            'checkin_id' => ['nullable', 'string', 'max:128'],
            'events' => ['required', 'array', 'max:500'],
            'events.*.event_type' => ['required', 'string', 'max:64'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.user_name' => ['nullable', 'string', 'max:255'],
            'events.*.process_name' => ['nullable', 'string', 'max:255'],
            'events.*.file_path' => ['nullable', 'string', 'max:2048'],
            'events.*.event_uid' => ['nullable', 'string', 'max:128'],
            'events.*.session_uid' => ['nullable', 'string', 'max:128'],
            'events.*.process_uid' => ['nullable', 'string', 'max:128'],
            'events.*.parent_process_uid' => ['nullable', 'string', 'max:128'],
            'events.*.checkin_id' => ['nullable', 'string', 'max:128'],
            'events.*.metadata' => ['nullable', 'array'],
        ]);

        $device = Device::query()->findOrFail($payload['device_id']);
        $auth = $this->ingestionAuth->authorizeBehaviorLogRequest($device, $request, $payload);
        if (! $auth['allowed']) {
            return response()->json([
                'message' => 'Behavior log authentication failed.',
                'reason' => $auth['reason'],
            ], 401);
        }

        $now = now();
        foreach ($payload['events'] as $event) {
            $attributes = [
                'device_id' => $device->id,
                'event_type' => (string) $event['event_type'],
                'occurred_at' => Carbon::parse((string) $event['occurred_at'])->utc(),
                'user_name' => $event['user_name'] ?? null,
                'process_name' => $event['process_name'] ?? null,
                'file_path' => $event['file_path'] ?? null,
                'event_uid' => $event['event_uid'] ?? null,
                'session_uid' => $event['session_uid'] ?? null,
                'process_uid' => $event['process_uid'] ?? null,
                'parent_process_uid' => $event['parent_process_uid'] ?? null,
                'checkin_id' => $event['checkin_id'] ?? null,
                'metadata' => array_filter([
                    ...((is_array($event['metadata'] ?? null) ? $event['metadata'] : [])),
                    'event_uid' => $event['event_uid'] ?? null,
                    'session_uid' => $event['session_uid'] ?? null,
                    'process_uid' => $event['process_uid'] ?? null,
                    'parent_process_uid' => $event['parent_process_uid'] ?? null,
                    'checkin_id' => $event['checkin_id'] ?? null,
                ], fn ($value) => $value !== null),
            ];

            if (! empty($attributes['event_uid'])) {
                $existing = DeviceBehaviorLog::query()
                    ->where('device_id', $device->id)
                    ->where('event_uid', (string) $attributes['event_uid'])
                    ->first();

                if ($existing) {
                    $existing->update($attributes);
                } else {
                    DeviceBehaviorLog::query()->create([
                        'id' => (string) Str::uuid(),
                        ...$attributes,
                    ]);
                }

                continue;
            }

            DeviceBehaviorLog::query()->create([
                'id' => (string) Str::uuid(),
                ...$attributes,
            ]);
        }

        $ingested = count($payload['events']);

        $tags = is_array($device->tags) ? $device->tags : [];
        $tags['behavior_telemetry'] = [
            'last_ingested_at' => $now->toIso8601String(),
            'last_batch_count' => $ingested,
            'deduplicated' => collect($payload['events'])->filter(fn (array $event) => ! empty($event['event_uid']))->count(),
            'ingested_without_event_uid' => collect($payload['events'])->filter(fn (array $event) => empty($event['event_uid']))->count(),
        ];
        $device->update(['tags' => $tags]);
        $this->dispatchService->dispatch($device->id);

        return response()->json([
            'ok' => true,
            'ingested' => $ingested,
            'auth_mode' => $auth['auth_mode'],
        ]);
    }
}
