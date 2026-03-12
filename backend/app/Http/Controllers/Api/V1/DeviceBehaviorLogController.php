<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\BehaviorPipeline\EventIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceBehaviorLogController extends Controller
{
    public function store(Request $request, EventIngestionService $ingestionService): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid', 'exists:devices,id'],
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.event_type' => ['required', 'string', 'in:user_logon,app_launch,file_access'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.user_name' => ['nullable', 'string', 'max:255'],
            'events.*.process_name' => ['nullable', 'string', 'max:1024'],
            'events.*.file_path' => ['nullable', 'string', 'max:4096'],
            'events.*.metadata' => ['nullable', 'array'],
        ]);

        $device = Device::query()->findOrFail((string) $data['device_id']);
        $result = $ingestionService->ingest($device, (array) $data['events']);

        return response()->json([
            'status' => 'ok',
            'accepted' => $result['accepted'],
            'detection_mode' => 'ai',
            'detection_dispatch' => 'queued',
        ]);
    }
}
