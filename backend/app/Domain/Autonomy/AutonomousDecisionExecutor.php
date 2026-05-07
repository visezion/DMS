<?php

namespace App\Domain\Autonomy;

use App\Domain\Autonomy\Enums\AutonomousDecisionStatus;
use App\Domain\Remediation\ActionCatalog;
use App\Domain\Remediation\RemediationPlannerService;
use App\Models\ActionRollback;
use App\Models\AutonomousDecision;
use App\Models\AutonomousExecutionResult;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\RemediationAction;
use App\Models\RemediationPlan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Str;

class AutonomousDecisionExecutor
{
    public function __construct(
        private readonly ActionCatalog $catalog,
        private readonly RemediationPlannerService $planner,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function execute(AutonomousDecision $decision, ?User $actor = null): AutonomousDecision
    {
        $decision->loadMissing('executionResults');

        $plan = $this->createPlan($decision, $actor);
        $plan = $this->planner->execute($plan->fresh('actions'), $actor);
        $action = $plan->actions->first();
        $latestResult = $action?->results()->latest('created_at')->first();

        AutonomousExecutionResult::query()->create([
            'id' => (string) Str::uuid(),
            'decision_id' => $decision->id,
            'action_name' => (string) $decision->recommended_action,
            'target_type' => $decision->device_id ? 'device' : 'fleet',
            'target_id' => $decision->device_id,
            'execution_status' => (string) ($plan->status ?? 'failed'),
            'command_payload' => is_array($decision->recommended_payload) ? $decision->recommended_payload : [],
            'output_log' => $latestResult?->error_text,
            'rollback_available' => (bool) data_get($latestResult?->evidence ?? [], 'rollback_hint.possible', false),
            'rollback_status' => null,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $status = in_array((string) $plan->status, ['executing', 'approved'], true)
            ? AutonomousDecisionStatus::EXECUTED
            : AutonomousDecisionStatus::FAILED;

        $decision->update([
            'status' => $status,
            'executed_at' => $status === AutonomousDecisionStatus::EXECUTED ? now() : null,
            'execution_reference' => $plan->id,
            'failure_reason' => $status === AutonomousDecisionStatus::FAILED ? 'Remediation plan could not enter execution.' : null,
        ]);

        $this->auditLogger->log(
            'autonomous.decision.executed',
            'autonomous_decision',
            $decision->id,
            null,
            [
                'status' => $status,
                'plan_id' => $plan->id,
                'action_id' => $action?->id,
            ],
            $actor?->id
        );

        return $decision->fresh(['executionResults', 'confidenceEvidence']);
    }

    public function rollback(AutonomousDecision $decision, ?User $actor = null): AutonomousDecision
    {
        $plan = RemediationPlan::query()->with('actions.results')->findOrFail((string) $decision->execution_reference);
        /** @var RemediationAction|null $action */
        $action = $plan->actions->first();
        $latestResult = $action?->results->sortByDesc('created_at')->first();
        $rollbackHint = is_array(data_get($latestResult?->evidence ?? [], 'rollback_hint')) ? data_get($latestResult?->evidence ?? [], 'rollback_hint') : [];
        $rollbackActionType = (string) ($rollbackHint['job_type'] ?? 'manual_review');
        $rollbackArgs = is_array($rollbackHint['payload'] ?? null)
            ? $rollbackHint['payload']
            : ['decision_id' => $decision->id];

        $rollback = ActionRollback::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $decision->tenant_id,
            'action_result_id' => $latestResult?->id,
            'rollback_action_type' => $rollbackActionType,
            'rollback_args' => $rollbackArgs,
            'status' => ($rollbackHint['possible'] ?? false) ? 'queued' : 'requested',
            'result' => [],
            'started_at' => now(),
        ]);

        if (($rollbackHint['possible'] ?? false) && $decision->device_id) {
            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $decision->tenant_id,
                'job_type' => $rollbackActionType === 'manual_review' ? 'run_command' : $rollbackActionType,
                'payload' => $rollbackArgs,
                'target_type' => 'device',
                'target_id' => $decision->device_id,
                'priority' => 80,
                'status' => 'queued',
            ]);

            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $decision->device_id,
                'status' => 'pending',
            ]);
        }

        $decision->update([
            'status' => AutonomousDecisionStatus::ROLLED_BACK,
            'rollback_reference' => $rollback->id,
        ]);

        $this->auditLogger->log(
            'autonomous.decision.rollback',
            'autonomous_decision',
            $decision->id,
            null,
            ['rollback_id' => $rollback->id],
            $actor?->id
        );

        return $decision->fresh(['executionResults', 'confidenceEvidence']);
    }

    private function createPlan(AutonomousDecision $decision, ?User $actor = null): RemediationPlan
    {
        $args = array_merge(
            is_array($this->catalog->get((string) $decision->recommended_action)['default_payload'] ?? null)
                ? $this->catalog->get((string) $decision->recommended_action)['default_payload']
                : [],
            is_array($decision->recommended_payload) ? $decision->recommended_payload : []
        );

        $plan = RemediationPlan::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $decision->tenant_id,
            'source_type' => 'autonomous_decision',
            'source_id' => $decision->id,
            'risk_level' => strtolower((string) data_get($decision->input_context, 'severity', 'medium')),
            'dry_run' => (bool) $decision->dry_run,
            'simulation' => (bool) $decision->simulation,
            'requires_approval' => false,
            'status' => 'draft',
            'summary' => [
                'reasoning_summary' => $decision->rationale,
                'recommendation_confidence_score' => ((float) $decision->confidence_score) / 100,
                'decision_mode' => $decision->decision_mode,
                'approval_override' => $decision->status === AutonomousDecisionStatus::APPROVED,
            ],
            'created_by' => $actor?->id,
            'approved_by' => $decision->status === AutonomousDecisionStatus::APPROVED ? $actor?->id : null,
            'approved_at' => $decision->status === AutonomousDecisionStatus::APPROVED ? now() : null,
        ]);

        RemediationAction::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $decision->tenant_id,
            'plan_id' => $plan->id,
            'action_order' => 1,
            'action_type' => (string) $decision->recommended_action,
            'target_device_id' => $decision->device_id,
            'target_group_id' => data_get($decision->input_context, 'group_id'),
            'args' => $args,
            'guardrail_snapshot' => [
                'autonomous_decision_id' => $decision->id,
                'confidence_score' => $decision->confidence_score,
                'decision_mode' => $decision->decision_mode,
            ],
            'approval_required' => false,
            'timeout_seconds' => 600,
            'max_retries' => 1,
            'cooldown_seconds' => max(0, ((int) ($this->catalog->get((string) $decision->recommended_action)['cooldown_minutes'] ?? 15)) * 60),
            'status' => 'pending',
        ]);

        return $plan->fresh('actions');
    }
}
