<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Autonomy\AutonomousDecisionExecutor;
use App\Domain\Autonomy\AutonomousResponseEngine;
use App\Domain\Remediation\ActionCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\AutonomousDecisionActionRequest;
use App\Http\Requests\EvaluateAutonomousDecisionRequest;
use App\Http\Requests\StoreAutonomousResponsePolicyRequest;
use App\Http\Requests\StoreRiskActionMappingRequest;
use App\Http\Resources\AutonomousActionDefinitionResource;
use App\Http\Resources\AutonomousDecisionResource;
use App\Http\Resources\AutonomousResponsePolicyResource;
use App\Http\Resources\RiskActionMappingResource;
use App\Jobs\ExecuteAutonomousDecisionJob;
use App\Jobs\RollbackAutonomousDecisionJob;
use App\Models\ApprovalRequest;
use App\Models\AutonomousDecision;
use App\Models\AutonomousResponsePolicy;
use App\Models\RiskActionMapping;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutonomousResponseController extends Controller
{
    public function __construct(
        private readonly ActionCatalog $catalog,
        private readonly AutonomousResponseEngine $engine,
        private readonly AutonomousDecisionExecutor $executor,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'policies' => AutonomousResponsePolicy::query()->where('enabled', true)->count(),
            'mappings' => RiskActionMapping::query()->where('enabled', true)->count(),
            'pending_approvals' => AutonomousDecision::query()->where('status', 'pending_approval')->count(),
            'recent_auto_executed' => AutonomousDecision::query()->where('decision_mode', 'auto_execute')->where('created_at', '>=', now()->subDay())->count(),
            'failed_actions' => AutonomousDecision::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'rolled_back' => AutonomousDecision::query()->where('status', 'rolled_back')->where('created_at', '>=', now()->subDays(7))->count(),
        ]);
    }

    public function policyIndex(): JsonResponse
    {
        return response()->json(AutonomousResponsePolicyResource::collection(
            AutonomousResponsePolicy::query()->latest('updated_at')->paginate(25)
        ));
    }

    public function policyStore(StoreAutonomousResponsePolicyRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $policy = AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $payload['tenant_id'] ?? null,
            'name' => $payload['name'],
            'scope_type' => $payload['scope_type'],
            'scope_id' => $payload['scope_id'] ?? null,
            'trigger_type' => $payload['trigger_type'],
            'minimum_risk_score' => (float) ($payload['minimum_risk_score'] ?? 0),
            'allowed_actions' => $payload['allowed_actions'] ?? [],
            'blocked_actions' => $payload['blocked_actions'] ?? [],
            'autonomy_mode' => $payload['autonomy_mode'],
            'minimum_confidence' => (float) ($payload['minimum_confidence'] ?? config('autonomous_response.default_confidence', 70)),
            'requires_rollback_plan' => (bool) ($payload['requires_rollback_plan'] ?? false),
            'max_actions_per_hour' => (int) ($payload['max_actions_per_hour'] ?? 4),
            'cooldown_minutes' => (int) ($payload['cooldown_minutes'] ?? 30),
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json(new AutonomousResponsePolicyResource($policy), 201);
    }

    public function policyUpdate(StoreAutonomousResponsePolicyRequest $request, string $policyId): JsonResponse
    {
        $policy = AutonomousResponsePolicy::query()->findOrFail($policyId);
        $payload = $request->validated();
        $policy->update([
            'name' => $payload['name'],
            'scope_type' => $payload['scope_type'],
            'scope_id' => $payload['scope_id'] ?? null,
            'trigger_type' => $payload['trigger_type'],
            'minimum_risk_score' => (float) ($payload['minimum_risk_score'] ?? 0),
            'allowed_actions' => $payload['allowed_actions'] ?? [],
            'blocked_actions' => $payload['blocked_actions'] ?? [],
            'autonomy_mode' => $payload['autonomy_mode'],
            'minimum_confidence' => (float) ($payload['minimum_confidence'] ?? config('autonomous_response.default_confidence', 70)),
            'requires_rollback_plan' => (bool) ($payload['requires_rollback_plan'] ?? false),
            'max_actions_per_hour' => (int) ($payload['max_actions_per_hour'] ?? 4),
            'cooldown_minutes' => (int) ($payload['cooldown_minutes'] ?? 30),
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json(new AutonomousResponsePolicyResource($policy->fresh()));
    }

    public function policyDelete(string $policyId): JsonResponse
    {
        $policy = AutonomousResponsePolicy::query()->findOrFail($policyId);
        $policy->delete();

        return response()->json(['deleted' => true]);
    }

    public function mappingIndex(): JsonResponse
    {
        return response()->json(RiskActionMappingResource::collection(
            RiskActionMapping::query()->latest('updated_at')->paginate(25)
        ));
    }

    public function mappingStore(StoreRiskActionMappingRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $mapping = RiskActionMapping::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $payload['tenant_id'] ?? null,
            'name' => $payload['name'],
            'trigger_type' => $payload['trigger_type'],
            'minimum_severity' => $payload['minimum_severity'] ?? null,
            'maximum_severity' => $payload['maximum_severity'] ?? null,
            'minimum_risk_score' => (float) ($payload['minimum_risk_score'] ?? 0),
            'maximum_risk_score' => isset($payload['maximum_risk_score']) ? (float) $payload['maximum_risk_score'] : null,
            'candidate_actions' => $payload['candidate_actions'],
            'preconditions' => $payload['preconditions'] ?? [],
            'rollback_metadata' => $payload['rollback_metadata'] ?? [],
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'priority' => (int) ($payload['priority'] ?? 100),
        ]);

        return response()->json(new RiskActionMappingResource($mapping), 201);
    }

    public function mappingUpdate(StoreRiskActionMappingRequest $request, string $mappingId): JsonResponse
    {
        $mapping = RiskActionMapping::query()->findOrFail($mappingId);
        $payload = $request->validated();
        $mapping->update([
            'name' => $payload['name'],
            'trigger_type' => $payload['trigger_type'],
            'minimum_severity' => $payload['minimum_severity'] ?? null,
            'maximum_severity' => $payload['maximum_severity'] ?? null,
            'minimum_risk_score' => (float) ($payload['minimum_risk_score'] ?? 0),
            'maximum_risk_score' => isset($payload['maximum_risk_score']) ? (float) $payload['maximum_risk_score'] : null,
            'candidate_actions' => $payload['candidate_actions'],
            'preconditions' => $payload['preconditions'] ?? [],
            'rollback_metadata' => $payload['rollback_metadata'] ?? [],
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'priority' => (int) ($payload['priority'] ?? 100),
        ]);

        return response()->json(new RiskActionMappingResource($mapping->fresh()));
    }

    public function mappingDelete(string $mappingId): JsonResponse
    {
        $mapping = RiskActionMapping::query()->findOrFail($mappingId);
        $mapping->delete();

        return response()->json(['deleted' => true]);
    }

    public function catalogIndex(): JsonResponse
    {
        $catalog = collect($this->catalog->all())
            ->map(fn (array $definition, string $key): array => array_merge(['key' => $key], $definition))
            ->values();

        return response()->json(AutonomousActionDefinitionResource::collection($catalog));
    }

    public function decisions(Request $request): JsonResponse
    {
        $query = AutonomousDecision::query()->with(['confidenceEvidence', 'executionResults'])->latest('created_at');

        foreach (['status', 'decision_mode', 'recommended_action', 'device_id', 'incident_id', 'finding_id'] as $filter) {
            $value = trim((string) $request->query($filter, ''));
            if ($value !== '') {
                $query->where($filter, $value);
            }
        }

        $confidenceMinimum = trim((string) $request->query('confidence_min', ''));
        if ($confidenceMinimum !== '' && is_numeric($confidenceMinimum)) {
            $query->where('confidence_score', '>=', (float) $confidenceMinimum);
        }

        return response()->json(AutonomousDecisionResource::collection($query->paginate(25)));
    }

    public function showDecision(string $decisionId): JsonResponse
    {
        $decision = AutonomousDecision::query()->with(['confidenceEvidence', 'executionResults'])->findOrFail($decisionId);

        return response()->json(new AutonomousDecisionResource($decision));
    }

    public function evaluate(EvaluateAutonomousDecisionRequest $request): JsonResponse
    {
        $decision = $this->engine->evaluate($request->validated(), $request->user());

        return response()->json(new AutonomousDecisionResource($decision->load(['confidenceEvidence', 'executionResults'])), 201);
    }

    public function simulate(EvaluateAutonomousDecisionRequest $request): JsonResponse
    {
        $payload = array_merge($request->validated(), ['simulation' => true, 'dry_run' => true]);
        $preview = $this->engine->evaluate($payload, $request->user(), false);

        return response()->json($preview);
    }

    public function approve(AutonomousDecisionActionRequest $request, string $decisionId): JsonResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);
        $before = $decision->toArray();

        DB::transaction(function () use ($decision, $request): void {
            $decision->update([
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ]);

            ApprovalRequest::query()
                ->where('request_type', 'autonomous_decision')
                ->where('request_ref_id', $decision->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'approved',
                    'decided_by' => $request->user()?->id,
                    'decided_at' => now(),
                    'decision_note' => (string) $request->input('note', 'Approved by operator.'),
                ]);
        });

        $this->auditLogger->log('autonomous.decision.approved', 'autonomous_decision', $decision->id, $before, $decision->fresh()?->toArray(), $request->user()?->id);

        return response()->json(new AutonomousDecisionResource($decision->fresh(['confidenceEvidence', 'executionResults'])));
    }

    public function reject(AutonomousDecisionActionRequest $request, string $decisionId): JsonResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);
        $before = $decision->toArray();

        DB::transaction(function () use ($decision, $request): void {
            $decision->update([
                'status' => 'rejected',
                'failure_reason' => (string) $request->input('note', 'Rejected by operator.'),
            ]);

            ApprovalRequest::query()
                ->where('request_type', 'autonomous_decision')
                ->where('request_ref_id', $decision->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'decided_by' => $request->user()?->id,
                    'decided_at' => now(),
                    'decision_note' => (string) $request->input('note', 'Rejected by operator.'),
                ]);
        });

        $this->auditLogger->log('autonomous.decision.rejected', 'autonomous_decision', $decision->id, $before, $decision->fresh()?->toArray(), $request->user()?->id);

        return response()->json(new AutonomousDecisionResource($decision->fresh(['confidenceEvidence', 'executionResults'])));
    }

    public function execute(string $decisionId, Request $request): JsonResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);

        if ($request->boolean('queued', true)) {
            ExecuteAutonomousDecisionJob::dispatch($decision->id);

            return response()->json([
                'queued' => true,
                'decision_id' => $decision->id,
            ]);
        }

        return response()->json(new AutonomousDecisionResource(
            $this->executor->execute($decision, $request->user())->load(['confidenceEvidence', 'executionResults'])
        ));
    }

    public function rollback(string $decisionId, Request $request): JsonResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);

        if ($request->boolean('queued', true)) {
            RollbackAutonomousDecisionJob::dispatch($decision->id);

            return response()->json([
                'queued' => true,
                'decision_id' => $decision->id,
            ]);
        }

        return response()->json(new AutonomousDecisionResource(
            $this->executor->rollback($decision, $request->user())->load(['confidenceEvidence', 'executionResults'])
        ));
    }
}
