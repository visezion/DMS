<?php

namespace App\Domain\Remediation;

use App\Models\AiRecommendation;
use App\Models\ApprovalRequest;
use App\Models\AutonomyPolicy;
use App\Models\ConfidenceThreshold;
use App\Models\ControlPlaneSetting;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\RemediationAction;
use App\Models\RemediationActionResult;
use App\Models\RemediationPlan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RemediationPlannerService
{
    public function __construct(
        private readonly GuardrailService $guardrails
    ) {
    }

    public function createPlanFromRecommendation(AiRecommendation $recommendation, ?User $actor = null): RemediationPlan
    {
        $actions = is_array($recommendation->recommended_actions) ? $recommendation->recommended_actions : [];
        $thresholds = $this->resolveConfidenceThreshold($recommendation->tenant_id);
        $confidenceScore = (float) $recommendation->confidence_score;
        $belowMinimumConfidence = $confidenceScore < $thresholds['min_confidence'];
        $confidenceNeedsApproval = $confidenceScore < $thresholds['approval_below'];
        $requiresApproval = (bool) $recommendation->approval_required || $confidenceNeedsApproval;
        $status = $belowMinimumConfidence
            ? 'blocked'
            : ($requiresApproval ? 'pending_approval' : 'draft');

        $plan = RemediationPlan::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $recommendation->tenant_id,
            'source_type' => 'ai_recommendation',
            'source_id' => $recommendation->id,
            'risk_level' => (string) $recommendation->risk_level,
            'dry_run' => false,
            'simulation' => false,
            'requires_approval' => $requiresApproval,
            'status' => $status,
            'summary' => [
                'reasoning_summary' => $recommendation->reasoning_summary,
                'recommended_action_count' => count($actions),
                'recommendation_confidence_score' => $confidenceScore,
                'confidence_thresholds' => $thresholds,
                'confidence_below_minimum' => $belowMinimumConfidence,
            ],
            'created_by' => $actor?->id,
        ]);

        foreach ($actions as $index => $action) {
            $guardrail = $this->guardrails->evaluate($action, $recommendation->tenant_id);
            $actionRequiresApproval = (bool) ($guardrail['approval_required'] ?? false) || $confidenceNeedsApproval;

            RemediationAction::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $recommendation->tenant_id,
                'plan_id' => $plan->id,
                'action_order' => $index + 1,
                'action_type' => (string) ($action['action_type'] ?? 'open_approval_request'),
                'target_device_id' => data_get($action, 'target_scope.type') === 'device' ? data_get($action, 'target_scope.id') : null,
                'target_group_id' => data_get($action, 'target_scope.type') === 'group' ? data_get($action, 'target_scope.id') : null,
                'args' => is_array($action['arguments'] ?? null) ? $action['arguments'] : [],
                'guardrail_snapshot' => $guardrail,
                'approval_required' => $actionRequiresApproval,
                'timeout_seconds' => 600,
                'max_retries' => 1,
                'cooldown_seconds' => 300,
                'status' => ($guardrail['allowed'] ?? false) ? 'pending' : 'blocked',
            ]);
        }

        if ($plan->requires_approval) {
            ApprovalRequest::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $plan->tenant_id,
                'request_type' => 'remediation_plan',
                'request_ref_id' => $plan->id,
                'risk_level' => (string) $plan->risk_level,
                'reason' => $belowMinimumConfidence
                    ? 'Plan is blocked because confidence is below minimum threshold.'
                    : 'Plan requires human approval before execution.',
                'requested_by' => $actor?->id,
                'required_role' => 'remediation.approve',
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);
        }

        return $plan->load('actions');
    }

    public function approve(RemediationPlan $plan, ?User $actor = null): RemediationPlan
    {
        $plan->update([
            'status' => 'approved',
            'approved_by' => $actor?->id,
            'approved_at' => now(),
        ]);

        ApprovalRequest::query()
            ->where('request_type', 'remediation_plan')
            ->where('request_ref_id', $plan->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'decided_by' => $actor?->id,
                'decided_at' => now(),
            ]);

        return $plan->fresh(['actions']);
    }

    public function execute(RemediationPlan $plan, ?User $actor = null): RemediationPlan
    {
        if ($plan->requires_approval && $plan->status !== 'approved') {
            abort(422, 'Plan must be approved before execution.');
        }

        $thresholds = $this->resolveConfidenceThreshold($plan->tenant_id);
        $recommendationConfidence = (float) data_get($plan->summary, 'recommendation_confidence_score', 1);
        $queuedCount = 0;

        foreach ($plan->actions as $action) {
            if ($action->status !== 'pending') {
                continue;
            }

            $runtimeDecision = $this->evaluateRuntimeDecision($plan, $action, $thresholds, $recommendationConfidence);
            if (! $runtimeDecision['allowed']) {
                $action->update([
                    'status' => 'blocked',
                    'guardrail_snapshot' => array_merge(
                        is_array($action->guardrail_snapshot) ? $action->guardrail_snapshot : [],
                        ['runtime_decision' => $runtimeDecision]
                    ),
                ]);
                continue;
            }

            if ((bool) $runtimeDecision['approval_required']) {
                $action->update(['status' => 'pending_approval']);
                continue;
            }

            if ((string) $action->action_type === 'open_approval_request') {
                $action->update(['status' => 'pending_approval']);
                continue;
            }

            $blueprint = $this->buildExecutionBlueprint($action);
            if (! is_array($blueprint)) {
                $action->update([
                    'status' => 'blocked',
                    'guardrail_snapshot' => array_merge(
                        is_array($action->guardrail_snapshot) ? $action->guardrail_snapshot : [],
                        ['runtime_decision' => array_merge($runtimeDecision, ['reason' => 'Action has no executable job mapping.'])],
                    ),
                ]);
                continue;
            }

            $jobPayload = array_merge(
                is_array($blueprint['payload'] ?? null) ? $blueprint['payload'] : [],
                [
                    'remediation_plan_id' => $plan->id,
                    'remediation_action_id' => $action->id,
                    'action_token' => (string) Str::uuid(),
                    'guardrail_snapshot' => array_merge(
                        is_array($action->guardrail_snapshot) ? $action->guardrail_snapshot : [],
                        ['runtime_decision' => $runtimeDecision]
                    ),
                ]
            );

            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $plan->tenant_id,
                'job_type' => (string) ($blueprint['job_type'] ?? 'run_command'),
                'payload' => $jobPayload,
                'target_type' => $action->target_device_id ? 'device' : 'group',
                'target_id' => $action->target_device_id ?: $action->target_group_id,
                'priority' => 70,
                'status' => 'queued',
                'created_by' => $actor?->id,
            ]);

            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $action->target_device_id,
                'status' => 'pending',
            ]);

            $action->update(['status' => 'queued']);

            RemediationActionResult::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $plan->tenant_id,
                'action_id' => $action->id,
                'attempt_no' => 1,
                'job_id' => $job->id,
                'status' => 'queued',
                'started_at' => now(),
            ]);

            $queuedCount++;
        }

        if ($queuedCount > 0) {
            $plan->update([
                'status' => 'executing',
                'executed_at' => now(),
            ]);
        } elseif ($plan->actions()->where('status', 'blocked')->exists()) {
            $plan->update(['status' => 'blocked']);
        } elseif ($plan->actions()->where('status', 'pending_approval')->exists()) {
            $plan->update(['status' => 'pending_approval']);
        } else {
            $plan->update(['status' => 'approved']);
        }

        return $plan->fresh(['actions']);
    }

    /**
     * @param  array{min_confidence:float,approval_below:float,auto_execute_above:float}  $thresholds
     * @return array{allowed:bool,approval_required:bool,reason:string,thresholds:array<string,float>,autonomy_policy:array<string,mixed>|null}
     */
    private function evaluateRuntimeDecision(RemediationPlan $plan, RemediationAction $action, array $thresholds, float $recommendationConfidence): array
    {
        $guardrail = $this->guardrails->evaluate([
            'action_type' => $action->action_type,
            'arguments' => is_array($action->args) ? $action->args : [],
            'target_scope' => [
                'type' => $action->target_device_id ? 'device' : ($action->target_group_id ? 'group' : 'fleet'),
                'id' => $action->target_device_id ?: $action->target_group_id,
            ],
        ], $plan->tenant_id);

        if (! ($guardrail['allowed'] ?? false)) {
            return [
                'allowed' => false,
                'approval_required' => true,
                'reason' => (string) ($guardrail['reason'] ?? 'blocked by guardrail'),
                'thresholds' => $thresholds,
                'autonomy_policy' => null,
            ];
        }

        if ($recommendationConfidence < $thresholds['min_confidence']) {
            return [
                'allowed' => false,
                'approval_required' => true,
                'reason' => 'Recommendation confidence is below minimum threshold.',
                'thresholds' => $thresholds,
                'autonomy_policy' => null,
            ];
        }

        $policy = $this->resolveAutonomyPolicy($plan, $action);
        if ($policy) {
            $autonomyLevel = strtolower((string) $policy->autonomy_level);
            if ($autonomyLevel === 'off') {
                return [
                    'allowed' => false,
                    'approval_required' => true,
                    'reason' => 'Autonomy policy is set to off for this scope.',
                    'thresholds' => $thresholds,
                    'autonomy_policy' => $policy->toArray(),
                ];
            }

            $allowedActions = collect($policy->allowed_actions ?? [])->map(fn (mixed $value): string => (string) $value)->filter();
            if ($allowedActions->isNotEmpty() && ! $allowedActions->contains((string) $action->action_type)) {
                return [
                    'allowed' => false,
                    'approval_required' => true,
                    'reason' => 'Action is not allowlisted by autonomy policy.',
                    'thresholds' => $thresholds,
                    'autonomy_policy' => $policy->toArray(),
                ];
            }

            if ($this->isKillSwitchBlockedByPolicy($policy)) {
                return [
                    'allowed' => false,
                    'approval_required' => true,
                    'reason' => 'Action blocked by kill switch policy condition.',
                    'thresholds' => $thresholds,
                    'autonomy_policy' => $policy->toArray(),
                ];
            }

            if (! $this->isWithinMaintenanceWindow($policy)) {
                return [
                    'allowed' => false,
                    'approval_required' => true,
                    'reason' => 'Current time is outside configured maintenance windows.',
                    'thresholds' => $thresholds,
                    'autonomy_policy' => $policy->toArray(),
                ];
            }

            $maxParallel = max(1, (int) ($policy->max_parallel_actions ?? 1));
            $activeActions = RemediationAction::query()
                ->where('tenant_id', $plan->tenant_id)
                ->whereIn('status', ['queued', 'running'])
                ->count();
            if ($activeActions >= $maxParallel) {
                return [
                    'allowed' => false,
                    'approval_required' => true,
                    'reason' => 'Max parallel remediation action budget reached for policy scope.',
                    'thresholds' => $thresholds,
                    'autonomy_policy' => $policy->toArray(),
                ];
            }
        }

        $approvedAutonomousOverride = (bool) data_get($plan->summary, 'approval_override', false)
            && $plan->approved_at !== null;

        $approvalRequired = ! $approvedAutonomousOverride
            && (
                (bool) ($guardrail['approval_required'] ?? true)
                || $recommendationConfidence < $thresholds['approval_below']
            );

        return [
            'allowed' => true,
            'approval_required' => $approvalRequired,
            'reason' => 'Runtime policy checks passed.',
            'thresholds' => $thresholds,
            'autonomy_policy' => $policy?->toArray(),
        ];
    }

    /**
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function buildExecutionBlueprint(RemediationAction $action): ?array
    {
        $args = is_array($action->args) ? $action->args : [];
        $actionType = (string) $action->action_type;

        return match ($actionType) {
            'restart_service' => $this->blueprintRestartService($args),
            'kill_process' => $this->blueprintKillProcess($args),
            'run_approved_command' => $this->blueprintRunCommand($args),
            'apply_policy' => $this->blueprintApplyPolicy($args),
            'isolate_device' => $this->blueprintRunCommand($args),
            'run_diagnostic_script' => $this->blueprintRunCommand($args),
            'collect_forensic_snapshot' => $this->blueprintRunCommand($args),
            'disable_user_session' => $this->blueprintRunCommand($args),
            'restart_agent' => [
                'job_type' => 'run_command',
                'payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Restart-Service -Name DMSAgent -Force"',
                ],
            ],
            'reapply_policy' => $this->blueprintApplyPolicy($args),
            'uninstall_package' => $this->blueprintUninstallSoftware($args),
            'block_hash' => $this->blueprintRunCommand($args),
            'quarantine_file' => $this->blueprintQuarantineFile($args),
            'restrict_network' => $this->blueprintRunCommand($args),
            'reboot_device' => $this->blueprintScheduleReboot($args),
            'uninstall_software' => $this->blueprintUninstallSoftware($args),
            'cleanup_temp_files' => [
                'job_type' => 'run_command',
                'payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "$paths=@($env:TEMP,\'C:\\Windows\\Temp\'); foreach($p in $paths){ if(Test-Path $p){ Get-ChildItem -Path $p -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue }}"',
                ],
            ],
            'trigger_windows_update' => [
                'job_type' => 'run_command',
                'payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "UsoClient StartScan; Start-Sleep -Seconds 5; UsoClient StartInstall"',
                ],
            ],
            're_run_inventory' => [
                'job_type' => 'reconcile_software_inventory',
                'payload' => [
                    'reason' => (string) ($args['reason'] ?? 'remediation_re_run_inventory'),
                ],
            ],
            're_enable_security_control' => $this->blueprintReEnableSecurityControl($args),
            'agent_self_heal' => [
                'job_type' => 'run_command',
                'payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Restart-Service -Name DMSAgent -Force"',
                ],
            ],
            'schedule_reboot' => $this->blueprintScheduleReboot($args),
            'force_password_reset', 'notify_admin', 'create_ticket', 'require_manual_investigation' => null,
            default => $this->blueprintRunCommand($args),
        };
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintRestartService(array $args): ?array
    {
        $serviceName = trim((string) ($args['service_name'] ?? $args['service'] ?? ''));
        if ($serviceName === '') {
            return null;
        }

        return [
            'job_type' => 'run_command',
            'payload' => [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Restart-Service -Name \''.$this->escapePowerShellLiteral($serviceName).'\' -Force"',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintKillProcess(array $args): ?array
    {
        $processId = is_numeric($args['process_id'] ?? null) ? (int) $args['process_id'] : null;
        $processName = trim((string) ($args['process_name'] ?? $args['name'] ?? ''));

        if ($processId !== null && $processId > 0) {
            return [
                'job_type' => 'run_command',
                'payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Stop-Process -Id '.$processId.' -Force"',
                ],
            ];
        }
        if ($processName === '') {
            return null;
        }

        return [
            'job_type' => 'run_command',
            'payload' => [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Stop-Process -Name \''.$this->escapePowerShellLiteral($processName).'\' -Force"',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintRunCommand(array $args): ?array
    {
        $script = trim((string) ($args['script'] ?? $args['command'] ?? ''));
        if ($script === '') {
            return null;
        }

        return [
            'job_type' => 'run_command',
            'payload' => ['script' => $script],
        ];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintApplyPolicy(array $args): ?array
    {
        if (trim((string) ($args['policy_version_id'] ?? '')) === '') {
            return null;
        }

        return [
            'job_type' => 'apply_policy',
            'payload' => $args,
        ];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintUninstallSoftware(array $args): ?array
    {
        $wingetId = trim((string) ($args['winget_id'] ?? ''));
        if ($wingetId !== '') {
            return [
                'job_type' => 'uninstall_package',
                'payload' => ['winget_id' => $wingetId],
            ];
        }

        $packageName = trim((string) ($args['package_name'] ?? $args['name'] ?? ''));
        if ($packageName === '') {
            return null;
        }

        return [
            'job_type' => 'run_command',
            'payload' => [
                'script' => 'winget uninstall --name "'.str_replace('"', '\"', $packageName).'" --silent --accept-source-agreements --disable-interactivity',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintReEnableSecurityControl(array $args): ?array
    {
        $control = strtolower(trim((string) ($args['control'] ?? 'defender')));

        return match ($control) {
            'defender', 'microsoft_defender' => [
                'job_type' => 'run_command',
                'payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Set-MpPreference -DisableRealtimeMonitoring $false; Start-Service -Name WinDefend"',
                ],
            ],
            'firewall' => [
                'job_type' => 'run_command',
                'payload' => [
                    'script' => 'netsh advfirewall set allprofiles state on',
                ],
            ],
            default => $this->blueprintRunCommand($args),
        };
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintScheduleReboot(array $args): ?array
    {
        $delay = is_numeric($args['delay_seconds'] ?? null) ? (int) $args['delay_seconds'] : 60;
        $delay = max(0, min(3600, $delay));

        return [
            'job_type' => 'run_command',
            'payload' => [
                'script' => 'shutdown.exe /r /t '.$delay.' /f /c "DMS remediation scheduled reboot"',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{job_type:string,payload:array<string,mixed>}|null
     */
    private function blueprintQuarantineFile(array $args): ?array
    {
        $path = trim((string) ($args['file_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $escapedPath = str_replace("'", "''", $path);

        return [
            'job_type' => 'run_command',
            'payload' => [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "$src = \''.$escapedPath.'\'; $dstRoot = \'C:\\DMS-Quarantine\'; if(!(Test-Path $dstRoot)){ New-Item -ItemType Directory -Path $dstRoot | Out-Null }; if(Test-Path $src){ Move-Item -LiteralPath $src -Destination $dstRoot -Force }"',
            ],
        ];
    }

    private function escapePowerShellLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * @return array{min_confidence:float,approval_below:float,auto_execute_above:float}
     */
    private function resolveConfidenceThreshold(?string $tenantId): array
    {
        $query = ConfidenceThreshold::query()
            ->where('engine', 'remediation')
            ->where('context_key', 'default')
            ->where('active', true)
            ->where(function ($scope) use ($tenantId): void {
                if ($tenantId !== null) {
                    $scope->where('tenant_id', $tenantId)->orWhereNull('tenant_id');

                    return;
                }

                $scope->whereNull('tenant_id');
            })
            ->orderByDesc('tenant_id')
            ->latest('updated_at');

        $row = $query->first();

        return [
            'min_confidence' => $this->normalizeConfidence((float) ($row?->min_confidence ?? 0.65)),
            'approval_below' => $this->normalizeConfidence((float) ($row?->approval_below ?? 0.85)),
            'auto_execute_above' => $this->normalizeConfidence((float) ($row?->auto_execute_above ?? 0.96)),
        ];
    }

    private function normalizeConfidence(float $value): float
    {
        if ($value > 1) {
            return round(max(0.0, min(1.0, $value / 100)), 4);
        }

        return round(max(0.0, min(1.0, $value)), 4);
    }

    private function resolveAutonomyPolicy(RemediationPlan $plan, RemediationAction $action): ?AutonomyPolicy
    {
        $candidates = [];
        if ($action->target_device_id) {
            $candidates[] = ['scope_type' => 'device', 'scope_id' => $action->target_device_id];
        }
        if ($action->target_group_id) {
            $candidates[] = ['scope_type' => 'group', 'scope_id' => $action->target_group_id];
        }
        if ($plan->tenant_id) {
            $candidates[] = ['scope_type' => 'tenant', 'scope_id' => $plan->tenant_id];
        }
        $candidates[] = ['scope_type' => 'global', 'scope_id' => 'global'];

        foreach ($candidates as $candidate) {
            $policy = AutonomyPolicy::query()
                ->where('scope_type', $candidate['scope_type'])
                ->where('scope_id', $candidate['scope_id'])
                ->where('active', true)
                ->where(function ($scope) use ($plan): void {
                    if ($plan->tenant_id !== null) {
                        $scope->where('tenant_id', $plan->tenant_id)->orWhereNull('tenant_id');

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

    private function isKillSwitchBlockedByPolicy(AutonomyPolicy $policy): bool
    {
        $conditions = Arr::wrap($policy->blocked_conditions);
        $requiresKillSwitch = collect($conditions)
            ->contains(fn (mixed $condition): bool => is_array($condition)
                ? (bool) ($condition['kill_switch'] ?? false)
                : trim((string) $condition) === 'kill_switch');

        if (! $requiresKillSwitch) {
            return false;
        }

        $setting = ControlPlaneSetting::query()->find('jobs.kill_switch');
        if (! $setting || ! is_array($setting->value)) {
            return false;
        }

        return (bool) ($setting->value['value'] ?? false);
    }

    private function isWithinMaintenanceWindow(AutonomyPolicy $policy): bool
    {
        $windows = Arr::wrap($policy->maintenance_windows);
        if ($windows === []) {
            return true;
        }

        $nowUtc = now()->utc();
        foreach ($windows as $window) {
            if (is_string($window) && preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $window, $matches) === 1) {
                if ($this->matchesTimeWindow($nowUtc, $matches[1], $matches[2])) {
                    return true;
                }
                continue;
            }

            if (! is_array($window)) {
                continue;
            }

            $timezone = trim((string) ($window['timezone'] ?? 'UTC'));
            $start = trim((string) ($window['start'] ?? $window['from'] ?? ''));
            $end = trim((string) ($window['end'] ?? $window['to'] ?? ''));
            if ($start === '' || $end === '') {
                continue;
            }

            $candidateNow = $nowUtc->copy()->setTimezone($timezone);
            $days = Arr::wrap($window['days'] ?? []);
            if ($days !== []) {
                $today = strtolower($candidateNow->shortEnglishDayOfWeek);
                $normalizedDays = collect($days)->map(fn (mixed $day): string => strtolower(substr((string) $day, 0, 3)))->values();
                if (! $normalizedDays->contains(substr($today, 0, 3))) {
                    continue;
                }
            }

            if ($this->matchesTimeWindow($candidateNow, $start, $end)) {
                return true;
            }
        }

        return false;
    }

    private function matchesTimeWindow(Carbon $candidateNow, string $start, string $end): bool
    {
        $startAt = Carbon::parse($candidateNow->toDateString().' '.$start, $candidateNow->timezone);
        $endAt = Carbon::parse($candidateNow->toDateString().' '.$end, $candidateNow->timezone);

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt->addDay();
            if ($candidateNow->lessThan($startAt)) {
                $candidateNow = $candidateNow->copy()->addDay();
            }
        }

        return $candidateNow->betweenIncluded($startAt, $endAt);
    }
}
