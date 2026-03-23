<x-admin-layout title="Device Behavior Intelligence" heading="Device Behavior Intelligence">
    <div class="space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 win-panel">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Device Brain</p>
                    <h3 class="text-2xl font-semibold text-slate-900">{{ $device->hostname }}</h3>
                    <p class="text-sm text-slate-600 font-mono">{{ $device->id }}</p>
                </div>
                <a href="{{ route('admin.devices.show', $device->id) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 hover:bg-slate-50">
                    Back to Device Detail
                </a>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Events (24h)</p>
                    <p class="text-lg font-semibold text-slate-900">{{ number_format((int) ($stats['events_24h'] ?? 0)) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Events (7d)</p>
                    <p class="text-lg font-semibold text-slate-900">{{ number_format((int) ($stats['events_7d'] ?? 0)) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Event Types (7d)</p>
                    <p class="text-lg font-semibold text-slate-900">{{ number_format((int) ($stats['event_types_7d'] ?? 0)) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Anomaly Cases (7d)</p>
                    <p class="text-lg font-semibold text-slate-900">{{ number_format((int) ($stats['anomaly_cases_7d'] ?? 0)) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-wide text-slate-500">OpenAI Verdicts (7d)</p>
                    <p class="text-lg font-semibold text-slate-900">{{ number_format((int) ($stats['openai_verdicts_7d'] ?? 0)) }}</p>
                </div>
            </div>

            <div class="mt-4 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                @foreach(($verdict_distribution ?? []) as $classification => $count)
                    @php
                        $label = ucfirst((string) $classification);
                        $tone = match ($classification) {
                            'normal' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                            'suspicious' => 'border-amber-200 bg-amber-50 text-amber-700',
                            'malicious' => 'border-red-200 bg-red-50 text-red-700',
                            default => 'border-slate-200 bg-slate-50 text-slate-700',
                        };
                    @endphp
                    <div class="rounded-xl border px-3 py-2 {{ $tone }}">
                        <p class="font-medium">{{ $label }}</p>
                        <p class="mt-0.5 text-base font-semibold">{{ number_format((int) $count) }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-4 win-panel">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h4 class="font-semibold text-slate-900">Behavior History</h4>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Latest telemetry per event</span>
                </div>

                @if(! $history_table_ready)
                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        `device_behavior_logs` table is not available in this environment.
                    </p>
                @else
                    <div class="space-y-2">
                        @forelse($history_events as $event)
                            <article class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-xs">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-medium text-slate-900">{{ $event['event_type'] }}</p>
                                    <p class="text-slate-500">
                                        {{ optional($event['occurred_at'])->toDateTimeString() ?: 'n/a' }}
                                        @if(!empty($event['occurred_at_human']))
                                            ({{ $event['occurred_at_human'] }})
                                        @endif
                                    </p>
                                </div>
                                <p class="mt-1 text-slate-700">
                                    User: {{ $event['user_name'] !== '' ? $event['user_name'] : '-' }} |
                                    Process: {{ $event['process_name'] !== '' ? $event['process_name'] : '-' }}
                                </p>
                                <p class="mt-1 font-mono text-slate-600 break-all">
                                    {{ $event['file_path'] !== '' ? $event['file_path'] : '-' }}
                                </p>
                                @if(!empty($event['tags']))
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach($event['tags'] as $tag)
                                            <span class="rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] text-sky-700">{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @empty
                            <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                No behavior events available for this device yet.
                            </p>
                        @endforelse
                    </div>

                    @if($history_paginator && $history_paginator->hasPages())
                        <div class="mt-3">
                            {{ $history_paginator->onEachSide(1)->links() }}
                        </div>
                    @endif
                @endif
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 win-panel">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h4 class="font-semibold text-slate-900">OpenAI Verdict Timeline</h4>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Anomaly pipeline decisions</span>
                </div>

                @if(! $timeline_table_ready)
                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        `behavior_anomaly_cases` table is not available in this environment.
                    </p>
                @else
                    <div class="space-y-2">
                        @forelse($openai_timeline as $entry)
                            @php
                                $classificationTone = match ($entry['classification']) {
                                    'normal' => 'bg-emerald-100 text-emerald-700',
                                    'suspicious' => 'bg-amber-100 text-amber-700',
                                    'malicious' => 'bg-red-100 text-red-700',
                                    default => 'bg-slate-100 text-slate-700',
                                };
                                $riskAdjust = (float) ($entry['risk_adjustment'] ?? 0.0);
                                $riskAdjustLabel = $riskAdjust >= 0 ? '+'.number_format($riskAdjust, 4) : number_format($riskAdjust, 4);
                            @endphp
                            <article class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-xs">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] {{ $classificationTone }}">{{ $entry['classification_label'] }}</span>
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-700">{{ strtoupper((string) $entry['severity']) }}</span>
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-700">{{ $entry['recommended_action_label'] }}</span>
                                    </div>
                                    <p class="text-slate-500">
                                        {{ optional($entry['detected_at'])->toDateTimeString() ?: 'n/a' }}
                                        @if(!empty($entry['detected_at_human']))
                                            ({{ $entry['detected_at_human'] }})
                                        @endif
                                    </p>
                                </div>

                                <p class="mt-2 text-slate-800">{{ $entry['summary'] }}</p>
                                <p class="mt-1 text-slate-700">
                                    Confidence: <span class="font-semibold">{{ number_format((float) ($entry['confidence_percent'] ?? 0.0), 1) }}%</span> |
                                    Risk Score: <span class="font-semibold">{{ number_format((float) ($entry['risk_score'] ?? 0.0), 4) }}</span> |
                                    OpenAI Adjustment: <span class="font-semibold">{{ $riskAdjustLabel }}</span>
                                </p>
                                <p class="mt-1 text-slate-700">
                                    Case Status: {{ $entry['status'] }}
                                    @if(!empty($entry['model']))
                                        | Model: {{ $entry['model'] }}
                                    @endif
                                </p>
                                @if(!empty($entry['behavior_markers']))
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach($entry['behavior_markers'] as $marker)
                                            <span class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] text-slate-700">{{ $marker }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @empty
                            <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                No OpenAI verdicts available for this device yet.
                            </p>
                        @endforelse
                    </div>

                    @if($timeline_paginator && $timeline_paginator->hasPages())
                        <div class="mt-3">
                            {{ $timeline_paginator->onEachSide(1)->links() }}
                        </div>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-admin-layout>
