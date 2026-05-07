<?php

namespace App\Domain\Autonomy;

use App\Domain\Autonomy\Enums\AutonomousDecisionMode;
use App\Models\AutonomyPolicy;
use App\Models\AutonomousResponsePolicy;
use Illuminate\Support\Facades\DB;

class AutonomousResponsePolicyResolver
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function resolve(array $context): array
    {
        $tenantId = $context['tenant_id'] ?? null;
        $triggerType = (string) ($context['trigger_type'] ?? 'any');
        $scopeCandidates = $this->scopeCandidates($context);

        $matchedPolicies = collect();
        $primaryPolicy = null;

        foreach ($scopeCandidates as $candidate) {
            $rows = AutonomousResponsePolicy::query()
                ->where('enabled', true)
                ->where('scope_type', $candidate['scope_type'])
                ->where(function ($query) use ($candidate): void {
                    if ($candidate['scope_id'] === null) {
                        $query->whereNull('scope_id');

                        return;
                    }

                    $query->where('scope_id', $candidate['scope_id']);
                })
                ->where(function ($query) use ($triggerType): void {
                    $query->where('trigger_type', $triggerType)->orWhere('trigger_type', 'any');
                })
                ->where(function ($scope) use ($tenantId): void {
                    if ($tenantId !== null) {
                        $scope->where('tenant_id', $tenantId)->orWhereNull('tenant_id');

                        return;
                    }

                    $scope->whereNull('tenant_id');
                })
                ->orderByDesc('tenant_id')
                ->latest('updated_at')
                ->get();

            if ($rows->isNotEmpty() && ! $primaryPolicy) {
                $primaryPolicy = $rows->first();
            }

            $matchedPolicies = $matchedPolicies->merge($rows);
        }

        $legacyPolicy = $this->resolveLegacyPolicy($context);
        $blockedActions = $matchedPolicies
            ->flatMap(fn (AutonomousResponsePolicy $policy): array => is_array($policy->blocked_actions) ? $policy->blocked_actions : [])
            ->map(fn (mixed $value): string => (string) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $allowedActions = is_array($primaryPolicy?->allowed_actions) ? $primaryPolicy->allowed_actions : [];
        $legacyAllowedActions = is_array($legacyPolicy?->allowed_actions) ? $legacyPolicy->allowed_actions : [];

        return [
            'policy' => $primaryPolicy,
            'matched_policies' => $matchedPolicies->values(),
            'legacy_policy' => $legacyPolicy,
            'autonomy_mode' => (string) ($primaryPolicy?->autonomy_mode ?: $this->mapLegacyMode($legacyPolicy?->autonomy_level)),
            'minimum_confidence' => (float) ($primaryPolicy?->minimum_confidence ?? config('autonomous_response.default_confidence', 70)),
            'minimum_risk_score' => (float) ($primaryPolicy?->minimum_risk_score ?? 0),
            'max_actions_per_hour' => (int) ($primaryPolicy?->max_actions_per_hour ?? config('autonomous_response.max_actions_per_device_per_hour', 3)),
            'cooldown_minutes' => (int) ($primaryPolicy?->cooldown_minutes ?? 30),
            'requires_rollback_plan' => (bool) ($primaryPolicy?->requires_rollback_plan ?? false),
            'allowed_actions' => $allowedActions,
            'blocked_actions' => $blockedActions,
            'legacy_allowed_actions' => $legacyAllowedActions,
            'legacy_blocked_conditions' => is_array($legacyPolicy?->blocked_conditions) ? $legacyPolicy->blocked_conditions : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array{scope_type:string,scope_id:string|null}>
     */
    private function scopeCandidates(array $context): array
    {
        $candidates = [];
        if (! empty($context['device_id'])) {
            $candidates[] = ['scope_type' => 'device', 'scope_id' => (string) $context['device_id']];
        }

        foreach ((array) ($context['device_group_ids'] ?? []) as $groupId) {
            $groupId = trim((string) $groupId);
            if ($groupId !== '') {
                $candidates[] = ['scope_type' => 'group', 'scope_id' => $groupId];
            }
        }

        if (! empty($context['finding_type'])) {
            $candidates[] = ['scope_type' => 'finding_type', 'scope_id' => (string) $context['finding_type']];
        }

        if (! empty($context['incident_type'])) {
            $candidates[] = ['scope_type' => 'incident_type', 'scope_id' => (string) $context['incident_type']];
        }

        if (! empty($context['tenant_id'])) {
            $candidates[] = ['scope_type' => 'tenant', 'scope_id' => (string) $context['tenant_id']];
        }

        $candidates[] = ['scope_type' => 'global', 'scope_id' => null];

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function resolveLegacyPolicy(array $context): ?AutonomyPolicy
    {
        $tenantId = $context['tenant_id'] ?? null;
        $candidates = [];
        if (! empty($context['device_id'])) {
            $candidates[] = ['scope_type' => 'device', 'scope_id' => (string) $context['device_id']];
        }
        foreach ((array) ($context['device_group_ids'] ?? []) as $groupId) {
            $candidates[] = ['scope_type' => 'group', 'scope_id' => (string) $groupId];
        }
        if (! empty($tenantId)) {
            $candidates[] = ['scope_type' => 'tenant', 'scope_id' => (string) $tenantId];
        }
        $candidates[] = ['scope_type' => 'global', 'scope_id' => 'global'];

        foreach ($candidates as $candidate) {
            $policy = AutonomyPolicy::query()
                ->where('active', true)
                ->where('scope_type', $candidate['scope_type'])
                ->where('scope_id', $candidate['scope_id'])
                ->where(function ($scope) use ($tenantId): void {
                    if ($tenantId !== null) {
                        $scope->where('tenant_id', $tenantId)->orWhereNull('tenant_id');

                        return;
                    }

                    $scope->whereNull('tenant_id');
                })
                ->orderByDesc('tenant_id')
                ->latest('updated_at')
                ->first();

            if ($policy) {
                return $policy;
            }
        }

        return null;
    }

    private function mapLegacyMode(?string $legacyLevel): string
    {
        return match (strtolower((string) $legacyLevel)) {
            'auto' => AutonomousDecisionMode::AUTO_EXECUTE,
            'semi_auto' => AutonomousDecisionMode::APPROVAL_REQUIRED,
            'off', 'advisory', '' => AutonomousDecisionMode::RECOMMEND_ONLY,
            default => AutonomousDecisionMode::RECOMMEND_ONLY,
        };
    }
}
