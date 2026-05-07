<x-admin-layout title="Risk & Threat Dashboard" heading="Risk & Threat Dashboard">
    @php
        $fleetRiskAverage = isset($metrics['fleet_risk_average']) ? number_format((float) $metrics['fleet_risk_average'], 1) : 'N/A';
        $openFindingsCount = (int) ($metrics['open_findings'] ?? 0);
        $highOrCriticalCount = (int) ($metrics['high_or_critical'] ?? 0);
        $devicesAtRiskCount = (int) ($metrics['devices_at_risk'] ?? 0);
        $staleRiskDevices = (int) data_get($freshness, 'stale_risk_devices', 0);
        $missingRiskScores = (int) data_get($freshness, 'risk_missing_devices', 0);
        $latestRiskUpdate = data_get($freshness, 'risk_latest.age_human', 'No data yet');
        $staleThresholdMinutes = (int) data_get($freshness, 'stale_after_minutes', 120);
        $heroBadges = [
            ['class' => 'ei-chip ei-chip-primary', 'label' => 'Average risk: '.$fleetRiskAverage],
            ['class' => 'ei-chip', 'label' => 'Latest update: '.$latestRiskUpdate],
            ['class' => 'ei-chip', 'label' => 'Stale threshold: '.$staleThresholdMinutes.' min'],
        ];
        $heroActions = [
            ['href' => route('admin.intelligence.health'), 'label' => 'Open Health'],
            ['href' => route('admin.intelligence.incidents'), 'label' => 'Open Incidents'],
            ['href' => route('admin.intelligence.approvals'), 'label' => 'Open Approvals'],
            ['href' => route('admin.intelligence.remediation'), 'class' => 'ei-button-primary rounded-xl px-4 py-3 text-sm font-medium text-white', 'label' => 'Open Remediation'],
        ];
        $summaryCards = [
            ['label' => 'Open Findings', 'value' => $openFindingsCount, 'description' => 'All active risk findings currently in queue.'],
            ['class' => 'rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-rose-700', 'value_class' => 'mt-2 text-3xl font-semibold text-rose-900', 'description_class' => 'mt-1 text-sm text-rose-800', 'label' => 'High Priority', 'value' => $highOrCriticalCount, 'description' => 'High and critical findings that should be reviewed first.'],
            ['class' => 'rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-amber-700', 'value_class' => 'mt-2 text-3xl font-semibold text-amber-900', 'description_class' => 'mt-1 text-sm text-amber-800', 'label' => 'Devices At Risk', 'value' => $devicesAtRiskCount, 'description' => 'Devices with elevated risk scores.'],
            ['label' => 'Data Gaps', 'value' => $staleRiskDevices + $missingRiskScores, 'description' => 'Stale or missing risk scores that can reduce confidence.'],
        ];
    @endphp

    <div class="endpoint-intelligence-shell space-y-5">
        @include('admin.endpoint-intelligence.partials.smart-nav')
        @include('admin.endpoint-intelligence.partials.overview-hero', [
            'eyebrow' => 'Risk Dashboard',
            'title' => 'See what is risky, why it matters, and what to review first',
            'description' => 'Use this page to identify endpoints with elevated risk, review active findings, and move into approvals or remediation when action is needed.',
            'badges' => $heroBadges,
            'actions' => $heroActions,
        ])

        @include('admin.endpoint-intelligence.partials.overview-stats', [
            'cards' => $summaryCards,
        ])

        <section class="grid gap-5 xl:grid-cols-[0.9fr,1.1fr]">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">What To Check</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">Risk review checklist</h3>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">1. Confirm the signal is current</p>
                        <p class="mt-1 text-xs text-slate-600">Latest risk update: {{ $latestRiskUpdate }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">2. Start with high and critical findings</p>
                        <p class="mt-1 text-xs text-slate-600">{{ $highOrCriticalCount }} findings are already in the priority queue.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">3. Open the affected device</p>
                        <p class="mt-1 text-xs text-slate-600">Use device detail for operational investigation and executive summary for security context.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">4. Move into approvals or remediation</p>
                        <p class="mt-1 text-xs text-slate-600">Use those pages when a response action needs review, approval, execution, or rollback.</p>
                    </div>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Risk Freshness</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">How current the scoring is</h3>
                    </div>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Latest Risk Update</p>
                        <p class="mt-2 text-base font-semibold text-slate-900">{{ $latestRiskUpdate }}</p>
                        <p class="mt-1 text-xs text-slate-500">Most recent risk scoring signal received.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Stale Risk Devices</p>
                        <p class="mt-2 text-base font-semibold text-slate-900">{{ $staleRiskDevices }}</p>
                        <p class="mt-1 text-xs text-slate-500">Devices whose scores may be too old to trust fully.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Missing Risk Scores</p>
                        <p class="mt-2 text-base font-semibold text-slate-900">{{ $missingRiskScores }}</p>
                        <p class="mt-1 text-xs text-slate-500">Devices without current risk scoring data.</p>
                    </article>
                </div>
            </article>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Review First</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Highest risk devices</h3>
                    <p class="mt-1 text-sm text-slate-500">These are the best starting points when you need to triage current risk quickly.</p>
                </div>
            </div>
            <div class="mt-4 grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
                @forelse ($topDevices as $score)
                    @php
                        $deviceId = (string) ($score->device_id ?? '');
                        $deviceName = $deviceNames->get($deviceId);
                    @endphp
                    <article class="rounded-xl border {{ $deviceName ? 'border-slate-200 bg-slate-50' : 'border-amber-200 bg-amber-50' }} p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold {{ $deviceName ? 'text-slate-900' : 'text-amber-900' }}">
                                    {{ $deviceName ?: 'Unknown device record' }}
                                </p>
                                <p class="mt-1 text-xs {{ $deviceName ? 'text-slate-500' : 'text-amber-800' }}">
                                    ID: {{ $deviceId !== '' ? $deviceId : 'missing' }}
                                </p>
                            </div>
                            <span class="rounded-full {{ $deviceName ? 'border border-rose-300 bg-rose-50 text-rose-700' : 'bg-amber-100 text-amber-800' }} px-2 py-1 text-xs font-medium">
                                Risk {{ $score->score }}
                            </span>
                        </div>
                        <p class="mt-3 text-xs {{ $deviceName ? 'text-slate-500' : 'text-amber-800' }}">
                            Updated {{ optional($score->scored_at)->diffForHumans() }}
                        </p>
                        @if ($deviceName)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('admin.intelligence.health.device', $deviceId) }}" class="ei-link text-sm font-medium">Open device</a>
                                <a href="{{ route('admin.intelligence.executive', $deviceId) }}" class="ei-link text-sm font-medium">Executive summary</a>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 lg:col-span-2 xl:col-span-3">
                        No risk scores are available yet.
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Active Findings</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Current risk queue</h3>
                    <p class="mt-1 text-sm text-slate-500">Read these as the reasons a device or the fleet is being scored as risky.</p>
                </div>
                <a href="{{ route('admin.intelligence.incidents') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">
                    Open Incidents
                </a>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="pb-2">Finding</th>
                            <th class="pb-2">Priority</th>
                            <th class="pb-2">Status</th>
                            <th class="pb-2">Device</th>
                            <th class="pb-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($findings as $finding)
                            @php
                                $deviceId = (string) ($finding->device_id ?? '');
                                $deviceName = $deviceNames->get($deviceId);
                                $severity = strtolower((string) $finding->severity);
                            @endphp
                            <tr>
                                <td class="py-2 pr-3">
                                    <p class="font-medium text-slate-900">{{ data_get($finding->evidence ?? [], 'summary', $finding->finding_type) }}</p>
                                    <p class="text-[11px] text-slate-500 capitalize">{{ str_replace('_', ' ', (string) $finding->finding_type) }}</p>
                                </td>
                                <td class="py-2 pr-3">
                                    <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $severity === 'critical' ? 'border-rose-300 bg-rose-50 text-rose-700' : ($severity === 'high' ? 'border-orange-300 bg-orange-50 text-orange-700' : 'border-amber-300 bg-amber-50 text-amber-700') }}">
                                        {{ $finding->severity }}
                                    </span>
                                </td>
                                <td class="py-2 pr-3 capitalize text-slate-700">{{ $finding->status }}</td>
                                <td class="py-2 pr-3">
                                    @if ($deviceName)
                                        <p class="font-medium text-slate-900">{{ $deviceName }}</p>
                                        <p class="text-[11px] text-slate-500">ID: {{ $deviceId }}</p>
                                    @elseif ($deviceId === '')
                                        <p class="text-xs text-amber-700">No device attached</p>
                                    @else
                                        <p class="text-xs text-amber-700">Device record unavailable</p>
                                        <p class="font-mono text-[11px] text-slate-500">ID: {{ $deviceId }}</p>
                                    @endif
                                </td>
                                <td class="py-2">
                                    @if ($deviceName)
                                        <div class="flex flex-wrap gap-2">
                                            <a class="ei-link text-sm font-medium" href="{{ route('admin.intelligence.health.device', $deviceId) }}">Open device</a>
                                            <a class="ei-link text-sm font-medium" href="{{ route('admin.intelligence.executive', $deviceId) }}">Summary</a>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">No linked action</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-sm text-slate-500">No findings in the current queue.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-admin-layout>
