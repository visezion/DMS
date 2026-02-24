<!DOCTYPE html>
<html lang="en">
@php
    $brandingSetting = \App\Models\ControlPlaneSetting::query()->find('ui.branding');
    $branding = is_array($brandingSetting?->value ?? null) ? (($brandingSetting->value['value'] ?? []) ?: []) : [];
    if (!is_array($branding)) {
        $branding = [];
    }
    $brandName = trim((string) ($branding['project_name'] ?? 'DMS Admin')) ?: 'DMS Admin';
    $brandTagline = trim((string) ($branding['project_tagline'] ?? 'Centralized control for Windows fleet operations')) ?: 'Centralized control for Windows fleet operations';
    $brandPrimary = strtoupper((string) ($branding['primary_color'] ?? '#0EA5E9'));
    $brandAccent = strtoupper((string) ($branding['accent_color'] ?? '#F97316'));
    $brandBackground = strtoupper((string) ($branding['background_color'] ?? '#F1F5F9'));
    $brandSidebarTint = strtoupper((string) ($branding['sidebar_tint'] ?? '#FFFFFF'));
    $brandRadiusPx = max(0, min(32, (int) ($branding['border_radius_px'] ?? 12)));
    $brandLogo = is_string($branding['logo_url'] ?? null) ? trim((string) $branding['logo_url']) : '';
    $brandFavicon = is_string($branding['favicon_url'] ?? null) ? trim((string) $branding['favicon_url']) : '';
    $topbarUser = auth()->user();
    $topbarUserName = trim((string) ($topbarUser?->name ?? 'User')) ?: 'User';
    $topbarInitial = strtoupper(substr($topbarUserName, 0, 1));
    $topbarUserAvatar = null;
    if ($topbarUser) {
        $profileSetting = \App\Models\ControlPlaneSetting::query()->find('users.profile.'.$topbarUser->id);
        $profileSettingValue = is_array($profileSetting?->value ?? null) ? ($profileSetting->value['value'] ?? []) : [];
        if (is_array($profileSettingValue) && is_string($profileSettingValue['avatar_url'] ?? null)) {
            $topbarUserAvatar = trim((string) $profileSettingValue['avatar_url']) ?: null;
        }
    }
