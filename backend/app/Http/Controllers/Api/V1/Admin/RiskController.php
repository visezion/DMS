<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceRiskScore;
use App\Models\ThreatFinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiskController extends Controller
{
    public function device(string $deviceId): JsonResponse
    {
        return response()->json([
            'risk' => DeviceRiskScore::query()->where('device_id', $deviceId)->latest('scored_at')->first(),
            'findings' => ThreatFinding::query()
                ->where('device_id', $deviceId)
                ->whereIn('status', ['open', 'investigating'])
                ->latest('last_seen_at')
                ->get(),
        ]);
    }

    public function findings(Request $request): JsonResponse
    {
        $query = ThreatFinding::query()->latest('last_seen_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity'));
        }

        $paginated = $query->paginate(50);
        $paginated->getCollection()->transform(function (ThreatFinding $finding) {
            $finding->setAttribute('summary', data_get($finding->evidence ?? [], 'summary', $finding->finding_type));

            return $finding;
        });

        return response()->json($paginated);
    }

    public function suppress(Request $request, string $findingId): JsonResponse
    {
        $finding = ThreatFinding::query()->findOrFail($findingId);
        $finding->update([
            'status' => 'suppressed',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        return response()->json($finding);
    }

    public function review(Request $request, string $findingId): JsonResponse
    {
        $finding = ThreatFinding::query()->findOrFail($findingId);
        $finding->update([
            'status' => 'investigating',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        return response()->json($finding);
    }

    public function escalate(Request $request, string $findingId): JsonResponse
    {
        $finding = ThreatFinding::query()->findOrFail($findingId);
        $finding->update([
            'status' => 'investigating',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'finding' => $finding,
            'escalated' => true,
        ]);
    }
}
