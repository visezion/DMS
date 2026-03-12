<?php

namespace App\Services\BehaviorPipeline;

use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorAnomalySignal;
use App\Models\BehaviorPolicyFeedback;
use App\Models\BehaviorPolicyRecommendation;
use App\Models\ControlPlaneSetting;
use Illuminate\Support\Facades\Storage;

class AdaptiveLearningService
{
    /**
     * @return array<string,mixed>
     */
    public function retrain(int $windowDays = 45, int $minFeedback = 20): array
    {
        $windowDays = max(7, min(365, $windowDays));
        $minFeedback = max(5, min(100000, $minFeedback));

        $feedbackRows = BehaviorPolicyFeedback::query()
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('created_at')
            ->get();

        if ($feedbackRows->count() < $minFeedback) {
            throw new \RuntimeException(
                "Insufficient feedback for adaptive retraining. Need {$minFeedback}, found {$feedbackRows->count()}."
            );
        }

        $latestByCase = $feedbackRows->unique('anomaly_case_id')->values();
        $caseIds = $latestByCase->pluck('anomaly_case_id')->filter()->values();

        $signals = BehaviorAnomalySignal::query()
            ->whereIn('anomaly_case_id', $caseIds)
            ->get(['anomaly_case_id', 'detector_key', 'score', 'confidence']);

        $detectorStats = [];
        foreach ($signals as $signal) {
            $feedback = $latestByCase->firstWhere('anomaly_case_id', $signal->anomaly_case_id);
            if (! $feedback) {
                continue;
            }

            $decision = strtolower((string) $feedback->decision);
            $positive = in_array($decision, ['approved', 'edited', 'false_negative'], true);
            $quality = $positive ? (float) $signal->score : (1.0 - (float) $signal->score);
            $confidence = max(0.1, min(1.0, (float) $signal->confidence));
            $weightedQuality = $quality * $confidence;

            $key = (string) $signal->detector_key;
            if (! isset($detectorStats[$key])) {
                $detectorStats[$key] = ['sum' => 0.0, 'n' => 0];
            }

            $detectorStats[$key]['sum'] += $weightedQuality;
            $detectorStats[$key]['n']++;
        }

        $detectorWeights = [];
        foreach ($detectorStats as $key => $stat) {
            $n = (int) $stat['n'];
            if ($n <= 0) {
                continue;
            }

            $detectorWeights[$key] = max(0.05, min(1.0, ((float) $stat['sum']) / $n));
        }

        if ($detectorWeights === []) {
            $detectorWeights = [
                'statistical_ensemble' => 0.55,
                'off_hours_profile' => 0.25,
                'rare_process_on_device' => 0.20,
            ];
        }

        $detectorWeights = $this->normalizeWeights($detectorWeights);

        $positiveCaseIds = $latestByCase
            ->filter(fn (BehaviorPolicyFeedback $f) => in_array(strtolower((string) $f->decision), ['approved', 'edited', 'false_negative'], true))
            ->pluck('anomaly_case_id')
            ->filter()
            ->values();

        $threshold = 0.82;
        if ($positiveCaseIds->isNotEmpty()) {
            $scores = BehaviorAnomalyCase::query()
                ->whereIn('id', $positiveCaseIds)
                ->pluck('risk_score')
                ->map(fn ($value) => (float) $value)
                ->sort()
                ->values();

            if ($scores->isNotEmpty()) {
                $index = max(0, (int) floor(($scores->count() - 1) * 0.25));
                $threshold = max(0.5, min(0.99, (float) $scores[$index]));
            }
        }

        $recommendations = BehaviorPolicyRecommendation::query()
            ->whereIn('id', $feedbackRows->pluck('recommendation_id')->filter()->values())
            ->get(['id', 'policy_version_id']);

        $recommendationPolicyMap = $recommendations->pluck('policy_version_id', 'id');
        $policyStats = [];
        foreach ($feedbackRows as $feedback) {
            $policyVersionId = trim((string) ($feedback->selected_policy_version_id ?: ($recommendationPolicyMap[$feedback->recommendation_id] ?? '')));
            if ($policyVersionId === '') {
                continue;
            }

            $decision = strtolower((string) $feedback->decision);
            $accepted = in_array($decision, ['approved', 'edited', 'false_negative'], true);

            if (! isset($policyStats[$policyVersionId])) {
                $policyStats[$policyVersionId] = ['accepted' => 0, 'total' => 0];
            }

            $policyStats[$policyVersionId]['total']++;
            if ($accepted) {
                $policyStats[$policyVersionId]['accepted']++;
            }
        }

        $policyAcceptance = [];
        foreach ($policyStats as $policyVersionId => $stat) {
            $total = max(1, (int) $stat['total']);
            $policyAcceptance[$policyVersionId] = round(((int) $stat['accepted']) / $total, 4);
        }

        $payload = [
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'window_days' => $windowDays,
            'feedback_samples' => $feedbackRows->count(),
            'labeled_cases' => $latestByCase->count(),
            'detector_weights' => $detectorWeights,
            'recommended_threshold' => round($threshold, 4),
            'policy_acceptance' => $policyAcceptance,
        ];

        Storage::disk('local')->put(
            'behavior_models/adaptive-learning.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.pipeline.last_retrained_at'],
            ['value' => ['value' => (string) $payload['generated_at']], 'updated_by' => null]
        );
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.ai_threshold'],
            ['value' => ['value' => (string) $payload['recommended_threshold']], 'updated_by' => null]
        );

        return $payload;
    }

    /**
     * @param array<string,float> $weights
     * @return array<string,float>
     */
    private function normalizeWeights(array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return $weights;
        }

        foreach ($weights as $key => $value) {
            $weights[$key] = round(max(0.01, $value / $sum), 4);
        }

        return $weights;
    }
}
