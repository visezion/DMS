<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
@php
    $brandingSetting = \App\Models\ControlPlaneSetting::query()->find('ui.branding');
    $branding = is_array($brandingSetting?->value ?? null) ? (($brandingSetting->value['value'] ?? []) ?: []) : [];
    if (!is_array($branding)) {
        $branding = [];
    }

    $brandName = trim((string) ($branding['project_name'] ?? config('app.name', 'DMS'))) ?: config('app.name', 'DMS');
    $brandTagline = trim((string) ($branding['project_tagline'] ?? 'Centralized control for Windows fleet operations')) ?: 'Centralized control for Windows fleet operations';
    $brandPrimary = strtoupper((string) ($branding['primary_color'] ?? '#0EA5E9'));
    $brandAccent = strtoupper((string) ($branding['accent_color'] ?? '#F97316'));
    $brandBackground = strtoupper((string) ($branding['background_color'] ?? '#F1F5F9'));
    $brandSidebarTint = strtoupper((string) ($branding['sidebar_tint'] ?? '#FFFFFF'));
    $brandLogo = is_string($branding['logo_url'] ?? null) ? trim((string) $branding['logo_url']) : '';
    $brandFavicon = is_string($branding['favicon_url'] ?? null) ? trim((string) $branding['favicon_url']) : '';

    $heroTitle = $brandName.' unifies endpoint control with precision';
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brandName }} | Device Security Platform</title>
    @if($brandFavicon !== '')
        <link rel="icon" type="image/png" href="{{ $brandFavicon }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: {{ $brandPrimary }};
            --brand-accent: {{ $brandAccent }};
            --brand-bg: {{ $brandBackground }};
            --brand-surface: {{ $brandSidebarTint }};
            --ink: #0f172a;
            --muted: #475569;
            --line: color-mix(in srgb, var(--brand-primary) 18%, #cbd5e1);
            --soft-primary: color-mix(in srgb, var(--brand-primary) 14%, white);
            --soft-accent: color-mix(in srgb, var(--brand-accent) 12%, white);
        }

        body {
            font-family: 'Space Grotesk', ui-sans-serif, system-ui;
            color: var(--ink);
            background: var(--brand-bg);
        }

        .mono {
            font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, monospace;
        }

        .panel {
            border: 0px solid var(--line);
            background: color-mix(in srgb, var(--brand-surface) 92%, white);
            box-shadow: 0 14px 38px color-mix(in srgb, var(--brand-primary) 12%, transparent);
        }

        .btn-primary {
            background: var(--brand-primary);
            color: #fff;
        }

        .btn-primary:hover {
            filter: brightness(.95);
        }

        .btn-secondary {
            border: 1px solid color-mix(in srgb, var(--brand-primary) 55%, #cbd5e1);
            background: #fff;
            color: color-mix(in srgb, var(--brand-primary) 70%, #0f172a);
        }

        
        
 .hero-wrap {
             background: color-mix(in srgb, var(--brand-primary) 10%, white);
        }

        .orbit {
            position: relative;
            width: min(520px, 100%);
            aspect-ratio: 1 / 1;
            margin-inline: auto;
        }

        .orbit::before,
        .orbit::after {
            content: "";
            position: absolute;
            border-radius: 9999px;
            border: 1px solid color-mix(in srgb, var(--brand-primary) 30%, #cbd5e1);
        }

        .orbit::before { inset: 10%; }
        .orbit::after { inset: 23%; }

        .core {
            position: absolute;
            inset: 34%;
            border-radius: 9999px;
            border: 1px solid color-mix(in srgb, var(--brand-primary) 35%, #cbd5e1);
            display: grid;
            place-items: center;
            text-align: center;
            background: color-mix(in srgb, var(--brand-surface) 88%, white);
            padding: 1rem;
        }

        .node {
            position: absolute;
            width: 112px;
            height: 112px;
            border-radius: 9999px;
            display: grid;
            place-items: center;
            text-align: center;
            font-weight: 600;
            font-size: .86rem;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: 0 8px 22px rgba(15,23,42,.08);
        }

        .node.connect { top: 17%; left: 7%; color: var(--brand-primary); }
        .node.detect { top: 17%; right: 7%; color: color-mix(in srgb, var(--brand-accent) 55%, #b91c1c); }
        .node.protect { bottom: 12%; left: 13%; color: #15803d; }
        .node.respond { bottom: 12%; right: 9%; color: #0f766e; }

        .odd-grid {
            background: color-mix(in srgb, var(--brand-surface) 80%, white);
            border-radius: 1.25rem;
        }

        @media (max-width: 1024px) {
            .orbit { max-width: 460px; }
            .node { width: 96px; height: 96px; font-size: .76rem; }
        }
    </style>
</head>
<body>
    <header class="border-b border-slate-200/70 bg-white/80 backdrop-blur">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-5 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                @if($brandLogo !== '')
                    <img src="{{ $brandLogo }}" alt="Brand logo" class="h-10 w-auto max-w-[11rem] object-contain">
                @else
                    <div class="h-10 w-10 rounded-full text-white grid place-items-center font-bold" style="background: var(--brand-primary);">
                        {{ strtoupper(substr($brandName, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <p class="text-lg font-bold leading-none">{{ $brandName }}</p>
                    <p class="mono text-[11px] uppercase tracking-[0.14em] text-slate-500">Fleet Security and Operations</p>
                </div>
            </div>

            <nav class="hidden items-center gap-7 text-sm font-medium text-slate-600 md:flex">
                <a href="#platform" class="hover:text-slate-900">Platform</a>
                <a href="#modules" class="hover:text-slate-900">Modules</a>
                <a href="#resources" class="hover:text-slate-900">Resources</a>
            </nav>

            <div class="flex items-center gap-2">
                @auth
                    <a href="{{ route('admin.dashboard') }}" class="rounded-full px-4 py-2 text-sm font-semibold btn-primary">Dashboard</a>
                @else
                    <a href="{{ route('admin.login') }}" class="rounded-full px-4 py-2 text-sm font-semibold btn-secondary">Log in</a>
                    <a href="{{ route('admin.login') }}" class="rounded-full px-4 py-2 text-sm font-semibold btn-primary">Request a demo</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl px-4 pb-14 pt-10 sm:px-6 lg:px-8">
        <section id="platform" class="panel hero-wrap overflow-hidden p-5 sm:p-8 lg:p-10">
            <div class="grid items-center gap-10 lg:grid-cols-2">
                <div>
                    <p class="mono inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[11px] uppercase tracking-[0.12em]" style="border-color: var(--line); background: var(--soft-primary); color: color-mix(in srgb, var(--brand-primary) 70%, #0f172a);">
                        Weirdly precise endpoint security
                    </p>
                    <h1 class="mt-4 text-4xl font-bold leading-tight text-slate-900 sm:text-5xl">
                        {{ $heroTitle }}
                    </h1>
                    <p class="mt-4 max-w-xl text-base text-slate-600">
                        {{ $brandTagline }}
                    </p>
                    <ul class="mt-7 space-y-3 text-base text-slate-700">
                        <li class="flex items-start gap-3">
                            <span class="mt-2 inline-block h-3 w-3 rounded-full" style="background: var(--brand-primary);"></span>
                            Device enrollment, policy orchestration, package rollout, and audit visibility in one system.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-2 inline-block h-3 w-3 rounded-full" style="background: var(--brand-accent);"></span>
                            Built for real operations: speed, traceability, and controlled execution.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-2 inline-block h-3 w-3 rounded-full" style="background: color-mix(in srgb, var(--brand-primary) 60%, var(--brand-accent));"></span>
                            Unusual visual language, familiar workflows, professional control.
                        </li>
                    </ul>
                    <div class="mt-8 flex flex-wrap gap-3">
                        @auth
                            <a href="{{ route('admin.dashboard') }}" class="rounded-full px-6 py-2.5 text-sm font-semibold btn-primary">Open Control Plane</a>
                        @else
                            <a href="{{ route('admin.login') }}" class="rounded-full px-6 py-2.5 text-sm font-semibold btn-primary">Start now</a>
                            <a href="{{ route('admin.docs') }}" class="rounded-full px-6 py-2.5 text-sm font-semibold btn-secondary">Read docs</a>
                        @endauth
                    </div>
                </div>

                <div class="odd-grid p-4 sm:p-5">
                    <div class="orbit">
                        <div class="core">
                            <div>
                                @if($brandLogo !== '')
                                    <img src="{{ $brandLogo }}" alt="Brand logo" class="mx-auto mb-2 h-10 w-auto max-w-[8rem] object-contain">
                                @else
                                    <div class="mx-auto mb-3 h-12 w-12 rounded-full text-white grid place-items-center font-bold" style="background: var(--brand-primary);">
                                        {{ strtoupper(substr($brandName, 0, 1)) }}
                                    </div>
                                @endif
                            
                            </div>
                        </div>
                        <div class="node connect">Connect</div>
                        <div class="node detect">Detect</div>
                        <div class="node protect">Protect</div>
                        <div class="node respond">Respond</div>
                    </div>
                </div>
            </div>
        </section>

    </main>
</body>
</html>
