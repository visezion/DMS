<?php

namespace App\Services\BehaviorPipeline;

use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorPolicyFeedback;
use App\Models\BehaviorPolicyRecommendation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HumanFeedbackService
{
    public function __construct(private readonly PolicyApplicationService $policyApplicationService)
    {
    }

    /**
     * @param array{decision:string,note?:string,selected_policy_version_id?:string,metadata?:array<string,mixed>} $payload
     */
    public function reviewRecommendation(BehaviorPolicyRecommendation $recommendation, array $payload, ?User $reviewer): BehaviorPolicyRecommendation
    {
        $decision = strtolower(trim((string) ($payload['decision'] ?? '')));
        if (! in_array($decision, ['approved', 'rejected', 'edited', 'false_positive', 'false_negative'], true)) {
            throw new \InvalidArgumentException('Invalid review decision provided.');
        }

        $reviewNote = trim((string) ($payload['note'] ?? ''));
        $selectedPolicyVersionId = trim((string) ($payload['selected_policy_version_id'] ?? ''));
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return DB::transaction(function () use ($recommendation, $reviewer, $decision, $reviewNote, $selectedPolicyVersionId, $metadata) {
            $case = BehaviorAnomalyCase::query()->findOrFail($recommendation->anomaly_case_id);

            BehaviorPolicyFeedback::query()->create([
                'recommendation_id' => $recommendation->id,
                'anomaly_case_id' => $recommendation->anomaly_case_id,
                'reviewer_user_id' => $reviewer?->id,
                'decision' => $decision,
                'selected_policy_version_id' => $selectedPolicyVersionId !== '' ? $selectedPolicyVersionId : null,
                'note' => $reviewNote !== '' ? $reviewNote : null,
                'metadata' => $metadata,
            ]);

            $status = match ($decision) {
                'approved' => 'approved',
                'edited' => 'approved',
                'false_negative' => 'approved',
                default => 'rejected',
            };

            $recommendation->status = $status;
            $recommendation->reviewed_by = $reviewer?->id;
            $recommendation->reviewed_at = now();
            $recommendation->review_note = $reviewNote !== '' ? $reviewNote : null;

            $policyVersionId = $selectedPolicyVersionId !== ''
                ? $selectedPolicyVersionId
                : (string) ($recommendation->policy_version_id ?? '');

            if (in_array($decision, ['approved', 'edited', 'false_negative'], true)
                && $recommendation->recommended_action === 'apply_policy'
                && $policyVersionId !== '') {
                $job = $this->policyApplicationService->applyPolicyToDevice(
                    (string) $case->device_id,
                    $policyVersionId,
                    'behavior_pipeline_human_'.($decision === 'edited' ? 'edited' : 'approved'),
                    $reviewer?->id,
                    (string) $case->id,
                    (string) $recommendation->id,
                );
                $recommendation->applied_job_id = $job->id;
                $recommendation->status = 'applied';
                $case->status = 'approved';
            } elseif ($decision === 'false_positive' || $decision === 'rejected') {
                $case->status = 'dismissed';
            } else {
                $case->status = 'approved';
            }

            $case->reviewed_by = $reviewer?->id;
            $case->reviewed_at = now();
            $case->save();
            $recommendation->save();

            return $recommendation->fresh() ?? $recommendation;
        });
    }
}