@endphp
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $title ?? $brandName }}</title>
    @if($brandFavicon !== '')
        <link rel="icon" type="image/png" href="{{ $brandFavicon }}">
    @endif
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Space Grotesk', 'ui-sans-serif', 'system-ui'],
                        mono: ['IBM Plex Mono', 'ui-monospace', 'SFMono-Regular']
                    },
                    colors: {
                        ink: '#0f172a',
                        skyline: @json($brandPrimary),
                        ember: @json($brandAccent),
                        leaf: '#16a34a',
                        mist: '#e2e8f0'
                    },
                    boxShadow: {
                        glow: '0 20px 60px rgba(14,165,233,.25)'
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --brand-primary: {{ $brandPrimary }};
            --brand-primary-soft: {{ $brandPrimary }}1A;
            --brand-primary-soft-2: {{ $brandPrimary }}26;
            --brand-primary-border: {{ $brandPrimary }}66;
            --brand-radius-base: {{ $brandRadiusPx }}px;
            --brand-radius-sm: max(2px, calc(var(--brand-radius-base) - 4px));
            --brand-radius-md: max(4px, calc(var(--brand-radius-base) - 2px));
            --brand-radius-lg: var(--brand-radius-base);
            --brand-radius-xl: calc(var(--brand-radius-base) + 2px);
            --brand-radius-2xl: calc(var(--brand-radius-base) + 4px);
            --brand-radius-3xl: calc(var(--brand-radius-base) + 8px);
        }
        body {
            background: {{ $brandBackground }};
        }
        .glass { background: rgba(255,255,255,.78); backdrop-filter: blur(10px); }
        .nav-link { transition: all .2s ease; }
        .nav-link:hover { transform: translateX(4px); }
        /* Sidebar active item style: light panel + left accent (not solid blue pill) */
        aside nav .bg-skyline {
            background: var(--brand-primary-soft) !important;
            color: #000000 !important;
            border-left: 3px solid var(--brand-primary);
            border-radius: 0.5rem;
            padding: 0.875rem 0.75rem 0.875rem 0.75rem !important;
        }
        aside nav .bg-skyline * {
            color: inherit !important;
        }
        aside nav .border-skyline {
            border-color: var(--brand-primary-border) !important;
        }

        /* Make common Tailwind sky/blue tokens follow Branding primary color */
        .text-sky-500, .text-sky-600, .text-sky-700,
        .text-blue-500, .text-blue-600, .text-blue-700 {
            color: var(--brand-primary) !important;
        }
        .border-sky-300, .border-sky-400, .border-sky-500,
        .border-blue-300, .border-blue-400, .border-blue-500 {
            border-color: var(--brand-primary-border) !important;
        }
        .bg-sky-50, .bg-sky-100, .bg-blue-50, .bg-blue-100 {
            background-color: var(--brand-primary-soft) !important;
        }
        .bg-skyline\/10 {
            background-color: var(--brand-primary-soft) !important;
        }
        .border-skyline\/30 {
            border-color: var(--brand-primary-border) !important;
        }
        .hover\:text-skyline:hover {
            color: var(--brand-primary) !important;
        }
        .hover\:border-sky-300:hover {
            border-color: var(--brand-primary-border) !important;
        }

        /* Force menu text/icons to black */
        aside nav a,
        aside nav summary,
        aside nav a svg,
        aside nav summary svg,
        .lg\:hidden nav a,
        .lg\:hidden nav summary,
        .lg\:hidden nav a svg,
        .lg\:hidden nav summary svg,
        header nav[aria-label="Top shortcuts"] a,
        header nav[aria-label="Top shortcuts"] a svg {
            color: #000 !important;
        }

        /* Global corner radius from Branding */
        .rounded:not(.rounded-full) { border-radius: var(--brand-radius-sm) !important; }
        .rounded-sm:not(.rounded-full) { border-radius: var(--brand-radius-sm) !important; }
        .rounded-md:not(.rounded-full) { border-radius: var(--brand-radius-md) !important; }
        .rounded-lg:not(.rounded-full) { border-radius: var(--brand-radius-lg) !important; }
        .rounded-xl:not(.rounded-full) { border-radius: var(--brand-radius-xl) !important; }
        .rounded-2xl:not(.rounded-full) { border-radius: var(--brand-radius-2xl) !important; }
        .rounded-3xl:not(.rounded-full) { border-radius: var(--brand-radius-3xl) !important; }

        .expand-indicator::before { content: '+'; }
        details[open] > summary .expand-indicator::before { content: '-'; }
    </style>
