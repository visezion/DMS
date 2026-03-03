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
    <title>{{ $brandName }} Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: {{ $brandPrimary }};
        }
        body { font-family: 'Space Grotesk', sans-serif; background: {{ $brandBackground }}; }
        .scene-grid {
            background-image:
                linear-gradient(to right, rgba(15,23,42,.06) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(15,23,42,.06) 1px, transparent 1px);
            background-size: 44px 44px;
        }
        .radar-ring {
            animation: pulse-ring 5s ease-in-out infinite;
        }
        .radar-ring.delay-1 { animation-delay: 1.2s; }
        .radar-ring.delay-2 { animation-delay: 2.4s; }
        @keyframes pulse-ring {
            0% { transform: scale(.72); opacity: .12; }
            45% { transform: scale(1); opacity: .3; }
            100% { transform: scale(1.16); opacity: 0; }
        }
        .node-chip {
            animation: float-chip 4.8s ease-in-out infinite;
        }
        .node-chip.delay-a { animation-delay: .5s; }
        .node-chip.delay-b { animation-delay: 1.1s; }
        .node-chip.delay-c { animation-delay: 1.8s; }
        .node-chip.delay-d { animation-delay: 2.6s; }
        .node-chip.delay-e { animation-delay: 3.2s; }
        @keyframes float-chip {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-7px); }
        }
        .node-card {
            border: 1px solid rgba(148, 163, 184, .45);
            background: rgba(255, 255, 255, .9);
            border-radius: .85rem;
            padding: .5rem .65rem;
            min-width: 9.5rem;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
        }
        .node-icon {
            width: 1.35rem;
            height: 1.35rem;
            border-radius: 9999px;
            border: 1px solid rgba(148, 163, 184, .6);
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
        }
        .node-sub {
            font-size: .64rem;
            line-height: .9rem;
            color: #64748b;
            margin-top: .05rem;
        }
        .flow-line {
            stroke-dasharray: 7 10;
            animation: dash-flow 2.2s linear infinite;
        }
        @keyframes dash-flow {
            to { stroke-dashoffset: -68; }
        }
        .flow-dot {
            animation: blink-dot 1.3s ease-in-out infinite;
        }
        @keyframes blink-dot {
            0%, 100% { opacity: .25; }
            50% { opacity: .95; }
        }
        .auth-card {
            border: 1px solid rgba(148, 163, 184, .35);
            background: linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(248,250,252,.97) 100%);
            box-shadow: 0 24px 60px rgba(15, 23, 42, .12), 0 2px 10px rgba(15, 23, 42, .06);
        }
        .field-wrap {
            position: relative;
        }
        .field-wrap .field-icon {
            position: absolute;
            left: .8rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1rem;
            height: 1rem;
            color: #64748b;
            pointer-events: none;
        }
        .field-wrap input {
            padding-left: 2.35rem;
            padding-right: 2.35rem;
        }
        .toggle-pass {
            position: absolute;
            right: .65rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1.6rem;
            height: 1.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: .45rem;
            color: #475569;
        }
        .toggle-pass:hover { background: #f1f5f9; }
    </style>
</head>
<body class="min-h-screen text-slate-900">
    <div class="min-h-screen grid lg:grid-cols-2">
        <section class="hidden lg:flex relative overflow-hidden border-r border-slate-200">
            <div class="absolute inset-0" style="background: linear-gradient(135deg, {{ $brandPrimary }}24 0%, #ffffff 55%, {{ $brandPrimary }}12 100%);"></div>
            <div class="absolute inset-0 scene-grid"></div>
            <div class="absolute inset-0">
                <div class="absolute left-1/2 top-[48%] h-72 w-72 -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-300/70 radar-ring"></div>
                <div class="absolute left-1/2 top-[48%] h-80 w-80 -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-300/60 radar-ring delay-1"></div>
                <div class="absolute left-1/2 top-[48%] h-[22rem] w-[22rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-300/50 radar-ring delay-2"></div>

                <svg class="absolute inset-0 h-full w-full" viewBox="0 0 1000 1000" preserveAspectRatio="none" fill="none">
                    <path class="flow-line" d="M500 480 L180 190" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M500 480 L300 760" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M500 480 L760 180" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M500 480 L840 360" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M500 480 L820 700" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M500 480 L420 145" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M500 480 L650 815" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M500 480 L120 520" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M760 180 L840 360" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                    <path class="flow-line" d="M840 360 L820 700" stroke="{{ $brandPrimary }}" stroke-width="2.2"/>
                </svg>

                <div class="absolute left-[11%] top-[16%] node-chip delay-a node-card">
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-800">
                        <span class="node-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="w-3.5 h-3.5"><path d="M3 4.5 10.5 3v8.1H3V4.5Zm8.4-1.7L21 1.5v9.6h-9.6V2.8ZM3 12.9h7.5V21L3 19.5v-6.6Zm8.4 0H21v9.6l-9.6-1.3v-8.3Z"/></svg>
                        </span>
                        Windows
                    </div>
                    <p class="node-sub">Device Check-in</p>
                </div>
                <div class="absolute left-[6.5%] top-[49%] node-chip delay-c node-card">
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-800">
                        <span class="node-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="w-3.5 h-3.5"><path d="M12 4v6m0 0 3-3m-3 3L9 7"/><path d="M5 14v4h14v-4"/><path d="M7 20h10"/></svg>
                        </span>
                        Linux
                    </div>
                    <p class="node-sub">Heartbeat + Ack</p>
                </div>
                <div class="absolute left-[20%] top-[74%] node-chip delay-b node-card">
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-800">
                        <span class="node-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="w-3.5 h-3.5"><rect x="7" y="3" width="10" height="18" rx="2"/><circle cx="12" cy="17" r="1"/></svg>
                        </span>
                        Android
                    </div>
                    <p class="node-sub">Managed Endpoint</p>
                </div>
                <div class="absolute left-[69%] top-[15%] node-chip delay-b node-card">
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-800">
                        <span class="node-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="w-3.5 h-3.5"><path d="M12 3v18"/><path d="M6 7h12"/><path d="M6 17h12"/></svg>
                        </span>
                        Policies
                    </div>
                    <p class="node-sub">Rule Selection</p>
                </div>
                <div class="absolute left-[79%] top-[34%] node-chip delay-d node-card">
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-800">
                        <span class="node-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="w-3.5 h-3.5"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>
                        </span>
                        Packages
                    </div>
                    <p class="node-sub">VS Code | M365</p>
                </div>
                <div class="absolute left-[76%] top-[69%] node-chip delay-e node-card">
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-800">
                        <span class="node-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="w-3.5 h-3.5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                        </span>
                        Remote Jobs
                    </div>
                    <p class="node-sub">Run + Result</p>
                </div>
                <div class="absolute left-[40%] top-[13%] node-chip delay-a rounded-full border border-slate-300/90 bg-white/90 px-3 py-1 text-[11px] font-semibold text-slate-700">VS Code</div>
                <div class="absolute left-[62%] top-[82%] node-chip delay-c rounded-full border border-slate-300/90 bg-white/90 px-3 py-1 text-[11px] font-semibold text-slate-700">M365</div>

                <div class="absolute left-[49%] top-[46%] -translate-x-1/2 -translate-y-1/2">
                    <div class="h-40 w-40 rounded-full border border-sky-300/90 bg-white/80 backdrop-blur-sm flex items-center justify-center shadow-lg">
                        <div class="text-center">
                            <div class="text-xs uppercase tracking-[0.18em] text-slate-500">Control Plane</div>
                            <div class="text-3xl font-bold text-center leading-tight px-3" style="color: var(--brand-primary);">{{ $brandName }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="relative z-10 p-12 flex flex-col justify-between w-full">
                <div class="flex items-center gap-3">
                    @if($brandLogo !== '')
                        <img src="{{ $brandLogo }}" alt="Brand logo" class="h-12 w-auto max-w-[12rem] rounded-lg border border-slate-200 bg-white object-contain px-2 py-1.5">
                    @else
                        <div class="h-12 w-12 rounded-full border border-slate-200 bg-white flex items-center justify-center text-slate-700" aria-label="Brand logo">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-7 h-7">
                                <path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4.5 7-9V6l-7-3Z"/>
                                <path d="m9 12 2 2 4-4"/>
                            </svg>
                        </div>
                    @endif
                    <div>
                        <p class="text-sm uppercase tracking-[0.25em] text-slate-500">Admin Access</p>
                    </div>
                </div>
                <div class="max-w-lg">
                    <p class="mt-2 text-sm text-slate-600">{{ $brandTagline }}</p>
                </div>
                
            </div>
        </section>

        <section class="flex items-center justify-center p-6 sm:p-10">
            <div class="auth-card w-full max-w-md rounded-2xl p-7">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Admin Login</p>
                        <h2 class="mt-2 text-3xl font-bold">{{ $brandName }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $brandTagline }}</p>
                    </div>
                    <div class="h-10 w-10 rounded-lg border border-slate-200 bg-white flex items-center justify-center">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5 text-slate-700"><path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4.5 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>
                    </div>
                </div>

                @if($errors->any())
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
                @endif

                <form id="login-form" class="mt-6 space-y-4" method="POST" action="{{ route('admin.login.submit') }}" autocomplete="off">
                    @csrf
                    @php
                        $puzzleRequired = (bool) (session('puzzle_required') ?? ($puzzleRequired ?? false));
                        $puzzleQuestion = (string) (session('puzzle_question') ?? ($puzzleQuestion ?? ''));
                        $captchaImage = (string) (session('captcha_image') ?? ($captchaImage ?? ''));
                    @endphp
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500">Email</label>
                        <div class="field-wrap mt-1">
                            <span class="field-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                            </span>
                            <input name="email" type="email" required value="{{ old('email') }}" autocomplete="off" autocapitalize="off" spellcheck="false" class="w-full rounded-lg border border-slate-300 bg-white py-2 focus:border-slate-400 focus:outline-none" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500">Password</label>
                        <div class="field-wrap mt-1">
                            <span class="field-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                            </span>
                            <input id="login-password" name="password" type="password" required autocomplete="new-password" class="w-full rounded-lg border border-slate-300 bg-white py-2 focus:border-slate-400 focus:outline-none" />
                            <button type="button" id="toggle-password" class="toggle-pass" aria-label="Show password">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    @if($puzzleRequired)
                        <div>
                            <label class="text-xs uppercase tracking-wide text-slate-500">Security Captcha</label>
                            <div class="mt-1 rounded-lg border border-slate-300 bg-slate-50 px-2 py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <img id="captcha-image" src="{{ $captchaImage }}" alt="Captcha" class="h-[74px] w-[260px] max-w-full rounded border border-slate-200 bg-white object-contain" />
                                    <button type="button" id="captcha-refresh" class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 hover:bg-slate-100">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
                                        Refresh
                                    </button>
                                </div>
                            </div>
                            <div class="field-wrap mt-2">
                                <span class="field-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path d="M4 7h16M7 3h10v18H7z"/></svg>
                                </span>
                                <input id="captcha-answer" name="captcha_answer" type="text" required autocomplete="off" class="w-full rounded-lg border border-slate-300 bg-white py-2 focus:border-slate-400 focus:outline-none" placeholder="Enter captcha text" />
                            </div>
                            <input type="text" name="company_website" id="company-website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                            <p class="mt-2 text-[11px] text-slate-500">Captcha appears after repeated failed attempts.</p>
                        </div>
                    @endif
                    <div class="pt-1">
                        <button id="login-submit" type="submit" class="w-full rounded-lg text-white font-semibold py-2.5 hover:opacity-95" style="background: var(--brand-primary);">Sign In</button>
                    </div>
                    <p class="text-[11px] text-slate-500 text-center">Authorized administrators only. All login activity is audited.</p>
                </form>
            </div>
        </section>
    </div>

    <div id="login-progress-modal" class="hidden fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-6">
        <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 h-5 w-5 rounded-full border-2 border-slate-300 animate-spin" style="border-top-color: var(--brand-primary);"></div>
                <div>
                    <p class="text-sm font-semibold text-slate-900">Signing in...</p>
                    <p class="text-xs text-slate-600 mt-1">Please wait while your session is being created.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('login-form');
            const submit = document.getElementById('login-submit');
            const modal = document.getElementById('login-progress-modal');
            const passwordInput = document.getElementById('login-password');
            const togglePassword = document.getElementById('toggle-password');
            const puzzleRequired = @json($puzzleRequired ?? false);
            const captchaRefresh = document.getElementById('captcha-refresh');
            const captchaImage = document.getElementById('captcha-image');
            const captchaAnswer = document.getElementById('captcha-answer');
            if (!form || !submit || !modal) return;

            if (passwordInput && togglePassword) {
                togglePassword.addEventListener('click', function () {
                    const isPassword = passwordInput.type === 'password';
                    passwordInput.type = isPassword ? 'text' : 'password';
                    togglePassword.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                });
            }

            if (puzzleRequired) {
                if (captchaAnswer) {
                    captchaAnswer.addEventListener('input', function () {
                        this.value = (this.value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                    });
                }
                if (captchaRefresh && captchaImage) {
                    captchaRefresh.addEventListener('click', async function () {
                        try {
                            captchaRefresh.disabled = true;
                            const response = await fetch(@json(route('admin.login.captcha.refresh')), {
                                method: 'GET',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin'
                            });
                            const data = await response.json();
                            if (data && data.ok && typeof data.captcha_image === 'string') {
                                captchaImage.src = data.captcha_image;
                                if (captchaAnswer) {
                                    captchaAnswer.value = '';
                                    captchaAnswer.focus();
                                }
                            }
                        } catch (e) {
                            // no-op
                        } finally {
                            captchaRefresh.disabled = false;
                        }
                    });
                }
            }

            form.addEventListener('submit', function () {
                modal.classList.remove('hidden');
                submit.disabled = true;
                submit.classList.add('opacity-70', 'cursor-not-allowed');
                submit.textContent = 'Signing In...';
            });
        })();
    </script>
</body>
</html>
