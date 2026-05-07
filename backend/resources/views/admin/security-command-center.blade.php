<x-admin-layout title="Security Hardening" heading="Security Hardening">
    @php
        $toneMap = [
            'good' => ['label' => 'Good', 'chip' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'panel' => 'border-emerald-200'],
            'warning' => ['label' => 'Needs Action', 'chip' => 'border-amber-200 bg-amber-50 text-amber-700', 'panel' => 'border-amber-200'],
            'info' => ['label' => 'Info', 'chip' => 'border-sky-200 bg-sky-50 text-sky-700', 'panel' => 'border-sky-200'],
        ];
        $priorityMap = [
            'critical' => ['label' => 'Critical', 'chip' => 'border-rose-200 bg-rose-50 text-rose-700'],
            'high' => ['label' => 'High', 'chip' => 'border-orange-200 bg-orange-50 text-orange-700'],
            'medium' => ['label' => 'Medium', 'chip' => 'border-amber-200 bg-amber-50 text-amber-700'],
            'low' => ['label' => 'Low', 'chip' => 'border-slate-200 bg-slate-50 text-slate-700'],
        ];

        $score = (int) ($summary['score'] ?? 0);
        $scoreSafe = max(0, min(100, $score));
        $scoreDeg = (int) round(($scoreSafe / 100) * 360);

        $controlsCollection = collect($controls ?? []);
        $criticalWarnings = $controlsCollection->filter(fn ($c) => ($c['status'] ?? '') === 'warning' && strtolower((string) ($c['priority'] ?? '')) === 'critical')->count();
        $highWarnings = $controlsCollection->filter(fn ($c) => ($c['status'] ?? '') === 'warning' && strtolower((string) ($c['priority'] ?? '')) === 'high')->count();
    @endphp

    <style>
        .security-hardening-shell {
            --sh-border: #d7deea;
            --sh-border-strong: #cbd5e1;
            --sh-panel-bg: #ffffff;
            --sh-panel-muted: #f8fafc;
            --sh-text-muted: #64748b;
            --sh-shadow: 0 18px 42px rgba(15, 23, 42, 0.07);
        }

        html[data-theme="dark"] .security-hardening-shell {
            --sh-border: #334155;
            --sh-border-strong: #475569;
            --sh-panel-bg: #0f172a;
            --sh-panel-muted: #111c2d;
            --sh-text-muted: #94a3b8;
            --sh-shadow: 0 24px 60px rgba(2, 6, 23, 0.4);
        }

        .security-hardening-shell .sh-panel,
        .security-hardening-shell .sh-card,
        .security-hardening-shell .sh-score-panel {
            border-color: var(--sh-border) !important;
            background: var(--sh-panel-bg) !important;
            box-shadow: var(--sh-shadow);
        }

        .security-hardening-shell .sh-muted {
            border-color: var(--sh-border) !important;
            background: var(--sh-panel-muted) !important;
        }

        .security-hardening-shell .sh-check-item {
            background: transparent !important;
            box-shadow: none !important;
        }

        .security-hardening-shell .sh-check-badge {
            background: transparent !important;
        }

        html[data-theme="dark"] .security-hardening-shell .border-emerald-200 {
            border-color: rgba(52, 211, 153, 0.35) !important;
        }

        html[data-theme="dark"] .security-hardening-shell .border-amber-200,
        html[data-theme="dark"] .security-hardening-shell .border-orange-200 {
            border-color: rgba(251, 191, 36, 0.35) !important;
        }

        html[data-theme="dark"] .security-hardening-shell .border-rose-200 {
            border-color: rgba(251, 113, 133, 0.35) !important;
        }

        html[data-theme="dark"] .security-hardening-shell .border-sky-200,
        html[data-theme="dark"] .security-hardening-shell .border-slate-200,
        html[data-theme="dark"] .security-hardening-shell .border-slate-300 {
            border-color: var(--sh-border) !important;
        }

        html[data-theme="dark"] .security-hardening-shell .bg-emerald-50,
        html[data-theme="dark"] .security-hardening-shell .bg-emerald-50\/40,
        html[data-theme="dark"] .security-hardening-shell .bg-emerald-50\/30 {
            background-color: rgba(6, 95, 70, 0.18) !important;
        }

        html[data-theme="dark"] .security-hardening-shell .bg-amber-50,
        html[data-theme="dark"] .security-hardening-shell .bg-amber-50\/40,
        html[data-theme="dark"] .security-hardening-shell .bg-amber-50\/30,
        html[data-theme="dark"] .security-hardening-shell .bg-orange-50 {
            background-color: rgba(146, 64, 14, 0.18) !important;
        }

        html[data-theme="dark"] .security-hardening-shell .bg-rose-50 {
            background-color: rgba(127, 29, 29, 0.18) !important;
        }

        html[data-theme="dark"] .security-hardening-shell .bg-sky-50,
        html[data-theme="dark"] .security-hardening-shell .bg-slate-50,
        html[data-theme="dark"] .security-hardening-shell .bg-slate-50\/50 {
            background-color: var(--sh-panel-muted) !important;
        }

        .security-hardening-shell .sh-empty,
        .security-hardening-shell .sh-copy-muted {
            color: var(--sh-text-muted) !important;
        }
    </style>
    <div class="security-hardening-shell space-y-4">
    <section class="sh-panel rounded-2xl border border-slate-200 bg-white p-5">
        <div class="grid gap-4 xl:grid-cols-12">
            <div class="xl:col-span-8">
                <p class="text-xs uppercase tracking-[0.24em] text-slate-500">Security Command Center</p>
                <h2 class="mt-1 text-4xl font-semibold tracking-tight text-slate-900">Hardening Radar</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-600">Live risk view generated from runtime controls, environment flags, and current job health.</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="sh-muted rounded-xl border border-emerald-200 bg-emerald-50/40 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Good</p>
                        <p class="mt-1 text-3xl font-semibold leading-none text-emerald-700">{{ $summary['good'] }}</p>
                    </div>
                    <div class="sh-muted rounded-xl border border-amber-200 bg-amber-50/40 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">Needs Action</p>
                        <p class="mt-1 text-3xl font-semibold leading-none text-amber-700">{{ $summary['warning'] }}</p>
                    </div>
                    <div class="sh-muted rounded-xl border border-sky-200 bg-sky-50/40 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700">Info</p>
                        <p class="mt-1 text-3xl font-semibold leading-none text-sky-700">{{ $summary['info'] }}</p>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-4">
                <div class="sh-score-panel sh-muted rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Security Score</p>
                    <div class="mt-3 flex items-center gap-4">
                        <div class="relative h-24 w-24 rounded-full" style="background: conic-gradient(var(--brand-primary) 0deg {{ $scoreDeg }}deg, #e2e8f0 {{ $scoreDeg }}deg 360deg);">
                            <div class="absolute inset-[7px] rounded-full bg-white"></div>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="text-3xl font-semibold text-slate-900">{{ $scoreSafe }}%</span>
                            </div>
                        </div>
                        <div class="text-sm text-slate-600">
                            <p>Checked: {{ $summary['checked_at'] }}</p>
                            <p class="mt-1">Warning pressure: {{ $summary['warning_pressure'] }}%</p>
                            <p class="mt-1">Target: keep warnings under 10%</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.settings') }}" class="mt-4 inline-flex rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Open Security Settings</a>
                </div>
            </div>
        </div>
    </section>

    <section class="mt-4 grid gap-3 md:grid-cols-3">
        <article class="sh-card rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="text-xs uppercase tracking-[0.16em] text-slate-500">Command Integrity</h3>
            <p class="mt-2 text-sm text-slate-700">Signed envelopes, nonce replay checks, and strict hash policy on remote scripts.</p>
        </article>
        <article class="sh-card rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="text-xs uppercase tracking-[0.16em] text-slate-500">Operational Containment</h3>
            <p class="mt-2 text-sm text-slate-700">Kill switch, retry windows, and controlled rollout settings reduce blast radius.</p>
        </article>
        <article class="sh-card rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="text-xs uppercase tracking-[0.16em] text-slate-500">Environment Posture</h3>
            <p class="mt-2 text-sm text-slate-700">Production-safe <code>.env</code> controls for HTTPS, debug, session cookie, and environment profile.</p>
        </article>
    </section>

    <section class="sh-panel mt-4 rounded-2xl border border-slate-200 bg-white p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-2xl font-semibold tracking-tight">Checklist</h3>
            <div class="flex items-center gap-2">
                <span class="sh-check-badge rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs text-rose-700">Critical Open: {{ $criticalWarnings }}</span>
                <span class="sh-check-badge rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-xs text-orange-700">High Open: {{ $highWarnings }}</span>
                <span class="sh-check-badge rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-600">{{ count($controls) }} controls</span>
            </div>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            @foreach($controls as $control)
                @php
                    $statusKey = strtolower((string) ($control['status'] ?? 'info'));
                    $priorityKey = strtolower((string) ($control['priority'] ?? 'medium'));
                    $tone = $toneMap[$statusKey] ?? $toneMap['info'];
                    $priorityTone = $priorityMap[$priorityKey] ?? $priorityMap['medium'];
                @endphp
                <article class="sh-check-item rounded-xl border p-4 {{ $tone['panel'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h4 class="text-lg font-semibold text-slate-900">{{ $control['title'] }}</h4>
                            <p class="mt-2 text-sm text-slate-700">{{ $control['description'] }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $tone['chip'] }}">{{ $tone['label'] }}</span>
                            <span class="rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $priorityTone['chip'] }}">{{ $priorityTone['label'] }}</span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="{{ $control['action_route'] }}" class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">{{ $control['action_label'] }}</a>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
    </div>
</x-admin-layout>
