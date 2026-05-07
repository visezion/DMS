<?php

namespace App\Domain\Autonomy;

use App\Models\RiskActionMapping;
use Illuminate\Support\Collection;

class RiskActionMapper
{
    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function resolve(string $triggerType, ?string $severity, float $riskScore, ?string $tenantId = null): Collection
    {
        $rows = RiskActionMapping::query()
            ->where('enabled', true)
            ->where('trigger_type', $triggerType)
            ->where(function ($scope) use ($tenantId): void {
                if ($tenantId !== null) {
                    $scope->where('tenant_id', $tenantId)->orWhereNull('tenant_id');

                    return;
                }

                $scope->whereNull('tenant_id');
            })
            ->orderBy('priority')
            ->get();

        return $rows
            ->filter(fn (RiskActionMapping $mapping): bool => $this->matchesSeverity($mapping, $severity))
            ->filter(fn (RiskActionMapping $mapping): bool => $this->matchesRiskScore($mapping, $riskScore))
            ->flatMap(function (RiskActionMapping $mapping): array {
                $candidateActions = is_array($mapping->candidate_actions) ? $mapping->candidate_actions : [];

                return collect($candidateActions)
                    ->map(function (mixed $action, int $index) use ($mapping): array {
                        $row = is_array($action) ? $action : ['action_key' => (string) $action];

                        return [
                            'mapping_id' => $mapping->id,
                            'mapping_name' => $mapping->name,
                            'mapping_priority' => (int) $mapping->priority,
                            'action_priority' => (int) ($row['priority'] ?? ($index + 1)),
                            'action_key' => (string) ($row['action_key'] ?? ''),
                            'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : [],
                            'preconditions' => is_array($row['preconditions'] ?? null) ? $row['preconditions'] : (is_array($mapping->preconditions) ? $mapping->preconditions : []),
                            'rollback_metadata' => is_array($row['rollback_metadata'] ?? null) ? $row['rollback_metadata'] : (is_array($mapping->rollback_metadata) ? $mapping->rollback_metadata : []),
                        ];
                    })->all();
            })
            ->sortBy([
                ['mapping_priority', 'asc'],
                ['action_priority', 'asc'],
            ])
            ->values();
    }

    private function matchesSeverity(RiskActionMapping $mapping, ?string $severity): bool
    {
        if ($severity === null || trim($severity) === '') {
            return true;
        }

        $target = $this->severityRank($severity);
        $minimum = $mapping->minimum_severity ? $this->severityRank((string) $mapping->minimum_severity) : null;
        $maximum = $mapping->maximum_severity ? $this->severityRank((string) $mapping->maximum_severity) : null;

        if ($minimum !== null && $target < $minimum) {
            return false;
        }

        if ($maximum !== null && $target > $maximum) {
            return false;
        }

        return true;
    }

    private function matchesRiskScore(RiskActionMapping $mapping, float $riskScore): bool
    {
        if ($riskScore < (float) $mapping->minimum_risk_score) {
            return false;
        }

        return $mapping->maximum_risk_score === null || $riskScore <= (float) $mapping->maximum_risk_score;
    }

    private function severityRank(string $severity): int
    {
        return match (strtolower(trim($severity))) {
            'info', 'informational' => 10,
            'low' => 25,
            'medium' => 50,
            'high' => 75,
            'critical' => 100,
            default => 40,
        };
    }
}