</head>
<body class="min-h-screen text-ink">
<div class="flex min-h-screen">
    <aside class="w-72 hidden lg:flex lg:flex-col border-r border-slate-200/60 glass" style="background: {{ $brandSidebarTint }}CC;">
        <div class="px-6 py-4 border-b border-slate-200/60">
            <div class="flex items-center gap-3">
                @if($brandLogo !== '')
                    <img src="{{ $brandLogo }}" alt="Brand Logo" class="h-10 w-10 rounded object-contain border border-slate-200 bg-white p-1">
                @endif
                @if($brandName !== '')
                    <h1 class="text-xl font-bold leading-tight">{{ $brandName }}</h1>
                @endif
            </div>
        </div>
        <nav class="px-4 py-3 space-y-3.5 text-sm font-medium">
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.enroll-devices*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.enroll-devices') }}">Enroll Devices</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.dashboard') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.dashboard') }}">Overview</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.devices') || request()->routeIs('admin.devices.show') || request()->routeIs('admin.devices.live') || request()->routeIs('admin.devices.update') || request()->routeIs('admin.devices.delete') || request()->routeIs('admin.devices.reenroll') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.devices') }}">Devices</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.groups*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.groups') }}">Groups</a>
            <details class="pt-2 group" {{ request()->routeIs('admin.packages*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Application Management</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-2 pl-2 space-y-1">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.packages') }}">Software Packages</a>
                </div>
            </details>
            <details class="pt-2 group" {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Policy Center</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-2 pl-2 space-y-1">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.policies*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.policies') }}">Policies</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.catalog*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.catalog') }}">Policy Catalog</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.policy-categories') }}">Policy Categories</a>
                </div>
            </details>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.jobs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.jobs') }}">Jobs</a>
            <details class="pt-2 group" {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Deployment Center</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-2 pl-2 space-y-1">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.agent') }}">Agent Delivery</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.ip-deploy') }}">IP Deployment</a>
                </div>
            </details>
            <details class="pt-2 group" {{ request()->routeIs('admin.settings*') ? 'open' : '' }}>
                <summary class="list-none cursor-pointer rounded-lg px-3 py-1.5 flex items-center justify-between {{ request()->routeIs('admin.settings*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}">
                    <span>Settings</span>
                    <span class="expand-indicator text-xs"></span>
                </summary>
                <div class="mt-2 pl-2 space-y-1">
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.settings') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.settings') }}">General</a>
                    <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.settings.branding*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.settings.branding') }}">Branding</a>
                </div>
            </details>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.access*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.access') }}">Access Control</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.docs*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.docs') }}">Docs</a>
            <a class="nav-link block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.audit*') ? 'bg-skyline text-white' : 'text-slate-700 hover:bg-white' }}" href="{{ route('admin.audit') }}">Audit Logs</a>
        </nav>
    </aside>

    <main class="flex-1">
        <header class="px-5 lg:px-8 py-2 border-b border-slate-200 bg-white/95 backdrop-blur flex items-center justify-end sticky top-0 z-20 shadow-[0_1px_0_rgba(15,23,42,.06)]">
            <div class="flex items-center gap-2">
                <nav class="hidden md:flex items-center gap-1.5 px-0 py-0" aria-label="Top shortcuts">
                    <a href="{{ route('admin.enroll-devices') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.enroll-devices*') ? 'text-skyline' : '' }}" title="Enroll Devices" aria-label="Enroll Devices">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="m9 11 2 2 4-4"/></svg>
                    </a>
                    <a href="{{ route('admin.devices') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.devices*') ? 'text-skyline' : '' }}" title="Devices" aria-label="Devices">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="4" y="3" width="16" height="12" rx="2"/><path d="M8 21h8M12 15v6"/></svg>
                    </a>
                    <a href="{{ route('admin.policies') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'text-skyline' : '' }}" title="Policies" aria-label="Policies">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3v18"/><path d="M6 7h12"/><path d="M6 17h12"/><path d="M8.5 7a3.5 3.5 0 0 1 0 7"/><path d="M15.5 17a3.5 3.5 0 0 0 0-7"/></svg>
                    </a>
                    <a href="{{ route('admin.packages') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.packages*') ? 'text-skyline' : '' }}" title="Software Packages" aria-label="Software Packages">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>
                    </a>
                    <a href="{{ route('admin.jobs') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.jobs*') ? 'text-skyline' : '' }}" title="Jobs" aria-label="Jobs">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                    </a>
                    <a href="{{ route('admin.settings') }}" class="h-9 w-9 rounded-full flex items-center justify-center text-slate-600 hover:text-skyline transition {{ request()->routeIs('admin.settings*') ? 'text-skyline' : '' }}" title="Settings" aria-label="Settings">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                </nav>
                <div class="relative" id="topbar-profile-root">
                    <button type="button" id="topbar-profile-btn" class="flex items-center rounded-full bg-white border border-slate-200 p-0.5 hover:bg-slate-50 shadow-sm">
                        @if($topbarUserAvatar)
                            <img src="{{ $topbarUserAvatar }}" alt="Profile" class="h-8 w-8 rounded-full object-cover border border-slate-200">
                        @else
                            <span class="h-8 w-8 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-xs font-semibold">{{ $topbarInitial }}</span>
                        @endif
                    </button>
                    <div id="topbar-profile-menu" class="hidden absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white shadow-xl z-50">
                        <div class="px-3 py-2 border-b border-slate-200">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $topbarUserName }}</p>
                            <p class="text-xs text-slate-500 truncate">{{ $topbarUser?->email }}</p>
                        </div>
                        <div class="p-1">
                            <a href="{{ route('admin.settings') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Settings</a>
                            <a href="{{ route('admin.docs') }}" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Documentation</a>
                            <form method="POST" action="{{ route('admin.logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left rounded-lg px-3 py-2 text-sm text-rose-700 hover:bg-rose-50">Logout</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <nav class="lg:hidden px-5 py-3 border-b border-slate-200/70 glass">
            <div class="grid grid-cols-3 gap-2 text-xs">
                <a class="rounded-lg px-2 py-2 text-center col-span-3 {{ request()->routeIs('admin.enroll-devices*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.enroll-devices') }}">Enroll Devices</a>
                <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.dashboard') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.dashboard') }}">Overview</a>
                <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.devices') || request()->routeIs('admin.devices.show') || request()->routeIs('admin.devices.live') || request()->routeIs('admin.devices.update') || request()->routeIs('admin.devices.delete') || request()->routeIs('admin.devices.reenroll') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.devices') }}">Devices</a>
                <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.groups*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.groups') }}">Groups</a>
                <details class="col-span-3 group" {{ request()->routeIs('admin.packages*') ? 'open' : '' }}>
                    <summary class="list-none cursor-pointer rounded-lg border border-slate-200 px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white border-skyline' : 'bg-slate-50 text-slate-600' }}">
                        Application Management <span class="expand-indicator ml-1 inline-block text-[10px]"></span>
                    </summary>
                    <div class="mt-2 grid grid-cols-1 gap-2">
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.packages*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.packages') }}">Software Packages</a>
                    </div>
                </details>
                <details class="col-span-3 group" {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'open' : '' }}>
                    <summary class="list-none cursor-pointer rounded-lg border border-slate-200 px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide {{ request()->routeIs('admin.policies*') || request()->routeIs('admin.catalog*') || request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white border-skyline' : 'bg-slate-50 text-slate-600' }}">
                        Policy Center <span class="expand-indicator ml-1 inline-block text-[10px]"></span>
                    </summary>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.policies*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.policies') }}">Policies</a>
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.catalog*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.catalog') }}">Policy Catalog</a>
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.policy-categories*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.policy-categories') }}">Categories</a>
                    </div>
                </details>
                <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.jobs*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.jobs') }}">Jobs</a>
                <details class="col-span-3 group" {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'open' : '' }}>
                    <summary class="list-none cursor-pointer rounded-lg border border-slate-200 px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide {{ request()->routeIs('admin.agent*') || request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white border-skyline' : 'bg-slate-50 text-slate-600' }}">
                        Deployment Center <span class="expand-indicator ml-1 inline-block text-[10px]"></span>
                    </summary>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.agent*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.agent') }}">Agent Delivery</a>
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.ip-deploy*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.ip-deploy') }}">IP Deployment</a>
                    </div>
                </details>
                <details class="col-span-3 group" {{ request()->routeIs('admin.settings*') ? 'open' : '' }}>
                    <summary class="list-none cursor-pointer rounded-lg border border-slate-200 px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide {{ request()->routeIs('admin.settings*') ? 'bg-skyline text-white border-skyline' : 'bg-slate-50 text-slate-600' }}">
                        Settings <span class="expand-indicator ml-1 inline-block text-[10px]"></span>
                    </summary>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.settings') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.settings') }}">General</a>
                        <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.settings.branding*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.settings.branding') }}">Branding</a>
                    </div>
                </details>
                <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.access*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.access') }}">Access</a>
                <a class="rounded-lg px-2 py-2 text-center {{ request()->routeIs('admin.docs*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.docs') }}">Docs</a>
                <a class="rounded-lg px-2 py-2 text-center col-span-3 {{ request()->routeIs('admin.audit*') ? 'bg-skyline text-white' : 'bg-white text-slate-700' }}" href="{{ route('admin.audit') }}">Audit Logs</a>
            </div>
        </nav>

        <section class="p-5 lg:p-8 space-y-4">
            @if(session('status'))
                <div class="rounded-xl border border-leaf/25 bg-leaf/10 px-4 py-3 text-sm text-green-900">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-xl border border-ember/30 bg-ember/10 px-4 py-3 text-sm text-amber-900">
                    {{ $errors->first() }}
                </div>
            @endif

            {{ $slot }}
        </section>
    </main>
