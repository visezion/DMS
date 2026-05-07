<?php

namespace App\Http\Controllers\Web;

use App\Domain\Assistant\AssistantService;
use App\Domain\Common\DeviceTelemetryDataBuilder;
use App\Domain\EndpointIntelligence\CurrentPostureService;
use App\Domain\Remediation\AutonomyPolicyUpsertService;
use App\Domain\Remediation\RemediationPlannerService;
use App\Http\Controllers\Controller;
use App\Models\ActionRollback;
use App\Models\AiRecommendation;
use App\Models\ApprovalRequest;
use App\Models\AssistantMessage;
use App\Models\AssistantSession;
use App\Models\AutonomyPolicy;
use App\Models\CorrelatedIncident;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceHealthScore;
use App\Models\DeviceHealthSnapshot;
use App\Models\DeviceRiskScore;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\OperatorConversation;
use App\Models\PackageModel;
use App\Models\RemediationAction;
use App\Models\RemediationActionResult;
use App\Models\RemediationPlan;
use App\Models\ThreatFinding;
use App\Models\TimelineEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminEndpointIntelligenceController extends Controller
{
    public function __construct(
        private readonly AutonomyPolicyUpsertService $autonomyPolicies,
        private readonly CurrentPostureService $currentPosture,
    ) {
        $this->middleware(function ($request, $next) {
            if (! $this->endpointIntelligenceEnabled()) {
                return redirect()
                    ->route('admin.dashboard')
                    ->with('status', 'Endpoint Intelligence is disabled in Admin Settings.');
            }

            return $next($request);
        });
    }

    public function fleetHealthOverview(): View
    {
        $latestScores = $this->currentPosture->latestHealthScores();
        $freshness = $this->currentPosture->fleetFreshness();

        $bandCounts = [
            'healthy' => $latestScores->where('band', 'healthy')->count(),
            'warning' => $latestScores->where('band', 'warning')->count(),
            'degraded' => $latestScores->where('band', 'degraded')->count(),
            'critical' => $latestScores->where('band', 'critical')->count(),
        ];
        $topUnhealthy = $latestScores->sortBy('score')->take(12)->values();
        $topUnhealthyDeviceNames = Device::query()
            ->whereIn('id', $topUnhealthy->pluck('device_id')->filter()->unique()->values())
            ->pluck('hostname', 'id');

        $priorityFindings = $this->currentPosture->activeFindingsQuery()
            ->whereIn('severity', ['high', 'critical'])
            ->limit(8)
            ->get(['id', 'device_id', 'finding_type', 'severity', 'status', 'confidence', 'last_seen_at']);
        $priorityFindingDeviceNames = Device::query()
            ->whereIn('id', $priorityFindings->pluck('device_id')->filter()->unique()->values())
            ->pluck('hostname', 'id');

        $pendingApprovals = ApprovalRequest::query()->where('status', 'pending')->count();
        $pendingPlans = RemediationPlan::query()->where('status', 'pending_approval')->count();
        $executingPlans = RemediationPlan::query()->where('status', 'executing')->count();
        $openIncidents = CorrelatedIncident::query()->where('status', 'open')->count();

        $flowCards = [
            [
                'title' => 'Detect & Prioritize',
                'count' => $bandCounts['critical'] + $bandCounts['degraded'],
                'detail' => 'Critical + degraded health scores',
                'href' => route('admin.intelligence.health'),
                'cta' => 'Open Health',
            ],
            [
                'title' => 'Investigate Risk',
                'count' => $openIncidents,
                'detail' => 'Open correlated incidents',
                'href' => route('admin.intelligence.risk'),
                'cta' => 'Open Risk',
            ],
            [
                'title' => 'Approve & Remediate',
                'count' => $pendingApprovals + $pendingPlans,
                'detail' => 'Pending approvals and remediation plans',
                'href' => route('admin.intelligence.approvals'),
                'cta' => 'Open Approvals',
            ],
            [
                'title' => 'Execution Watch',
                'count' => $executingPlans,
                'detail' => 'Plans currently executing',
                'href' => route('admin.intelligence.remediation'),
                'cta' => 'Open Remediation',
            ],
        ];

        return view('admin.endpoint-intelligence.health-overview', [
            'metrics' => [
                'fleet_average' => round((float) $latestScores->avg('score'), 2),
                'critical_devices' => $bandCounts['critical'],
                'predicted_failures' => $latestScores->where('predicted_failure_risk', '>=', 70)->count(),
                'active_devices' => Device::query()->where('status', 'online')->count(),
                'freshness_health_age_min' => data_get($freshness, 'health_latest.age_minutes'),
                'freshness_risk_age_min' => data_get($freshness, 'risk_latest.age_minutes'),
                'stale_health_devices' => data_get($freshness, 'stale_health_devices', 0),
                'missing_health_scores' => data_get($freshness, 'health_missing_devices', 0),
            ],
            'bandCounts' => $bandCounts,
            'topUnhealthy' => $topUnhealthy,
            'topUnhealthyDeviceNames' => $topUnhealthyDeviceNames,
            'priorityFindings' => $priorityFindings,
            'priorityFindingDeviceNames' => $priorityFindingDeviceNames,
            'flowCards' => $flowCards,
            'freshness' => $freshness,
            'recentTrend' => DeviceHealthScore::query()->where('scored_at', '>=', now()->subDays(7))->orderBy('scored_at')->get(['device_id', 'score', 'band', 'scored_at']),
        ]);
    }

    public function deviceHealthDetail(string $deviceId): View
    {
        $device = Device::query()->findOrFail($deviceId);
        $health = $this->currentPosture->latestHealthScoreForDevice($deviceId);
        $risk = $this->currentPosture->latestRiskScoreForDevice($deviceId);
        $freshness = $this->currentPosture->deviceFreshness($deviceId);

        return view('admin.endpoint-intelligence.device-health-detail', [
            'device' => $device,
            'health' => $health,
            'risk' => $risk,
            'healthTrend' => DeviceHealthScore::query()->where('device_id', $deviceId)->latest('scored_at')->limit(20)->get()->reverse()->values(),
            'findings' => $this->currentPosture->activeFindingsQuery()
                ->where('device_id', $deviceId)
                ->limit(12)
                ->get(),
            'timeline' => TimelineEvent::query()->where('device_id', $deviceId)->latest('occurred_at')->limit(25)->get(),
            'freshness' => $freshness,
        ]);
    }

    public function telemetryDetail(string $deviceId, DeviceTelemetryDataBuilder $builder): View
    {
        $device = Device::query()->findOrFail($deviceId);
        $snapshot = DeviceHealthSnapshot::query()
            ->where('device_id', $deviceId)
            ->orderByDesc('snapshot_at')
            ->orderByDesc('created_at')
            ->first();

        $isLivePreview = false;
        $livePreviewGeneratedAt = null;
        $rawPayload = is_array($snapshot?->raw_payload) ? $snapshot->raw_payload : [];
        $metrics = is_array($snapshot?->metrics) ? $snapshot->metrics : [];

        if ($rawPayload === []) {
            $liveBuild = $builder->build($device);
            $rawPayload = is_array($liveBuild['raw_payload'] ?? null) ? $liveBuild['raw_payload'] : [];
            $metrics = is_array($liveBuild['metrics'] ?? null) ? $liveBuild['metrics'] : [];
            $isLivePreview = true;
            $livePreviewGeneratedAt = $liveBuild['snapshot_at'] ?? now();
        }

        $checklist = $this->buildTelemetryChecklist($rawPayload);
        $coverage = $this->buildTelemetryCoverage($rawPayload);
        $collectorError = trim((string) (
            data_get($rawPayload, 'windows_telemetry_meta.collection_error')
            ?: data_get($rawPayload, 'inventory.windows_telemetry.collection_error')
            ?: ''
        ));

        return view('admin.endpoint-intelligence.telemetry-detail', [
            'device' => $device,
            'snapshot' => $snapshot,
            'metrics' => $metrics,
            'coverage' => $coverage,
            'identity' => is_array(data_get($rawPayload, 'identity')) ? data_get($rawPayload, 'identity') : [],
            'behaviorSummary' => is_array(data_get($rawPayload, 'behavior_summary')) ? data_get($rawPayload, 'behavior_summary') : [],
            'windowsTelemetry' => is_array(data_get($rawPayload, 'windows_telemetry')) ? data_get($rawPayload, 'windows_telemetry') : [],
            'windowsTelemetryMeta' => array_filter([
                ... (is_array(data_get($rawPayload, 'windows_telemetry_meta')) ? data_get($rawPayload, 'windows_telemetry_meta') : []),
                'collection_error' => $collectorError !== '' ? $collectorError : null,
                'collector' => data_get($rawPayload, 'windows_telemetry_meta.collector', data_get($rawPayload, 'inventory.windows_telemetry.collector')),
                'collector_version' => data_get($rawPayload, 'windows_telemetry_meta.collector_version', data_get($rawPayload, 'inventory.windows_telemetry.collector_version')),
                'collected_at' => data_get($rawPayload, 'windows_telemetry_meta.collected_at', data_get($rawPayload, 'inventory.windows_telemetry.collected_at')),
                'stdout_tail' => data_get($rawPayload, 'windows_telemetry_meta.stdout_tail', data_get($rawPayload, 'inventory.windows_telemetry.stdout_tail')),
                'stderr_tail' => data_get($rawPayload, 'windows_telemetry_meta.stderr_tail', data_get($rawPayload, 'inventory.windows_telemetry.stderr_tail')),
            ], fn ($value) => $value !== null && $value !== ''),
            'rawPayload' => $rawPayload,
            'telemetryChecklist' => $checklist,
            'collectorError' => $collectorError !== '' ? $collectorError : null,
            'isLivePreview' => $isLivePreview,
            'livePreviewGeneratedAt' => $livePreviewGeneratedAt,
        ]);
    }

    public function riskDashboard(): View
    {
        $latestRiskScores = $this->currentPosture->latestRiskScores();
        $findings = $this->currentPosture->activeFindingsQuery()->limit(100)->get();
        $topDevices = $latestRiskScores
            ->sortByDesc(fn (DeviceRiskScore $score): float => (float) $score->score)
            ->take(15)
            ->values();
        $freshness = $this->currentPosture->fleetFreshness();
        $deviceIds = $findings
            ->pluck('device_id')
            ->merge($topDevices->pluck('device_id'))
            ->filter()
            ->unique()
            ->values();
        $deviceNames = Device::query()
            ->whereIn('id', $deviceIds)
            ->pluck('hostname', 'id');

        return view('admin.endpoint-intelligence.risk-dashboard', [
            'metrics' => [
                'fleet_risk_average' => round((float) $latestRiskScores->avg('score'), 2),
                'open_findings' => $this->currentPosture->activeFindingsQuery()->count(),
                'high_or_critical' => $this->currentPosture->activeFindingsQuery()->whereIn('severity', ['high', 'critical'])->count(),
                'devices_at_risk' => $latestRiskScores->where('score', '>=', 60)->count(),
                'freshness_risk_age_min' => data_get($freshness, 'risk_latest.age_minutes'),
                'stale_risk_devices' => data_get($freshness, 'stale_risk_devices', 0),
                'missing_risk_scores' => data_get($freshness, 'risk_missing_devices', 0),
            ],
            'findings' => $findings,
            'topDevices' => $topDevices,
            'deviceNames' => $deviceNames,
            'freshness' => $freshness,
        ]);
    }

    public function incidentExplorer(): View
    {
        return view('admin.endpoint-intelligence.incidents', [
            'metrics' => [
                'open_incidents' => CorrelatedIncident::query()->where('status', 'open')->count(),
                'critical_incidents' => CorrelatedIncident::query()->where('severity', 'critical')->count(),
                'timelines_built' => TimelineEvent::query()->count(),
                'open_findings' => ThreatFinding::query()->where('status', 'open')->count(),
            ],
            'incidents' => CorrelatedIncident::query()->latest('opened_at')->limit(50)->get(),
        ]);
    }

    public function incidentTimeline(string $incidentId): View
    {
        $incident = CorrelatedIncident::query()->with('timelines')->findOrFail($incidentId);

        return view('admin.endpoint-intelligence.incident-timeline', [
            'incident' => $incident,
            'events' => TimelineEvent::query()->where('incident_id', $incidentId)->orderBy('occurred_at')->get(),
        ]);
    }

    public function assistant(): View
    {
        $latestConversation = OperatorConversation::query()->latest('last_message_at')->first();
        $conversationIdFromQuery = trim((string) request()->query('conversation_id', ''));
        $startNewConversation = request()->boolean('new') && $conversationIdFromQuery === '';
        $selectedConversationId = $startNewConversation
            ? ''
            : ($conversationIdFromQuery !== '' ? $conversationIdFromQuery : ($latestConversation?->id ?? ''));
        $recentConversations = OperatorConversation::query()->latest('last_message_at')->limit(20)->get();
        $recentMessages = collect();

        if ($selectedConversationId !== '') {
            $sessionIds = AssistantSession::query()
                ->where('conversation_id', $selectedConversationId)
                ->orderByDesc('started_at')
                ->limit(60)
                ->pluck('id');

            if ($sessionIds->isNotEmpty()) {
                $recentMessages = AssistantMessage::query()
                    ->whereIn('session_id', $sessionIds)
                    ->orderBy('created_at')
                    ->get();
            }
        }

        $selectedConversation = $selectedConversationId !== ''
            ? OperatorConversation::query()->find($selectedConversationId)
            : null;
        $selectedScope = is_array($selectedConversation?->scope) ? $selectedConversation->scope : [];
        $selectedMode = trim((string) request()->query('mode', 'investigate'));
        if (! in_array($selectedMode, ['explain', 'investigate', 'recommend', 'guided_fix'], true)) {
            $selectedMode = 'investigate';
        }
        foreach (['device_id', 'group_id', 'package_id'] as $scopeKey) {
            $queryValue = trim((string) request()->query($scopeKey, ''));
            if ($queryValue !== '') {
                $selectedScope[$scopeKey] = $queryValue;
            }
        }

        $devices = Device::query()->latest('updated_at')->limit(50)->get(['id', 'hostname']);
        $groups = DeviceGroup::query()->latest('updated_at')->limit(50)->get(['id', 'name']);
        $packages = PackageModel::query()->latest('updated_at')->limit(50)->get(['id', 'name', 'slug']);

        $selectedDeviceName = null;
        if (! empty($selectedScope['device_id'])) {
            $selectedDeviceName = optional($devices->firstWhere('id', $selectedScope['device_id']))->hostname
                ?? Device::query()->where('id', $selectedScope['device_id'])->value('hostname');
        }

        $selectedGroupName = null;
        if (! empty($selectedScope['group_id'])) {
            $selectedGroupName = optional($groups->firstWhere('id', $selectedScope['group_id']))->name
                ?? DeviceGroup::query()->where('id', $selectedScope['group_id'])->value('name');
        }

        $selectedPackageName = null;
        if (! empty($selectedScope['package_id'])) {
            $selectedPackageName = optional($packages->firstWhere('id', $selectedScope['package_id']))->name
                ?? PackageModel::query()->where('id', $selectedScope['package_id'])->value('name');
        }

        return view('admin.endpoint-intelligence.assistant', [
            'metrics' => [
                'sessions_24h' => AssistantSession::query()->where('created_at', '>=', now()->subDay())->count(),
                'assistant_messages' => AssistantMessage::query()->count(),
                'devices_with_scores' => DeviceHealthScore::query()->distinct('device_id')->count('device_id'),
                'open_findings' => ThreatFinding::query()->where('status', 'open')->count(),
                'open_incidents' => CorrelatedIncident::query()->where('status', 'open')->count(),
                'pending_approvals' => ApprovalRequest::query()->where('status', 'pending')->count(),
            ],
            'recentSessions' => AssistantSession::query()->latest('started_at')->limit(20)->get(),
            'recentConversations' => $recentConversations,
            'selectedConversationId' => $selectedConversationId !== '' ? $selectedConversationId : null,
            'recentMessages' => $recentMessages,
            'devices' => $devices,
            'groups' => $groups,
            'packages' => $packages,
            'selectedScope' => [
                'device_id' => (string) ($selectedScope['device_id'] ?? ''),
                'group_id' => (string) ($selectedScope['group_id'] ?? ''),
                'package_id' => (string) ($selectedScope['package_id'] ?? ''),
            ],
            'selectedMode' => $selectedMode,
            'selectedScopeLabels' => [
                'device' => $selectedDeviceName,
                'group' => $selectedGroupName,
                'package' => $selectedPackageName,
            ],
            'quickPrompts' => [
                'Show the top 5 risky devices and why.',
                'Summarize today\'s critical findings with confidence.',
                'What safe remediation can run without approval right now?',
                'Which group needs immediate triage and why?',
                'Give me a step-by-step guided fix for a degraded endpoint.',
                'What context is missing before we can auto-remediate?',
            ],
            'assistantFlow' => [
                [
                    'title' => 'Scope',
                    'description' => 'Pin device, group, or package before asking.',
                ],
                [
                    'title' => 'Investigate',
                    'description' => 'Ask for evidence-backed facts and inferences.',
                ],
                [
                    'title' => 'Act',
                    'description' => 'Review recommended actions and approval needs.',
                ],
                [
                    'title' => 'Track',
                    'description' => 'Push actions to remediation and monitor outcomes.',
                ],
            ],
        ]);
    }

    public function remediationQueue(): View
    {
        return view('admin.endpoint-intelligence.remediation', [
            'metrics' => [
                'plans_total' => RemediationPlan::query()->count(),
                'pending_approval' => RemediationPlan::query()->where('status', 'pending_approval')->count(),
                'executing' => RemediationPlan::query()->where('status', 'executing')->count(),
                'rollbacks_available' => ActionRollback::query()->whereIn('status', ['available', 'queued'])->count(),
            ],
            'plans' => RemediationPlan::query()->with('actions')->latest('created_at')->limit(30)->get(),
            'recentResults' => RemediationActionResult::query()->latest('created_at')->limit(20)->get(),
        ]);
    }

    public function approvalCenter(): View
    {
        return view('admin.endpoint-intelligence.approvals', [
            'metrics' => [
                'pending' => ApprovalRequest::query()->where('status', 'pending')->count(),
                'expired' => ApprovalRequest::query()->where('status', 'expired')->count(),
                'breached_sla' => ApprovalRequest::query()->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(30))->count(),
                'approved_today' => ApprovalRequest::query()->where('status', 'approved')->where('updated_at', '>=', now()->startOfDay())->count(),
            ],
            'approvals' => ApprovalRequest::query()->latest('created_at')->limit(40)->get(),
        ]);
    }

    public function actionHistory(): View
    {
        return view('admin.endpoint-intelligence.actions', [
            'metrics' => [
                'completed_actions' => RemediationAction::query()->where('status', 'completed')->count(),
                'failed_actions' => RemediationAction::query()->where('status', 'failed')->count(),
                'rollback_records' => ActionRollback::query()->count(),
                'success_results' => RemediationActionResult::query()->where('status', 'success')->count(),
            ],
            'results' => RemediationActionResult::query()->latest('created_at')->limit(50)->get(),
            'rollbacks' => ActionRollback::query()->latest('created_at')->limit(25)->get(),
        ]);
    }

    public function autonomySettings(): View
    {
        return view('admin.endpoint-intelligence.autonomy', [
            'metrics' => [
                'policies_total' => AutonomyPolicy::query()->count(),
                'active_policies' => AutonomyPolicy::query()->where('active', true)->count(),
                'global_policies' => AutonomyPolicy::query()->where('scope_type', 'global')->count(),
                'queued_remediation' => RemediationPlan::query()->where('status', 'executing')->count(),
            ],
            'policies' => AutonomyPolicy::query()->latest('updated_at')->limit(30)->get(),
        ]);
    }

    public function saveAutonomyPolicy(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scope_type' => ['required', 'in:global,tenant,group,device'],
            'scope_id' => ['nullable', 'string', 'max:64', 'required_unless:scope_type,global'],
            'autonomy_level' => ['required', 'in:off,advisory,semi_auto,auto'],
            'allowed_actions' => ['nullable', 'array'],
            'blocked_conditions' => ['nullable', 'array'],
            'maintenance_windows' => ['nullable', 'array'],
            'max_parallel_actions' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $policy = $this->autonomyPolicies->upsert($payload);

        return response()->json([
            'message' => 'Autonomy policy saved.',
            'policy' => $policy,
        ]);
    }

    public function askAssistant(Request $request, AssistantService $assistant): JsonResponse
    {
        $payload = $request->validate([
            'question' => ['required', 'string', 'max:4000'],
            'mode' => ['nullable', 'in:explain,investigate,recommend,guided_fix'],
            'device_id' => ['nullable', 'uuid'],
            'group_id' => ['nullable', 'uuid'],
            'package_id' => ['nullable', 'uuid'],
            'incident_id' => ['nullable', 'uuid'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        $result = $assistant->ask($payload, $request->user());
        $answer = is_array($result['answer'] ?? null) ? $result['answer'] : [];
        $recommendedActions = is_array($answer['recommended_actions'] ?? null) ? $answer['recommended_actions'] : [];
        $needsApproval = collect($recommendedActions)->contains(fn (mixed $action): bool => (bool) data_get($action, 'approval_required', false));

        $result['flow'] = [
            'mode' => (string) ($payload['mode'] ?? 'investigate'),
            'risk_level' => (string) ($answer['risk_level'] ?? 'unknown'),
            'confidence_percent' => max(0, min(100, (int) round(((float) ($answer['confidence_score'] ?? 0)) * 100))),
            'recommended_action_count' => count($recommendedActions),
            'next_step' => $recommendedActions === []
                ? 'collect_more_context'
                : ($needsApproval ? 'operator_approval' : 'execute_safe_actions'),
        ];

        return response()->json($result);
    }

    public function approveRequest(Request $request, string $approvalId): JsonResponse
    {
        $approval = ApprovalRequest::query()->findOrFail($approvalId);
        $approval->update([
            'status' => 'approved',
            'decided_by' => $request->user()?->id,
            'decided_at' => now(),
        ]);

        return response()->json($approval);
    }

    public function rejectRequest(Request $request, string $approvalId): JsonResponse
    {
        $approval = ApprovalRequest::query()->findOrFail($approvalId);
        $approval->update([
            'status' => 'rejected',
            'decided_by' => $request->user()?->id,
            'decided_at' => now(),
            'decision_note' => (string) $request->input('note', 'Rejected by operator.'),
        ]);

        return response()->json($approval);
    }

    public function validateRemediationPlan(string $planId): JsonResponse
    {
        $plan = RemediationPlan::query()->with('actions')->findOrFail($planId);

        return response()->json([
            'plan' => $plan,
            'ready' => $plan->actions->every(fn (RemediationAction $action) => in_array($action->status, ['pending', 'queued'], true)),
        ]);
    }

    public function approveRemediationPlan(string $planId, Request $request, RemediationPlannerService $planner): JsonResponse
    {
        $plan = RemediationPlan::query()->with('actions')->findOrFail($planId);

        return response()->json($planner->approve($plan, $request->user()));
    }

    public function executeRemediationPlan(string $planId, Request $request, RemediationPlannerService $planner): JsonResponse
    {
        $plan = RemediationPlan::query()->with('actions')->findOrFail($planId);

        return response()->json($planner->execute($plan, $request->user()));
    }

    public function rollbackRemediationAction(string $actionId): JsonResponse
    {
        $action = RemediationAction::query()->findOrFail($actionId);
        $latestResult = $action->results()->latest('created_at')->first();
        $rollbackHint = is_array(data_get($latestResult?->evidence ?? [], 'rollback_hint')) ? data_get($latestResult?->evidence ?? [], 'rollback_hint') : [];
        $rollbackActionType = (string) ($rollbackHint['job_type'] ?? 'manual_review');
        $rollbackArgs = is_array($rollbackHint['payload'] ?? null)
            ? $rollbackHint['payload']
            : (is_array($rollbackHint) ? $rollbackHint : ['action_id' => $action->id]);

        $rollback = ActionRollback::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $action->tenant_id,
            'action_result_id' => $latestResult?->id,
            'rollback_action_type' => $rollbackActionType,
            'rollback_args' => $rollbackArgs,
            'status' => ($rollbackHint['possible'] ?? false) ? 'queued' : 'requested',
            'result' => [],
            'started_at' => now(),
        ]);

        if (($rollbackHint['possible'] ?? false) && $action->target_device_id) {
            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $action->tenant_id,
                'job_type' => $rollbackActionType === 'manual_review' ? 'run_command' : $rollbackActionType,
                'payload' => $rollbackArgs,
                'target_type' => 'device',
                'target_id' => $action->target_device_id,
                'priority' => 80,
                'status' => 'queued',
            ]);

            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $action->target_device_id,
                'status' => 'pending',
            ]);

            $rollback->update([
                'result' => [
                    'rollback_job_id' => $job->id,
                ],
            ]);
        }

        return response()->json($rollback, 201);
    }

    public function engineTuning(): View
    {
        $freshness = $this->currentPosture->fleetFreshness();

        return view('admin.endpoint-intelligence.tuning', [
            'metrics' => [
                'health_scores_7d' => DeviceHealthScore::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'risk_scores_7d' => DeviceRiskScore::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'findings_reviewed_7d' => ThreatFinding::query()->whereNotNull('reviewed_at')->where('reviewed_at', '>=', now()->subDays(7))->count(),
                'assistant_sessions_7d' => AssistantSession::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'stale_health_devices' => data_get($freshness, 'stale_health_devices', 0),
                'stale_risk_devices' => data_get($freshness, 'stale_risk_devices', 0),
                'health_latest_age_min' => data_get($freshness, 'health_latest.age_minutes'),
                'risk_latest_age_min' => data_get($freshness, 'risk_latest.age_minutes'),
            ],
            'suggestions' => [
                ['engine' => 'Health', 'suggestion' => 'Tune disk pressure penalty for kiosk devices with small system partitions.', 'status' => 'review'],
                ['engine' => 'Risk', 'suggestion' => 'Promote failed login burst to high severity when paired with suspicious PowerShell.', 'status' => 'review'],
                ['engine' => 'Assistant', 'suggestion' => 'Add grounded examples for remediation summaries with rollback-safe recommendations.', 'status' => 'review'],
                ['engine' => 'Remediation', 'suggestion' => 'Allow semi-auto inventory reruns for medium confidence device-health degradations.', 'status' => 'review'],
            ],
            'freshness' => $freshness,
        ]);
    }

    public function executiveSummary(string $deviceId): View
    {
        $device = Device::query()->findOrFail($deviceId);

        return view('admin.endpoint-intelligence.executive-summary', [
            'device' => $device,
            'health' => $this->currentPosture->latestHealthScoreForDevice($deviceId),
            'risk' => $this->currentPosture->latestRiskScoreForDevice($deviceId),
            'findings' => $this->currentPosture->activeFindingsQuery()
                ->where('device_id', $deviceId)
                ->limit(10)
                ->get(),
            'incident' => CorrelatedIncident::query()->where('primary_device_id', $deviceId)->where('status', 'open')->latest('opened_at')->first(),
            'recentActions' => RemediationActionResult::query()
                ->whereIn('action_id', RemediationAction::query()->where('target_device_id', $deviceId)->pluck('id'))
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    private function buildTelemetryChecklist(array $rawPayload): array
    {
        $identity = is_array(data_get($rawPayload, 'identity')) ? data_get($rawPayload, 'identity') : [];
        $inventory = is_array(data_get($rawPayload, 'inventory')) ? data_get($rawPayload, 'inventory') : [];
        $runtime = is_array(data_get($rawPayload, 'runtime_diagnostics')) ? data_get($rawPayload, 'runtime_diagnostics') : [];
        $windowsTelemetry = is_array(data_get($rawPayload, 'windows_telemetry')) ? data_get($rawPayload, 'windows_telemetry') : [];
        $behaviorSummary = is_array(data_get($rawPayload, 'behavior_summary')) ? data_get($rawPayload, 'behavior_summary') : [];

        $checks = [
            [
                'key' => 'device_identity',
                'label' => 'Device Identity',
                'present' => filled(data_get($identity, 'hostname'))
                    && (
                        filled(data_get($identity, 'serial_number'))
                        || filled(data_get($identity, 'manufacturer'))
                        || filled(data_get($identity, 'model'))
                        || filled(data_get($identity, 'windows_edition'))
                        || filled(data_get($identity, 'windows_build_number'))
                    ),
                'summary' => 'Hostname, serial, OS edition, manufacturer, model.',
            ],
            [
                'key' => 'system_health',
                'label' => 'System Health',
                'present' => (
                    is_numeric(data_get($windowsTelemetry, 'system_health_and_performance.memory_usage_percent'))
                    || is_numeric(data_get($windowsTelemetry, 'system_health_and_performance.memory_total_bytes'))
                    || is_numeric(data_get($runtime, 'memory_usage_percent'))
                    || is_numeric(data_get($inventory, 'memory.total_bytes'))
                ) && (
                    is_array(data_get($windowsTelemetry, 'system_health_and_performance.disk_space_per_drive'))
                    || is_array(data_get($inventory, 'disks'))
                    || is_array(data_get($inventory, 'drives'))
                ) && (
                    is_numeric(data_get($windowsTelemetry, 'system_health_and_performance.cpu_usage_percent'))
                    || is_numeric(data_get($runtime, 'cpu_usage_percent'))
                    || is_numeric(data_get($windowsTelemetry, 'system_health_and_performance.service_failures_24h'))
                    || $this->hasStructuredData(data_get($windowsTelemetry, 'system_health_and_performance.running_services_status'))
                    || filled(data_get($runtime, 'collected_at'))
                ),
                'summary' => 'CPU, memory, disk, uptime, crash and service health.',
            ],
            [
                'key' => 'event_logs',
                'label' => 'Windows Event Logs',
                'present' => $this->hasStructuredData(data_get($windowsTelemetry, 'windows_event_logs.logs_24h'))
                    && $this->hasStructuredData(data_get($windowsTelemetry, 'windows_event_logs.important_event_counts_24h')),
                'summary' => 'System, Application, Security, Defender, PowerShell, Update.',
            ],
            [
                'key' => 'process_activity',
                'label' => 'Process and Application Activity',
                'present' => (
                    $this->hasStructuredData(data_get($windowsTelemetry, 'process_and_application_activity.running_processes'))
                    || $this->hasStructuredData(data_get($inventory, 'running_processes'))
                ) && (
                    $this->hasStructuredData(data_get($windowsTelemetry, 'process_and_application_activity.installed_software'))
                    || $this->hasStructuredData(data_get($inventory, 'installed_software'))
                ),
                'summary' => 'Processes, installed software, startup apps, tasks, services.',
            ],
            [
                'key' => 'security_posture',
                'label' => 'Security Posture',
                'present' => is_array(data_get($windowsTelemetry, 'security_posture'))
                    && (
                        data_get($windowsTelemetry, 'security_posture.microsoft_defender_status') !== null
                        || data_get($windowsTelemetry, 'security_posture.firewall_status') !== null
                    ),
                'summary' => 'Defender, firewall, BitLocker, TPM, Secure Boot, patch state.',
            ],
            [
                'key' => 'authentication',
                'label' => 'Authentication and User Activity',
                'present' => $this->hasStructuredData(data_get($windowsTelemetry, 'authentication_and_user_activity.login_events'))
                    || $this->hasStructuredData(data_get($windowsTelemetry, 'authentication_and_user_activity.auth_event_samples'))
                    || $this->hasStructuredData(data_get($inventory, 'logged_in_sessions')),
                'summary' => 'Logins, failures, lock/unlock, elevation, account changes.',
            ],
            [
                'key' => 'file_storage',
                'label' => 'File and Storage Activity',
                'present' => $this->hasStructuredData(data_get($windowsTelemetry, 'file_and_storage_activity.low_disk_alerts'))
                    || $this->hasStructuredData(data_get($windowsTelemetry, 'file_and_storage_activity.download_folder_activity'))
                    || $this->hasStructuredData(data_get($inventory, 'disks'))
                    || $this->hasStructuredData(data_get($inventory, 'drives')),
                'summary' => 'Low disk, folder changes, deletes, downloads, recycle-bin signals.',
            ],
            [
                'key' => 'network',
                'label' => 'Network Telemetry',
                'present' => $this->hasStructuredData(data_get($windowsTelemetry, 'network_telemetry.active_tcp_connections'))
                    || $this->hasStructuredData(data_get($windowsTelemetry, 'network_telemetry.frequent_outbound_destinations'))
                    || $this->hasStructuredData(data_get($windowsTelemetry, 'network_telemetry.ip_addresses'))
                    || $this->hasStructuredData(data_get($inventory, 'network.ip_addresses')),
                'summary' => 'Connections, remote IPs, DNS, gateway, Wi-Fi, VPN, bytes.',
            ],
            [
                'key' => 'configuration',
                'label' => 'Configuration and Policy State',
                'present' => is_array(data_get($windowsTelemetry, 'configuration_and_policy_state'))
                    && (
                        data_get($windowsTelemetry, 'configuration_and_policy_state.windows_update_policy') !== null
                        || data_get($windowsTelemetry, 'configuration_and_policy_state.remote_management_state') !== null
                    ),
                'summary' => 'Update policy, Defender policy, DNS, proxy, RDP, PowerShell policy.',
            ],
            [
                'key' => 'smart_ops',
                'label' => 'Smart Operational Data',
                'present' => is_array(data_get($windowsTelemetry, 'smart_operational_data'))
                    && (
                        data_get($windowsTelemetry, 'smart_operational_data.incident_count_per_device') !== null
                        || data_get($windowsTelemetry, 'smart_operational_data.health_trend_over_time') !== null
                    ),
                'summary' => 'Crash trend, patch failures, incident count, health/risk trends.',
            ],
            [
                'key' => 'behavior_events',
                'label' => 'Behavior Event Stream',
                'present' => (int) data_get($behaviorSummary, 'recent_event_count', 0) > 0,
                'summary' => 'User logon, app launch, file access, with session and process lineage.',
            ],
        ];

        $present = collect($checks)->where('present', true)->count();
        $missing = count($checks) - $present;

        return [
            'items' => $checks,
            'present' => $present,
            'missing' => $missing,
            'coverage_percent' => count($checks) > 0 ? (int) round(($present / count($checks)) * 100) : 0,
        ];
    }

    private function buildTelemetryCoverage(array $rawPayload): array
    {
        $stored = is_array(data_get($rawPayload, 'telemetry_coverage')) ? data_get($rawPayload, 'telemetry_coverage') : [];
        $collectorError = trim((string) (
            data_get($rawPayload, 'windows_telemetry_meta.collection_error')
            ?: data_get($rawPayload, 'inventory.windows_telemetry.collection_error')
            ?: ''
        ));

        return array_merge($stored, [
            'windows_telemetry_present' => $collectorError === ''
                && $this->hasStructuredData(data_get($rawPayload, 'windows_telemetry')),
            'behavior_logs_present' => (int) data_get($rawPayload, 'behavior_summary.recent_event_count', 0) > 0,
            'runtime_diagnostics_present' => $this->hasStructuredData(data_get($rawPayload, 'runtime_diagnostics')),
            'inventory_present' => $this->hasStructuredData(data_get($rawPayload, 'inventory')),
            'security_posture_present' => $this->hasStructuredData(data_get($rawPayload, 'windows_telemetry.security_posture')),
            'network_telemetry_present' => $this->hasStructuredData(data_get($rawPayload, 'windows_telemetry.network_telemetry'))
                || $this->hasStructuredData(data_get($rawPayload, 'inventory.network')),
            'configuration_state_present' => $this->hasStructuredData(data_get($rawPayload, 'windows_telemetry.configuration_and_policy_state')),
        ]);
    }

    private function hasStructuredData(mixed $value): bool
    {
        if (!is_array($value)) {
            return $value !== null && $value !== '';
        }

        if ($value === []) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->hasStructuredData($item)) {
                return true;
            }
        }

        return false;
    }

    private function endpointIntelligenceEnabled(): bool
    {
        $setting = ControlPlaneSetting::query()->find('endpoint_intelligence.enabled');
        if (! $setting || ! is_array($setting->value)) {
            return true;
        }

        return (bool) ($setting->value['value'] ?? true);
    }
}
