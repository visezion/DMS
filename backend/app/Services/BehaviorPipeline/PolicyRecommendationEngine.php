<?php

namespace App\Services\BehaviorPipeline;

use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorPolicyRecommendation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PolicyRecommendationEngine
{
    public function __construct(private readonly BehaviorPipelineSettings $settings)
    {
    }

    /**
     * @return Collection<int,BehaviorPolicyRecommendation>
     */
    public function recommend(BehaviorAnomalyCase $case): Collection
    {
        $risk = (float) $case->risk_score;
        $context = is_array($case->context) ? $case->context : [];
        $features = is_array($context['features'] ?? null) ? $context['features'] : [];
        $eventType = (string) ($features['event_type'] ?? 'unknown');

        $adaptiveModel = $this->settings->adaptiveModel();
        $policyPriors = is_array($adaptiveModel['policy_acceptance'] ?? null)
            ? $adaptiveModel['policy_acceptance']
            : [];

        $candidates = collect();

        $candidates->push([
            'recommended_action' => 'notify',
            'policy_version_id' => null,
            'score' => $this->rankScore($risk, 0.60, 0.85, 0.10),
            'rationale' => [
                'strategy' => 'human_notification',
                'risk_score' => round($risk, 4),
                'expected_risk_reduction' => 0.60,
                'historical_acceptance' => 0.85,
                'operational_cost' => 0.10,
            ],
        ]);

        $candidates->push([
            'recommended_action' => 'observe',
            'policy_version_id' => null,
            'score' => $this->rankScore($risk, 0.20, 0.95, 0.02),
            'rationale' => [
                'strategy' => 'observe_only',
                'risk_score' => round($risk, 4),
                'expected_risk_reduction' => 0.20,
                'historical_acceptance' => 0.95,
                'operational_cost' => 0.02,
            ],
        ]);

        $autoPolicyVersionId = trim((string) $this->settings->settingString('behavior.auto_policy_version_id', ''));
        if ($autoPolicyVersionId !== '') {
            $prior = (float) ($policyPriors[$autoPolicyVersionId] ?? 0.70);
            $impact = $risk >= 0.9 ? 0.95 : 0.75;
            $cost = $risk >= 0.9 ? 0.20 : 0.30;

            $candidates->push([
                'recommended_action' => 'apply_policy',
                'policy_version_id' => $autoPolicyVersionId,
                'score' => $this->rankScore($risk, $impact, $prior, $cost),
                'rationale' => [
                    'strategy' => 'configured_auto_policy',
                    'risk_score' => round($risk, 4),
                    'expected_risk_reduction' => $impact,
                    'historical_acceptance' => $prior,
                    'operational_cost' => $cost,
                ],
            ]);
        }

        $publishedPolicies = DB::table('policy_versions as pv')
            ->join('policies as p', 'p.id', '=', 'pv.policy_id')
            ->where('pv.status', 'published')
            ->select([
                'pv.id as policy_version_id',
                'pv.policy_id',
                'p.category',
                'p.name',
            ])
            ->orderByDesc('pv.published_at')
            ->limit(12)
            ->get();

        foreach ($publishedPolicies as $row) {
            $category = mb_strtolower((string) ($row->category ?? ''));
            $prior = (float) ($policyPriors[$row->policy_version_id] ?? 0.5);

            $impact = 0.55;
            if (str_contains($category, 'security')) {
                $impact += 0.20;
            }
            if ($eventType === 'app_launch' && str_contains($category, 'application')) {
                $impact += 0.15;
            }
            if ($eventType === 'file_access' && str_contains($category, 'data')) {
                $impact += 0.20;
            }

            $impact = max(0.2, min(0.98, $impact));
            $cost = str_contains($category, 'operations') ? 0.35 : 0.22;

            $candidates->push([
                'recommended_action' => 'apply_policy',
                'policy_version_id' => (string) $row->policy_version_id,
                'score' => $this->rankScore($risk, $impact, $prior, $cost),
                'rationale' => [
                    'strategy' => 'published_policy_match',
                    'policy_name' => (string) ($row->name ?? 'unknown'),
                    'policy_category' => (string) ($row->category ?? ''),
                    'risk_score' => round($risk, 4),
                    'expected_risk_reduction' => round($impact, 4),
                    'historical_acceptance' => round($prior, 4),
                    'operational_cost' => round($cost, 4),
                    'event_type' => $eventType,
                ],
            ]);
        }

        $ranked = $candidates
            ->sortByDesc('score')
            ->values()
            ->take(5);

        $persisted = collect();
        foreach ($ranked as $index => $candidate) {
            $recommendation = BehaviorPolicyRecommendation::query()->firstOrNew([
                'anomaly_case_id' => $case->id,
                'rank' => $index + 1,
            ]);
            $recommendation->policy_version_id = $candidate['policy_version_id'];
            $recommendation->recommended_action = $candidate['recommended_action'];
            $recommendation->score = round((float) $candidate['score'], 4);
            $recommendation->rationale = $candidate['rationale'];
            if (! $recommendation->exists) {
                $recommendation->status = 'pending';
            }
            $recommendation->save();

            $persisted->push($recommendation);
        }

        BehaviorPolicyRecommendation::query()
            ->where('anomaly_case_id', $case->id)
            ->where('rank', '>', $persisted->count())
            ->delete();

        return $persisted;
    }

    private function rankScore(float $risk, float $expectedRiskReduction, float $historicalAcceptance, float $operationalCost): float
    {
        $score = ($risk * 0.50)
            + ($expectedRiskReduction * 0.30)
            + ($historicalAcceptance * 0.25)
            - ($operationalCost * 0.20);

        return max(0.0, min(1.0, $score));
    }
}
