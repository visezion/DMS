<x-admin-layout :title="$incident->title" heading="Incident Timeline">
    <div class="endpoint-intelligence-shell space-y-5">
    <section class="grid gap-3 md:grid-cols-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Severity</p><p class="mt-2 text-2xl font-semibold capitalize text-slate-900">{{ $incident->severity }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Confidence</p><p class="mt-2 text-2xl font-semibold text-slate-900">{{ $incident->confidence }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Status</p><p class="mt-2 text-2xl font-semibold capitalize text-slate-900">{{ $incident->status }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase tracking-[0.18em] text-slate-500">Device</p><p class="mt-2 text-sm font-semibold text-slate-900">{{ $incident->primary_device_id }}</p></article>
    </section>

    <section class="mt-5 grid gap-5 xl:grid-cols-[1.2fr,0.8fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Event Timeline</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ $incident->title }}</h3>
            <div class="mt-4 space-y-3">
                @forelse ($events as $event)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-slate-900">{{ $event->event_type }}</p>
                                <p class="text-xs text-slate-500">{{ $event->actor_user ?? 'system' }} | {{ optional($event->occurred_at)->toIso8601String() }}</p>
                            </div>
                            <span class="rounded-full bg-slate-200 px-2 py-1 text-xs text-slate-700">{{ $event->risk_delta }}</span>
                        </div>
                        @if(!empty($event->evidence))
                            <pre class="mt-2 whitespace-pre-wrap break-all text-xs text-slate-600">{{ json_encode($event->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No timeline events are attached yet.</div>
                @endforelse
            </div>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Narrative Versions</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">Generated summaries</h3>
            <div class="mt-4 space-y-3">
                @forelse ($incident->timelines as $timeline)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-medium text-slate-900">Version {{ $timeline->version }}</p>
                            <span class="text-xs text-slate-500">{{ optional($timeline->generated_at)->diffForHumans() }}</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-700">{{ $timeline->summary }}</p>
                        <pre class="mt-2 whitespace-pre-wrap break-all text-xs text-slate-600">{{ $timeline->narrative }}</pre>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No incident narratives built yet.</div>
                @endforelse
            </div>
        </article>
    </section>
    </div>
</x-admin-layout>
