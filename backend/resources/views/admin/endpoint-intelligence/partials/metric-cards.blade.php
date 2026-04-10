@php
    $iconMap = [
        'health' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M4 12h3l2-5 4 10 2-5h5" /><path d="M12 21c5-3.2 8-6.3 8-10.4A4.6 4.6 0 0 0 12 7a4.6 4.6 0 0 0-8 3.6C4 14.7 7 17.8 12 21Z" /></svg>',
        'risk' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z" /><path d="M12 8v4" /><circle cx="12" cy="15.5" r="0.9" fill="currentColor" stroke="none" /></svg>',
        'devices' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><rect x="4" y="3" width="16" height="12" rx="2"/><path d="M8 21h8M12 15v6"/></svg>',
        'incident' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="7" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8.2 7.2 15.6 17"/><path d="M15.7 8.8 12.9 15.6"/></svg>',
        'assistant' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M12 3 9.9 8.4 4 10.5l5.9 2.1L12 18l2.1-5.4 5.9-2.1-5.9-2.1L12 3Z" /><path d="M5 3v3" /><path d="M19 18v3" /></svg>',
        'approval' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M12 3 5 6v6c0 4.7 3 7.9 7 9 4-1.1 7-4.3 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>',
        'action' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="m14.5 5.5 4 4"/><path d="M6.8 17.2 17 7a2.8 2.8 0 1 0-4-4L2.8 13.2a2 2 0 0 0-.5 1L2 20l5.8-.3a2 2 0 0 0 1-.5Z"/></svg>',
        'policy' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M7 7v10"/><path d="M17 7v10"/><circle cx="7" cy="11" r="2.5"/><circle cx="17" cy="13" r="2.5"/></svg>',
        'default' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>',
    ];

    $resolveIcon = static function (string $label) use ($iconMap): string {
        $label = strtolower($label);

        return match (true) {
            str_contains($label, 'risk'), str_contains($label, 'finding') => $iconMap['risk'],
            str_contains($label, 'incident'), str_contains($label, 'timeline') => $iconMap['incident'],
            str_contains($label, 'device'), str_contains($label, 'fleet') => $iconMap['devices'],
            str_contains($label, 'approval'), str_contains($label, 'sla') => $iconMap['approval'],
            str_contains($label, 'assistant'), str_contains($label, 'message') => $iconMap['assistant'],
            str_contains($label, 'policy'), str_contains($label, 'autonomy') => $iconMap['policy'],
            str_contains($label, 'action'), str_contains($label, 'rollback'), str_contains($label, 'plan') => $iconMap['action'],
            str_contains($label, 'health'), str_contains($label, 'failure') => $iconMap['health'],
            default => $iconMap['default'],
        };
    };
@endphp

<section class="endpoint-intelligence-metrics grid gap-3 md:grid-cols-2 xl:grid-cols-3">
    @foreach ($metrics as $label => $value)
        @php
            $normalizedLabel = str_replace('_', ' ', $label);
            $displayValue = is_scalar($value) || $value === null ? ($value ?? 'N/A') : json_encode($value);
        @endphp
        <article class="ei-metric-card rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ $normalizedLabel }}</p>
                    <p class="ei-metric-value mt-3 font-semibold text-slate-900">{{ $displayValue }}</p>
                </div>
                <span class="ei-metric-icon flex h-11 w-11 items-center justify-center rounded-2xl" aria-hidden="true">{!! $resolveIcon($normalizedLabel) !!}</span>
            </div>
            <div class="mt-4 flex items-center gap-2 text-xs text-slate-500">
                <span class="h-2 w-2 rounded-full" style="background: var(--brand-accent, var(--brand-primary));"></span>
                Live Endpoint Intelligence projection
            </div>
        </article>
    @endforeach
</section>
