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
    $brandBackground = strtoupper((string) ($branding['background_color'] ?? '#F1F5F9'));
    $brandLogo = is_string($branding['logo_url'] ?? null) ? trim((string) $branding['logo_url']) : '';
@endphp
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $brandName }} Sign Up</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body
    class="min-h-screen text-slate-900 flex items-center justify-center p-6 admin-auth-register"
    style="--brand-primary: {{ $brandPrimary }}; --brand-background: {{ $brandBackground }};"
>
    <div class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-7 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                @if($brandLogo !== '')
                    <img src="{{ $brandLogo }}" alt="Brand logo" class="h-10 w-auto max-w-[10rem] rounded-lg border border-slate-200 bg-white object-contain px-2 py-1">
                @else
                    <div class="h-10 w-10 rounded-full border border-slate-200 bg-white flex items-center justify-center text-slate-700" aria-label="Brand logo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-6 h-6">
                            <path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4.5 7-9V6l-7-3Z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </div>
                @endif
                <div>
                    <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Create Organization</p>
                    <h1 class="text-xl font-bold">{{ $brandName }}</h1>
                    <p class="text-xs text-slate-500">{{ $brandTagline }}</p>
                </div>
            </div>
            <a href="{{ route('admin.login') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                Back To Login
            </a>
        </div>

        @if($errors->any())
            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
        @endif

        <form class="mt-6 space-y-5" method="POST" action="{{ route('admin.signup.submit') }}" autocomplete="off">
            @csrf
            <section class="rounded-xl border border-slate-200 p-4">
                <h2 class="text-sm font-semibold text-slate-900">Organization</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs uppercase tracking-wide text-slate-500">Organization Name</label>
                        <input name="organization_name" value="{{ old('organization_name') }}" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none" placeholder="Acme Corporation">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs uppercase tracking-wide text-slate-500">Organization Slug (optional)</label>
                        <input name="organization_slug" value="{{ old('organization_slug') }}" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-mono focus:border-slate-400 focus:outline-none" placeholder="acme-corp">
                        <p class="mt-1 text-[11px] text-slate-500">Used for tenant identity. If empty, it will be generated from organization name.</p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 p-4">
                <h2 class="text-sm font-semibold text-slate-900">Administrator Account</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-wide text-slate-500">Full Name</label>
                        <input name="name" value="{{ old('name') }}" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none" placeholder="Jane Doe">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-wide text-slate-500">Email</label>
                        <input name="email" type="email" value="{{ old('email') }}" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none" placeholder="admin@acme.com">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-wide text-slate-500">Password</label>
                        <input name="password" type="password" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none" placeholder="At least 8 characters">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-wide text-slate-500">Confirm Password</label>
                        <input name="password_confirmation" type="password" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none" placeholder="Repeat password">
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-slate-500">You will be created as tenant super-admin and signed in immediately.</p>
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-white hover:opacity-95 auth-brand-btn">
                    Create Organization
                </button>
            </div>
        </form>
    </div>
</body>
</html>
