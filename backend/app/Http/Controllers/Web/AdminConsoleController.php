<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AgentRelease;
use App\Models\AdminNote;
use App\Models\AuditLog;
use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorRemediationExecution;
use App\Models\DeviceBehaviorDriftEvent;
use App\Models\BehaviorPolicyRecommendation;
use App\Models\ComplianceResult;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DmsJob;
use App\Models\EnrollmentToken;
use App\Models\JobEvent;
use App\Models\JobRun;
use App\Models\KeyMaterial;
use App\Models\PackageFile;
use App\Models\PackageModel;
use App\Models\PackageVersion;
use App\Models\Permission;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\AgentBuildService;
use App\Services\AuditLogger;
use App\Services\CommandEnvelopeSigner;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminConsoleController extends Controller
{
    public function dashboard(): View
    {
        $windowStart = now()->subDays(6)->startOfDay();
        $onlineThreshold = now()->subMinutes(2);
        $days = collect(range(6, 0))
            ->map(fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString())
            ->values();
        $retryingRuns = JobRun::query()->whereNotNull('next_retry_at')->where('status', 'pending')->count();
        $complianceTotal = ComplianceResult::query()->count();
        $complianceCompliant = ComplianceResult::query()->where('status', 'compliant')->count();
        $complianceNonCompliant = ComplianceResult::query()->where('status', 'non_compliant')->count();
        $jobFinalCount = JobRun::query()->whereIn('status', ['success', 'failed'])->count();
        $jobSuccessCount = JobRun::query()->where('status', 'success')->count();
        $devicesTotal = Device::query()->count();
        $devicesPending = Device::query()->where('status', 'pending')->count();
        $devicesOnline = Device::query()
            ->whereNotIn('status', ['pending', 'quarantined'])
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>', $onlineThreshold)
            ->count();
        $devicesEnrolled = Device::query()->where('status', '!=', 'pending')->count();
        $devicesOffline = max(0, $devicesTotal - $devicesOnline - $devicesPending);
        $jobsPending = JobRun::query()->whereIn('status', ['pending', 'acked', 'running'])->count();
        $jobsFailed = JobRun::query()->where('status', 'failed')->count();
        $jobsSuccess = JobRun::query()->where('status', 'success')->count();
        $replayRejects = JobRun::query()
            ->where('status', 'failed')
            ->where('result_payload', 'like', '%replay_detected%')
            ->count();
        $lastKeyRotation = KeyMaterial::query()
            ->where('purpose', 'command_signing')
            ->latest('created_at')
            ->value('created_at');
        $jobTrendRows = JobRun::query()
            ->selectRaw('date(updated_at) as day, status, count(*) as total')
            ->where('updated_at', '>=', $windowStart)
            ->whereIn('status', ['success', 'failed', 'pending', 'acked', 'running'])
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day');
        $deviceTrendRows = Device::query()
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->where('created_at', '>=', $windowStart)
            ->groupBy('day')
            ->pluck('total', 'day');
        $auditTrendRows = AuditLog::query()
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->where('created_at', '>=', $windowStart)
            ->groupBy('day')
            ->pluck('total', 'day');
        $anomalyTrendRows = BehaviorAnomalyCase::query()
            ->selectRaw('date(detected_at) as day, count(*) as total')
            ->whereNotNull('detected_at')
            ->where('detected_at', '>=', $windowStart)
            ->groupBy('day')
            ->pluck('total', 'day');
        $jobTrend = $days->map(function (string $day) use ($jobTrendRows) {
            $rows = collect($jobTrendRows->get($day, collect()));

            return [
                'day' => $day,
                'label' => \Carbon\Carbon::parse($day)->format('M d'),
                'success' => (int) (($rows->firstWhere('status', 'success')->total ?? 0)),
                'failed' => (int) (($rows->firstWhere('status', 'failed')->total ?? 0)),
                'active' => (int) $rows
                    ->filter(fn ($row) => in_array((string) $row->status, ['pending', 'acked', 'running'], true))
                    ->sum('total'),
            ];
        })->values();
        $enrollmentTrend = $days->map(fn (string $day) => [
            'day' => $day,
            'label' => \Carbon\Carbon::parse($day)->format('M d'),
            'total' => (int) ($deviceTrendRows[$day] ?? 0),
        ])->values();
        $auditTrend = $days->map(fn (string $day) => [
            'day' => $day,
            'label' => \Carbon\Carbon::parse($day)->format('M d'),
            'total' => (int) ($auditTrendRows[$day] ?? 0),
        ])->values();
        $anomalyTrend = $days->map(fn (string $day) => [
            'day' => $day,
            'label' => \Carbon\Carbon::parse($day)->format('M d'),
            'total' => (int) ($anomalyTrendRows[$day] ?? 0),
        ])->values();
        $baselineEnabled = $this->settingBool('behavior.baseline.enabled', false);
        $baselineTablesReady = $baselineEnabled && Schema::hasTable('device_behavior_drift_events');
        $baselineDriftEvents24h = 0;
        $baselineDriftDevices7d = 0;
        $baselineRiskContribution = 0.0;
        if ($baselineTablesReady) {
            $baselineWindowStart = now()->subDays(6)->startOfDay();
            $baselineDayStart = now()->subDay();
            $baselineQuery7d = DeviceBehaviorDriftEvent::query()
                ->where('detected_at', '>=', $baselineWindowStart);
            $baselineDriftEvents24h = DeviceBehaviorDriftEvent::query()
                ->where('detected_at', '>=', $baselineDayStart)
                ->count();
            $baselineDriftDevices7d = (clone $baselineQuery7d)
                ->distinct('device_id')
                ->count('device_id');
            $baselineDriftHigh7d = (clone $baselineQuery7d)
                ->where('severity', 'high')
                ->count();

            $deviceRiskRate = min(100.0, ($baselineDriftDevices7d / max(1, $devicesEnrolled)) * 100);
            $highSeverityRate = min(100.0, ($baselineDriftHigh7d / max(1, $devicesEnrolled)) * 100);
            $baselineRiskContribution = round(min(100.0, ($deviceRiskRate * 0.65) + ($highSeverityRate * 0.35)), 1);
        }
        $remediationEnabled = $this->settingBool('behavior.remediation.enabled', false);
        $remediationTablesReady = $remediationEnabled && Schema::hasTable('behavior_remediation_executions');
        $remediationActions24h = 0;
        $remediationActions7d = 0;
        $remediationFailed7d = 0;
        $remediationActive = 0;
        $remediationRiskContribution = 0.0;
        if ($remediationTablesReady) {
            $remediationWindowStart = now()->subDays(6)->startOfDay();
            $remediationActions24h = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', now()->subDay())
                ->count();
            $remediationActions7d = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', $remediationWindowStart)
                ->count();
            $remediationHighRisk7d = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', $remediationWindowStart)
                ->where('risk_score', '>=', 0.9000)
                ->count();
            $remediationActive = BehaviorRemediationExecution::query()
                ->leftJoin('jobs', 'jobs.id', '=', 'behavior_remediation_executions.dispatched_job_id')
                ->whereIn('jobs.status', ['queued', 'running'])
                ->count();
            $remediationJobFailed7d = BehaviorRemediationExecution::query()
                ->leftJoin('jobs', 'jobs.id', '=', 'behavior_remediation_executions.dispatched_job_id')
                ->where('behavior_remediation_executions.created_at', '>=', $remediationWindowStart)
                ->whereIn('jobs.status', ['failed'])
                ->count();
            $remediationDispatchFailed7d = BehaviorRemediationExecution::query()
                ->where('created_at', '>=', $remediationWindowStart)
                ->where('status', 'dispatch_failed')
                ->count();
            $remediationFailed7d = $remediationJobFailed7d + $remediationDispatchFailed7d;

            $backlogRate = min(100.0, ($remediationActive / max(1, $remediationActions7d)) * 100);
            $failureRate = min(100.0, ($remediationFailed7d / max(1, $remediationActions7d)) * 100);
            $highRiskRate = min(100.0, ($remediationHighRisk7d / max(1, $remediationActions7d)) * 100);
            $remediationRiskContribution = round(min(100.0, ($failureRate * 0.50) + ($backlogRate * 0.35) + ($highRiskRate * 0.15)), 1);
        }

        return view('admin.dashboard', [
            'metrics' => [
                'devices_total' => $devicesTotal,
                'devices_online' => $devicesOnline,
                'devices_offline' => $devicesOffline,
                'devices_pending' => $devicesPending,
                'devices_enrolled' => $devicesEnrolled,
                'jobs_pending' => $jobsPending,
                'jobs_failed' => $jobsFailed,
                'jobs_success' => $jobsSuccess,
                'packages_total' => PackageModel::query()->count(),
                'policies_total' => Policy::query()->count(),
                'compliance_non_compliant' => $complianceNonCompliant,
                'audit_events' => AuditLog::query()->count(),
                'retrying_runs' => $retryingRuns,
                'compliance_rate' => $complianceTotal > 0 ? round(($complianceCompliant / $complianceTotal) * 100, 1) : null,
                'job_success_rate' => $jobFinalCount > 0 ? round(($jobSuccessCount / $jobFinalCount) * 100, 1) : null,
                'replay_rejects' => $replayRejects,
                'last_key_rotation' => $lastKeyRotation,
                'behavior_ai_cases_pending' => BehaviorAnomalyCase::query()->where('status', 'pending_review')->count(),
                'behavior_ai_cases_total' => BehaviorAnomalyCase::query()->count(),
                'behavior_ai_recommendations_pending' => BehaviorPolicyRecommendation::query()->where('status', 'pending')->count(),
                'behavior_baseline_enabled' => $baselineTablesReady,
                'behavior_baseline_drift_events_24h' => $baselineDriftEvents24h,
                'behavior_baseline_drift_devices_7d' => $baselineDriftDevices7d,
                'behavior_baseline_risk' => $baselineRiskContribution,
                'behavior_remediation_enabled' => $remediationTablesReady,
                'behavior_remediation_actions_24h' => $remediationActions24h,
                'behavior_remediation_actions_7d' => $remediationActions7d,
                'behavior_remediation_failed_7d' => $remediationFailed7d,
                'behavior_remediation_active' => $remediationActive,
                'behavior_remediation_risk' => $remediationRiskContribution,
            ],
            'recent_jobs' => JobRun::query()->latest('updated_at')->limit(8)->get(),
            'recent_devices' => Device::query()->latest('updated_at')->limit(8)->get(),
            'recent_behavior_ai_cases' => BehaviorAnomalyCase::query()->latest('detected_at')->limit(8)->get(),
            'charts' => [
                'job_trend' => $jobTrend,
                'enrollment_trend' => $enrollmentTrend,
                'audit_trend' => $auditTrend,
                'anomaly_trend' => $anomalyTrend,
                'device_status' => [
                    'online' => $devicesOnline,
                    'offline' => $devicesOffline,
                    'pending' => $devicesPending,
                ],
                'job_status' => [
                    'success' => $jobsSuccess,
                    'failed' => $jobsFailed,
                    'active' => $jobsPending,
                ],
                'compliance_status' => [
                    'compliant' => $complianceCompliant,
                    'non_compliant' => $complianceNonCompliant,
                    'unknown' => max(0, $complianceTotal - $complianceCompliant - $complianceNonCompliant),
                ],
            ],
            'ops' => [
                'kill_switch' => $this->settingBool('jobs.kill_switch', false),
                'max_retries' => $this->settingInt('jobs.max_retries', 3),
                'base_backoff_seconds' => $this->settingInt('jobs.base_backoff_seconds', 30),
                'allowed_script_hashes' => $this->settingArray('scripts.allowed_sha256', []),
                'auto_allow_run_command_hashes' => $this->settingBool('scripts.auto_allow_run_command_hashes', false),
                'delete_cleanup_before_uninstall' => $this->settingBool('devices.delete_cleanup_before_uninstall', false),
                'package_download_url_mode' => $this->settingString('packages.download_url_mode', 'public'),
            ],
        ]);
    }

    public function devices(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $devicesQuery = Device::query();
        if ($search !== '') {
            $devicesQuery->where(function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where('hostname', 'like', $like)
                    ->orWhere('id', 'like', $like)
                    ->orWhere('meshcentral_device_id', 'like', $like)
                    ->orWhere('os_name', 'like', $like)
                    ->orWhere('os_version', 'like', $like);
            });
        }

        return view('admin.devices', [
            'devices' => $devicesQuery
                ->addSelect([
                    'failed_runs' => JobRun::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('device_id', 'devices.id')
                        ->where('status', 'failed'),
                    'pending_retries' => JobRun::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('device_id', 'devices.id')
                        ->where('status', 'pending')
                        ->whereNotNull('next_retry_at'),
                ])
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString(),
            'searchQuery' => $search,
        ]);
    }

    public function enrollDevices(): View
    {
        $defaultPublicBase = rtrim(request()->getSchemeAndHttpHost().request()->getBaseUrl(), '/');
        $defaultApiBase = $defaultPublicBase.'/api/v1';

        return view('admin.enroll-devices', [
            'recent_tokens' => EnrollmentToken::query()
                ->latest('created_at')
                ->limit(5)
                ->get(['id', 'expires_at', 'created_at', 'used_by_device_id', 'created_by']),
            'releases' => AgentRelease::query()->latest('created_at')->get(),
            'activeRelease' => AgentRelease::query()->where('is_active', true)->first(),
            'generated' => session('agent_generated'),
            'connectivity' => session('agent_connectivity'),
            'defaultApiBase' => $defaultApiBase,
            'defaultPublicBase' => $defaultPublicBase,
        ]);
    }

    public function deviceDetail(string $deviceId): View
    {
        $device = Device::query()->findOrFail($deviceId);
        $groupIds = \DB::table('device_group_memberships')
            ->where('device_id', $device->id)
            ->pluck('device_group_id')
            ->all();

        $effectivePolicyAssignments = \DB::table('policy_assignments as a')
            ->join('policy_versions as pv', 'pv.id', '=', 'a.policy_version_id')
            ->join('policies as p', 'p.id', '=', 'pv.policy_id')
            ->where(function ($query) use ($device, $groupIds) {
                $query->where(function ($deviceQuery) use ($device) {
                    $deviceQuery->where('a.target_type', 'device')->where('a.target_id', $device->id);
                });
                if ($groupIds !== []) {
                    $query->orWhere(function ($groupQuery) use ($groupIds) {
                        $groupQuery->where('a.target_type', 'group')->whereIn('a.target_id', $groupIds);
                    });
                }
            })
            ->select([
                'a.id as assignment_id',
                'a.target_type',
                'a.target_id',
                'a.updated_at as assignment_updated_at',
                'pv.id as policy_version_id',
                'pv.version_number',
                'pv.status as policy_version_status',
                'p.id as policy_id',
                'p.name as policy_name',
                'p.slug as policy_slug',
            ])
            ->orderByDesc('a.updated_at')
            ->get();

        $recentRuns = JobRun::query()
            ->where('device_id', $device->id)
            ->latest('updated_at')
            ->limit(600)
            ->get();
        $relatedJobs = DmsJob::query()
            ->whereIn('id', $recentRuns->pluck('job_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $policyRunByVersion = [];
        $packageRunByVersion = [];
        foreach ($recentRuns as $run) {
            $job = $relatedJobs->get($run->job_id);
            if (! $job) {
                continue;
            }
            $payload = is_array($job->payload) ? $job->payload : [];

            if ($job->job_type === 'apply_policy') {
                $policyVersionId = (string) ($payload['policy_version_id'] ?? '');
                if ($policyVersionId !== '' && ! array_key_exists($policyVersionId, $policyRunByVersion)) {
                    $policyRunByVersion[$policyVersionId] = $run;
                }
                continue;
            }

            if (! in_array($job->job_type, ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive', 'uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'], true)) {
                continue;
            }
            $packageVersionId = (string) ($payload['package_version_id'] ?? '');
            if ($packageVersionId !== '' && ! array_key_exists($packageVersionId, $packageRunByVersion)) {
                $packageRunByVersion[$packageVersionId] = (object) [
                    'run' => $run,
                    'job' => $job,
                ];
            }
        }

        $effectivePolicies = $effectivePolicyAssignments
            ->unique('policy_version_id')
            ->values()
            ->map(function ($assignment) use ($policyRunByVersion) {
                $run = $policyRunByVersion[$assignment->policy_version_id] ?? null;
                return (object) [
                    'assignment_id' => $assignment->assignment_id,
                    'policy_name' => $assignment->policy_name,
                    'policy_slug' => $assignment->policy_slug,
                    'policy_version_id' => $assignment->policy_version_id,
                    'version_number' => $assignment->version_number,
                    'assignment_source' => $assignment->target_type,
                    'policy_version_status' => $assignment->policy_version_status,
                    'last_run_status' => $run?->status ?? 'assigned',
                    'last_run_error' => $run?->last_error ?? null,
                    'last_run_id' => $run?->id,
                    'last_run_at' => $run?->updated_at,
                ];
            });
        $tags = is_array($device->tags) ? $device->tags : [];
        $runtimeDiagnostics = $this->normalizeRuntimeDiagnostics(
            is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : null
        );

        $packageVersions = PackageVersion::query()
            ->whereIn('id', array_keys($packageRunByVersion))
            ->get(['id', 'package_id', 'version', 'channel'])
            ->keyBy('id');
        $packages = PackageModel::query()
            ->whereIn('id', $packageVersions->pluck('package_id')->unique()->values())
            ->get(['id', 'name', 'slug', 'package_type'])
            ->keyBy('id');

        $devicePackages = collect($packageRunByVersion)->map(function ($item, $packageVersionId) use ($packageVersions, $packages) {
            $version = $packageVersions->get($packageVersionId);
            $package = $version ? $packages->get($version->package_id) : null;
            $resultPayload = is_array($item->run->result_payload) ? $item->run->result_payload : [];
            $alreadyInstalled = (bool) ($resultPayload['already_installed'] ?? false);

            return (object) [
                'package_id' => $package?->id,
                'package_version_id' => $version?->id,
                'package_name' => $package?->name ?? 'Unknown package',
                'package_slug' => $package?->slug ?? '-',
                'package_type' => $package?->package_type ?? '-',
                'version' => $version?->version ?? '-',
                'channel' => $version?->channel ?? '-',
                'job_type' => $item->job->job_type,
                'status' => $item->run->status,
                'already_installed' => $alreadyInstalled,
                'last_error' => $item->run->last_error,
                'run_id' => $item->run->id,
                'updated_at' => $item->run->updated_at,
            ];
        })
            ->filter(function ($row) {
                $isUninstall = in_array((string) ($row->job_type ?? ''), ['uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'], true);
                $isSuccess = strtolower((string) ($row->status ?? '')) === 'success';
                // If uninstall succeeded, package is no longer on this device.
                return ! ($isUninstall && $isSuccess);
            })
            ->sortByDesc(fn ($row) => $row->updated_at)
            ->values();

        return view('admin.device-detail', [
            'device' => $device,
            'job_runs' => JobRun::query()
                ->where('device_id', $device->id)
                ->latest('updated_at')
                ->limit(50)
                ->get(),
            'compliance' => ComplianceResult::query()
                ->where('device_id', $device->id)
                ->latest('checked_at')
                ->limit(50)
                ->get(),
            'audit' => AuditLog::query()
                ->where('actor_device_id', $device->id)
                ->orWhere(fn ($query) => $query->where('entity_type', 'device')->where('entity_id', $device->id))
                ->latest('id')
                ->limit(80)
                ->get(),
            'assigned_policy_versions' => \DB::table('policy_assignments')
                ->where(function ($query) use ($device) {
                    $query->where('target_type', 'device')->where('target_id', $device->id);
                })
                ->latest('updated_at')
                ->limit(20)
                ->get(),
            'effective_policies' => $effectivePolicies,
            'device_packages' => $devicePackages,
        ]);
    }

    public function removeDevicePolicyAssignment(Request $request, string $deviceId, string $assignmentId, AuditLogger $auditLogger): RedirectResponse
    {
        try {
            $device = Device::query()->findOrFail($deviceId);
            $assignment = \DB::table('policy_assignments')
                ->where('id', $assignmentId)
                ->where('target_type', 'device')
                ->where('target_id', $device->id)
                ->first();

            if (! $assignment) {
                return back()->withErrors(['device_policy' => 'Policy assignment not found for this device.']);
            }

            $removedPolicyVersionId = (string) ($assignment->policy_version_id ?? '');
            \DB::table('policy_assignments')->where('id', $assignmentId)->delete();
            $cleanupQueued = $removedPolicyVersionId !== ''
                ? $this->queuePolicyRemovalProfileForDevice($device->id, $removedPolicyVersionId, $request->user()?->id)
                : 0;
            $queued = $this->queuePolicyReconcileForDevice($device->id, $request->user()?->id);

            $auditLogger->log('device.policy_assignment.remove.web', 'policy_assignment', $assignmentId, (array) $assignment, [
                'device_id' => $device->id,
                'queued_policy_remove_jobs' => $cleanupQueued,
                'queued_policy_reconcile_jobs' => $queued,
            ], $request->user()?->id);

            $status = "Policy removed from device. Queued {$queued} reconcile job(s).";
            if ($cleanupQueued > 0) {
                $status .= " Queued {$cleanupQueued} remove policy job(s).";
            }

            return back()->with('status', $status);
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors([
                'device_policy' => 'Failed to remove policy assignment. Check logs for details.',
            ]);
        }
    }

    public function deviceDetailLive(string $deviceId): JsonResponse
    {
        $device = Device::query()->findOrFail($deviceId);
        $onlineWindowMinutes = max(1, (int) ($this->settingInt('jobs.online_window_minutes', 2)));
        $rawStatus = strtolower((string) ($device->status ?? 'offline'));
        $currentStatus = $rawStatus;
        if (! in_array($rawStatus, ['pending', 'quarantined'], true)) {
            $isFresh = $device->last_seen_at !== null
                && $device->last_seen_at->gt(now()->subMinutes($onlineWindowMinutes));
            $currentStatus = $isFresh ? 'online' : 'offline';
        }

        $jobRuns = JobRun::query()
            ->where('device_id', $device->id)
            ->latest('updated_at')
            ->limit(20)
            ->get();

        $tags = is_array($device->tags) ? $device->tags : [];
        $runtimeDiagnostics = $this->normalizeRuntimeDiagnostics(
            is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : null
        );

        return response()->json([
            'device' => [
                'id' => $device->id,
                'hostname' => $device->hostname,
                'status' => $currentStatus,
                'raw_status' => $device->status,
                'agent_version' => $device->agent_version,
                'agent_build' => (string) ($tags['agent_build'] ?? 'unknown'),
                'inventory' => is_array($tags['inventory'] ?? null) ? $tags['inventory'] : null,
                'inventory_updated_at' => (string) ($tags['inventory_updated_at'] ?? ''),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_seen_human' => $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'never',
            ],
            'job_runs' => $jobRuns->map(function (JobRun $run) {
                $resultPayload = is_array($run->result_payload) ? $run->result_payload : [];
                return [
                    'id' => $run->id,
                    'status' => $run->status,
                    'attempt_count' => (int) ($run->attempt_count ?? 0),
                    'next_retry_at' => $run->next_retry_at?->toDateTimeString(),
                    'last_error' => (string) ($run->last_error ?? ''),
                    'already_installed' => (bool) ($resultPayload['already_installed'] ?? false),
                    'updated_at' => $run->updated_at?->toDateTimeString(),
                ];
            })->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function normalizeRuntimeDiagnostics(?array $runtimeDiagnostics): array
    {
        if (! is_array($runtimeDiagnostics)) {
            return [];
        }

        $normalized = $runtimeDiagnostics;
        $boolKeys = [
            'uwf_feature_enabled',
            'uwf_filter_enabled',
            'uwf_filter_next_enabled',
            'uwf_volume_c_protected',
            'uwf_volume_c_next_protected',
            'signature_bypass_enabled',
            'signature_debug_enabled',
        ];
        foreach ($boolKeys as $key) {
            if (! array_key_exists($key, $normalized)) {
                continue;
            }
            if (! is_bool($normalized[$key])) {
                $normalized[$key] = null;
            }
        }

        $lastError = strtolower(trim((string) ($normalized['uwf_last_check_error'] ?? '')));
        $cfgExit = array_key_exists('uwf_get_config_exit_code', $normalized)
            ? (int) $normalized['uwf_get_config_exit_code']
            : null;
        $volExit = array_key_exists('uwf_volume_c_exit_code', $normalized)
            ? (int) $normalized['uwf_volume_c_exit_code']
            : null;

        $cfgTimedOut = str_contains($lastError, 'cfg: timeout')
            || (($cfgExit !== null && $cfgExit !== 0) && str_contains($lastError, 'timeout'));
        $volTimedOut = str_contains($lastError, 'vol: timeout')
            || (($volExit !== null && $volExit !== 0) && str_contains($lastError, 'timeout'));

        if ($cfgTimedOut) {
            $normalized['uwf_filter_enabled'] = null;
            $normalized['uwf_filter_next_enabled'] = null;
        }

        if ($volTimedOut) {
            $normalized['uwf_volume_c_protected'] = null;
            $normalized['uwf_volume_c_next_protected'] = null;
        }

        return $normalized;
    }

    public function updateDevice(Request $request, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,online,offline,quarantined'],
            'meshcentral_device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $device = Device::query()->findOrFail($deviceId);
        $before = $device->toArray();
        $device->update($data);

        $auditLogger->log('device.update.web', 'device', $device->id, $before, $device->toArray(), $request->user()?->id);

        return back()->with('status', 'Device updated.');
    }

    public function reenrollDevice(Request $request, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $device = Device::query()->findOrFail($deviceId);
        $before = $device->toArray();

        Device::query()->where('id', $deviceId)->update([
            'status' => 'pending',
            'last_seen_at' => null,
        ]);
        \DB::table('device_identities')->where('device_id', $deviceId)->update([
            'revoked' => true,
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);

        $rawToken = Str::random(64);
        EnrollmentToken::query()->create([
            'id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHours(24),
            'created_by' => $request->user()?->id,
            'used_by_device_id' => null,
        ]);

        $after = Device::query()->findOrFail($deviceId)->toArray();
        $auditLogger->log('device.reenroll.web', 'device', $deviceId, $before, $after, $request->user()?->id);

        return back()->with('status', 'Re-enrollment token generated for '.$device->hostname.': '.$rawToken);
    }

    public function deleteDevice(Request $request, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'admin_password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! $user || ! Hash::check((string) $data['admin_password'], (string) $user->password)) {
            return back()->withErrors([
                'device_delete' => 'Admin password is incorrect.',
            ]);
        }

        $device = Device::query()->findOrFail($deviceId);
        $before = $device->toArray();

        $payload = [
            'service_name' => 'DMSAgent',
            'install_dir' => 'C:\\Program Files\\DMS Agent',
            'data_dir' => 'C:\\ProgramData\\DMS',
            'delete_device_after_uninstall' => true,
        ] + $this->buildAgentUninstallAuthorizationPayload($user);
        $cleanupPoliciesQueued = 0;
        $cleanupPackagesQueued = 0;
        if ($this->settingBool('devices.delete_cleanup_before_uninstall', false)) {
            $cleanup = $this->queueDeleteCleanupForDevice($device->id, $user->id);
            $cleanupPoliciesQueued = (int) ($cleanup['policy_remove_jobs'] ?? 0);
            $cleanupPackagesQueued = (int) ($cleanup['package_uninstall_jobs'] ?? 0);
            $payload['cleanup_before_uninstall'] = true;
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'uninstall_agent',
            'status' => 'queued',
            'priority' => 80,
            'payload' => $payload,
            'target_type' => 'device',
            'target_id' => $device->id,
            'created_by' => $user->id,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'device_id' => $device->id,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        $tags = is_array($device->tags) ? $device->tags : [];
        $tags['deletion_requested_at'] = now()->toIso8601String();
        $tags['deletion_requested_by'] = $user->id;
        $device->update([
            'status' => 'quarantined',
            'tags' => $tags,
        ]);

        $after = $device->fresh()?->toArray();
        $auditLogger->log('device.delete.requested.web', 'device', $deviceId, $before, $after, $user->id);
        $auditLogger->log('device.delete.cleanup.web', 'device', $deviceId, null, [
            'cleanup_enabled' => (bool) ($payload['cleanup_before_uninstall'] ?? false),
            'queued_policy_remove_jobs' => $cleanupPoliciesQueued,
            'queued_package_uninstall_jobs' => $cleanupPackagesQueued,
            'agent_uninstall_job_id' => $job->id,
        ], $user->id);

        $status = 'Device deletion requested. Agent uninstall job queued; record will auto-delete after uninstall succeeds on target.';
        if (($payload['cleanup_before_uninstall'] ?? false) === true) {
            $status .= " Queued {$cleanupPoliciesQueued} policy remove job(s) and {$cleanupPackagesQueued} package uninstall job(s) before uninstall.";
        }

        return back()->with('status', $status);
    }

    public function forceDeleteDevice(Request $request, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'admin_password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! $user || ! Hash::check((string) $data['admin_password'], (string) $user->password)) {
            return back()->withErrors([
                'device_force_delete' => 'Admin password is incorrect.',
            ]);
        }

        $device = Device::query()->findOrFail($deviceId);
        $before = $device->toArray();

        $deletedCounts = $this->purgeDeviceRecordForAdmin($device->id);

        $auditLogger->log('device.force_delete.web', 'device', $deviceId, $before, [
            'deleted' => true,
            'mode' => 'force',
            'counts' => $deletedCounts,
        ], $user->id);

        return back()->with('status', 'Device force-deleted from server data. Related device records were purged immediately.');
    }

    public function createEnrollmentToken(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $rawToken = Str::random(64);
        $token = EnrollmentToken::query()->create([
            'id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHours((int) ($data['expires_hours'] ?? 24)),
            'created_by' => $request->user()?->id,
        ]);

        $auditLogger->log('enrollment_token.create.web', 'enrollment_token', $token->id, null, ['expires_at' => $token->expires_at], $request->user()?->id);

        return back()->with('status', 'Enrollment token created: '.$rawToken);
    }

    public function groups(): View
    {
        $groups = DeviceGroup::query()->latest('updated_at')->paginate(20);
        $groupIds = $groups->getCollection()->pluck('id');

        $memberCounts = \DB::table('device_group_memberships')
            ->selectRaw('device_group_id, count(*) as total')
            ->whereIn('device_group_id', $groupIds)
            ->groupBy('device_group_id')
            ->pluck('total', 'device_group_id');
        $policyCounts = \DB::table('policy_assignments')
            ->selectRaw('target_id, count(*) as total')
            ->where('target_type', 'group')
            ->whereIn('target_id', $groupIds)
            ->groupBy('target_id')
            ->pluck('total', 'target_id');
        $packageCounts = DmsJob::query()
            ->selectRaw('target_id, count(*) as total')
            ->where('target_type', 'group')
            ->whereIn('target_id', $groupIds)
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive'])
            ->groupBy('target_id')
            ->pluck('total', 'target_id');

        return view('admin.groups', [
            'groups' => $groups,
            'memberCounts' => $memberCounts,
            'policyCounts' => $policyCounts,
            'packageCounts' => $packageCounts,
        ]);
    }

    public function groupsCreate(): View
    {
        return view('admin.groups-create', [
            'devices' => Device::query()->orderBy('hostname')->get(['id', 'hostname']),
        ]);
    }

    public function groupDetail(Request $request, string $groupId): View
    {
        $group = DeviceGroup::query()->findOrFail($groupId);
        $defaultPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');

        $policyVersionOptions = PolicyVersion::query()
            ->join('policies', 'policies.id', '=', 'policy_versions.policy_id')
            ->select([
                'policy_versions.id',
                'policy_versions.version_number',
                'policy_versions.status',
                'policy_versions.updated_at',
                'policies.name as policy_name',
                'policies.slug as policy_slug',
            ])
            ->orderBy('policies.name')
            ->orderByDesc('policy_versions.version_number')
            ->get();

        $members = \DB::table('device_group_memberships as m')
            ->join('devices as d', 'd.id', '=', 'm.device_id')
            ->where('m.device_group_id', $group->id)
            ->select([
                'm.device_id',
                'm.created_at',
                'd.hostname',
                'd.status',
                'd.agent_version',
            ])
            ->orderBy('d.hostname')
            ->get();

        $policies = \DB::table('policy_assignments as a')
            ->join('policy_versions as pv', 'pv.id', '=', 'a.policy_version_id')
            ->join('policies as p', 'p.id', '=', 'pv.policy_id')
            ->where('a.target_type', 'group')
            ->where('a.target_id', $group->id)
            ->select([
                'a.id as assignment_id',
                'pv.id as policy_version_id',
                'pv.version_number',
                'pv.status as policy_version_status',
                'pv.updated_at',
                'p.id as policy_id',
                'p.name as policy_name',
                'p.slug as policy_slug',
            ])
            ->orderByDesc('pv.updated_at')
            ->get();

        $groupPackageJobs = DmsJob::query()
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive'])
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        $packageVersionIds = $groupPackageJobs
            ->map(function (DmsJob $job) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return (string) ($payload['package_version_id'] ?? '');
            })
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values();
        $packageVersionsById = PackageVersion::query()
            ->whereIn('id', $packageVersionIds)
            ->get(['id', 'package_id', 'version', 'channel'])
            ->keyBy('id');
        $packagesById = PackageModel::query()
            ->whereIn('id', $packageVersionsById->pluck('package_id')->filter()->unique()->values())
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');
        $jobRunSummaryRows = \DB::table('job_runs')
            ->selectRaw('job_id, status, count(*) as total')
            ->whereIn('job_id', $groupPackageJobs->pluck('id'))
            ->groupBy('job_id', 'status')
            ->get();
        $jobRunSummaryByJob = $jobRunSummaryRows
            ->groupBy('job_id')
            ->map(function ($rows) {
                return [
                    'pending' => (int) ($rows->firstWhere('status', 'pending')->total ?? 0),
                    'running' => (int) ($rows->firstWhere('status', 'running')->total ?? 0),
                    'acked' => (int) ($rows->firstWhere('status', 'acked')->total ?? 0),
                    'success' => (int) ($rows->firstWhere('status', 'success')->total ?? 0),
                    'failed' => (int) ($rows->firstWhere('status', 'failed')->total ?? 0),
                ];
            });
        $packages = $groupPackageJobs->map(function (DmsJob $job) use ($packageVersionsById, $packagesById, $jobRunSummaryByJob) {
            $payload = is_array($job->payload) ? $job->payload : [];
            $versionId = (string) ($payload['package_version_id'] ?? '');
            $version = $packageVersionsById->get($versionId);
            $package = $version ? $packagesById->get($version->package_id) : null;
            return (object) [
                'job_id' => $job->id,
                'job_type' => $job->job_type,
                'job_status' => $job->status,
                'created_at' => $job->created_at,
                'package_name' => $package?->name,
                'package_slug' => $package?->slug,
                'package_version' => $version?->version,
                'package_channel' => $version?->channel,
                'run_summary' => $jobRunSummaryByJob->get($job->id, ['pending' => 0, 'running' => 0, 'acked' => 0, 'success' => 0, 'failed' => 0]),
            ];
        });

        $packageVersionOptions = PackageVersion::query()
            ->join('packages', 'packages.id', '=', 'package_versions.package_id')
            ->select([
                'package_versions.id',
                'package_versions.package_id',
                'package_versions.version',
                'package_versions.channel',
                'packages.name as package_name',
                'packages.slug as package_slug',
                'packages.package_type',
            ])
            ->orderBy('packages.name')
            ->orderByDesc('package_versions.created_at')
            ->get();

        $kioskPresetMatrix = $this->kioskLockdownPresetMatrix();
        $kioskPresetKeys = collect($kioskPresetMatrix)
            ->flatMap(fn (array $section) => (array) ($section['preset_keys'] ?? []))
            ->filter(fn ($key) => is_string($key) && trim($key) !== '')
            ->unique()
            ->values();
        $kioskPresetSlugMap = collect($this->policyCatalog())
            ->filter(fn ($item) => is_array($item))
            ->whereIn('key', $kioskPresetKeys->all())
            ->mapWithKeys(fn ($item) => [(string) $item['key'] => (string) ($item['slug'] ?? '')]);
        $assignedKioskPresetKeys = \DB::table('policy_assignments as a')
            ->join('policy_versions as pv', 'pv.id', '=', 'a.policy_version_id')
            ->join('policies as p', 'p.id', '=', 'pv.policy_id')
            ->where('a.target_type', 'group')
            ->where('a.target_id', $group->id)
            ->whereIn('p.slug', $kioskPresetSlugMap->values()->filter()->all())
            ->pluck('p.slug')
            ->unique()
            ->values();
        $assignedKioskPresetMap = $kioskPresetSlugMap
            ->map(fn (string $slug) => $slug !== '' && $assignedKioskPresetKeys->contains($slug))
            ->all();

        return view('admin.group-detail', [
            'group' => $group,
            'devices' => Device::query()->orderBy('hostname')->get(['id', 'hostname']),
            'members' => $members,
            'policies' => $policies,
            'packages' => $packages,
            'policyVersionOptions' => $policyVersionOptions,
            'packageVersionOptions' => $packageVersionOptions,
            'defaultPublicBase' => $defaultPublicBase,
            'kioskPresetMatrix' => $kioskPresetMatrix,
            'assignedKioskPresetMap' => $assignedKioskPresetMap,
        ]);
    }

    public function createGroup(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'device_ids' => ['nullable', 'array'],
            'device_ids.*' => ['uuid'],
        ]);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if (! empty($data['device_ids'])) {
            foreach ($data['device_ids'] as $deviceId) {
                \DB::table('device_group_memberships')->insert([
                    'device_group_id' => $group->id,
                    'device_id' => $deviceId,
                    'created_at' => now(),
                ]);
            }
        }

        $auditLogger->log('group.create.web', 'device_group', $group->id, null, $group->toArray(), $request->user()?->id);

        return redirect()->route('admin.groups')->with('status', 'Group created.');
    }

    public function deleteGroup(Request $request, string $groupId, AuditLogger $auditLogger): RedirectResponse
    {
        $group = DeviceGroup::query()->findOrFail($groupId);
        $before = $group->toArray();

        $deviceIds = \DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->pluck('device_id')
            ->filter()
            ->unique()
            ->values();

        $groupPolicyVersionIds = \DB::table('policy_assignments')
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->pluck('policy_version_id')
            ->filter()
            ->unique()
            ->values();

        $queuedPolicyRemove = 0;
        $queuedPolicyReconcile = 0;
        $queuedPackageUninstalls = 0;
        foreach ($deviceIds as $deviceId) {
            foreach ($groupPolicyVersionIds as $policyVersionId) {
                $queuedPolicyRemove += $this->queuePolicyRemovalProfileForDevice((string) $deviceId, (string) $policyVersionId, $request->user()?->id);
            }
            $queuedPolicyReconcile += $this->queuePolicyReconcileForDevice((string) $deviceId, $request->user()?->id);
            $queuedPackageUninstalls += $this->queueGroupPackageUninstallsForDevice($group->id, (string) $deviceId, $request->user()?->id);
        }

        $groupJobIds = DmsJob::query()
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->pluck('id')
            ->values();

        \DB::transaction(function () use ($group, $groupJobIds) {
            \DB::table('device_group_memberships')->where('device_group_id', $group->id)->delete();
            \DB::table('policy_assignments')->where('target_type', 'group')->where('target_id', $group->id)->delete();
            if ($groupJobIds->isNotEmpty()) {
                \DB::table('job_events')->whereIn('job_run_id', function ($query) use ($groupJobIds) {
                    $query->select('id')->from('job_runs')->whereIn('job_id', $groupJobIds);
                })->delete();
                \DB::table('job_runs')->whereIn('job_id', $groupJobIds)->delete();
                \DB::table('jobs')->whereIn('id', $groupJobIds)->delete();
            }
            DeviceGroup::query()->where('id', $group->id)->delete();
        });

        $auditLogger->log('group.delete.web', 'device_group', $group->id, $before, [
            'members_removed' => $deviceIds->count(),
            'policy_assignments_removed' => $groupPolicyVersionIds->count(),
            'group_jobs_removed' => $groupJobIds->count(),
            'queued_policy_remove_jobs' => $queuedPolicyRemove,
            'queued_policy_reconcile_jobs' => $queuedPolicyReconcile,
            'queued_package_uninstall_jobs' => $queuedPackageUninstalls,
        ], $request->user()?->id);

        return redirect()->route('admin.groups')->with(
            'status',
            "Group deleted. Queued {$queuedPolicyRemove} policy remove job(s), {$queuedPolicyReconcile} policy reconcile job(s), and {$queuedPackageUninstalls} package uninstall job(s)."
        );
    }

    public function bulkAssignGroupMembers(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'device_group_id' => ['required', 'uuid'],
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['uuid'],
        ]);

        $inserted = 0;
        $queuedPolicyJobs = 0;
        $queuedPackageRuns = 0;
        foreach ($data['device_ids'] as $deviceId) {
            $exists = \DB::table('device_group_memberships')
                ->where('device_group_id', $data['device_group_id'])
                ->where('device_id', $deviceId)
                ->exists();
            if ($exists) {
                continue;
            }

            \DB::table('device_group_memberships')->insert([
                'device_group_id' => $data['device_group_id'],
                'device_id' => $deviceId,
                'created_at' => now(),
            ]);
            $inserted++;

            $backfill = $this->backfillGroupAssignmentsForDevice($data['device_group_id'], $deviceId, $request->user()?->id);
            $queuedPolicyJobs += $backfill['policy_jobs'];
            $queuedPackageRuns += $backfill['package_runs'];
        }

        $auditLogger->log('group.bulk_assign.web', 'device_group', $data['device_group_id'], null, [
            'inserted' => $inserted,
            'device_count' => count($data['device_ids']),
            'queued_policy_jobs' => $queuedPolicyJobs,
            'queued_package_runs' => $queuedPackageRuns,
        ], $request->user()?->id);

        return back()->with('status', "Bulk group assignment complete. Added {$inserted} device(s), queued {$queuedPolicyJobs} policy job(s), {$queuedPackageRuns} package run(s).");
    }

    public function addGroupMember(Request $request, string $groupId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid', 'exists:devices,id'],
        ]);

        $group = DeviceGroup::query()->findOrFail($groupId);
        $exists = \DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->where('device_id', $data['device_id'])
            ->exists();

        if (! $exists) {
            \DB::table('device_group_memberships')->insert([
                'device_group_id' => $group->id,
                'device_id' => $data['device_id'],
                'created_at' => now(),
            ]);
        }

        $backfill = ['policy_jobs' => 0, 'package_runs' => 0];
        if (! $exists) {
            $backfill = $this->backfillGroupAssignmentsForDevice($group->id, $data['device_id'], $request->user()?->id);
        }

        $auditLogger->log('group.member.add.web', 'device_group', $group->id, null, [
            'device_id' => $data['device_id'],
            'created' => ! $exists,
            'queued_policy_jobs' => $backfill['policy_jobs'],
            'queued_package_runs' => $backfill['package_runs'],
        ], $request->user()?->id);

        if ($exists) {
            return back()->with('status', 'Device is already in group.');
        }

        return back()->with('status', "Device added to group. Queued {$backfill['policy_jobs']} policy job(s) and {$backfill['package_runs']} package run(s).");
    }

    public function removeGroupMember(Request $request, string $groupId, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $group = DeviceGroup::query()->findOrFail($groupId);
        $groupPolicyVersionIds = \DB::table('policy_assignments')
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->pluck('policy_version_id')
            ->filter()
            ->unique()
            ->values();
        $deleted = \DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->where('device_id', $deviceId)
            ->delete();

        $queuedPolicyReconcile = 0;
        $queuedPolicyRemove = 0;
        $queuedPackageUninstalls = 0;
        if ($deleted > 0) {
            foreach ($groupPolicyVersionIds as $policyVersionId) {
                $queuedPolicyRemove += $this->queuePolicyRemovalProfileForDevice($deviceId, (string) $policyVersionId, $request->user()?->id);
            }
            $queuedPolicyReconcile = $this->queuePolicyReconcileForDevice($deviceId, $request->user()?->id);
            $queuedPackageUninstalls = $this->queueGroupPackageUninstallsForDevice($group->id, $deviceId, $request->user()?->id);
        }

        $auditLogger->log('group.member.remove.web', 'device_group', $group->id, null, [
            'device_id' => $deviceId,
            'deleted' => $deleted > 0,
            'queued_policy_remove_jobs' => $queuedPolicyRemove,
            'queued_policy_reconcile_jobs' => $queuedPolicyReconcile,
            'queued_package_uninstall_jobs' => $queuedPackageUninstalls,
        ], $request->user()?->id);

        if ($deleted > 0) {
            return back()->with('status', "Device removed from group. Queued {$queuedPolicyRemove} policy remove job(s), {$queuedPolicyReconcile} policy reconcile job(s), and {$queuedPackageUninstalls} uninstall job(s).");
        }

        return back()->with('status', 'Device was not a member of this group.');
    }

    public function removeGroupPolicyAssignment(Request $request, string $groupId, string $assignmentId, AuditLogger $auditLogger): RedirectResponse
    {
        $group = DeviceGroup::query()->findOrFail($groupId);
        $assignment = \DB::table('policy_assignments')
            ->where('id', $assignmentId)
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->first();

        if (! $assignment) {
            return back()->withErrors(['group_policy' => 'Policy assignment not found for this group.']);
        }

        $removedPolicyVersionId = (string) ($assignment->policy_version_id ?? '');
        $deviceIds = \DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->pluck('device_id')
            ->filter()
            ->unique()
            ->values();

        \DB::table('policy_assignments')->where('id', $assignmentId)->delete();
        $cleanupQueued = 0;
        if ($removedPolicyVersionId !== '' && $deviceIds->isNotEmpty()) {
            foreach ($deviceIds as $deviceId) {
                $cleanupQueued += $this->queuePolicyRemovalProfileForDevice((string) $deviceId, $removedPolicyVersionId, $request->user()?->id);
            }
        }
        $queued = $this->queuePolicyReconcileForTarget('group', $group->id, $request->user()?->id);

        $auditLogger->log('group.policy_assignment.remove.web', 'policy_assignment', $assignmentId, (array) $assignment, [
            'queued_policy_remove_jobs' => $cleanupQueued,
            'queued_policy_reconcile_jobs' => $queued,
        ], $request->user()?->id);

        return back()->with('status', "Policy removed from group. Queued {$cleanupQueued} remove policy job(s) and {$queued} reconcile job(s).");
    }

    public function addGroupPolicyAssignment(Request $request, string $groupId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'policy_version_id' => ['required', 'uuid', 'exists:policy_versions,id'],
            'queue_now' => ['nullable', 'boolean'],
        ]);

        $group = DeviceGroup::query()->findOrFail($groupId);
        $policyVersion = PolicyVersion::query()->findOrFail($data['policy_version_id']);

        $existing = \DB::table('policy_assignments')
            ->where('policy_version_id', $policyVersion->id)
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->first();

        $created = false;
        $assignmentId = $existing->id ?? null;
        if (! $existing) {
            $assignmentId = (string) Str::uuid();
            \DB::table('policy_assignments')->insert([
                'id' => $assignmentId,
                'policy_version_id' => $policyVersion->id,
                'target_type' => 'group',
                'target_id' => $group->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created = true;
        }

        $queued = false;
        if ((bool) ($data['queue_now'] ?? true)) {
            $this->queueApplyPolicyJob($policyVersion, 'group', $group->id, $request->user()?->id);
            $queued = true;
        }

        $auditLogger->log('group.policy_assignment.add.web', 'policy_assignment', (string) $assignmentId, null, [
            'group_id' => $group->id,
            'policy_version_id' => $policyVersion->id,
            'created_assignment' => $created,
            'queued_apply_job' => $queued,
        ], $request->user()?->id);

        if ($created && $queued) {
            return back()->with('status', 'Policy assigned to group and apply job queued.');
        }
        if ($created) {
            return back()->with('status', 'Policy assigned to group.');
        }
        if ($queued) {
            return back()->with('status', 'Policy assignment already exists. Apply job queued.');
        }

        return back()->with('status', 'Policy assignment already exists.');
    }

    public function applyGroupKioskLockdown(Request $request, string $groupId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'queue_now' => ['nullable', 'boolean'],
            'include_app_controls' => ['nullable', 'boolean'],
            'include_usb_lock' => ['nullable', 'boolean'],
            'include_local_admin_restriction' => ['nullable', 'boolean'],
            'include_shell_lock' => ['nullable', 'boolean'],
            'include_taskmgr_lock' => ['nullable', 'boolean'],
            'include_control_panel_lock' => ['nullable', 'boolean'],
        ]);

        $group = DeviceGroup::query()->findOrFail($groupId);
        $queueNow = (bool) ($data['queue_now'] ?? true);
        $matrix = $this->kioskLockdownPresetMatrix();

        $selectedKeys = collect($matrix)
            ->filter(function (array $section) use ($data) {
                $toggle = (string) ($section['toggle'] ?? '');
                if ($toggle === '') {
                    return false;
                }
                return (bool) ($data[$toggle] ?? false);
            })
            ->flatMap(fn (array $section) => (array) ($section['preset_keys'] ?? []))
            ->filter(fn ($key) => is_string($key) && trim($key) !== '')
            ->map(fn ($key) => trim((string) $key))
            ->unique()
            ->values();

        if ($selectedKeys->isEmpty()) {
            return back()->withErrors(['group_policy' => 'Select at least one kiosk control section.']);
        }

        $catalogByKey = collect($this->policyCatalog())
            ->filter(fn ($item) => is_array($item))
            ->keyBy(fn ($item) => (string) ($item['key'] ?? ''));

        $createdPolicies = 0;
        $createdVersions = 0;
        $createdAssignments = 0;
        $queuedJobs = 0;
        $appliedKeys = [];
        $missingKeys = [];
        $errors = [];

        foreach ($selectedKeys as $presetKey) {
            $catalogItem = $catalogByKey->get((string) $presetKey);
            if (! is_array($catalogItem)) {
                $missingKeys[] = (string) $presetKey;
                continue;
            }

            $ensured = $this->ensureCatalogPresetPolicyVersion($catalogItem, $request->user()?->id);
            $version = $ensured['version'] ?? null;
            if (! $version instanceof PolicyVersion) {
                $errors[] = 'Failed to prepare policy preset: '.(string) $presetKey;
                continue;
            }

            if ((bool) ($ensured['policy_created'] ?? false)) {
                $createdPolicies++;
            }
            if ((bool) ($ensured['version_created'] ?? false)) {
                $createdVersions++;
            }

            $created = $this->createPolicyAssignment($version->id, 'group', $group->id);
            if ($created) {
                $createdAssignments++;
            }

            if ($queueNow) {
                $this->queueApplyPolicyJob($version, 'group', $group->id, $request->user()?->id);
                $queuedJobs++;
            }

            $appliedKeys[] = (string) $presetKey;
        }

        $auditLogger->log('group.kiosk_lockdown.apply.web', 'device_group', $group->id, null, [
            'selected_keys' => $selectedKeys->all(),
            'applied_keys' => $appliedKeys,
            'missing_keys' => $missingKeys,
            'errors' => $errors,
            'created_policies' => $createdPolicies,
            'created_versions' => $createdVersions,
            'created_assignments' => $createdAssignments,
            'queued_jobs' => $queuedJobs,
            'queue_now' => $queueNow,
        ], $request->user()?->id);

        if ($errors !== []) {
            return back()->withErrors([
                'group_policy' => 'Kiosk lockdown partially applied. '.implode(' | ', array_unique($errors)),
            ]);
        }

        $status = "Kiosk lockdown bundle applied. Presets: ".count($appliedKeys).", new policies: {$createdPolicies}, new versions: {$createdVersions}, new assignments: {$createdAssignments}.";
        if ($queueNow) {
            $status .= " Queued {$queuedJobs} apply job(s).";
        }
        if ($missingKeys !== []) {
            $status .= ' Missing presets: '.implode(', ', $missingKeys).'.';
        }

        return back()->with('status', $status);
    }

    public function addGroupPackageAssignment(Request $request, string $groupId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'package_version_id' => ['required', 'uuid', 'exists:package_versions,id'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'stagger_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'public_base_url' => ['nullable', 'url'],
        ]);

        $group = DeviceGroup::query()->findOrFail($groupId);
        $version = PackageVersion::query()->findOrFail((string) $data['package_version_id']);
        $package = PackageModel::query()->findOrFail($version->package_id);
        $installArgs = is_array($version->install_args) ? $version->install_args : [];
        $detection = $this->normalizeDetectionRule(is_array($version->detection_rules) ? $version->detection_rules : []);
        $priority = (int) ($data['priority'] ?? 100);
        $staggerSeconds = (int) ($data['stagger_seconds'] ?? 0);

        $payload = [
            'package_id' => $package->id,
            'package_version_id' => $version->id,
        ];
        if ($detection !== null) {
            $payload['detection'] = $detection;
        }

        $jobType = '';
        if ($package->package_type === 'winget') {
            $wingetId = (string) ($installArgs['winget_id'] ?? $package->slug);
            if ($wingetId === '') {
                return back()->withErrors(['group_package' => 'Winget package requires install_args_json {"winget_id":"..."} or a valid package slug.'])->withInput();
            }
            $jobType = 'install_package';
            $payload['winget_id'] = $wingetId;
        } elseif ($package->package_type === 'config_file') {
            $file = PackageFile::query()->where('package_version_id', $version->id)->first();
            if (! $file) {
                return back()->withErrors(['group_package' => 'Config file package requires an artifact or source URI with SHA256.'])->withInput();
            }

            $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
            $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? $requestPublicBase), '/');
            if ($this->isLocalOnlyHost($publicBaseUrl)) {
                return back()->withErrors([
                    'group_package' => 'Package download link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.',
                ])->withInput();
            }

            $downloadUrl = $this->resolvePackageArtifactDownloadUrl(
                $request,
                $file,
                (int) ($data['expires_hours'] ?? 24),
                $publicBaseUrl
            );
            $configuredTargetPath = trim((string) ($installArgs['config_target_path'] ?? ''));
            if ($configuredTargetPath === '') {
                return back()->withErrors(['group_package' => 'Config file package requires install_args_json {"config_target_path":"C:\\\\path\\\\config.json"}'])->withInput();
            }
            $targetPath = $this->resolveConfigDeployTargetPath($configuredTargetPath, (string) $file->file_name);

            $jobType = 'install_exe';
            $payload['path'] = 'powershell.exe';
            $payload['silent_args'] = $this->buildConfigFilePushPowerShellArgs(
                $downloadUrl,
                strtolower((string) $file->sha256),
                $targetPath,
                (bool) ($installArgs['backup_existing'] ?? true),
                isset($installArgs['restart_service']) ? trim((string) $installArgs['restart_service']) : null
            );
            $payload['file_name'] = $file->file_name;
            $payload['sha256'] = strtolower((string) $file->sha256);
            $payload['config_target_path'] = $targetPath;
        } elseif ($package->package_type === 'archive_bundle') {
            $file = PackageFile::query()->where('package_version_id', $version->id)->first();
            if (! $file) {
                return back()->withErrors(['group_package' => 'Archive bundle package requires an artifact or source URI with SHA256.'])->withInput();
            }

            $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
            $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? $requestPublicBase), '/');
            if ($this->isLocalOnlyHost($publicBaseUrl)) {
                return back()->withErrors([
                    'group_package' => 'Package download link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.',
                ])->withInput();
            }

            $downloadUrl = $this->resolvePackageArtifactDownloadUrl(
                $request,
                $file,
                (int) ($data['expires_hours'] ?? 24),
                $publicBaseUrl
            );
            $extractTo = trim((string) (
                $installArgs['extract_to']
                ?? $installArgs['target_dir']
                ?? $installArgs['install_dir']
                ?? ''
            ));
            if ($extractTo === '') {
                return back()->withErrors(['group_package' => 'Archive bundle deploy requires install_args_json {"extract_to":"C:\\\\path\\\\folder"}'])->withInput();
            }
            $extension = strtolower(pathinfo((string) $file->file_name, PATHINFO_EXTENSION));
            if ($extension !== 'zip') {
                return back()->withErrors(['group_package' => 'Archive bundle currently supports .zip artifacts only.'])->withInput();
            }

            $jobType = 'install_archive';
            $payload['download_url'] = $downloadUrl;
            $payload['sha256'] = strtolower((string) $file->sha256);
            $payload['file_name'] = (string) $file->file_name;
            $payload['extract_to'] = $extractTo;
            $payload['clean_target'] = (bool) ($installArgs['clean_target'] ?? false);
            $payload['strip_top_level'] = (bool) ($installArgs['strip_top_level'] ?? false);
            if (! empty($installArgs['post_install_command'])) {
                $payload['post_install_command'] = (string) $installArgs['post_install_command'];
            }
            if (array_key_exists('keep_artifact', $installArgs)) {
                $payload['keep_artifact'] = (bool) $installArgs['keep_artifact'];
            }
        } else {
            $file = PackageFile::query()->where('package_version_id', $version->id)->first();
            if (! $file) {
                return back()->withErrors(['group_package' => 'No package artifact found for this version. Upload a file or configure source URI first.'])->withInput();
            }

            $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
            $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? $requestPublicBase), '/');
            if ($this->isLocalOnlyHost($publicBaseUrl)) {
                return back()->withErrors([
                    'group_package' => 'Package download link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.',
                ])->withInput();
            }

            $downloadUrl = $this->resolvePackageArtifactDownloadUrl(
                $request,
                $file,
                (int) ($data['expires_hours'] ?? 24),
                $publicBaseUrl
            );
            $extension = strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION));
            if ($extension === 'msi') {
                $jobType = 'install_msi';
                $payload['msi_args'] = (string) ($installArgs['msi_args'] ?? '/qn /norestart');
            } elseif ($extension === 'exe') {
                $jobType = 'install_exe';
                $payload['silent_args'] = (string) ($installArgs['silent_args'] ?? '/S');
            } else {
                return back()->withErrors([
                    'group_package' => 'Only .msi and .exe artifacts are supported for group package deploy.',
                ])->withInput();
            }

            $payload['download_url'] = $downloadUrl;
            $payload['sha256'] = strtolower((string) $file->sha256);
            $payload['file_name'] = $file->file_name;
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => $jobType,
            'status' => 'queued',
            'priority' => $priority,
            'payload' => $payload,
            'target_type' => 'group',
            'target_id' => $group->id,
            'created_by' => $request->user()?->id,
        ]);

        $deviceIds = \DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->pluck('device_id');

        $createdRuns = 0;
        $index = 0;
        foreach ($deviceIds as $deviceId) {
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $deviceId,
                'status' => 'pending',
                'next_retry_at' => $staggerSeconds > 0 ? now()->addSeconds($index * $staggerSeconds) : null,
            ]);
            $createdRuns++;
            $index++;
        }

        $auditLogger->log('group.package_assignment.add.web', 'job', $job->id, null, [
            'group_id' => $group->id,
            'package_id' => $package->id,
            'package_version_id' => $version->id,
            'job_type' => $jobType,
            'created_runs' => $createdRuns,
        ], $request->user()?->id);

        return back()->with('status', "Package assigned to group and deployment queued. Runs: {$createdRuns}.");
    }

    public function removeGroupPackageAssignment(Request $request, string $groupId, string $jobId, AuditLogger $auditLogger): RedirectResponse
    {
        $group = DeviceGroup::query()->findOrFail($groupId);
        $job = DmsJob::query()
            ->where('id', $jobId)
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive'])
            ->first();

        if (! $job) {
            return back()->withErrors(['group_package' => 'Group package assignment not found.']);
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $packageVersionId = (string) ($payload['package_version_id'] ?? '');
        if ($packageVersionId === '') {
            return back()->withErrors(['group_package' => 'Assignment payload missing package_version_id.']);
        }

        $version = PackageVersion::query()->find($packageVersionId);
        if (! $version) {
            return back()->withErrors(['group_package' => 'Package version not found for this assignment.']);
        }
        $package = PackageModel::query()->find($version->package_id);
        if (! $package) {
            return back()->withErrors(['group_package' => 'Package not found for this assignment.']);
        }

        $deviceIds = \DB::table('device_group_memberships')
            ->where('device_group_id', $group->id)
            ->pluck('device_id')
            ->values()
            ->all();

        $queuedUninstalls = 0;
        $uninstallErrors = [];
        foreach ($deviceIds as $deviceId) {
            $result = $this->queueUninstallForDeviceAndVersion((string) $deviceId, $version, $package, $request->user()?->id, 100);
            if ((bool) ($result['queued'] ?? false)) {
                $queuedUninstalls++;
                continue;
            }
            $uninstallErrors[] = (string) ($result['error'] ?? 'unknown');
        }

        $before = $job->toArray();
        \DB::transaction(function () use ($job) {
            JobRun::query()->where('job_id', $job->id)->delete();
            $job->delete();
        });

        $auditLogger->log('group.package_assignment.remove.web', 'job', $jobId, $before, [
            'group_id' => $group->id,
            'package_id' => $package->id,
            'package_version_id' => $version->id,
            'member_count' => count($deviceIds),
            'queued_uninstall_jobs' => $queuedUninstalls,
            'uninstall_errors' => $uninstallErrors,
        ], $request->user()?->id);

        if ($uninstallErrors !== []) {
            return back()->withErrors([
                'group_package' => "Package assignment removed. Uninstall queued: {$queuedUninstalls}. Some devices could not queue uninstall: ".implode('; ', array_unique($uninstallErrors)),
            ]);
        }

        return back()->with('status', "Package removed from group. Queued {$queuedUninstalls} uninstall job(s) to current group devices.");
    }

    public function packages(): View
    {
        $packages = PackageModel::query()->latest('updated_at')->paginate(20);
        $packageIds = $packages->pluck('id');
        $versions = PackageVersion::query()
            ->whereIn('package_id', $packageIds)
            ->orderByDesc('created_at')
            ->get();

        $filesByVersion = PackageFile::query()
            ->whereIn('package_version_id', $versions->pluck('id'))
            ->get()
            ->keyBy('package_version_id');

        $versionIds = $versions->pluck('id')->all();
        $deploymentJobs = DmsJob::query()
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive', 'uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'])
            ->latest('created_at')
            ->limit(300)
            ->get()
            ->filter(function (DmsJob $job) use ($versionIds) {
                $payload = is_array($job->payload) ? $job->payload : [];
                $packageVersionId = (string) ($payload['package_version_id'] ?? '');
                return $packageVersionId !== '' && in_array($packageVersionId, $versionIds, true);
            })
            ->values();

        $deploymentDeviceIds = $deploymentJobs->where('target_type', 'device')->pluck('target_id')->filter()->unique()->values();
        $deploymentGroupIds = $deploymentJobs->where('target_type', 'group')->pluck('target_id')->filter()->unique()->values();
        $deploymentDeviceNames = Device::query()->whereIn('id', $deploymentDeviceIds)->pluck('hostname', 'id');
        $deploymentGroupNames = DeviceGroup::query()->whereIn('id', $deploymentGroupIds)->pluck('name', 'id');
        $versionsById = $versions->keyBy('id');
        $packagesById = $packages->getCollection()->keyBy('id');

        return view('admin.packages', [
            'packages' => $packages,
            'versionsByPackage' => $versions->groupBy('package_id'),
            'filesByVersion' => $filesByVersion,
            'devices' => Device::query()->orderBy('hostname')->get(['id', 'hostname']),
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
            'deploymentJobs' => $deploymentJobs,
            'deploymentDeviceNames' => $deploymentDeviceNames,
            'deploymentGroupNames' => $deploymentGroupNames,
            'versionsById' => $versionsById,
            'packagesById' => $packagesById,
        ]);
    }

    public function packageDetail(string $packageId): View
    {
        $package = PackageModel::query()->findOrFail($packageId);
        $versions = PackageVersion::query()
            ->where('package_id', $package->id)
            ->latest('created_at')
            ->get();

        $filesByVersion = PackageFile::query()
            ->whereIn('package_version_id', $versions->pluck('id'))
            ->get()
            ->keyBy('package_version_id');

        $versionIds = $versions->pluck('id')->all();
        $deploymentJobs = DmsJob::query()
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive', 'uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'])
            ->latest('created_at')
            ->limit(500)
            ->get()
            ->filter(function (DmsJob $job) use ($versionIds) {
                $payload = is_array($job->payload) ? $job->payload : [];
                $packageVersionId = (string) ($payload['package_version_id'] ?? '');
                return $packageVersionId !== '' && in_array($packageVersionId, $versionIds, true);
            })
            ->values();

        $deviceNames = Device::query()
            ->whereIn('id', $deploymentJobs->where('target_type', 'device')->pluck('target_id')->unique()->values())
            ->pluck('hostname', 'id');
        $groupNames = DeviceGroup::query()
            ->whereIn('id', $deploymentJobs->where('target_type', 'group')->pluck('target_id')->unique()->values())
            ->pluck('name', 'id');

        $runSummaryByJob = JobRun::query()
            ->selectRaw(
                "job_id,
                count(*) as total_runs,
                sum(case when status = 'success' then 1 else 0 end) as success_runs,
                sum(case when status = 'failed' then 1 else 0 end) as failed_runs,
                sum(case when status in ('pending','acked','running') then 1 else 0 end) as pending_runs"
            )
            ->whereIn('job_id', $deploymentJobs->pluck('id'))
            ->groupBy('job_id')
            ->get()
            ->keyBy('job_id');

        return view('admin.package-detail', [
            'package' => $package,
            'versions' => $versions,
            'filesByVersion' => $filesByVersion,
            'deploymentJobs' => $deploymentJobs,
            'runSummaryByJob' => $runSummaryByJob,
            'deviceNames' => $deviceNames,
            'groupNames' => $groupNames,
            'devices' => Device::query()->orderBy('hostname')->get(['id', 'hostname']),
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function createPackage(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'package_type' => ['required', 'in:winget,msi,exe,custom,config_file,archive_bundle'],
        ]);

        $package = PackageModel::query()->create([
            'id' => (string) Str::uuid(),
            ...$data,
        ]);

        $auditLogger->log('package.create.web', 'package', $package->id, null, $package->toArray(), $request->user()?->id);

        return back()->with('status', 'Package created.');
    }

    public function createPackageVersion(Request $request, string $packageId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:100'],
            'source_uri' => ['nullable', 'url'],
            'sha256' => ['nullable', 'string', 'size:64'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'install_args_json' => ['nullable', 'json'],
            'uninstall_args_json' => ['nullable', 'json'],
            // Size limit is controlled at PHP/web-server level.
            'artifact' => ['nullable', 'file'],
            'detection_type' => ['required', 'in:registry,file,product_code,version'],
            'detection_value' => ['required', 'string', 'max:1000'],
            'config_target_path' => ['nullable', 'string', 'max:1000'],
            'backup_existing' => ['nullable', 'boolean'],
            'restart_service' => ['nullable', 'string', 'max:120'],
        ]);

        $hasSourceUri = ! empty($data['source_uri']);
        $hasSha256 = ! empty($data['sha256']);
        if ($hasSourceUri xor $hasSha256) {
            return back()->withErrors([
                'package_version' => 'Source URI requires SHA256, and SHA256 requires Source URI. Provide both fields together.',
            ])->withInput();
        }

        $package = PackageModel::query()->findOrFail($packageId);
        $installArgs = null;
        if (! empty($data['install_args_json'])) {
            $installArgs = json_decode($data['install_args_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        if ($package->package_type === 'config_file') {
            $targetPath = trim((string) ($data['config_target_path'] ?? ''));
            if ($targetPath === '') {
                return back()->withErrors([
                    'package_version' => 'Config file package requires target path (for example C:\\ProgramData\\Vendor\\config.json).',
                ])->withInput();
            }
            $installArgs = is_array($installArgs) ? $installArgs : [];
            $installArgs['config_target_path'] = $targetPath;
            $installArgs['backup_existing'] = (bool) ($data['backup_existing'] ?? true);
            if (! empty($data['restart_service'])) {
                $installArgs['restart_service'] = trim((string) $data['restart_service']);
            }
        }
        $uninstallArgs = null;
        if (! empty($data['uninstall_args_json'])) {
            $uninstallArgs = json_decode($data['uninstall_args_json'], true, 512, JSON_THROW_ON_ERROR);
        }

        $version = PackageVersion::query()->create([
            'id' => (string) Str::uuid(),
            'package_id' => $packageId,
            'version' => $data['version'],
            'channel' => $data['channel'] ?? 'stable',
            'install_args' => $installArgs,
            'uninstall_args' => $uninstallArgs,
            'detection_rules' => [
                'type' => $data['detection_type'],
                'value' => $data['detection_value'],
            ],
        ]);

        if ($request->hasFile('artifact')) {
            $artifact = $request->file('artifact');
            $storedPath = $artifact->storeAs(
                'package-artifacts/'.$package->id.'/'.$version->id,
                Str::uuid().'_'.$artifact->getClientOriginalName()
            );

            PackageFile::query()->create([
                'id' => (string) Str::uuid(),
                'package_version_id' => $version->id,
                'file_name' => $artifact->getClientOriginalName(),
                'source_uri' => 'uploaded://'.$storedPath,
                'size_bytes' => (int) ($artifact->getSize() ?? 0),
                'sha256' => strtolower(hash_file('sha256', $artifact->getRealPath())),
                'signature_metadata' => [
                    'storage_path' => $storedPath,
                ],
            ]);
        } elseif (! empty($data['source_uri']) && ! empty($data['sha256'])) {
            PackageFile::query()->create([
                'id' => (string) Str::uuid(),
                'package_version_id' => $version->id,
                'file_name' => $data['file_name'] ?? basename(parse_url($data['source_uri'], PHP_URL_PATH) ?: 'package.bin'),
                'source_uri' => $data['source_uri'],
                'size_bytes' => (int) ($data['size_bytes'] ?? 0),
                'sha256' => strtolower($data['sha256']),
            ]);
        }

        $auditLogger->log('package.version.create.web', 'package_version', $version->id, null, $version->toArray(), $request->user()?->id);

        return back()->with('status', 'Package version created.');
    }

    public function uninstallDevicePackage(Request $request, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'package_version_id' => ['required', 'uuid', 'exists:package_versions,id'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $device = Device::query()->findOrFail($deviceId);
        $version = PackageVersion::query()->findOrFail((string) $data['package_version_id']);
        $package = PackageModel::query()->findOrFail($version->package_id);
        $queued = $this->queueUninstallForDeviceAndVersion($device->id, $version, $package, $request->user()?->id, (int) ($data['priority'] ?? 100));
        if (! $queued['queued']) {
            return back()->withErrors(['device_package' => (string) ($queued['error'] ?? 'Unable to queue uninstall job.')]);
        }
        $jobId = (string) ($queued['job_id'] ?? '');

        $auditLogger->log('package.uninstall.device.web', 'job', $jobId, null, [
            'device_id' => $device->id,
            'package_id' => $package->id,
            'package_version_id' => $version->id,
            'job_type' => $queued['job_type'] ?? null,
        ], $request->user()?->id);

        return back()->with('status', 'Uninstall job queued for device.');
    }

    public function uninstallDeviceAgent(Request $request, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'admin_password' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $user = $request->user();
        if (! $user || ! Hash::check((string) $data['admin_password'], (string) $user->password)) {
            return back()->withErrors([
                'agent_uninstall' => 'Admin password is incorrect.',
            ]);
        }

        $device = Device::query()->findOrFail($deviceId);
        $payload = [
            'service_name' => 'DMSAgent',
            'install_dir' => 'C:\\Program Files\\DMS Agent',
            'data_dir' => 'C:\\ProgramData\\DMS',
        ] + $this->buildAgentUninstallAuthorizationPayload($user);

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'uninstall_agent',
            'status' => 'queued',
            'priority' => (int) ($data['priority'] ?? 100),
            'payload' => $payload,
            'target_type' => 'device',
            'target_id' => $device->id,
            'created_by' => $user->id,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'device_id' => $device->id,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        $auditLogger->log('agent.uninstall.device.web', 'job', $job->id, null, [
            'device_id' => $device->id,
            'job_type' => 'uninstall_agent',
            'queued_by' => $user->id,
        ], $user->id);

        return back()->with('status', 'Agent uninstall job queued for device.');
    }

    public function rebootDevice(Request $request, string $deviceId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'admin_password' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $user = $request->user();
        if (! $user || ! Hash::check((string) $data['admin_password'], (string) $user->password)) {
            return back()->withErrors([
                'device_reboot' => 'Admin password is incorrect.',
            ]);
        }

        $device = Device::query()->findOrFail($deviceId);
        $script = 'shutdown.exe /r /t 0';
        $payload = [
            'script' => $script,
            'script_sha256' => strtolower(hash('sha256', $script)),
        ];

        if ($this->settingBool('scripts.auto_allow_run_command_hashes', false)) {
            $allow = array_map('strtolower', $this->settingArray('scripts.allowed_sha256', []));
            if (! in_array($payload['script_sha256'], $allow, true)) {
                $updatedAllow = array_values(array_unique(array_merge($allow, [$payload['script_sha256']])));
                ControlPlaneSetting::query()->updateOrCreate(
                    ['key' => 'scripts.allowed_sha256'],
                    ['value' => ['value' => $updatedAllow], 'updated_by' => $user->id]
                );
            }
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'run_command',
            'status' => 'queued',
            'priority' => (int) ($data['priority'] ?? 95),
            'payload' => $payload,
            'target_type' => 'device',
            'target_id' => $device->id,
            'created_by' => $user->id,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'device_id' => $device->id,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        $auditLogger->log('device.reboot.web', 'job', $job->id, null, [
            'device_id' => $device->id,
            'job_type' => 'run_command',
            'script_sha256' => $payload['script_sha256'],
        ], $user->id);

        return back()->with('status', 'Reboot job queued for device.');
    }

    public function deletePackageVersion(Request $request, string $packageId, string $versionId, AuditLogger $auditLogger): RedirectResponse
    {
        $package = PackageModel::query()->findOrFail($packageId);
        $version = PackageVersion::query()
            ->where('id', $versionId)
            ->where('package_id', $package->id)
            ->firstOrFail();

        $before = $version->toArray();
        $files = PackageFile::query()->where('package_version_id', $version->id)->get();
        $queuedUninstalls = $this->queueUninstallForInstalledDevices([$version], $request->user()?->id);
        $deployCleanup = $this->deletePackageDeploymentData([$version->id]);

        $deletedArtifacts = 0;
        foreach ($files as $file) {
            $deletedArtifacts += $this->deletePackageArtifactForFile($file) ? 1 : 0;
        }
        \Illuminate\Support\Facades\Storage::deleteDirectory('package-artifacts/'.$package->id.'/'.$version->id);

        \DB::transaction(function () use ($version) {
            PackageFile::query()->where('package_version_id', $version->id)->delete();
            PackageVersion::query()->where('id', $version->id)->delete();
        });

        $auditLogger->log('package.version.delete.web', 'package_version', $version->id, $before, [
            'package_id' => $package->id,
            'deleted_files' => $files->count(),
            'deleted_artifacts' => $deletedArtifacts,
            'deleted_jobs' => $deployCleanup['jobs'],
            'deleted_job_runs' => $deployCleanup['job_runs'],
            'queued_uninstall_jobs' => $queuedUninstalls,
        ], $request->user()?->id);

        return back()->with('status', "Package version deleted. Queued {$queuedUninstalls} uninstall job(s).");
    }

    public function deletePackage(Request $request, string $packageId, AuditLogger $auditLogger): RedirectResponse
    {
        $package = PackageModel::query()->findOrFail($packageId);
        $before = $package->toArray();
        $versions = PackageVersion::query()->where('package_id', $package->id)->get(['id']);
        $fullVersions = PackageVersion::query()->where('package_id', $package->id)->get();
        $versionIds = $versions->pluck('id')->values()->all();
        $files = PackageFile::query()->whereIn('package_version_id', $versionIds)->get();
        $queuedUninstalls = $this->queueUninstallForInstalledDevices($fullVersions->all(), $request->user()?->id);
        $deployCleanup = $this->deletePackageDeploymentData($versionIds);

        $deletedArtifacts = 0;
        foreach ($files as $file) {
            $deletedArtifacts += $this->deletePackageArtifactForFile($file) ? 1 : 0;
        }
        \Illuminate\Support\Facades\Storage::deleteDirectory('package-artifacts/'.$package->id);

        \DB::transaction(function () use ($package, $versionIds) {
            if ($versionIds !== []) {
                PackageFile::query()->whereIn('package_version_id', $versionIds)->delete();
                PackageVersion::query()->whereIn('id', $versionIds)->delete();
            }
            PackageModel::query()->where('id', $package->id)->delete();
        });

        $auditLogger->log('package.delete.web', 'package', $package->id, $before, [
            'deleted_versions' => count($versionIds),
            'deleted_files' => $files->count(),
            'deleted_artifacts' => $deletedArtifacts,
            'deleted_jobs' => $deployCleanup['jobs'],
            'deleted_job_runs' => $deployCleanup['job_runs'],
            'queued_uninstall_jobs' => $queuedUninstalls,
        ], $request->user()?->id);

        return redirect()->route('admin.packages')->with('status', "Package deleted. Queued {$queuedUninstalls} uninstall job(s).");
    }

    public function deployPackageVersion(Request $request, string $versionId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'target_scope' => ['required', 'in:all,device,group'],
            'target_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'stagger_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'public_base_url' => ['nullable', 'url'],
        ]);

        if ($data['target_scope'] !== 'all' && empty($data['target_id'])) {
            return back()->withErrors(['package_deploy' => 'Select a target device/group, or use "all".'])->withInput();
        }

        $version = PackageVersion::query()->findOrFail($versionId);
        $package = PackageModel::query()->findOrFail($version->package_id);
        $installArgs = is_array($version->install_args) ? $version->install_args : [];
        $detection = $this->normalizeDetectionRule(is_array($version->detection_rules) ? $version->detection_rules : []);
        $priority = (int) ($data['priority'] ?? 100);
        $staggerSeconds = (int) ($data['stagger_seconds'] ?? 0);

        $jobType = '';
        $payload = [
            'package_id' => $package->id,
            'package_version_id' => $version->id,
        ];
        if ($detection !== null) {
            $payload['detection'] = $detection;
        }

        if ($package->package_type === 'winget') {
            $wingetId = (string) ($installArgs['winget_id'] ?? $package->slug);
            if ($wingetId === '') {
                return back()->withErrors(['package_deploy' => 'Winget package requires install_args_json {"winget_id":"..."} or a valid package slug.'])->withInput();
            }
            $jobType = 'install_package';
            $payload['winget_id'] = $wingetId;
        } elseif ($package->package_type === 'config_file') {
            $file = PackageFile::query()->where('package_version_id', $version->id)->first();
            if (! $file) {
                return back()->withErrors(['package_deploy' => 'Config file package requires an artifact or source URI with SHA256.'])->withInput();
            }

            $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
            $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? $requestPublicBase), '/');
            if ($this->isLocalOnlyHost($publicBaseUrl)) {
                return back()->withErrors([
                    'package_deploy' => 'Package download link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.',
                ])->withInput();
            }

            $downloadUrl = $this->resolvePackageArtifactDownloadUrl(
                $request,
                $file,
                (int) ($data['expires_hours'] ?? 24),
                $publicBaseUrl
            );
            $configuredTargetPath = trim((string) ($installArgs['config_target_path'] ?? ''));
            if ($configuredTargetPath === '') {
                return back()->withErrors(['package_deploy' => 'Config file package requires install_args_json {"config_target_path":"C:\\\\path\\\\config.json"}'])->withInput();
            }
            $targetPath = $this->resolveConfigDeployTargetPath($configuredTargetPath, (string) $file->file_name);

            $jobType = 'install_exe';
            $payload['path'] = 'powershell.exe';
            $payload['silent_args'] = $this->buildConfigFilePushPowerShellArgs(
                $downloadUrl,
                strtolower((string) $file->sha256),
                $targetPath,
                (bool) ($installArgs['backup_existing'] ?? true),
                isset($installArgs['restart_service']) ? trim((string) $installArgs['restart_service']) : null
            );
            $payload['file_name'] = $file->file_name;
            $payload['sha256'] = strtolower((string) $file->sha256);
            $payload['config_target_path'] = $targetPath;
        } elseif ($package->package_type === 'archive_bundle') {
            $file = PackageFile::query()->where('package_version_id', $version->id)->first();
            if (! $file) {
                return back()->withErrors(['package_deploy' => 'Archive bundle package requires an artifact or source URI with SHA256.'])->withInput();
            }

            $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
            $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? $requestPublicBase), '/');
            if ($this->isLocalOnlyHost($publicBaseUrl)) {
                return back()->withErrors([
                    'package_deploy' => 'Package download link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.',
                ])->withInput();
            }

            $downloadUrl = $this->resolvePackageArtifactDownloadUrl(
                $request,
                $file,
                (int) ($data['expires_hours'] ?? 24),
                $publicBaseUrl
            );
            $extractTo = trim((string) (
                $installArgs['extract_to']
                ?? $installArgs['target_dir']
                ?? $installArgs['install_dir']
                ?? ''
            ));
            if ($extractTo === '') {
                return back()->withErrors(['package_deploy' => 'Archive bundle deploy requires install_args_json {"extract_to":"C:\\\\path\\\\folder"}'])->withInput();
            }
            $extension = strtolower(pathinfo((string) $file->file_name, PATHINFO_EXTENSION));
            if ($extension !== 'zip') {
                return back()->withErrors(['package_deploy' => 'Archive bundle currently supports .zip artifacts only.'])->withInput();
            }

            $jobType = 'install_archive';
            $payload['download_url'] = $downloadUrl;
            $payload['sha256'] = strtolower((string) $file->sha256);
            $payload['file_name'] = (string) $file->file_name;
            $payload['extract_to'] = $extractTo;
            $payload['clean_target'] = (bool) ($installArgs['clean_target'] ?? false);
            $payload['strip_top_level'] = (bool) ($installArgs['strip_top_level'] ?? false);
            if (! empty($installArgs['post_install_command'])) {
                $payload['post_install_command'] = (string) $installArgs['post_install_command'];
            }
            if (array_key_exists('keep_artifact', $installArgs)) {
                $payload['keep_artifact'] = (bool) $installArgs['keep_artifact'];
            }
        } else {
            $file = PackageFile::query()->where('package_version_id', $version->id)->first();
            if (! $file) {
                return back()->withErrors(['package_deploy' => 'No package artifact found for this version. Upload a file or configure source URI first.'])->withInput();
            }

            $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
            $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? $requestPublicBase), '/');
            if ($this->isLocalOnlyHost($publicBaseUrl)) {
                return back()->withErrors([
                    'package_deploy' => 'Package download link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.',
                ])->withInput();
            }

            $downloadUrl = $this->resolvePackageArtifactDownloadUrl(
                $request,
                $file,
                (int) ($data['expires_hours'] ?? 24),
                $publicBaseUrl
            );
            $extension = strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION));
            if ($extension === 'msi') {
                $jobType = 'install_msi';
            } elseif ($extension === 'exe') {
                $jobType = 'install_exe';
            } else {
                return back()->withErrors([
                    'package_deploy' => 'Only .msi and .exe artifacts are supported for remote package deploy.',
                ])->withInput();
            }

            $payload['download_url'] = $downloadUrl;
            $payload['sha256'] = strtolower((string) $file->sha256);
            $payload['file_name'] = $file->file_name;
            if ($jobType === 'install_exe') {
                $payload['silent_args'] = (string) ($installArgs['silent_args'] ?? '/S');
            }
            if ($jobType === 'install_msi') {
                $payload['msi_args'] = (string) ($installArgs['msi_args'] ?? '/qn /norestart');
            }
        }

        $createdJobs = 0;
        $createdRuns = 0;
        if ($data['target_scope'] === 'all') {
            $deviceIds = Device::query()->pluck('id');
            $index = 0;
            foreach ($deviceIds as $deviceId) {
                $job = DmsJob::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_type' => $jobType,
                    'status' => 'queued',
                    'priority' => $priority,
                    'payload' => $payload,
                    'target_type' => 'device',
                    'target_id' => $deviceId,
                    'created_by' => $request->user()?->id,
                ]);
                JobRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'device_id' => $deviceId,
                    'status' => 'pending',
                    'next_retry_at' => $staggerSeconds > 0 ? now()->addSeconds($index * $staggerSeconds) : null,
                ]);
                $createdJobs++;
                $createdRuns++;
                $index++;
            }
        } elseif ($data['target_scope'] === 'group') {
            $targetId = (string) $data['target_id'];
            DeviceGroup::query()->findOrFail($targetId);

            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'job_type' => $jobType,
                'status' => 'queued',
                'priority' => $priority,
                'payload' => $payload,
                'target_type' => 'group',
                'target_id' => $targetId,
                'created_by' => $request->user()?->id,
            ]);
            $createdJobs++;

            $deviceIds = \DB::table('device_group_memberships')
                ->where('device_group_id', $targetId)
                ->pluck('device_id');
            $index = 0;
            foreach ($deviceIds as $deviceId) {
                JobRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'device_id' => $deviceId,
                    'status' => 'pending',
                    'next_retry_at' => $staggerSeconds > 0 ? now()->addSeconds($index * $staggerSeconds) : null,
                ]);
                $createdRuns++;
                $index++;
            }
        } else {
            $targetId = (string) $data['target_id'];
            Device::query()->findOrFail($targetId);
            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'job_type' => $jobType,
                'status' => 'queued',
                'priority' => $priority,
                'payload' => $payload,
                'target_type' => 'device',
                'target_id' => $targetId,
                'created_by' => $request->user()?->id,
            ]);
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $targetId,
                'status' => 'pending',
            ]);
            $createdJobs++;
            $createdRuns++;
        }

        $auditLogger->log('package.deploy.web', 'package_version', $version->id, null, [
            'package_id' => $package->id,
            'job_type' => $jobType,
            'target_scope' => $data['target_scope'],
            'target_id' => $data['target_id'] ?? null,
            'created_jobs' => $createdJobs,
            'created_runs' => $createdRuns,
        ], $request->user()?->id);

        return back()->with('status', "Package deploy queued. Jobs: {$createdJobs}, runs: {$createdRuns}.");
    }

    public function downloadPackageFile(string $packageFileId)
    {
        $packageFile = PackageFile::query()->findOrFail($packageFileId);
        $storedPath = $this->resolvePackageArtifactStoragePath($packageFile) ?? '';
        if ($storedPath === '' || ! is_file(storage_path('app'.DIRECTORY_SEPARATOR.$storedPath))) {
            abort(404, 'Package artifact not found');
        }

        return response()->download(
            storage_path('app'.DIRECTORY_SEPARATOR.$storedPath),
            $packageFile->file_name
        );
    }

    public function downloadPackageFilePublic(string $packageFileId)
    {
        $packageFile = PackageFile::query()->findOrFail($packageFileId);
        $storedPath = $this->resolvePackageArtifactStoragePath($packageFile) ?? '';
        if ($storedPath === '' || ! is_file(storage_path('app'.DIRECTORY_SEPARATOR.$storedPath))) {
            abort(404, 'Package artifact not found');
        }

        return response()->download(
            storage_path('app'.DIRECTORY_SEPARATOR.$storedPath),
            $packageFile->file_name
        );
    }

    public function policies(): View
    {
        $this->backfillPolicyRemovalProfiles(auth()->id());

        $policies = Policy::query()->latest('updated_at')->paginate(20);
        $policyIds = $policies->pluck('id');
        $policyCatalog = $this->policyCatalog();
        $versions = PolicyVersion::query()
            ->whereIn('policy_id', $policyIds)
            ->orderByDesc('version_number')
            ->orderByDesc('created_at')
            ->get();
        $isSuperAdmin = auth()->user()?->roles()->where('slug', 'super-admin')->exists() ?? false;

        return view('admin.policies', [
            'policies' => $policies,
            'versionsByPolicy' => $versions->groupBy('policy_id'),
            'policyCatalog' => $policyCatalog,
            'rulePresetJson' => collect($policyCatalog)->mapWithKeys(fn ($item) => [$item['rule_type'] => $item['rule_json']])->all(),
            'isSuperAdmin' => $isSuperAdmin,
            'customCatalog' => $this->settingArray('policies.catalog_custom', []),
            'policyCategories' => $this->policyCategories(),
        ]);
    }

    public function policyDetail(string $policyId): View
    {
        $this->backfillPolicyRemovalProfiles(auth()->id());

        $policy = Policy::query()->findOrFail($policyId);
        $policyCatalog = $this->policyCatalog();
        $versions = PolicyVersion::query()
            ->where('policy_id', $policy->id)
            ->orderByDesc('version_number')
            ->orderByDesc('created_at')
            ->get();
        $versionIds = $versions->pluck('id');

        $rules = PolicyRule::query()
            ->whereIn('policy_version_id', $versionIds)
            ->orderBy('order_index')
            ->get();
        $assignments = \DB::table('policy_assignments')
            ->whereIn('policy_version_id', $versionIds)
            ->orderByDesc('updated_at')
            ->get();

        $assignmentDeviceIds = $assignments->where('target_type', 'device')->pluck('target_id')->unique()->values();
        $assignmentGroupIds = $assignments->where('target_type', 'group')->pluck('target_id')->unique()->values();
        $assignmentDeviceNames = Device::query()->whereIn('id', $assignmentDeviceIds)->pluck('hostname', 'id');
        $assignmentGroupNames = DeviceGroup::query()->whereIn('id', $assignmentGroupIds)->pluck('name', 'id');
        $removalProfilesByVersion = collect($this->settingArray('policies.removal_profiles', []))
            ->filter(fn ($row) => is_array($row) && isset($row['policy_version_id']))
            ->keyBy(fn ($row) => (string) $row['policy_version_id']);
        $nextVersion = ((int) $versions->max('version_number')) + 1;

        return view('admin.policy-detail', [
            'policy' => $policy,
            'versions' => $versions,
            'rulesByVersion' => $rules->groupBy('policy_version_id'),
            'assignmentsByVersion' => $assignments->groupBy('policy_version_id'),
            'assignmentDeviceNames' => $assignmentDeviceNames,
            'assignmentGroupNames' => $assignmentGroupNames,
            'removalProfilesByVersion' => $removalProfilesByVersion,
            'nextVersion' => $nextVersion > 0 ? $nextVersion : 1,
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
            'devices' => Device::query()->orderBy('hostname')->get(['id', 'hostname']),
            'policyCatalog' => $policyCatalog,
            'rulePresetJson' => collect($policyCatalog)->mapWithKeys(fn ($item) => [$item['rule_type'] => $item['rule_json']])->all(),
            'policyCategories' => $this->policyCategories(),
        ]);
    }

    public function createPolicyCatalogPreset(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'rule_type' => ['required', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'rule_json' => ['required', 'json'],
            'description' => ['nullable', 'string', 'max:240'],
            'applies_to' => ['nullable', 'in:device,group,both'],
            'remove_mode' => ['nullable', 'in:auto,json,command'],
            'remove_rule_type' => ['nullable', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'remove_rule_json' => ['nullable', 'json'],
            'remove_command' => ['nullable', 'string', 'max:15000'],
        ]);

        try {
            $ruleJson = json_decode($data['rule_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return back()->withErrors(['policy_catalog' => 'Rule JSON is invalid.'])->withInput();
        }
        if (! is_array($ruleJson)) {
            return back()->withErrors(['policy_catalog' => 'Rule JSON must be a JSON object.'])->withInput();
        }
        $ruleValidationError = $this->validateRuleConfig((string) $data['rule_type'], $ruleJson);
        if ($ruleValidationError !== null) {
            return back()->withErrors(['policy_catalog' => $ruleValidationError])->withInput();
        }
        [$removeMode, $removeRules, $removeError] = $this->resolveCatalogRemoveRules($data, (string) $data['rule_type'], $ruleJson);
        if ($removeError !== null) {
            return back()->withErrors(['policy_catalog' => $removeError])->withInput();
        }

        $custom = collect($this->settingArray('policies.catalog_custom', []))
            ->filter(fn ($row) => is_array($row))
            ->values();

        $key = Str::slug($data['label']).'-'.Str::lower(Str::random(6));
        $custom->push([
            'key' => $key,
            'label' => $data['label'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'category' => $data['category'],
            'rule_type' => $data['rule_type'],
            'rule_json' => $ruleJson,
            'remove_mode' => $removeMode,
            'remove_rules' => $removeRules,
            'description' => trim((string) ($data['description'] ?? '')),
            'applies_to' => (string) ($data['applies_to'] ?? 'both'),
            'source' => 'custom',
        ]);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'policies.catalog_custom'],
            ['value' => ['value' => $custom->values()->all()], 'updated_by' => $request->user()?->id]
        );

        $auditLogger->log('policy.catalog.create.web', 'control_plane_settings', 'policies.catalog_custom', null, [
            'key' => $key,
            'label' => $data['label'],
            'rule_type' => $data['rule_type'],
        ], $request->user()?->id);

        return back()->with('status', 'Policy catalog preset added.');
    }

    public function deletePolicyCatalogPreset(Request $request, string $catalogKey, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $custom = collect($this->settingArray('policies.catalog_custom', []))
            ->filter(fn ($row) => is_array($row))
            ->values();
        $beforeCount = $custom->count();
        $updated = $custom->reject(fn ($row) => (string) ($row['key'] ?? '') === $catalogKey)->values();

        if ($updated->count() === $beforeCount) {
            return back()->withErrors(['policy_catalog' => 'Catalog preset not found.']);
        }

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'policies.catalog_custom'],
            ['value' => ['value' => $updated->all()], 'updated_by' => $request->user()?->id]
        );

        $auditLogger->log('policy.catalog.delete.web', 'control_plane_settings', 'policies.catalog_custom', [
            'key' => $catalogKey,
            'count' => $beforeCount,
        ], [
            'key' => $catalogKey,
            'count' => $updated->count(),
        ], $request->user()?->id);

        return back()->with('status', 'Policy catalog preset removed.');
    }

    public function updatePolicyCatalogPreset(Request $request, string $catalogKey, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'rule_type' => ['required', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'rule_json' => ['required', 'json'],
            'description' => ['nullable', 'string', 'max:240'],
            'applies_to' => ['nullable', 'in:device,group,both'],
            'remove_mode' => ['nullable', 'in:auto,json,command'],
            'remove_rule_type' => ['nullable', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'remove_rule_json' => ['nullable', 'json'],
            'remove_command' => ['nullable', 'string', 'max:15000'],
        ]);

        try {
            $ruleJson = json_decode($data['rule_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return back()->withErrors(['policy_catalog' => 'Rule JSON is invalid.']);
        }
        if (! is_array($ruleJson)) {
            return back()->withErrors(['policy_catalog' => 'Rule JSON must be a JSON object.']);
        }
        $ruleValidationError = $this->validateRuleConfig((string) $data['rule_type'], $ruleJson);
        if ($ruleValidationError !== null) {
            return back()->withErrors(['policy_catalog' => $ruleValidationError]);
        }
        [$removeMode, $removeRules, $removeError] = $this->resolveCatalogRemoveRules($data, (string) $data['rule_type'], $ruleJson);
        if ($removeError !== null) {
            return back()->withErrors(['policy_catalog' => $removeError]);
        }

        $updatedRow = [
            'key' => $catalogKey,
            'label' => $data['label'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'category' => $data['category'],
            'rule_type' => $data['rule_type'],
            'rule_json' => $ruleJson,
            'remove_mode' => $removeMode,
            'remove_rules' => $removeRules,
            'description' => trim((string) ($data['description'] ?? '')),
            'applies_to' => (string) ($data['applies_to'] ?? 'both'),
        ];

        $catalogItem = collect($this->policyCatalog())
            ->filter(fn ($row) => is_array($row))
            ->first(fn ($row) => (string) ($row['key'] ?? '') === $catalogKey);
        if (! is_array($catalogItem)) {
            return back()->withErrors(['policy_catalog' => 'Catalog preset not found.']);
        }

        $source = (string) ($catalogItem['source'] ?? 'default');
        if ($source === 'custom') {
            $custom = collect($this->settingArray('policies.catalog_custom', []))
                ->filter(fn ($row) => is_array($row))
                ->values();
            $index = $custom->search(fn ($row) => (string) ($row['key'] ?? '') === $catalogKey);
            if ($index === false) {
                return back()->withErrors(['policy_catalog' => 'Custom preset not found.']);
            }

            $before = $custom->get($index);
            $custom->put($index, $updatedRow + ['source' => 'custom']);
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => 'policies.catalog_custom'],
                ['value' => ['value' => $custom->values()->all()], 'updated_by' => $request->user()?->id]
            );

            $auditLogger->log(
                'policy.catalog.update.web',
                'control_plane_settings',
                'policies.catalog_custom',
                is_array($before) ? $before : null,
                $updatedRow + ['source' => 'custom'],
                $request->user()?->id
            );
        } else {
            $defaultOverrides = collect($this->settingArray('policies.catalog_default_overrides', []))
                ->filter(fn ($row) => is_array($row))
                ->values();
            $index = $defaultOverrides->search(fn ($row) => (string) ($row['key'] ?? '') === $catalogKey);
            $before = $catalogItem;
            if ($index === false) {
                $defaultOverrides->push($updatedRow + ['source' => 'default']);
            } else {
                $defaultOverrides->put($index, $updatedRow + ['source' => 'default']);
            }
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => 'policies.catalog_default_overrides'],
                ['value' => ['value' => $defaultOverrides->values()->all()], 'updated_by' => $request->user()?->id]
            );

            $auditLogger->log(
                'policy.catalog.update.default.web',
                'control_plane_settings',
                'policies.catalog_default_overrides',
                $before,
                $updatedRow + ['source' => 'default'],
                $request->user()?->id
            );
        }

        return back()->with('status', 'Policy catalog preset updated.');
    }

    public function createPolicyCategory(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
        ]);

        $category = trim((string) $data['category']);
        if ($category === '') {
            return back()->withErrors(['policy_category' => 'Category cannot be empty.']);
        }

        $categories = collect($this->policyCategories())->values();
        if ($categories->contains(fn ($item) => strcasecmp((string) $item, $category) === 0)) {
            return back()->withErrors(['policy_category' => 'Category already exists.']);
        }

        $categories->push($category);
        $this->saveSettingArray('policies.categories', $categories->values()->all(), $request->user()?->id);

        $auditLogger->log('policy.category.create.web', 'control_plane_settings', 'policies.categories', null, [
            'category' => $category,
            'count' => $categories->count(),
        ], $request->user()?->id);

        return back()->with('status', 'Policy category added.');
    }

    public function updatePolicyCategory(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'current_category' => ['required', 'string', 'max:100'],
            'new_category' => ['required', 'string', 'max:100'],
        ]);

        $current = trim((string) $data['current_category']);
        $new = trim((string) $data['new_category']);
        if ($current === '' || $new === '') {
            return back()->withErrors(['policy_category' => 'Category values cannot be empty.']);
        }
        if (strcasecmp($current, $new) === 0) {
            return back()->with('status', 'Category unchanged.');
        }

        $categories = collect($this->policyCategories())->values();
        $currentIdx = $categories->search(fn ($item) => strcasecmp((string) $item, $current) === 0);
        if ($currentIdx === false) {
            return back()->withErrors(['policy_category' => 'Category not found.']);
        }
        $existsNew = $categories->contains(fn ($item) => strcasecmp((string) $item, $new) === 0);
        if ($existsNew) {
            return back()->withErrors(['policy_category' => 'New category already exists.']);
        }

        $categories[$currentIdx] = $new;
        $updatedPolicies = Policy::query()->where('category', $current)->update(['category' => $new]);
        $updatedCatalog = $this->replaceCustomCatalogCategory($current, $new, $request->user()?->id);

        $this->saveSettingArray('policies.categories', $categories->values()->all(), $request->user()?->id);

        $auditLogger->log('policy.category.update.web', 'control_plane_settings', 'policies.categories', [
            'current_category' => $current,
        ], [
            'new_category' => $new,
            'updated_policies' => $updatedPolicies,
            'updated_catalog_items' => $updatedCatalog,
        ], $request->user()?->id);

        return back()->with('status', 'Policy category updated.');
    }

    public function deletePolicyCategory(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'replace_with' => ['nullable', 'string', 'max:100'],
        ]);

        $category = trim((string) $data['category']);
        $replaceWith = trim((string) ($data['replace_with'] ?? ''));
        if ($category === '') {
            return back()->withErrors(['policy_category' => 'Category cannot be empty.']);
        }
        if ($replaceWith !== '' && strcasecmp($category, $replaceWith) === 0) {
            return back()->withErrors(['policy_category' => 'Replacement category must be different.']);
        }

        $categories = collect($this->policyCategories())->values();
        $exists = $categories->contains(fn ($item) => strcasecmp((string) $item, $category) === 0);
        if (! $exists) {
            return back()->withErrors(['policy_category' => 'Category not found.']);
        }

        $policyUsage = Policy::query()->where('category', $category)->count();
        $catalogUsage = collect($this->settingArray('policies.catalog_custom', []))
            ->filter(fn ($item) => is_array($item) && strcasecmp((string) ($item['category'] ?? ''), $category) === 0)
            ->count();

        if (($policyUsage > 0 || $catalogUsage > 0) && $replaceWith === '') {
            return back()->withErrors([
                'policy_category' => 'Category is in use. Provide replacement category to migrate policies/catalog first.',
            ]);
        }

        if ($replaceWith !== '') {
            if (! $categories->contains(fn ($item) => strcasecmp((string) $item, $replaceWith) === 0)) {
                $categories->push($replaceWith);
            }
            Policy::query()->where('category', $category)->update(['category' => $replaceWith]);
            $this->replaceCustomCatalogCategory($category, $replaceWith, $request->user()?->id);
        }

        $categories = $categories
            ->reject(fn ($item) => strcasecmp((string) $item, $category) === 0)
            ->values();
        $this->saveSettingArray('policies.categories', $categories->all(), $request->user()?->id);

        $auditLogger->log('policy.category.delete.web', 'control_plane_settings', 'policies.categories', [
            'category' => $category,
        ], [
            'replace_with' => $replaceWith !== '' ? $replaceWith : null,
            'policy_usage' => $policyUsage,
            'catalog_usage' => $catalogUsage,
            'remaining_count' => $categories->count(),
        ], $request->user()?->id);

        return back()->with('status', 'Policy category deleted.');
    }

    public function createPolicy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
        ]);

        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            ...$data,
        ]);

        $auditLogger->log('policy.create.web', 'policy', $policy->id, null, $policy->toArray(), $request->user()?->id);

        return back()->with('status', 'Policy created.');
    }

    public function updatePolicy(Request $request, string $policyId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,active,retired'],
        ]);

        $policy = Policy::query()->findOrFail($policyId);
        $before = $policy->toArray();
        $policy->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'category' => $data['category'],
            'status' => $data['status'] ?? $policy->status,
        ]);

        $auditLogger->log('policy.update.web', 'policy', $policy->id, $before, $policy->toArray(), $request->user()?->id);

        return back()->with('status', 'Policy updated.');
    }

    public function deletePolicy(Request $request, string $policyId, AuditLogger $auditLogger): RedirectResponse
    {
        $policy = Policy::query()->findOrFail($policyId);
        $versionCount = PolicyVersion::query()->where('policy_id', $policy->id)->count();
        if ($versionCount > 0) {
            return back()->withErrors([
                'policy_delete' => 'Cannot delete a policy that has versions. Delete versions first.',
            ]);
        }

        $before = $policy->toArray();
        $policy->delete();
        $auditLogger->log('policy.delete.web', 'policy', $policy->id, $before, null, $request->user()?->id);

        return back()->with('status', 'Policy deleted.');
    }

    public function createPolicyVersion(Request $request, string $policyId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'version_number' => ['required', 'integer', 'min:1'],
            'apply_mode' => ['nullable', 'in:json,command'],
            'rule_type' => ['nullable', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'rule_json' => ['nullable', 'json'],
            'apply_command' => ['nullable', 'string', 'max:15000'],
            'apply_run_as' => ['nullable', 'in:default,elevated,system'],
            'apply_timeout_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'apply_uwf_ensure' => ['nullable', 'in:present,absent'],
            'apply_uwf_enable_feature' => ['nullable', 'boolean'],
            'apply_uwf_enable_filter' => ['nullable', 'boolean'],
            'apply_uwf_protect_volume' => ['nullable', 'boolean'],
            'apply_uwf_volume' => ['nullable', 'string', 'max:16'],
            'apply_uwf_reboot_now' => ['nullable', 'boolean'],
            'apply_uwf_reboot_if_pending' => ['nullable', 'boolean'],
            'apply_uwf_max_reboot_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'apply_uwf_reboot_cooldown_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'apply_uwf_reboot_command' => ['nullable', 'string', 'max:500'],
            'apply_uwf_file_exclusions' => ['nullable', 'string', 'max:4000'],
            'apply_uwf_registry_exclusions' => ['nullable', 'string', 'max:4000'],
            'apply_uwf_fail_on_unsupported_edition' => ['nullable', 'boolean'],
            'apply_uwf_overlay_type' => ['nullable', 'in:ram,disk'],
            'apply_uwf_overlay_max_size_mb' => ['nullable', 'integer', 'min:128', 'max:1048576'],
            'apply_uwf_overlay_warning_threshold_mb' => ['nullable', 'integer', 'min:64', 'max:1048576'],
            'apply_uwf_overlay_critical_threshold_mb' => ['nullable', 'integer', 'min:64', 'max:1048576'],
            'remove_mode' => ['nullable', 'in:auto,json,command'],
            'remove_rule_type' => ['nullable', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'remove_rule_json' => ['nullable', 'json'],
            'remove_command' => ['nullable', 'string', 'max:15000'],
            'target_type' => ['nullable', 'in:device,group'],
            'target_id' => ['nullable', 'uuid'],
            'assign_now' => ['nullable', 'boolean'],
        ]);

        $policy = Policy::query()->findOrFail($policyId);
        if (PolicyVersion::query()->where('policy_id', $policyId)->where('version_number', (int) $data['version_number'])->exists()) {
            return back()->withErrors([
                'policy_version' => 'Version number already exists for this policy. Use a new version number.',
            ])->withInput();
        }

        $assignNow = (bool) ($data['assign_now'] ?? false);
        if ($assignNow && (empty($data['target_type']) || empty($data['target_id']))) {
            return back()->withErrors([
                'policy_assign' => 'Choose target type and target when "assign now" is enabled.',
            ])->withInput();
        }

        [$ruleType, $ruleConfig, $resolveError] = $this->resolveApplyRuleFromRequestData($data);
        if ($resolveError !== null) {
            return back()->withErrors(['policy_version' => $resolveError])->withInput();
        }

        $ruleValidationError = $this->validateRuleConfig($ruleType, $ruleConfig);
        if ($ruleValidationError !== null) {
            return back()->withErrors(['policy_version' => $ruleValidationError])->withInput();
        }

        [$removeRules, $removeError] = $this->resolveRemoveRulesFromRequestData($data, $ruleType, $ruleConfig);
        if ($removeError !== null) {
            return back()->withErrors(['policy_version' => $removeError])->withInput();
        }

        $policyVersion = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policyId,
            'version_number' => $data['version_number'],
            'status' => 'active',
            'created_by' => $request->user()?->id,
            'published_at' => now(),
        ]);

        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $policyVersion->id,
            'order_index' => 0,
            'rule_type' => $ruleType,
            'rule_config' => $ruleConfig,
            'enforce' => true,
        ]);
        $this->upsertPolicyRemovalProfile($policyVersion->id, $ruleType, $ruleConfig, $request->user()?->id, $removeRules);

        if ($assignNow) {
            $this->createPolicyAssignment($policyVersion->id, (string) $data['target_type'], (string) $data['target_id']);
            $this->queueApplyPolicyJob($policyVersion, (string) $data['target_type'], (string) $data['target_id'], $request->user()?->id);
        }

        $auditLogger->log('policy.version.create.web', 'policy_version', $policyVersion->id, null, [
            ...$policyVersion->toArray(),
            'policy_name' => $policy->name,
            'assigned_on_create' => $assignNow,
        ], $request->user()?->id);

        return back()->with('status', $assignNow ? 'Policy version published, assigned, and queued.' : 'Policy version published.');
    }

    public function updatePolicyVersion(Request $request, string $policyId, string $versionId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'version_number' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:draft,active,retired'],
            'apply_mode' => ['nullable', 'in:json,command'],
            'rule_type' => ['nullable', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'rule_json' => ['nullable', 'json'],
            'apply_command' => ['nullable', 'string', 'max:15000'],
            'apply_run_as' => ['nullable', 'in:default,elevated,system'],
            'apply_timeout_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'apply_uwf_ensure' => ['nullable', 'in:present,absent'],
            'apply_uwf_enable_feature' => ['nullable', 'boolean'],
            'apply_uwf_enable_filter' => ['nullable', 'boolean'],
            'apply_uwf_protect_volume' => ['nullable', 'boolean'],
            'apply_uwf_volume' => ['nullable', 'string', 'max:16'],
            'apply_uwf_reboot_now' => ['nullable', 'boolean'],
            'apply_uwf_reboot_if_pending' => ['nullable', 'boolean'],
            'apply_uwf_max_reboot_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'apply_uwf_reboot_cooldown_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'apply_uwf_reboot_command' => ['nullable', 'string', 'max:500'],
            'apply_uwf_file_exclusions' => ['nullable', 'string', 'max:4000'],
            'apply_uwf_registry_exclusions' => ['nullable', 'string', 'max:4000'],
            'apply_uwf_fail_on_unsupported_edition' => ['nullable', 'boolean'],
            'apply_uwf_overlay_type' => ['nullable', 'in:ram,disk'],
            'apply_uwf_overlay_max_size_mb' => ['nullable', 'integer', 'min:128', 'max:1048576'],
            'apply_uwf_overlay_warning_threshold_mb' => ['nullable', 'integer', 'min:64', 'max:1048576'],
            'apply_uwf_overlay_critical_threshold_mb' => ['nullable', 'integer', 'min:64', 'max:1048576'],
            'remove_mode' => ['nullable', 'in:auto,json,command'],
            'remove_rule_type' => ['nullable', 'in:firewall,dns,network_adapter,registry,bitlocker,local_group,windows_update,scheduled_task,command,baseline_profile,reboot_restore_mode,uwf'],
            'remove_rule_json' => ['nullable', 'json'],
            'remove_command' => ['nullable', 'string', 'max:15000'],
            'enforce' => ['nullable', 'boolean'],
        ]);

        $policyVersion = PolicyVersion::query()->where('id', $versionId)->where('policy_id', $policyId)->firstOrFail();
        $exists = PolicyVersion::query()
            ->where('policy_id', $policyId)
            ->where('version_number', (int) $data['version_number'])
            ->where('id', '!=', $policyVersion->id)
            ->exists();
        if ($exists) {
            return back()->withErrors([
                'policy_version_update' => 'Another version already uses this version number.',
            ])->withInput();
        }

        $before = $policyVersion->toArray();
        $policyVersion->update([
            'version_number' => (int) $data['version_number'],
            'status' => $data['status'],
            'published_at' => $data['status'] === 'active' ? ($policyVersion->published_at ?? now()) : $policyVersion->published_at,
        ]);

        [$ruleType, $ruleConfig, $resolveError] = $this->resolveApplyRuleFromRequestData($data);
        if ($resolveError !== null) {
            return back()->withErrors(['policy_version_update' => $resolveError])->withInput();
        }

        $ruleValidationError = $this->validateRuleConfig($ruleType, $ruleConfig);
        if ($ruleValidationError !== null) {
            return back()->withErrors(['policy_version_update' => $ruleValidationError])->withInput();
        }
        [$removeRules, $removeError] = $this->resolveRemoveRulesFromRequestData($data, $ruleType, $ruleConfig);
        if ($removeError !== null) {
            return back()->withErrors(['policy_version_update' => $removeError])->withInput();
        }

        $rule = PolicyRule::query()->where('policy_version_id', $policyVersion->id)->orderBy('order_index')->first();
        if (! $rule) {
            $rule = PolicyRule::query()->create([
                'id' => (string) Str::uuid(),
                'policy_version_id' => $policyVersion->id,
                'order_index' => 0,
                'rule_type' => $ruleType,
                'rule_config' => $ruleConfig,
                'enforce' => (bool) ($data['enforce'] ?? true),
            ]);
        } else {
            $rule->update([
                'rule_type' => $ruleType,
                'rule_config' => $ruleConfig,
                'enforce' => (bool) ($data['enforce'] ?? true),
            ]);
        }
        $this->upsertPolicyRemovalProfile($policyVersion->id, $ruleType, $ruleConfig, $request->user()?->id, $removeRules);

        $auditLogger->log('policy.version.update.web', 'policy_version', $policyVersion->id, $before, [
            ...$policyVersion->toArray(),
            'rule_type' => $rule->rule_type,
            'enforce' => (bool) $rule->enforce,
        ], $request->user()?->id);

        return back()->with('status', 'Policy version updated.');
    }

    public function deletePolicyVersion(Request $request, string $policyId, string $versionId, AuditLogger $auditLogger): RedirectResponse
    {
        $policyVersion = PolicyVersion::query()->where('id', $versionId)->where('policy_id', $policyId)->firstOrFail();
        $before = $policyVersion->toArray();
        $assignments = \DB::table('policy_assignments')->where('policy_version_id', $policyVersion->id)->get();

        \DB::table('policy_assignments')->where('policy_version_id', $policyVersion->id)->delete();
        PolicyRule::query()->where('policy_version_id', $policyVersion->id)->delete();
        $policyVersion->delete();
        $this->deletePolicyRemovalProfile($policyVersion->id, $request->user()?->id);

        $queued = 0;
        foreach ($assignments as $assignment) {
            $queued += $this->queuePolicyReconcileForTarget((string) $assignment->target_type, (string) $assignment->target_id, $request->user()?->id);
        }

        $auditLogger->log('policy.version.delete.web', 'policy_version', $versionId, $before, [
            'queued_policy_reconcile_jobs' => $queued,
        ], $request->user()?->id);

        return back()->with('status', "Policy version deleted. Queued {$queued} reconcile job(s).");
    }

    public function assignPolicyVersion(Request $request, string $policyId, string $versionId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'in:device,group'],
            'target_id' => ['required', 'uuid'],
            'queue_job' => ['nullable', 'boolean'],
        ]);

        $policyVersion = PolicyVersion::query()->where('id', $versionId)->where('policy_id', $policyId)->firstOrFail();
        $created = $this->createPolicyAssignment($policyVersion->id, $data['target_type'], $data['target_id']);
        $queueJob = (bool) ($data['queue_job'] ?? true);
        if ($queueJob) {
            $this->queueApplyPolicyJob($policyVersion, $data['target_type'], $data['target_id'], $request->user()?->id);
        }

        $auditLogger->log('policy.assignment.create.web', 'policy_assignment', $policyVersion->id, null, [
            'policy_version_id' => $policyVersion->id,
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'created_assignment' => $created,
            'queued_job' => $queueJob,
        ], $request->user()?->id);

        return back()->with('status', $created ? 'Policy version assigned.' : 'Assignment already exists.');
    }

    public function deletePolicyAssignment(Request $request, string $policyId, string $versionId, string $assignmentId, AuditLogger $auditLogger): RedirectResponse
    {
        $policyVersion = PolicyVersion::query()->where('id', $versionId)->where('policy_id', $policyId)->firstOrFail();
        $assignment = \DB::table('policy_assignments')
            ->where('id', $assignmentId)
            ->where('policy_version_id', $policyVersion->id)
            ->first();
        if (! $assignment) {
            return back()->withErrors(['policy_assignment_delete' => 'Assignment not found.']);
        }

        \DB::table('policy_assignments')->where('id', $assignmentId)->delete();
        $cleanupQueued = 0;
        if ((string) $assignment->target_type === 'device') {
            $cleanupQueued = $this->queuePolicyRemovalProfileForDevice((string) $assignment->target_id, $policyVersion->id, $request->user()?->id);
        } elseif ((string) $assignment->target_type === 'group') {
            $deviceIds = \DB::table('device_group_memberships')
                ->where('device_group_id', (string) $assignment->target_id)
                ->pluck('device_id')
                ->filter()
                ->unique()
                ->values();

            foreach ($deviceIds as $deviceId) {
                $cleanupQueued += $this->queuePolicyRemovalProfileForDevice((string) $deviceId, $policyVersion->id, $request->user()?->id);
            }
        }
        $queued = $this->queuePolicyReconcileForTarget((string) $assignment->target_type, (string) $assignment->target_id, $request->user()?->id);

        $auditLogger->log('policy.assignment.delete.web', 'policy_assignment', $assignmentId, (array) $assignment, [
            'queued_policy_remove_jobs' => $cleanupQueued,
            'queued_policy_reconcile_jobs' => $queued,
        ], $request->user()?->id);

        $status = "Policy assignment removed. Queued {$queued} reconcile job(s).";
        if ($cleanupQueued > 0) {
            $status .= " Queued {$cleanupQueued} remove policy job(s).";
        }

        return back()->with('status', $status);
    }

    private function queuePolicyReconcileForTarget(string $targetType, string $targetId, ?int $createdBy): int
    {
        if ($targetType === 'group') {
            $policyVersionIds = \DB::table('policy_assignments')
                ->where('target_type', 'group')
                ->where('target_id', $targetId)
                ->pluck('policy_version_id')
                ->unique()
                ->values();
            $versions = PolicyVersion::query()->whereIn('id', $policyVersionIds)->get();
            foreach ($versions as $version) {
                $this->queueApplyPolicyJob($version, 'group', $targetId, $createdBy);
            }
            return $versions->count();
        }

        if ($targetType === 'device') {
            return $this->queuePolicyReconcileForDevice($targetId, $createdBy);
        }

        return 0;
    }

    private function queuePolicyReconcileForDevice(string $deviceId, ?int $createdBy): int
    {
        $groupIds = \DB::table('device_group_memberships')
            ->where('device_id', $deviceId)
            ->pluck('device_group_id')
            ->values();

        $policyVersionIds = \DB::table('policy_assignments')
            ->where(function ($query) use ($deviceId, $groupIds) {
                $query->where(function ($q) use ($deviceId) {
                    $q->where('target_type', 'device')->where('target_id', $deviceId);
                });
                if ($groupIds->isNotEmpty()) {
                    $query->orWhere(function ($q) use ($groupIds) {
                        $q->where('target_type', 'group')->whereIn('target_id', $groupIds);
                    });
                }
            })
            ->pluck('policy_version_id')
            ->unique()
            ->values();

        $versions = PolicyVersion::query()->whereIn('id', $policyVersionIds)->get();
        foreach ($versions as $version) {
            $this->queueApplyPolicyJob($version, 'device', $deviceId, $createdBy);
        }

        return $versions->count();
    }

    private function queuePolicyRemovalProfileForDevice(string $deviceId, string $removedPolicyVersionId, ?int $createdBy): int
    {
        $cleanupRules = $this->buildPolicyRemovalRulesForDevice($deviceId, $removedPolicyVersionId);
        if ($cleanupRules === []) {
            return 0;
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'apply_policy',
            'status' => 'queued',
            'priority' => 100,
            'payload' => [
                'policy_version_id' => $removedPolicyVersionId,
                'cleanup' => true,
                'rules' => $cleanupRules,
            ],
            'target_type' => 'device',
            'target_id' => $deviceId,
            'created_by' => $createdBy,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'device_id' => $deviceId,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        return 1;
    }

    private function buildPolicyRemovalRulesForDevice(string $deviceId, string $removedPolicyVersionId): array
    {
        $configuredRemoveRules = $this->policyRemovalRulesForVersion($removedPolicyVersionId);
        $derivedRemoveRules = $this->deriveRemovalRulesFromPolicyVersion($removedPolicyVersionId);
        if ($configuredRemoveRules === []) {
            $configuredRemoveRules = $derivedRemoveRules;
        } elseif ($derivedRemoveRules !== []) {
            $configuredRemoveRules = collect($configuredRemoveRules)
                ->merge($derivedRemoveRules)
                ->filter(fn ($rule) => is_array($rule))
                ->values()
                ->all();
        }
        if ($configuredRemoveRules === []) {
            return [];
        }

        $groupIds = \DB::table('device_group_memberships')
            ->where('device_id', $deviceId)
            ->pluck('device_group_id')
            ->values();

        $activeVersionIds = \DB::table('policy_assignments')
            ->where(function ($query) use ($deviceId, $groupIds) {
                $query->where(function ($q) use ($deviceId) {
                    $q->where('target_type', 'device')->where('target_id', $deviceId);
                });
                if ($groupIds->isNotEmpty()) {
                    $query->orWhere(function ($q) use ($groupIds) {
                        $q->where('target_type', 'group')->whereIn('target_id', $groupIds);
                    });
                }
            })
            ->pluck('policy_version_id')
            ->filter()
            ->unique()
            ->values();

        $activeRegistrySignatures = [];
        $activeScheduledTaskSignatures = [];
        $activeCommandSignatures = [];
        $activeLocalGroupSignatures = [];
        $activeDnsSignatures = [];
        $activeNetworkAdapterSignatures = [];
        $activeUwfSignatures = [];
        if ($activeVersionIds->isNotEmpty()) {
            $activeRules = PolicyRule::query()
                ->whereIn('policy_version_id', $activeVersionIds)
                ->whereIn('rule_type', ['registry', 'scheduled_task', 'command', 'local_group', 'dns', 'network_adapter', 'uwf'])
                ->get(['rule_type', 'rule_config']);

            foreach ($activeRules as $rule) {
                $config = is_array($rule->rule_config) ? $rule->rule_config : [];
                $ruleType = (string) $rule->rule_type;
                if ($ruleType === 'registry') {
                    $signature = $this->registryRuleSignature($config);
                    if ($signature !== null) {
                        $activeRegistrySignatures[$signature] = true;
                    }
                    continue;
                }

                if ($ruleType === 'scheduled_task') {
                    $signature = $this->scheduledTaskRuleSignature($config);
                    if ($signature !== null && ! $this->isScheduledTaskMarkedAbsent($config)) {
                        $activeScheduledTaskSignatures[$signature] = true;
                    }
                    continue;
                }

                if ($ruleType === 'command') {
                    $signature = $this->commandRuleSignature($config);
                    if ($signature !== null) {
                        $activeCommandSignatures[$signature] = true;
                    }
                    continue;
                }

                if ($ruleType === 'local_group') {
                    $signature = $this->localGroupRuleSignature($config);
                    if ($signature !== null && ! $this->isLocalGroupMarkedAbsent($config)) {
                        $activeLocalGroupSignatures[$signature] = true;
                    }
                    continue;
                }

                if ($ruleType === 'dns') {
                    $signature = $this->networkSelectorSignature($config);
                    if ($signature !== null) {
                        $activeDnsSignatures[$signature] = true;
                    }
                    continue;
                }

                if ($ruleType === 'network_adapter') {
                    $signature = $this->networkSelectorSignature($config);
                    if ($signature !== null) {
                        $activeNetworkAdapterSignatures[$signature] = true;
                    }
                    continue;
                }

                if ($ruleType === 'uwf') {
                    $signature = $this->uwfRuleSignature($config);
                    if ($signature !== null && ! $this->isUwfMarkedAbsent($config)) {
                        $activeUwfSignatures[$signature] = true;
                    }
                }
            }
        }

        $cleanupRules = [];
        $queuedRegistrySignatures = [];
        $queuedScheduledTaskSignatures = [];
        $queuedCommandSignatures = [];
        $queuedLocalGroupSignatures = [];
        $queuedDnsSignatures = [];
        $queuedNetworkAdapterSignatures = [];
        $queuedUwfSignatures = [];
        foreach ($configuredRemoveRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $type = strtolower((string) ($rule['type'] ?? ''));
            $config = is_array($rule['config'] ?? null) ? $rule['config'] : [];

            if ($type === 'uwf') {
                $signature = $this->uwfRuleSignature($config);
                if ($signature !== null && (isset($activeUwfSignatures[$signature]) || isset($queuedUwfSignatures[$signature]))) {
                    continue;
                }
                $cleanupCommandRule = $this->buildUwfRemovalCommandRule();
                $cleanupCommandSignature = $this->commandRuleSignature((array) ($cleanupCommandRule['config'] ?? []));
                if ($cleanupCommandSignature !== null
                    && (isset($activeCommandSignatures[$cleanupCommandSignature]) || isset($queuedCommandSignatures[$cleanupCommandSignature]))) {
                    continue;
                }
                $cleanupRules[] = $cleanupCommandRule;
                if ($signature !== null) {
                    $queuedUwfSignatures[$signature] = true;
                }
                if ($cleanupCommandSignature !== null) {
                    $queuedCommandSignatures[$cleanupCommandSignature] = true;
                }
                continue;
            }

            if ($type === 'local_group') {
                $signature = $this->localGroupRuleSignature($config);
                if ($signature === null) {
                    continue;
                }
                if (isset($activeLocalGroupSignatures[$signature]) || isset($queuedLocalGroupSignatures[$signature])) {
                    continue;
                }
                $cleanupRules[] = [
                    'type' => 'local_group',
                    'config' => $config + ['ensure' => 'absent', 'restore_previous' => true, 'strict_restore' => true],
                    'enforce' => true,
                ];
                $queuedLocalGroupSignatures[$signature] = true;
                continue;
            }

            if ($type === 'dns') {
                $signature = $this->networkSelectorSignature($config);
                if ($signature === null) {
                    continue;
                }
                if (isset($activeDnsSignatures[$signature]) || isset($queuedDnsSignatures[$signature])) {
                    continue;
                }
                $cleanupRules[] = [
                    'type' => 'dns',
                    'config' => $config + ['mode' => 'automatic'],
                    'enforce' => true,
                ];
                $queuedDnsSignatures[$signature] = true;
                continue;
            }

            if ($type === 'network_adapter') {
                $signature = $this->networkSelectorSignature($config);
                if ($signature === null) {
                    continue;
                }
                if (isset($activeNetworkAdapterSignatures[$signature]) || isset($queuedNetworkAdapterSignatures[$signature])) {
                    continue;
                }
                $cleanupRules[] = [
                    'type' => 'network_adapter',
                    'config' => $config + ['ipv4_mode' => 'dhcp'],
                    'enforce' => true,
                ];
                $queuedNetworkAdapterSignatures[$signature] = true;
                continue;
            }

            if ($type === 'registry') {
                $signature = $this->registryRuleSignature($config);
                if ($signature === null) {
                    continue;
                }
                if (isset($activeRegistrySignatures[$signature]) || isset($queuedRegistrySignatures[$signature])) {
                    continue;
                }
                $cleanupRules[] = [
                    'type' => 'registry',
                    'config' => $config + ['ensure' => 'absent'],
                    'enforce' => true,
                ];
                $queuedRegistrySignatures[$signature] = true;
                continue;
            }

            if ($type !== 'scheduled_task') {
                if ($type !== 'command') {
                    continue;
                }
                $signature = $this->commandRuleSignature($config);
                if ($signature === null) {
                    continue;
                }
                if (isset($activeCommandSignatures[$signature]) || isset($queuedCommandSignatures[$signature])) {
                    continue;
                }
                $cleanupRules[] = [
                    'type' => 'command',
                    'config' => $config,
                    'enforce' => true,
                ];
                $queuedCommandSignatures[$signature] = true;
                continue;
            }

            $signature = $this->scheduledTaskRuleSignature($config);
            if ($signature === null) {
                continue;
            }
            if (isset($activeScheduledTaskSignatures[$signature]) || isset($queuedScheduledTaskSignatures[$signature])) {
                continue;
            }
            $cleanupRules[] = [
                'type' => 'scheduled_task',
                'config' => $config + ['ensure' => 'absent'],
                'enforce' => true,
            ];
            $queuedScheduledTaskSignatures[$signature] = true;
        }

        return $cleanupRules;
    }

    private function deriveRemovalRulesFromPolicyVersion(string $policyVersionId): array
    {
        $rules = PolicyRule::query()
            ->where('policy_version_id', $policyVersionId)
            ->whereIn('rule_type', ['registry', 'scheduled_task', 'command', 'local_group', 'dns', 'network_adapter', 'uwf'])
            ->get(['rule_type', 'rule_config']);

        $removeRules = [];
        foreach ($rules as $rule) {
            $ruleType = (string) $rule->rule_type;
            $config = is_array($rule->rule_config) ? $rule->rule_config : [];
            $removeRules = array_merge($removeRules, $this->buildRemovalRulesForPolicyRule($ruleType, $config));
        }

        return array_values(array_filter($removeRules, fn ($rule) => is_array($rule)));
    }

    private function registryRuleSignature(array $config): ?string
    {
        $path = strtoupper(trim((string) ($config['path'] ?? '')));
        $name = strtoupper(trim((string) ($config['name'] ?? '')));
        if ($path === '' || $name === '') {
            return null;
        }

        return $path.'|'.$name;
    }

    private function scheduledTaskRuleSignature(array $config): ?string
    {
        $taskName = strtoupper(trim((string) ($config['task_name'] ?? '')));
        if ($taskName === '') {
            return null;
        }

        return $taskName;
    }

    private function isScheduledTaskMarkedAbsent(array $config): bool
    {
        return strtolower(trim((string) ($config['ensure'] ?? 'present'))) === 'absent';
    }

    private function commandRuleSignature(array $config): ?string
    {
        $command = trim((string) ($config['command'] ?? ''));
        if ($command === '') {
            return null;
        }

        return hash('sha256', $command);
    }

    private function networkSelectorSignature(array $config): ?string
    {
        $alias = strtoupper(trim((string) ($config['interface_alias'] ?? '')));
        if ($alias !== '') {
            return 'ALIAS:'.$alias;
        }

        if (array_key_exists('interface_index', $config) && is_numeric($config['interface_index'])) {
            return 'INDEX:'.(int) $config['interface_index'];
        }

        $description = strtoupper(trim((string) ($config['interface_description'] ?? '')));
        if ($description !== '') {
            return 'DESCRIPTION:'.$description;
        }

        return null;
    }

    private function copyNetworkSelectorConfig(array $config): array
    {
        $selector = [];
        $alias = trim((string) ($config['interface_alias'] ?? ''));
        if ($alias !== '') {
            $selector['interface_alias'] = $alias;
        }

        if (array_key_exists('interface_index', $config) && is_numeric($config['interface_index'])) {
            $selector['interface_index'] = (int) $config['interface_index'];
        }

        $description = trim((string) ($config['interface_description'] ?? ''));
        if ($description !== '') {
            $selector['interface_description'] = $description;
        }

        return $selector;
    }

    private function localGroupRuleSignature(array $config): ?string
    {
        $group = strtoupper(trim((string) ($config['group'] ?? '')));
        if ($group === '') {
            return null;
        }

        return $group;
    }

    private function uwfRuleSignature(array $config): ?string
    {
        $volume = strtoupper(trim((string) ($config['volume'] ?? 'C:')));
        if ($volume === '') {
            $volume = 'C:';
        }

        return $volume;
    }

    private function isUwfMarkedAbsent(array $config): bool
    {
        return strtolower(trim((string) ($config['ensure'] ?? 'present'))) === 'absent';
    }

    private function buildUwfRemovalCommandRule(): array
    {
        return [
            'type' => 'command',
            'config' => [
                'command' => 'powershell.exe -NoProfile -ExecutionPolicy Bypass -EncodedCommand JABFAHIAcgBvAHIAQQBjAHQAaQBvAG4AUAByAGUAZgBlAHIAZQBuAGMAZQAgAD0AIAAnAFMAaQBsAGUAbgB0AGwAeQBDAG8AbgB0AGkAbgB1AGUAJwAKACQAcgBlAHMAdABvAHIAZQBSAG8AbwB0ACAAPQAgACcAQwA6AFwAUAByAG8AZwByAGEAbQBEAGEAdABhAFwARABNAFMAXABSAGUAcwB0AG8AcgBlACcACgAkAGYAcgBlAGUAegBlAFIAbwBvAHQAIAA9ACAAJwBDADoAXABQAHIAbwBnAHIAYQBtAEQAYQB0AGEAXABEAE0AUwBcAEYAcgBlAGUAegBlACcACgAkAHQAYQBzAGsATgBhAG0AZQAgAD0AIAAnAEQATQBTAC0ARgByAGUAZQB6AGUALQBGAGkAbgBhAGwAaQB6AGUAJwAKACQAcgB1AG4ATwBuAGMAZQBOAGEAbQBlACAAPQAgACcARABNAFMARgByAGUAZQB6AGUARgBpAG4AYQBsAGkAegBlACcACgAkAGEAdAB0AGUAbQBwAHQAUABhAHQAaAAgAD0AIABKAG8AaQBuAC0AUABhAHQAaAAgACQAZgByAGUAZQB6AGUAUgBvAG8AdAAgACcAYQB0AHQAZQBtAHAAdAAuAHQAeAB0ACcACgAKAFIAZQBtAG8AdgBlAC0ASQB0AGUAbQAgACgASgBvAGkAbgAtAFAAYQB0AGgAIAAkAHIAZQBzAHQAbwByAGUAUgBvAG8AdAAgACcAcABlAHIAcwBpAHMAdABlAG4AdAAtAHIAZQBzAHQAbwByAGUALgBqAHMAbwBuACcAKQAgAC0ARgBvAHIAYwBlACAALQBFAHIAcgBvAHIAQQBjAHQAaQBvAG4AIABTAGkAbABlAG4AdABsAHkAQwBvAG4AdABpAG4AdQBlAAoAUgBlAG0AbwB2AGUALQBJAHQAZQBtACAAKABKAG8AaQBuAC0AUABhAHQAaAAgACQAcgBlAHMAdABvAHIAZQBSAG8AbwB0ACAAJwBwAGUAbgBkAGkAbgBnAC0AcgBlAHMAdABvAHIAZQAuAGoAcwBvAG4AJwApACAALQBGAG8AcgBjAGUAIAAtAEUAcgByAG8AcgBBAGMAdABpAG8AbgAgAFMAaQBsAGUAbgB0AGwAeQBDAG8AbgB0AGkAbgB1AGUACgAKAHMAYwBoAHQAYQBzAGsAcwAuAGUAeABlACAALwBEAGUAbABlAHQAZQAgAC8AVABOACAAJAB0AGEAcwBrAE4AYQBtAGUAIAAvAEYAIAB8ACAATwB1AHQALQBOAHUAbABsAAoAUgBlAG0AbwB2AGUALQBJAHQAZQBtAFAAcgBvAHAAZQByAHQAeQAgAC0AUABhAHQAaAAgACcASABLAEwATQA6AFwAUwBvAGYAdAB3AGEAcgBlAFwATQBpAGMAcgBvAHMAbwBmAHQAXABXAGkAbgBkAG8AdwBzAFwAQwB1AHIAcgBlAG4AdABWAGUAcgBzAGkAbwBuAFwAUgB1AG4ATwBuAGMAZQAnACAALQBOAGEAbQBlACAAJAByAHUAbgBPAG4AYwBlAE4AYQBtAGUAIAAtAEUAcgByAG8AcgBBAGMAdABpAG8AbgAgAFMAaQBsAGUAbgB0AGwAeQBDAG8AbgB0AGkAbgB1AGUACgBSAGUAbQBvAHYAZQAtAEkAdABlAG0AIAAkAGEAdAB0AGUAbQBwAHQAUABhAHQAaAAgAC0ARgBvAHIAYwBlACAALQBFAHIAcgBvAHIAQQBjAHQAaQBvAG4AIABTAGkAbABlAG4AdABsAHkAQwBvAG4AdABpAG4AdQBlAAoACgAkAHUAdwBmACAAPQAgAEoAbwBpAG4ALQBQAGEAdABoACAAJABlAG4AdgA6AFcASQBOAEQASQBSACAAJwBTAHkAcwB0AGUAbQAzADIAXAB1AHcAZgBtAGcAcgAuAGUAeABlACcACgBpAGYAIAAoAFQAZQBzAHQALQBQAGEAdABoACAAJAB1AHcAZgApACAAewAKACAAIAAmACAAJAB1AHcAZgAgAGYAaQBsAHQAZQByACAAZABpAHMAYQBiAGwAZQAgAHwAIABPAHUAdAAtAE4AdQBsAGwACgAgACAAJgAgACQAdQB3AGYAIAB2AG8AbAB1AG0AZQAgAHUAbgBwAHIAbwB0AGUAYwB0ACAAQwA6ACAAfAAgAE8AdQB0AC0ATgB1AGwAbAAKAH0ACgAKAGkAZgAgACgAVABlAHMAdAAtAFAAYQB0AGgAIAAkAGYAcgBlAGUAegBlAFIAbwBvAHQAKQAgAHsACgAgACAAJABzAHQAYQB0AGUAUABhAHQAaAAgAD0AIABKAG8AaQBuAC0AUABhAHQAaAAgACQAZgByAGUAZQB6AGUAUgBvAG8AdAAgACcAcwB0AGEAdABlAC4AagBzAG8AbgAnAAoAIAAgACQAbwBiAGoAIAA9ACAAQAB7AAoAIAAgACAAIABzAHQAYQB0AGUAIAA9ACAAJwB1AG4AZgByAG8AegBlAG4AJwAKACAAIAAgACAAbQBlAHMAcwBhAGcAZQAgAD0AIAAnAEYAcgBlAGUAegBlACAAbQBvAGQAZQAgAGQAaQBzAGEAYgBsAGUAZAAgAGIAeQAgAEQATQBTACAAdQBuAGYAcgBlAGUAegBlACAAYQBjAHQAaQBvAG4ALgAnAAoAIAAgACAAIAB1AHQAYwAgAD0AIAAoAEcAZQB0AC0ARABhAHQAZQApAC4AVABvAFUAbgBpAHYAZQByAHMAYQBsAFQAaQBtAGUAKAApAC4AVABvAFMAdAByAGkAbgBnACgAJwBvACcAKQAKACAAIAB9ACAAfAAgAEMAbwBuAHYAZQByAHQAVABvAC0ASgBzAG8AbgAgAC0ARABlAHAAdABoACAANAAKACAAIABTAGUAdAAtAEMAbwBuAHQAZQBuAHQAIAAtAFAAYQB0AGgAIAAkAHMAdABhAHQAZQBQAGEAdABoACAALQBWAGEAbAB1AGUAIAAkAG8AYgBqACAALQBFAG4AYwBvAGQAaQBuAGcAIABVAFQARgA4ACAALQBGAG8AcgBjAGUACgB9AAoACgBzAGgAdQB0AGQAbwB3AG4ALgBlAHgAZQAgAC8AcgAgAC8AdAAgADAACgBlAHgAaQB0ACAAMAA=',
                'run_as' => 'system',
                'timeout_seconds' => 900,
            ],
            'enforce' => true,
        ];
    }

    private function isLocalGroupMarkedAbsent(array $config): bool
    {
        return strtolower(trim((string) ($config['ensure'] ?? 'present'))) === 'absent';
    }

    private function queueGroupPackageUninstallsForDevice(string $groupId, string $deviceId, ?int $createdBy): int
    {
        $groupInstallJobs = DmsJob::query()
            ->where('target_type', 'group')
            ->where('target_id', $groupId)
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive'])
            ->get(['payload']);

        $versionIds = $groupInstallJobs
            ->map(function (DmsJob $job) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return (string) ($payload['package_version_id'] ?? '');
            })
            ->filter()
            ->unique()
            ->values();

        if ($versionIds->isEmpty()) {
            return 0;
        }

        $versions = PackageVersion::query()->whereIn('id', $versionIds)->get()->keyBy('id');
        $packages = PackageModel::query()->whereIn('id', $versions->pluck('package_id')->unique()->values())->get()->keyBy('id');

        $count = 0;
        foreach ($versionIds as $versionId) {
            $version = $versions->get($versionId);
            if (! $version) {
                continue;
            }
            $package = $packages->get($version->package_id);
            if (! $package) {
                continue;
            }
            $queued = $this->queueUninstallForDeviceAndVersion($deviceId, $version, $package, $createdBy, 100);
            if ((bool) ($queued['queued'] ?? false)) {
                $count++;
            }
        }

        return $count;
    }

    private function queueDeleteCleanupForDevice(string $deviceId, ?int $createdBy): array
    {
        $policyVersionIds = collect();
        $policyVersionIds = $policyVersionIds->merge(
            \DB::table('policy_assignments')
                ->where('target_type', 'device')
                ->where('target_id', $deviceId)
                ->pluck('policy_version_id')
        );

        $groupIds = \DB::table('device_group_memberships')
            ->where('device_id', $deviceId)
            ->pluck('device_group_id');
        if ($groupIds->isNotEmpty()) {
            $policyVersionIds = $policyVersionIds->merge(
                \DB::table('policy_assignments')
                    ->where('target_type', 'group')
                    ->whereIn('target_id', $groupIds)
                    ->pluck('policy_version_id')
            );
        }

        $policyRemoveCount = 0;
        foreach ($policyVersionIds->filter()->unique()->values() as $policyVersionId) {
            $policyRemoveCount += $this->queuePolicyRemovalProfileForDevice($deviceId, (string) $policyVersionId, $createdBy);
        }

        $packageUninstallCount = 0;
        $installedVersionIds = $this->installedPackageVersionIdsForDevice($deviceId);
        if ($installedVersionIds !== []) {
            $versions = PackageVersion::query()->whereIn('id', $installedVersionIds)->get()->keyBy('id');
            $packages = PackageModel::query()
                ->whereIn('id', $versions->pluck('package_id')->unique()->values())
                ->get()
                ->keyBy('id');
            foreach ($installedVersionIds as $versionId) {
                $version = $versions->get($versionId);
                if (! $version) {
                    continue;
                }
                $package = $packages->get($version->package_id);
                if (! $package) {
                    continue;
                }
                $queued = $this->queueUninstallForDeviceAndVersion($deviceId, $version, $package, $createdBy, 90);
                if ((bool) ($queued['queued'] ?? false)) {
                    $packageUninstallCount++;
                }
            }
        }

        return [
            'policy_remove_jobs' => $policyRemoveCount,
            'package_uninstall_jobs' => $packageUninstallCount,
        ];
    }

    private function purgeDeviceRecordForAdmin(string $deviceId): array
    {
        $device = Device::query()->find($deviceId);
        if (! $device) {
            return [
                'memberships' => 0,
                'identities' => 0,
                'policy_assignments' => 0,
                'compliance_results' => 0,
                'job_events' => 0,
                'job_runs' => 0,
                'enrollment_tokens' => 0,
                'devices' => 0,
            ];
        }

        return \DB::transaction(function () use ($device) {
            $runIds = JobRun::query()
                ->where('device_id', $device->id)
                ->pluck('id')
                ->values();

            $deletedEvents = 0;
            if ($runIds->isNotEmpty()) {
                $deletedEvents = (int) JobEvent::query()->whereIn('job_run_id', $runIds)->delete();
            }

            return [
                'memberships' => (int) \DB::table('device_group_memberships')->where('device_id', $device->id)->delete(),
                'identities' => (int) \DB::table('device_identities')->where('device_id', $device->id)->delete(),
                'policy_assignments' => (int) \DB::table('policy_assignments')->where('target_type', 'device')->where('target_id', $device->id)->delete(),
                'compliance_results' => (int) ComplianceResult::query()->where('device_id', $device->id)->delete(),
                'job_events' => $deletedEvents,
                'job_runs' => (int) JobRun::query()->where('device_id', $device->id)->delete(),
                'enrollment_tokens' => (int) EnrollmentToken::query()->where('used_by_device_id', $device->id)->update(['used_by_device_id' => null]),
                'devices' => (int) Device::query()->where('id', $device->id)->delete(),
            ];
        });
    }

    private function queueUninstallForInstalledDevices(array $versions, ?int $createdBy): int
    {
        $count = 0;
        foreach ($versions as $version) {
            if (! $version instanceof PackageVersion) {
                continue;
            }
            $package = PackageModel::query()->find($version->package_id);
            if (! $package) {
                continue;
            }
            $deviceIds = $this->installedDeviceIdsForPackageVersion($version->id);
            foreach ($deviceIds as $deviceId) {
                $queued = $this->queueUninstallForDeviceAndVersion($deviceId, $version, $package, $createdBy, 100);
                if ((bool) ($queued['queued'] ?? false)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function installedDeviceIdsForPackageVersion(string $packageVersionId): array
    {
        $jobs = DmsJob::query()
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive', 'uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'])
            ->get(['id', 'payload']);

        $matchingJobs = $jobs
            ->filter(function (DmsJob $job) use ($packageVersionId) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return (string) ($payload['package_version_id'] ?? '') === $packageVersionId;
            });
        $jobIds = $matchingJobs->pluck('id')->values();

        if ($jobIds->isEmpty()) {
            return [];
        }

        $jobTypeById = $matchingJobs
            ->mapWithKeys(fn (DmsJob $job) => [(string) $job->id => (string) $job->job_type]);

        $runs = JobRun::query()
            ->whereIn('job_id', $jobIds)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get(['job_id', 'device_id', 'status']);

        $installedByDevice = [];
        foreach ($runs as $run) {
            $deviceId = (string) ($run->device_id ?? '');
            if ($deviceId === '' || array_key_exists($deviceId, $installedByDevice)) {
                continue;
            }

            $jobType = (string) ($jobTypeById[(string) ($run->job_id ?? '')] ?? '');
            $status = strtolower((string) ($run->status ?? ''));
            $isInstall = in_array($jobType, ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive'], true);
            $isUninstall = in_array($jobType, ['uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'], true);

            if ($isUninstall && $status === 'success') {
                $installedByDevice[$deviceId] = false;
                continue;
            }
            if ($isInstall && $status === 'success') {
                $installedByDevice[$deviceId] = true;
                continue;
            }
        }

        return collect($installedByDevice)
            ->filter(fn ($installed) => $installed === true)
            ->keys()
            ->values()
            ->all();
    }

    private function installedPackageVersionIdsForDevice(string $deviceId): array
    {
        $jobs = DmsJob::query()
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive', 'uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'])
            ->get(['id', 'job_type', 'payload']);

        if ($jobs->isEmpty()) {
            return [];
        }

        $jobById = $jobs->mapWithKeys(function (DmsJob $job) {
            $payload = is_array($job->payload) ? $job->payload : [];
            return [(string) $job->id => [
                'job_type' => (string) $job->job_type,
                'package_version_id' => (string) ($payload['package_version_id'] ?? ''),
            ]];
        });

        $runs = JobRun::query()
            ->where('device_id', $deviceId)
            ->whereIn('job_id', $jobById->keys()->values())
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get(['job_id', 'status']);

        $installedByVersion = [];
        foreach ($runs as $run) {
            $meta = $jobById[(string) ($run->job_id ?? '')] ?? null;
            if (! is_array($meta)) {
                continue;
            }

            $versionId = (string) ($meta['package_version_id'] ?? '');
            if ($versionId === '' || array_key_exists($versionId, $installedByVersion)) {
                continue;
            }

            $jobType = (string) ($meta['job_type'] ?? '');
            $status = strtolower((string) ($run->status ?? ''));
            $isInstall = in_array($jobType, ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive'], true);
            $isUninstall = in_array($jobType, ['uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'], true);

            if ($isUninstall && $status === 'success') {
                $installedByVersion[$versionId] = false;
                continue;
            }
            if ($isInstall && $status === 'success') {
                $installedByVersion[$versionId] = true;
                continue;
            }
        }

        return collect($installedByVersion)
            ->filter(fn ($installed) => $installed === true)
            ->keys()
            ->values()
            ->all();
    }

    private function queueUninstallForDeviceAndVersion(string $deviceId, PackageVersion $version, PackageModel $package, ?int $createdBy, int $priority = 100): array
    {
        $installArgs = is_array($version->install_args) ? $version->install_args : [];
        $uninstallArgs = is_array($version->uninstall_args) ? $version->uninstall_args : [];
        $detection = is_array($version->detection_rules) ? $version->detection_rules : [];

        $jobType = '';
        $payload = [
            'package_id' => $package->id,
            'package_version_id' => $version->id,
        ];

        if ($package->package_type === 'winget') {
            $wingetId = (string) ($uninstallArgs['winget_id'] ?? ($installArgs['winget_id'] ?? $package->slug));
            if ($wingetId === '') {
                return ['queued' => false, 'error' => 'Uninstall failed: winget_id is missing in uninstall args and package slug is empty.'];
            }
            $jobType = 'uninstall_package';
            $payload['winget_id'] = $wingetId;
        } elseif ($package->package_type === 'msi') {
            $productCode = (string) ($uninstallArgs['product_code'] ?? (($detection['type'] ?? '') === 'product_code' ? ($detection['value'] ?? '') : ''));
            if ($productCode === '') {
                return ['queued' => false, 'error' => 'MSI uninstall requires uninstall_args_json {"product_code":"{GUID}"} or detection type product_code.'];
            }
            $jobType = 'uninstall_msi';
            $payload['product_code'] = $productCode;
            $payload['msi_args'] = (string) ($uninstallArgs['msi_args'] ?? '/qn /norestart');
        } elseif ($package->package_type === 'archive_bundle') {
            $removePath = trim((string) (
                $uninstallArgs['remove_path']
                ?? $uninstallArgs['remove_dir']
                ?? $uninstallArgs['target_path']
                ?? $installArgs['extract_to']
                ?? $installArgs['target_dir']
                ?? $installArgs['install_dir']
                ?? ''
            ));
            $command = trim((string) (
                $uninstallArgs['command']
                ?? $uninstallArgs['uninstall_command']
                ?? $uninstallArgs['cmd']
                ?? $uninstallArgs['script']
                ?? $installArgs['uninstall_command']
                ?? $installArgs['command_uninstall']
                ?? ''
            ));
            if ($removePath === '' && $command === '') {
                return ['queued' => false, 'error' => 'Archive uninstall requires remove_path and/or uninstall command.'];
            }
            $jobType = 'uninstall_archive';
            if ($removePath !== '') {
                $payload['remove_path'] = $removePath;
            }
            if ($command !== '') {
                $payload['command'] = $command;
            }
        } else {
            $command = trim((string) (
                $uninstallArgs['command']
                ?? $uninstallArgs['uninstall_command']
                ?? $uninstallArgs['cmd']
                ?? $uninstallArgs['script']
                ?? $installArgs['uninstall_command']
                ?? $installArgs['command_uninstall']
                ?? ''
            ));
            if ($command === '') {
                $command = $this->buildInferredExeUninstallCommand($package, $version);
            }
            if ($command === '') {
                return ['queued' => false, 'error' => 'EXE/custom uninstall requires an uninstall command. Supported keys: uninstall_args.command, uninstall_args.uninstall_command, uninstall_args.cmd, uninstall_args.script, install_args.uninstall_command, install_args.command_uninstall.'];
            }
            $jobType = 'uninstall_exe';
            $payload['command'] = $command;
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => $jobType,
            'status' => 'queued',
            'priority' => $priority,
            'payload' => $payload,
            'target_type' => 'device',
            'target_id' => $deviceId,
            'created_by' => $createdBy,
        ]);

        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'device_id' => $deviceId,
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        return [
            'queued' => true,
            'job_id' => $job->id,
            'job_type' => $jobType,
        ];
    }

    private function buildInferredExeUninstallCommand(PackageModel $package, PackageVersion $version): string
    {
        $packageName = trim((string) ($package->name ?? ''));
        if ($packageName === '') {
            return '';
        }

        $escapedName = str_replace("'", "''", $packageName);
        $fallback = <<<'POWERSHELL'
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $name='%s'; $roots=@('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*','HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'); $app=Get-ItemProperty $roots -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -like ('*'+$name+'*') -and -not [string]::IsNullOrWhiteSpace($_.UninstallString) } | Select-Object -First 1; if(-not $app){ throw 'UninstallString not found for package'; }; $cmd=$app.UninstallString; if($cmd -match '(?i)msiexec(\.exe)?\s'){ if($cmd -notmatch '(?i)\s(/x|/uninstall)\s'){ $cmd='msiexec.exe /x ' + ($app.PSChildName) + ' /qn /norestart'; }; if($cmd -notmatch '(?i)\s/qn\b'){ $cmd += ' /qn'; }; if($cmd -notmatch '(?i)\s/norestart\b'){ $cmd += ' /norestart'; }; }; Start-Process -FilePath 'cmd.exe' -ArgumentList ('/c ' + $cmd) -Wait -NoNewWindow; if($LASTEXITCODE -ne 0 -and $LASTEXITCODE -ne 3010){ exit $LASTEXITCODE }"
POWERSHELL;

        return sprintf($fallback, $escapedName);
    }

    private function queueApplyPolicyJob(PolicyVersion $policyVersion, string $targetType, string $targetId, ?int $createdBy): void
    {
        $rules = PolicyRule::query()
            ->where('policy_version_id', $policyVersion->id)
            ->orderBy('order_index')
            ->get()
            ->map(fn (PolicyRule $rule) => $this->normalizePolicyRuleForDispatch($rule))
            ->values()
            ->all();

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'apply_policy',
            'status' => 'queued',
            'priority' => 100,
            'payload' => [
                'policy_version_id' => $policyVersion->id,
                'rules' => $rules,
            ],
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_by' => $createdBy,
        ]);

        if ($job->target_type === 'device') {
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $job->target_id,
                'status' => 'pending',
            ]);
        } else {
            $deviceIds = \DB::table('device_group_memberships')->where('device_group_id', $job->target_id)->pluck('device_id');
            foreach ($deviceIds as $deviceId) {
                JobRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'device_id' => $deviceId,
                    'status' => 'pending',
                ]);
            }
        }
    }

    private function normalizePolicyRuleForDispatch(PolicyRule $rule): array
    {
        $type = (string) $rule->rule_type;
        $config = is_array($rule->rule_config) ? $rule->rule_config : [];

        if (strtolower($type) === 'reboot_restore_mode') {
            $config = $this->normalizeRebootRestoreModeConfigForDispatch($config);
        }

        return [
            'type' => $type,
            'config' => $config,
            'enforce' => (bool) $rule->enforce,
        ];
    }

    private function normalizeRebootRestoreModeConfigForDispatch(array $config): array
    {
        if ($this->hasActionableRebootRestoreConfig($config)) {
            return $config;
        }

        $profile = strtolower(trim((string) ($config['profile'] ?? '')));
        if (! in_array($profile, ['lab_fast', 'deepfreeze_fast', 'school_fast'], true)) {
            return $config;
        }

        $compatManifest = $this->buildRebootRestoreCompatManifestFromProfile($config);
        if ($compatManifest === []) {
            return $config;
        }

        if ((array_key_exists('cleanup_paths', $config) && ! is_array($config['cleanup_paths'])) || ! array_key_exists('cleanup_paths', $config) || $config['cleanup_paths'] === []) {
            if (array_key_exists('cleanup_paths', $compatManifest)) {
                $config['cleanup_paths'] = $compatManifest['cleanup_paths'];
            }
        }
        if ((array_key_exists('steps', $config) && ! is_array($config['steps'])) || ! array_key_exists('steps', $config) || $config['steps'] === []) {
            if (array_key_exists('steps', $compatManifest)) {
                $config['steps'] = $compatManifest['steps'];
            }
        }
        if ((array_key_exists('restore_steps', $config) && ! is_array($config['restore_steps'])) || ! array_key_exists('restore_steps', $config) || $config['restore_steps'] === []) {
            if (array_key_exists('restore_steps', $compatManifest)) {
                $config['restore_steps'] = $compatManifest['restore_steps'];
            }
        }

        return $config;
    }

    private function hasActionableRebootRestoreConfig(array $config): bool
    {
        $manifest = null;
        if (array_key_exists('manifest', $config) && is_array($config['manifest'])) {
            $manifest = $config['manifest'];
        }

        $cleanup = [];
        $steps = [];
        $restoreSteps = [];

        if (is_array($manifest)) {
            $cleanup = is_array($manifest['cleanup_paths'] ?? null) ? $manifest['cleanup_paths'] : [];
            $steps = is_array($manifest['steps'] ?? null) ? $manifest['steps'] : [];
            $restoreSteps = is_array($manifest['restore_steps'] ?? null) ? $manifest['restore_steps'] : [];
        } else {
            $cleanup = is_array($config['cleanup_paths'] ?? null) ? $config['cleanup_paths'] : [];
            $steps = is_array($config['steps'] ?? null) ? $config['steps'] : [];
            $restoreSteps = is_array($config['restore_steps'] ?? null) ? $config['restore_steps'] : [];
        }

        return $cleanup !== [] || $steps !== [] || $restoreSteps !== [];
    }

    private function buildRebootRestoreCompatManifestFromProfile(array $config): array
    {
        $cleanDownloads = (bool) ($config['clean_downloads'] ?? true);
        $cleanDesktop = (bool) ($config['clean_desktop'] ?? false);
        $cleanDocuments = (bool) ($config['clean_documents'] ?? false);
        $cleanUserTemp = (bool) ($config['clean_user_temp'] ?? true);
        $cleanWindowsTemp = (bool) ($config['clean_windows_temp'] ?? true);
        $cleanRecycleBin = (bool) ($config['clean_recycle_bin'] ?? false);
        $cleanDmsStaging = (bool) ($config['clean_dms_staging'] ?? true);

        $programData = getenv('ProgramData') ?: 'C:\\ProgramData';
        $cleanupPaths = [];
        if ($cleanDmsStaging) {
            $cleanupPaths[] = rtrim($programData, '\\/').'\\DMS\\Packages\\staging';
            $cleanupPaths[] = rtrim($programData, '\\/').'\\DMS\\ConfigPush';
        }

        $script = $this->buildRebootRestoreCompatScript(
            $cleanDownloads,
            $cleanDesktop,
            $cleanDocuments,
            $cleanUserTemp,
            $cleanWindowsTemp,
            $cleanRecycleBin
        );

        $manifest = [
            'steps' => [[
                'type' => 'shell',
                'shell' => 'powershell',
                'script' => $script,
            ]],
        ];
        if ($cleanupPaths !== []) {
            $manifest['cleanup_paths'] = $cleanupPaths;
        }

        return $manifest;
    }

    private function buildRebootRestoreCompatScript(
        bool $cleanDownloads,
        bool $cleanDesktop,
        bool $cleanDocuments,
        bool $cleanUserTemp,
        bool $cleanWindowsTemp,
        bool $cleanRecycleBin
    ): string {
        $lines = [
            "\$ErrorActionPreference='SilentlyContinue'",
            "\$excluded=@('Public','Default','Default User','All Users','Administrator')",
            "\$usersRoot='C:\\Users'",
            "if(Test-Path \$usersRoot){",
            "  Get-ChildItem \$usersRoot -Directory -ErrorAction SilentlyContinue | Where-Object { \$excluded -notcontains \$_.Name } | ForEach-Object {",
            "    \$userRoot=\$_.FullName",
        ];

        if ($cleanDownloads) {
            $lines[] = "    \$p=Join-Path \$userRoot 'Downloads'; if(Test-Path \$p){ Remove-Item (Join-Path \$p '*') -Recurse -Force -ErrorAction SilentlyContinue }";
        }
        if ($cleanDesktop) {
            $lines[] = "    \$p=Join-Path \$userRoot 'Desktop'; if(Test-Path \$p){ Remove-Item (Join-Path \$p '*') -Recurse -Force -ErrorAction SilentlyContinue }";
        }
        if ($cleanDocuments) {
            $lines[] = "    \$p=Join-Path \$userRoot 'Documents'; if(Test-Path \$p){ Remove-Item (Join-Path \$p '*') -Recurse -Force -ErrorAction SilentlyContinue }";
        }
        if ($cleanUserTemp) {
            $lines[] = "    \$p=Join-Path \$userRoot 'AppData\\Local\\Temp'; if(Test-Path \$p){ Remove-Item (Join-Path \$p '*') -Recurse -Force -ErrorAction SilentlyContinue }";
        }

        $lines[] = "  }";
        $lines[] = "}";

        if ($cleanWindowsTemp) {
            $lines[] = "\$w='C:\\Windows\\Temp'; if(Test-Path \$w){ Remove-Item (Join-Path \$w '*') -Recurse -Force -ErrorAction SilentlyContinue }";
        }
        if ($cleanRecycleBin) {
            $lines[] = "try { Clear-RecycleBin -Force -ErrorAction SilentlyContinue } catch {}";
        }

        return implode('; ', $lines);
    }

    private function buildAgentUninstallAuthorizationPayload(?User $user): array
    {
        $ttlMinutes = $this->settingInt('devices.agent_uninstall_confirmation_ttl_minutes', 30);
        $ttlMinutes = max(1, min(240, $ttlMinutes));

        return [
            'admin_confirmed' => true,
            'admin_confirmed_at' => now()->toIso8601String(),
            'admin_confirmed_by_user_id' => $user?->id,
            'admin_confirmation_ttl_minutes' => $ttlMinutes,
            'admin_confirmation_nonce' => (string) Str::uuid(),
        ];
    }

    private function createPolicyAssignment(string $policyVersionId, string $targetType, string $targetId): bool
    {
        $existing = \DB::table('policy_assignments')
            ->where('policy_version_id', $policyVersionId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->exists();
        if ($existing) {
            return false;
        }

        \DB::table('policy_assignments')->insert([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $policyVersionId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'rollout_strategy' => 'immediate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function kioskLockdownPresetMatrix(): array
    {
        return [
            'app_controls' => [
                'label' => 'App Controls (AppLocker path + Store restrictions)',
                'toggle' => 'include_app_controls',
                'preset_keys' => ['kiosk_applocker_service_enforced', 'lab_disable_store', 'lab_disable_consumer_features'],
            ],
            'usb_lock' => [
                'label' => 'USB storage restrictions',
                'toggle' => 'include_usb_lock',
                'preset_keys' => ['block_usb_storage'],
            ],
            'local_admin' => [
                'label' => 'Local admin rights restriction',
                'toggle' => 'include_local_admin_restriction',
                'preset_keys' => ['lab_local_admin_restriction'],
            ],
            'shell_lock' => [
                'label' => 'Shell lock (Explorer-only shell)',
                'toggle' => 'include_shell_lock',
                'preset_keys' => ['kiosk_shell_explorer_only'],
            ],
            'taskmgr_lock' => [
                'label' => 'Disable Task Manager',
                'toggle' => 'include_taskmgr_lock',
                'preset_keys' => ['lab_exam_mode_taskmgr_off'],
            ],
            'control_panel_lock' => [
                'label' => 'Disable Control Panel',
                'toggle' => 'include_control_panel_lock',
                'preset_keys' => ['disable_control_panel'],
            ],
        ];
    }

    private function ensureCatalogPresetPolicyVersion(array $catalogItem, ?int $createdBy): array
    {
        $name = trim((string) ($catalogItem['name'] ?? ''));
        $slug = trim((string) ($catalogItem['slug'] ?? ''));
        $category = trim((string) ($catalogItem['category'] ?? 'general'));
        $ruleType = trim((string) ($catalogItem['rule_type'] ?? ''));
        $ruleConfig = is_array($catalogItem['rule_json'] ?? null) ? $catalogItem['rule_json'] : [];
        $removeRules = is_array($catalogItem['remove_rules'] ?? null) ? $catalogItem['remove_rules'] : [];

        if ($slug === '' || $ruleType === '') {
            return ['version' => null, 'policy_created' => false, 'version_created' => false];
        }

        $validationError = $this->validateRuleConfig($ruleType, $ruleConfig);
        if ($validationError !== null) {
            return ['version' => null, 'policy_created' => false, 'version_created' => false];
        }

        $policyCreated = false;
        $policy = Policy::query()->where('slug', $slug)->first();
        if (! $policy) {
            $policy = Policy::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $name !== '' ? $name : Str::headline(str_replace('-', ' ', $slug)),
                'slug' => $slug,
                'category' => $category !== '' ? $category : 'general',
                'status' => 'active',
            ]);
            $policyCreated = true;
        }

        $existingVersion = PolicyVersion::query()
            ->where('policy_id', $policy->id)
            ->orderByDesc('version_number')
            ->get()
            ->first(function (PolicyVersion $version) use ($ruleType, $ruleConfig) {
                $rule = PolicyRule::query()
                    ->where('policy_version_id', $version->id)
                    ->orderBy('order_index')
                    ->first();
                if (! $rule) {
                    return false;
                }
                if (strcasecmp((string) $rule->rule_type, $ruleType) !== 0) {
                    return false;
                }
                $current = $this->normalizeConfigForComparison(is_array($rule->rule_config) ? $rule->rule_config : []);
                $expected = $this->normalizeConfigForComparison($ruleConfig);
                return $current === $expected;
            });

        if ($existingVersion) {
            if ($removeRules !== []) {
                $this->upsertPolicyRemovalProfile($existingVersion->id, $ruleType, $ruleConfig, $createdBy, $removeRules);
            }
            return ['version' => $existingVersion, 'policy_created' => $policyCreated, 'version_created' => false];
        }

        $nextVersionNumber = (int) (PolicyVersion::query()->where('policy_id', $policy->id)->max('version_number') ?? 0) + 1;
        $version = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'version_number' => $nextVersionNumber,
            'status' => 'active',
            'created_by' => $createdBy,
            'published_at' => now(),
        ]);

        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $version->id,
            'order_index' => 0,
            'rule_type' => $ruleType,
            'rule_config' => $ruleConfig,
            'enforce' => true,
        ]);
        $this->upsertPolicyRemovalProfile($version->id, $ruleType, $ruleConfig, $createdBy, $removeRules);

        return ['version' => $version, 'policy_created' => $policyCreated, 'version_created' => true];
    }

    private function normalizeConfigForComparison(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            if ($isList) {
                return array_map(fn ($item) => $this->normalizeConfigForComparison($item), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->normalizeConfigForComparison($item);
            }
            ksort($normalized);
            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    private function cloneJobWithRuns(
        DmsJob $source,
        string $targetType,
        string $targetId,
        ?string $singleDeviceId,
        int $priority,
        ?int $createdBy
    ): DmsJob {
        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => (string) $source->job_type,
            'status' => 'queued',
            'priority' => $priority,
            'payload' => is_array($source->payload) ? $source->payload : [],
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_by' => $createdBy,
        ]);

        if ($targetType === 'device') {
            $deviceId = $singleDeviceId ?: $targetId;
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $deviceId,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);

            return $job;
        }

        $deviceIds = \DB::table('device_group_memberships')->where('device_group_id', $targetId)->pluck('device_id');
        foreach ($deviceIds as $deviceId) {
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => (string) $deviceId,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);
        }

        return $job;
    }

    public function jobs(): View
    {
        $jobs = DmsJob::query()->latest('created_at')->paginate(20);
        $jobs = $this->reconcileJobsWithRuns($jobs);
        $jobSummary = $this->jobSummaryCounts();
        $jobIds = $jobs->getCollection()->pluck('id')->values()->all();

        $skippedByJob = collect();
        if ($jobIds !== []) {
            $skippedByJob = \DB::table('job_runs')
                ->selectRaw('job_id, count(*) as total')
                ->whereIn('job_id', $jobIds)
                ->where('result_payload', 'like', '%"already_installed":true%')
                ->groupBy('job_id')
                ->pluck('total', 'job_id');
        }

        $jobs->getCollection()->transform(function ($job) use ($skippedByJob) {
            $job->skipped_runs = (int) ($skippedByJob[$job->id] ?? 0);
            return $job;
        });

        return view('admin.jobs', [
            'jobs' => $jobs,
            'job_summary' => $jobSummary,
            'devices' => Device::query()->orderBy('hostname')->get(['id', 'hostname']),
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
            'packages' => PackageModel::query()->orderBy('name')->get(['id', 'name', 'package_type']),
            'policies' => Policy::query()->orderBy('name')->get(['id', 'name']),
            'ops' => [
                'kill_switch' => $this->settingBool('jobs.kill_switch', false),
                'max_retries' => $this->settingInt('jobs.max_retries', 3),
                'base_backoff_seconds' => $this->settingInt('jobs.base_backoff_seconds', 30),
                'allowed_script_hashes' => $this->settingArray('scripts.allowed_sha256', []),
                'auto_allow_run_command_hashes' => $this->settingBool('scripts.auto_allow_run_command_hashes', false),
                'delete_cleanup_before_uninstall' => $this->settingBool('devices.delete_cleanup_before_uninstall', false),
                'package_download_url_mode' => $this->settingString('packages.download_url_mode', 'public'),
                'behavior_detection_mode' => 'ai',
                'behavior_ai_threshold' => $this->settingString('behavior.ai_threshold', '0.82'),
                'behavior_ai_model_path' => $this->settingString('behavior.ai_model_path', 'behavior_models/current-model.json'),
                'behavior_ai_model_trained_at' => $this->settingString('behavior.ai_model_trained_at', 'not-trained'),
            ],
        ]);
    }

    public function createJob(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'job_type' => ['required', 'in:install_package,uninstall_package,install_msi,install_exe,install_custom,install_archive,uninstall_msi,uninstall_exe,uninstall_archive,apply_policy,run_command,create_snapshot,restore_snapshot,update_agent,uninstall_agent,reconcile_software_inventory'],
            'target_type' => ['required', 'in:device,group'],
            'target_id' => ['required', 'uuid'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'payload_json' => ['required', 'json'],
            'stagger_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'run_as' => ['nullable', 'in:default,elevated,system'],
            'timeout_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
        ]);

        $payload = json_decode($data['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($data['job_type'] === 'run_command') {
            $script = (string) ($payload['script'] ?? '');
            if (trim($script) === '') {
                return back()->withErrors(['job' => 'run_command requires payload.script.'])->withInput();
            }

            $runAs = strtolower(trim((string) ($data['run_as'] ?? ($payload['run_as'] ?? 'default'))));
            if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
                $runAs = 'default';
            }
            $payload['run_as'] = $runAs;

            $timeoutSeconds = isset($data['timeout_seconds']) ? (int) $data['timeout_seconds'] : (int) ($payload['timeout_seconds'] ?? 300);
            $payload['timeout_seconds'] = max(30, min(3600, $timeoutSeconds));

            $computedHash = strtolower(hash('sha256', $script));
            // Always normalize payload hash server-side from exact script text.
            $payload['script_sha256'] = $computedHash;

            // Optional convenience: keep central allowlist in sync when enabled.
            if ($this->settingBool('scripts.auto_allow_run_command_hashes', false)) {
                $allow = array_map('strtolower', $this->settingArray('scripts.allowed_sha256', []));
                if (! in_array($computedHash, $allow, true)) {
                    $updatedAllow = array_values(array_unique(array_merge($allow, [$computedHash])));
                    ControlPlaneSetting::query()->updateOrCreate(
                        ['key' => 'scripts.allowed_sha256'],
                        ['value' => ['value' => $updatedAllow], 'updated_by' => $request->user()?->id]
                    );
                }
            }
        }
        if ($data['job_type'] === 'uninstall_agent') {
            $payload = $payload + $this->buildAgentUninstallAuthorizationPayload($request->user());
        }

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => $data['job_type'],
            'status' => 'queued',
            'priority' => (int) ($data['priority'] ?? 100),
            'payload' => $payload,
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'created_by' => $request->user()?->id,
        ]);

        if ($job->target_type === 'device') {
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $job->target_id,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);
        } else {
            $deviceIds = \DB::table('device_group_memberships')->where('device_group_id', $job->target_id)->pluck('device_id');
            $staggerSeconds = (int) ($data['stagger_seconds'] ?? 0);
            $index = 0;
            foreach ($deviceIds as $deviceId) {
                JobRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'device_id' => $deviceId,
                    'status' => 'pending',
                    'next_retry_at' => $staggerSeconds > 0 ? now()->addSeconds($index * $staggerSeconds) : null,
                ]);
                $index++;
            }
        }

        $auditLogger->log('job.create.web', 'job', $job->id, null, $job->toArray(), $request->user()?->id);

        return back()->with('status', 'Job queued.');
    }

    public function rerunJob(Request $request, string $jobId, AuditLogger $auditLogger): RedirectResponse
    {
        $source = DmsJob::query()->findOrFail($jobId);
        $cloned = $this->cloneJobWithRuns(
            $source,
            (string) $source->target_type,
            (string) $source->target_id,
            null,
            (int) $source->priority,
            $request->user()?->id
        );

        $auditLogger->log('job.rerun.web', 'job', $cloned->id, null, [
            'source_job_id' => $source->id,
            'target_type' => $cloned->target_type,
            'target_id' => $cloned->target_id,
        ], $request->user()?->id);

        return back()->with('status', 'Job re-run queued.');
    }

    public function rerunJobRun(Request $request, string $runId, AuditLogger $auditLogger): RedirectResponse
    {
        $run = JobRun::query()->findOrFail($runId);
        $source = DmsJob::query()->findOrFail((string) $run->job_id);
        $device = Device::query()->findOrFail((string) $run->device_id);

        $cloned = $this->cloneJobWithRuns(
            $source,
            'device',
            $device->id,
            $device->id,
            (int) $source->priority,
            $request->user()?->id
        );

        $auditLogger->log('job.run.rerun.web', 'job', $cloned->id, null, [
            'source_job_id' => $source->id,
            'source_run_id' => $run->id,
            'device_id' => $device->id,
        ], $request->user()?->id);

        return back()->with('status', 'Run re-queued for this device.');
    }

    public function jobDetail(string $jobId): View
    {
        $job = DmsJob::query()->findOrFail($jobId);

        $runs = JobRun::query()
            ->where('job_id', $job->id)
            ->orderByDesc('updated_at')
            ->get();

        $deviceNames = Device::query()
            ->whereIn('id', $runs->pluck('device_id')->filter()->unique()->values())
            ->pluck('hostname', 'id');

        $eventsByRun = collect();
        if ($runs->isNotEmpty()) {
            $eventsByRun = JobEvent::query()
                ->whereIn('job_run_id', $runs->pluck('id')->values())
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('job_run_id');
        }

        return view('admin.job-detail', [
            'job' => $job,
            'runs' => $runs,
            'deviceNames' => $deviceNames,
            'eventsByRun' => $eventsByRun,
        ]);
    }

    public function storeAndClearJobs(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:completed,all'],
            'store_snapshot' => ['nullable', 'boolean'],
        ]);

        $scope = (string) $data['scope'];
        $storeSnapshot = (bool) ($data['store_snapshot'] ?? true);

        $jobIds = collect();
        if ($scope === 'all') {
            $jobIds = DmsJob::query()->pluck('id');
        } else {
            $completedIds = \DB::table('job_runs')
                ->select('job_id')
                ->whereNotNull('job_id')
                ->groupBy('job_id')
                ->havingRaw("sum(case when status in ('pending','acked','running') then 1 else 0 end) = 0")
                ->pluck('job_id');
            $jobIds = DmsJob::query()->whereIn('id', $completedIds)->pluck('id');
        }

        if ($jobIds->isEmpty()) {
            return back()->with('status', 'No jobs matched the selected clear scope.');
        }

        $jobIdList = $jobIds->values()->all();
        $runs = JobRun::query()->whereIn('job_id', $jobIdList)->get();
        $runIds = $runs->pluck('id')->values()->all();

        $archivePath = null;
        if ($storeSnapshot) {
            $jobsData = DmsJob::query()
                ->whereIn('id', $jobIdList)
                ->orderBy('created_at')
                ->get()
                ->map(fn (DmsJob $job) => $job->toArray())
                ->values()
                ->all();

            $runsData = $runs
                ->sortBy('created_at')
                ->values()
                ->map(fn (JobRun $run) => $run->toArray())
                ->all();

            $eventsData = $runIds === []
                ? []
                : \DB::table('job_events')
                    ->whereIn('job_run_id', $runIds)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn ($event) => (array) $event)
                    ->values()
                    ->all();

            $archive = [
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    'scope' => $scope,
                    'generated_by_user_id' => $request->user()?->id,
                    'jobs_count' => count($jobsData),
                    'runs_count' => count($runsData),
                    'events_count' => count($eventsData),
                ],
                'jobs' => $jobsData,
                'job_runs' => $runsData,
                'job_events' => $eventsData,
            ];

            $archiveDir = storage_path('app'.DIRECTORY_SEPARATOR.'job-archives');
            if (! is_dir($archiveDir)) {
                @mkdir($archiveDir, 0775, true);
            }
            $archiveFile = 'job-archive-'.now()->format('Ymd-His').'-'.$scope.'-'.Str::lower(Str::random(6)).'.json';
            $archivePath = $archiveDir.DIRECTORY_SEPARATOR.$archiveFile;
            file_put_contents($archivePath, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $deletedEvents = 0;
        $deletedRuns = 0;
        $deletedJobs = 0;

        \DB::transaction(function () use ($jobIdList, $runIds, &$deletedEvents, &$deletedRuns, &$deletedJobs) {
            if ($runIds !== []) {
                $deletedEvents = (int) \DB::table('job_events')->whereIn('job_run_id', $runIds)->delete();
            }
            $deletedRuns = (int) JobRun::query()->whereIn('job_id', $jobIdList)->delete();
            $deletedJobs = (int) DmsJob::query()->whereIn('id', $jobIdList)->delete();
        });

        $auditLogger->log('jobs.store_clear.web', 'job', 'bulk', null, [
            'scope' => $scope,
            'stored_snapshot' => $storeSnapshot,
            'archive_path' => $archivePath,
            'deleted_jobs' => $deletedJobs,
            'deleted_runs' => $deletedRuns,
            'deleted_events' => $deletedEvents,
        ], $request->user()?->id);

        $message = "Jobs cleared. Deleted jobs: {$deletedJobs}, runs: {$deletedRuns}, events: {$deletedEvents}.";
        if ($archivePath) {
            $message .= ' Archive: '.str_replace('\\', '/', $archivePath);
        }

        return back()->with('status', $message);
    }

    private function reconcileJobsWithRuns(\Illuminate\Contracts\Pagination\LengthAwarePaginator $jobs): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $items = $jobs->getCollection();
        if ($items->isEmpty()) {
            return $jobs;
        }

        $jobIds = $items->pluck('id')->values()->all();
        $aggregates = \DB::table('job_runs')
            ->selectRaw('job_id, count(*) as total_runs')
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending_runs")
            ->selectRaw("sum(case when status = 'acked' then 1 else 0 end) as acked_runs")
            ->selectRaw("sum(case when status = 'running' then 1 else 0 end) as running_runs")
            ->selectRaw("sum(case when status = 'success' then 1 else 0 end) as success_runs")
            ->selectRaw("sum(case when status in ('failed','non_compliant') then 1 else 0 end) as failed_runs")
            ->whereIn('job_id', $jobIds)
            ->groupBy('job_id')
            ->get()
            ->keyBy('job_id');

        $dirtyUpdates = [];
        foreach ($items as $job) {
            $agg = $aggregates->get($job->id);
            $computed = 'queued';
            if ($agg) {
                $pending = (int) ($agg->pending_runs ?? 0);
                $acked = (int) ($agg->acked_runs ?? 0);
                $running = (int) ($agg->running_runs ?? 0);
                $success = (int) ($agg->success_runs ?? 0);
                $failed = (int) ($agg->failed_runs ?? 0);
                $total = (int) ($agg->total_runs ?? 0);

                if ($running > 0) {
                    $computed = 'running';
                } elseif ($acked > 0) {
                    $computed = 'acked';
                } elseif ($pending > 0) {
                    $computed = 'pending';
                } elseif ($failed > 0) {
                    $computed = 'failed';
                } elseif ($total > 0 && $success === $total) {
                    $computed = 'completed';
                } else {
                    $computed = 'running';
                }
            }

            if ($job->status !== $computed) {
                $dirtyUpdates[$job->id] = $computed;
                $job->status = $computed;
            }
        }

        if ($dirtyUpdates !== []) {
            foreach ($dirtyUpdates as $id => $status) {
                DmsJob::query()->where('id', $id)->update(['status' => $status]);
            }
        }

        $jobs->setCollection($items);
        return $jobs;
    }

    /**
     * @return array{total:int,active:int,completed:int,failed:int}
     */
    private function jobSummaryCounts(): array
    {
        $totalJobs = (int) DmsJob::query()->count();
        $jobRunAgg = \DB::table('job_runs')
            ->selectRaw('job_id, count(*) as total_runs')
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending_runs")
            ->selectRaw("sum(case when status = 'acked' then 1 else 0 end) as acked_runs")
            ->selectRaw("sum(case when status = 'running' then 1 else 0 end) as running_runs")
            ->selectRaw("sum(case when status = 'success' then 1 else 0 end) as success_runs")
            ->selectRaw("sum(case when status in ('failed','non_compliant') then 1 else 0 end) as failed_runs")
            ->groupBy('job_id');

        $summary = \DB::query()
            ->fromSub($jobRunAgg, 'jra')
            ->selectRaw('count(*) as jobs_with_runs')
            ->selectRaw('sum(case when (pending_runs + acked_runs + running_runs) > 0 then 1 else 0 end) as active_jobs')
            ->selectRaw('sum(case when failed_runs > 0 then 1 else 0 end) as failed_jobs')
            ->selectRaw('sum(case when total_runs > 0 and success_runs = total_runs then 1 else 0 end) as completed_jobs')
            ->first();

        return [
            'total' => $totalJobs,
            'active' => (int) ($summary->active_jobs ?? 0),
            'completed' => (int) ($summary->completed_jobs ?? 0),
            'failed' => (int) ($summary->failed_jobs ?? 0),
        ];
    }

    public function updateOps(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'kill_switch' => ['nullable', 'boolean'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'base_backoff_seconds' => ['nullable', 'integer', 'min:5', 'max:1800'],
            'allowed_script_hashes' => ['nullable', 'string', 'max:5000'],
            'auto_allow_run_command_hashes' => ['nullable', 'boolean'],
            'delete_cleanup_before_uninstall' => ['nullable', 'boolean'],
            'package_download_url_mode' => ['nullable', 'in:public,signed'],
            'behavior_ai_threshold' => ['nullable', 'numeric', 'min:0.10', 'max:0.99'],
        ]);

        $settings = [
            'jobs.kill_switch' => (bool) ($data['kill_switch'] ?? false),
            'jobs.max_retries' => (int) ($data['max_retries'] ?? 3),
            'jobs.base_backoff_seconds' => (int) ($data['base_backoff_seconds'] ?? 30),
            'scripts.allowed_sha256' => collect(preg_split('/\r\n|\r|\n/', (string) ($data['allowed_script_hashes'] ?? '')))
                ->map(fn ($line) => strtolower(trim($line)))
                ->filter(fn ($line) => preg_match('/^[a-f0-9]{64}$/', $line))
                ->values()
                ->all(),
            'scripts.auto_allow_run_command_hashes' => (bool) ($data['auto_allow_run_command_hashes'] ?? false),
            'devices.delete_cleanup_before_uninstall' => (bool) ($data['delete_cleanup_before_uninstall'] ?? false),
            'packages.download_url_mode' => (string) ($data['package_download_url_mode'] ?? 'public'),
            'behavior.detection_mode' => 'ai',
            'behavior.ai_threshold' => (string) ($data['behavior_ai_threshold'] ?? $this->settingString('behavior.ai_threshold', '0.82')),
        ];

        foreach ($settings as $key => $value) {
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value], 'updated_by' => $request->user()?->id]
            );
        }

        $auditLogger->log('ops.settings.update.web', 'control_plane_settings', 'global', null, $settings, $request->user()?->id);

        return back()->with('status', 'Operational settings updated.');
    }

    public function toggleKillSwitch(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'admin_password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! $user || ! Hash::check((string) $data['admin_password'], (string) $user->password)) {
            return back()->withErrors([
                'kill_switch' => 'Admin password is incorrect.',
            ]);
        }

        $enabled = (bool) $data['enabled'];
        $previous = $this->settingBool('jobs.kill_switch', false);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'jobs.kill_switch'],
            ['value' => ['value' => $enabled], 'updated_by' => $user->id]
        );

        $auditLogger->log(
            'ops.kill_switch.toggle.web',
            'control_plane_settings',
            'jobs.kill_switch',
            ['value' => $previous],
            ['value' => $enabled],
            $user->id
        );

        if ($previous === $enabled) {
            return back()->with('status', $enabled
                ? 'Command dispatch remains paused. Kill switch is already enabled.'
                : 'Command dispatch remains active. Kill switch is already disabled.');
        }

        return back()->with('status', $enabled
            ? 'Command dispatch paused. Kill switch is now enabled.'
            : 'Command dispatch resumed. Kill switch is now disabled.');
    }

    public function rotateSigningKey(Request $request, CommandEnvelopeSigner $signer, AuditLogger $auditLogger): RedirectResponse
    {
        $newKey = $signer->rotate();
        $auditLogger->log('keys.rotate.web', 'key_material', $newKey->id, null, ['kid' => $newKey->kid], $request->user()?->id);

        return back()->with('status', 'Command signing key rotated: '.$newKey->kid);
    }

    public function agent(Request $request): View
    {
        $defaultPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $defaultApiBase = $defaultPublicBase.'/api/v1';
        $backendServer = $this->agentBackendStatusData();

        return view('admin.agent', [
            'releases' => AgentRelease::query()->latest('created_at')->get(),
            'activeRelease' => AgentRelease::query()->where('is_active', true)->first(),
            'devices' => Device::query()->orderBy('hostname')->get(['id', 'hostname']),
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
            'generated' => session('agent_generated'),
            'connectivity' => session('agent_connectivity'),
            'defaultApiBase' => $defaultApiBase,
            'defaultPublicBase' => $defaultPublicBase,
            'backendServer' => $backendServer,
        ]);
    }

    public function startAgentBackendServer(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $status = $this->agentBackendStatusData();
        if ($status['running']) {
            return back()->with('status', 'Agent backend server is already running.');
        }

        $configuredWorkdir = trim((string) env('AGENT_BACKEND_WORKDIR', ''));
        $wrapperStart = $this->defaultAgentBackendWrapperStartCommand();
        $defaultStartCommand = $wrapperStart ?? $this->defaultAgentBackendStartCommand();
        $command = trim((string) env('AGENT_BACKEND_START_COMMAND', $defaultStartCommand));
        if ($command === '') {
            $command = $defaultStartCommand;
        }

        $resolvedWorkdir = $this->resolveAgentBackendWorkdir();
        $workdir = $resolvedWorkdir ?? base_path();
        $logPath = storage_path('logs'.DIRECTORY_SEPARATOR.'agent-backend.log');
        if (! is_dir(dirname($logPath))) {
            @mkdir(dirname($logPath), 0775, true);
        }

        if (str_contains($command, 'app.main:app')) {
            if ($resolvedWorkdir === null) {
                $candidates = $this->agentBackendWorkdirCandidates($configuredWorkdir);
                $hint = $configuredWorkdir !== ''
                    ? 'Configured AGENT_BACKEND_WORKDIR does not exist or is invalid: '.$configuredWorkdir
                    : 'No AGENT_BACKEND_WORKDIR is set and bundled backend was not found in candidate paths: '.implode(', ', $candidates);
                return back()->withErrors([
                    'agent_backend' => $hint.' | Set AGENT_BACKEND_WORKDIR to your Python API project folder.',
                ]);
            }

            $workdir = $resolvedWorkdir;
            $expectedModule = $workdir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'main.py';
            if (! is_file($expectedModule)) {
                $hint = $configuredWorkdir === ''
                    ? 'Bundled backend is missing app/main.py. Restore backend/agent-backend/app/main.py or set AGENT_BACKEND_WORKDIR to your Python API project folder.'
                    : 'Configured workdir does not contain app/main.py. Set AGENT_BACKEND_WORKDIR to your Python API project folder.';
                return back()->withErrors([
                    'agent_backend' => 'Backend start command expects app/main.py, but not found in workdir: '.$expectedModule.' | '.$hint,
                ]);
            }
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $fullCommand = 'cmd /c "cd /d '.escapeshellarg($workdir).' && start "" /b '.$command.' >> '.escapeshellarg($logPath).' 2>&1"';
        } else {
            $fullCommand = 'sh -lc "cd '.escapeshellarg($workdir).' && nohup '.$command.' >> '.escapeshellarg($logPath).' 2>&1 &"';
        }

        $exitCode = 0;
        @exec($fullCommand, $noop, $exitCode);
        usleep(800000);
        $after = $this->agentBackendStatusData();

        $auditLogger->log('agent.backend.start.web', 'agent_backend', 'local', null, [
            'workdir' => $workdir,
            'command' => $command,
            'exit_code' => $exitCode,
            'running_after_start' => $after['running'],
        ], $request->user()?->id);

        if (! $after['running']) {
            $logHint = '';
            if (is_file($logPath)) {
                $tail = @file($logPath);
                if (is_array($tail) && ! empty($tail)) {
                    $last = trim((string) end($tail));
                    if ($last !== '') {
                        $logHint = ' | Last log: '.$last;
                    }
                }
            }
            return back()->withErrors([
                'agent_backend' => 'Start command executed but backend is still not reachable on '.$after['host'].':'.$after['port'].'. Check storage/logs/agent-backend.log'.$logHint,
            ]);
        }

        return back()->with('status', 'Agent backend server started.');
    }

    public function agentBackendServerStatus(): JsonResponse
    {
        return response()->json($this->agentBackendStatusData());
    }

    public function uploadAgentRelease(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
            'installer' => ['required', 'file', 'max:307200'],
        ]);

        $file = $request->file('installer');
        $fileName = $file->getClientOriginalName();
        $storagePath = $file->storeAs('agent-releases', Str::uuid().'_'.$fileName);
        $absolutePath = storage_path('app'.DIRECTORY_SEPARATOR.$storagePath);

        $release = AgentRelease::query()->create([
            'id' => (string) Str::uuid(),
            'version' => $data['version'],
            'platform' => $data['platform'] ?? 'windows-x64',
            'file_name' => $fileName,
            'storage_path' => $storagePath,
            'size_bytes' => filesize($absolutePath),
            'sha256' => hash_file('sha256', $absolutePath),
            'notes' => $data['notes'] ?? null,
            'is_active' => false,
            'uploaded_by' => $request->user()?->id,
        ]);

        $auditLogger->log('agent.release.upload.web', 'agent_release', $release->id, null, $release->toArray(), $request->user()?->id);

        return back()->with('status', 'Agent release uploaded.');
    }

    public function autoBuildAgentRelease(Request $request, AgentBuildService $buildService, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:100'],
            'runtime' => ['nullable', 'string', 'max:50'],
            'self_contained' => ['nullable', 'boolean'],
            'activate_after_build' => ['nullable', 'boolean'],
        ]);

        try {
            $build = $buildService->build(
                $data['version'],
                $data['runtime'] ?? 'win-x64',
                (bool) ($data['self_contained'] ?? false)
            );

            $relativeStorage = 'agent-releases/builds/'.$build['zip_name'];
            $targetPath = storage_path('app'.DIRECTORY_SEPARATOR.$relativeStorage);
            if (! is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0775, true);
            }
            if ($build['zip_full_path'] !== $targetPath) {
                copy($build['zip_full_path'], $targetPath);
            }

            $release = AgentRelease::query()->create([
                'id' => (string) Str::uuid(),
                'version' => $data['version'],
                'platform' => 'windows-x64',
                'file_name' => $build['zip_name'],
                'storage_path' => $relativeStorage,
                'size_bytes' => $build['size_bytes'],
                'sha256' => $build['sha256'],
                'notes' => 'Auto-built via dashboard',
                'is_active' => false,
                'uploaded_by' => $request->user()?->id,
            ]);

            if ((bool) ($data['activate_after_build'] ?? true)) {
                AgentRelease::query()->where('id', '!=', $release->id)->update(['is_active' => false]);
                $release->update(['is_active' => true]);
            }

            $auditLogger->log('agent.release.autobuild.web', 'agent_release', $release->id, null, [
                'file_name' => $release->file_name,
                'sha256' => $release->sha256,
            ], $request->user()?->id);

            return back()->with('status', 'Agent auto-build succeeded and release created: '.$release->file_name)
                ->with('agent_build_log', $build['log']);
        } catch (\Throwable $e) {
            $message = 'Auto-build failed: '.$e->getMessage().' | Verify .NET SDK and PowerShell are available to the app runtime, and ensure AGENT_BUILD_REPO_PATH (or default /var/www/agent) plus storage/app/agent-releases/builds are accessible.';
            if (str_contains(strtolower($e->getMessage()), 'not enough space on the disk')) {
                $message .= ' | Disk is full: free space on C: or disable self-contained publish to reduce artifact size.';
            }

            return back()->withErrors(['agent_build' => $message]);
        }
    }

    public function activateAgentRelease(Request $request, string $releaseId, AuditLogger $auditLogger): RedirectResponse
    {
        $release = AgentRelease::query()->findOrFail($releaseId);
        AgentRelease::query()->update(['is_active' => false]);
        $release->update(['is_active' => true]);

        $auditLogger->log('agent.release.activate.web', 'agent_release', $release->id, null, $release->toArray(), $request->user()?->id);

        return back()->with('status', 'Active agent release updated.');
    }

    public function deleteAgentRelease(Request $request, string $releaseId, AuditLogger $auditLogger): RedirectResponse
    {
        $release = AgentRelease::query()->findOrFail($releaseId);

        if ($release->is_active) {
            return back()->withErrors([
                'agent_release_delete' => 'Cannot delete active release. Activate another release first.',
            ]);
        }

        $absolutePath = storage_path('app'.DIRECTORY_SEPARATOR.$release->storage_path);
        $before = $release->toArray();
        $removedRepoArtifacts = 0;

        try {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            // For auto-build artifacts, also remove matching folders in agent repo dist/
            // so dashboard deletions reclaim repository disk space too.
            $removedRepoArtifacts = $this->cleanupAgentRepoArtifacts($release->file_name);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'agent_release_delete' => 'Release record exists but file delete failed: '.$e->getMessage(),
            ]);
        }

        $release->delete();

        $auditLogger->log('agent.release.delete.web', 'agent_release', $releaseId, $before, [
            'removed_repo_artifacts' => $removedRepoArtifacts,
        ], $request->user()?->id);

        $suffix = $removedRepoArtifacts > 0
            ? " Also removed {$removedRepoArtifacts} local build artifact folder(s) from agent repo."
            : '';

        return back()->with('status', 'Release deleted.'.$suffix);
    }

    public function generateAgentInstaller(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'release_id' => ['required', 'uuid'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'api_base_url' => ['nullable', 'url'],
            'public_base_url' => ['nullable', 'url'],
        ]);

        try {
            $bundle = $this->buildAgentInstallerBundle($request, $data, $auditLogger);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['agent_generate' => $e->getMessage()])->withInput();
        }

        return back()->with('agent_generated', $bundle)->with('status', 'Installer links generated.');
    }

    public function generateAgentInstallerJson(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate([
            'release_id' => ['required', 'uuid'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'api_base_url' => ['nullable', 'url'],
            'public_base_url' => ['nullable', 'url'],
        ]);

        try {
            $bundle = $this->buildAgentInstallerBundle($request, $data, $auditLogger);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Installer links generated.',
            'bundle' => $bundle,
        ]);
    }

    public function pushAgentUpdate(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'release_id' => ['required', 'uuid'],
            'target_scope' => ['required', 'in:all,group,device'],
            'target_id' => ['nullable', 'uuid'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'stagger_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'public_base_url' => ['nullable', 'url'],
        ]);

        if ($data['target_scope'] !== 'all' && empty($data['target_id'])) {
            return back()->withErrors(['agent_push_update' => 'Select a target device/group, or use scope "all".'])->withInput();
        }

        $release = AgentRelease::query()->findOrFail($data['release_id']);
        $expiresAt = now()->addHours((int) ($data['expires_hours'] ?? 24));
        $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? $requestPublicBase), '/');
        if ($this->isLocalOnlyHost($publicBaseUrl)) {
            return back()->withErrors([
                'agent_push_update' => 'Update link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.',
            ])->withInput();
        }

        $downloadUrl = $this->buildSignedUrl($publicBaseUrl, 'agent.release.download', $expiresAt, ['releaseId' => $release->id]);
        $priority = (int) ($data['priority'] ?? 100);
        $staggerSeconds = (int) ($data['stagger_seconds'] ?? 0);

        $payload = [
            'download_url' => $downloadUrl,
            'sha256' => strtolower((string) $release->sha256),
            'file_name' => $release->file_name,
            'release_id' => $release->id,
            'release_version' => $release->version,
        ];

        $createdJobs = 0;
        $createdRuns = 0;

        if ($data['target_scope'] === 'all') {
            $deviceIds = Device::query()->pluck('id');
            $index = 0;
            foreach ($deviceIds as $deviceId) {
                $job = DmsJob::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_type' => 'update_agent',
                    'status' => 'queued',
                    'priority' => $priority,
                    'payload' => $payload,
                    'target_type' => 'device',
                    'target_id' => $deviceId,
                    'created_by' => $request->user()?->id,
                ]);
                JobRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'device_id' => $deviceId,
                    'status' => 'pending',
                    'next_retry_at' => $staggerSeconds > 0 ? now()->addSeconds($index * $staggerSeconds) : null,
                ]);
                $createdJobs++;
                $createdRuns++;
                $index++;
            }
        } elseif ($data['target_scope'] === 'group') {
            $targetId = (string) $data['target_id'];
            DeviceGroup::query()->findOrFail($targetId);

            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'job_type' => 'update_agent',
                'status' => 'queued',
                'priority' => $priority,
                'payload' => $payload,
                'target_type' => 'group',
                'target_id' => $targetId,
                'created_by' => $request->user()?->id,
            ]);
            $createdJobs++;

            $deviceIds = \DB::table('device_group_memberships')
                ->where('device_group_id', $targetId)
                ->pluck('device_id');
            $index = 0;
            foreach ($deviceIds as $deviceId) {
                JobRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'device_id' => $deviceId,
                    'status' => 'pending',
                    'next_retry_at' => $staggerSeconds > 0 ? now()->addSeconds($index * $staggerSeconds) : null,
                ]);
                $createdRuns++;
                $index++;
            }
        } else {
            $targetId = (string) $data['target_id'];
            Device::query()->findOrFail($targetId);
            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'job_type' => 'update_agent',
                'status' => 'queued',
                'priority' => $priority,
                'payload' => $payload,
                'target_type' => 'device',
                'target_id' => $targetId,
                'created_by' => $request->user()?->id,
            ]);
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $targetId,
                'status' => 'pending',
            ]);
            $createdJobs++;
            $createdRuns++;
        }

        $auditLogger->log('agent.update.push.web', 'agent_release', $release->id, null, [
            'release_version' => $release->version,
            'target_scope' => $data['target_scope'],
            'target_id' => $data['target_id'] ?? null,
            'created_jobs' => $createdJobs,
            'created_runs' => $createdRuns,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $request->user()?->id);

        return back()->with('status', "Agent update queued. Jobs: {$createdJobs}, runs: {$createdRuns}.");
    }

    public function testAgentApiConnectivity(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'api_base_url' => ['required', 'url'],
        ]);

        $baseUrl = rtrim($data['api_base_url'], '/');
        $origin = preg_replace('#/api/v1$#', '', $baseUrl) ?: $baseUrl;
        $targets = [
            'health' => $origin.'/up',
            'keyset' => $baseUrl.'/device/keyset',
            'enroll' => $baseUrl.'/device/enroll',
        ];

        $results = [];
        foreach ($targets as $name => $url) {
            $start = microtime(true);
            try {
                $response = Http::timeout(6)->acceptJson()->get($url);
                $elapsed = (int) round((microtime(true) - $start) * 1000);
                $results[$name] = [
                    'url' => $url,
                    'ok' => $response->status() < 500,
                    'status' => $response->status(),
                    'latency_ms' => $elapsed,
                ];
            } catch (\Throwable $e) {
                $elapsed = (int) round((microtime(true) - $start) * 1000);
                $results[$name] = [
                    'url' => $url,
                    'ok' => false,
                    'status' => 'error',
                    'latency_ms' => $elapsed,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $allGood = collect($results)->every(fn ($r) => (bool) ($r['ok'] ?? false));
        $auditLogger->log('agent.connectivity.test.web', 'agent_delivery', $baseUrl, null, [
            'all_good' => $allGood,
            'results' => $results,
        ], $request->user()?->id);

        return back()
            ->with('agent_connectivity', [
                'api_base_url' => $baseUrl,
                'all_good' => $allGood,
                'results' => $results,
                'tested_at' => now()->toIso8601String(),
            ])
            ->with('status', $allGood ? 'Connectivity test passed.' : 'Connectivity test found issues.');
    }

    public function ipDeploy(Request $request): View
    {
        $defaultPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $defaultApiBase = $defaultPublicBase.'/api/v1';

        return view('admin.ip-deploy', [
            'result' => session('ip_deploy_result'),
            'releases' => AgentRelease::query()->latest('created_at')->get(['id', 'version', 'file_name', 'is_active']),
            'activeRelease' => AgentRelease::query()->where('is_active', true)->first(['id']),
            'defaultApiBase' => $defaultApiBase,
            'defaultPublicBase' => $defaultPublicBase,
        ]);
    }

    public function runIpDeploy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'deploy_method' => ['nullable', 'in:smb_rpc,psexec'],
            'release_id' => ['nullable', 'uuid'],
            'install_script_url' => ['nullable', 'url'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'api_base_url' => ['nullable', 'url'],
            'public_base_url' => ['nullable', 'url'],
            'target_ip' => ['nullable', 'ip'],
            'ip_range_cidr' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'target_ips' => ['nullable', 'string', 'max:10000'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'scan_only' => ['nullable', 'boolean'],
            'skip_port_checks' => ['nullable', 'boolean'],
            'auto_bootstrap' => ['nullable', 'boolean'],
            'bootstrap_only' => ['nullable', 'boolean'],
        ]);
        $scanOnly = (bool) ($data['scan_only'] ?? false);

        if (
            empty($data['target_ip'])
            && empty($data['ip_range_cidr'])
            && trim((string) ($data['target_ips'] ?? '')) === ''
        ) {
            return back()->withErrors([
                'ip_deploy' => 'Provide at least one target: Single IP, CIDR range, or target IP list.',
            ])->withInput();
        }

        $deployMethod = (string) ($data['deploy_method'] ?? 'smb_rpc');
        $bootstrapOnly = $deployMethod === 'psexec' && (bool) ($data['bootstrap_only'] ?? false);
        $scriptUrl = trim((string) ($data['install_script_url'] ?? ''));
        if (! $scanOnly && ! $bootstrapOnly && $scriptUrl === '' && empty($data['release_id'])) {
            return back()->withErrors([
                'ip_deploy' => 'Select an agent release or provide a signed install script URL.',
            ])->withInput();
        }
        if (! $scanOnly && (trim((string) ($data['username'] ?? '')) === '' || trim((string) ($data['password'] ?? '')) === '')) {
            return back()->withErrors([
                'ip_deploy' => 'Username and password are required for install mode.',
            ])->withInput();
        }

        if (! empty($data['release_id'])) {
            $release = AgentRelease::query()->findOrFail((string) $data['release_id']);
            $expiresAt = now()->addHours((int) ($data['expires_hours'] ?? 24));
            $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
            $requestApiBase = $requestPublicBase.'/api/v1';
            $apiBaseUrl = rtrim((string) ($data['api_base_url'] ?? $requestApiBase), '/');
            $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? preg_replace('#/api/v1$#', '', $apiBaseUrl)), '/');
            if ($this->isLocalOnlyHost($publicBaseUrl) || $this->isLocalOnlyHost($apiBaseUrl)) {
                return back()->withErrors([
                    'ip_deploy' => 'IP deploy link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from target PCs.',
                ])->withInput();
            }

            $rawToken = Str::random(64);
            EnrollmentToken::query()->create([
                'id' => (string) Str::uuid(),
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => $expiresAt,
                'created_by' => $request->user()?->id,
            ]);

            $scriptUrl = $this->buildSignedUrl($publicBaseUrl, 'agent.release.script', $expiresAt, [
                'releaseId' => $release->id,
                'token' => $rawToken,
                'api_base_url' => $apiBaseUrl,
            ]);
        }

        $scriptFile = $deployMethod === 'psexec'
            ? 'install-agent-by-psexec.ps1'
            : 'install-agent-by-smb-rpc.ps1';
        $scriptPath = base_path('scripts'.DIRECTORY_SEPARATOR.$scriptFile);
        if (! is_file($scriptPath)) {
            return back()->withErrors([
                'ip_deploy' => 'Worker script not found: '.$scriptPath,
            ])->withInput();
        }

        $listFilePath = null;
        $targetIpsRaw = trim((string) ($data['target_ips'] ?? ''));
        if ($targetIpsRaw !== '') {
            $tmpDir = storage_path('app'.DIRECTORY_SEPARATOR.'tmp');
            if (! is_dir($tmpDir)) {
                @mkdir($tmpDir, 0775, true);
            }
            $listFilePath = $tmpDir.DIRECTORY_SEPARATOR.'ip-deploy-'.Str::uuid().'.txt';
            file_put_contents($listFilePath, $targetIpsRaw);
        }

        $parts = [
            'powershell',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            escapeshellarg($scriptPath),
        ];
        if ($scriptUrl !== '') {
            $parts[] = '-InstallScriptUrl';
            $parts[] = escapeshellarg($scriptUrl);
        }
        if (trim((string) ($data['username'] ?? '')) !== '') {
            $parts[] = '-Username';
            $parts[] = escapeshellarg((string) $data['username']);
        }
        if (trim((string) ($data['password'] ?? '')) !== '') {
            $parts[] = '-Password';
            $parts[] = escapeshellarg((string) $data['password']);
        }
        if (! empty($data['target_ip'])) {
            $parts[] = '-TargetIp';
            $parts[] = escapeshellarg((string) $data['target_ip']);
        }
        if (! empty($data['ip_range_cidr'])) {
            $parts[] = '-IpRangeCidr';
            $parts[] = escapeshellarg((string) $data['ip_range_cidr']);
        }
        if ($listFilePath !== null) {
            $parts[] = '-TargetListPath';
            $parts[] = escapeshellarg($listFilePath);
        }
        if ($scanOnly) {
            $parts[] = '-WhatIf';
        }
        if ((bool) ($data['skip_port_checks'] ?? false)) {
            $parts[] = '-SkipPortChecks';
        }
        if ($deployMethod === 'psexec' && (bool) ($data['auto_bootstrap'] ?? false)) {
            $parts[] = '-AutoBootstrap';
        }
        if ($bootstrapOnly) {
            $parts[] = '-BootstrapOnly';
        }

        $command = implode(' ', $parts);
        $output = [];
        $exitCode = 0;
        @exec($command.' 2>&1', $output, $exitCode);

        if ($listFilePath !== null && is_file($listFilePath)) {
            @unlink($listFilePath);
        }

        $reportPath = null;
        foreach ($output as $line) {
            if (preg_match('/^Report:\s*(.+)$/', trim((string) $line), $matches)) {
                $reportPath = trim((string) $matches[1]);
            }
        }

        $rows = [];
        if ($reportPath && is_file($reportPath)) {
            $handle = @fopen($reportPath, 'rb');
            if (is_resource($handle)) {
                $headers = fgetcsv($handle);
                if (is_array($headers)) {
                    while (($vals = fgetcsv($handle)) !== false) {
                        if (! is_array($vals)) {
                            continue;
                        }
                        if (count($vals) < count($headers)) {
                            $vals = array_pad($vals, count($headers), '');
                        } elseif (count($vals) > count($headers)) {
                            $vals = array_slice($vals, 0, count($headers));
                        }

                        $row = array_combine($headers, $vals);
                        if (is_array($row)) {
                            $rows[] = $row;
                        }
                    }
                }
                @fclose($handle);
            }
        }

        $summary = [
            'found' => count($rows),
            'success' => count(array_filter($rows, fn ($r) => strtolower((string) ($r['ok'] ?? '')) === 'true')),
            'failed' => count(array_filter($rows, fn ($r) => strtolower((string) ($r['ok'] ?? '')) !== 'true')),
        ];

        $hints = [];
        $outputText = implode("\n", array_map(static fn ($line) => (string) $line, $output));
        if (stripos($outputText, 'Access is denied') !== false) {
            $hints[] = 'Credentials authenticated but are missing remote admin rights (ADMIN$/service control). Try local Administrator or run one-time bootstrap as admin on target.';
        }
        if (stripos($outputText, 'WinRM') !== false) {
            $hints[] = 'WinRM path is unavailable. Use SMB/RPC or PsExec mode, or enable WinRM on the target network policy.';
        }
        if (stripos($outputText, 'psexec.exe not found') !== false) {
            $hints[] = 'PsExec mode selected, but psexec.exe is not available in PATH on the admin server.';
        }

        $auditLogger->log('agent.ip_deploy.run.web', 'agent_deploy', $deployMethod, null, [
            'deploy_method' => $deployMethod,
            'target_ip' => $data['target_ip'] ?? null,
            'ip_range_cidr' => $data['ip_range_cidr'] ?? null,
            'release_id' => $data['release_id'] ?? null,
            'used_generated_script_url' => ! empty($data['release_id']),
            'scan_only' => $scanOnly,
            'skip_port_checks' => (bool) ($data['skip_port_checks'] ?? false),
            'auto_bootstrap' => (bool) ($data['auto_bootstrap'] ?? false),
            'bootstrap_only' => $bootstrapOnly,
            'exit_code' => $exitCode,
            'summary' => $summary,
            'report_path' => $reportPath,
        ], $request->user()?->id);

        return back()->with('ip_deploy_result', [
            'exit_code' => $exitCode,
            'command_output' => $output,
            'report_path' => $reportPath,
            'rows' => $rows,
            'summary' => $summary,
            'deploy_method' => $deployMethod,
            'worker_script' => $scriptFile,
            'hints' => $hints,
            'scan_only' => $scanOnly,
            'auto_bootstrap' => (bool) ($data['auto_bootstrap'] ?? false),
            'bootstrap_only' => $bootstrapOnly,
        ])->with('status', $exitCode === 0 ? 'IP deploy execution completed.' : 'IP deploy execution finished with errors.');
    }

    public function downloadAgentRelease(Request $request, string $releaseId)
    {
        $release = AgentRelease::query()->findOrFail($releaseId);
        $path = storage_path('app'.DIRECTORY_SEPARATOR.$release->storage_path);
        abort_unless(is_file($path), 404, 'Installer artifact not found');

        return response()->download($path, $release->file_name);
    }

    public function agentInstallScript(Request $request, string $releaseId): Response
    {
        $release = AgentRelease::query()->findOrFail($releaseId);
        $publicBaseUrl = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $downloadUrl = $this->buildSignedUrl($publicBaseUrl, 'agent.release.download', now()->addMinutes(30), ['releaseId' => $release->id]);
        $token = (string) $request->query('token');
        $apiBaseUrl = (string) $request->query('api_base_url', rtrim(config('app.url'), '/').'/api/v1');

        $script = <<<'PS1'
$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$DownloadUrl = "__DOWNLOAD_URL__"
$EnrollmentToken = "__ENROLLMENT_TOKEN__"
$ApiBaseUrl = "__API_BASE_URL__"
$WorkDir = "$env:ProgramData\DMS"
$InstallerPath = Join-Path $WorkDir "__FILE_NAME__"
$ExtractPath = Join-Path $WorkDir "agent"
$TokenFile = Join-Path $WorkDir "enrollment-token.txt"
$ApiFile = Join-Path $WorkDir "api-base-url.txt"

function Test-DmsAdmin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-DmsAdmin)) {
    if ([string]::IsNullOrWhiteSpace($PSCommandPath)) {
        throw "Administrator rights are required. Save this script to disk and run it again so Windows can prompt for elevation."
    }

    Start-Process -FilePath "powershell.exe" -Verb RunAs -ArgumentList @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", "`"$PSCommandPath`""
    )
    exit 0
}

New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null
[Environment]::SetEnvironmentVariable("DMS_API_BASE_URL", $ApiBaseUrl, "Machine")
[Environment]::SetEnvironmentVariable("DMS_ENROLLMENT_TOKEN", $EnrollmentToken, "Machine")
Set-Content -Path $TokenFile -Value $EnrollmentToken -Encoding UTF8
Set-Content -Path $ApiFile -Value $ApiBaseUrl -Encoding UTF8

Invoke-WebRequest -Uri $DownloadUrl -OutFile $InstallerPath

$ext = [System.IO.Path]::GetExtension($InstallerPath).ToLowerInvariant()
if ($ext -eq ".msi") {
    Start-Process -FilePath "msiexec.exe" -Wait -ArgumentList "/i `"$InstallerPath`" /qn /norestart"
}
elseif ($ext -eq ".exe") {
    Start-Process -FilePath $InstallerPath -Wait -ArgumentList "/quiet /norestart"
}
elseif ($ext -eq ".zip") {
    New-Item -ItemType Directory -Force -Path $ExtractPath | Out-Null
    Expand-Archive -Path $InstallerPath -DestinationPath $ExtractPath -Force
    $installScript = Join-Path $ExtractPath "installer\\windows-service-install.ps1"
    if (Test-Path $installScript) {
        powershell -ExecutionPolicy Bypass -File $installScript
    }
    else {
        throw "Installer zip does not contain installer\\windows-service-install.ps1"
    }
}
else {
    throw "Unsupported installer type: $ext"
}

Write-Host "DMS agent installer completed."
PS1;

        $script = str_replace(
            ['__DOWNLOAD_URL__', '__ENROLLMENT_TOKEN__', '__API_BASE_URL__', '__FILE_NAME__'],
            [$downloadUrl, $token, $apiBaseUrl, $release->file_name],
            $script
        );

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="install-dms-agent.ps1"',
        ]);
    }

    public function agentInstallLauncher(Request $request, string $releaseId): Response
    {
        $release = AgentRelease::query()->findOrFail($releaseId);
        $publicBaseUrl = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $scriptUrl = $this->buildSignedUrl($publicBaseUrl, 'agent.release.script', now()->addMinutes(30), [
            'releaseId' => $release->id,
            'token' => (string) $request->query('token'),
            'api_base_url' => (string) $request->query('api_base_url', rtrim(config('app.url'), '/').'/api/v1'),
        ]);
        $launcher = $this->buildAgentInstallLauncherScript($scriptUrl);

        return response($launcher, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="install-dms-agent.cmd"',
        ]);
    }

    private function buildAbsoluteUrl(string $publicBaseUrl, string $relativeSignedPath): string
    {
        return rtrim($publicBaseUrl, '/').$relativeSignedPath;
    }

    private function buildSignedUrl(string $publicBaseUrl, string $routeName, \DateTimeInterface $expiration, array $parameters = []): string
    {
        $defaultRoot = rtrim((string) config('app.url'), '/');
        URL::forceRootUrl(rtrim($publicBaseUrl, '/'));

        try {
            return URL::temporarySignedRoute($routeName, $expiration, $parameters);
        } finally {
            if ($defaultRoot !== '') {
                URL::forceRootUrl($defaultRoot);
            }
        }
    }

    private function buildAgentInstallLauncherScript(string $scriptUrl): string
    {
        $cmdSafeScriptUrl = str_replace('%', '%%', $scriptUrl);

        return str_replace('__SCRIPT_URL__', $cmdSafeScriptUrl, <<<'CMD'
@echo off
setlocal
set "SCRIPT_URL=__SCRIPT_URL__"
set "SCRIPT_PATH=%TEMP%\install-dms-agent.ps1"

echo Downloading DMS installer...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -UseBasicParsing '%SCRIPT_URL%' -OutFile '%SCRIPT_PATH%'"
if errorlevel 1 (
  echo Failed to download installer script.
  pause
  exit /b 1
)

echo Requesting administrator permission...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File','%SCRIPT_PATH%')"
if errorlevel 1 (
  echo Elevation was canceled or failed.
  pause
  exit /b 1
)

exit /b 0
CMD);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildAgentInstallerBundle(Request $request, array $data, AuditLogger $auditLogger): array
    {
        $release = AgentRelease::query()->findOrFail((string) $data['release_id']);
        $expiresAt = now()->addHours((int) ($data['expires_hours'] ?? 24));
        $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $requestApiBase = $requestPublicBase.'/api/v1';
        $apiBaseUrl = rtrim((string) ($data['api_base_url'] ?? $requestApiBase), '/');
        $publicBaseUrl = rtrim((string) ($data['public_base_url'] ?? preg_replace('#/api/v1$#', '', $apiBaseUrl)), '/');

        if ($this->isLocalOnlyHost($publicBaseUrl) || $this->isLocalOnlyHost($apiBaseUrl)) {
            throw new \InvalidArgumentException('Install link cannot use localhost/127.0.0.1. Use a LAN IP or DNS host reachable from client PCs.');
        }

        $rawToken = Str::random(64);
        $token = EnrollmentToken::query()->create([
            'id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $expiresAt,
            'created_by' => $request->user()?->id,
        ]);

        $downloadUrl = $this->buildSignedUrl($publicBaseUrl, 'agent.release.download', $expiresAt, ['releaseId' => $release->id]);
        $scriptUrl = $this->buildSignedUrl($publicBaseUrl, 'agent.release.script', $expiresAt, [
            'releaseId' => $release->id,
            'token' => $rawToken,
            'api_base_url' => $apiBaseUrl,
        ]);
        $launcherUrl = $this->buildSignedUrl($publicBaseUrl, 'agent.release.launcher', $expiresAt, [
            'releaseId' => $release->id,
            'token' => $rawToken,
            'api_base_url' => $apiBaseUrl,
        ]);

        $auditLogger->log('agent.release.generate_installer.web', 'agent_release', $release->id, null, [
            'expires_at' => $expiresAt->toIso8601String(),
            'token_id' => $token->id,
        ], $request->user()?->id);

        $copyCommand = "powershell -NoProfile -ExecutionPolicy Bypass -Command '\$scriptPath = Join-Path \$env:TEMP ''install-dms-agent.ps1''; Invoke-WebRequest -UseBasicParsing ''{$scriptUrl}'' -OutFile \$scriptPath; Start-Process -FilePath ''powershell.exe'' -Verb RunAs -ArgumentList @(''-NoProfile'',''-ExecutionPolicy'',''Bypass'',''-File'',\$scriptPath)'";
        $cmdScript = $this->buildAgentInstallLauncherScript($scriptUrl);

        return [
            'expires_at' => $expiresAt->toIso8601String(),
            'download_url' => $downloadUrl,
            'script_url' => $scriptUrl,
            'launcher_url' => $launcherUrl,
            'copy_command' => $copyCommand,
            'cmd_script' => $cmdScript,
            'token' => $rawToken,
            'api_base_url' => $apiBaseUrl,
            'public_base_url' => $publicBaseUrl,
            'release_id' => $release->id,
            'release_version' => $release->version,
        ];
    }

    private function resolvePackageArtifactDownloadUrl(
        Request $request,
        PackageFile $file,
        int $expiresHours,
        ?string $publicBaseUrl = null
    ): string {
        $sourceUri = trim((string) ($file->source_uri ?? ''));
        if ($sourceUri !== '' && preg_match('/^https?:\/\//i', $sourceUri) === 1) {
            return $sourceUri;
        }

        $requestPublicBase = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $base = rtrim((string) ($publicBaseUrl ?? $requestPublicBase), '/');

        $storagePath = $this->resolvePackageArtifactStoragePath($file);
        if (is_string($storagePath) && $storagePath !== '') {
            $mode = strtolower($this->settingString('packages.download_url_mode', 'public'));
            if ($mode === 'signed') {
                $expiresAt = now()->addHours(max(1, $expiresHours));
                $signedPath = URL::temporarySignedRoute(
                    'package.file.download',
                    $expiresAt,
                    ['packageFileId' => $file->id],
                    absolute: false
                );
                return $this->buildAbsoluteUrl($base, $signedPath);
            }

            $publicPath = route('package.file.download.public', ['packageFileId' => $file->id], false);
            return $this->buildAbsoluteUrl($base, $publicPath);
        }

        $expiresAt = now()->addHours(max(1, $expiresHours));
        $signedPath = URL::temporarySignedRoute(
            'package.file.download',
            $expiresAt,
            ['packageFileId' => $file->id],
            absolute: false
        );

        return $this->buildAbsoluteUrl($base, $signedPath);
    }

    private function cleanupAgentRepoArtifacts(string $fileName): int
    {
        // Expected auto-build file format: dms-agent-{version}-{runtime}-{buildId}.zip
        if (! preg_match('/^dms-agent-(.+)-([^-]+)-([0-9a-f]{8})\.zip$/i', $fileName, $parts)) {
            return 0;
        }

        $version = $parts[1];
        $runtime = $parts[2];
        $buildId = $parts[3];

        $agentRoot = $this->resolveAgentBuildRepoRoot();
        if ($agentRoot === null) {
            return 0;
        }

        $agentDistRoot = $agentRoot.DIRECTORY_SEPARATOR.'dist';
        if (! is_dir($agentDistRoot)) {
            return 0;
        }

        $targets = [
            $agentDistRoot.DIRECTORY_SEPARATOR."agent-build-{$version}-{$runtime}-{$buildId}",
            $agentDistRoot.DIRECTORY_SEPARATOR."bundle-{$version}-{$runtime}-{$buildId}",
        ];

        $removed = 0;
        foreach ($targets as $target) {
            if (! is_dir($target)) {
                continue;
            }

            $this->deleteDirectoryRecursive($target);
            $removed++;
        }

        return $removed;
    }

    private function resolveAgentBuildRepoRoot(): ?string
    {
        $configured = trim((string) env('AGENT_BUILD_REPO_PATH', ''));
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $repoRoot = realpath(base_path('..')) ?: dirname(base_path());
        $candidates[] = $repoRoot.DIRECTORY_SEPARATOR.'agent';
        $candidates[] = base_path('agent');
        $candidates[] = '/var/www/agent';

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            $path = $resolved !== false ? $resolved : $candidate;
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    private function deleteDirectoryRecursive(string $path): void
    {
        $items = @scandir($path);
        if ($items === false) {
            throw new \RuntimeException('Unable to read directory: '.$path);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full) && ! is_link($full)) {
                $this->deleteDirectoryRecursive($full);
            } else {
                if (! @unlink($full)) {
                    throw new \RuntimeException('Unable to delete file: '.$full);
                }
            }
        }

        if (! @rmdir($path)) {
            throw new \RuntimeException('Unable to delete directory: '.$path);
        }
    }

    private function buildConfigFilePushPowerShellArgs(
        string $downloadUrl,
        string $sha256,
        string $targetPath,
        bool $backupExisting,
        ?string $restartService
    ): string {
        $q = static fn (string $v): string => str_replace("'", "''", $v);

        $script = "\$ErrorActionPreference='Stop';"
            ."\$src='".$q($downloadUrl)."';"
            ."\$dst='".$q($targetPath)."';"
            ."\$expected='".$q(strtolower($sha256))."';"
            ."\$tmpRoot=Join-Path \$env:ProgramData 'DMS\\ConfigPush';"
            ."New-Item -ItemType Directory -Force -Path \$tmpRoot | Out-Null;"
            ."\$tmp=Join-Path \$tmpRoot ([Guid]::NewGuid().ToString()+'.tmp');"
            ."Invoke-WebRequest -Uri \$src -OutFile \$tmp -UseBasicParsing;"
            ."\$actual=(Get-FileHash -Path \$tmp -Algorithm SHA256).Hash.ToLower();"
            ."if(\$actual -ne \$expected){ throw ('sha256 mismatch expected='+\$expected+' actual='+\$actual); };"
            ."\$dstDir=Split-Path -Parent \$dst;"
            ."if(-not [string]::IsNullOrWhiteSpace(\$dstDir)){ New-Item -ItemType Directory -Force -Path \$dstDir | Out-Null; };";

        if ($backupExisting) {
            $script .= "if(Test-Path \$dst){ Copy-Item -Path \$dst -Destination (\$dst+'.bak') -Force; };";
        }

        $script .= "Copy-Item -Path \$tmp -Destination \$dst -Force;"
            ."Remove-Item -Path \$tmp -Force -ErrorAction SilentlyContinue;";

        $serviceName = trim((string) $restartService);
        if ($serviceName !== '') {
            $script .= "if(Get-Service -Name '".$q($serviceName)."' -ErrorAction SilentlyContinue){ Restart-Service -Name '".$q($serviceName)."' -Force -ErrorAction Stop; };";
        }

        return '-NoProfile -ExecutionPolicy Bypass -Command "'.$script.'"';
    }

    private function resolveConfigDeployTargetPath(string $targetPath, string $fileName): string
    {
        $targetPath = trim($targetPath);
        $fileName = trim($fileName);
        if ($targetPath === '' || $fileName === '') {
            return $targetPath;
        }

        $normalized = str_replace('/', '\\', $targetPath);
        $trimmedEnd = rtrim($normalized, "\\ \t\n\r\0\x0B");
        if ($trimmedEnd === '') {
            return $targetPath;
        }

        if ($trimmedEnd !== $normalized) {
            return $trimmedEnd.'\\'.$fileName;
        }

        $baseName = basename(str_replace('\\', '/', $trimmedEnd));
        if (strcasecmp($baseName, $fileName) === 0) {
            return $trimmedEnd;
        }

        $ext = pathinfo($baseName, PATHINFO_EXTENSION);
        if (is_string($ext) && $ext !== '') {
            return $trimmedEnd;
        }

        return $trimmedEnd.'\\'.$fileName;
    }

    private function normalizeDetectionRule(array $rule): ?array
    {
        $type = strtolower((string) ($rule['type'] ?? ''));
        if ($type === '') {
            return null;
        }

        if (isset($rule['path'], $rule['name'], $rule['expected'])) {
            return [
                'type' => $type,
                'path' => (string) $rule['path'],
                'name' => (string) $rule['name'],
                'expected' => (string) $rule['expected'],
            ];
        }

        if (isset($rule['path'], $rule['min_version'])) {
            return [
                'type' => $type,
                'path' => (string) $rule['path'],
                'min_version' => (string) $rule['min_version'],
            ];
        }

        if (isset($rule['product_code'])) {
            return [
                'type' => $type,
                'product_code' => (string) $rule['product_code'],
            ];
        }

        if (isset($rule['path']) && $type === 'file') {
            return [
                'type' => 'file',
                'path' => (string) $rule['path'],
            ];
        }

        return null;
    }

    private function backfillGroupAssignmentsForDevice(string $groupId, string $deviceId, ?int $createdBy): array
    {
        $queuedPolicyJobs = 0;
        $queuedPackageRuns = 0;

        $policyVersionIds = \DB::table('policy_assignments')
            ->where('target_type', 'group')
            ->where('target_id', $groupId)
            ->pluck('policy_version_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($policyVersionIds as $policyVersionId) {
            $policyVersion = PolicyVersion::query()->find((string) $policyVersionId);
            if (! $policyVersion) {
                continue;
            }
            $this->queueApplyPolicyJob($policyVersion, 'device', $deviceId, $createdBy);
            $queuedPolicyJobs++;
        }

        $groupPackageJobs = DmsJob::query()
            ->where('target_type', 'group')
            ->where('target_id', $groupId)
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive'])
            ->orderByDesc('created_at')
            ->get();

        $latestByPackageVersion = [];
        foreach ($groupPackageJobs as $job) {
            $payload = is_array($job->payload) ? $job->payload : [];
            $packageVersionId = (string) ($payload['package_version_id'] ?? '');
            if ($packageVersionId === '') {
                continue;
            }
            if (! array_key_exists($packageVersionId, $latestByPackageVersion)) {
                $latestByPackageVersion[$packageVersionId] = $job;
            }
        }

        foreach ($latestByPackageVersion as $job) {
            $exists = JobRun::query()
                ->where('job_id', $job->id)
                ->where('device_id', $deviceId)
                ->exists();
            if ($exists) {
                continue;
            }

            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $deviceId,
                'status' => 'pending',
                'next_retry_at' => null,
            ]);
            $queuedPackageRuns++;
        }

        return [
            'policy_jobs' => $queuedPolicyJobs,
            'package_runs' => $queuedPackageRuns,
        ];
    }

    private function isLocalOnlyHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }

    private function deletePackageDeploymentData(array $packageVersionIds): array
    {
        $packageVersionIds = array_values(array_filter(array_map(static fn ($id) => (string) $id, $packageVersionIds)));
        if ($packageVersionIds === []) {
            return ['jobs' => 0, 'job_runs' => 0];
        }

        $candidateJobs = DmsJob::query()
            ->whereIn('job_type', ['install_package', 'install_msi', 'install_exe', 'install_custom', 'install_archive', 'uninstall_package', 'uninstall_msi', 'uninstall_exe', 'uninstall_archive'])
            ->where(function ($query) use ($packageVersionIds) {
                foreach ($packageVersionIds as $id) {
                    $query->orWhere('payload', 'like', '%'.$id.'%');
                }
            })
            ->get();

        $jobIds = $candidateJobs
            ->filter(function (DmsJob $job) use ($packageVersionIds) {
                $payload = is_array($job->payload) ? $job->payload : [];
                $versionId = (string) ($payload['package_version_id'] ?? '');
                return in_array($versionId, $packageVersionIds, true);
            })
            ->pluck('id')
            ->values();

        if ($jobIds->isEmpty()) {
            return ['jobs' => 0, 'job_runs' => 0];
        }

        $deletedRuns = JobRun::query()->whereIn('job_id', $jobIds)->delete();
        $deletedJobs = DmsJob::query()->whereIn('id', $jobIds)->delete();

        return [
            'jobs' => (int) $deletedJobs,
            'job_runs' => (int) $deletedRuns,
        ];
    }

    private function agentBackendStatusData(): array
    {
        $host = (string) env('AGENT_BACKEND_HOST', '127.0.0.1');
        $port = (int) env('AGENT_BACKEND_PORT', 8000);
        $workdir = $this->resolveAgentBackendWorkdir();
        $wrapperAvailable = $this->defaultAgentBackendWrapperStartCommand() !== null;

        $timeout = 1.2;
        $errno = 0;
        $errstr = '';
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $running = is_resource($connection);
        if ($running) {
            @fclose($connection);
        }
        $configured = $workdir !== null || $wrapperAvailable || $running;
        $error = null;
        if (! $running) {
            $error = trim($errstr) !== '' ? trim($errstr) : ('connect errno '.$errno);
            if (! $configured) {
                $error = 'No agent backend workdir discovered. Set AGENT_BACKEND_WORKDIR to a folder that contains app/main.py.';
            }
        }

        return [
            'configured' => $configured,
            'running' => $running,
            'host' => $host,
            'port' => $port,
            'workdir' => $workdir,
            'start_command' => (string) (trim((string) env('AGENT_BACKEND_START_COMMAND', '')) !== ''
                ? trim((string) env('AGENT_BACKEND_START_COMMAND', ''))
                : ($this->defaultAgentBackendWrapperStartCommand() ?? $this->defaultAgentBackendStartCommand())),
            'checked_at' => now()->toIso8601String(),
            'error' => $error,
        ];
    }

    private function resolveAgentBackendWorkdir(): ?string
    {
        $configured = trim((string) env('AGENT_BACKEND_WORKDIR', ''));
        foreach ($this->agentBackendWorkdirCandidates($configured) as $candidate) {
            $resolved = realpath($candidate);
            $path = $resolved !== false ? $resolved : $candidate;
            if (! is_dir($path)) {
                continue;
            }

            return $path;
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function agentBackendWorkdirCandidates(string $configured): array
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $parent = rtrim(dirname($base), DIRECTORY_SEPARATOR);
        $candidates = [];

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $candidates[] = $base.DIRECTORY_SEPARATOR.'agent-backend';
        $candidates[] = $base.DIRECTORY_SEPARATOR.'backend'.DIRECTORY_SEPARATOR.'agent-backend';
        $candidates[] = $parent.DIRECTORY_SEPARATOR.'agent-backend';
        $candidates[] = $parent.DIRECTORY_SEPARATOR.'backend'.DIRECTORY_SEPARATOR.'agent-backend';

        if (DIRECTORY_SEPARATOR !== '\\') {
            $candidates[] = '/var/www/html/agent-backend';
            $candidates[] = '/var/www/html/backend/agent-backend';
            $candidates[] = '/var/www/agent-backend';
            $candidates[] = '/var/www/backend/agent-backend';
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $normalized = rtrim((string) $candidate, DIRECTORY_SEPARATOR);
            if ($normalized === '' || in_array($normalized, $unique, true)) {
                continue;
            }
            $unique[] = $normalized;
        }

        return $unique;
    }

    private function defaultAgentBackendWrapperStartCommand(): ?string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        $wrapper = base_path('scripts/runtime/agent-backend.sh');
        if (! is_file($wrapper)) {
            return null;
        }

        return 'sh '.escapeshellarg($wrapper);
    }

    private function defaultAgentBackendStartCommand(): string
    {
        return 'python -m uvicorn app.main:app --host 127.0.0.1 --port 8000';
    }

    public function audit(): View
    {
        return view('admin.audit', [
            'logs' => AuditLog::query()->latest('id')->paginate(40),
            'users' => \App\Models\User::query()->orderBy('name')->get(['id', 'name']),
            'permissions' => Permission::query()->orderBy('slug')->get(['id', 'slug']),
        ]);
    }

    public function access(): View
    {
        $this->ensureSuperAdminAccess();

        return view('admin.access', [
            'roles' => Role::query()->with('permissions')->orderBy('name')->get(),
            'permissions' => Permission::query()->orderBy('slug')->get(['id', 'slug', 'name']),
            'users' => User::query()->with('roles')->orderBy('name')->get(['id', 'name', 'email', 'is_active']),
        ]);
    }

    public function settings(): View
    {
        $this->ensureSuperAdminAccess();

        $policyCatalog = $this->policyCatalog();
        $policyCategories = $this->policyCategories();
        $customCatalog = $this->settingArray('policies.catalog_custom', []);

        return view('admin.settings', [
            'policyCatalog' => $policyCatalog,
            'customCatalog' => $customCatalog,
            'policyCategories' => $policyCategories,
            'rulePresetJson' => collect($policyCatalog)->mapWithKeys(fn ($item) => [$item['rule_type'] => $item['rule_json']])->all(),
            'ops' => [
                'kill_switch' => $this->settingBool('jobs.kill_switch', false),
                'max_retries' => $this->settingInt('jobs.max_retries', 3),
                'base_backoff_seconds' => $this->settingInt('jobs.base_backoff_seconds', 30),
                'allowed_script_hashes' => $this->settingArray('scripts.allowed_sha256', []),
                'auto_allow_run_command_hashes' => $this->settingBool('scripts.auto_allow_run_command_hashes', false),
                'delete_cleanup_before_uninstall' => $this->settingBool('devices.delete_cleanup_before_uninstall', false),
                'behavior_detection_mode' => 'ai',
                'behavior_ai_threshold' => $this->settingString('behavior.ai_threshold', '0.82'),
                'behavior_ai_model_path' => $this->settingString('behavior.ai_model_path', 'behavior_models/current-model.json'),
                'behavior_ai_model_trained_at' => $this->settingString('behavior.ai_model_trained_at', 'not-trained'),
            ],
            'signatureBypassEnabled' => $this->settingBool(
                'security.signature_bypass_enabled',
                filter_var((string) env('DMS_SIGNATURE_BYPASS', 'false'), FILTER_VALIDATE_BOOL)
            ),
            'authPolicy' => [
                'require_mfa' => $this->settingBool('auth.require_mfa', false),
                'max_login_attempts' => max(1, $this->settingInt('auth.max_login_attempts', 5)),
                'lockout_minutes' => max(1, $this->settingInt('auth.lockout_minutes', 15)),
            ],
            'httpsPolicy' => [
                'app_url' => (string) config('app.url', ''),
                'session_secure_cookie' => (bool) config('session.secure', false),
            ],
            'environmentPolicy' => [
                'app_env' => (string) config('app.env', 'local'),
                'app_debug' => (bool) config('app.debug', false),
                'session_secure_cookie' => (bool) config('session.secure', false),
            ],
        ]);
    }

    public function securityCommandCenter(): View
    {
        $this->ensureSuperAdminAccess();

        $signatureBypassEnabled = $this->settingBool(
            'security.signature_bypass_enabled',
            filter_var((string) env('DMS_SIGNATURE_BYPASS', 'false'), FILTER_VALIDATE_BOOL)
        );

        $productionLockMode = $this->settingBool('security.production_lock_mode', false);
        $authRequireMfa = $this->settingBool('auth.require_mfa', false);
        $authMaxAttempts = max(1, $this->settingInt('auth.max_login_attempts', 5));
        $authLockoutMinutes = max(1, $this->settingInt('auth.lockout_minutes', 15));
        $autoAllow = $this->settingBool('scripts.auto_allow_run_command_hashes', false);
        $allowedHashes = $this->settingArray('scripts.allowed_sha256', []);
        $maxRetries = $this->settingInt('jobs.max_retries', 3);
        $baseBackoff = $this->settingInt('jobs.base_backoff_seconds', 30);
        $deleteCleanup = $this->settingBool('devices.delete_cleanup_before_uninstall', false);
        $downloadUrlMode = $this->settingString('packages.download_url_mode', 'public');
        $killSwitch = $this->settingBool('jobs.kill_switch', false);

        $appUrl = (string) config('app.url', '');
        $appDebug = (bool) config('app.debug', false);
        $sessionSecure = (bool) config('session.secure', false);
        $appEnv = strtolower((string) config('app.env', 'local'));
        $httpsConfigured = str_starts_with(strtolower($appUrl), 'https://');

        $staleActiveRuns = JobRun::query()
            ->whereIn('status', ['pending', 'acked', 'running'])
            ->where('updated_at', '<', now()->subMinutes(30))
            ->count();
        $recentFailedRuns = JobRun::query()
            ->whereIn('status', ['failed', 'non_compliant'])
            ->where('updated_at', '>=', now()->subHours(24))
            ->count();

        $controls = [
            [
                'title' => 'Enable Production Lock Mode',
                'status' => $productionLockMode ? 'good' : 'warning',
                'priority' => 'high',
                'description' => $productionLockMode
                    ? 'Production lock mode is enabled.'
                    : 'Enable production lock mode to enforce strict command safety in production.',
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Disable signature bypass',
                'status' => $signatureBypassEnabled ? 'warning' : 'good',
                'priority' => 'critical',
                'description' => $signatureBypassEnabled ? 'Signature verification bypass is ON.' : 'Signature verification bypass is OFF.',
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Enforce admin MFA',
                'status' => $authRequireMfa ? 'good' : 'warning',
                'priority' => 'critical',
                'description' => $authRequireMfa ? 'All admin logins require MFA challenge.' : 'Require MFA for all admin logins.',
                'action_label' => 'Open Settings',
                'action_route' => route('admin.access'),
            ],
            [
                'title' => 'Harden login lockout policy',
                'status' => ($authMaxAttempts <= 8 && $authLockoutMinutes >= 10) ? 'good' : 'warning',
                'priority' => 'high',
                'description' => "Current auth policy: max_login_attempts={$authMaxAttempts}, lockout_minutes={$authLockoutMinutes}.",
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Use strict run_command hashing',
                'status' => (! $autoAllow && count($allowedHashes) > 0) ? 'good' : 'warning',
                'priority' => 'critical',
                'description' => (! $autoAllow && count($allowedHashes) > 0)
                    ? 'Auto-allow is disabled and allowlist has entries.'
                    : 'Set auto_allow_run_command_hashes=false and keep a valid SHA256 allowlist.',
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Keep retry/backoff in safe range',
                'status' => ($maxRetries >= 1 && $maxRetries <= 5 && $baseBackoff >= 15 && $baseBackoff <= 300) ? 'good' : 'warning',
                'priority' => 'medium',
                'description' => "Current: max_retries={$maxRetries}, base_backoff_seconds={$baseBackoff}. Recommended: retries 1-5 and backoff 15-300 seconds.",
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Clean managed payload before device delete',
                'status' => $deleteCleanup ? 'good' : 'warning',
                'priority' => 'high',
                'description' => $deleteCleanup ? 'Enabled: policy/package cleanup before uninstall/delete flow.' : 'Enable cleanup before uninstall/delete flow.',
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Prefer signed package URLs',
                'status' => $downloadUrlMode === 'signed' ? 'good' : 'warning',
                'priority' => 'medium',
                'description' => $downloadUrlMode === 'signed' ? 'Package download mode is signed.' : "Package download mode is {$downloadUrlMode}.",
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Enforce HTTPS app URL',
                'status' => $httpsConfigured ? 'good' : 'warning',
                'priority' => 'high',
                'description' => $httpsConfigured ? 'APP_URL is configured with HTTPS.' : 'APP_URL is not HTTPS. Use TLS for admin/API/package links.',
                'action_label' => 'Review .env',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Disable debug mode',
                'status' => $appDebug ? 'warning' : 'good',
                'priority' => 'high',
                'description' => $appDebug ? 'APP_DEBUG=true exposes diagnostics in errors.' : 'APP_DEBUG is disabled.',
                'action_label' => 'Review .env',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Use secure session cookies',
                'status' => $sessionSecure ? 'good' : 'warning',
                'priority' => 'high',
                'description' => $sessionSecure ? 'session.secure=true.' : 'Set SESSION_SECURE_COOKIE=true for HTTPS.',
                'action_label' => 'Review .env',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Monitor stale active job runs',
                'status' => $staleActiveRuns === 0 ? 'good' : 'warning',
                'priority' => 'medium',
                'description' => $staleActiveRuns === 0 ? 'No stale pending/acked/running job runs (>30 min).' : "Stale active runs detected: {$staleActiveRuns}.",
                'action_label' => 'Open Jobs',
                'action_route' => route('admin.jobs'),
            ],
            [
                'title' => 'Watch failure pressure (24h)',
                'status' => $recentFailedRuns <= 10 ? 'good' : 'warning',
                'priority' => 'medium',
                'description' => "Recent failed/non_compliant job runs (24h): {$recentFailedRuns}.",
                'action_label' => 'Open Jobs',
                'action_route' => route('admin.jobs'),
            ],
            [
                'title' => 'Kill switch readiness',
                'status' => 'info',
                'priority' => 'low',
                'description' => $killSwitch ? 'Kill switch currently ON (dispatch paused).' : 'Kill switch currently OFF (normal). Keep for emergency containment.',
                'action_label' => 'Open Settings',
                'action_route' => route('admin.settings'),
            ],
            [
                'title' => 'Environment posture',
                'status' => $appEnv === 'production' ? 'good' : 'warning',
                'priority' => 'high',
                'description' => $appEnv === 'production' ? 'APP_ENV is production.' : "APP_ENV is {$appEnv}. Use production profile for hardened deployment.",
                'action_label' => 'Review .env',
                'action_route' => route('admin.settings'),
            ],
        ];

        $good = collect($controls)->where('status', 'good')->count();
        $warning = collect($controls)->where('status', 'warning')->count();
        $info = collect($controls)->where('status', 'info')->count();
        $priorityWeights = ['critical' => 25, 'high' => 15, 'medium' => 9, 'low' => 5];
        $totalRiskWeight = (float) collect($controls)->sum(function (array $control) use ($priorityWeights) {
            if (($control['status'] ?? 'info') === 'info') {
                return 0;
            }
            return $priorityWeights[(string) ($control['priority'] ?? 'medium')] ?? 9;
        });
        $currentRiskWeight = (float) collect($controls)->sum(function (array $control) use ($priorityWeights) {
            if (($control['status'] ?? '') !== 'warning') {
                return 0;
            }
            return $priorityWeights[(string) ($control['priority'] ?? 'medium')] ?? 9;
        });
        $warningPressure = $totalRiskWeight > 0
            ? (float) round(($currentRiskWeight / $totalRiskWeight) * 100, 1)
            : 0.0;
        $score = $totalRiskWeight > 0
            ? max(0, min(100, (int) round(100 - (($currentRiskWeight / $totalRiskWeight) * 100))))
            : 100;

        return view('admin.security-command-center', [
            'controls' => $controls,
            'summary' => [
                'good' => $good,
                'warning' => $warning,
                'info' => $info,
                'score' => $score,
                'checked_at' => now()->format('Y-m-d H:i:s'),
                'warning_pressure' => $warningPressure,
            ],
        ]);
    }

    public function profile(Request $request): View
    {
        $user = $request->user();
        $pref = $this->settingArray('users.profile.'.$user->id, []);
        if (is_array($pref)) {
            $pref['avatar_url'] = $this->normalizeAvatarPath($pref['avatar_url'] ?? null);
        }
        $mfaSecretPlain = null;
        $mfaProvisioningUri = null;
        if (is_string($user->mfa_secret) && trim($user->mfa_secret) !== '') {
            try {
                $mfaSecretPlain = Crypt::decryptString($user->mfa_secret);
                $mfaProvisioningUri = app(TotpService::class)->provisioningUri((string) $user->email, $mfaSecretPlain, 'DMS');
            } catch (\Throwable) {
                $mfaSecretPlain = null;
                $mfaProvisioningUri = null;
            }
        }

        return view('admin.profile', [
            'user' => $user,
            'profilePref' => array_merge([
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => 'en_US',
                'bio' => '',
                'avatar_url' => null,
            ], is_array($pref) ? $pref : []),
            'mfaSecretPlain' => $mfaSecretPlain,
            'mfaProvisioningUri' => $mfaProvisioningUri,
        ]);
    }

    public function updateProfile(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:16'],
            'bio' => ['nullable', 'string', 'max:600'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        $user->name = $data['name'];
        $user->email = $data['email'];
        if (!empty($data['password'])) {
            $user->password = Hash::make((string) $data['password']);
        }
        $user->save();

        $prefKey = 'users.profile.'.$user->id;
        $pref = array_merge([
            'timezone' => config('app.timezone', 'UTC'),
            'locale' => 'en_US',
            'bio' => '',
            'avatar_url' => null,
        ], $this->settingArray($prefKey, []));
        $pref['avatar_url'] = $this->normalizeAvatarPath($pref['avatar_url'] ?? null);

        $pref['timezone'] = trim((string) ($data['timezone'] ?? $pref['timezone']));
        $pref['locale'] = trim((string) ($data['locale'] ?? $pref['locale']));
        $pref['bio'] = trim((string) ($data['bio'] ?? $pref['bio']));

        if ($request->boolean('remove_avatar')) {
            $pref['avatar_url'] = null;
        }

        if ($request->hasFile('avatar')) {
            $avatarFile = $request->file('avatar');
            if ($avatarFile) {
                $uploadDir = public_path('uploads/avatars');
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                $avatarName = 'avatar-'.$user->id.'-'.date('YmdHis').'-'.Str::lower(Str::random(8)).'.'.$avatarFile->getClientOriginalExtension();
                $avatarFile->move($uploadDir, $avatarName);
                $pref['avatar_url'] = '/uploads/avatars/'.$avatarName;
            }
        }

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => $prefKey],
            ['value' => ['value' => $pref], 'updated_by' => $user->id]
        );

        $auditLogger->log('profile.update.web', 'user', (string) $user->id, $before, [
            'name' => $user->name,
            'email' => $user->email,
            'pref' => $pref,
        ], $user->id);

        return back()->with('status', 'Profile updated successfully.');
    }

    private function normalizeAvatarPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $candidate) === 1) {
            $path = parse_url($candidate, PHP_URL_PATH);
            $candidate = is_string($path) ? $path : '';
        }

        $uploadsPos = strpos($candidate, '/uploads/avatars/');
        if ($uploadsPos !== false) {
            $candidate = substr($candidate, $uploadsPos);
        }

        $candidate = '/'.ltrim($candidate, '/');

        return str_starts_with($candidate, '/uploads/avatars/')
            ? $candidate
            : null;
    }

    public function setupProfileMfa(Request $request, AuditLogger $auditLogger, TotpService $totpService): RedirectResponse
    {
        $user = $request->user();
        $secret = $totpService->generateSecret();
        $before = ['mfa_enabled' => (bool) $user->mfa_enabled];

        $user->mfa_secret = Crypt::encryptString($secret);
        $user->mfa_enabled = false;
        $user->save();

        $auditLogger->log('profile.mfa.setup.web', 'user', (string) $user->id, $before, [
            'mfa_enabled' => false,
            'secret_rotated' => true,
        ], $user->id);

        return back()->with('status', 'MFA setup secret generated. Scan it in your authenticator app, then confirm with a code.');
    }

    public function enableProfileMfa(Request $request, AuditLogger $auditLogger, TotpService $totpService): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ]);

        $user = $request->user();
        if (! is_string($user->mfa_secret) || trim($user->mfa_secret) === '') {
            return back()->withErrors(['profile_mfa' => 'MFA secret not set. Generate setup secret first.']);
        }

        try {
            $secret = Crypt::decryptString($user->mfa_secret);
        } catch (\Throwable) {
            return back()->withErrors(['profile_mfa' => 'Stored MFA secret is invalid. Generate setup secret again.']);
        }

        if (! $totpService->verifyCode($secret, (string) $data['code'])) {
            return back()->withErrors(['profile_mfa' => 'Invalid MFA code.']);
        }

        $before = ['mfa_enabled' => (bool) $user->mfa_enabled];
        $user->mfa_enabled = true;
        $user->save();

        $auditLogger->log('profile.mfa.enable.web', 'user', (string) $user->id, $before, [
            'mfa_enabled' => true,
        ], $user->id);

        return back()->with('status', 'MFA is now enabled for your account.');
    }

    public function disableProfileMfa(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! Hash::check((string) $data['password'], (string) $user->password)) {
            return back()->withErrors(['profile_mfa' => 'Password is incorrect.']);
        }

        $before = ['mfa_enabled' => (bool) $user->mfa_enabled];
        $user->mfa_enabled = false;
        $user->mfa_secret = null;
        $user->save();

        $auditLogger->log('profile.mfa.disable.web', 'user', (string) $user->id, $before, [
            'mfa_enabled' => false,
            'secret_removed' => true,
        ], $user->id);

        return back()->with('status', 'MFA has been disabled for your account.');
    }

    public function branding(): View
    {
        $this->ensureSuperAdminAccess();

        return view('admin.settings-branding', [
            'branding' => $this->brandingSettings(),
        ]);
    }

    public function updateBranding(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'project_name' => ['nullable', 'string', 'max:80'],
            'project_tagline' => ['nullable', 'string', 'max:160'],
            'primary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'accent_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'background_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'sidebar_tint' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'border_radius_px' => ['nullable', 'integer', 'min:0', 'max:32'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'favicon' => ['nullable', 'image', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
            'reset_defaults' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('reset_defaults')) {
            $branding = $this->brandingDefaults();
        } else {
            $branding = array_merge($this->brandingDefaults(), $this->brandingSettings());
            $branding['project_name'] = trim((string) ($data['project_name'] ?? $branding['project_name']));
            $branding['project_tagline'] = trim((string) ($data['project_tagline'] ?? $branding['project_tagline']));
            $branding['primary_color'] = strtoupper((string) ($data['primary_color'] ?? $branding['primary_color']));
            $branding['accent_color'] = strtoupper((string) ($data['accent_color'] ?? $branding['accent_color']));
            $branding['background_color'] = strtoupper((string) ($data['background_color'] ?? $branding['background_color']));
            $branding['sidebar_tint'] = strtoupper((string) ($data['sidebar_tint'] ?? $branding['sidebar_tint']));
            $branding['border_radius_px'] = (int) ($data['border_radius_px'] ?? $branding['border_radius_px']);
        }

        $uploadDir = public_path('uploads/branding');
        if (! is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        if ($request->boolean('remove_logo')) {
            $branding['logo_url'] = null;
        }
        if ($request->boolean('remove_favicon')) {
            $branding['favicon_url'] = null;
        }

        if ($request->hasFile('logo')) {
            $logoFile = $request->file('logo');
            if ($logoFile) {
                $logoName = 'logo-'.date('YmdHis').'-'.Str::lower(Str::random(8)).'.'.$logoFile->getClientOriginalExtension();
                $logoFile->move($uploadDir, $logoName);
                $branding['logo_url'] = url('uploads/branding/'.$logoName);
            }
        }
        if ($request->hasFile('favicon')) {
            $faviconFile = $request->file('favicon');
            if ($faviconFile) {
                $faviconName = 'favicon-'.date('YmdHis').'-'.Str::lower(Str::random(8)).'.'.$faviconFile->getClientOriginalExtension();
                $faviconFile->move($uploadDir, $faviconName);
                $branding['favicon_url'] = url('uploads/branding/'.$faviconName);
            }
        }

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'ui.branding'],
            ['value' => ['value' => $branding], 'updated_by' => $request->user()?->id]
        );

        $auditLogger->log('settings.branding.update.web', 'control_plane_settings', 'ui.branding', null, $branding, $request->user()?->id);

        return back()->with('status', 'Branding updated successfully.');
    }

    public function policyCategoriesPage(): View
    {
        $this->ensureSuperAdminAccess();

        $policyCatalog = $this->policyCatalog();
        $policyCategories = $this->policyCategories();

        return view('admin.policy-categories', [
            'policyCategories' => $policyCategories,
            'categoryStats' => $this->buildPolicyCategoryStats($policyCatalog, $policyCategories),
        ]);
    }

    public function catalog(): View
    {
        $this->ensureSuperAdminAccess();

        $policyCatalog = $this->policyCatalog();

        return view('admin.catalog', [
            'policyCatalog' => $policyCatalog,
            'customCatalog' => $this->settingArray('policies.catalog_custom', []),
            'policyCategories' => $this->policyCategories(),
            'rulePresetJson' => collect($policyCatalog)->mapWithKeys(fn ($item) => [$item['rule_type'] => $item['rule_json']])->all(),
        ]);
    }

    public function updateSignatureBypass(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $enabled = (bool) $request->boolean('signature_bypass_enabled');

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'security.signature_bypass_enabled'],
            ['value' => ['value' => $enabled], 'updated_by' => $request->user()?->id]
        );

        $envPath = base_path('.env');
        $envUpdated = $this->upsertEnvBoolean($envPath, 'DMS_SIGNATURE_BYPASS', $enabled);
        if ($envUpdated) {
            Artisan::call('config:clear');
        }

        $auditLogger->log('settings.signature_bypass.update.web', 'control_plane_settings', 'security.signature_bypass_enabled', null, [
            'enabled' => $enabled,
            'env_synced' => $envUpdated,
        ], $request->user()?->id);

        return back()->with(
            'status',
            'Signature bypass '.($enabled ? 'enabled' : 'disabled').'. '.($enabled ? 'Use only for development/testing.' : 'Production-safe mode restored.')
        );
    }

    public function updateAuthPolicy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'require_mfa' => ['nullable', 'boolean'],
            'max_login_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $settings = [
            'auth.require_mfa' => (bool) ($data['require_mfa'] ?? false),
            'auth.max_login_attempts' => (int) $data['max_login_attempts'],
            'auth.lockout_minutes' => (int) $data['lockout_minutes'],
        ];

        foreach ($settings as $key => $value) {
            ControlPlaneSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value], 'updated_by' => $request->user()?->id]
            );
        }

        $auditLogger->log(
            'settings.auth_policy.update.web',
            'control_plane_settings',
            'auth_policy',
            null,
            $settings,
            $request->user()?->id
        );

        return back()->with('status', 'Login lockout policy updated.');
    }

    public function updateHttpsAppUrl(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'app_url' => ['required', 'url', 'max:255'],
            'enforce_secure_cookie' => ['nullable', 'boolean'],
        ]);

        $appUrl = trim((string) $data['app_url']);
        if (! str_starts_with(strtolower($appUrl), 'https://')) {
            return back()->withErrors([
                'https_app_url' => 'APP_URL must start with https:// to enforce HTTPS.',
            ])->withInput();
        }

        $secureCookie = (bool) ($data['enforce_secure_cookie'] ?? false);
        $envPath = base_path('.env');
        $appUrlUpdated = $this->upsertEnvValue($envPath, 'APP_URL', $appUrl);
        $cookieUpdated = $this->upsertEnvBoolean($envPath, 'SESSION_SECURE_COOKIE', $secureCookie);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'security.enforce_https_app_url'],
            ['value' => ['value' => true], 'updated_by' => $request->user()?->id]
        );

        if ($appUrlUpdated || $cookieUpdated) {
            Artisan::call('config:clear');
        }

        $auditLogger->log(
            'settings.https_app_url.update.web',
            'control_plane_settings',
            'security.enforce_https_app_url',
            null,
            [
                'app_url' => $appUrl,
                'session_secure_cookie' => $secureCookie,
                'env_app_url_updated' => $appUrlUpdated,
                'env_session_secure_cookie_updated' => $cookieUpdated,
            ],
            $request->user()?->id
        );

        return back()->with('status', 'HTTPS app URL policy updated.');
    }

    public function updateEnvironmentPosture(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'app_env' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'disable_debug_mode' => ['nullable', 'boolean'],
            'secure_session_cookies' => ['nullable', 'boolean'],
        ]);

        $appEnv = strtolower(trim((string) $data['app_env']));
        $disableDebugMode = (bool) ($data['disable_debug_mode'] ?? false);
        $secureSessionCookies = (bool) ($data['secure_session_cookies'] ?? false);
        $appDebugEnabled = ! $disableDebugMode;

        $envPath = base_path('.env');
        $appEnvUpdated = $this->upsertEnvValue($envPath, 'APP_ENV', $appEnv);
        $debugUpdated = $this->upsertEnvBoolean($envPath, 'APP_DEBUG', $appDebugEnabled);
        $secureCookieUpdated = $this->upsertEnvBoolean($envPath, 'SESSION_SECURE_COOKIE', $secureSessionCookies);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'security.environment_posture'],
            ['value' => ['value' => [
                'app_env' => $appEnv,
                'disable_debug_mode' => $disableDebugMode,
                'secure_session_cookies' => $secureSessionCookies,
            ]], 'updated_by' => $request->user()?->id]
        );

        if ($appEnvUpdated || $debugUpdated || $secureCookieUpdated) {
            Artisan::call('config:clear');
        }

        $auditLogger->log(
            'settings.environment_posture.update.web',
            'control_plane_settings',
            'security.environment_posture',
            null,
            [
                'app_env' => $appEnv,
                'disable_debug_mode' => $disableDebugMode,
                'secure_session_cookies' => $secureSessionCookies,
                'env_app_env_updated' => $appEnvUpdated,
                'env_app_debug_updated' => $debugUpdated,
                'env_session_secure_cookie_updated' => $secureCookieUpdated,
            ],
            $request->user()?->id
        );

        return back()->with('status', 'Environment posture updated.');
    }

    public function createRole(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9._-]+$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['uuid', 'exists:permissions,id'],
        ]);

        $exists = Role::query()
            ->whereNull('tenant_id')
            ->where('slug', $data['slug'])
            ->exists();
        if ($exists) {
            return back()->withErrors(['access' => 'Role slug already exists.'])->withInput();
        }

        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
        ]);

        $role->permissions()->sync($data['permission_ids'] ?? []);

        $auditLogger->log('role.create.web', 'role', $role->id, null, [
            'name' => $role->name,
            'slug' => $role->slug,
            'permission_count' => count($data['permission_ids'] ?? []),
        ], $request->user()?->id);

        return back()->with('status', 'Role created.');
    }

    public function updateRolePermissions(Request $request, string $roleId, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['uuid', 'exists:permissions,id'],
        ]);

        $role = Role::query()->findOrFail($roleId);
        $before = $role->permissions()->pluck('permissions.id')->toArray();
        $role->permissions()->sync($data['permission_ids'] ?? []);
        $after = $role->permissions()->pluck('permissions.id')->toArray();

        $auditLogger->log('role.permissions.update.web', 'role', $role->id, [
            'permission_ids' => $before,
        ], [
            'permission_ids' => $after,
        ], $request->user()?->id);

        return back()->with('status', 'Role permissions updated.');
    }

    public function assignUserRoles(Request $request, int $userId, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['uuid', 'exists:roles,id'],
        ]);

        $user = User::query()->findOrFail($userId);
        $before = $user->roles()->pluck('roles.id')->toArray();
        $user->roles()->sync($data['role_ids'] ?? []);
        $after = $user->roles()->pluck('roles.id')->toArray();

        $auditLogger->log('user.roles.assign.web', 'user', (string) $user->id, [
            'role_ids' => $before,
        ], [
            'role_ids' => $after,
        ], $request->user()?->id);

        return back()->with('status', 'User roles updated.');
    }

    public function deleteRole(Request $request, string $roleId, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $role = Role::query()->findOrFail($roleId);
        if ($role->slug === 'super-admin') {
            return back()->withErrors(['access' => 'super-admin role cannot be deleted.']);
        }

        $before = $role->toArray();
        $role->permissions()->detach();
        \DB::table('role_user')->where('role_id', $role->id)->delete();
        $role->delete();

        $auditLogger->log('role.delete.web', 'role', $roleId, $before, null, $request->user()?->id);

        return back()->with('status', 'Role deleted.');
    }

    public function createStaffUser(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSuperAdminAccess();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['uuid', 'exists:roles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => strtolower((string) $data['email']),
            'password' => $data['password'],
            'tenant_id' => null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $user->roles()->sync($data['role_ids'] ?? []);

        $auditLogger->log('user.create.staff.web', 'user', (string) $user->id, null, [
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role_ids' => $data['role_ids'] ?? [],
        ], $request->user()?->id);

        return back()->with('status', 'Staff account created and roles assigned.');
    }

    public function docs(): View
    {
        return view('admin.docs', [
            'functionsGuide' => $this->readDocFile(base_path('docs/FUNCTIONS_GUIDE.md')),
            'operationsRunbook' => $this->readDocFile(base_path('docs/runbooks/operations.md')),
            'architectureDoc' => $this->readDocFile(base_path('docs/architecture/architecture.md')),
            'docsPolicy' => $this->readDocFile(base_path('docs/DOCS_MAINTENANCE_POLICY.md')),
        ]);
    }

    public function notes(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $notesQuery = AdminNote::query()->with('author:id,name')->orderByDesc('is_pinned')->latest('updated_at');
        if ($search !== '') {
            $notesQuery->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('title', 'like', $like)->orWhere('body', 'like', $like);
            });
        }

        return view('admin.notes', [
            'notes' => $notesQuery->paginate(20)->withQueryString(),
            'searchQuery' => $search,
        ]);
    }

    public function createNote(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:20000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $note = AdminNote::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'user_id' => $request->user()?->id,
            'title' => trim((string) $data['title']),
            'body' => trim((string) $data['body']),
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
        ]);

        $auditLogger->log('admin_note.create.web', 'admin_note', $note->id, null, $note->toArray(), $request->user()?->id);

        return back()->with('status', 'Note created.');
    }

    public function updateNote(Request $request, string $noteId, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:20000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $note = AdminNote::query()->findOrFail($noteId);
        $before = $note->toArray();
        $note->update([
            'title' => trim((string) $data['title']),
            'body' => trim((string) $data['body']),
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
        ]);

        $auditLogger->log('admin_note.update.web', 'admin_note', $note->id, $before, $note->fresh()?->toArray(), $request->user()?->id);

        return back()->with('status', 'Note updated.');
    }

    public function deleteNote(Request $request, string $noteId, AuditLogger $auditLogger): RedirectResponse
    {
        $note = AdminNote::query()->findOrFail($noteId);
        $before = $note->toArray();
        $note->delete();

        $auditLogger->log('admin_note.delete.web', 'admin_note', $noteId, $before, null, $request->user()?->id);

        return back()->with('status', 'Note deleted.');
    }

    public function packageWindowsStoreIcon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $name = trim((string) ($data['name'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));

        if ($name === '' && $slug === '') {
            return response()->json(['icon_url' => null], 404);
        }

        $cacheKey = 'packages.windows_store_icon.'.sha1(strtolower($name.'|'.$slug));
        $resolved = Cache::remember($cacheKey, now()->addHours(12), function () use ($name, $slug) {
            return $this->resolveWindowsStoreIcon($name, $slug !== '' ? $slug : null);
        });

        if (! is_array($resolved) || ! is_string($resolved['icon_url'] ?? null) || trim((string) $resolved['icon_url']) === '') {
            return response()->json(['icon_url' => null], 404);
        }

        return response()->json([
            'icon_url' => (string) $resolved['icon_url'],
            'product_id' => (string) ($resolved['product_id'] ?? ''),
            'title' => (string) ($resolved['title'] ?? ''),
        ]);
    }

    public function packageSha256FromUri(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_uri' => ['required', 'url', 'max:2000'],
        ]);

        try {
            $resolved = $this->computeRemoteArtifactSha256((string) $data['source_uri']);
            return response()->json($resolved);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Unable to fetch SHA256 from Source URI: '.$e->getMessage(),
            ], 422);
        }
    }

    public function gettingStarted(): View
    {
        return view('admin.getting-started');
    }

    private function settingInt(string $key, int $default): int
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        return (int) ($setting->value['value'] ?? $default);
    }

    private function settingBool(string $key, bool $default): bool
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        return (bool) ($setting->value['value'] ?? $default);
    }

    private function settingArray(string $key, array $default): array
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        return is_array($value) ? $value : $default;
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

    private function computeRemoteArtifactSha256(string $url): array
    {
        $response = Http::withOptions([
            'stream' => true,
            'allow_redirects' => ['max' => 5, 'strict' => false, 'referer' => true],
        ])->timeout(180)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $stream = $response->toPsrResponse()->getBody();
        $ctx = hash_init('sha256');
        $bytes = 0;

        while (! $stream->eof()) {
            $chunk = $stream->read(1024 * 1024);
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            hash_update($ctx, $chunk);
        }

        return [
            'sha256' => hash_final($ctx),
            'size_bytes' => $bytes,
            'content_type' => (string) ($response->header('Content-Type') ?? ''),
            'source_uri' => $url,
        ];
    }

    private function brandingDefaults(): array
    {
        return [
            'project_name' => 'DMS Admin',
            'project_tagline' => 'Centralized control for Windows fleet operations',
            'primary_color' => '#0EA5E9',
            'accent_color' => '#F97316',
            'background_color' => '#F1F5F9',
            'sidebar_tint' => '#FFFFFF',
            'border_radius_px' => 12,
            'logo_url' => null,
            'favicon_url' => null,
        ];
    }

    private function brandingSettings(): array
    {
        $defaults = $this->brandingDefaults();
        $stored = $this->settingArray('ui.branding', []);
        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    private function policyRemovalRulesForVersion(string $policyVersionId): array
    {
        $profiles = collect($this->settingArray('policies.removal_profiles', []))
            ->filter(fn ($row) => is_array($row))
            ->values();

        $profile = $profiles->first(fn ($row) => (string) ($row['policy_version_id'] ?? '') === $policyVersionId);
        if (! is_array($profile)) {
            return [];
        }

        $rules = $profile['rules'] ?? [];
        return is_array($rules) ? array_values(array_filter($rules, fn ($rule) => is_array($rule))) : [];
    }

    private function backfillPolicyRemovalProfiles(?int $updatedBy): int
    {
        $profiles = collect($this->settingArray('policies.removal_profiles', []))
            ->filter(fn ($row) => is_array($row))
            ->values();
        $existing = $profiles
            ->mapWithKeys(fn ($row) => [(string) ($row['policy_version_id'] ?? '') => true])
            ->all();

        $rules = PolicyRule::query()
            ->orderBy('order_index')
            ->get(['policy_version_id', 'rule_type', 'rule_config'])
            ->groupBy('policy_version_id');

        $added = 0;
        foreach ($rules as $policyVersionId => $versionRules) {
            $policyVersionId = (string) $policyVersionId;
            if ($policyVersionId === '' || isset($existing[$policyVersionId])) {
                continue;
            }

            $removeRules = [];
            foreach ($versionRules as $rule) {
                $removeRules = array_merge(
                    $removeRules,
                    $this->buildRemovalRulesForPolicyRule(
                        (string) ($rule->rule_type ?? ''),
                        is_array($rule->rule_config) ? $rule->rule_config : []
                    )
                );
            }

            $profiles->push([
                'policy_version_id' => $policyVersionId,
                'rules' => array_values(array_filter($removeRules, fn ($rule) => is_array($rule))),
                'updated_at' => now()->toIso8601String(),
            ]);
            $existing[$policyVersionId] = true;
            $added++;
        }

        if ($added > 0) {
            $this->saveSettingArray('policies.removal_profiles', $profiles->values()->all(), $updatedBy);
        }

        return $added;
    }

    private function upsertPolicyRemovalProfile(string $policyVersionId, string $ruleType, array $ruleConfig, ?int $updatedBy, ?array $overrideRules = null): void
    {
        $removeRules = is_array($overrideRules)
            ? array_values(array_filter($overrideRules, fn ($rule) => is_array($rule)))
            : $this->buildRemovalRulesForPolicyRule($ruleType, $ruleConfig);
        $profiles = collect($this->settingArray('policies.removal_profiles', []))
            ->filter(fn ($row) => is_array($row))
            ->values();

        $row = [
            'policy_version_id' => $policyVersionId,
            'rules' => $removeRules,
            'updated_at' => now()->toIso8601String(),
        ];

        $index = $profiles->search(fn ($item) => (string) ($item['policy_version_id'] ?? '') === $policyVersionId);
        if ($index === false) {
            $profiles->push($row);
        } else {
            $profiles->put($index, $row);
        }

        $this->saveSettingArray('policies.removal_profiles', $profiles->values()->all(), $updatedBy);
    }

    private function deletePolicyRemovalProfile(string $policyVersionId, ?int $updatedBy): void
    {
        $profiles = collect($this->settingArray('policies.removal_profiles', []))
            ->filter(fn ($row) => is_array($row))
            ->reject(fn ($row) => (string) ($row['policy_version_id'] ?? '') === $policyVersionId)
            ->values();

        $this->saveSettingArray('policies.removal_profiles', $profiles->all(), $updatedBy);
    }

    private function buildRemovalRulesForPolicyRule(string $ruleType, array $ruleConfig): array
    {
        $type = strtolower(trim($ruleType));
        if ($type === 'baseline_profile') {
            $removeRules = $ruleConfig['remove_rules'] ?? [];
            if (! is_array($removeRules)) {
                return [];
            }

            return collect($removeRules)
                ->filter(fn ($rule) => is_array($rule))
                ->map(function (array $rule) {
                    $removeType = strtolower(trim((string) ($rule['type'] ?? '')));
                    $removeConfig = is_array($rule['config'] ?? null) ? $rule['config'] : [];
                    $enforce = array_key_exists('enforce', $rule) ? (bool) $rule['enforce'] : true;
                    if ($removeType === '' || $removeConfig === []) {
                        return null;
                    }

                    return [
                        'type' => $removeType,
                        'config' => $removeConfig,
                        'enforce' => $enforce,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        if ($type === 'registry') {
            $path = trim((string) ($ruleConfig['path'] ?? ''));
            $name = trim((string) ($ruleConfig['name'] ?? ''));
            if ($path === '' || $name === '') {
                return [];
            }

            return [[
                'type' => 'registry',
                'config' => [
                    'path' => $path,
                    'name' => $name,
                    'type' => (string) ($ruleConfig['type'] ?? 'STRING'),
                    'ensure' => 'absent',
                ],
                'enforce' => true,
            ]];
        }

        if ($type === 'local_group') {
            $group = trim((string) ($ruleConfig['group'] ?? 'Administrators'));
            if ($group === '') {
                return [];
            }

            $removeConfig = [
                'group' => $group,
                'ensure' => 'absent',
                'restore_previous' => true,
                'strict_restore' => true,
            ];
            $stateKey = trim((string) ($ruleConfig['state_key'] ?? ''));
            if ($stateKey !== '') {
                $removeConfig['state_key'] = $stateKey;
            }

            return [[
                'type' => 'local_group',
                'config' => $removeConfig,
                'enforce' => true,
            ]];
        }

        if ($type === 'dns') {
            $selectorConfig = $this->copyNetworkSelectorConfig($ruleConfig);
            if ($selectorConfig === []) {
                return [];
            }

            return [[
                'type' => 'dns',
                'config' => $selectorConfig + ['mode' => 'automatic'],
                'enforce' => true,
            ]];
        }

        if ($type === 'network_adapter') {
            $selectorConfig = $this->copyNetworkSelectorConfig($ruleConfig);
            if ($selectorConfig === []) {
                return [];
            }

            return [[
                'type' => 'network_adapter',
                'config' => $selectorConfig + ['ipv4_mode' => 'dhcp'],
                'enforce' => true,
            ]];
        }

        if ($type === 'scheduled_task') {
            $taskName = trim((string) ($ruleConfig['task_name'] ?? ''));
            if ($taskName === '') {
                return [];
            }

            return [[
                'type' => 'scheduled_task',
                'config' => [
                    'task_name' => $taskName,
                    'ensure' => 'absent',
                ],
                'enforce' => true,
            ]];
        }

        if ($type === 'command') {
            $command = trim((string) ($ruleConfig['command'] ?? ''));
            if ($command === '') {
                return [];
            }

            return [[
                'type' => 'command',
                'config' => [
                    'command' => $command,
                ],
                'enforce' => true,
            ]];
        }

        if ($type === 'reboot_restore_mode') {
            return [[
                'type' => 'reboot_restore_mode',
                'config' => [
                    'ensure' => 'absent',
                    'enabled' => false,
                    'persistent' => false,
                    'remove_pending' => true,
                ],
                'enforce' => true,
            ]];
        }

        if ($type === 'uwf') {
            return [$this->buildUwfRemovalCommandRule()];
        }

        return [];
    }

    private function resolveApplyRuleFromRequestData(array $data): array
    {
        $applyMode = strtolower(trim((string) ($data['apply_mode'] ?? 'json')));
        if ($applyMode === 'command') {
            $command = trim((string) ($data['apply_command'] ?? ''));
            if ($command === '') {
                return ['', [], 'Apply command is required when apply mode is command.'];
            }

            $runAs = strtolower(trim((string) ($data['apply_run_as'] ?? 'default')));
            if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
                $runAs = 'default';
            }

            $timeoutSeconds = isset($data['apply_timeout_seconds']) ? (int) $data['apply_timeout_seconds'] : 300;
            $timeoutSeconds = max(30, min(3600, $timeoutSeconds));

            return ['command', [
                'command' => $command,
                'run_as' => $runAs,
                'timeout_seconds' => $timeoutSeconds,
            ], null];
        }

        $ruleType = strtolower(trim((string) ($data['rule_type'] ?? '')));
        if ($ruleType === '') {
            return ['', [], 'Rule type is required in JSON mode.'];
        }

        if ($ruleType === 'uwf') {
            $uwfRuleConfig = $this->buildUwfRuleConfigFromRequestData($data);
            if ($uwfRuleConfig !== null) {
                return ['uwf', $uwfRuleConfig, null];
            }
        }

        $ruleJson = (string) ($data['rule_json'] ?? '');
        if (trim($ruleJson) === '') {
            return ['', [], 'Rule JSON is required in JSON mode.'];
        }

        try {
            $ruleConfig = json_decode($ruleJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['', [], 'Rule JSON is invalid.'];
        }

        if (! is_array($ruleConfig)) {
            return ['', [], 'Rule JSON must be a JSON object.'];
        }

        return [$ruleType, $ruleConfig, null];
    }

    private function buildUwfRuleConfigFromRequestData(array $data): ?array
    {
        $uwfKeys = [
            'apply_uwf_ensure',
            'apply_uwf_enable_feature',
            'apply_uwf_enable_filter',
            'apply_uwf_protect_volume',
            'apply_uwf_volume',
            'apply_uwf_reboot_now',
            'apply_uwf_reboot_if_pending',
            'apply_uwf_max_reboot_attempts',
            'apply_uwf_reboot_cooldown_minutes',
            'apply_uwf_reboot_command',
            'apply_uwf_file_exclusions',
            'apply_uwf_registry_exclusions',
            'apply_uwf_fail_on_unsupported_edition',
            'apply_uwf_overlay_type',
            'apply_uwf_overlay_max_size_mb',
            'apply_uwf_overlay_warning_threshold_mb',
            'apply_uwf_overlay_critical_threshold_mb',
        ];

        $hasUwfInputs = false;
        foreach ($uwfKeys as $key) {
            if (array_key_exists($key, $data)) {
                $hasUwfInputs = true;
                break;
            }
        }
        if (! $hasUwfInputs) {
            return null;
        }

        $config = [
            'ensure' => strtolower(trim((string) ($data['apply_uwf_ensure'] ?? 'present'))) === 'absent' ? 'absent' : 'present',
            'enable_feature' => (bool) ($data['apply_uwf_enable_feature'] ?? true),
            'enable_filter' => (bool) ($data['apply_uwf_enable_filter'] ?? true),
            'protect_volume' => (bool) ($data['apply_uwf_protect_volume'] ?? true),
            'volume' => trim((string) ($data['apply_uwf_volume'] ?? 'C:')),
            'reboot_now' => (bool) ($data['apply_uwf_reboot_now'] ?? false),
            'reboot_if_pending' => (bool) ($data['apply_uwf_reboot_if_pending'] ?? true),
            'max_reboot_attempts' => isset($data['apply_uwf_max_reboot_attempts']) ? (int) $data['apply_uwf_max_reboot_attempts'] : 2,
            'reboot_cooldown_minutes' => isset($data['apply_uwf_reboot_cooldown_minutes']) ? (int) $data['apply_uwf_reboot_cooldown_minutes'] : 30,
        ];

        if ($config['volume'] === '') {
            $config['volume'] = 'C:';
        }

        $rebootCommand = trim((string) ($data['apply_uwf_reboot_command'] ?? ''));
        if ($rebootCommand !== '') {
            $config['reboot_command'] = $rebootCommand;
        }

        $fileExclusions = $this->parseMultilineListInput((string) ($data['apply_uwf_file_exclusions'] ?? ''));
        if ($fileExclusions !== []) {
            $config['file_exclusions'] = $fileExclusions;
        }

        $registryExclusions = $this->parseMultilineListInput((string) ($data['apply_uwf_registry_exclusions'] ?? ''));
        if ($registryExclusions !== []) {
            $config['registry_exclusions'] = $registryExclusions;
        }

        if ((bool) ($data['apply_uwf_fail_on_unsupported_edition'] ?? false)) {
            $config['fail_on_unsupported_edition'] = true;
        }

        $overlayType = strtolower(trim((string) ($data['apply_uwf_overlay_type'] ?? '')));
        if (in_array($overlayType, ['ram', 'disk'], true)) {
            $config['overlay_type'] = $overlayType;
        }

        if (isset($data['apply_uwf_overlay_max_size_mb']) && (string) $data['apply_uwf_overlay_max_size_mb'] !== '') {
            $config['overlay_max_size_mb'] = max(128, min(1048576, (int) $data['apply_uwf_overlay_max_size_mb']));
        }

        if (isset($data['apply_uwf_overlay_warning_threshold_mb']) && (string) $data['apply_uwf_overlay_warning_threshold_mb'] !== '') {
            $config['overlay_warning_threshold_mb'] = max(64, min(1048576, (int) $data['apply_uwf_overlay_warning_threshold_mb']));
        }

        if (isset($data['apply_uwf_overlay_critical_threshold_mb']) && (string) $data['apply_uwf_overlay_critical_threshold_mb'] !== '') {
            $config['overlay_critical_threshold_mb'] = max(64, min(1048576, (int) $data['apply_uwf_overlay_critical_threshold_mb']));
        }

        return $config;
    }

    private function parseMultilineListInput(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $values = collect($parts)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->values()
            ->all();

        return array_values(array_unique($values));
    }

    private function resolveRemoveRulesFromRequestData(array $data, string $applyRuleType, array $applyRuleConfig): array
    {
        $removeMode = strtolower(trim((string) ($data['remove_mode'] ?? 'auto')));
        if ($removeMode === 'auto') {
            return [$this->buildRemovalRulesForPolicyRule($applyRuleType, $applyRuleConfig), null];
        }

        if ($removeMode === 'command') {
            $command = trim((string) ($data['remove_command'] ?? ''));
            if ($command === '') {
                return [[], 'Remove command is required when remove mode is command.'];
            }

            return [[
                [
                    'type' => 'command',
                    'config' => ['command' => $command],
                    'enforce' => true,
                ],
            ], null];
        }

        $removeRuleType = strtolower(trim((string) ($data['remove_rule_type'] ?? $applyRuleType)));
        if ($removeRuleType === '') {
            return [[], 'Remove rule type is required in JSON mode.'];
        }

        $removeRuleJson = (string) ($data['remove_rule_json'] ?? '');
        if (trim($removeRuleJson) === '') {
            return [[], 'Remove rule JSON is required in JSON mode.'];
        }

        try {
            $removeRuleConfig = json_decode($removeRuleJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], 'Remove rule JSON is invalid.'];
        }

        if (! is_array($removeRuleConfig)) {
            return [[], 'Remove rule JSON must be a JSON object.'];
        }

        $removeValidationError = $this->validateRuleConfig($removeRuleType, $removeRuleConfig);
        if ($removeValidationError !== null) {
            return [[], 'Remove rule invalid: '.$removeValidationError];
        }

        return [[
            [
                'type' => $removeRuleType,
                'config' => $removeRuleConfig,
                'enforce' => true,
            ],
        ], null];
    }

    private function resolveCatalogRemoveRules(array $data, string $applyRuleType, array $applyRuleConfig): array
    {
        $removeMode = strtolower(trim((string) ($data['remove_mode'] ?? 'auto')));
        if ($removeMode === '') {
            $removeMode = 'auto';
        }

        if ($removeMode === 'auto') {
            return ['auto', $this->buildRemovalRulesForPolicyRule($applyRuleType, $applyRuleConfig), null];
        }

        if ($removeMode === 'command') {
            $command = trim((string) ($data['remove_command'] ?? ''));
            if ($command === '') {
                return ['command', [], 'Remove command is required when remove mode is command.'];
            }

            return ['command', [[
                'type' => 'command',
                'config' => ['command' => $command],
                'enforce' => true,
            ]], null];
        }

        $removeRuleType = strtolower(trim((string) ($data['remove_rule_type'] ?? $applyRuleType)));
        if ($removeRuleType === '') {
            return ['json', [], 'Remove rule type is required when remove mode is json.'];
        }

        $removeRuleJson = (string) ($data['remove_rule_json'] ?? '');
        if (trim($removeRuleJson) === '') {
            return ['json', [], 'Remove rule JSON is required when remove mode is json.'];
        }

        try {
            $removeRuleConfig = json_decode($removeRuleJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['json', [], 'Remove rule JSON is invalid.'];
        }

        if (! is_array($removeRuleConfig)) {
            return ['json', [], 'Remove rule JSON must be a JSON object.'];
        }

        $removeValidationError = $this->validateRuleConfig($removeRuleType, $removeRuleConfig);
        if ($removeValidationError !== null) {
            return ['json', [], 'Remove rule invalid: '.$removeValidationError];
        }

        return ['json', [[
            'type' => $removeRuleType,
            'config' => $removeRuleConfig,
            'enforce' => true,
        ]], null];
    }

    private function validateRuleConfig(string $ruleType, array $config): ?string
    {
        return match ($ruleType) {
            'registry' => $this->validateRegistryRuleConfig($config),
            'firewall' => $this->validateFirewallRuleConfig($config),
            'dns' => $this->validateDnsRuleConfig($config),
            'network_adapter' => $this->validateNetworkAdapterRuleConfig($config),
            'bitlocker' => $this->validateBitlockerRuleConfig($config),
            'local_group' => $this->validateLocalGroupRuleConfig($config),
            'windows_update' => $this->validateWindowsUpdateRuleConfig($config),
            'scheduled_task' => $this->validateScheduledTaskRuleConfig($config),
            'command' => $this->validateCommandRuleConfig($config),
            'baseline_profile' => $this->validateBaselineProfileRuleConfig($config),
            'reboot_restore_mode' => $this->validateRebootRestoreModeRuleConfig($config),
            'uwf' => $this->validateUwfRuleConfig($config),
            default => 'Unsupported rule type.',
        };
    }

    private function validateBaselineProfileRuleConfig(array $config): ?string
    {
        $listChecks = [
            'critical_files',
            'registry_values',
            'services',
            'installed_packages',
            'remediation_rules',
            'remove_rules',
        ];
        foreach ($listChecks as $listKey) {
            if (array_key_exists($listKey, $config) && ! is_array($config[$listKey])) {
                return sprintf('Baseline profile "%s" must be an array.', $listKey);
            }
        }

        if (array_key_exists('auto_remediate', $config) && ! is_bool($config['auto_remediate'])) {
            return 'Baseline profile "auto_remediate" must be true/false.';
        }

        foreach (($config['critical_files'] ?? []) as $idx => $fileCheck) {
            if (! is_array($fileCheck)) {
                return sprintf('Baseline critical_files[%d] must be an object.', $idx);
            }
            $path = trim((string) ($fileCheck['path'] ?? ''));
            if ($path === '') {
                return sprintf('Baseline critical_files[%d] requires non-empty "path".', $idx);
            }
            if (array_key_exists('sha256', $fileCheck)) {
                $sha = strtolower(trim((string) $fileCheck['sha256']));
                if ($sha !== '' && ! preg_match('/^[a-f0-9]{64}$/', $sha)) {
                    return sprintf('Baseline critical_files[%d].sha256 must be 64 hex chars.', $idx);
                }
            }
            if (array_key_exists('exists', $fileCheck) && ! is_bool($fileCheck['exists'])) {
                return sprintf('Baseline critical_files[%d].exists must be true/false.', $idx);
            }
        }

        foreach (($config['registry_values'] ?? []) as $idx => $regCheck) {
            if (! is_array($regCheck)) {
                return sprintf('Baseline registry_values[%d] must be an object.', $idx);
            }
            $error = $this->validateRegistryRuleConfig($regCheck);
            if ($error !== null) {
                return sprintf('Baseline registry_values[%d] invalid: %s', $idx, $error);
            }
        }

        foreach (($config['services'] ?? []) as $idx => $serviceCheck) {
            if (! is_array($serviceCheck)) {
                return sprintf('Baseline services[%d] must be an object.', $idx);
            }
            $name = trim((string) ($serviceCheck['name'] ?? ''));
            if ($name === '') {
                return sprintf('Baseline services[%d] requires non-empty "name".', $idx);
            }
            if (array_key_exists('status', $serviceCheck)) {
                $status = strtolower(trim((string) $serviceCheck['status']));
                if (! in_array($status, ['running', 'stopped', 'paused'], true)) {
                    return sprintf('Baseline services[%d].status must be running, stopped, or paused.', $idx);
                }
            }
            if (array_key_exists('start_mode', $serviceCheck)) {
                $startMode = strtolower(trim((string) $serviceCheck['start_mode']));
                if (! in_array($startMode, ['auto', 'automatic', 'manual', 'disabled', 'delayed-auto'], true)) {
                    return sprintf('Baseline services[%d].start_mode must be auto/automatic/manual/disabled/delayed-auto.', $idx);
                }
            }
            if (array_key_exists('ensure', $serviceCheck)) {
                $ensure = strtolower(trim((string) $serviceCheck['ensure']));
                if (! in_array($ensure, ['present', 'absent'], true)) {
                    return sprintf('Baseline services[%d].ensure must be present or absent.', $idx);
                }
            }
        }

        foreach (($config['installed_packages'] ?? []) as $idx => $pkgCheck) {
            if (! is_array($pkgCheck)) {
                return sprintf('Baseline installed_packages[%d] must be an object.', $idx);
            }
            $name = trim((string) ($pkgCheck['name'] ?? ''));
            if ($name === '') {
                return sprintf('Baseline installed_packages[%d] requires non-empty "name".', $idx);
            }
            if (array_key_exists('ensure', $pkgCheck)) {
                $ensure = strtolower(trim((string) $pkgCheck['ensure']));
                if (! in_array($ensure, ['present', 'absent'], true)) {
                    return sprintf('Baseline installed_packages[%d].ensure must be present or absent.', $idx);
                }
            }
            if (array_key_exists('match', $pkgCheck)) {
                $matchMode = strtolower(trim((string) $pkgCheck['match']));
                if (! in_array($matchMode, ['contains', 'exact'], true)) {
                    return sprintf('Baseline installed_packages[%d].match must be contains or exact.', $idx);
                }
            }
        }

        $nestedChecks = [
            'remediation_rules' => 'remediation rule',
            'remove_rules' => 'remove rule',
        ];
        foreach ($nestedChecks as $nestedKey => $label) {
            foreach (($config[$nestedKey] ?? []) as $idx => $nestedRule) {
                if (! is_array($nestedRule)) {
                    return sprintf('Baseline %s[%d] must be an object.', $nestedKey, $idx);
                }
                $nestedType = strtolower(trim((string) ($nestedRule['type'] ?? '')));
                if ($nestedType === '' || in_array($nestedType, ['baseline_profile', 'reboot_restore_mode'], true)) {
                    return sprintf('Baseline %s[%d] has invalid nested rule type.', $nestedKey, $idx);
                }
                $nestedConfig = is_array($nestedRule['config'] ?? null) ? $nestedRule['config'] : [];
                if ($nestedConfig === []) {
                    return sprintf('Baseline %s[%d] requires non-empty config.', $nestedKey, $idx);
                }
                $nestedError = $this->validateRuleConfig($nestedType, $nestedConfig);
                if ($nestedError !== null) {
                    return sprintf('Baseline %s[%d] invalid: %s', $nestedKey, $idx, $nestedError);
                }
            }
        }

        return null;
    }

    private function validateRebootRestoreModeRuleConfig(array $config): ?string
    {
        if (array_key_exists('profile', $config)) {
            $profile = strtolower(trim((string) $config['profile']));
            if (! in_array($profile, ['lab_fast', 'deepfreeze_fast', 'school_fast'], true)) {
                return 'Reboot restore mode "profile" must be one of: lab_fast, deepfreeze_fast, school_fast.';
            }
        }
        if (array_key_exists('enabled', $config) && ! is_bool($config['enabled'])) {
            return 'Reboot restore mode "enabled" must be true/false.';
        }
        if (array_key_exists('persistent', $config) && ! is_bool($config['persistent'])) {
            return 'Reboot restore mode "persistent" must be true/false.';
        }
        if (array_key_exists('remove_pending', $config) && ! is_bool($config['remove_pending'])) {
            return 'Reboot restore mode "remove_pending" must be true/false.';
        }
        if (array_key_exists('reboot_now', $config) && ! is_bool($config['reboot_now'])) {
            return 'Reboot restore mode "reboot_now" must be true/false.';
        }
        foreach (['clean_downloads', 'clean_desktop', 'clean_documents', 'clean_user_temp', 'clean_windows_temp', 'clean_recycle_bin', 'clean_dms_staging'] as $boolKey) {
            if (array_key_exists($boolKey, $config) && ! is_bool($config[$boolKey])) {
                return sprintf('Reboot restore mode "%s" must be true/false.', $boolKey);
            }
        }

        $ensure = strtolower(trim((string) ($config['ensure'] ?? 'present')));
        if (! in_array($ensure, ['present', 'absent'], true)) {
            return 'Reboot restore mode "ensure" must be present or absent.';
        }

        if (array_key_exists('reboot_command', $config) && trim((string) $config['reboot_command']) === '') {
            return 'Reboot restore mode "reboot_command" cannot be empty when provided.';
        }

        $manifest = null;
        if (isset($config['manifest'])) {
            if (! is_array($config['manifest'])) {
                return 'Reboot restore mode "manifest" must be a JSON object.';
            }
            $manifest = $config['manifest'];
        } else {
            $manifest = [];
            foreach (['steps', 'restore_steps', 'cleanup_paths'] as $key) {
                if (array_key_exists($key, $config)) {
                    $manifest[$key] = $config[$key];
                }
            }
        }

        foreach (['steps', 'restore_steps', 'cleanup_paths'] as $listKey) {
            if (array_key_exists($listKey, $manifest) && ! is_array($manifest[$listKey])) {
                return sprintf('Reboot restore mode "%s" must be an array.', $listKey);
            }
        }

        foreach (($manifest['cleanup_paths'] ?? []) as $idx => $path) {
            if (! is_string($path) || trim($path) === '') {
                return sprintf('Reboot restore mode cleanup_paths[%d] must be a non-empty string.', $idx);
            }
        }

        foreach (['steps', 'restore_steps'] as $stepsKey) {
            foreach (($manifest[$stepsKey] ?? []) as $idx => $step) {
                if (! is_array($step)) {
                    return sprintf('Reboot restore mode %s[%d] must be an object.', $stepsKey, $idx);
                }
                $type = strtolower(trim((string) ($step['type'] ?? '')));
                if ($type === '') {
                    $type = array_key_exists('script', $step) ? 'shell' : 'process';
                }
                if (! in_array($type, ['shell', 'run_command', 'process', 'command', 'delete_path'], true)) {
                    return sprintf('Reboot restore mode %s[%d] has unsupported type.', $stepsKey, $idx);
                }
                if (in_array($type, ['shell', 'run_command'], true) && trim((string) ($step['script'] ?? '')) === '') {
                    return sprintf('Reboot restore mode %s[%d] shell step requires non-empty "script".', $stepsKey, $idx);
                }
                if (in_array($type, ['process', 'command'], true) && trim((string) ($step['path'] ?? '')) === '') {
                    return sprintf('Reboot restore mode %s[%d] process step requires non-empty "path".', $stepsKey, $idx);
                }
                if ($type === 'delete_path' && trim((string) ($step['path'] ?? '')) === '') {
                    return sprintf('Reboot restore mode %s[%d] delete_path step requires non-empty "path".', $stepsKey, $idx);
                }
            }
        }

        $enabled = ! array_key_exists('enabled', $config) || (bool) $config['enabled'];
        $persistent = ! array_key_exists('persistent', $config) || (bool) $config['persistent'];
        if ($ensure === 'present' && $enabled && $persistent) {
            $hasActions = ! empty($manifest['cleanup_paths'] ?? [])
                || ! empty($manifest['steps'] ?? [])
                || ! empty($manifest['restore_steps'] ?? []);
            $hasProfile = in_array(strtolower(trim((string) ($config['profile'] ?? ''))), ['lab_fast', 'deepfreeze_fast', 'school_fast'], true);
            if (! $hasActions && ! $hasProfile) {
                return 'Reboot restore mode requires at least one action in manifest (steps, restore_steps, or cleanup_paths).';
            }
        }

        return null;
    }

    private function validateUwfRuleConfig(array $config): ?string
    {
        $ensure = strtolower(trim((string) ($config['ensure'] ?? 'present')));
        if (! in_array($ensure, ['present', 'absent'], true)) {
            return 'UWF "ensure" must be present or absent.';
        }

        foreach (['enable_feature', 'enable_filter', 'protect_volume', 'reboot_now', 'dry_run', 'reboot_if_pending'] as $boolKey) {
            if (array_key_exists($boolKey, $config) && ! is_bool($config[$boolKey])) {
                return sprintf('UWF "%s" must be true/false.', $boolKey);
            }
        }

        if (array_key_exists('max_reboot_attempts', $config)) {
            if (! is_int($config['max_reboot_attempts'])) {
                return 'UWF "max_reboot_attempts" must be an integer.';
            }
            if ($config['max_reboot_attempts'] < 1 || $config['max_reboot_attempts'] > 10) {
                return 'UWF "max_reboot_attempts" must be between 1 and 10.';
            }
        }

        if (array_key_exists('reboot_cooldown_minutes', $config)) {
            if (! is_int($config['reboot_cooldown_minutes'])) {
                return 'UWF "reboot_cooldown_minutes" must be an integer.';
            }
            if ($config['reboot_cooldown_minutes'] < 1 || $config['reboot_cooldown_minutes'] > 240) {
                return 'UWF "reboot_cooldown_minutes" must be between 1 and 240.';
            }
        }

        if (array_key_exists('volume', $config)) {
            $volume = trim((string) $config['volume']);
            if ($volume === '') {
                return 'UWF "volume" cannot be empty when provided.';
            }
        }

        if (array_key_exists('reboot_command', $config)) {
            $rebootCommand = trim((string) $config['reboot_command']);
            if ($rebootCommand === '') {
                return 'UWF "reboot_command" cannot be empty when provided.';
            }
            if (! $this->isValidUwfRebootCommand($rebootCommand)) {
                return 'UWF "reboot_command" is invalid. Use a valid reboot command (example: shutdown.exe /r /t 0 or shutdown.exe /r /t 30 /c "Enabling UWF protection").';
            }
        }

        foreach (['file_exclusions', 'registry_exclusions'] as $listKey) {
            if (! array_key_exists($listKey, $config)) {
                continue;
            }
            if (! is_array($config[$listKey])) {
                return sprintf('UWF "%s" must be an array of non-empty strings.', $listKey);
            }
            foreach ($config[$listKey] as $idx => $item) {
                if (! is_string($item) || trim($item) === '') {
                    return sprintf('UWF "%s[%d]" must be a non-empty string.', $listKey, $idx);
                }
            }
        }

        if (array_key_exists('fail_on_unsupported_edition', $config) && ! is_bool($config['fail_on_unsupported_edition'])) {
            return 'UWF "fail_on_unsupported_edition" must be true/false.';
        }

        if (array_key_exists('overlay_type', $config)) {
            $overlayType = strtolower(trim((string) $config['overlay_type']));
            if (! in_array($overlayType, ['ram', 'disk'], true)) {
                return 'UWF "overlay_type" must be ram or disk.';
            }
        }

        foreach ([
            'overlay_max_size_mb' => ['min' => 128, 'max' => 1048576],
            'overlay_warning_threshold_mb' => ['min' => 64, 'max' => 1048576],
            'overlay_critical_threshold_mb' => ['min' => 64, 'max' => 1048576],
        ] as $overlayKey => $range) {
            if (! array_key_exists($overlayKey, $config)) {
                continue;
            }
            if (! is_int($config[$overlayKey])) {
                return sprintf('UWF "%s" must be an integer.', $overlayKey);
            }
            if ($config[$overlayKey] < $range['min'] || $config[$overlayKey] > $range['max']) {
                return sprintf('UWF "%s" must be between %d and %d.', $overlayKey, $range['min'], $range['max']);
            }
        }

        $overlayWarn = array_key_exists('overlay_warning_threshold_mb', $config) ? (int) $config['overlay_warning_threshold_mb'] : null;
        $overlayCritical = array_key_exists('overlay_critical_threshold_mb', $config) ? (int) $config['overlay_critical_threshold_mb'] : null;
        if ($overlayWarn !== null && $overlayCritical !== null && $overlayCritical <= $overlayWarn) {
            return 'UWF "overlay_critical_threshold_mb" must be greater than "overlay_warning_threshold_mb".';
        }

        return null;
    }

    private function isValidUwfRebootCommand(string $command): bool
    {
        $normalized = strtolower(trim($command));
        if ($normalized === '') {
            return false;
        }

        // Allow non-shutdown custom commands. We only enforce strict checks for shutdown syntax.
        if (! str_contains($normalized, 'shutdown')) {
            return true;
        }

        // If /c is present, Windows requires a non-empty comment string.
        if (! preg_match('/(?:^|\s)\/c(?:\s+|$)/i', $command)) {
            return true;
        }

        return (bool) preg_match('/(?:^|\s)\/c\s+(?:"[^"]+"|\S.*)$/i', $command);
    }

    private function validateCommandRuleConfig(array $config): ?string
    {
        $command = trim((string) ($config['command'] ?? ''));
        if ($command === '') {
            return 'Command rule requires non-empty "command".';
        }

        if (array_key_exists('run_as', $config)) {
            $runAs = strtolower(trim((string) $config['run_as']));
            if (! in_array($runAs, ['default', 'elevated', 'system'], true)) {
                return 'Command rule "run_as" must be default, elevated, or system.';
            }
        }

        if (array_key_exists('timeout_seconds', $config)) {
            if (! is_int($config['timeout_seconds'])) {
                return 'Command rule "timeout_seconds" must be an integer.';
            }
            if ($config['timeout_seconds'] < 30 || $config['timeout_seconds'] > 3600) {
                return 'Command rule "timeout_seconds" must be between 30 and 3600.';
            }
        }

        return null;
    }

    private function validateRegistryRuleConfig(array $config): ?string
    {
        $path = trim((string) ($config['path'] ?? ''));
        $name = trim((string) ($config['name'] ?? ''));
        $type = strtoupper(trim((string) ($config['type'] ?? '')));
        $ensure = strtolower(trim((string) ($config['ensure'] ?? 'present')));
        if ($path === '' || $name === '') {
            return 'Registry rule requires non-empty "path" and "name".';
        }
        if (! in_array($type, ['DWORD', 'QWORD', 'STRING', 'EXPANDSTRING', 'MULTISTRING', 'BINARY'], true)) {
            return 'Registry rule "type" must be one of DWORD, QWORD, STRING, EXPANDSTRING, MULTISTRING, BINARY.';
        }
        if ($ensure !== '' && ! in_array($ensure, ['present', 'absent'], true)) {
            return 'Registry rule "ensure" must be "present" or "absent".';
        }
        if ($ensure === 'absent') {
            return null;
        }
        if (! array_key_exists('value', $config)) {
            return 'Registry rule requires "value".';
        }
        return null;
    }

    private function validateFirewallRuleConfig(array $config): ?string
    {
        if (array_key_exists('enabled', $config) && ! is_bool($config['enabled'])) {
            return 'Firewall rule "enabled" must be true/false.';
        }
        if (array_key_exists('state', $config)) {
            $state = strtolower(trim((string) $config['state']));
            if (! in_array($state, ['on', 'off'], true)) {
                return 'Firewall rule "state" must be "on" or "off".';
            }
        }
        if (array_key_exists('profiles', $config)) {
            if (! is_array($config['profiles'])) {
                return 'Firewall rule "profiles" must be an array.';
            }
            $allowed = ['domain', 'private', 'public'];
            foreach ($config['profiles'] as $profile) {
                if (! in_array(strtolower((string) $profile), $allowed, true)) {
                    return 'Firewall rule profiles only allow: domain, private, public.';
                }
            }
        }
        if (array_key_exists('rules', $config)) {
            if (! is_array($config['rules'])) {
                return 'Firewall rule "rules" must be an array.';
            }
            foreach ($config['rules'] as $rule) {
                if (! is_array($rule)) {
                    return 'Firewall rule entry must be an object.';
                }
                $ensure = strtolower(trim((string) ($rule['ensure'] ?? 'present')));
                if (! in_array($ensure, ['present', 'absent'], true)) {
                    return 'Firewall rule entry "ensure" must be "present" or "absent".';
                }
                $name = trim((string) ($rule['name'] ?? ''));
                if ($name === '') {
                    return 'Firewall rule entry requires non-empty "name".';
                }
                if ($ensure === 'absent') {
                    continue;
                }
                $direction = strtolower(trim((string) ($rule['direction'] ?? 'in')));
                if (! in_array($direction, ['in', 'out'], true)) {
                    return 'Firewall rule entry "direction" must be "in" or "out".';
                }
                $action = strtolower(trim((string) ($rule['action'] ?? 'allow')));
                if (! in_array($action, ['allow', 'block'], true)) {
                    return 'Firewall rule entry "action" must be "allow" or "block".';
                }
            }
        }
        return null;
    }

    private function validateDnsRuleConfig(array $config): ?string
    {
        $selectorError = $this->validateNetworkSelectorConfig($config);
        if ($selectorError !== null) {
            return $selectorError;
        }

        $mode = strtolower(trim((string) ($config['mode'] ?? 'static')));
        if (! in_array($mode, ['static', 'automatic'], true)) {
            return 'DNS rule "mode" must be "static" or "automatic".';
        }

        if (array_key_exists('dry_run', $config) && ! is_bool($config['dry_run'])) {
            return 'DNS rule "dry_run" must be true/false.';
        }

        $servers = $config['servers'] ?? null;
        if ($mode === 'static') {
            if (! is_array($servers) || $servers === []) {
                return 'DNS rule requires non-empty "servers" array when mode=static.';
            }

            foreach ($servers as $idx => $server) {
                $value = trim((string) $server);
                if ($value === '' || ! $this->isValidIpv4Address($value)) {
                    return sprintf('DNS rule servers[%d] must be a valid IPv4 address.', $idx);
                }
            }

            return null;
        }

        if ($servers !== null && (! is_array($servers) || count($servers) > 0)) {
            return 'DNS rule "servers" must be omitted or empty when mode=automatic.';
        }

        return null;
    }

    private function validateNetworkAdapterRuleConfig(array $config): ?string
    {
        $selectorError = $this->validateNetworkSelectorConfig($config);
        if ($selectorError !== null) {
            return $selectorError;
        }

        $mode = strtolower(trim((string) ($config['ipv4_mode'] ?? '')));
        if (! in_array($mode, ['dhcp', 'static'], true)) {
            return 'Network adapter rule "ipv4_mode" must be "dhcp" or "static".';
        }

        if (array_key_exists('dry_run', $config) && ! is_bool($config['dry_run'])) {
            return 'Network adapter rule "dry_run" must be true/false.';
        }

        if ($mode === 'dhcp') {
            if (array_key_exists('address', $config) || array_key_exists('prefix_length', $config) || array_key_exists('gateway', $config)) {
                return 'Network adapter rule address/prefix_length/gateway must be omitted when ipv4_mode=dhcp.';
            }

            return null;
        }

        $address = trim((string) ($config['address'] ?? ''));
        if ($address === '' || ! $this->isValidIpv4Address($address)) {
            return 'Network adapter rule requires valid IPv4 "address" when ipv4_mode=static.';
        }

        if (! array_key_exists('prefix_length', $config) || ! is_int($config['prefix_length'])) {
            return 'Network adapter rule requires integer "prefix_length" when ipv4_mode=static.';
        }
        $prefixLength = (int) $config['prefix_length'];
        if ($prefixLength < 1 || $prefixLength > 32) {
            return 'Network adapter rule "prefix_length" must be between 1 and 32.';
        }

        if (array_key_exists('gateway', $config)) {
            $gateway = trim((string) $config['gateway']);
            if ($gateway !== '' && ! $this->isValidIpv4Address($gateway)) {
                return 'Network adapter rule "gateway" must be a valid IPv4 address when provided.';
            }
        }

        return null;
    }

    private function validateNetworkSelectorConfig(array $config): ?string
    {
        $selectorCount = 0;

        $alias = trim((string) ($config['interface_alias'] ?? ''));
        if ($alias !== '') {
            $selectorCount++;
        }

        if (array_key_exists('interface_index', $config)) {
            if (! is_int($config['interface_index']) || (int) $config['interface_index'] < 1) {
                return 'Network rule "interface_index" must be a positive integer.';
            }
            $selectorCount++;
        }

        $description = trim((string) ($config['interface_description'] ?? ''));
        if ($description !== '') {
            $selectorCount++;
        }

        if ($selectorCount === 0) {
            return 'Network rule requires exactly one selector: interface_alias, interface_index, or interface_description.';
        }

        if ($selectorCount > 1) {
            return 'Network rule selector must use only one of interface_alias, interface_index, or interface_description.';
        }

        return null;
    }

    private function isValidIpv4Address(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function validateBitlockerRuleConfig(array $config): ?string
    {
        $drive = trim((string) ($config['drive'] ?? ''));
        if ($drive === '') {
            return 'BitLocker rule requires "drive" (for example C:).';
        }
        if (array_key_exists('required', $config) && ! is_bool($config['required'])) {
            return 'BitLocker rule "required" must be true/false.';
        }
        if (array_key_exists('auto_enable', $config) && ! is_bool($config['auto_enable'])) {
            return 'BitLocker rule "auto_enable" must be true/false.';
        }
        return null;
    }

    private function validateLocalGroupRuleConfig(array $config): ?string
    {
        $group = trim((string) ($config['group'] ?? ''));
        if ($group === '') {
            return 'Local group rule requires "group".';
        }
        if (! array_key_exists('allowed_members', $config) || ! is_array($config['allowed_members'])) {
            return 'Local group rule requires "allowed_members" array.';
        }
        foreach ($config['allowed_members'] as $member) {
            if (trim((string) $member) === '') {
                return 'Local group rule "allowed_members" cannot contain empty values.';
            }
        }
        return null;
    }

    private function validateWindowsUpdateRuleConfig(array $config): ?string
    {
        $start = $config['active_hours_start'] ?? null;
        $end = $config['active_hours_end'] ?? null;
        if (! is_int($start) || $start < 0 || $start > 23) {
            return 'Windows Update rule "active_hours_start" must be an integer from 0 to 23.';
        }
        if (! is_int($end) || $end < 0 || $end > 23) {
            return 'Windows Update rule "active_hours_end" must be an integer from 0 to 23.';
        }
        if ($start === $end) {
            return 'Windows Update active hours start/end cannot be equal.';
        }
        if (array_key_exists('force_install_window', $config)) {
            $window = trim((string) $config['force_install_window']);
            if ($window !== '' && ! preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $window)) {
                return 'Windows Update "force_install_window" must be HH:MM-HH:MM.';
            }
        }
        if (array_key_exists('pause_updates_days', $config)) {
            $days = $config['pause_updates_days'];
            if (! is_int($days) || $days < 0 || $days > 35) {
                return 'Windows Update "pause_updates_days" must be an integer from 0 to 35.';
            }
        }
        if (array_key_exists('au_options', $config)) {
            $opt = $config['au_options'];
            if (! is_int($opt) || ! in_array($opt, [2, 3, 4, 5], true)) {
                return 'Windows Update "au_options" must be one of 2, 3, 4, 5.';
            }
        }
        if (array_key_exists('no_auto_reboot_with_logged_on_users', $config) && ! is_bool($config['no_auto_reboot_with_logged_on_users'])) {
            return 'Windows Update "no_auto_reboot_with_logged_on_users" must be true/false.';
        }
        return null;
    }

    private function validateScheduledTaskRuleConfig(array $config): ?string
    {
        $taskName = trim((string) ($config['task_name'] ?? ''));
        if ($taskName === '') {
            return 'Scheduled task rule requires "task_name".';
        }

        $ensure = strtolower(trim((string) ($config['ensure'] ?? 'present')));
        if (! in_array($ensure, ['present', 'absent'], true)) {
            return 'Scheduled task rule "ensure" must be "present" or "absent".';
        }
        if ($ensure === 'absent') {
            return null;
        }

        $schedule = strtolower(trim((string) ($config['schedule'] ?? 'daily')));
        if (! in_array($schedule, ['daily', 'weekly', 'hourly', 'onstart', 'onlogon'], true)) {
            return 'Scheduled task rule "schedule" must be daily, weekly, hourly, onstart, or onlogon.';
        }

        $command = trim((string) ($config['command'] ?? ''));
        if ($command === '') {
            return 'Scheduled task rule requires non-empty "command" when ensure=present.';
        }

        $time = trim((string) ($config['time'] ?? ''));
        if (! in_array($schedule, ['onstart', 'onlogon'], true)) {
            if ($time === '' || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
                return 'Scheduled task rule requires "time" in HH:MM format for the selected schedule.';
            }
        }
        return null;
    }

    private function saveSettingArray(string $key, array $value, ?int $updatedBy): void
    {
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => array_values($value)], 'updated_by' => $updatedBy]
        );
    }

    private function upsertEnvBoolean(string $envPath, string $key, bool $enabled): bool
    {
        if (! is_file($envPath)) {
            return false;
        }

        $contents = (string) file_get_contents($envPath);
        $value = $enabled ? 'true' : 'false';
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $contents)) {
            $updated = preg_replace($pattern, $line, $contents);
        } else {
            $updated = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        if (! is_string($updated) || $updated === $contents) {
            return false;
        }

        return file_put_contents($envPath, $updated) !== false;
    }

    private function upsertEnvValue(string $envPath, string $key, string $value): bool
    {
        if (! is_file($envPath)) {
            return false;
        }

        $contents = (string) file_get_contents($envPath);
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $contents)) {
            $updated = preg_replace($pattern, $line, $contents);
        } else {
            $updated = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        if (! is_string($updated) || $updated === $contents) {
            return false;
        }

        return file_put_contents($envPath, $updated) !== false;
    }

    private function replaceCustomCatalogCategory(string $from, string $to, ?int $updatedBy): int
    {
        $custom = collect($this->settingArray('policies.catalog_custom', []))
            ->filter(fn ($item) => is_array($item))
            ->values();
        $changed = 0;
        $updated = $custom->map(function ($item) use ($from, $to, &$changed) {
            $current = (string) ($item['category'] ?? '');
            if (strcasecmp($current, $from) === 0) {
                $item['category'] = $to;
                $changed++;
            }
            return $item;
        })->values();

        if ($changed > 0) {
            $this->saveSettingArray('policies.catalog_custom', $updated->all(), $updatedBy);
        }

        return $changed;
    }

    private function buildPolicyCategoryStats(array $policyCatalog, array $policyCategories): \Illuminate\Support\Collection
    {
        $policyUsage = Policy::query()
            ->selectRaw('category, count(*) as total')
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck('total', 'category');

        $catalogUsage = collect($policyCatalog)
            ->filter(fn ($item) => is_array($item))
            ->groupBy(fn ($item) => (string) ($item['category'] ?? ''))
            ->map(fn ($rows) => $rows->count());

        return collect($policyCategories)
            ->map(function (string $category) use ($policyUsage, $catalogUsage) {
                $policyCount = (int) ($policyUsage[$category] ?? 0);
                $presetCount = (int) ($catalogUsage[$category] ?? 0);
                return [
                    'name' => $category,
                    'policy_count' => $policyCount,
                    'preset_count' => $presetCount,
                    'total_count' => $policyCount + $presetCount,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function policyCatalog(): array
    {
        $defaults = [
            ['key' => 'block_usb_storage', 'label' => 'Block USB Storage', 'name' => 'Block USB Storage', 'slug' => 'security-usb-storage-block', 'category' => 'security/device_control', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR', 'name' => 'Start', 'type' => 'DWORD', 'value' => 4], 'source' => 'default'],
            ['key' => 'allow_usb_storage', 'label' => 'Allow USB Storage', 'name' => 'Allow USB Storage', 'slug' => 'security-usb-storage-allow', 'category' => 'security/device_control', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR', 'name' => 'Start', 'type' => 'DWORD', 'value' => 3], 'source' => 'default'],
            ['key' => 'enforce_firewall', 'label' => 'Enforce Firewall', 'name' => 'Enforce Firewall', 'slug' => 'security-firewall-enforce', 'category' => 'security/network', 'rule_type' => 'firewall', 'rule_json' => ['enabled' => true, 'profiles' => ['domain', 'private', 'public']], 'remove_mode' => 'json', 'remove_rules' => [['type' => 'firewall', 'config' => ['enabled' => false], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'dns_static_servers', 'label' => 'DNS: Static Servers', 'name' => 'DNS Static Servers', 'slug' => 'network-dns-static-servers', 'category' => 'security/network', 'rule_type' => 'dns', 'rule_json' => ['interface_alias' => 'Ethernet', 'mode' => 'static', 'servers' => ['10.0.0.10', '10.0.0.11']], 'remove_mode' => 'auto', 'remove_rules' => [['type' => 'dns', 'config' => ['interface_alias' => 'Ethernet', 'mode' => 'automatic'], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'ipv4_static_address', 'label' => 'IPv4: Static Address', 'name' => 'IPv4 Static Address', 'slug' => 'network-ipv4-static-address', 'category' => 'security/network', 'rule_type' => 'network_adapter', 'rule_json' => ['interface_alias' => 'Ethernet', 'ipv4_mode' => 'static', 'address' => '10.0.0.25', 'prefix_length' => 24, 'gateway' => '10.0.0.1'], 'remove_mode' => 'auto', 'remove_rules' => [['type' => 'network_adapter', 'config' => ['interface_alias' => 'Ethernet', 'ipv4_mode' => 'dhcp'], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'require_bitlocker', 'label' => 'Require BitLocker', 'name' => 'Require BitLocker', 'slug' => 'security-bitlocker-required', 'category' => 'security/encryption', 'rule_type' => 'bitlocker', 'rule_json' => ['drive' => 'C:', 'required' => true], 'remove_mode' => 'command', 'remove_rules' => [['type' => 'command', 'config' => ['command' => 'powershell.exe -NoProfile -Command "Write-Output BitLocker baseline removed from DMS policy profile"'], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'local_admins_baseline', 'label' => 'Local Admin Baseline', 'name' => 'Local Admin Group Baseline', 'slug' => 'security-local-admins-baseline', 'category' => 'security/local_accounts', 'rule_type' => 'local_group', 'rule_json' => ['group' => 'Administrators', 'allowed_members' => ['DOMAIN\\IT-Admins', 'Administrator']], 'remove_mode' => 'command', 'remove_rules' => [['type' => 'command', 'config' => ['command' => 'powershell.exe -NoProfile -Command "Write-Output Local admin baseline removed from DMS policy profile"'], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'windows_update_business', 'label' => 'Windows Update Hours', 'name' => 'Windows Update Active Hours', 'slug' => 'update-active-hours', 'category' => 'update/windows_update', 'rule_type' => 'windows_update', 'rule_json' => ['active_hours_start' => 8, 'active_hours_end' => 17, 'force_install_window' => '22:00-02:00'], 'remove_mode' => 'json', 'remove_rules' => [['type' => 'windows_update', 'config' => ['active_hours_start' => 8, 'active_hours_end' => 17], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'baseline_lab_core', 'label' => 'Baseline: Lab Core Integrity', 'name' => 'Lab Core Integrity Baseline', 'slug' => 'baseline-lab-core-integrity', 'category' => 'operations/baseline', 'rule_type' => 'baseline_profile', 'rule_json' => ['critical_files' => [['path' => 'C:\\Windows\\System32\\notepad.exe', 'exists' => true]], 'registry_values' => [['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR', 'name' => 'Start', 'type' => 'DWORD', 'value' => 4]], 'services' => [['name' => 'wuauserv', 'status' => 'running', 'ensure' => 'present']], 'installed_packages' => [['name' => 'Microsoft Edge', 'ensure' => 'present', 'match' => 'contains']], 'auto_remediate' => false, 'remediation_rules' => [['type' => 'registry', 'config' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR', 'name' => 'Start', 'type' => 'DWORD', 'value' => 4], 'enforce' => true]]], 'source' => 'default'],
            [
                'key' => 'fast_reboot_restore_mode',
                'label' => 'Fast Reboot Restore Mode',
                'name' => 'Fast Reboot Restore Mode',
                'slug' => 'ops-fast-reboot-restore-mode',
                'category' => 'operations/restore',
                'rule_type' => 'reboot_restore_mode',
                'rule_json' => [
                    'enabled' => true,
                    'persistent' => true,
                    'profile' => 'lab_fast',
                    'clean_downloads' => true,
                    'clean_user_temp' => true,
                    'clean_windows_temp' => true,
                    'clean_dms_staging' => true,
                    'clean_desktop' => false,
                    'clean_documents' => false,
                    'clean_recycle_bin' => false,
                    'reboot_now' => false,
                ],
                'remove_mode' => 'auto',
                'remove_rules' => [[
                    'type' => 'reboot_restore_mode',
                    'config' => ['ensure' => 'absent', 'enabled' => false, 'persistent' => false, 'remove_pending' => true],
                    'enforce' => true,
                ]],
                'source' => 'default',
            ],
            ['key' => 'disable_cmd', 'label' => 'Disable CMD', 'name' => 'Disable Command Prompt', 'slug' => 'security-disable-cmd', 'category' => 'security/endpoint_hardening', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows\\System', 'name' => 'DisableCMD', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'disable_control_panel', 'label' => 'Disable Control Panel', 'name' => 'Disable Control Panel', 'slug' => 'security-disable-control-panel', 'category' => 'security/endpoint_hardening', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer', 'name' => 'NoControlPanel', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'kiosk_applocker_service_enforced', 'label' => 'Kiosk: Enforce AppLocker Service', 'name' => 'Kiosk - Enforce AppLocker Service', 'slug' => 'kiosk-enforce-applocker-service', 'category' => 'education/application_control', 'rule_type' => 'command', 'rule_json' => ['command' => 'powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Set-Service -Name AppIDSvc -StartupType Automatic; Start-Service -Name AppIDSvc -ErrorAction SilentlyContinue"'], 'remove_mode' => 'command', 'remove_rules' => [['type' => 'command', 'config' => ['command' => 'powershell.exe -NoProfile -Command "Set-Service -Name AppIDSvc -StartupType Manual -ErrorAction SilentlyContinue"'], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'kiosk_shell_explorer_only', 'label' => 'Kiosk: Lock Shell to Explorer', 'name' => 'Kiosk - Shell Lock (Explorer)', 'slug' => 'kiosk-shell-lock-explorer', 'category' => 'education/lab_lockdown', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon', 'name' => 'Shell', 'type' => 'STRING', 'value' => 'explorer.exe'], 'remove_mode' => 'json', 'remove_rules' => [['type' => 'registry', 'config' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon', 'name' => 'Shell', 'type' => 'STRING', 'ensure' => 'absent'], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'daily_security_task', 'label' => 'Daily Audit Task', 'name' => 'Daily Security Audit Task', 'slug' => 'security-daily-audit-task', 'category' => 'security/monitoring', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'SecurityBaselineAudit', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '03:00', 'command' => 'powershell.exe -NoProfile -File C:\\ProgramData\\DMS\\audit.ps1'], 'source' => 'default'],
            ['key' => 'disable_rdp', 'label' => 'Disable RDP', 'name' => 'Disable Remote Desktop', 'slug' => 'security-disable-rdp', 'category' => 'security/remote_access', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server', 'name' => 'fDenyTSConnections', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'enable_rdp', 'label' => 'Enable RDP', 'name' => 'Enable Remote Desktop', 'slug' => 'security-enable-rdp', 'category' => 'security/remote_access', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server', 'name' => 'fDenyTSConnections', 'type' => 'DWORD', 'value' => 0], 'source' => 'default'],
            ['key' => 'disable_autoplay', 'label' => 'Disable AutoPlay', 'name' => 'Disable AutoPlay', 'slug' => 'security-disable-autoplay', 'category' => 'security/device_control', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer', 'name' => 'NoDriveTypeAutoRun', 'type' => 'DWORD', 'value' => 255], 'source' => 'default'],
            ['key' => 'disable_guest_account', 'label' => 'Disable Guest Account', 'name' => 'Disable Local Guest Account', 'slug' => 'security-disable-guest-account', 'category' => 'security/local_accounts', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SAM\\SAM\\Domains\\Account\\Users\\Names\\Guest', 'name' => 'Disabled', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'enforce_uac', 'label' => 'Enforce UAC', 'name' => 'Enforce UAC Prompting', 'slug' => 'security-enforce-uac', 'category' => 'security/endpoint_hardening', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System', 'name' => 'EnableLUA', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'disable_powershell_v2', 'label' => 'Disable PowerShell v2', 'name' => 'Disable PowerShell v2 Engine', 'slug' => 'security-disable-powershell-v2', 'category' => 'security/endpoint_hardening', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\PowerShell\\1\\PowerShellEngine', 'name' => 'PowerShellVersion', 'type' => 'STRING', 'value' => '5.1'], 'source' => 'default'],
            ['key' => 'defender_realtime_on', 'label' => 'Defender Realtime On', 'name' => 'Enable Defender Realtime Protection', 'slug' => 'security-defender-realtime-on', 'category' => 'security/antimalware', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows Defender\\Real-Time Protection', 'name' => 'DisableRealtimeMonitoring', 'type' => 'DWORD', 'value' => 0], 'source' => 'default'],
            ['key' => 'defender_cloud_on', 'label' => 'Defender Cloud Protection', 'name' => 'Enable Defender Cloud Protection', 'slug' => 'security-defender-cloud-protection', 'category' => 'security/antimalware', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows Defender\\Spynet', 'name' => 'SpynetReporting', 'type' => 'DWORD', 'value' => 2], 'source' => 'default'],
            ['key' => 'disable_smbv1', 'label' => 'Disable SMBv1', 'name' => 'Disable SMBv1 Protocol', 'slug' => 'security-disable-smbv1', 'category' => 'security/network', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\LanmanServer\\Parameters', 'name' => 'SMB1', 'type' => 'DWORD', 'value' => 0], 'source' => 'default'],
            ['key' => 'lock_screen_timeout', 'label' => 'Lock Screen Timeout', 'name' => 'Set Lock Screen Timeout', 'slug' => 'security-lock-screen-timeout', 'category' => 'security/endpoint_hardening', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System', 'name' => 'InactivityTimeoutSecs', 'type' => 'DWORD', 'value' => 900], 'source' => 'default'],
            ['key' => 'disable_telemetry', 'label' => 'Limit Telemetry', 'name' => 'Set Windows Telemetry to Security', 'slug' => 'security-limit-telemetry', 'category' => 'security/privacy', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows\\DataCollection', 'name' => 'AllowTelemetry', 'type' => 'DWORD', 'value' => 0], 'source' => 'default'],
            ['key' => 'weekly_reboot_task', 'label' => 'Weekly Reboot Task', 'name' => 'Weekly Maintenance Reboot', 'slug' => 'ops-weekly-reboot-task', 'category' => 'operations/maintenance', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'WeeklyMaintenanceReboot', 'ensure' => 'present', 'schedule' => 'weekly', 'time' => '04:00', 'command' => 'shutdown.exe /r /t 60 /c "Scheduled maintenance reboot"'], 'source' => 'default'],
            ['key' => 'daily_disk_cleanup', 'label' => 'Daily Disk Cleanup', 'name' => 'Daily Temp Cleanup Task', 'slug' => 'ops-daily-temp-cleanup', 'category' => 'operations/maintenance', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'DailyTempCleanup', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '02:30', 'command' => 'powershell.exe -NoProfile -Command "Get-ChildItem $env:TEMP -Recurse -ErrorAction SilentlyContinue | Remove-Item -Force -Recurse -ErrorAction SilentlyContinue"'], 'source' => 'default'],
            ['key' => 'lab_exam_mode_cmd_off', 'label' => 'Lab Exam: Disable CMD', 'name' => 'Student Lab Exam Mode - Disable CMD', 'slug' => 'lab-exam-disable-cmd', 'category' => 'education/lab_lockdown', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows\\System', 'name' => 'DisableCMD', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_exam_mode_taskmgr_off', 'label' => 'Lab Exam: Disable Task Manager', 'name' => 'Student Lab Exam Mode - Disable Task Manager', 'slug' => 'lab-exam-disable-taskmgr', 'category' => 'education/lab_lockdown', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System', 'name' => 'DisableTaskMgr', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_exam_mode_regedit_off', 'label' => 'Lab Exam: Disable Registry Editor', 'name' => 'Student Lab Exam Mode - Disable Regedit', 'slug' => 'lab-exam-disable-regedit', 'category' => 'education/lab_lockdown', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System', 'name' => 'DisableRegistryTools', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_exam_mode_cp_off', 'label' => 'Lab Exam: Disable Control Panel', 'name' => 'Student Lab Exam Mode - Disable Control Panel', 'slug' => 'lab-exam-disable-control-panel', 'category' => 'education/lab_lockdown', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer', 'name' => 'NoControlPanel', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_hide_drives', 'label' => 'Lab: Hide Local Drives', 'name' => 'Student Lab - Hide Local Drives in Explorer', 'slug' => 'lab-hide-local-drives', 'category' => 'education/lab_lockdown', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer', 'name' => 'NoDrives', 'type' => 'DWORD', 'value' => 67108863], 'source' => 'default'],
            ['key' => 'lab_disable_store', 'label' => 'Lab: Disable Microsoft Store', 'name' => 'Student Lab - Disable Microsoft Store', 'slug' => 'lab-disable-microsoft-store', 'category' => 'education/application_control', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Policies\\Microsoft\\WindowsStore', 'name' => 'RemoveWindowsStore', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_disable_consumer_features', 'label' => 'Lab: Disable Consumer Features', 'name' => 'Student Lab - Disable Consumer Experience', 'slug' => 'lab-disable-consumer-features', 'category' => 'education/application_control', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows\\CloudContent', 'name' => 'DisableWindowsConsumerFeatures', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_shared_pc_mode', 'label' => 'Lab: Enable Shared PC Mode', 'name' => 'Student Lab - Shared PC Mode', 'slug' => 'lab-enable-shared-pc-mode', 'category' => 'education/shared_device', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\SharedPC', 'name' => 'EnableSharedPCMode', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_clear_temp_on_boot', 'label' => 'Lab: Cleanup Temp on Startup', 'name' => 'Student Lab - Startup Temp Cleanup', 'slug' => 'lab-startup-temp-cleanup', 'category' => 'education/shared_device', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'LabStartupTempCleanup', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '00:10', 'command' => 'powershell.exe -NoProfile -Command "Get-ChildItem C:\\Windows\\Temp -Recurse -ErrorAction SilentlyContinue | Remove-Item -Force -Recurse -ErrorAction SilentlyContinue"'], 'source' => 'default'],
            ['key' => 'lab_daily_user_profile_cleanup', 'label' => 'Lab: Daily Profile Cleanup', 'name' => 'Student Lab - Daily Profile Cache Cleanup', 'slug' => 'lab-daily-profile-cleanup', 'category' => 'education/shared_device', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'LabDailyProfileCleanup', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '01:30', 'command' => 'powershell.exe -NoProfile -Command "Get-ChildItem C:\\Users -Directory | Where-Object { $_.Name -notin @(\"Public\",\"Default\",\"Default User\",\"Administrator\") } | ForEach-Object { Get-ChildItem $_.FullName\\AppData\\Local\\Temp -Recurse -ErrorAction SilentlyContinue | Remove-Item -Force -Recurse -ErrorAction SilentlyContinue }"'], 'source' => 'default'],
            ['key' => 'lab_maintenance_window_updates', 'label' => 'Lab: Overnight Update Window', 'name' => 'Student Lab - Overnight Windows Update', 'slug' => 'lab-overnight-update-window', 'category' => 'education/maintenance', 'rule_type' => 'windows_update', 'rule_json' => ['active_hours_start' => 7, 'active_hours_end' => 18, 'force_install_window' => '23:00-05:00'], 'remove_mode' => 'json', 'remove_rules' => [['type' => 'windows_update', 'config' => ['active_hours_start' => 8, 'active_hours_end' => 17], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'lab_firewall_strict', 'label' => 'Lab: Strict Firewall', 'name' => 'Student Lab - Strict Firewall Baseline', 'slug' => 'lab-firewall-strict-baseline', 'category' => 'education/network_security', 'rule_type' => 'firewall', 'rule_json' => ['enabled' => true, 'profiles' => ['domain', 'private', 'public']], 'remove_mode' => 'json', 'remove_rules' => [['type' => 'firewall', 'config' => ['enabled' => false], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'lab_local_admin_restriction', 'label' => 'Lab: Restrict Local Admins', 'name' => 'Student Lab - Local Admin Restriction', 'slug' => 'lab-restrict-local-admins', 'category' => 'education/local_accounts', 'rule_type' => 'local_group', 'rule_json' => ['group' => 'Administrators', 'allowed_members' => ['Administrator', 'DOMAIN\\Lab-IT-Admins']], 'remove_mode' => 'json', 'remove_rules' => [['type' => 'local_group', 'config' => ['group' => 'Administrators', 'ensure' => 'absent', 'restore_previous' => true, 'strict_restore' => true], 'enforce' => true]], 'source' => 'default'],
            ['key' => 'lab_non_persistent_uwf_enable', 'label' => 'Lab Non-Persistent: Enable UWF', 'name' => 'Non-Persistent Lab - Enable UWF Protection', 'slug' => 'lab-non-persistent-enable-uwf', 'category' => 'education/non_persistent', 'rule_type' => 'uwf', 'rule_json' => ['ensure' => 'present', 'enable_feature' => true, 'enable_filter' => true, 'protect_volume' => true, 'volume' => 'C:', 'reboot_now' => true, 'reboot_if_pending' => true, 'max_reboot_attempts' => 2, 'reboot_cooldown_minutes' => 30, 'reboot_command' => 'shutdown.exe /r /t 30 /c "Enabling UWF protection"', 'file_exclusions' => ['C:\\ProgramData\\DMS\\State', 'C:\\ProgramData\\DMS\\Logs', 'C:\\ProgramData\\DMS\\Uwf'], 'registry_exclusions' => ['HKLM\\SOFTWARE\\DMS'], 'fail_on_unsupported_edition' => false], 'source' => 'default'],
            ['key' => 'lab_non_persistent_profile_purge', 'label' => 'Lab Non-Persistent: Purge Profiles', 'name' => 'Non-Persistent Lab - Purge Non-Admin Profiles', 'slug' => 'lab-non-persistent-purge-profiles', 'category' => 'education/non_persistent', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'PurgeLabProfilesOnStartup', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '00:15', 'command' => 'powershell.exe -NoProfile -Command "Get-CimInstance Win32_UserProfile | Where-Object { -not $_.Special -and -not $_.Loaded -and $_.LocalPath -notmatch \'Administrator|Public|Default\' } | ForEach-Object { Remove-CimInstance $_ -ErrorAction SilentlyContinue }"'], 'source' => 'default'],
            ['key' => 'lab_non_persistent_downloads_cleanup', 'label' => 'Lab Non-Persistent: Cleanup Downloads', 'name' => 'Non-Persistent Lab - Cleanup Downloads Folder', 'slug' => 'lab-non-persistent-clean-downloads', 'category' => 'education/non_persistent', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'CleanupStudentDownloads', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '00:20', 'command' => 'powershell.exe -NoProfile -Command "Get-ChildItem C:\\Users\\*\\Downloads -Recurse -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue"'], 'source' => 'default'],
            ['key' => 'lab_non_persistent_block_removable', 'label' => 'Lab Non-Persistent: Block Removable Storage', 'name' => 'Non-Persistent Lab - Block Removable Storage', 'slug' => 'lab-non-persistent-block-removable', 'category' => 'education/non_persistent', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR', 'name' => 'Start', 'type' => 'DWORD', 'value' => 4], 'source' => 'default'],
            ['key' => 'lab_non_persistent_disable_cached_logons', 'label' => 'Lab Non-Persistent: Disable Cached Logons', 'name' => 'Non-Persistent Lab - Disable Cached Domain Logons', 'slug' => 'lab-non-persistent-disable-cached-logons', 'category' => 'education/non_persistent', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon', 'name' => 'CachedLogonsCount', 'type' => 'STRING', 'value' => '0'], 'source' => 'default'],
            ['key' => 'lab_non_persistent_clear_pagefile', 'label' => 'Lab Non-Persistent: Clear Pagefile', 'name' => 'Non-Persistent Lab - Clear Pagefile on Shutdown', 'slug' => 'lab-non-persistent-clear-pagefile', 'category' => 'education/non_persistent', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Control\\Session Manager\\Memory Management', 'name' => 'ClearPageFileAtShutdown', 'type' => 'DWORD', 'value' => 1], 'source' => 'default'],
            ['key' => 'lab_non_persistent_disable_hibernation', 'label' => 'Lab Non-Persistent: Disable Hibernation', 'name' => 'Non-Persistent Lab - Disable Hibernation', 'slug' => 'lab-non-persistent-disable-hibernation', 'category' => 'education/non_persistent', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'DisableHibernationDaily', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '00:25', 'command' => 'powershell.exe -NoProfile -Command "powercfg.exe /h off"'], 'source' => 'default'],
            ['key' => 'lab_non_persistent_daily_reboot', 'label' => 'Lab Non-Persistent: Daily Reboot', 'name' => 'Non-Persistent Lab - Daily Forced Reboot', 'slug' => 'lab-non-persistent-daily-reboot', 'category' => 'education/non_persistent', 'rule_type' => 'scheduled_task', 'rule_json' => ['task_name' => 'LabDailyReboot', 'ensure' => 'present', 'schedule' => 'daily', 'time' => '03:30', 'command' => 'shutdown.exe /r /t 60 /f /c "Daily non-persistent lab reset"'], 'source' => 'default'],
            ['key' => 'lab_non_persistent_short_idle_lock', 'label' => 'Lab Non-Persistent: Short Idle Lock', 'name' => 'Non-Persistent Lab - Short Idle Timeout', 'slug' => 'lab-non-persistent-idle-lock', 'category' => 'education/non_persistent', 'rule_type' => 'registry', 'rule_json' => ['path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System', 'name' => 'InactivityTimeoutSecs', 'type' => 'DWORD', 'value' => 300], 'source' => 'default'],
        ];

        $defaultOverrides = collect($this->settingArray('policies.catalog_default_overrides', []))
            ->filter(fn ($item) => is_array($item))
            ->keyBy(fn ($item) => (string) ($item['key'] ?? ''));

        $defaults = collect($defaults)->map(function (array $item) use ($defaultOverrides) {
            $key = (string) ($item['key'] ?? '');
            if ($key !== '' && $defaultOverrides->has($key)) {
                $override = (array) $defaultOverrides->get($key);
                foreach (['label', 'name', 'slug', 'category', 'rule_type', 'rule_json', 'remove_mode', 'remove_rules', 'description', 'applies_to'] as $field) {
                    if (array_key_exists($field, $override)) {
                        $item[$field] = $override[$field];
                    }
                }
            }
            $item['source'] = 'default';
            return $item;
        })->values()->all();

        $custom = collect($this->settingArray('policies.catalog_custom', []))
            ->filter(fn ($item) => is_array($item))
            ->map(function ($item) {
                $required = ['key', 'label', 'name', 'slug', 'category', 'rule_type', 'rule_json'];
                foreach ($required as $key) {
                    if (! array_key_exists($key, $item)) {
                        return null;
                    }
                }
                $item['source'] = 'custom';
                return $item;
            })
            ->filter()
            ->values()
            ->all();

        $items = array_merge($defaults, $custom);
        $metaByKey = [
            'block_usb_storage' => ['description' => 'Blocks USB storage devices by disabling UsbStor service start.', 'applies_to' => 'both'],
            'allow_usb_storage' => ['description' => 'Re-enables USB storage access on managed endpoints.', 'applies_to' => 'both'],
            'enforce_firewall' => ['description' => 'Forces Windows Firewall enabled for all network profiles.', 'applies_to' => 'both'],
            'dns_static_servers' => ['description' => 'Sets explicit IPv4 DNS resolvers on a selected network interface.', 'applies_to' => 'device'],
            'ipv4_static_address' => ['description' => 'Pins a selected interface to a static IPv4 address and optional default gateway.', 'applies_to' => 'device'],
            'require_bitlocker' => ['description' => 'Requires BitLocker protection for system drive C:.', 'applies_to' => 'device'],
            'fast_reboot_restore_mode' => ['description' => 'Applies a persistent startup restore manifest at every boot for non-persistent classroom behavior.', 'applies_to' => 'both'],
            'kiosk_applocker_service_enforced' => ['description' => 'Enforces AppLocker identity service startup as a prerequisite for allowlist-based controls.', 'applies_to' => 'both'],
            'kiosk_shell_explorer_only' => ['description' => 'Pins Winlogon shell to explorer.exe to prevent alternate shell persistence.', 'applies_to' => 'both'],
            'lab_non_persistent_uwf_enable' => ['description' => 'Turns on Unified Write Filter and protects C: for reboot-to-clean behavior.', 'applies_to' => 'group'],
            'lab_non_persistent_profile_purge' => ['description' => 'Removes non-admin local user profiles on schedule.', 'applies_to' => 'group'],
            'lab_non_persistent_downloads_cleanup' => ['description' => 'Deletes user Downloads content to reduce retained data.', 'applies_to' => 'group'],
            'lab_non_persistent_block_removable' => ['description' => 'Prevents removable USB storage usage in shared labs.', 'applies_to' => 'both'],
            'lab_non_persistent_disable_cached_logons' => ['description' => 'Disables cached domain credentials on endpoint.', 'applies_to' => 'both'],
            'lab_non_persistent_clear_pagefile' => ['description' => 'Clears pagefile at shutdown for privacy hardening.', 'applies_to' => 'both'],
            'lab_non_persistent_disable_hibernation' => ['description' => 'Disables hibernation to avoid persistence via hiberfil.', 'applies_to' => 'both'],
            'lab_non_persistent_daily_reboot' => ['description' => 'Schedules forced daily reboot to return lab to baseline.', 'applies_to' => 'group'],
            'lab_non_persistent_short_idle_lock' => ['description' => 'Locks idle sessions quickly on shared student endpoints.', 'applies_to' => 'both'],
        ];

        return collect($items)
            ->map(function ($item) use ($metaByKey) {
                if (! is_array($item)) {
                    return $item;
                }
                $key = (string) ($item['key'] ?? '');
                $meta = $metaByKey[$key] ?? null;
                $description = trim((string) ($item['description'] ?? ''));
                $appliesTo = trim((string) ($item['applies_to'] ?? ''));

                if ($description === '') {
                    $description = (string) ($meta['description'] ?? ('Preset for '.((string) ($item['category'] ?? 'general')).' using '.((string) ($item['rule_type'] ?? 'rule')).'.'));
                }
                if ($appliesTo === '') {
                    $appliesTo = (string) ($meta['applies_to'] ?? 'both');
                }
                $removeMode = strtolower(trim((string) ($item['remove_mode'] ?? 'auto')));
                if (! in_array($removeMode, ['auto', 'json', 'command'], true)) {
                    $removeMode = 'auto';
                }
                $removeRules = is_array($item['remove_rules'] ?? null) ? $item['remove_rules'] : [];
                if ($removeRules === []) {
                    $removeRules = $this->buildRemovalRulesForPolicyRule(
                        (string) ($item['rule_type'] ?? ''),
                        is_array($item['rule_json'] ?? null) ? $item['rule_json'] : []
                    );
                }

                $item['description'] = $description;
                $item['applies_to'] = in_array($appliesTo, ['device', 'group', 'both'], true) ? $appliesTo : 'both';
                $item['remove_mode'] = $removeMode;
                $item['remove_rules'] = array_values(array_filter($removeRules, fn ($rule) => is_array($rule)));
                return $item;
            })
            ->values()
            ->all();
    }

    private function policyCategories(): array
    {
        $defaults = collect($this->policyCatalog())
            ->pluck('category')
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim((string) $v));

        $fromPolicies = Policy::query()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim((string) $v));

        $custom = collect($this->settingArray('policies.categories', []))
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim((string) $v));

        return $defaults
            ->merge($fromPolicies)
            ->merge($custom)
            ->unique(fn ($v) => strtolower($v))
            ->values()
            ->all();
    }

    private function deletePackageArtifactForFile(PackageFile $file): bool
    {
        $storagePath = $this->resolvePackageArtifactStoragePath($file);
        if ($storagePath === null || $storagePath === '') {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Storage::delete($storagePath);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolvePackageArtifactStoragePath(PackageFile $file): ?string
    {
        $metadata = is_array($file->signature_metadata) ? $file->signature_metadata : [];
        $storagePath = trim((string) ($metadata['storage_path'] ?? ''));
        if ($storagePath !== '') {
            return str_replace('\\', '/', ltrim($storagePath, '/\\'));
        }

        $sourceUri = trim((string) ($file->source_uri ?? ''));
        if (str_starts_with($sourceUri, 'uploaded://')) {
            $fromUri = trim(substr($sourceUri, strlen('uploaded://')));
            if ($fromUri !== '') {
                return str_replace('\\', '/', ltrim($fromUri, '/\\'));
            }
        }

        return null;
    }

    private function readDocFile(string $path): string
    {
        if (! is_file($path)) {
            return 'Document file not found: '.$path;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return 'Unable to read document file: '.$path;
        }

        return $contents;
    }

    private function ensureSuperAdminAccess(): void
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->roles()->where('slug', 'super-admin')->exists() ?? false;
        abort_unless($isSuperAdmin, 403, 'Only super-admin can manage access control.');
    }

    private function resolveWindowsStoreIcon(string $name, ?string $slug = null): ?array
    {
        $queries = collect([$name, $slug])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(function (string $v) {
                $normalized = preg_replace('/[^a-z0-9\s.+-]/i', ' ', $v) ?? $v;
                $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
                return trim((string) $normalized);
            })
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values();

        if ($queries->isEmpty()) {
            return null;
        }

        $best = null;
        foreach ($queries as $query) {
            $searchUrl = 'https://apps.microsoft.com/search?query='.urlencode($query);
            $searchResponse = Http::timeout(12)->retry(1, 200)->get($searchUrl);
            if (! $searchResponse->successful()) {
                continue;
            }

            $html = (string) $searchResponse->body();
            preg_match_all('/detail\/([A-Z0-9]{12})/i', $html, $matches);
            $productIds = collect($matches[1] ?? [])->map(fn ($id) => strtoupper((string) $id))->unique()->take(6)->values();
            if ($productIds->isEmpty()) {
                continue;
            }

            foreach ($productIds as $productId) {
                $detailsUrl = 'https://storeedgefd.dsx.mp.microsoft.com/v9.0/products/'.$productId.'?market=US&locale=en-US&deviceFamily=Windows.Desktop';
                $detailsResponse = Http::timeout(12)->retry(1, 200)->get($detailsUrl);
                if (! $detailsResponse->successful()) {
                    continue;
                }

                $payload = $detailsResponse->json('Payload');
                if (! is_array($payload)) {
                    continue;
                }

                $title = trim((string) ($payload['Title'] ?? ''));
                $images = is_array($payload['Images'] ?? null) ? $payload['Images'] : [];
                $iconUrl = $this->pickWindowsStoreIconFromImages($images);
                if ($iconUrl === null) {
                    continue;
                }

                $score = $this->scoreWindowsStoreTitleMatch($title, $name, $slug);
                if ($best === null || $score > (int) ($best['score'] ?? 0)) {
                    $best = [
                        'score' => $score,
                        'icon_url' => $iconUrl,
                        'product_id' => $productId,
                        'title' => $title,
                    ];
                }
            }
        }

        if (! is_array($best)) {
            return null;
        }

        return [
            'icon_url' => (string) ($best['icon_url'] ?? ''),
            'product_id' => (string) ($best['product_id'] ?? ''),
            'title' => (string) ($best['title'] ?? ''),
        ];
    }

    private function pickWindowsStoreIconFromImages(array $images): ?string
    {
        $candidates = collect($images)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $url = trim((string) ($item['Url'] ?? ''));
                $type = strtolower(trim((string) ($item['ImageType'] ?? '')));
                $w = (int) ($item['Width'] ?? 0);
                $h = (int) ($item['Height'] ?? 0);
                $area = $w > 0 && $h > 0 ? $w * $h : 0;
                $priority = $type === 'logo' ? 3 : ($type === 'tile' ? 2 : ($type === 'icon' ? 1 : 0));
                return [
                    'url' => $url,
                    'priority' => $priority,
                    'area' => $area,
                ];
            })
            ->filter(fn ($item) => is_string($item['url']) && $item['url'] !== '' && str_starts_with($item['url'], 'http'));

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = $candidates
            ->sortByDesc(fn ($item) => ($item['priority'] * 100000000) + (int) ($item['area'] ?? 0))
            ->first();

        return is_array($best) ? (string) ($best['url'] ?? '') : null;
    }

    private function scoreWindowsStoreTitleMatch(string $storeTitle, string $name, ?string $slug): int
    {
        $normalize = function (?string $value): string {
            $v = strtolower((string) ($value ?? ''));
            $v = preg_replace('/[^a-z0-9]+/', ' ', $v) ?? $v;
            $v = preg_replace('/\s+/', ' ', $v) ?? $v;
            return trim((string) $v);
        };

        $title = $normalize($storeTitle);
        $nameNorm = $normalize($name);
        $slugNorm = $normalize((string) ($slug ?? ''));
        $score = 0;

        if ($title !== '' && $nameNorm !== '') {
            if ($title === $nameNorm) {
                $score += 100;
            }
            if (str_contains($title, $nameNorm) || str_contains($nameNorm, $title)) {
                $score += 60;
            }
        }
        if ($title !== '' && $slugNorm !== '') {
            if (str_contains($title, $slugNorm) || str_contains($slugNorm, $title)) {
                $score += 35;
            }
        }

        $nameTokens = collect(explode(' ', $nameNorm))->filter(fn ($t) => $t !== '');
        foreach ($nameTokens as $token) {
            if (str_contains($title, (string) $token)) {
                $score += 8;
            }
        }

        return $score;
    }
}
