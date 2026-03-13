<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillBehaviorBaselineProfilesJob;
use App\Jobs\BackfillBehaviorDatasetJob;
use App\Jobs\ProcessBehaviorEventStreamJob;
use App\Jobs\RetrainAdaptiveBehaviorModelJob;
use App\Jobs\SweepAutonomousRemediationCasesJob;
use App\Jobs\TrainBehaviorAiModelJob;
use App\Models\AiEventStream;
use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorRemediationExecution;
use App\Models\DeviceBehaviorBaseline;
use App\Models\DeviceBehaviorDriftEvent;
use App\Models\BehaviorPolicyFeedback;
use App\Models\BehaviorPolicyRecommendation;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\PolicyVersion;
use App\Services\BehaviorPipeline\HumanFeedbackService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class BehaviorAiController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'case_status' => trim((string) $request->query('case_status', '')),
            'severity' => trim((string) $request->query('severity', '')),
            'recommendation_status' => trim((string) $request->query('recommendation_status', '')),
            'action' => trim((string) $request->query('action', '')),
            'q' => trim((string) $request->query('q', '')),
        ];

        $casesQuery = BehaviorAnomalyCase::query()
            ->with(['device:id,hostname'])
            ->orderByDesc('detected_at');
        if (in_array($filters['case_status'], ['pending_review', 'approved', 'dismissed', 'auto_applied'], true)) {
            $casesQuery->where('status', $filters['case_status']);
        }
        if (in_array($filters['severity'], ['low', 'medium', 'high'], true)) {
            $casesQuery->where('severity', $filters['severity']);
        }
        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $casesQuery->where(function ($builder) use ($like) {
                $builder->where('summary', 'like', $like)
                    ->orWhere('device_id', 'like', $like)
                    ->orWhere('behavior_log_id', 'like', $like)
                    ->orWhere('id', 'like', $like);
            });
        }

        $recommendationsQuery = BehaviorPolicyRecommendation::query()
            ->with(['anomalyCase.device:id,hostname', 'feedbackEntries'])
            ->orderByDesc('created_at');
        if (in_array($filters['recommendation_status'], ['pending', 'approved', 'rejected', 'applied', 'auto_applied'], true)) {
            $recommendationsQuery->where('status', $filters['recommendation_status']);
        }
        if (in_array($filters['action'], ['observe', 'notify', 'apply_policy'], true)) {
            $recommendationsQuery->where('recommended_action', $filters['action']);
        }
        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $recommendationsQuery->where(function ($builder) use ($like) {
                $builder->where('id', 'like', $like)
                    ->orWhere('anomaly_case_id', 'like', $like)
                    ->orWhere('policy_version_id', 'like', $like);
            });
        }

        $cases = $casesQuery->paginate(15, ['*'], 'cases_page')->withQueryString();
        $recommendations = $recommendationsQuery->paginate(12, ['*'], 'recommendations_page')->withQueryString();

        $caseDeviceIds = $cases->getCollection()
            ->pluck('device_id')
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->values();
        $recommendationDeviceIds = $recommendations->getCollection()
            ->map(fn (BehaviorPolicyRecommendation $recommendation) => (string) ($recommendation->anomalyCase?->device_id ?? ''))
            ->filter(fn (string $id) => trim($id) !== '')
            ->values();
        $deviceNameMap = Device::query()
            ->whereIn('id', $caseDeviceIds->merge($recommendationDeviceIds)->unique()->values())
            ->pluck('hostname', 'id');

        $cases->getCollection()->transform(function (BehaviorAnomalyCase $case) use ($deviceNameMap) {
            $this->hydrateCaseDisplayFields($case, $deviceNameMap);

            return $case;
        });
        $recommendations->getCollection()->transform(function (BehaviorPolicyRecommendation $recommendation) use ($deviceNameMap) {
            $case = $recommendation->anomalyCase;
            if (! $case instanceof BehaviorAnomalyCase) {
                $recommendation->case_summary_display = null;
                $recommendation->case_device_name_display = null;
                $recommendation->case_event_type_display = null;
                $recommendation->case_app_name_display = null;
                $recommendation->case_process_name_display = null;
                $recommendation->case_file_path_display = null;
                $recommendation->case_user_name_display = null;
                $recommendation->case_pattern_hints_display = [];

                return $recommendation;
            }

            $this->hydrateCaseDisplayFields($case, $deviceNameMap);
            $recommendation->case_summary_display = trim((string) ($case->summary ?? '')) ?: null;
            $recommendation->case_device_id_display = trim((string) ($case->device_id ?? '')) ?: null;
            $recommendation->case_device_name_display = $case->device_name_display ?? null;
            $recommendation->case_event_type_display = $case->event_type_display ?? null;
            $recommendation->case_app_name_display = $case->app_name_display ?? null;
            $recommendation->case_process_name_display = $case->process_name_display ?? null;
            $recommendation->case_file_path_display = $case->file_path_display ?? null;
            $recommendation->case_user_name_display = $case->user_name_display ?? null;
            $recommendation->case_pattern_hints_display = $case->pattern_hints_display ?? [];

            return $recommendation;
        });

        $cockpit = $this->behaviorCockpitPayload();

        return view('admin.behavior-ai', [
            'stats' => $cockpit['stats'],
            'cases' => $cases,
            'recommendations' => $recommendations,
            'filters' => $filters,
            'lastRetrainedAt' => $cockpit['last_retrained_at'],
            'threshold' => $cockpit['threshold'],
            'runtime' => $cockpit['runtime'],
        ]);
    }

    public function baseline(Request $request): View
    {
        return view('admin.behavior-baseline', [
            'baseline' => $this->buildBaselineViewData($request),
        ]);
    }

    public function remediation(Request $request): View
    {
        return view('admin.behavior-remediation', [
            'remediation' => $this->buildRemediationViewData($request),
        ]);
    }

    public function updateRemediationSettings(Request $request): RedirectResponse
    {
        $request->merge([
            'emergency_policy_version_id' => trim((string) $request->input('emergency_policy_version_id')) ?: null,
            'scan_command' => trim((string) $request->input('scan_command')),
            'isolate_command' => trim((string) $request->input('isolate_command')),
            'rollback_restore_point_description' => trim((string) $request->input('rollback_restore_point_description')),
        ]);

        $data = $request->validate([
            'remediation_enabled' => ['nullable', 'boolean'],
            'min_risk' => ['required', 'numeric', 'min:0.50', 'max:0.99'],
            'max_actions_per_case' => ['required', 'integer', 'min:1', 'max:6'],
            'allow_isolate_network' => ['nullable', 'boolean'],
            'allow_kill_process' => ['nullable', 'boolean'],
            'allow_uninstall_software' => ['nullable', 'boolean'],
            'allow_rollback_policy' => ['nullable', 'boolean'],
            'allow_emergency_profile' => ['nullable', 'boolean'],
            'allow_force_scan' => ['nullable', 'boolean'],
            'emergency_policy_version_id' => ['nullable', 'uuid', 'exists:policy_versions,id'],
            'scan_command' => ['required', 'string', 'min:3', 'max:500'],
            'isolate_command' => ['required', 'string', 'min:3', 'max:500'],
            'rollback_restore_point_description' => ['required', 'string', 'min:3', 'max:255'],
            'rollback_reboot_now' => ['nullable', 'boolean'],
        ]);

        $settings = [
            'behavior.remediation.enabled' => (bool) ($data['remediation_enabled'] ?? false),
            'behavior.remediation.min_risk' => round((float) $data['min_risk'], 4),
            'behavior.remediation.max_actions_per_case' => (int) $data['max_actions_per_case'],
            'behavior.remediation.allow_isolate_network' => (bool) ($data['allow_isolate_network'] ?? false),
            'behavior.remediation.allow_kill_process' => (bool) ($data['allow_kill_process'] ?? false),
            'behavior.remediation.allow_uninstall_software' => (bool) ($data['allow_uninstall_software'] ?? false),
            'behavior.remediation.allow_rollback_policy' => (bool) ($data['allow_rollback_policy'] ?? false),
            'behavior.remediation.allow_emergency_profile' => (bool) ($data['allow_emergency_profile'] ?? false),
            'behavior.remediation.allow_force_scan' => (bool) ($data['allow_force_scan'] ?? false),
            'behavior.remediation.emergency_policy_version_id' => (string) ($data['emergency_policy_version_id'] ?? ''),
            'behavior.remediation.scan_command' => (string) $data['scan_command'],
            'behavior.remediation.isolate_command' => (string) $data['isolate_command'],
            'behavior.remediation.rollback_restore_point_description' => (string) $data['rollback_restore_point_description'],
            'behavior.remediation.rollback_reboot_now' => (bool) ($data['rollback_reboot_now'] ?? true),
        ];

        foreach ($settings as $key => $value) {
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => ['value' => $value],
                    'updated_by' => $request->user()?->id,
                ]
            );
        }

        return back()->with('status', 'Autonomous remediation settings updated.');
    }

    public function queueRemediationSweep(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:50000'],
            'pending_only' => ['nullable', 'boolean'],
            'auto_enable' => ['nullable', 'boolean'],
        ]);

        $days = (int) ($data['days'] ?? 14);
        $limit = (int) ($data['limit'] ?? 2000);
        $pendingOnly = (bool) ($data['pending_only'] ?? true);
        $autoEnable = (bool) ($data['auto_enable'] ?? true);

        if ($autoEnable && ! $this->settingBool('behavior.remediation.enabled', false)) {
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => 'behavior.remediation.enabled'],
                [
                    'value' => ['value' => true],
                    'updated_by' => $request->user()?->id,
                ]
            );
        }

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.remediation.last_sweep_requested_at'],
            [
                'value' => ['value' => now()->toIso8601String()],
                'updated_by' => $request->user()?->id,
            ]
        );

        SweepAutonomousRemediationCasesJob::dispatch($days, $limit, $pendingOnly)->onQueue('horizon');

        return back()->with(
            'status',
            'Remediation sweep queued for last '.$days.' day(s), up to '.$limit.' case(s)'.($pendingOnly ? ' (pending only).' : '.')
        );
    }

    public function reviewRecommendation(Request $request, string $recommendationId, HumanFeedbackService $feedbackService): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected,edited,false_positive,false_negative'],
            'note' => ['nullable', 'string', 'max:5000'],
            'selected_policy_version_id' => ['nullable', 'uuid', 'exists:policy_versions,id'],
        ]);

        $recommendation = BehaviorPolicyRecommendation::query()->findOrFail($recommendationId);
        $feedbackService->reviewRecommendation($recommendation, $data, $request->user());

        return back()->with('status', 'Recommendation reviewed successfully.');
    }

    public function approveAllPendingRecommendations(Request $request, HumanFeedbackService $feedbackService): RedirectResponse
    {
        $approved = 0;
        $failed = 0;
        $reviewer = $request->user();

        BehaviorPolicyRecommendation::query()
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->chunkById(100, function ($recommendations) use (&$approved, &$failed, $feedbackService, $reviewer) {
                foreach ($recommendations as $recommendation) {
                    try {
                        $feedbackService->reviewRecommendation($recommendation, [
                            'decision' => 'approved',
                            'note' => 'Bulk auto-approved from AI Control Center.',
                            'metadata' => [
                                'source' => 'bulk_auto_approve',
                                'approved_at' => now()->toIso8601String(),
                            ],
                        ], $reviewer);
                        $approved++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }
            }, 'id');

        if ($approved === 0 && $failed === 0) {
            return back()->with('status', 'No pending recommendations found.');
        }

        if ($failed > 0) {
            return back()->withErrors([
                'recommendation_bulk_review' => 'Approved '.$approved.' pending recommendations, failed '.$failed.'.',
            ]);
        }

        return back()->with('status', 'Approved '.$approved.' pending recommendations.');
    }

    public function queueRetrain(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:7', 'max:365'],
            'min_feedback' => ['nullable', 'integer', 'min:5', 'max:100000'],
        ]);

        $days = (int) ($data['days'] ?? 45);
        $minFeedback = (int) ($data['min_feedback'] ?? 20);

        RetrainAdaptiveBehaviorModelJob::dispatch($days, $minFeedback)->onQueue('horizon');

        return back()->with('status', 'Adaptive retraining job queued.');
    }

    public function queueTrainNow(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:180'],
            'min_events' => ['nullable', 'integer', 'min:50', 'max:100000'],
        ]);

        $days = (int) ($data['days'] ?? 30);
        $minEvents = (int) ($data['min_events'] ?? 200);

        Bus::chain([
            new BackfillBehaviorDatasetJob($days),
            new TrainBehaviorAiModelJob($days, $minEvents),
        ])->onQueue('horizon')->dispatch();

        return back()->with('status', 'Train Now queued: dataset backfill + model training.');
    }

    public function updateBaselineSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'baseline_enabled' => ['nullable', 'boolean'],
            'min_samples' => ['required', 'integer', 'min:5', 'max:500'],
            'min_login_samples' => ['required', 'integer', 'min:3', 'max:300'],
            'min_numeric_samples' => ['required', 'integer', 'min:5', 'max:600'],
            'drift_event_threshold' => ['required', 'numeric', 'min:0.40', 'max:0.99'],
            'category_drift_threshold' => ['required', 'numeric', 'min:0.40', 'max:0.99'],
            'max_category_bins' => ['required', 'integer', 'min:30', 'max:1000'],
        ]);

        $settings = [
            'behavior.baseline.enabled' => (bool) ($data['baseline_enabled'] ?? false),
            'behavior.baseline.min_samples' => (int) $data['min_samples'],
            'behavior.baseline.min_login_samples' => (int) $data['min_login_samples'],
            'behavior.baseline.min_numeric_samples' => (int) $data['min_numeric_samples'],
            'behavior.baseline.drift_event_threshold' => round((float) $data['drift_event_threshold'], 4),
            'behavior.baseline.category_drift_threshold' => round((float) $data['category_drift_threshold'], 4),
            'behavior.baseline.max_category_bins' => (int) $data['max_category_bins'],
        ];

        foreach ($settings as $key => $value) {
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => ['value' => $value],
                    'updated_by' => $request->user()?->id,
                ]
            );
        }

        return back()->with('status', 'Behavioral baseline settings updated.');
    }

    public function queueBaselineBackfill(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:100', 'max:200000'],
            'auto_enable' => ['nullable', 'boolean'],
        ]);

        $days = (int) ($data['days'] ?? 30);
        $limit = (int) ($data['limit'] ?? 5000);
        $autoEnable = (bool) ($data['auto_enable'] ?? true);

        if ($autoEnable && ! $this->settingBool('behavior.baseline.enabled', false)) {
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => 'behavior.baseline.enabled'],
                [
                    'value' => ['value' => true],
                    'updated_by' => $request->user()?->id,
                ]
            );
        }

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.baseline.last_backfill_requested_at'],
            [
                'value' => ['value' => now()->toIso8601String()],
                'updated_by' => $request->user()?->id,
            ]
        );

        BackfillBehaviorBaselineProfilesJob::dispatch($days, $limit)->onQueue('horizon');

        return back()->with(
            'status',
            'Baseline backfill queued for the last '.$days.' day(s) (up to '.$limit.' events).'
        );
    }

    public function replayFailedStream(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $limit = (int) ($data['limit'] ?? 200);
        $streamIds = AiEventStream::query()
            ->whereIn('status', ['queued', 'failed'])
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id')
            ->values();

        foreach ($streamIds as $streamId) {
            ProcessBehaviorEventStreamJob::dispatch((string) $streamId)->onQueue('horizon');
        }

        return back()->with('status', 'Queued '.$streamIds->count().' stream events for processing.');
    }

    public function startRuntime(Request $request): RedirectResponse
    {
        $runtime = $this->aiRuntimeStatusData();
        if (($runtime['queue_running'] ?? false) && ($runtime['scheduler_running'] ?? false)) {
            return back()->with('status', 'AI runtime is already running.');
        }

        $workdir = base_path();
        $logsDir = storage_path('logs');
        if (! is_dir($logsDir)) {
            @mkdir($logsDir, 0775, true);
        }

        $started = [];
        if (! ($runtime['queue_running'] ?? false)) {
            $ok = $this->startBackgroundProcess(
                'php artisan queue:work --queue=horizon --sleep=1 --tries=3 --timeout=120',
                $workdir,
                $logsDir.DIRECTORY_SEPARATOR.'ai-queue-worker.log'
            );
            if ($ok) {
                $started[] = 'queue worker';
            }
        }

        if (! ($runtime['scheduler_running'] ?? false)) {
            $ok = $this->startBackgroundProcess(
                'php artisan schedule:work',
                $workdir,
                $logsDir.DIRECTORY_SEPARATOR.'ai-scheduler.log'
            );
            if ($ok) {
                $started[] = 'scheduler';
            }
        }

        $after = $this->aiRuntimeStatusData();
        if (($after['queue_running'] ?? false) && ($after['scheduler_running'] ?? false)) {
            return back()->with('status', 'AI runtime started successfully.');
        }

        if ($started === []) {
            return back()->withErrors(['ai_runtime' => 'Failed to start AI runtime processes. Check logs in storage/logs.']);
        }

        return back()->with('status', 'Started '.implode(' and ', $started).'.');
    }

    public function runtimeStatus(): JsonResponse
    {
        return response()->json($this->aiRuntimeStatusData());
    }

    public function liveStatus(): JsonResponse
    {
        return response()->json($this->behaviorCockpitPayload());
    }

    /**
     * @return array{
     *   tables_ready: bool,
     *   settings: array<string,mixed>,
     *   filters: array<string,string>,
     *   stats: array<string,int>,
     *   drift_events: \Illuminate\Pagination\LengthAwarePaginator,
     *   profiles: \Illuminate\Pagination\LengthAwarePaginator,
     *   backfill: array<string,mixed>
     * }
     */
    private function buildBaselineViewData(Request $request): array
    {
        $baselineEnabled = $this->settingBool('behavior.baseline.enabled', false);
        $baselineSettings = [
            'enabled' => $baselineEnabled,
            'min_samples' => $this->settingInt('behavior.baseline.min_samples', 30),
            'min_login_samples' => $this->settingInt('behavior.baseline.min_login_samples', 12),
            'min_numeric_samples' => $this->settingInt('behavior.baseline.min_numeric_samples', 20),
            'drift_event_threshold' => $this->settingFloat('behavior.baseline.drift_event_threshold', 0.68),
            'category_drift_threshold' => $this->settingFloat('behavior.baseline.category_drift_threshold', 0.70),
            'max_category_bins' => $this->settingInt('behavior.baseline.max_category_bins', 240),
        ];
        $baselineFilters = [
            'device_q' => trim((string) $request->query('device_q', '')),
            'severity' => trim((string) $request->query('severity', '')),
        ];
        $baselineTablesReady = Schema::hasTable('device_behavior_baselines') && Schema::hasTable('device_behavior_drift_events');
        $baselineStats = [
            'profiles_total' => 0,
            'profiles_ready' => 0,
            'drift_events_24h' => 0,
            'drift_events_7d' => 0,
            'drift_events_high_7d' => 0,
            'drift_devices_7d' => 0,
        ];
        $baselineDriftEvents = new LengthAwarePaginator(
            [],
            0,
            10,
            max(1, (int) $request->query('drift_page', 1)),
            ['path' => $request->url(), 'pageName' => 'drift_page']
        );
        $baselineProfiles = new LengthAwarePaginator(
            [],
            0,
            10,
            max(1, (int) $request->query('profile_page', 1)),
            ['path' => $request->url(), 'pageName' => 'profile_page']
        );
        $baselineBackfill = [
            'last_requested_at' => $this->settingString('behavior.baseline.last_backfill_requested_at', ''),
            'last_completed_at' => $this->settingString('behavior.baseline.last_backfill_completed_at', ''),
            'last_result' => $this->settingArray('behavior.baseline.last_backfill_result', []),
        ];

        if ($baselineTablesReady) {
            $drift7dStart = now()->subDays(7);
            $baselineStats['profiles_total'] = DeviceBehaviorBaseline::query()->count();
            $baselineStats['profiles_ready'] = DeviceBehaviorBaseline::query()
                ->where('sample_count', '>=', (int) $baselineSettings['min_samples'])
                ->count();
            $baselineStats['drift_events_24h'] = DeviceBehaviorDriftEvent::query()
                ->where('detected_at', '>=', now()->subDay())
                ->count();
            $baselineStats['drift_events_7d'] = DeviceBehaviorDriftEvent::query()
                ->where('detected_at', '>=', $drift7dStart)
                ->count();
            $baselineStats['drift_events_high_7d'] = DeviceBehaviorDriftEvent::query()
                ->where('detected_at', '>=', $drift7dStart)
                ->where('severity', 'high')
                ->count();
            $baselineStats['drift_devices_7d'] = DeviceBehaviorDriftEvent::query()
                ->where('detected_at', '>=', $drift7dStart)
                ->distinct('device_id')
                ->count('device_id');

            $driftQuery = DeviceBehaviorDriftEvent::query()
                ->leftJoin('devices', 'devices.id', '=', 'device_behavior_drift_events.device_id')
                ->select([
                    'device_behavior_drift_events.*',
                    'devices.hostname as device_hostname',
                ])
                ->orderByDesc('device_behavior_drift_events.detected_at');
            if (in_array($baselineFilters['severity'], ['low', 'medium', 'high'], true)) {
                $driftQuery->where('device_behavior_drift_events.severity', $baselineFilters['severity']);
            }
            if ($baselineFilters['device_q'] !== '') {
                $like = '%'.$baselineFilters['device_q'].'%';
                $driftQuery->where(function ($builder) use ($like) {
                    $builder->where('device_behavior_drift_events.device_id', 'like', $like)
                        ->orWhere('device_behavior_drift_events.behavior_log_id', 'like', $like)
                        ->orWhere('device_behavior_drift_events.anomaly_case_id', 'like', $like)
                        ->orWhere('devices.hostname', 'like', $like);
                });
            }
            $baselineDriftEvents = $driftQuery
                ->paginate(10, ['*'], 'drift_page')
                ->withQueryString();

            $profilesQuery = DeviceBehaviorBaseline::query()
                ->leftJoin('devices', 'devices.id', '=', 'device_behavior_baselines.device_id')
                ->select([
                    'device_behavior_baselines.*',
                    'devices.hostname as device_hostname',
                ])
                ->orderByDesc('device_behavior_baselines.sample_count')
                ->orderByDesc('device_behavior_baselines.updated_at');
            if ($baselineFilters['device_q'] !== '') {
                $like = '%'.$baselineFilters['device_q'].'%';
                $profilesQuery->where(function ($builder) use ($like) {
                    $builder->where('device_behavior_baselines.device_id', 'like', $like)
                        ->orWhere('devices.hostname', 'like', $like);
                });
            }
            $baselineProfiles = $profilesQuery
                ->paginate(10, ['*'], 'profile_page')
                ->withQueryString();
        }

        return [
            'tables_ready' => $baselineTablesReady,
            'settings' => $baselineSettings,
            'filters' => $baselineFilters,
            'stats' => $baselineStats,
            'drift_events' => $baselineDriftEvents,
            'profiles' => $baselineProfiles,
            'backfill' => $baselineBackfill,
        ];
    }

    /**
     * @return array{
     *   tables_ready: bool,
     *   settings: array<string,mixed>,
     *   filters: array<string,string>,
     *   stats: array<string,int>,
     *   executions: \Illuminate\Pagination\LengthAwarePaginator,
     *   emergency_policies: \Illuminate\Support\Collection<int,array<string,mixed>>,
     *   action_options: array<string,string>,
     *   sweep: array<string,mixed>
     * }
     */
    private function buildRemediationViewData(Request $request): array
    {
        $settings = [
            'enabled' => $this->settingBool('behavior.remediation.enabled', false),
            'min_risk' => $this->settingFloat('behavior.remediation.min_risk', 0.90),
            'max_actions_per_case' => $this->settingInt('behavior.remediation.max_actions_per_case', 2),
            'allow_isolate_network' => $this->settingBool('behavior.remediation.allow_isolate_network', false),
            'allow_kill_process' => $this->settingBool('behavior.remediation.allow_kill_process', false),
            'allow_uninstall_software' => $this->settingBool('behavior.remediation.allow_uninstall_software', false),
            'allow_rollback_policy' => $this->settingBool('behavior.remediation.allow_rollback_policy', false),
            'allow_emergency_profile' => $this->settingBool('behavior.remediation.allow_emergency_profile', true),
            'allow_force_scan' => $this->settingBool('behavior.remediation.allow_force_scan', true),
            'emergency_policy_version_id' => $this->settingString('behavior.remediation.emergency_policy_version_id', ''),
            'scan_command' => $this->settingString(
                'behavior.remediation.scan_command',
                'powershell.exe -NoProfile -Command "Start-MpScan -ScanType QuickScan"'
            ),
            'isolate_command' => $this->settingString(
                'behavior.remediation.isolate_command',
                'netsh advfirewall set allprofiles state on && netsh advfirewall set allprofiles firewallpolicy blockinbound,blockoutbound'
            ),
            'rollback_restore_point_description' => $this->settingString(
                'behavior.remediation.rollback_restore_point_description',
                'DMS Baseline Safe Point'
            ),
            'rollback_reboot_now' => $this->settingBool('behavior.remediation.rollback_reboot_now', true),
        ];

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => trim((string) $request->query('status', '')),
            'action' => trim((string) $request->query('action', '')),
        ];

        $actionOptions = [
            'force_system_scan' => 'Force system scan',
            'apply_emergency_security_profile' => 'Apply emergency profile',
            'isolate_device_network' => 'Isolate network',
            'kill_suspicious_process' => 'Kill suspicious process',
            'uninstall_suspicious_software' => 'Uninstall suspicious software',
            'rollback_policy_state' => 'Rollback policy state',
        ];
        $statusFilterOptions = ['queued', 'running', 'success', 'failed', 'dispatch_failed'];

        $tablesReady = Schema::hasTable('behavior_remediation_executions');
        $stats = [
            'actions_24h' => 0,
            'actions_7d' => 0,
            'devices_7d' => 0,
            'dispatch_failed_7d' => 0,
            'job_failed_7d' => 0,
            'job_active' => 0,
        ];
        $sweep = [
            'last_requested_at' => $this->settingString('behavior.remediation.last_sweep_requested_at', ''),
            'last_completed_at' => $this->settingString('behavior.remediation.last_sweep_completed_at', ''),
            'last_result' => $this->settingArray('behavior.remediation.last_sweep_result', []),
        ];
        $executions = new LengthAwarePaginator(
            [],
            0,
            12,
            max(1, (int) $request->query('execution_page', 1)),
            ['path' => $request->url(), 'pageName' => 'execution_page']
        );

        if ($tablesReady) {
            $window7d = now()->subDays(7);
            $stats['actions_24h'] = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', now()->subDay())
                ->count();
            $stats['actions_7d'] = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', $window7d)
                ->count();
            $stats['devices_7d'] = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', $window7d)
                ->distinct('device_id')
                ->count('device_id');
            $stats['dispatch_failed_7d'] = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', $window7d)
                ->where('status', 'dispatch_failed')
                ->count();
            $stats['job_failed_7d'] = BehaviorRemediationExecution::query()
                ->leftJoin('jobs', 'jobs.id', '=', 'behavior_remediation_executions.dispatched_job_id')
                ->where('behavior_remediation_executions.created_at', '>=', $window7d)
                ->whereIn('jobs.status', ['failed'])
                ->count();
            $stats['job_active'] = BehaviorRemediationExecution::query()
                ->leftJoin('jobs', 'jobs.id', '=', 'behavior_remediation_executions.dispatched_job_id')
                ->whereIn('jobs.status', ['queued', 'running'])
                ->count();

            $query = BehaviorRemediationExecution::query()
                ->leftJoin('devices', 'devices.id', '=', 'behavior_remediation_executions.device_id')
                ->leftJoin('jobs', 'jobs.id', '=', 'behavior_remediation_executions.dispatched_job_id')
                ->select([
                    'behavior_remediation_executions.*',
                    'devices.hostname as device_hostname',
                    'jobs.status as dispatched_job_status',
                    'jobs.job_type as dispatched_job_type',
                ])
                ->orderByDesc('behavior_remediation_executions.created_at');

            if ($filters['action'] !== '' && array_key_exists($filters['action'], $actionOptions)) {
                $query->where('behavior_remediation_executions.remediation_key', $filters['action']);
            }

            if ($filters['status'] !== '' && in_array($filters['status'], $statusFilterOptions, true)) {
                if ($filters['status'] === 'dispatch_failed') {
                    $query->where('behavior_remediation_executions.status', 'dispatch_failed');
                } else {
                    $query->where('jobs.status', $filters['status']);
                }
            }

            if ($filters['q'] !== '') {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($builder) use ($like) {
                    $builder->where('behavior_remediation_executions.id', 'like', $like)
                        ->orWhere('behavior_remediation_executions.anomaly_case_id', 'like', $like)
                        ->orWhere('behavior_remediation_executions.device_id', 'like', $like)
                        ->orWhere('behavior_remediation_executions.dispatched_job_id', 'like', $like)
                        ->orWhere('devices.hostname', 'like', $like);
                });
            }

            $executions = $query
                ->paginate(12, ['*'], 'execution_page')
                ->withQueryString();
        }

        $emergencyPolicies = PolicyVersion::query()
            ->join('policies', 'policies.id', '=', 'policy_versions.policy_id')
            ->whereIn('policy_versions.status', ['active', 'published'])
            ->orderByDesc('policy_versions.published_at')
            ->orderByDesc('policy_versions.created_at')
            ->limit(200)
            ->get([
                'policy_versions.id as id',
                'policy_versions.version_number as version_number',
                'policy_versions.status as status',
                'policies.name as policy_name',
                'policies.category as policy_category',
            ])
            ->map(fn ($row) => [
                'id' => (string) ($row->id ?? ''),
                'version_number' => (int) ($row->version_number ?? 0),
                'status' => (string) ($row->status ?? 'unknown'),
                'policy_name' => (string) ($row->policy_name ?? 'Unknown policy'),
                'policy_category' => (string) ($row->policy_category ?? ''),
            ])
            ->values();

        return [
            'tables_ready' => $tablesReady,
            'settings' => $settings,
            'filters' => $filters,
            'stats' => $stats,
            'executions' => $executions,
            'emergency_policies' => $emergencyPolicies,
            'action_options' => $actionOptions,
            'sweep' => $sweep,
        ];
    }

    private function settingString(string $key, string $default): string
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function settingBool(string $key, bool $default): bool
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var((string) $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    private function settingArray(string $key, array $default): array
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        return is_array($value) ? $value : $default;
    }

    private function settingFloat(string $key, float $default): float
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        return is_numeric($value) ? (float) $value : $default;
    }

    private function settingInt(string $key, int $default): int
    {
        return (int) round($this->settingFloat($key, (float) $default));
    }

    private function hydrateCaseDisplayFields(BehaviorAnomalyCase $case, mixed $deviceNameMap = null): void
    {
        $context = is_array($case->context) ? $case->context : [];
        $features = is_array($context['features'] ?? null) ? $context['features'] : [];
        $metadata = is_array($features['metadata'] ?? null) ? $features['metadata'] : [];
        $signals = is_array($context['detector_signals'] ?? null) ? $context['detector_signals'] : [];

        $eventType = strtolower(trim((string) ($features['event_type'] ?? 'unknown')));
        $rawProcess = trim((string) ($features['process_name_raw'] ?? ($features['process_name'] ?? '')));
        $rawFilePath = trim((string) ($features['file_path'] ?? ''));
        $rawUser = trim((string) ($features['user_name_raw'] ?? ($features['user_name'] ?? '')));

        $appFromFeature = trim((string) ($features['app_name'] ?? ''));
        $appFromMetadata = trim((string) ($metadata['app_name'] ?? ($metadata['application'] ?? ($metadata['process_name'] ?? ''))));
        $appCandidate = $appFromFeature !== '' ? $appFromFeature : ($appFromMetadata !== '' ? $appFromMetadata : $rawProcess);
        $normalizedApp = trim(basename(str_replace('\\', '/', $appCandidate)));

        $resolvedName = '';
        if ($deviceNameMap instanceof \Illuminate\Support\Collection) {
            $resolvedName = trim((string) ($deviceNameMap->get((string) $case->device_id, '')));
        }
        if ($resolvedName === '') {
            $resolvedName = trim((string) ($case->device?->hostname ?? ''));
        }
        if ($resolvedName === '' && is_array($metadata)) {
            $resolvedName = trim((string) ($metadata['device_name'] ?? $metadata['hostname'] ?? ''));
        }
        $case->device_name_display = $resolvedName;
        $case->event_type_display = $eventType;
        $case->app_name_display = $eventType === 'app_launch' && $normalizedApp !== '' ? $normalizedApp : null;
        $case->process_name_display = $rawProcess !== '' ? $rawProcess : null;
        $case->file_path_display = $rawFilePath !== '' ? $rawFilePath : null;
        $case->user_name_display = $rawUser !== '' ? $rawUser : null;
        $case->pattern_hints_display = $this->patternHintsFromSignals($signals);
    }

    /**
     * @param array<string,mixed> $signals
     * @return array<int,string>
     */
    private function patternHintsFromSignals(array $signals): array
    {
        $labels = [
            'statistical_ensemble' => 'Statistical behavior drift',
            'off_hours_profile' => 'Off-hours usage pattern',
            'rare_process_on_device' => 'Rare process on device',
            'multi_signal_correlation_window' => 'Multi-signal correlated activity',
            'burst_file_access' => 'Burst file-access activity',
            'behavioral_baseline_drift' => 'Behavioral baseline drift',
        ];

        $hints = [];
        foreach ($signals as $key => $signal) {
            if (! is_array($signal)) {
                continue;
            }
            $score = (float) ($signal['score'] ?? 0.0);
            if ($score < 0.55) {
                continue;
            }

            $label = $labels[(string) $key] ?? (string) $key;
            $hints[] = $label.' (score '.number_format($score, 2).')';
        }

        return array_values($hints);
    }

    /**
     * @return array{queue_running:bool,scheduler_running:bool,runtime_running:bool,checked_at:string}
     */
    private function aiRuntimeStatusData(): array
    {
        $queueRunning = $this->processExistsByPattern('artisan queue:work');
        $schedulerRunning = $this->processExistsByPattern('artisan schedule:work');

        return [
            'queue_running' => $queueRunning,
            'scheduler_running' => $schedulerRunning,
            'runtime_running' => $queueRunning && $schedulerRunning,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function processExistsByPattern(string $pattern): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $escaped = str_replace("'", "''", $pattern);
            $cmd = 'wmic process where "Name=\'php.exe\' and CommandLine like \'%'.$escaped.'%\'" get ProcessId /value 2>NUL';
            $output = shell_exec($cmd);
            if (is_string($output) && preg_match('/ProcessId=\\d+/', $output) === 1) {
                return true;
            }

            $fallback = shell_exec('tasklist /FI "IMAGENAME eq php.exe" 2>NUL');
            return is_string($fallback) && stripos($fallback, 'php.exe') !== false;
        }

        $safe = escapeshellarg($pattern);
        $output = shell_exec('pgrep -af '.$safe.' 2>/dev/null');
        return is_string($output) && trim($output) !== '';
    }

    private function startBackgroundProcess(string $command, string $workdir, string $logPath): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $workdirEscaped = str_replace('"', '""', $workdir);
            $logPathEscaped = str_replace('"', '""', $logPath);
            $cmdEscaped = str_replace('"', '""', $command);
            $cmd = 'start "" /B cmd /C "cd /d "'.$workdirEscaped.'" && '.$cmdEscaped.' >> "'.$logPathEscaped.'" 2>&1"';
            pclose(popen($cmd, 'r'));
            usleep(300000);
            return true;
        }

        $cmd = 'cd '.escapeshellarg($workdir).' && nohup '.$command.' >> '.escapeshellarg($logPath).' 2>&1 &';
        shell_exec($cmd);
        usleep(300000);
        return true;
    }

    /**
     * @return array{
     *   stats: array{
     *      stream_queued:int,
     *      stream_failed:int,
     *      cases_pending:int,
     *      cases_high:int,
     *      recommendations_pending:int,
     *      recommendations_applied:int,
     *      feedback_total:int,
     *      feedback_approved_30d:int,
     *      feedback_rejected_30d:int
     *   },
     *   threshold:string,
     *   last_retrained_at:string,
     *   runtime:array{
     *      queue_running:bool,
     *      scheduler_running:bool,
     *      runtime_running:bool,
     *      checked_at:string
     *   },
     *   runtime_healthy:bool,
     *   operations_backlog:int,
     *   approval:array{
     *      ratio:float|null,
     *      approved_30d:int,
     *      rejected_30d:int
     *   },
     *   updated_at:string
     * }
     */
    private function behaviorCockpitPayload(): array
    {
        $stats = [
            'stream_queued' => AiEventStream::query()->where('status', 'queued')->count(),
            'stream_failed' => AiEventStream::query()->where('status', 'failed')->count(),
            'cases_pending' => BehaviorAnomalyCase::query()->where('status', 'pending_review')->count(),
            'cases_high' => BehaviorAnomalyCase::query()->where('severity', 'high')->count(),
            'recommendations_pending' => BehaviorPolicyRecommendation::query()->where('status', 'pending')->count(),
            'recommendations_applied' => BehaviorPolicyRecommendation::query()->whereIn('status', ['applied', 'auto_applied'])->count(),
            'feedback_total' => BehaviorPolicyFeedback::query()->count(),
            'feedback_approved_30d' => BehaviorPolicyFeedback::query()
                ->whereIn('decision', ['approved', 'edited', 'false_negative'])
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'feedback_rejected_30d' => BehaviorPolicyFeedback::query()
                ->whereIn('decision', ['rejected', 'false_positive'])
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
        ];

        $runtime = $this->aiRuntimeStatusData();
        $approvalDenominator = $stats['feedback_approved_30d'] + $stats['feedback_rejected_30d'];
        $approvalRatio = $approvalDenominator > 0
            ? round(($stats['feedback_approved_30d'] / $approvalDenominator) * 100, 1)
            : null;

        return [
            'stats' => $stats,
            'threshold' => $this->settingString('behavior.ai_threshold', '0.82'),
            'last_retrained_at' => $this->settingString('behavior.pipeline.last_retrained_at', 'never'),
            'runtime' => $runtime,
            'runtime_healthy' => (bool) (($runtime['queue_running'] ?? false) && ($runtime['scheduler_running'] ?? false)),
            'operations_backlog' => (int) $stats['stream_queued'] + (int) $stats['stream_failed'] + (int) $stats['cases_pending'],
            'approval' => [
                'ratio' => $approvalRatio,
                'approved_30d' => (int) $stats['feedback_approved_30d'],
                'rejected_30d' => (int) $stats['feedback_rejected_30d'],
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
