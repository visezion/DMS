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
        $coverageRate = round((((int) ($metrics['devices_enrolled'] ?? 0)) / $deviceTotal) * 100, 1);

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

        $overviewCards = [
            ['label' => 'Policies / 100 devices', 'value' => $policyDensity],
            ['label' => 'Packages / 100 devices', 'value' => $packageDensity],
            ['label' => 'Replay Rejects', 'value' => (int) ($metrics['replay_rejects'] ?? 0)],
            ['label' => 'Failed / Device', 'value' => $failureRate.'%'],
        ];

        $intelligenceTrend = $jobTrend->values()->map(function (array $point, int $index) use ($anomalyTrend) {
            $dayTotal = max(1, (int) (($point['success'] ?? 0) + ($point['failed'] ?? 0) + ($point['active'] ?? 0)));
            $daySuccessRate = ((int) ($point['success'] ?? 0) / $dayTotal) * 100;
            $dayRisk = max(0, min(100, 100 - $daySuccessRate));
            $dayIncidents = (int) (($anomalyTrend[$index]['total'] ?? 0));
            $projection = max(0, min(100, ($dayRisk * 0.62) + min(38, $dayIncidents * 8.5)));

            return [
                'label' => $point['label'] ?? now()->format('M d'),
                'projection' => round($projection, 1),
                'incidents' => $dayIncidents,
            ];
        });
        $projectionNext24h = round(min(100, max(0, (($intelligenceTrend->take(-3)->avg('projection') ?? 0) * 0.68) + ($riskScore * 0.32))), 1);
        $fleetAverageHealth = round(max(0, min(100, ($onlineRate + $complianceRate + $jobSuccessRate) / 3)), 1);
        $fleetAverageRisk = round(max(0, min(100, 100 - $fleetAverageHealth)), 1);
        $incidentDailyAverage = round((float) ($anomalyTrend->avg('total') ?? 0), 1);
        $incidentChartMax = max(1, (int) $intelligenceTrend->max('incidents'));
        $projectionTone = $projectionNext24h >= 60 ? 'text-rose-700 bg-rose-50 border-rose-200' : ($projectionNext24h >= 35 ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');
        $fleetRiskTone = $fleetAverageRisk >= 60 ? 'text-rose-700 bg-rose-50 border-rose-200' : ($fleetAverageRisk >= 35 ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');
        $fleetAverageCards = [
            ['label' => 'Fleet Average Health', 'value' => $fleetAverageHealth.'%', 'barClass' => 'bg-teal-500', 'barValue' => $fleetAverageHealth],
            ['label' => 'Fleet Average Risk', 'value' => $fleetAverageRisk.'%', 'barClass' => 'bg-rose-500', 'barValue' => $fleetAverageRisk],
            ['label' => 'Incidents / Day', 'value' => $incidentDailyAverage, 'barClass' => 'bg-amber-500', 'barValue' => min(100, $incidentDailyAverage * 14)],
            ['label' => 'Projected Risk (24h)', 'value' => $projectionNext24h.'%', 'barClass' => 'bg-sky-500', 'barValue' => $projectionNext24h],
        ];

        $recentPanels = [
            ['type' => 'devices', 'eyebrow' => 'Recent Devices', 'title' => 'Last touched endpoints', 'route' => route('admin.devices'), 'cta' => 'Open Devices'],
            ['type' => 'jobs', 'eyebrow' => 'Recent Job Runs', 'title' => 'Latest execution traffic', 'route' => route('admin.jobs'), 'cta' => 'Open Jobs'],
            ['type' => 'risk', 'eyebrow' => 'Behavior Oversight', 'title' => 'Recent anomaly review feed', 'route' => route('admin.intelligence.risk'), 'cta' => 'Open Risk'],
        ];
        $freshnessThreshold = (int) data_get($intelligenceFreshness ?? [], 'stale_after_minutes', 120);
        $healthFreshnessAge = (string) data_get($intelligenceFreshness ?? [], 'health_latest.age_human', 'No data yet');
        $riskFreshnessAge = (string) data_get($intelligenceFreshness ?? [], 'risk_latest.age_human', 'No data yet');
        $findingFreshnessAge = (string) data_get($intelligenceFreshness ?? [], 'finding_latest.age_human', 'No active findings');
        $staleHealthDevices = (int) data_get($intelligenceFreshness ?? [], 'stale_health_devices', 0);
        $staleRiskDevices = (int) data_get($intelligenceFreshness ?? [], 'stale_risk_devices', 0);
        $missingHealthDevices = (int) data_get($intelligenceFreshness ?? [], 'health_missing_devices', 0);
        $missingRiskDevices = (int) data_get($intelligenceFreshness ?? [], 'risk_missing_devices', 0);
    @endphp
<div id="admin-dashboard-root" class="space-y-4">
        <section class="hero-surface rounded-[1.5rem] p-4 lg:p-5">
            <div class="relative z-10">
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
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Fleet Coverage</p>
                        <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $coverageRate }}%</p>
                        <p class="mt-1 text-xs text-slate-500">Enrolled {{ $metrics['devices_enrolled'] }} of {{ $metrics['devices_total'] }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-12">
            <div class="board-surface self-start rounded-[1.4rem] p-4 xl:col-span-8">
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

            <aside class="board-surface self-start rounded-[1.4rem] p-4 xl:col-span-4">
                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Quick Overview</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    @foreach($overviewCards as $overviewCard)
                        <div class="metric-card rounded-[1rem] p-3.5">
                            <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ $overviewCard['label'] }}</p>
                            <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $overviewCard['value'] }}</p>
                        </div>
                    @endforeach
                </div>
            </aside>
        </section>

        <section class="grid gap-4 xl:grid-cols-12">
            <div class="board-surface self-start rounded-[1.4rem] p-4 xl:col-span-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Live Endpoint Intelligence</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Projection, risk, incidents</h3>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-slate-300 bg-white px-3 py-1 text-[11px] font-medium text-slate-600">
                            Freshness threshold {{ $freshnessThreshold }} min
                        </span>
                        <a href="{{ route('admin.intelligence.health') }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700">Open Health</a>
                        <a href="{{ route('admin.intelligence.assistant') }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700">Open Assistant</a>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Projection (24h)</p>
                        <div class="mt-1 flex items-center gap-2">
                            <p class="text-2xl font-semibold text-slate-900">{{ $projectionNext24h }}%</p>
                            <span class="rounded-full border px-2.5 py-1 text-[11px] font-medium {{ $projectionTone }}">Forecast</span>
                        </div>
                    </div>
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Fleet Average Risk</p>
                        <div class="mt-1 flex items-center gap-2">
                            <p class="text-2xl font-semibold text-slate-900">{{ $fleetAverageRisk }}%</p>
                            <span class="rounded-full border px-2.5 py-1 text-[11px] font-medium {{ $fleetRiskTone }}">Current</span>
                        </div>
                    </div>
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Incident Load</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $incidentDailyAverage }} / day</p>
                        <p class="text-xs text-slate-500">Based on last 7 days anomaly feed</p>
                    </div>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Health Freshness</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $healthFreshnessAge }}</p>
                        <p class="text-xs text-slate-500">Stale {{ $staleHealthDevices }} | missing {{ $missingHealthDevices }}</p>
                    </div>
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Risk Freshness</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $riskFreshnessAge }}</p>
                        <p class="text-xs text-slate-500">Stale {{ $staleRiskDevices }} | missing {{ $missingRiskDevices }}</p>
                    </div>
                    <div class="metric-card rounded-[1rem] p-3.5">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Findings Freshness</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $findingFreshnessAge }}</p>
                        <p class="text-xs text-slate-500">Older findings can remain visible until new telemetry/check-ins arrive.</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 xl:grid-cols-[0.78fr,1.22fr]">
                    <div class="rounded-[1.1rem] border border-slate-200 bg-slate-50 p-3.5">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Risk Model</p>
                        <div class="mt-2 space-y-2 text-sm text-slate-600">
                            <p class="flex items-center justify-between gap-2"><span>Execution risk</span><span class="font-semibold text-slate-900">62%</span></p>
                            <p class="flex items-center justify-between gap-2"><span>Incident pressure</span><span class="font-semibold text-slate-900">38%</span></p>
                            <p class="flex items-center justify-between gap-2"><span>Daily anomalies</span><span class="font-semibold text-slate-900">{{ $incidentDailyAverage }}</span></p>
                        </div>
                    </div>

                    <div class="overflow-x-auto pb-2">
                        <div class="chart-scroll intelligence-chart-scroll">
                            @foreach($intelligenceTrend as $trendPoint)
                                @php
                                    $projectionHeight = max(8, min(100, (float) $trendPoint['projection']));
                                    $incidentHeight = max(8, min(100, ((int) $trendPoint['incidents'] / $incidentChartMax) * 100));
                                @endphp
                                <div class="chart-col">
                                    <div class="chart-well">
                                        <div class="flex h-full items-end gap-1.5">
                                            <div class="w-2 rounded-full bg-sky-500" style="height: {{ $projectionHeight }}%"></div>
                                            <div class="w-2 rounded-full bg-rose-400" style="height: {{ $incidentHeight }}%"></div>
                                        </div>
                                    </div>
                                    <div class="mt-3 text-center">
                                        <p class="text-base font-semibold text-slate-900">{{ $trendPoint['projection'] }}%</p>
                                        <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ $trendPoint['label'] }}</p>
                                        <p class="text-[11px] text-slate-500">{{ $trendPoint['incidents'] }} incidents</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <aside class="board-surface self-start rounded-[1.4rem] p-4 xl:col-span-4">
                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Fleet Averages</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    @foreach($fleetAverageCards as $fleetAverageCard)
                        <div class="metric-card rounded-[1rem] p-3.5">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ $fleetAverageCard['label'] }}</p>
                                <p class="text-sm font-semibold text-slate-900">{{ $fleetAverageCard['value'] }}</p>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full {{ $fleetAverageCard['barClass'] }}" style="width: {{ max(4, min(100, (float) $fleetAverageCard['barValue'])) }}%"></div>
                            </div>
                        </div>
                    @endforeach
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
            @foreach($recentPanels as $panel)
                <div class="board-surface rounded-[1.4rem] p-4">
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">{{ $panel['eyebrow'] }}</p>
                            <h3 class="mt-1 text-xl font-semibold text-slate-900">{{ $panel['title'] }}</h3>
                        </div>
                        <a href="{{ $panel['route'] }}" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700">{{ $panel['cta'] }}</a>
                    </div>
                    <div class="space-y-3">
                        @if($panel['type'] === 'devices')
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
                        @elseif($panel['type'] === 'jobs')
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
                        @else
                            @forelse($recent_anomaly_cases as $case)
                                <div class="rounded-[1.3rem] border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-base font-semibold text-slate-900">{{ $case->summary }}</p>
                                            <p class="mt-1 text-sm text-slate-500">{{ $case->device?->hostname ?? 'Unknown device' }}</p>
                                        </div>
                                        <span class="rounded-full px-3 py-1 text-xs font-medium {{ $case->severity === 'critical' ? 'bg-rose-100 text-rose-700' : ($case->severity === 'high' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700') }}">
                                            {{ $case->severity }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-slate-500">
                                        <span>Status {{ str_replace('_', ' ', $case->status) }}</span>
                                        <span>Detected {{ $case->detected_at ? $case->detected_at->diffForHumans() : 'recently' }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-[1.3rem] border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No anomaly cases awaiting review.</div>
                            @endforelse
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    </div>
</x-admin-layout>
