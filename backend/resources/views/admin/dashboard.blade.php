<x-admin-layout title="Overview" heading="Operations Overview">
    @php
        $deviceStatus = $charts['device_status'] ?? ['online' => 0, 'offline' => 0, 'pending' => 0];
        $jobStatus = $charts['job_status'] ?? ['success' => 0, 'failed' => 0, 'active' => 0];
        $complianceStatus = $charts['compliance_status'] ?? ['compliant' => 0, 'non_compliant' => 0, 'unknown' => 0];
        $jobTrend = collect($charts['job_trend'] ?? []);
        $enrollmentTrend = collect($charts['enrollment_trend'] ?? []);
        $auditTrend = collect($charts['audit_trend'] ?? []);
        $anomalyTrend = collect($charts['anomaly_trend'] ?? []);

        $deviceOnlineCount = (int) ($deviceStatus['online'] ?? 0);
        $deviceOfflineCount = (int) ($deviceStatus['offline'] ?? 0);
        $devicePendingCount = (int) ($deviceStatus['pending'] ?? 0);
        $deviceTotal = max(1, $deviceOnlineCount + $deviceOfflineCount + $devicePendingCount);
        $onlineRate = round(($deviceOnlineCount / $deviceTotal) * 100, 1);
        $complianceRate = (float) ($metrics['compliance_rate'] ?? 0);
        $jobSuccessRate = (float) ($metrics['job_success_rate'] ?? 0);
        $pendingRate = round((((int) ($metrics['jobs_pending'] ?? 0)) / $deviceTotal) * 100, 1);
        $failureRate = round((((int) ($metrics['jobs_failed'] ?? 0)) / $deviceTotal) * 100, 1);
        $baselineEnabled = (bool) ($metrics['behavior_baseline_enabled'] ?? false);
        $baselineRiskContribution = (float) ($metrics['behavior_baseline_risk'] ?? 0.0);
        $remediationEnabled = (bool) ($metrics['behavior_remediation_enabled'] ?? false);
        $remediationRiskContribution = (float) ($metrics['behavior_remediation_risk'] ?? 0.0);

        if ($baselineEnabled && $remediationEnabled) {
            $riskScore = max(0, min(100,
                (100 - $onlineRate) * 0.26
                + (100 - $complianceRate) * 0.28
                + (100 - $jobSuccessRate) * 0.22
                + ($baselineRiskContribution * 0.12)
                + ($remediationRiskContribution * 0.12)
            ));
        } elseif ($baselineEnabled) {
            $riskScore = max(0, min(100,
                (100 - $onlineRate) * 0.30
                + (100 - $complianceRate) * 0.32
                + (100 - $jobSuccessRate) * 0.26
                + ($baselineRiskContribution * 0.12)
            ));
        } elseif ($remediationEnabled) {
            $riskScore = max(0, min(100,
                (100 - $onlineRate) * 0.30
                + (100 - $complianceRate) * 0.33
                + (100 - $jobSuccessRate) * 0.25
                + ($remediationRiskContribution * 0.12)
            ));
        } else {
            $riskScore = max(0, min(100,
                (100 - $onlineRate) * 0.34
                + (100 - $complianceRate) * 0.36
                + (100 - $jobSuccessRate) * 0.30
            ));
        }
        $riskLabel = $riskScore >= 60 ? 'Needs Attention' : ($riskScore >= 35 ? 'Watch Closely' : 'Healthy');
        $riskTone = $riskScore >= 60 ? 'text-amber-800 bg-amber-100 border-amber-300' : ($riskScore >= 35 ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');

        $opsPressure = min(100, round(
            ($pendingRate * 0.58)
            + (((int) ($metrics['retrying_runs'] ?? 0) / max(1, (int) ($metrics['jobs_pending'] ?? 1))) * 42),
            1
        ));
        $opsTone = $opsPressure >= 70 ? 'text-amber-800' : ($opsPressure >= 40 ? 'text-amber-700' : 'text-emerald-700');

        $policyDensity = round((((int) ($metrics['policies_total'] ?? 0)) / $deviceTotal) * 100, 1);
        $packageDensity = round((((int) ($metrics['packages_total'] ?? 0)) / $deviceTotal) * 100, 1);
        $aiQueue = (int) (($metrics['behavior_ai_cases_pending'] ?? 0) + ($metrics['behavior_ai_recommendations_pending'] ?? 0));

        $deviceRingTotal = max(1, $deviceOnlineCount + $deviceOfflineCount + $devicePendingCount);
        $deviceOnlineDeg = round(($deviceOnlineCount / $deviceRingTotal) * 360, 1);
        $deviceOfflineDeg = round(($deviceOfflineCount / $deviceRingTotal) * 360, 1);
        $deviceRing = 'background: conic-gradient(#14b8a6 0deg '.$deviceOnlineDeg.'deg, #f59e0b '.$deviceOnlineDeg.'deg '.($deviceOnlineDeg + $deviceOfflineDeg).'deg, #cbd5e1 '.($deviceOnlineDeg + $deviceOfflineDeg).'deg 360deg);';

        $complianceRingTotal = max(1, array_sum($complianceStatus));
        $compliantDeg = round((($complianceStatus['compliant'] ?? 0) / $complianceRingTotal) * 360, 1);
        $nonCompliantDeg = round((($complianceStatus['non_compliant'] ?? 0) / $complianceRingTotal) * 360, 1);
        $complianceRing = 'background: conic-gradient(#0ea5e9 0deg '.$compliantDeg.'deg, #f59e0b '.$compliantDeg.'deg '.($compliantDeg + $nonCompliantDeg).'deg, #e2e8f0 '.($compliantDeg + $nonCompliantDeg).'deg 360deg);';

        $jobRingTotal = max(1, array_sum($jobStatus));
        $jobSuccessDeg = round((($jobStatus['success'] ?? 0) / $jobRingTotal) * 360, 1);
        $jobFailedDeg = round((($jobStatus['failed'] ?? 0) / $jobRingTotal) * 360, 1);
        $jobRing = 'background: conic-gradient(#6366f1 0deg '.$jobSuccessDeg.'deg, #f59e0b '.$jobSuccessDeg.'deg '.($jobSuccessDeg + $jobFailedDeg).'deg, #94a3b8 '.($jobSuccessDeg + $jobFailedDeg).'deg 360deg);';

        $jobTrendMax = max(1, (int) $jobTrend->map(fn ($point) => (int) ($point['success'] + $point['failed'] + $point['active']))->max());
        $oversightMax = max(1, (int) max(
            (int) $enrollmentTrend->max('total'),
            (int) $auditTrend->max('total'),
            (int) $anomalyTrend->max('total')
        ));

        $quickLinks = [
            ['label' => 'Enroll Devices', 'url' => route('admin.enroll-devices'), 'classes' => 'bg-sky-100 text-sky-800 border-sky-200'],
            ['label' => 'Devices', 'url' => route('admin.devices'), 'classes' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
            ['label' => 'Jobs', 'url' => route('admin.jobs'), 'classes' => 'bg-indigo-100 text-indigo-800 border-indigo-200'],
            ['label' => 'Agent Delivery', 'url' => route('admin.agent'), 'classes' => 'bg-amber-100 text-amber-800 border-amber-200'],
        ];
    @endphp

    <style>
        .board-surface {
            border: 1px solid #dbe5f0;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(148, 163, 184, 0.10);
        }
        .hero-surface {
            position: relative;
            overflow: hidden;
            border: 1px solid #dbe5f0;
            background: #f8fafc;
            box-shadow: 0 12px 28px rgba(148, 163, 184, 0.12);
        }
        .hero-surface::after {
            display: none;
        }
        .hero-card {
            border: 1px solid rgba(226, 232, 240, 0.95);
            background: #ffffff;
            backdrop-filter: blur(12px);
        }
        .signal-card {
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .ring-shell {
            position: relative;
            flex-shrink: 0;
            height: 5.5rem;
            width: 5.5rem;
            border-radius: 9999px;
            padding: 0.62rem;
        }
        .ring-shell::after {
            content: "";
            display: block;
            height: 100%;
            width: 100%;
            border-radius: 9999px;
            background: #ffffff;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.14);
        }
        .metric-card {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            padding:  0.5rem;
        }
        .chart-card {
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .chart-layout {
            display: grid;
            gap: 1rem;
        }
        .chart-scroll {
            display: grid;
            grid-template-columns: repeat(7, minmax(3.35rem, 1fr));
            gap: 0.7rem;
            min-width: 28rem;
            width: 100%;
        }
        .chart-col {
            min-width: 0;
        }
        .chart-well {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            height: 10.5rem;
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 0.7rem 0.45rem;
        }
        .stack-track {
            width: 0.75rem;
            height: 100%;
            border-radius: 9999px;
            overflow: hidden;
            background: rgba(226, 232, 240, 0.92);
        }
        .group-bar {
            width: 0.52rem;
            border-radius: 9999px;
            overflow: hidden;
            background: rgba(226, 232, 240, 0.92);
        }
        body.ui-modal-open #admin-dashboard-root {
            visibility: hidden;
        }
        @media (min-width: 1024px) {
            .chart-layout {
                grid-template-columns: 9.5rem minmax(0, 1fr);
                align-items: start;
            }
        }
    </style>

    <div id="admin-dashboard-root" class="mx-auto max-w-[1320px] space-y-4">
        <section class="hero-surface rounded-[1.5rem] p-4 lg:p-5">
            <div class="relative z-10 grid gap-4 xl:grid-cols-[1.55fr,0.95fr]">
                <div>
                    <div class="flex flex-wrap gap-2 text-[11px] uppercase tracking-[0.22em] text-slate-500">
                        <span class="rounded-full border border-slate-200 bg-white px-3 py-1">Fleet Runtime</span>
                        <span class="rounded-full border border-slate-200 bg-white px-3 py-1">Security Posture</span>
                        <span class="rounded-full border border-slate-200 bg-white px-3 py-1">{{ now()->format('D, M j') }}</span>
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div class="hero-card rounded-[1.2rem] p-4">
                            <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Fleet Risk</p>
                            <div class="mt-2 flex items-end justify-between gap-3">
                                <p class="text-3xl font-semibold text-slate-900">{{ number_format($riskScore, 1) }}</p>
                                <span class="rounded-full border px-3 py-1 text-xs font-medium {{ $riskTone }}">{{ $riskLabel }}</span>
                            </div>
                        </div>
                        <div class="hero-card rounded-[1.2rem] p-4">
                            <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Dispatch Pressure</p>
                            <p class="mt-2 text-3xl font-semibold {{ $opsTone }}">{{ $opsPressure }}%</p>
                            <p class="mt-1 text-xs text-slate-500">Pending {{ $metrics['jobs_pending'] }} | retrying {{ $metrics['retrying_runs'] }}</p>
                        </div>
                        <div class="hero-card rounded-[1.2rem] p-4">
                            <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">AI Review Queue</p>
                            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $aiQueue }}</p>
                            <p class="mt-1 text-xs text-slate-500">Waiting for action</p>
                        </div>
                    </div>

                </div>

            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-12">
            <div class="board-surface rounded-[1.4rem] p-4 xl:col-span-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Executive Signals</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Health indicators</h3>
                    </div>
                    <div class="text-xs text-slate-500">Online {{ $deviceOnlineCount }} | non-compliant {{ $metrics['compliance_non_compliant'] }} | success {{ $metrics['job_success_rate'] ?? 'N/A' }}%</div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <article class="signal-card rounded-[1.2rem] p-4">
                        <div class="flex items-center gap-4">
                            <div class="ring-shell" style="{{ $deviceRing }}"></div>
                            <div>
                                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Device Reachability</p>
                                <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $onlineRate }}%</p>
                                <p class="mt-1 text-xs text-slate-500">Online {{ $deviceOnlineCount }} | offline {{ $deviceOfflineCount }} | pending {{ $devicePendingCount }}</p>
                            </div>
                        </div>
                    </article>

                    <article class="signal-card rounded-[1.2rem] p-4">
                        <div class="flex items-center gap-4">
                            <div class="ring-shell" style="{{ $complianceRing }}"></div>
                            <div>
                                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Compliance</p>
                                <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $metrics['compliance_rate'] !== null ? $metrics['compliance_rate'].'%' : 'N/A' }}</p>
                                <p class="mt-1 text-xs text-slate-500">Compliant {{ $complianceStatus['compliant'] ?? 0 }} | non-compliant {{ $complianceStatus['non_compliant'] ?? 0 }}</p>
                            </div>
                        </div>
                    </article>

                    <article class="signal-card rounded-[1.2rem] p-4">
                        <div class="flex items-center gap-4">
                            <div class="ring-shell" style="{{ $jobRing }}"></div>
                            <div>
                                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Execution Quality</p>
                                <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $metrics['job_success_rate'] !== null ? $metrics['job_success_rate'].'%' : 'N/A' }}</p>
                                <p class="mt-1 text-xs text-slate-500">Failed {{ $jobStatus['failed'] ?? 0 }} | active {{ $jobStatus['active'] ?? 0 }}</p>
                            </div>
                        </div>
                    </article>
                </div>
            </div>

            <aside class="board-surface rounded-[1.4rem] p-4 xl:col-span-4">
                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Quick Overview</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Policies / 100 devices</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $policyDensity }}</p>
                    </div>
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Packages / 100 devices</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $packageDensity }}</p>
                    </div>
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Replay Rejects</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['replay_rejects'] }}</p>
                    </div>
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Failed / Device</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $failureRate }}%</p>
                    </div>
                </div>
            </aside>
        </section>

        <section class="grid gap-5 xl:grid-cols-2">
            <div class="board-surface rounded-[1.4rem] p-4">
                <div class="chart-layout">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">7-Day Chart</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Job run activity</h3>
                        <div class="mt-3 space-y-1.5 text-xs text-slate-500">
                            <p class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-indigo-500"></span>Success</p>
                            <p class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>Failed</p>
                            <p class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>Active</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto pb-2">
                        <div class="chart-scroll">
                            @foreach($jobTrend as $point)
                                @php $total = max(1, (int) ($point['success'] + $point['failed'] + $point['active'])); @endphp
                                <div class="chart-col">
                                    <div class="chart-well">
                                        <div class="stack-track flex h-full flex-col justify-end">
                                            @if($point['success'] > 0)
                                                <div class="bg-indigo-500" style="height: {{ max(6, (($point['success'] ?? 0) / $jobTrendMax) * 100) }}%"></div>
                                            @endif
                                            @if($point['failed'] > 0)
                                                <div class="bg-amber-500" style="height: {{ max(6, (($point['failed'] ?? 0) / $jobTrendMax) * 100) }}%"></div>
                                            @endif
                                            @if($point['active'] > 0)
                                                <div class="bg-slate-400" style="height: {{ max(6, (($point['active'] ?? 0) / $jobTrendMax) * 100) }}%"></div>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="mt-3 text-center">
                                        <p class="text-base font-semibold text-slate-900">{{ $total }}</p>
                                        <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ $point['label'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="board-surface rounded-[1.4rem] p-4">
                <div class="chart-layout">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Oversight Chart</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Enrollments, audit, anomalies</h3>
                        <p class="mt-3 text-xs leading-5 text-slate-500">Daily enroll / audit / anomaly volume</p>
                    </div>
                    <div class="overflow-x-auto pb-2">
                        <div class="chart-scroll">
                            @foreach($enrollmentTrend as $index => $point)
                                @php
                                    $auditPoint = $auditTrend[$index] ?? ['total' => 0];
                                    $anomalyPoint = $anomalyTrend[$index] ?? ['total' => 0];
                                @endphp
                                <div class="chart-col">
                                    <div class="chart-well gap-1">
                                        <div class="group-bar"><div class="w-full rounded-full bg-teal-500" style="height: {{ max(4, (($point['total'] ?? 0) / $oversightMax) * 100) }}%"></div></div>
                                        <div class="group-bar"><div class="w-full rounded-full bg-slate-500" style="height: {{ max(4, (($auditPoint['total'] ?? 0) / $oversightMax) * 100) }}%"></div></div>
                                        <div class="group-bar"><div class="w-full rounded-full bg-amber-500" style="height: {{ max(4, (($anomalyPoint['total'] ?? 0) / $oversightMax) * 100) }}%"></div></div>
                                    </div>
                                    <div class="mt-3 text-center">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ $point['label'] }}</p>
                                        <p class="text-xs text-slate-600">{{ $point['total'] }}/{{ $auditPoint['total'] ?? 0 }}/{{ $anomalyPoint['total'] ?? 0 }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-3">
            <div class="board-surface rounded-[1.4rem] p-4">
                <div class="mb-4 flex items-center justify-between gap-2">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Recent Devices</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Last touched endpoints</h3>
                    </div>
                    <a href="{{ route('admin.devices') }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700">Open Devices</a>
                </div>
                <div class="space-y-3">
                    @forelse($recent_devices as $device)
                        <a href="{{ route('admin.devices.show', $device->id) }}" class="block rounded-[1.3rem] border border-slate-200 bg-slate-50 px-4 py-4 transition hover:border-slate-300 hover:bg-white">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-base font-semibold text-slate-900">{{ $device->hostname }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ $device->os_name }} {{ $device->os_version }}</p>
                                </div>
                                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $device->status === 'online' ? 'bg-emerald-100 text-emerald-700' : ($device->status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700') }}">
                                    {{ $device->status }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">Last seen {{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'never' }}</p>
                        </a>
                    @empty
                        <div class="rounded-[1.3rem] border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No devices yet.</div>
                    @endforelse
                </div>
            </div>

            <div class="board-surface rounded-[1.4rem] p-4">
                <div class="mb-4 flex items-center justify-between gap-2">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Recent Job Runs</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Latest execution traffic</h3>
                    </div>
                    <a href="{{ route('admin.jobs') }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700">Open Jobs</a>
                </div>
                <div class="space-y-3">
                    @forelse($recent_jobs as $job)
                        <div class="rounded-[1.3rem] border border-slate-200 bg-slate-50 px-4 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="font-mono text-xs text-slate-700 break-all">{{ $job->id }}</p>
                                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $job->status === 'success' ? 'bg-indigo-100 text-indigo-700' : ($job->status === 'failed' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700') }}">
                                    {{ $job->status }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">Updated {{ $job->updated_at ? $job->updated_at->diffForHumans() : 'recently' }}</p>
                        </div>
                    @empty
                        <div class="rounded-[1.3rem] border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No job runs yet.</div>
                    @endforelse
                </div>
            </div>

            <div class="board-surface rounded-[1.4rem] p-4">
                <div class="mb-4 flex items-center justify-between gap-2">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">AI Cases</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Recent anomaly review feed</h3>
                    </div>
                    <a href="{{ route('admin.behavior-ai.index') }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700">Open AI Center</a>
                </div>
                <div class="space-y-3">
                    @forelse($recent_behavior_ai_cases as $case)
                        <div class="rounded-[1.3rem] border border-slate-200 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-base font-semibold text-slate-900">{{ $case->summary }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Device <span class="font-mono">{{ $case->device_id }}</span> | Severity {{ $case->severity }} | Risk {{ number_format((float) $case->risk_score, 4) }}</p>
                                </div>
                                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $case->status === 'pending_review' ? 'bg-amber-100 text-amber-700' : (($case->status === 'approved' || $case->status === 'auto_applied') ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700') }}">
                                    {{ $case->status }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-[1.3rem] border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No AI anomaly cases recorded.</div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</x-admin-layout>
