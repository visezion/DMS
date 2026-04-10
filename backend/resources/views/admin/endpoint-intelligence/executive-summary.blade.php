<x-admin-layout :title="'Executive Summary: '.$device->hostname" heading="Device Executive Summary">
    <div class="endpoint-intelligence-shell space-y-5">
    <section class="grid gap-3 md:grid-cols-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Device</p><p class="mt-2 text-lg font-semibold text-slate-900">{{ $device->hostname }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Health</p><p class="mt-2 text-3xl font-semibold text-slate-900">{{ $health?->score ?? 'N/A' }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Risk</p><p class="mt-2 text-3xl font-semibold text-slate-900">{{ $risk?->score ?? 'N/A' }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Open Findings</p><p class="mt-2 text-3xl font-semibold text-slate-900">{{ $findings->count() }}</p></article>
    </section>

    <section class="mt-5 grid gap-5 xl:grid-cols-[1fr,1fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Current Incident</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ $incident?->title ?? 'No open incident' }}</h3>
                </div>
                <a href="{{ route('admin.intelligence.telemetry.device', $device->id) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">Telemetry Detail</a>
            </div>
            <p class="mt-2 text-sm text-slate-600">{{ $incident?->summary ?? 'The device currently has no correlated open incident.' }}</p>
            <div class="mt-4 space-y-3">
                @foreach ($findings as $finding)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-medium text-slate-900">{{ data_get($finding->evidence ?? [], 'summary', $finding->finding_type) }}</p>
                            <span class="rounded-full bg-slate-200 px-2 py-1 text-xs text-slate-700">{{ $finding->severity }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Recent Action Outcomes</p>
            <div class="mt-4 space-y-3">
                @forelse ($recentActions as $action)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-medium text-slate-900">Action {{ $action->action_id }}</p>
                            <span class="rounded-full bg-slate-200 px-2 py-1 text-xs text-slate-700">{{ $action->status }}</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Job {{ $action->job_id }} | {{ optional($action->created_at)->diffForHumans() }}</p>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No remediation actions have run for this device.</div>
                @endforelse
            </div>
        </article>
    </section>
    </div>
</x-admin-layout>
