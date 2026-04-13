<x-admin-layout title="Risk & Threat Dashboard" heading="Risk & Threat Dashboard">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Risk Freshness</p>
        <h3 class="mt-1 text-lg font-semibold text-slate-900">Latest scoring signal</h3>
        <div class="mt-3 grid gap-3 md:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Latest Risk Update</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ data_get($freshness, 'risk_latest.age_human', 'No data yet') }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Stale Risk Devices</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ data_get($freshness, 'stale_risk_devices', 0) }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Missing Risk Scores</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ data_get($freshness, 'risk_missing_devices', 0) }}</p>
            </article>
        </div>
    </section>

    <section class="mt-5 grid gap-5 xl:grid-cols-[1.15fr,0.85fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Open Findings</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Current queue</h3>
                </div>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="pb-2">Finding</th>
                            <th class="pb-2">Severity</th>
                            <th class="pb-2">Status</th>
                            <th class="pb-2">Device</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($findings as $finding)
                            @php
                                $deviceId = (string) ($finding->device_id ?? '');
                                $deviceName = $deviceNames->get($deviceId);
                            @endphp
                            <tr>
                                <td class="py-2 pr-3">{{ data_get($finding->evidence ?? [], 'summary', $finding->finding_type) }}</td>
                                <td class="py-2 pr-3 capitalize">{{ $finding->severity }}</td>
                                <td class="py-2 pr-3 capitalize">{{ $finding->status }}</td>
                                <td class="py-2">
                                    @if ($deviceName)
                                        <a class="ei-link" href="{{ route('admin.intelligence.executive', $deviceId) }}">{{ $deviceName }}</a>
                                        <p class="text-[11px] text-slate-500">ID: {{ $deviceId }}</p>
                                    @elseif ($deviceId === '')
                                        <p class="text-xs text-amber-700">No device was attached to this finding.</p>
                                    @else
                                        <p class="text-xs text-amber-700">Device record unavailable. It may have been deleted or not synced yet.</p>
                                        <p class="font-mono text-[11px] text-slate-500">ID: {{ $deviceId }}</p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 text-sm text-slate-500">No findings in the current queue.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Highest Risk Devices</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">Prioritize review</h3>
            <div class="mt-4 space-y-3">
                @forelse ($topDevices as $score)
                    @php
                        $deviceId = (string) ($score->device_id ?? '');
                        $deviceName = $deviceNames->get($deviceId);
                    @endphp
                    @if ($deviceName)
                        <a href="{{ route('admin.intelligence.executive', $deviceId) }}" class="block rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-medium text-slate-900">{{ $deviceName }}</p>
                                    <p class="text-xs text-slate-500">ID: {{ $deviceId }} | {{ optional($score->scored_at)->diffForHumans() }}</p>
                                </div>
                                <span class="ei-chip ei-chip-accent px-2 py-1 text-xs font-medium">{{ $score->score }}</span>
                            </div>
                        </a>
                    @else
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-medium text-amber-900">Unknown device record</p>
                                    <p class="text-xs text-amber-800">ID: {{ $deviceId !== '' ? $deviceId : 'missing' }} | This score references a device that no longer exists in inventory.</p>
                                </div>
                                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">{{ $score->score }}</span>
                            </div>
                        </div>
                    @endif
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No risk scores yet.</div>
                @endforelse
            </div>
        </article>
    </section>
    </div>
</x-admin-layout>
