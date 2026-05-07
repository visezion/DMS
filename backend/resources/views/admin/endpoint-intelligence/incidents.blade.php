<x-admin-layout title="Correlated Incident Explorer" heading="Correlated Incident Explorer">
    @php
        $openIncidents = (int) ($metrics['open_incidents'] ?? 0);
        $criticalIncidents = (int) ($metrics['critical_incidents'] ?? 0);
        $timelinesBuilt = (int) ($metrics['timelines_built'] ?? 0);
        $openFindings = (int) ($metrics['open_findings'] ?? 0);
        $heroActions = [
            ['href' => route('admin.intelligence.risk'), 'label' => 'Open Risk'],
            ['href' => route('admin.intelligence.health'), 'label' => 'Open Health'],
            ['href' => route('admin.intelligence.approvals'), 'label' => 'Open Approvals'],
            ['href' => route('admin.intelligence.remediation'), 'class' => 'ei-button-primary rounded-xl px-4 py-3 text-sm font-medium text-white', 'label' => 'Open Remediation'],
        ];
        $summaryCards = [
            ['label' => 'Open Incidents', 'value' => $openIncidents, 'description' => 'Incidents that still need investigation or action.'],
            ['class' => 'rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-rose-700', 'value_class' => 'mt-2 text-3xl font-semibold text-rose-900', 'description_class' => 'mt-1 text-sm text-rose-800', 'label' => 'Critical', 'value' => $criticalIncidents, 'description' => 'Highest-severity incidents requiring immediate attention.'],
            ['label' => 'Timelines Built', 'value' => $timelinesBuilt, 'description' => 'Tracked timeline events across incident investigations.'],
            ['class' => 'rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-amber-700', 'value_class' => 'mt-2 text-3xl font-semibold text-amber-900', 'description_class' => 'mt-1 text-sm text-amber-800', 'label' => 'Open Findings', 'value' => $openFindings, 'description' => 'Signals that may feed new or existing incidents.'],
        ];
    @endphp

    <div class="endpoint-intelligence-shell space-y-5">
        @include('admin.endpoint-intelligence.partials.smart-nav')
        @include('admin.endpoint-intelligence.partials.overview-hero', [
            'eyebrow' => 'Incidents',
            'title' => 'Understand related security events in one place',
            'description' => 'Incidents group related findings into one investigation view so admins can follow the story of an event instead of reviewing isolated alerts one by one.',
            'actions' => $heroActions,
        ])

        @include('admin.endpoint-intelligence.partials.overview-stats', [
            'cards' => $summaryCards,
        ])

        <section class="grid gap-5 xl:grid-cols-[0.85fr,1.15fr]">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">How To Use This Page</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Simple incident workflow</h3>
                </div>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">1. Start with critical incidents</p>
                        <p class="mt-1 text-xs text-slate-600">Those incidents usually represent the fastest path to real risk or production impact.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">2. Read the summary first</p>
                        <p class="mt-1 text-xs text-slate-600">The summary explains the event in plain language before you open the full timeline.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">3. Open the timeline</p>
                        <p class="mt-1 text-xs text-slate-600">Use the timeline to understand sequence, evidence, and whether the event is still active.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">4. Move to remediation if needed</p>
                        <p class="mt-1 text-xs text-slate-600">Use approvals or remediation when the investigation leads to a response action.</p>
                    </div>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Incident Queue</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">Current investigations</h3>
                        <p class="mt-1 text-sm text-slate-500">Open the timeline to review what happened and in what order.</p>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($incidents as $incident)
                        @php
                            $severity = strtolower((string) $incident->severity);
                        @endphp
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-slate-900">{{ $incident->title }}</p>
                                        <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $severity === 'critical' ? 'border-rose-300 bg-rose-50 text-rose-700' : ($severity === 'high' ? 'border-orange-300 bg-orange-50 text-orange-700' : 'border-amber-300 bg-amber-50 text-amber-700') }}">
                                            {{ $incident->severity }}
                                        </span>
                                        <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs capitalize text-slate-600">
                                            {{ $incident->status }}
                                        </span>
                                    </div>
                                    <p class="mt-2 text-sm text-slate-600">{{ $incident->summary ?: 'No summary available for this incident yet.' }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                                        <span class="ei-chip px-2 py-1">Confidence {{ $incident->confidence }}</span>
                                        <span class="ei-chip px-2 py-1">Opened {{ optional($incident->opened_at ?? $incident->created_at)->diffForHumans() }}</span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <a class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700" href="{{ route('admin.intelligence.incidents.timeline', $incident->id) }}">
                                        Open timeline
                                    </a>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                            No correlated incidents yet.
                        </div>
                    @endforelse
                </div>
            </article>
        </section>
    </div>
</x-admin-layout>
