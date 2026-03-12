<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillBehaviorDatasetJob;
use App\Jobs\ProcessBehaviorEventStreamJob;
use App\Jobs\RetrainAdaptiveBehaviorModelJob;
use App\Jobs\TrainBehaviorAiModelJob;
use App\Models\AiEventStream;
use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorPolicyFeedback;
use App\Models\BehaviorPolicyRecommendation;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Services\BehaviorPipeline\HumanFeedbackService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $lastRetrainedAt = $this->settingString('behavior.pipeline.last_retrained_at', 'never');
        $threshold = $this->settingString('behavior.ai_threshold', '0.82');

        return view('admin.behavior-ai', [
            'stats' => $stats,
            'cases' => $cases,
            'recommendations' => $recommendations,
            'filters' => $filters,
            'lastRetrainedAt' => $lastRetrainedAt,
            'threshold' => $threshold,
            'runtime' => $this->aiRuntimeStatusData(),
        ]);
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

    private function settingString(string $key, string $default): string
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
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
}
