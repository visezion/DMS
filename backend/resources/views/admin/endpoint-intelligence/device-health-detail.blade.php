<x-admin-layout :title="'Device Health: '.$device->hostname" :heading="$device->hostname">
    <div class="endpoint-intelligence-shell space-y-5">
    <section class="grid gap-3 md:grid-cols-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Health Score</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $health?->score ?? 'N/A' }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Health Band</p>
            <p class="mt-2 text-3xl font-semibold capitalize text-slate-900">{{ $health?->band ?? 'unknown' }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Risk Score</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $risk?->score ?? 'N/A' }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Failure Risk</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $health?->predicted_failure_risk ?? 'N/A' }}</p>
        </article>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Intelligence Freshness</p>
                <h3 class="mt-1 text-base font-semibold text-slate-900">Signal recency for this endpoint</h3>
            </div>
            <p class="text-xs text-slate-500">Stale threshold: {{ data_get($freshness, 'stale_after_minutes', 120) }} minutes</p>
        </div>
        <div class="mt-3 grid gap-3 md:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Health</p>
                <p class="mt-1 text-sm font-semibold {{ data_get($freshness, 'health.is_stale') ? 'text-amber-700' : 'text-slate-900' }}">
                    {{ data_get($freshness, 'health.age_human', 'No data yet') }}
                </p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Risk</p>
                <p class="mt-1 text-sm font-semibold {{ data_get($freshness, 'risk.is_stale') ? 'text-amber-700' : 'text-slate-900' }}">
                    {{ data_get($freshness, 'risk.age_human', 'No data yet') }}
                </p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.15em] text-slate-500">Active Findings</p>
                <p class="mt-1 text-sm font-semibold {{ data_get($freshness, 'finding.is_stale') ? 'text-amber-700' : 'text-slate-900' }}">
                    {{ data_get($freshness, 'finding.age_human', 'No active findings') }}
                </p>
            </article>
        </div>
    </section>

    <section class="mt-5 grid gap-5 xl:grid-cols-[1.1fr,0.9fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Top Contributors</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Why the score moved</h3>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.intelligence.executive', $device->id) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">Executive Summary</a>
                    <a href="{{ route('admin.intelligence.telemetry.device', $device->id) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">Telemetry Detail</a>
                </div>
            </div>
            <div class="mt-4 space-y-3">
                @forelse (($health?->contributors ?? []) as $contributor)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-slate-900">{{ $contributor['label'] ?? $contributor['factor'] ?? 'factor' }}</p>
                                <p class="text-xs text-slate-500">Observed value: {{ is_scalar($contributor['value'] ?? null) ? $contributor['value'] : json_encode($contributor['value'] ?? null) }}</p>
                            </div>
                            <span class="ei-chip ei-chip-accent px-2 py-1 text-xs font-medium">-{{ $contributor['impact'] ?? '0' }}</span>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No contributor breakdown is available yet.</div>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Recent Findings</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">Health and risk context</h3>
            <div class="mt-4 space-y-3">
                @forelse ($findings as $finding)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-medium text-slate-900">{{ data_get($finding->evidence ?? [], 'summary', $finding->finding_type) }}</p>
                            <span class="ei-chip px-2 py-1 text-xs font-medium {{ in_array($finding->severity, ['high','critical'], true) ? 'ei-chip-accent' : 'ei-chip-primary' }}">{{ $finding->severity }}</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Updated {{ optional($finding->last_seen_at)->diffForHumans() }}</p>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No findings for this device.</div>
                @endforelse
            </div>
        </article>
    </section>
    </div>
</x-admin-layout>
