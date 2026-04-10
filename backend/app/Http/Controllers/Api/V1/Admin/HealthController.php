<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceHealthScore;
use App\Models\DeviceHealthSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function summary(string $deviceId): JsonResponse
    {
        $device = Device::query()->findOrFail($deviceId);
        $health = DeviceHealthScore::query()->where('device_id', $device->id)->latest('scored_at')->first();

        return response()->json([
            'device' => $device,
            'health' => $health,
        ]);
    }

    public function trend(Request $request, string $deviceId): JsonResponse
    {
        $windowDays = max(1, min(90, (int) $request->integer('days', 30)));
        $scores = DeviceHealthScore::query()
            ->where('device_id', $deviceId)
            ->where('scored_at', '>=', now()->subDays($windowDays))
            ->orderBy('scored_at')
            ->get();

        return response()->json($scores);
    }

    public function unhealthy(Request $request): JsonResponse
    {
        $bands = array_values(array_filter(explode(',', (string) $request->query('bands', 'critical,degraded'))));
        $scores = DeviceHealthScore::query()
            ->whereIn('band', $bands)
            ->orderBy('score')
            ->orderByDesc('scored_at')
            ->limit(100)
            ->get()
            ->unique('device_id')
            ->values();

        return response()->json($scores);
    }

    public function compare(string $deviceId): JsonResponse
    {
        $device = Device::query()->findOrFail($deviceId);
        $current = DeviceHealthScore::query()->where('device_id', $device->id)->latest('scored_at')->first();
        $fleetAverage = round((float) DeviceHealthScore::query()->avg('score'), 2);
        $peerAverage = round((float) DeviceHealthScore::query()
            ->whereIn('device_id', Device::query()->where('os_name', $device->os_name)->pluck('id'))
            ->avg('score'), 2);

        return response()->json([
            'device_id' => $device->id,
            'current' => $current,
            'fleet_average' => $fleetAverage,
            'peer_average' => $peerAverage,
        ]);
    }

    public function telemetry(string $deviceId): JsonResponse
    {
        $device = Device::query()->findOrFail($deviceId);
        $snapshot = DeviceHealthSnapshot::query()
            ->where('device_id', $device->id)
            ->orderByDesc('snapshot_at')
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'device' => $device,
            'snapshot' => $snapshot,
            'telemetry_coverage' => data_get($snapshot?->raw_payload, 'telemetry_coverage', []),
            'behavior_summary' => data_get($snapshot?->raw_payload, 'behavior_summary', []),
            'windows_telemetry' => data_get($snapshot?->raw_payload, 'windows_telemetry', []),
        ]);
    }
}
