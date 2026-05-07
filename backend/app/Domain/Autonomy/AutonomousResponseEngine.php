<?php

namespace App\Domain\Autonomy;

use App\Domain\Autonomy\Enums\AutonomousDecisionMode;
use App\Domain\Autonomy\Enums\AutonomousDecisionStatus;
use App\Domain\Remediation\ActionCatalog;
use App\Models\ApprovalRequest;
use App\Models\AutonomousDecision;
use App\Models\ConfidenceEvidence;
use App\Models\ControlPlaneSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutonomousResponseEngine
{
    public function __construct(
        private readonly AutonomousContextBuilder $contextBuilder,
        private readonly RiskActionMapper $mapper,
        private readonly AutonomousResponsePolicyResolver $policyResolver,
        private readonly AutonomousConfidenceService $confidenceService,
        private readonly AutonomousAiAdvisor $advisor,
        private readonly ActionCatalog $catalog,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param  array<string,mixed>  $trigger
     * @return AutonomousDecision|array<string,mixed>
     */
    public function evaluate(array $trigger, ?User $actor = null, bool $persist = true): AutonomousDecision|array
    {
        $context = $this->contextBuilder->build($trigger);
        $policy = $this->policyResolver->resolve($context);
        $mappedCandidates = $this->mapper->resolve(
            (string) $context['trigger_type'],
            (string) $context['severity'],
            (float) $context['risk_score'],
            $context['tenant_id'] ?? null
        );

        $filteredCandidates = $this->filterCandidates($mappedCandidates, $context, $policy);
        $noEligibleCandidates = $mappedCandidates->isNotEmpty() && $filteredCandidates->isEmpty();
        $blockedActions = collect((array) ($policy['blocked_actions'] ?? []))->map(fn (mixed $value): string => (string) $value);
        $allowedActions = collect((array) ($policy['allowed_actions'] ?? []))->map(fn (mixed $value): string => (string) $value);
        $legacyAllowedActions = collect((array) ($policy['legacy_allowed_actions'] ?? []))->map(fn (mixed $value): string => (string) $value);
        $blockedByPolicyOnly = $mappedCandidates->isNotEmpty()
            && $mappedCandidates->every(function (array $candidate) use ($blockedActions, $allowedActions, $legacyAllowedActions): bool {
                $actionKey = (string) ($candidate['action_key'] ?? '');

                if ($actionKey === '') {
                    return true;
                }

                if ($blockedActions->contains($actionKey)) {
                    return true;
                }

                if ($allowedActions->isNotEmpty() && ! $allowedActions->contains($actionKey)) {
                    return true;
                }

                return $legacyAllowedActions->isNotEmpty() && ! $legacyAllowedActions->contains($actionKey);
            });
        if ($filteredCandidates->isEmpty()) {
            $filteredCandidates = collect([
                [
                    'mapping_id' => null,
                    'mapping_name' => 'fallback',
                    'mapping_priority' => 999,
                    'action_priority' => 1,
                    'action_key' => 'require_manual_investigation',
                    'payload' => [],
                    'preconditions' => [],
                    'rollback_metadata' => [],
                ],
            ]);
        }

        $scoredCandidates = $filteredCandidates
            ->map(function (array $candidate) use ($context, $policy): array {
                $definition = $this->catalog->get((string) $candidate['action_key']);
                $score = $this->confidenceService->score($context, array_merge($candidate, ['definition' => $definition]), $policy);

                return array_merge($candidate, $score, [
                    'definition' => $definition,
                    'decision_score' => ((float) $score['confidence_score']) + max(0, 15 - (int) $candidate['action_priority']),
                    'eligible_for_auto_execute' => ($definition['execution_strategy'] ?? 'job') === 'job',
                ]);
            })
            ->values()
            ->all();

        $ranking = $this->advisor->rank($context, $scoredCandidates);
        $recommended = is_array($ranking['recommended_action'] ?? null) ? $ranking['recommended_action'] : null;
        $recommendedKey = (string) ($recommended['action_key'] ?? '');
        $recommendedDefinition = is_array($recommended['definition'] ?? null) ? $recommended['definition'] : $this->catalog->get($recommendedKey);
        $blocked = $recommendedKey !== '' && in_array($recommendedKey, (array) ($policy['blocked_actions'] ?? []), true);
        $killSwitch = $this->killSwitchEnabled();
        $autonomyPaused = $this->autonomyPaused();
        $minimumConfidence = (float) ($policy['minimum_confidence'] ?? config('autonomous_response.default_confidence', 70));
        $minimumRiskScore = (float) ($policy['minimum_risk_score'] ?? 0);

        $mode = AutonomousDecisionMode::RECOMMEND_ONLY;
        $status = AutonomousDecisionStatus::GENERATED;
        $failureReason = null;

        if ($blockedByPolicyOnly) {
            $failureReason = 'Action blocked by autonomous response policy.';
        } elseif ($noEligibleCandidates) {
            $failureReason = 'No candidate action survived policy and safety filtering.';
        } elseif ($blocked) {
            $failureReason = 'Action blocked by autonomous response policy.';
        } elseif ($killSwitch) {
            $failureReason = 'Kill switch is enabled. Automatic execution is paused.';
        } elseif ($autonomyPaused) {
            $failureReason = 'Autonomous response is paused in settings.';
        } elseif (((float) ($ranking['confidence_score'] ?? 0)) < $minimumConfidence) {
            $failureReason = 'Confidence is below the minimum autonomous threshold.';
        } elseif ((float) ($context['risk_score'] ?? 0) < $minimumRiskScore) {
            $failureReason = 'Risk score is below policy minimum.';
        } else {
            $mode = (string) ($policy['autonomy_mode'] ?? AutonomousDecisionMode::RECOMMEND_ONLY);
            if (($recommendedDefinition['recommended_approval_mode'] ?? AutonomousDecisionMode::APPROVAL_REQUIRED) === AutonomousDecisionMode::APPROVAL_REQUIRED
                && in_array($mode, [AutonomousDecisionMode::AUTO_EXECUTE], true)) {
                $mode = AutonomousDecisionMode::APPROVAL_REQUIRED;
            }
            if (($recommendedDefinition['safety_class'] ?? 'moderate') === 'destructive') {
                $mode = AutonomousDecisionMode::APPROVAL_REQUIRED;
            }
        }

        if ($mode === AutonomousDecisionMode::APPROVAL_REQUIRED) {
            $status = AutonomousDecisionStatus::PENDING_APPROVAL;
        } elseif ($mode === AutonomousDecisionMode::AUTO_EXECUTE && ! ($trigger['simulation'] ?? false) && ! ($trigger['dry_run'] ?? false)) {
            $status = AutonomousDecisionStatus::EXECUTING;
        }

        $payload = [
            'tenant_id' => $context['tenant_id'] ?? null,
            'device_id' => $context['device_id'] ?? null,
            'incident_id' => $context['incident_id'] ?? null,
            'finding_id' => $context['finding_id'] ?? null,
            'policy_id' => $policy['policy']?->id,
            'trigger_source' => (string) ($context['trigger_source'] ?? $context['trigger_type']),
            'input_context' => $context,
            'recommended_action' => $recommendedKey !== '' ? $recommendedKey : null,
            'recommended_payload' => is_array($recommended['payload'] ?? null) ? array_merge(
                $recommended['payload'],
                is_array($recommended['rollback_metadata'] ?? null) ? $recommended['rollback_metadata'] : []
            ) : [],
            'alternative_actions' => collect($ranking['alternative_actions'] ?? [])->map(function (array $candidate): array {
                return [
                    'action_key' => (string) ($candidate['action_key'] ?? ''),
                    'confidence_score' => (float) ($candidate['confidence_score'] ?? 0),
                    'summary' => (string) ($candidate['summary'] ?? ''),
                ];
            })->values()->all(),
            'confidence_score' => (float) ($ranking['confidence_score'] ?? 0),
            'rationale' => (string) ($ranking['rationale'] ?? $failureReason ?? 'Decision generated.'),
            'explanation' => array_merge(
                is_array($ranking['explanation'] ?? null) ? $ranking['explanation'] : [],
                [
                    'policy' => $policy['policy']?->toArray(),
                    'legacy_policy' => $policy['legacy_policy']?->toArray(),
                    'blocked' => $blocked,
                    'kill_switch' => $killSwitch,
                    'autonomy_paused' => $autonomyPaused,
                    'failure_reason' => $failureReason,
                ]
            ),
            'decision_mode' => $mode,
            'status' => $status,
            'simulation' => (bool) ($trigger['simulation'] ?? false),
            'dry_run' => (bool) ($trigger['dry_run'] ?? false),
            'failure_reason' => $failureReason,
        ];

        if (! $persist) {
            return $payload;
        }

        return DB::transaction(function () use ($payload, $recommended, $actor, $mode, $status): AutonomousDecision {
            $decision = AutonomousDecision::query()->create(array_merge(
                ['id' => (string) Str::uuid()],
                $payload
            ));

            foreach ((array) ($recommended['factors'] ?? []) as $factor) {
                ConfidenceEvidence::query()->create([
                    'id' => (string) Str::uuid(),
                    'decision_id' => $decision->id,
                    'factor_name' => (string) ($factor['name'] ?? 'factor'),
                    'factor_weight' => (float) ($factor['weight'] ?? 0),
                    'factor_value' => (float) ($factor['value'] ?? 0),
                    'notes' => Arr::except($factor, ['name', 'weight', 'value']),
                ]);
            }

            if ($mode === AutonomousDecisionMode::APPROVAL_REQUIRED) {
                $approval = ApprovalRequest::query()->create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $decision->tenant_id,
                    'request_type' => 'autonomous_decision',
                    'request_ref_id' => $decision->id,
                    'risk_level' => strtolower((string) data_get($decision->input_context, 'severity', 'medium')),
                    'reason' => (string) $decision->rationale,
                    'requested_by' => $actor?->id,
                    'required_role' => 'autonomous.approve',
                    'status' => 'pending',
                    'expires_at' => now()->addDay(),
                ]);

                $decision->update(['approval_request_id' => $approval->id]);
            }

            $this->auditLogger->log(
                'autonomous.decision.generated',
                'autonomous_decision',
                $decision->id,
                null,
                [
                    'decision_mode' => $mode,
                    'status' => $status,
                    'recommended_action' => $decision->recommended_action,
                    'confidence_score' => $decision->confidence_score,
                ],
                $actor?->id
            );

            return $decision->fresh(['confidenceEvidence']);
        });
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $policy
     * @return Collection<int,array<string,mixed>>
     */
    private function filterCandidates(Collection $candidates, array $context, array $policy): Collection
    {
        $deviceId = $context['device_id'] ?? null;
        $allowedActions = collect((array) ($policy['allowed_actions'] ?? []))->map(fn (mixed $value): string => (string) $value);
        $legacyAllowed = collect((array) ($policy['legacy_allowed_actions'] ?? []))->map(fn (mixed $value): string => (string) $value);
        $blockedActions = collect((array) ($policy['blocked_actions'] ?? []))->map(fn (mixed $value): string => (string) $value);

        $actionsLastHour = $deviceId
            ? AutonomousDecision::query()
                ->where('device_id', $deviceId)
                ->whereIn('status', [
                    AutonomousDecisionStatus::EXECUTING,
                    AutonomousDecisionStatus::EXECUTED,
                    AutonomousDecisionStatus::PENDING_APPROVAL,
                    AutonomousDecisionStatus::APPROVED,
                ])
                ->where('created_at', '>=', now()->subHour())
                ->count()
            : 0;

        if ($actionsLastHour >= (int) ($policy['max_actions_per_hour'] ?? config('autonomous_response.max_actions_per_device_per_hour', 3))) {
            return collect();
        }

        return $candidates
            ->filter(function (array $candidate) use ($context, $policy, $allowedActions, $legacyAllowed, $blockedActions, $deviceId): bool {
                $actionKey = (string) ($candidate['action_key'] ?? '');
                if ($actionKey === '' || ! $this->catalog->has($actionKey)) {
                    return false;
                }

                if ($blockedActions->contains($actionKey)) {
                    return false;
                }

                if ($allowedActions->isNotEmpty() && ! $allowedActions->contains($actionKey)) {
                    return false;
                }

                if ($legacyAllowed->isNotEmpty() && ! $legacyAllowed->contains($actionKey)) {
                    return false;
                }

                $definition = $this->catalog->get($actionKey);
                if (($definition['tenant_compatible'] ?? true) === false && ! empty($context['tenant_id'])) {
                    return false;
                }

                if (($definition['requires_online'] ?? true) && ! (bool) ($context['device_online'] ?? false)) {
                    return false;
                }

                $cooldownMinutes = max(
                    (int) ($policy['cooldown_minutes'] ?? 30),
                    (int) ($definition['cooldown_minutes'] ?? 15)
                );
                if ($deviceId && $cooldownMinutes > 0) {
                    $recentDuplicate = AutonomousDecision::query()
                        ->where('device_id', $deviceId)
                        ->where('recommended_action', $actionKey)
                        ->whereIn('status', [
                            AutonomousDecisionStatus::GENERATED,
                            AutonomousDecisionStatus::PENDING_APPROVAL,
                            AutonomousDecisionStatus::APPROVED,
                            AutonomousDecisionStatus::EXECUTING,
                            AutonomousDecisionStatus::EXECUTED,
                        ])
                        ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
                        ->exists();

                    if ($recentDuplicate) {
                        return false;
                    }
                }

                if ((bool) ($policy['requires_rollback_plan'] ?? false)
                    && ($definition['reversible'] ?? false)
                    && empty($candidate['rollback_metadata'])) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    private function killSwitchEnabled(): bool
    {
        $setting = ControlPlaneSetting::query()->find('jobs.kill_switch');

        return is_array($setting?->value) && (bool) ($setting->value['value'] ?? false);
    }

    private function autonomyPaused(): bool
    {
        $setting = ControlPlaneSetting::query()->find('autonomous_response.pause');

        return is_array($setting?->value) && (bool) ($setting->value['value'] ?? false);
    }
}
