<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BehaviorPolicyRecommendation;
use App\Services\BehaviorPipeline\HumanFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BehaviorPolicyReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,rejected,applied,auto_applied'],
            'action' => ['nullable', 'string', 'in:observe,notify,apply_policy'],
            'severity' => ['nullable', 'string', 'in:low,medium,high'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BehaviorPolicyRecommendation::query()
            ->with([
                'anomalyCase:id,device_id,risk_score,severity,status,summary,detected_at',
                'feedbackEntries:recommendation_id,decision,note,created_at',
            ])
            ->orderByDesc('created_at');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['action'])) {
            $query->where('recommended_action', $data['action']);
        }

        if (! empty($data['severity'])) {
            $query->whereHas('anomalyCase', fn ($q) => $q->where('severity', $data['severity']));
        }

        $perPage = (int) ($data['per_page'] ?? 25);

        return response()->json($query->paginate($perPage));
    }

    public function review(
        Request $request,
        string $recommendationId,
        HumanFeedbackService $feedbackService,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected,edited,false_positive,false_negative'],
            'note' => ['nullable', 'string', 'max:5000'],
            'selected_policy_version_id' => ['nullable', 'uuid', 'exists:policy_versions,id'],
            'metadata' => ['nullable', 'array'],
        ]);

        $recommendation = BehaviorPolicyRecommendation::query()->findOrFail($recommendationId);
        $reviewed = $feedbackService->reviewRecommendation($recommendation, $data, $request->user());

        return response()->json([
            'status' => 'ok',
            'recommendation' => $reviewed->fresh(['anomalyCase', 'feedbackEntries']),
        ]);
    }
}