</div>
<div id="confirm-modal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/45 p-4">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div class="border-b border-slate-200 px-5 py-4">
            <p class="text-sm uppercase tracking-wide text-slate-500">Please Confirm</p>
            <h3 class="text-lg font-semibold text-ink">Action Confirmation</h3>
        </div>
        <div class="px-5 py-4">
            <p id="confirm-modal-message" class="text-sm text-slate-700"></p>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
            <button id="confirm-modal-cancel" type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700">Cancel</button>
            <button id="confirm-modal-ok" type="button" class="rounded-lg bg-rose-600 px-4 py-2 text-sm text-white">Confirm</button>
        </div>
    </div>
</div>
<script>
    (function () {
        const root = document.getElementById('topbar-profile-root');
        const btn = document.getElementById('topbar-profile-btn');
        const menu = document.getElementById('topbar-profile-menu');
        if (!root || !btn || !menu) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.toggle('hidden');
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                menu.classList.add('hidden');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                menu.classList.add('hidden');
            }
        });
    })();
</script>
<script>
    (function () {
        const modal = document.getElementById('confirm-modal');
        const msg = document.getElementById('confirm-modal-message');
        const okBtn = document.getElementById('confirm-modal-ok');
        const cancelBtn = document.getElementById('confirm-modal-cancel');
        if (!modal || !msg || !okBtn || !cancelBtn) return;

        let pendingForm = null;

        function extractConfirmMessage(form) {
            if (form.dataset.confirmMessage && form.dataset.confirmMessage.trim() !== '') {
                return form.dataset.confirmMessage;
            }
            const inline = form.getAttribute('onsubmit') || '';
            const match = inline.match(/confirm\((['"])([\s\S]*?)\1\)/);
            if (!match || !match[2]) {
                return '';
            }
            const text = match[2];
            form.dataset.confirmMessage = text;
            form.removeAttribute('onsubmit');
            return text;
        }

        function closeModal() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            pendingForm = null;
        }

        function openModal(message, form) {
            msg.textContent = message || 'Are you sure?';
            pendingForm = form;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            okBtn.focus();
        }

        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
        okBtn.addEventListener('click', function () {
            if (!pendingForm) return;
            pendingForm.dataset.confirmBypass = '1';
            const form = pendingForm;
            closeModal();
            form.submit();
        });

        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (form.dataset.confirmBypass === '1') {
                form.dataset.confirmBypass = '0';
                return;
            }

            const message = extractConfirmMessage(form);
            if (!message) {
                return;
            }

            e.preventDefault();
            openModal(message, form);
        }, true);
    })();
</script>
<script>
    (function () {
        const iconMap = {
            'Overview': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>',
            'Devices': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="3" width="16" height="12" rx="2"/><path d="M8 21h8M12 15v6"/></svg>',
            'Groups': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M8 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M2.5 20a5.5 5.5 0 0 1 11 0"/><path d="M13 20a5 5 0 0 1 8.5-3.5"/></svg>',
            'Software Packages': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>',
            'Application Management': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/></svg>',
            'Policies': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3v18"/><path d="M6 7h12"/><path d="M6 17h12"/><path d="M8.5 7a3.5 3.5 0 0 1 0 7"/><path d="M15.5 17a3.5 3.5 0 0 0 0-7"/></svg>',
            'Policy Catalog': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 0-3 3V4Z"/><path d="M8 8h7M8 12h7M8 16h5"/></svg>',
            'Categories': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 8h18"/><path d="M3 12h18"/><path d="M3 16h18"/></svg>',
            'Policy Categories': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M3 8h18"/><path d="M3 12h18"/><path d="M3 16h18"/></svg>',
            'Jobs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
            'Agent Delivery': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 2 3 7l9 5 9-5-9-5Z"/><path d="M3 17l9 5 9-5"/><path d="M3 12l9 5 9-5"/></svg>',
            'IP Deployment': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>',
            'Settings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'General': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M10.3 3h3.4l.6 2.2a7.8 7.8 0 0 1 1.8.8l2-1.1 2.4 2.4-1.1 2a7.8 7.8 0 0 1 .8 1.8l2.2.6v3.4l-2.2.6a7.8 7.8 0 0 1-.8 1.8l1.1 2-2.4 2.4-2-1.1a7.8 7.8 0 0 1-1.8.8l-.6 2.2h-3.4l-.6-2.2a7.8 7.8 0 0 1-1.8-.8l-2 1.1-2.4-2.4 1.1-2a7.8 7.8 0 0 1-.8-1.8L3 13.7v-3.4l2.2-.6a7.8 7.8 0 0 1 .8-1.8l-1.1-2 2.4-2.4 2 1.1a7.8 7.8 0 0 1 1.8-.8l.6-2.2Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'Branding': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3a9 9 0 0 0-9 9c0 4 3 7 7 7h1v2h2v-2h1a7 7 0 0 0 0-14h-2z"/><circle cx="8" cy="10" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="15" cy="11" r="1"/></svg>',
            'Enroll Devices': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="m9 11 2 2 4-4"/></svg>',
            'Access': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
            'Access Control': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
            'Docs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5"/></svg>',
            'Audit Logs': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M11 8v3l2 2"/></svg>',
            'Policy Center': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 3 4 7v6c0 5 3.5 7.8 8 9 4.5-1.2 8-4 8-9V7l-8-4Z"/></svg>',
            'Deployment Center': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M12 2v20"/><path d="M5 7h14"/><path d="M7 12h10"/><path d="M9 17h6"/></svg>'
        };

        function cleanText(el) {
            return (el.textContent || '').replace(/\s+/g, ' ').trim();
        }

        function addIcon(el, iconHtml) {
            if (!el || !iconHtml || el.dataset.iconized === '1') return;
            const text = cleanText(el);
            el.textContent = '';
            const iconSpan = document.createElement('span');
            iconSpan.setAttribute('aria-hidden', 'true');
            iconSpan.className = 'text-current';
            iconSpan.innerHTML = iconHtml;
            const textSpan = document.createElement('span');
            textSpan.textContent = text;
            if (el.classList.contains('text-center')) {
                el.classList.add('inline-flex', 'items-center', 'justify-center', 'gap-1.5');
            } else {
                el.classList.add('flex', 'items-center', 'gap-2');
            }
            el.appendChild(iconSpan);
            el.appendChild(textSpan);
            el.dataset.iconized = '1';
        }

        document.querySelectorAll('aside nav a, .lg\\:hidden nav a').forEach(function (a) {
            const txt = cleanText(a);
            if (iconMap[txt]) addIcon(a, iconMap[txt]);
        });

        document.querySelectorAll('aside nav summary, .lg\\:hidden nav summary').forEach(function (s) {
            const raw = cleanText(s).replace(/[v+-]$/, '').trim();
            for (const [label, iconHtml] of Object.entries(iconMap)) {
                if (raw.startsWith(label)) {
                    if (s.dataset.iconized === '1') break;
                    const arrow = document.createElement('span');
                    arrow.className = 'expand-indicator text-xs';
                    const left = document.createElement('span');
                    left.className = 'inline-flex items-center gap-2';
                    const iconSpan = document.createElement('span');
                    iconSpan.setAttribute('aria-hidden', 'true');
                    iconSpan.className = 'text-current';
                    iconSpan.innerHTML = iconHtml;
                    const textSpan = document.createElement('span');
                    textSpan.textContent = label;
                    left.appendChild(iconSpan);
                    left.appendChild(textSpan);
                    s.textContent = '';
                    if (!s.classList.contains('flex')) {
                        s.classList.add('flex', 'items-center', 'justify-between');
                    }
                    s.appendChild(left);
                    s.appendChild(arrow);
                    s.dataset.iconized = '1';
                    break;
                }
            }
        });
    })();
</script>
</body>
</html>
