<?php

namespace App\Domain\Autonomy;

use Illuminate\Support\Facades\Http;

class AutonomousAiAdvisor
{
    /**
     * @param  array<int,array<string,mixed>>  $scoredCandidates
     * @return array{recommended_action:?array<string,mixed>,alternative_actions:array<int,array<string,mixed>>,confidence_score:float,rationale:string,explanation:array<string,mixed>,eligible_for_auto_execute:bool}
     */
    public function rank(array $context, array $scoredCandidates): array
    {
        $endpoint = trim((string) config('autonomous_response.ai.endpoint'));
        $driver = trim((string) config('autonomous_response.ai.driver', 'local'));

        if ($driver === 'http' && $endpoint !== '') {
            $remote = $this->callRemoteAdvisor($endpoint, $context, $scoredCandidates);
            if ($remote !== null) {
                return $remote;
            }
        }

        usort($scoredCandidates, function (array $left, array $right): int {
            return (int) (($right['decision_score'] ?? 0) <=> ($left['decision_score'] ?? 0));
        });

        $recommended = $scoredCandidates[0] ?? null;
        $alternatives = array_values(array_slice($scoredCandidates, 1, 3));
        $confidence = (float) ($recommended['confidence_score'] ?? 0);
        $factors = is_array($recommended['factors'] ?? null) ? $recommended['factors'] : [];

        return [
            'recommended_action' => $recommended,
            'alternative_actions' => $alternatives,
            'confidence_score' => $confidence,
            'rationale' => (string) ($recommended['summary'] ?? 'Local ranking selected the highest-confidence action.'),
            'explanation' => [
                'driver' => 'local',
                'factors' => $factors,
                'ranking_basis' => 'weighted_confidence_and_priority',
            ],
            'eligible_for_auto_execute' => (bool) ($recommended['eligible_for_auto_execute'] ?? false),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $scoredCandidates
     * @return array<string,mixed>|null
     */
    private function callRemoteAdvisor(string $endpoint, array $context, array $scoredCandidates): ?array
    {
        try {
            $response = Http::retry(
                max(0, (int) config('autonomous_response.ai.retry_times', 1)),
                150
            )->timeout(
                max(1, (int) config('autonomous_response.ai.timeout_seconds', 4))
            )->post($endpoint, [
                'context' => $context,
                'candidates' => $scoredCandidates,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_key_exists('recommended_action', $payload)) {
                return null;
            }

            return [
                'recommended_action' => is_array($payload['recommended_action'] ?? null) ? $payload['recommended_action'] : null,
                'alternative_actions' => is_array($payload['alternative_actions'] ?? null) ? $payload['alternative_actions'] : [],
                'confidence_score' => (float) ($payload['confidence_score'] ?? 0),
                'rationale' => (string) ($payload['rationale'] ?? 'Remote advisor returned a ranked decision.'),
                'explanation' => is_array($payload['explanation'] ?? null) ? $payload['explanation'] : [],
                'eligible_for_auto_execute' => (bool) ($payload['eligible_for_auto_execute'] ?? false),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
