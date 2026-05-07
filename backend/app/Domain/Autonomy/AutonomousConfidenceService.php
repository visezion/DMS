<?php

namespace App\Domain\Autonomy;

class AutonomousConfidenceService
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $policyResolution
     * @return array{confidence_score:float,factors:array<int,array<string,mixed>>,summary:string}
     */
    public function score(array $context, array $candidate, array $policyResolution): array
    {
        $severityValue = $this->severityValue((string) ($context['severity'] ?? 'medium'));
        $riskValue = max(0.0, min(100.0, (float) ($context['risk_score'] ?? 0)));
        $telemetryValue = max(0.0, min(100.0, (float) ($context['telemetry_corroboration'] ?? 0)));

        $historicalSuccessCount = (int) ($context['previous_remediation_success'] ?? 0);
        $historicalFailureCount = (int) ($context['previous_remediation_failure'] ?? 0);
        $historicalValue = ($historicalSuccessCount + $historicalFailureCount) === 0
            ? 70.0
            : round(($historicalSuccessCount / max(1, ($historicalSuccessCount + $historicalFailureCount))) * 100, 2);

        $actionDefinition = is_array($candidate['definition'] ?? null) ? $candidate['definition'] : [];
        $criticalityValue = $this->criticalityValue(
            (string) ($context['device_criticality'] ?? 'medium'),
            (string) ($actionDefinition['safety_class'] ?? 'moderate')
        );
        $policyAlignmentValue = $this->policyAlignmentValue($policyResolution, (string) ($candidate['action_key'] ?? ''));
        $actionSafetyValue = $this->actionSafetyValue((string) ($actionDefinition['safety_class'] ?? 'moderate'));
        $patternMatchValue = $candidate['mapping_id'] ? 95.0 : 70.0;

        $factors = [
            ['name' => 'severity', 'weight' => 0.10, 'value' => $severityValue],
            ['name' => 'risk_score', 'weight' => 0.25, 'value' => $riskValue],
            ['name' => 'telemetry_match', 'weight' => 0.20, 'value' => $telemetryValue],
            ['name' => 'historical_success', 'weight' => 0.15, 'value' => $historicalValue],
            ['name' => 'device_criticality_compatibility', 'weight' => 0.10, 'value' => $criticalityValue],
            ['name' => 'policy_alignment', 'weight' => 0.10, 'value' => $policyAlignmentValue],
            ['name' => 'action_safety', 'weight' => 0.05, 'value' => $actionSafetyValue],
            ['name' => 'incident_pattern_match', 'weight' => 0.05, 'value' => $patternMatchValue],
        ];

        $score = 0.0;
        foreach ($factors as $factor) {
            $score += ((float) $factor['weight']) * ((float) $factor['value']);
        }

        $confidence = round(max(0.0, min(100.0, $score)), 2);

        return [
            'confidence_score' => $confidence,
            'factors' => $factors,
            'summary' => sprintf(
                'Confidence %.2f because %s is elevated, telemetry corroboration is %.0f, and %s is %s with current policy alignment %.0f.',
                $confidence,
                strtolower((string) ($context['trigger_type'] ?? 'risk')),
                $telemetryValue,
                (string) ($candidate['action_key'] ?? 'action'),
                strtolower((string) ($actionDefinition['safety_class'] ?? 'moderate')),
                $policyAlignmentValue
            ),
        ];
    }

    private function severityValue(string $severity): float
    {
        return match (strtolower(trim($severity))) {
            'critical' => 100.0,
            'high' => 85.0,
            'medium' => 65.0,
            'low' => 40.0,
            default => 55.0,
        };
    }

    private function criticalityValue(string $criticality, string $safetyClass): float
    {
        $criticality = strtolower(trim($criticality));
        $safetyClass = strtolower(trim($safetyClass));

        if ($criticality === 'high' && in_array($safetyClass, ['high', 'destructive'], true)) {
            return 45.0;
        }

        if ($criticality === 'high') {
            return 75.0;
        }

        if ($criticality === 'low') {
            return 90.0;
        }

        return 80.0;
    }

    /**
     * @param  array<string,mixed>  $policyResolution
     */
    private function policyAlignmentValue(array $policyResolution, string $actionKey): float
    {
        if (in_array($actionKey, (array) ($policyResolution['blocked_actions'] ?? []), true)) {
            return 0.0;
        }

        $allowed = (array) ($policyResolution['allowed_actions'] ?? []);
        if ($allowed !== [] && ! in_array($actionKey, $allowed, true)) {
            return 35.0;
        }

        return 100.0;
    }

    private function actionSafetyValue(string $safetyClass): float
    {
        return match (strtolower(trim($safetyClass))) {
            'safe' => 100.0,
            'moderate' => 82.0,
            'high' => 62.0,
            'destructive' => 35.0,
            default => 60.0,
        };
    }
}
