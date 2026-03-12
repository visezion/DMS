<?php

namespace App\Services\BehaviorPipeline;

use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorPolicyFeedback;
use App\Models\BehaviorPolicyRecommendation;

class BehaviorPolicyDecisionService
{
    public function __construct(
        private readonly BehaviorPipelineSettings $settings,
        private readonly PolicyApplicationService $policyApplicationService,
    ) {
    }

    public function maybeAutoApply(BehaviorAnomalyCase $case): ?BehaviorPolicyRecommendation
    {
        $autoApplyEnabled = $this->settings->settingBool('behavior.pipeline.auto_apply_enabled', false);
        if (! $autoApplyEnabled) {
            return null;
        }

        $minimumScore = $this->settings->settingFloat('behavior.pipeline.auto_apply_min_score', 0.92);
        $topRecommendation = BehaviorPolicyRecommendation::query()
            ->where('anomaly_case_id', $case->id)
            ->where('recommended_action', 'apply_policy')
            ->orderByDesc('score')
            ->orderBy('rank')
            ->first();

        if (! $topRecommendation) {
            return null;
        }

        if ((float) $topRecommendation->score < $minimumScore) {
            return null;
        }

        $policyVersionId = trim((string) ($topRecommendation->policy_version_id ?? ''));
        if ($policyVersionId === '') {
            return null;
        }

        $job = $this->policyApplicationService->applyPolicyToDevice(
            (string) $case->device_id,
            $policyVersionId,
            'behavior_pipeline_auto_apply',
            null,
            (string) $case->id,
            (string) $topRecommendation->id,
        );

        $topRecommendation->status = 'auto_applied';
        $topRecommendation->applied_job_id = $job->id;
        $topRecommendation->reviewed_at = now();
        $topRecommendation->save();

        BehaviorPolicyFeedback::query()->create([
            'recommendation_id' => $topRecommendation->id,
            'anomaly_case_id' => $case->id,
            'reviewer_user_id' => null,
            'decision' => 'approved',
            'selected_policy_version_id' => $policyVersionId,
            'note' => 'Policy auto-applied by confidence threshold',
            'metadata' => [
                'auto_applied' => true,
                'minimum_score' => $minimumScore,
                'recommendation_score' => (float) $topRecommendation->score,
            ],
        ]);

        $case->status = 'auto_applied';
        $case->reviewed_at = now();
        $case->save();

        return $topRecommendation;
    }
}
