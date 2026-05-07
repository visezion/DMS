<x-admin-layout title="Engine / Rule Tuning" heading="Engine / Rule Tuning">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.smart-nav')
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Freshness SLO Checks</p>
        <h3 class="mt-1 text-lg font-semibold text-slate-900">Operational guardrails</h3>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.14em] text-slate-500">Stale Threshold</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ data_get($freshness, 'stale_after_minutes', 120) }} min</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.14em] text-slate-500">Latest Health</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ data_get($freshness, 'health_latest.age_human', 'No data yet') }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.14em] text-slate-500">Latest Risk</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ data_get($freshness, 'risk_latest.age_human', 'No data yet') }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs uppercase tracking-[0.14em] text-slate-500">Latest Finding</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ data_get($freshness, 'finding_latest.age_human', 'No active findings') }}</p>
            </article>
        </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Controlled Improvement</p>
        <h3 class="mt-1 text-lg font-semibold text-slate-900">Current tuning proposals</h3>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            @foreach ($suggestions as $suggestion)
                <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-medium text-slate-900">{{ $suggestion['engine'] }}</p>
                        <span class="ei-chip ei-chip-accent px-2 py-1 text-xs font-medium">{{ $suggestion['status'] }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-700">{{ $suggestion['suggestion'] }}</p>
                </article>
            @endforeach
        </div>
    </section>
    </div>
</x-admin-layout>
