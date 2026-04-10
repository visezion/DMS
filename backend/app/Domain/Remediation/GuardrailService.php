<?php

namespace App\Domain\Remediation;

use App\Models\ActionGuardrail;
use App\Models\ControlPlaneSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class GuardrailService
{
    public function __construct(
        private readonly ActionCatalog $catalog
    ) {
    }

    public function evaluate(array $action, ?string $tenantId = null): array
    {
        $actionType = (string) ($action['action_type'] ?? '');
        if (! $this->catalog->has($actionType)) {
            return [
                'allowed' => false,
                'reason' => 'Action type is not allowlisted.',
                'approval_required' => true,
            ];
        }

        $arguments = is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
        $command = strtolower((string) ($arguments['script'] ?? $arguments['command'] ?? ''));
        $runtimeRules = $this->resolveRuleSet($actionType, $tenantId);
        $forbiddenPatterns = collect([
            'remove-item',
            'format',
            'cipher /w',
            'bcdedit',
            'netsh advfirewall set allprofiles state off',
        ])->merge($runtimeRules->pluck('forbidden_patterns')->flatten(1))->filter()->values();

        foreach ($forbiddenPatterns as $pattern) {
            $needle = strtolower(trim((string) $pattern));
            if ($needle !== '' && $command !== '' && str_contains($command, $needle)) {
                return [
                    'allowed' => false,
                    'reason' => 'Action contains a forbidden command pattern.',
                    'approval_required' => true,
                    'matched_pattern' => $pattern,
                ];
            }
        }

        if ($this->isBlockedByKillSwitch($runtimeRules)) {
            return [
                'allowed' => false,
                'reason' => 'Action is blocked by kill switch condition.',
                'approval_required' => true,
            ];
        }

        $targetCount = $this->resolveTargetCount($action);
        $maxTargets = $runtimeRules
            ->map(fn (ActionGuardrail $rule): int => max(1, (int) $rule->max_targets))
            ->min();
        if (is_int($maxTargets) && $targetCount > $maxTargets) {
            return [
                'allowed' => false,
                'reason' => 'Action exceeds max_targets guardrail.',
                'approval_required' => true,
                'max_targets' => $maxTargets,
                'target_count' => $targetCount,
            ];
        }

        $requiresRollbackPlan = $runtimeRules->contains(fn (ActionGuardrail $rule): bool => (bool) $rule->requires_rollback_plan);
        if ($requiresRollbackPlan && ! $this->hasRollbackPlan($arguments)) {
            return [
                'allowed' => false,
                'reason' => 'Action requires rollback plan metadata.',
                'approval_required' => true,
            ];
        }

        $meta = $this->catalog->get($actionType);

        return [
            'allowed' => true,
            'reason' => 'Action satisfied current guardrail checks.',
            'approval_required' => (bool) ($meta['approval_required'] ?? true),
            'risk_level' => (string) ($meta['risk'] ?? 'medium'),
            'max_targets' => $maxTargets,
            'target_count' => $targetCount,
            'requires_rollback_plan' => $requiresRollbackPlan,
            'guardrail_ids' => $runtimeRules->pluck('id')->values()->all(),
        ];
    }

    /**
     * @return Collection<int,ActionGuardrail>
     */
    private function resolveRuleSet(string $actionType, ?string $tenantId): Collection
    {
        $query = ActionGuardrail::query()
            ->where('action_type', $actionType)
            ->where('active', true)
            ->where(function ($scope) use ($tenantId): void {
                if ($tenantId !== null) {
                    $scope->where('tenant_id', $tenantId)->orWhereNull('tenant_id');

                    return;
                }

                $scope->whereNull('tenant_id');
            });

        return $query->orderByDesc('tenant_id')->orderByDesc('version')->get();
    }

    /**
     * @param  array<string,mixed>  $action
     */
    private function resolveTargetCount(array $action): int
    {
        $scope = is_array($action['target_scope'] ?? null) ? $action['target_scope'] : [];
        $ids = Arr::wrap($scope['ids'] ?? []);
        if ($ids !== []) {
            return max(1, count(array_filter($ids, fn ($id): bool => trim((string) $id) !== '')));
        }

        if (! empty($scope['id'])) {
            return 1;
        }

        return 1;
    }

    /**
     * @param  Collection<int,ActionGuardrail>  $rules
     */
    private function isBlockedByKillSwitch(Collection $rules): bool
    {
        $killSwitchRequired = $rules
            ->pluck('deny_conditions')
            ->filter(fn (mixed $value): bool => is_array($value))
            ->contains(fn (array $conditions): bool => (bool) ($conditions['kill_switch'] ?? false));

        if (! $killSwitchRequired) {
            return false;
        }

        $setting = ControlPlaneSetting::query()->find('jobs.kill_switch');
        if (! $setting || ! is_array($setting->value)) {
            return false;
        }

        return (bool) ($setting->value['value'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    private function hasRollbackPlan(array $arguments): bool
    {
        return trim((string) ($arguments['rollback_command'] ?? '')) !== ''
            || is_array($arguments['rollback_payload'] ?? null)
            || trim((string) ($arguments['rollback_job_type'] ?? '')) !== '';
    }
}
