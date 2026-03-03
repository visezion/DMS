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
    <title>{{ $brandName }} MFA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: {{ $brandPrimary }};
        }
        body { font-family: 'Space Grotesk', sans-serif; background: {{ $brandBackground }}; }
    </style>
</head>
<body class="min-h-screen text-slate-900 flex items-center justify-center p-6">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm">
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
                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Multi-Factor Authentication</p>
            </div>
        </div>
        <p class="text-slate-600 text-sm mt-3">{{ $brandTagline }}</p>
        <p class="text-slate-700 text-sm mt-2">Enter the 6-digit code from your authenticator app for <span class="font-semibold">{{ $email }}</span>.</p>

        @if($errors->any())
            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
        @endif

        <form id="mfa-form" class="mt-6 space-y-4" method="POST" action="{{ route('admin.login.mfa.verify') }}" autocomplete="off">
            @csrf
            <div>
                <label class="text-xs uppercase tracking-wide text-slate-500">Authentication Code</label>
                <input id="mfa-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 tracking-[0.35em] text-center text-2xl font-semibold focus:border-slate-400 focus:outline-none" />
            </div>
            <button id="mfa-submit" type="submit" class="w-full rounded-lg text-white font-semibold py-2.5 hover:opacity-95" style="background: var(--brand-primary);">Verify</button>
        </form>

        <form class="mt-3" method="POST" action="{{ route('admin.login.mfa.cancel') }}">
            @csrf
            <button type="submit" class="w-full rounded-lg border border-slate-300 bg-white py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</button>
        </form>
    </div>

    <script>
        (function () {
            const codeInput = document.getElementById('mfa-code');
            const form = document.getElementById('mfa-form');
            const submit = document.getElementById('mfa-submit');
            if (codeInput) {
                codeInput.addEventListener('input', function () {
                    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8);
                });
                setTimeout(() => codeInput.focus(), 120);
            }
            if (form && submit) {
                form.addEventListener('submit', function () {
                    submit.disabled = true;
                    submit.classList.add('opacity-70', 'cursor-not-allowed');
                    submit.textContent = 'Verifying...';
                });
            }
        })();
    </script>
</body>
</html>
