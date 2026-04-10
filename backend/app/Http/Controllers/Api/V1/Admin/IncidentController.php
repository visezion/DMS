<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CorrelatedIncident;
use App\Models\TimelineEvent;
use Illuminate\Http\JsonResponse;

class IncidentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(CorrelatedIncident::query()->latest('opened_at')->paginate(25));
    }

    public function show(string $incidentId): JsonResponse
    {
        $incident = CorrelatedIncident::query()->with('timelines')->findOrFail($incidentId);

        return response()->json($incident);
    }

    public function timeline(string $incidentId): JsonResponse
    {
        return response()->json(
            TimelineEvent::query()
                ->where('incident_id', $incidentId)
                ->orderBy('occurred_at')
                ->get()
        );
    }

    public function deviceTimeline(string $deviceId): JsonResponse
    {
        return response()->json(
            TimelineEvent::query()
                ->where('device_id', $deviceId)
                ->orderByDesc('occurred_at')
                ->limit(250)
                ->get()
        );
    }
}
