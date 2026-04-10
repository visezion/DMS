<?php

namespace App\Domain\Health;

class HealthScoringService
{
    public function score(array $metrics): array
    {
        $score = 100.0;
        $components = [];
        $contributors = [];

        $applyPenalty = function (string $key, float $weight, float $value, float $threshold, float $maxPenalty, string $label) use (&$score, &$components, &$contributors): void {
            $penalty = 0.0;
            if ($value > $threshold) {
                $penalty = min($maxPenalty, round((($value - $threshold) / max(1, (100 - $threshold))) * $maxPenalty, 2));
                $score -= $penalty;
            }

            $components[$key] = [
                'label' => $label,
                'weight' => $weight,
                'value' => round($value, 2),
                'threshold' => $threshold,
                'penalty' => $penalty,
            ];

            if ($penalty > 0) {
                $contributors[] = [
                    'factor' => $key,
                    'label' => $label,
                    'impact' => $penalty,
                    'value' => round($value, 2),
                ];
            }
        };

        $applyPenalty('cpu', 0.12, (float) ($metrics['cpu_usage_percent'] ?? 0), 75, 12, 'Sustained CPU pressure');
        $applyPenalty('memory', 0.12, (float) ($metrics['memory_usage_percent'] ?? 0), 80, 12, 'Memory pressure');

        $diskFree = (float) ($metrics['disk_free_percent'] ?? 0);
        $diskPenalty = $diskFree < 20 ? round(((20 - $diskFree) / 20) * 16, 2) : 0.0;
        $score -= $diskPenalty;
        $components['disk'] = [
            'label' => 'System drive free space',
            'weight' => 0.14,
            'value' => $diskFree,
            'threshold' => 20,
            'penalty' => $diskPenalty,
        ];
        if ($diskPenalty > 0) {
            $contributors[] = ['factor' => 'disk', 'label' => 'System drive free space', 'impact' => $diskPenalty, 'value' => $diskFree];
        }

        $crashPenalty = min(18, ((int) ($metrics['crash_count_7d'] ?? 0)) * 4.5);
        $score -= $crashPenalty;
        $components['crashes'] = [
            'label' => 'Crash frequency',
            'weight' => 0.18,
            'value' => (int) ($metrics['crash_count_7d'] ?? 0),
            'threshold' => 0,
            'penalty' => $crashPenalty,
        ];
        if ($crashPenalty > 0) {
            $contributors[] = ['factor' => 'crashes', 'label' => 'Crash frequency', 'impact' => $crashPenalty, 'value' => (int) ($metrics['crash_count_7d'] ?? 0)];
        }

        $servicePenalty = min(12, ((int) ($metrics['service_failures_24h'] ?? 0)) * 3);
        $score -= $servicePenalty;
        $components['service_failures'] = [
            'label' => 'Service failures',
            'weight' => 0.12,
            'value' => (int) ($metrics['service_failures_24h'] ?? 0),
            'threshold' => 0,
            'penalty' => $servicePenalty,
        ];
        if ($servicePenalty > 0) {
            $contributors[] = ['factor' => 'service_failures', 'label' => 'Service failures', 'impact' => $servicePenalty, 'value' => (int) ($metrics['service_failures_24h'] ?? 0)];
        }

        $patchPenalty = min(10, ((int) ($metrics['patch_gap_count'] ?? 0)) * 2);
        $score -= $patchPenalty;
        $components['patch_gaps'] = [
            'label' => 'Update failures or gaps',
            'weight' => 0.10,
            'value' => (int) ($metrics['patch_gap_count'] ?? 0),
            'threshold' => 0,
            'penalty' => $patchPenalty,
        ];
        if ($patchPenalty > 0) {
            $contributors[] = ['factor' => 'patch_gaps', 'label' => 'Update failures or gaps', 'impact' => $patchPenalty, 'value' => (int) ($metrics['patch_gap_count'] ?? 0)];
        }

        $thermalPenalty = max(
            (float) ((float) ($metrics['thermal_state_percent'] ?? 0) > 85 ? 6 : 0),
            (float) ((float) ($metrics['battery_health_percent'] ?? 100) < 55 ? 5 : 0)
        );
        $score -= $thermalPenalty;
        $components['thermal_battery'] = [
            'label' => 'Thermal or battery stress',
            'weight' => 0.08,
            'value' => [
                'thermal_state_percent' => (float) ($metrics['thermal_state_percent'] ?? 0),
                'battery_health_percent' => (float) ($metrics['battery_health_percent'] ?? 0),
            ],
            'threshold' => ['thermal' => 85, 'battery' => 55],
            'penalty' => $thermalPenalty,
        ];
        if ($thermalPenalty > 0) {
            $contributors[] = ['factor' => 'thermal_battery', 'label' => 'Thermal or battery stress', 'impact' => $thermalPenalty, 'value' => $components['thermal_battery']['value']];
        }

        $rebootPenalty = min(8, ((int) ($metrics['unexpected_shutdowns_7d'] ?? 0)) * 4);
        $score -= $rebootPenalty;
        $components['reboots'] = [
            'label' => 'Unexpected shutdowns',
            'weight' => 0.08,
            'value' => (int) ($metrics['unexpected_shutdowns_7d'] ?? 0),
            'threshold' => 0,
            'penalty' => $rebootPenalty,
        ];
        if ($rebootPenalty > 0) {
            $contributors[] = ['factor' => 'reboots', 'label' => 'Unexpected shutdowns', 'impact' => $rebootPenalty, 'value' => (int) ($metrics['unexpected_shutdowns_7d'] ?? 0)];
        }

        usort($contributors, fn (array $left, array $right) => $right['impact'] <=> $left['impact']);

        $score = max(0, min(100, round($score, 2)));
        $band = $score >= 80 ? 'healthy' : ($score >= 60 ? 'warning' : ($score >= 40 ? 'degraded' : 'critical'));

        return [
            'score' => $score,
            'band' => $band,
            'component_scores' => $components,
            'contributors' => array_slice($contributors, 0, 5),
            'predicted_failure_risk' => min(
                100,
                round(
                    ((100 - $score) * 0.65)
                    + (((int) ($metrics['crash_count_7d'] ?? 0)) * 6)
                    + (((int) ($metrics['service_failures_24h'] ?? 0)) * 3),
                    2
                )
            ),
        ];
    }
}
