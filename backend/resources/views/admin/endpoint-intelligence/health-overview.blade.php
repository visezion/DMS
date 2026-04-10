<x-admin-layout title="Fleet Health Overview" heading="Fleet Health Overview">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Operations Flow</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">Command Center pipeline</h3>
            </div>
            <a href="{{ route('admin.intelligence.assistant') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">Open AI Assistant</a>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($flowCards as $card)
                <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $card['title'] }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $card['count'] }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $card['detail'] }}</p>
                    <a href="{{ $card['href'] }}" class="mt-3 inline-flex rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700">{{ $card['cta'] }}</a>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mt-5 grid gap-5 xl:grid-cols-[0.9fr,1.1fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Health Bands</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Current fleet distribution</h3>
                </div>
                <a href="{{ route('admin.intelligence.risk') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">Open Risk Dashboard</a>
            </div>
            <div class="mt-4 space-y-3">
                @foreach ($bandCounts as $band => $count)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium capitalize text-slate-700">{{ $band }}</span>
                            <span class="text-slate-500">{{ $count }}</span>
                        </div>
                        <div class="ei-progress h-2 rounded-full">
                            <div
                                class="h-2 rounded-full"
                                style="width: {{ max(4, $count) }}%; background: {{ $band === 'critical' ? 'var(--brand-accent, var(--brand-primary))' : ($band === 'degraded' ? 'var(--brand-accent-soft-2, var(--brand-accent-soft, var(--brand-primary-soft)))' : ($band === 'warning' ? 'var(--brand-primary-border)' : 'var(--brand-primary)')) }};"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Most Unhealthy Devices</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Priority review list</h3>
                </div>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="pb-2">Device</th>
                            <th class="pb-2">Score</th>
                            <th class="pb-2">Band</th>
                            <th class="pb-2">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($topUnhealthy as $score)
                            <tr>
                                <td class="py-2 pr-3">
                                    <a class="ei-link font-medium" href="{{ route('admin.intelligence.health.device', $score->device_id) }}">{{ $topUnhealthyDeviceNames[$score->device_id] ?? $score->device_id }}</a>
                                    <p class="text-[11px] text-slate-500">{{ $score->device_id }}</p>
                                </td>
                                <td class="py-2 pr-3">{{ $score->score }}</td>
                                <td class="py-2 pr-3 capitalize">{{ $score->band }}</td>
                                <td class="py-2 text-slate-500">{{ optional($score->scored_at)->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-slate-500">No health scores yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Triage Queue</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">High-priority findings</h3>
            </div>
            <a href="{{ route('admin.intelligence.risk') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">Open Risk Dashboard</a>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="pb-2">Device</th>
                        <th class="pb-2">Finding</th>
                        <th class="pb-2">Severity</th>
                        <th class="pb-2">Confidence</th>
                        <th class="pb-2">Seen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($priorityFindings as $finding)
                        <tr>
                            <td class="py-2 pr-3">
                                @if ($finding->device_id)
                                    <a class="ei-link font-medium" href="{{ route('admin.intelligence.health.device', $finding->device_id) }}">{{ $priorityFindingDeviceNames[$finding->device_id] ?? $finding->device_id }}</a>
                                    <p class="text-[11px] text-slate-500">{{ $finding->device_id }}</p>
                                @else
                                    <span class="text-slate-600">Fleet scope</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3 capitalize">{{ str_replace('_', ' ', (string) $finding->finding_type) }}</td>
                            <td class="py-2 pr-3">
                                <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $finding->severity === 'critical' ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-amber-300 bg-amber-50 text-amber-700' }}">
                                    {{ $finding->severity }}
                                </span>
                            </td>
                            <td class="py-2 pr-3">{{ number_format((float) $finding->confidence, 2) }}</td>
                            <td class="py-2 text-slate-500">{{ optional($finding->last_seen_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-slate-500">No high-priority findings right now.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    </div>
</x-admin-layout>
