<x-admin-layout title="Settings" heading="Configuration">
    @php
        $signatureBypassEnabled = (bool) ($signatureBypassEnabled ?? false);
        $currentEnv = strtolower((string) old('app_env', (string) ($environmentPolicy['app_env'] ?? 'local')));
        $debugDisabled = old('disable_debug_mode', !((bool) ($environmentPolicy['app_debug'] ?? false)));
        $secureCookies = old('secure_session_cookies', (bool) ($environmentPolicy['session_secure_cookie'] ?? false));
        $httpsEnabled = str_starts_with(strtolower((string) ($httpsPolicy['app_url'] ?? '')), 'https://');
    @endphp

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Admin Settings</h2>
                <p class="mt-1 text-sm text-slate-600">Structured controls for security, environment posture, enrollment, and runtime operations.</p>
            </div>
            <a href="{{ route('admin.settings.branding') }}" class="rounded-lg bg-skyline px-4 py-2 text-sm text-white">Open Branding</a>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border {{ $signatureBypassEnabled ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }} p-3">
                <p class="text-xs uppercase tracking-wide {{ $signatureBypassEnabled ? 'text-amber-700' : 'text-emerald-700' }}">Signature Bypass</p>
                <p class="mt-1 text-sm font-semibold {{ $signatureBypassEnabled ? 'text-amber-700' : 'text-emerald-700' }}">{{ $signatureBypassEnabled ? 'Enabled' : 'Disabled' }}</p>
            </div>
            <div class="rounded-xl border {{ $httpsEnabled ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }} p-3">
                <p class="text-xs uppercase tracking-wide {{ $httpsEnabled ? 'text-emerald-700' : 'text-amber-700' }}">APP URL TLS</p>
                <p class="mt-1 text-sm font-semibold {{ $httpsEnabled ? 'text-emerald-700' : 'text-amber-700' }}">{{ $httpsEnabled ? 'HTTPS' : 'Needs HTTPS' }}</p>
            </div>
            <div class="rounded-xl border {{ $debugDisabled ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }} p-3">
                <p class="text-xs uppercase tracking-wide {{ $debugDisabled ? 'text-emerald-700' : 'text-amber-700' }}">Debug Mode</p>
                <p class="mt-1 text-sm font-semibold {{ $debugDisabled ? 'text-emerald-700' : 'text-amber-700' }}">{{ $debugDisabled ? 'Disabled' : 'Enabled' }}</p>
            </div>
            <div class="rounded-xl border {{ $secureCookies ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }} p-3">
                <p class="text-xs uppercase tracking-wide {{ $secureCookies ? 'text-emerald-700' : 'text-amber-700' }}">Session Cookie</p>
                <p class="mt-1 text-sm font-semibold {{ $secureCookies ? 'text-emerald-700' : 'text-amber-700' }}">{{ $secureCookies ? 'Secure' : 'Insecure' }}</p>
            </div>
        </div>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">Security Controls</h3>
            <p class="mt-1 text-xs text-slate-500">Restrict dangerous behaviors and keep production-safe defaults.</p>

            <div class="mt-4 space-y-4">
                <form method="POST" action="{{ route('admin.settings.signature-bypass') }}" onsubmit="return confirm('Change signature bypass mode? Use enabled only for development/testing.');" class="rounded-xl border border-slate-200 bg-slate-50/40 p-3">
                    @csrf
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-900">Signature Bypass (Dev Only)</p>
                            <p class="mt-1 text-xs text-slate-600">Toggles <span class="font-mono">DMS_SIGNATURE_BYPASS</span> in environment and control plane setting.</p>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="hidden" name="signature_bypass_enabled" value="0">
                            <input type="checkbox" name="signature_bypass_enabled" value="1" {{ $signatureBypassEnabled ? 'checked' : '' }} class="rounded border-slate-300">
                            Enable
                        </label>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button class="rounded bg-ink px-3 py-2 text-xs text-white">Save</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.settings.auth-policy') }}" class="rounded-xl border border-slate-200 bg-slate-50/40 p-3">
                    @csrf
                    <p class="text-sm font-medium text-slate-900">Harden Login Lockout Policy</p>
                    <p class="mt-1 text-xs text-slate-600">Limits repeated failures and forces lockout windows per email + IP.</p>
                    <label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="require_mfa" value="0">
                        <input type="checkbox" name="require_mfa" value="1" @checked((bool) ($authPolicy['require_mfa'] ?? false)) class="rounded border-slate-300">
                        Enforce admin MFA (require MFA for all admin logins)
                    </label>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-xs uppercase text-slate-500">Max Login Attempts</label>
                            <input name="max_login_attempts" type="number" min="1" max="20" value="{{ (int) ($authPolicy['max_login_attempts'] ?? 5) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                        </div>
                        <div>
                            <label class="text-xs uppercase text-slate-500">Lockout Minutes</label>
                            <input name="lockout_minutes" type="number" min="1" max="1440" value="{{ (int) ($authPolicy['lockout_minutes'] ?? 15) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button class="rounded bg-ink px-3 py-2 text-xs text-white">Save Lockout Policy</button>
                    </div>
                </form>
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">Environment & Transport</h3>
            <p class="mt-1 text-xs text-slate-500">Control TLS URL policy, deployment mode, debug posture, and secure cookie behavior.</p>

            <div class="mt-4 space-y-4">
                <form method="POST" action="{{ route('admin.settings.https-app-url') }}" class="rounded-xl border border-slate-200 bg-slate-50/40 p-3">
                    @csrf
                    <p class="text-sm font-medium text-slate-900">Enforce HTTPS App URL</p>
                    <p class="mt-1 text-xs text-slate-600">Set <span class="font-mono">APP_URL</span> to HTTPS and optionally enforce secure cookie mode.</p>
                    <div class="mt-3">
                        <label class="text-xs uppercase text-slate-500">APP URL (HTTPS)</label>
                        <input name="app_url" type="url" required value="{{ old('app_url', (string) ($httpsPolicy['app_url'] ?? '')) }}" placeholder="https://your-domain.example" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                    </div>
                    <label class="mt-3 inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="enforce_secure_cookie" value="1" {{ old('enforce_secure_cookie', (bool) ($httpsPolicy['session_secure_cookie'] ?? false)) ? 'checked' : '' }} class="rounded border-slate-300">
                        Also set SESSION_SECURE_COOKIE=true
                    </label>
                    @error('https_app_url')
                        <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                    <div class="mt-3 flex justify-end">
                        <button class="rounded bg-ink px-3 py-2 text-xs text-white">Save HTTPS Policy</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.settings.environment-posture') }}" class="rounded-xl border border-slate-200 bg-slate-50/40 p-3">
                    @csrf
                    <p class="text-sm font-medium text-slate-900">Environment Posture</p>
                    <p class="mt-1 text-xs text-slate-600">Keep production-safe runtime profile with secure diagnostics and session controls.</p>
                    <div class="mt-3">
                        <label class="text-xs uppercase text-slate-500">Environment</label>
                        <select name="app_env" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="production" @selected($currentEnv === 'production')>production</option>
                            <option value="staging" @selected($currentEnv === 'staging')>staging</option>
                            <option value="local" @selected($currentEnv === 'local')>local</option>
                            <option value="testing" @selected($currentEnv === 'testing')>testing</option>
                        </select>
                    </div>
                    <div class="mt-3 space-y-2">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="hidden" name="disable_debug_mode" value="0">
                            <input type="checkbox" name="disable_debug_mode" value="1" {{ $debugDisabled ? 'checked' : '' }} class="rounded border-slate-300">
                            Disable debug mode
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="hidden" name="secure_session_cookies" value="0">
                            <input type="checkbox" name="secure_session_cookies" value="1" {{ $secureCookies ? 'checked' : '' }} class="rounded border-slate-300">
                            Use secure session cookies
                        </label>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button class="rounded bg-ink px-3 py-2 text-xs text-white">Save Environment Posture</button>
                    </div>
                </form>
            </div>
        </article>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-3">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 xl:col-span-2">
            <h3 class="text-base font-semibold text-slate-900">Operations Controls</h3>
            <p class="mt-1 text-xs text-slate-500">Tune dispatch safety, retries, script allowlist, and package URL mode.</p>

            <form method="POST" action="{{ route('admin.ops.update') }}" class="mt-4 grid gap-3 md:grid-cols-2">
                @csrf
                <label class="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                    <input type="checkbox" name="kill_switch" value="1" @checked(($ops['kill_switch'] ?? false))>
                    Pause all command dispatch (Kill Switch)
                </label>
                <div>
                    <label class="text-xs uppercase text-slate-500">Max Retries</label>
                    <input name="max_retries" type="number" min="0" max="10" value="{{ $ops['max_retries'] ?? 3 }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-2" />
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Base Backoff (Seconds)</label>
                    <input name="base_backoff_seconds" type="number" min="5" max="1800" value="{{ $ops['base_backoff_seconds'] ?? 30 }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-2" />
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase text-slate-500">Allowed Script SHA256 (One Per Line)</label>
                    <textarea name="allowed_script_hashes" class="mt-1 min-h-28 w-full rounded border border-slate-300 px-2 py-2 font-mono text-xs">{{ implode("\n", $ops['allowed_script_hashes'] ?? []) }}</textarea>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                    <input type="checkbox" name="auto_allow_run_command_hashes" value="1" @checked(($ops['auto_allow_run_command_hashes'] ?? false))>
                    Auto-allow new run_command script hashes (generated from payload.script)
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                    <input type="checkbox" name="delete_cleanup_before_uninstall" value="1" @checked(($ops['delete_cleanup_before_uninstall'] ?? false))>
                    On device delete: remove assigned policies and uninstall packages before uninstalling agent
                </label>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase text-slate-500">Package Download URL Mode</label>
                    <select name="package_download_url_mode" class="mt-1 w-full rounded border border-slate-300 px-2 py-2 text-sm">
                        <option value="public" @selected(($ops['package_download_url_mode'] ?? 'public') === 'public')>Public (stable URL for uploaded files)</option>
                        <option value="signed" @selected(($ops['package_download_url_mode'] ?? 'public') === 'signed')>Signed (expires by deploy window)</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">External source URLs always use their original URL.</p>
                </div>
                <div class="md:col-span-2 flex justify-end">
                    <button class="rounded bg-skyline px-4 py-2 text-sm text-white">Save Ops Settings</button>
                </div>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">Enrollment</h3>
            <p class="mt-1 text-xs text-slate-500">Generate enrollment tokens for onboarding devices.</p>
            <form method="POST" action="{{ route('admin.devices.enrollment-token') }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="text-xs uppercase text-slate-500">Expires (hours)</label>
                    <input name="expires_hours" type="number" min="1" max="720" value="24" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                </div>
                <button class="w-full rounded-lg bg-skyline px-4 py-2 text-sm text-white">Generate Token</button>
            </form>
        </article>
    </section>

    <datalist id="policy-category-options">
        @foreach(($policyCategories ?? []) as $cat)
            <option value="{{ $cat }}"></option>
        @endforeach
    </datalist>
</x-admin-layout>
