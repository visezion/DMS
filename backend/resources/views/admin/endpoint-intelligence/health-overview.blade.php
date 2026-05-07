<x-admin-layout title="Fleet Health Overview" heading="Fleet Health Overview">
    @php
        $healthyCount = (int) ($bandCounts['healthy'] ?? 0);
        $warningCount = (int) ($bandCounts['warning'] ?? 0);
        $degradedCount = (int) ($bandCounts['degraded'] ?? 0);
        $criticalCount = (int) ($bandCounts['critical'] ?? 0);
        $needsAttentionCount = $warningCount + $degradedCount + $criticalCount;
        $pendingWorkCount = $flowCards[2]['count'] ?? 0;
        $healthFreshness = data_get($freshness, 'health_latest.age_human', 'No data yet');
        $riskFreshness = data_get($freshness, 'risk_latest.age_human', 'No data yet');
        $findingFreshness = data_get($freshness, 'finding_latest.age_human', 'No active findings');
        $staleThresholdMinutes = (int) data_get($freshness, 'stale_after_minutes', 120);
        $staleHealthDevices = (int) data_get($freshness, 'stale_health_devices', 0);
        $missingHealthDevices = (int) data_get($freshness, 'health_missing_devices', 0);
        $fleetAverage = isset($metrics['fleet_average']) ? number_format((float) $metrics['fleet_average'], 1) : 'N/A';
        $heroBadges = [
            ['class' => 'ei-chip ei-chip-primary', 'label' => 'Average score: '.$fleetAverage],
            ['class' => 'ei-chip', 'label' => 'Devices needing attention: '.$needsAttentionCount],
            ['class' => 'ei-chip', 'label' => 'Stale threshold: '.$staleThresholdMinutes.' min'],
        ];
        $heroActions = [
            ['href' => route('admin.intelligence.risk'), 'class' => 'ei-button-primary rounded-xl px-4 py-3 text-sm font-medium text-white', 'label' => 'Open Risk Dashboard'],
            ['href' => route('admin.intelligence.approvals'), 'label' => 'Open Approvals'],
            ['href' => route('admin.intelligence.remediation'), 'label' => 'Open Remediation'],
            ['href' => route('admin.intelligence.assistant'), 'label' => 'Ask AI Assistant'],
        ];
        $summaryCards = [
            ['label' => 'Healthy Devices', 'value' => $healthyCount, 'description' => 'Devices currently in the healthy band.'],
            ['class' => 'rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-amber-700', 'value_class' => 'mt-2 text-3xl font-semibold text-amber-900', 'description_class' => 'mt-1 text-sm text-amber-800', 'label' => 'Needs Review', 'value' => $needsAttentionCount, 'description' => 'Warning, degraded, and critical devices combined.'],
            ['class' => 'rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-rose-700', 'value_class' => 'mt-2 text-3xl font-semibold text-rose-900', 'description_class' => 'mt-1 text-sm text-rose-800', 'label' => 'Critical Devices', 'value' => $criticalCount, 'description' => 'Highest-priority health issues to review first.'],
            ['label' => 'Pending Actions', 'value' => $pendingWorkCount, 'description' => 'Approvals and remediation plans waiting for action.'],
        ];
    @endphp

    <div class="endpoint-intelligence-shell space-y-5">
        @include('admin.endpoint-intelligence.partials.smart-nav')
        @include('admin.endpoint-intelligence.partials.overview-hero', [
            'eyebrow' => 'Fleet Health',
            'title' => 'Simple view of what needs attention now',
            'description' => 'Start with devices in critical or degraded health, then review fresh high-priority findings and pending remediation work.',
            'badges' => $heroBadges,
            'actions' => $heroActions,
        ])

        @include('admin.endpoint-intelligence.partials.overview-stats', [
            'cards' => $summaryCards,
        ])

        <section class="grid gap-5 xl:grid-cols-[0.95fr,1.05fr]">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Fleet Status</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">Health bands</h3>
                    </div>
                    <span class="text-xs text-slate-500">Use this as the first triage split</span>
                </div>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-emerald-900">Healthy</p>
                                <p class="mt-1 text-xs text-emerald-700">No immediate action needed.</p>
                            </div>
                            <span class="text-2xl font-semibold text-emerald-900">{{ $healthyCount }}</span>
                        </div>
                    </div>
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-amber-900">Warning</p>
                                <p class="mt-1 text-xs text-amber-700">Watch closely before the condition worsens.</p>
                            </div>
                            <span class="text-2xl font-semibold text-amber-900">{{ $warningCount }}</span>
                        </div>
                    </div>
                    <div class="rounded-xl border border-orange-200 bg-orange-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-orange-900">Degraded</p>
                                <p class="mt-1 text-xs text-orange-700">Needs operator review and likely action.</p>
                            </div>
                            <span class="text-2xl font-semibold text-orange-900">{{ $degradedCount }}</span>
                        </div>
                    </div>
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-rose-900">Critical</p>
                                <p class="mt-1 text-xs text-rose-700">Review these first and escalate if needed.</p>
                            </div>
                            <span class="text-2xl font-semibold text-rose-900">{{ $criticalCount }}</span>
                        </div>
                    </div>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Data Freshness</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">How current the intelligence is</h3>
                    </div>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Health Scores</p>
                        <p class="mt-2 text-base font-semibold text-slate-900">{{ $healthFreshness }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            Stale devices: {{ $staleHealthDevices }} | Missing: {{ $missingHealthDevices }}
                        </p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Risk Scores</p>
                        <p class="mt-2 text-base font-semibold text-slate-900">{{ $riskFreshness }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            Stale devices: {{ data_get($freshness, 'stale_risk_devices', 0) }} |
                            Missing: {{ data_get($freshness, 'risk_missing_devices', 0) }}
                        </p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Active Findings</p>
                        <p class="mt-2 text-base font-semibold text-slate-900">{{ $findingFreshness }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            Shows how recent the evidence is behind current findings.
                        </p>
                    </article>
                </div>
            </article>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Review First</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Devices that need operator attention</h3>
                    <p class="mt-1 text-sm text-slate-500">Start at the top of this list and open the device detail page for investigation.</p>
                </div>
                <a href="{{ route('admin.intelligence.risk') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">
                    Open Risk Dashboard
                </a>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="pb-2">Device</th>
                            <th class="pb-2">Score</th>
                            <th class="pb-2">Status</th>
                            <th class="pb-2">Updated</th>
                            <th class="pb-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($topUnhealthy as $score)
                            <tr>
                                <td class="py-2 pr-3">
                                    <p class="font-medium text-slate-900">{{ $topUnhealthyDeviceNames[$score->device_id] ?? $score->device_id }}</p>
                                    <p class="text-[11px] text-slate-500">{{ $score->device_id }}</p>
                                </td>
                                <td class="py-2 pr-3 font-medium text-slate-900">{{ $score->score }}</td>
                                <td class="py-2 pr-3">
                                    <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $score->band === 'critical' ? 'border-rose-300 bg-rose-50 text-rose-700' : ($score->band === 'degraded' ? 'border-orange-300 bg-orange-50 text-orange-700' : ($score->band === 'warning' ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-emerald-300 bg-emerald-50 text-emerald-700')) }}">
                                        {{ $score->band }}
                                    </span>
                                </td>
                                <td class="py-2 pr-3 text-slate-500">{{ optional($score->scored_at)->diffForHumans() }}</td>
                                <td class="py-2">
                                    <a class="ei-link font-medium" href="{{ route('admin.intelligence.health.device', $score->device_id) }}">Open device</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-8 text-center text-slate-500">No unhealthy devices found right now.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Active Findings</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">High-priority alerts</h3>
                    <p class="mt-1 text-sm text-slate-500">These are the strongest signals currently driving health and risk concerns.</p>
                </div>
                <a href="{{ route('admin.intelligence.assistant') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">
                    Ask AI Assistant
                </a>
            </div>
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse ($priorityFindings as $finding)
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold capitalize text-slate-900">{{ str_replace('_', ' ', (string) $finding->finding_type) }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    @if ($finding->device_id)
                                        {{ $priorityFindingDeviceNames[$finding->device_id] ?? $finding->device_id }}
                                    @else
                                        Fleet scope
                                    @endif
                                </p>
                            </div>
                            <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $finding->severity === 'critical' ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-amber-300 bg-amber-50 text-amber-700' }}">
                                {{ $finding->severity }}
                            </span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                            <span class="ei-chip px-2 py-1">Confidence {{ number_format((float) $finding->confidence, 0) }}</span>
                            <span class="ei-chip px-2 py-1">Seen {{ optional($finding->last_seen_at)->diffForHumans() }}</span>
                        </div>
                        @if ($finding->device_id)
                            <div class="mt-3">
                                <a class="ei-link text-sm font-medium" href="{{ route('admin.intelligence.health.device', $finding->device_id) }}">Open device detail</a>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 lg:col-span-2">
                        No high-priority findings right now.
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-admin-layout>
