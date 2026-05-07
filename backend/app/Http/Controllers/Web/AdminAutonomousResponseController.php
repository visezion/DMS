<?php

namespace App\Http\Controllers\Web;

use App\Domain\Autonomy\AutonomousDecisionExecutor;
use App\Domain\Autonomy\AutonomousResponseEngine;
use App\Domain\Remediation\ActionCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\AutonomousDecisionActionRequest;
use App\Http\Requests\EvaluateAutonomousDecisionRequest;
use App\Http\Requests\StoreAutonomousResponsePolicyRequest;
use App\Http\Requests\StoreRiskActionMappingRequest;
use App\Models\AutonomousDecision;
use App\Models\AutonomousResponsePolicy;
use App\Models\CorrelatedIncident;
use App\Models\Device;
use App\Models\RiskActionMapping;
use App\Models\ThreatFinding;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminAutonomousResponseController extends Controller
{
    public function __construct(
        private readonly ActionCatalog $catalog,
        private readonly AutonomousResponseEngine $engine,
        private readonly AutonomousDecisionExecutor $executor,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function policies(): View
    {
        return view('admin.endpoint-intelligence.autonomous.policies', [
            'policies' => AutonomousResponsePolicy::query()->latest('updated_at')->get(),
            'catalog' => collect($this->catalog->all())->map(fn (array $meta, string $key): array => array_merge(['key' => $key], $meta))->values(),
        ]);
    }

    public function storePolicy(StoreAutonomousResponsePolicyRequest $request): RedirectResponse
    {
        AutonomousResponsePolicy::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => null,
            'name' => $request->string('name')->toString(),
            'scope_type' => $request->string('scope_type')->toString(),
            'scope_id' => $request->input('scope_id') ?: null,
            'trigger_type' => $request->string('trigger_type')->toString(),
            'minimum_risk_score' => (float) $request->input('minimum_risk_score', 0),
            'allowed_actions' => $request->input('allowed_actions', []),
            'blocked_actions' => $request->input('blocked_actions', []),
            'autonomy_mode' => $request->string('autonomy_mode')->toString(),
            'minimum_confidence' => (float) $request->input('minimum_confidence', config('autonomous_response.default_confidence', 70)),
            'requires_rollback_plan' => $request->boolean('requires_rollback_plan'),
            'max_actions_per_hour' => (int) $request->input('max_actions_per_hour', 4),
            'cooldown_minutes' => (int) $request->input('cooldown_minutes', 30),
            'enabled' => $request->boolean('enabled', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('admin.intelligence.autonomous.policies')->with('status', 'Autonomous response policy saved.');
    }

    public function updatePolicy(StoreAutonomousResponsePolicyRequest $request, string $policyId): RedirectResponse
    {
        $policy = AutonomousResponsePolicy::query()->findOrFail($policyId);
        $before = $policy->toArray();
        $policy->update([
            'name' => $request->string('name')->toString(),
            'scope_type' => $request->string('scope_type')->toString(),
            'scope_id' => $request->input('scope_id') ?: null,
            'trigger_type' => $request->string('trigger_type')->toString(),
            'minimum_risk_score' => (float) $request->input('minimum_risk_score', 0),
            'allowed_actions' => $request->input('allowed_actions', []),
            'blocked_actions' => $request->input('blocked_actions', []),
            'autonomy_mode' => $request->string('autonomy_mode')->toString(),
            'minimum_confidence' => (float) $request->input('minimum_confidence', config('autonomous_response.default_confidence', 70)),
            'requires_rollback_plan' => $request->boolean('requires_rollback_plan'),
            'max_actions_per_hour' => (int) $request->input('max_actions_per_hour', 4),
            'cooldown_minutes' => (int) $request->input('cooldown_minutes', 30),
            'enabled' => $request->boolean('enabled', false),
            'updated_by' => $request->user()?->id,
        ]);

        $this->auditLogger->log('autonomous.policy.update.web', 'autonomous_response_policy', $policy->id, $before, $policy->fresh()?->toArray(), $request->user()?->id);

        return redirect()->route('admin.intelligence.autonomous.policies')->with('status', 'Autonomous response policy updated.');
    }

    public function mappings(): View
    {
        return view('admin.endpoint-intelligence.autonomous.mappings', [
            'mappings' => RiskActionMapping::query()->latest('updated_at')->get(),
            'catalog' => collect($this->catalog->all())->map(fn (array $meta, string $key): array => array_merge(['key' => $key], $meta))->values(),
        ]);
    }

    public function storeMapping(StoreRiskActionMappingRequest $request): RedirectResponse
    {
        RiskActionMapping::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => null,
            'name' => $request->string('name')->toString(),
            'trigger_type' => $request->string('trigger_type')->toString(),
            'minimum_severity' => $request->input('minimum_severity') ?: null,
            'maximum_severity' => $request->input('maximum_severity') ?: null,
            'minimum_risk_score' => (float) $request->input('minimum_risk_score', 0),
            'maximum_risk_score' => $request->filled('maximum_risk_score') ? (float) $request->input('maximum_risk_score') : null,
            'candidate_actions' => $request->input('candidate_actions', []),
            'preconditions' => $request->input('preconditions', []),
            'rollback_metadata' => $request->input('rollback_metadata', []),
            'enabled' => $request->boolean('enabled', true),
            'priority' => (int) $request->input('priority', 100),
        ]);

        return redirect()->route('admin.intelligence.autonomous.mappings')->with('status', 'Risk-to-action mapping saved.');
    }

    public function updateMapping(StoreRiskActionMappingRequest $request, string $mappingId): RedirectResponse
    {
        $mapping = RiskActionMapping::query()->findOrFail($mappingId);
        $mapping->update([
            'name' => $request->string('name')->toString(),
            'trigger_type' => $request->string('trigger_type')->toString(),
            'minimum_severity' => $request->input('minimum_severity') ?: null,
            'maximum_severity' => $request->input('maximum_severity') ?: null,
            'minimum_risk_score' => (float) $request->input('minimum_risk_score', 0),
            'maximum_risk_score' => $request->filled('maximum_risk_score') ? (float) $request->input('maximum_risk_score') : null,
            'candidate_actions' => $request->input('candidate_actions', []),
            'preconditions' => $request->input('preconditions', []),
            'rollback_metadata' => $request->input('rollback_metadata', []),
            'enabled' => $request->boolean('enabled', false),
            'priority' => (int) $request->input('priority', 100),
        ]);

        return redirect()->route('admin.intelligence.autonomous.mappings')->with('status', 'Risk-to-action mapping updated.');
    }

    public function decisions(Request $request): View
    {
        $query = AutonomousDecision::query()->with(['confidenceEvidence', 'executionResults'])->latest('created_at');
        foreach (['status', 'decision_mode', 'recommended_action'] as $filter) {
            $value = trim((string) $request->query($filter, ''));
            if ($value !== '') {
                $query->where($filter, $value);
            }
        }

        return view('admin.endpoint-intelligence.autonomous.decisions', [
            'decisions' => $query->paginate(20)->withQueryString(),
            'metrics' => [
                'pending_approval' => AutonomousDecision::query()->where('status', 'pending_approval')->count(),
                'auto_executed_24h' => AutonomousDecision::query()->where('decision_mode', 'auto_execute')->where('created_at', '>=', now()->subDay())->count(),
                'failed_24h' => AutonomousDecision::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                'rolled_back_7d' => AutonomousDecision::query()->where('status', 'rolled_back')->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function showDecision(string $decisionId): View
    {
        return view('admin.endpoint-intelligence.autonomous.decision-detail', [
            'decision' => AutonomousDecision::query()->with(['confidenceEvidence', 'executionResults'])->findOrFail($decisionId),
        ]);
    }

    public function catalog(): View
    {
        return view('admin.endpoint-intelligence.autonomous.catalog', [
            'catalog' => collect($this->catalog->all())->map(fn (array $meta, string $key): array => array_merge(['key' => $key], $meta))->values(),
        ]);
    }

    public function simulate(Request $request): View
    {
        return view('admin.endpoint-intelligence.autonomous.simulate', [
            'devices' => Device::query()->latest('updated_at')->limit(50)->get(['id', 'hostname']),
            'findings' => ThreatFinding::query()->latest('last_seen_at')->limit(50)->get(['id', 'finding_type', 'device_id']),
            'incidents' => CorrelatedIncident::query()->latest('opened_at')->limit(50)->get(['id', 'title', 'primary_device_id']),
            'preview' => session('autonomous_preview'),
        ]);
    }

    public function simulateEvaluate(EvaluateAutonomousDecisionRequest $request): View
    {
        $preview = $this->engine->evaluate(
            array_merge($request->validated(), ['simulation' => true, 'dry_run' => true]),
            $request->user(),
            false
        );

        return view('admin.endpoint-intelligence.autonomous.simulate', [
            'devices' => Device::query()->latest('updated_at')->limit(50)->get(['id', 'hostname']),
            'findings' => ThreatFinding::query()->latest('last_seen_at')->limit(50)->get(['id', 'finding_type', 'device_id']),
            'incidents' => CorrelatedIncident::query()->latest('opened_at')->limit(50)->get(['id', 'title', 'primary_device_id']),
            'preview' => $preview,
        ]);
    }

    public function evaluate(EvaluateAutonomousDecisionRequest $request): RedirectResponse
    {
        $this->engine->evaluate($request->validated(), $request->user());

        return redirect()->route('admin.intelligence.autonomous.decisions')->with('status', 'Autonomous decision evaluated.');
    }

    public function approve(AutonomousDecisionActionRequest $request, string $decisionId): RedirectResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);
        $decision->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return back()->with('status', 'Decision approved.');
    }

    public function reject(AutonomousDecisionActionRequest $request, string $decisionId): RedirectResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);
        $decision->update([
            'status' => 'rejected',
            'failure_reason' => (string) $request->input('note', 'Rejected by operator.'),
        ]);

        return back()->with('status', 'Decision rejected.');
    }

    public function execute(string $decisionId, Request $request): RedirectResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);
        $this->executor->execute($decision, $request->user());

        return back()->with('status', 'Decision executed through remediation pipeline.');
    }

    public function rollback(string $decisionId, Request $request): RedirectResponse
    {
        $decision = AutonomousDecision::query()->findOrFail($decisionId);
        $this->executor->rollback($decision, $request->user());

        return back()->with('status', 'Rollback requested for autonomous decision.');
    }
}
