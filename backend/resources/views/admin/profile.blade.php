<x-admin-layout title="{{ __('ui.profile.title') }}" heading="{{ __('ui.profile.title') }}">
    @php
        $supportedLocales = \App\Support\LocaleManager::supported();
        $profilePref = array_merge([
            'timezone' => config('app.timezone', 'UTC'),
            'locale' => 'en',
            'bio' => '',
            'avatar_url' => null,
        ], is_array($profilePref ?? null) ? $profilePref : []);
        $profilePref['locale'] = \App\Support\LocaleManager::normalize((string) ($profilePref['locale'] ?? 'en'));
        $mfaPolicyRequired = (bool) ($mfaPolicyRequired ?? false);
        $mfaSecretCorrupted = (bool) ($mfaSecretCorrupted ?? false);
        $mfaEnabled = (bool) ($user->mfa_enabled ?? false);
        $mfaHasSecret = !empty($mfaSecretPlain);
        $mfaCanRotate = !($mfaPolicyRequired && $mfaEnabled);
        $profileAvatarUrl = is_string($profilePref['avatar_url'] ?? null) ? trim((string) $profilePref['avatar_url']) : '';
        if ($profileAvatarUrl !== '' && preg_match('/^https?:\/\//i', $profileAvatarUrl) === 1) {
            $path = parse_url($profileAvatarUrl, PHP_URL_PATH);
            $profileAvatarUrl = is_string($path) ? $path : '';
        }
        $uploadsPos = strpos($profileAvatarUrl, '/uploads/avatars/');
        if ($uploadsPos !== false) {
            $profileAvatarUrl = substr($profileAvatarUrl, $uploadsPos);
        }
        $profileAvatarUrl = $profileAvatarUrl === '' ? '' : '/'.ltrim($profileAvatarUrl, '/');
        if ($profileAvatarUrl !== '' && !str_starts_with($profileAvatarUrl, '/uploads/avatars/')) {
            $profileAvatarUrl = '';
        }
    @endphp

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl bg-white border border-slate-200 p-4">
            <h3 class="font-semibold">{{ __('ui.profile.account') }}</h3>
            <p class="text-xs text-slate-500 mt-1">{{ __('ui.profile.account_help') }}</p>
            <div class="mt-4 flex items-center gap-3">
                @if($profileAvatarUrl !== '')
                    <img src="{{ asset(ltrim($profileAvatarUrl, '/')) }}" alt="Avatar" class="h-14 w-14 rounded-full object-cover border border-slate-200">
                @else
                    <span class="h-14 w-14 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-lg font-semibold">
                        {{ strtoupper(substr((string) ($user->name ?? 'U'), 0, 1)) }}
                    </span>
                @endif
                <div>
                    <p class="font-medium">{{ $user->name }}</p>
                    <p class="text-xs text-slate-500">{{ $user->email }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 lg:col-span-2">
            <h3 class="font-semibold">{{ __('ui.profile.preferences') }}</h3>
            <form method="POST" action="{{ route('admin.profile.update') }}" enctype="multipart/form-data" class="grid gap-3 md:grid-cols-2 mt-4">
                @csrf
                <div>
                    <label class="text-xs uppercase text-slate-500">{{ __('ui.profile.full_name') }}</label>
                    <input name="name" value="{{ old('name', $user->name) }}" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">{{ __('ui.profile.email') }}</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">{{ __('ui.profile.time_zone') }}</label>
                    <input name="timezone" value="{{ old('timezone', $profilePref['timezone']) }}" placeholder="UTC" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">{{ __('ui.profile.language') }}</label>
                    <select name="locale" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                        @foreach($supportedLocales as $localeCode => $localeLabel)
                            <option value="{{ $localeCode }}" @selected(old('locale', $profilePref['locale']) === $localeCode)>{{ $localeLabel }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('ui.locale.help') }}</p>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase text-slate-500">{{ __('ui.profile.bio') }}</label>
                    <textarea name="bio" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 min-h-24">{{ old('bio', $profilePref['bio']) }}</textarea>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">{{ __('ui.profile.avatar') }}</label>
                    <input type="file" name="avatar" accept="image/*" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-xs">
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="remove_avatar" value="1" class="rounded border-slate-300">
                        {{ __('ui.profile.remove_avatar') }}
                    </label>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">{{ __('ui.profile.new_password') }}</label>
                    <input type="password" name="password" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <label class="text-xs uppercase text-slate-500 mt-2 block">{{ __('ui.profile.confirm_new_password') }}</label>
                    <input type="password" name="password_confirmation" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div class="md:col-span-2">
                    <button class="rounded bg-skyline text-white px-4 py-2 text-sm">{{ __('ui.profile.save') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="mt-4 rounded-2xl bg-white border border-slate-200 p-4">
        <div class="flex items-center justify-between gap-2">
            <div>
                <h3 class="font-semibold">Multi-Factor Authentication (TOTP)</h3>
                <p class="text-xs text-slate-500 mt-1">Protect your admin account with an authenticator app.</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $mfaEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                {{ $mfaEnabled ? 'Enabled' : 'Disabled' }}
            </span>
        </div>

        <div class="mt-3 rounded-lg border px-3 py-2 text-xs {{ $mfaPolicyRequired ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-200 bg-slate-50 text-slate-700' }}">
            Policy:
            @if($mfaPolicyRequired)
                MFA is required by admin auth policy. Disable and rotate actions are restricted to prevent lockout.
            @else
                MFA is optional for this account.
            @endif
        </div>

        @if($mfaSecretCorrupted)
            <div class="mt-3 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                Stored MFA secret could not be decrypted. Generate a new setup secret and re-enable MFA.
            </div>
        @endif

        @error('profile_mfa')
            <div class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
        @enderror
        @error('code')
            <div class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
        @enderror
        @error('password')
            <div class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
        @enderror

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs font-medium text-slate-700">1. Setup Secret</p>
                <form method="POST" action="{{ route('admin.profile.mfa.setup') }}" class="mt-2">
                    @csrf
                    <button class="rounded bg-skyline text-white px-3 py-2 text-xs disabled:cursor-not-allowed disabled:opacity-60" @disabled(! $mfaCanRotate)>Generate / Rotate Secret</button>
                </form>
                @if(! $mfaCanRotate)
                    <p class="mt-2 text-[11px] text-amber-700">Disable policy requirement first before rotating a currently enabled MFA secret.</p>
                @endif
                @if(!empty($mfaSecretPlain))
                    <div class="mt-3 space-y-2">
                        <p class="text-[11px] text-slate-600">Setup Secret</p>
                        <code class="block rounded border border-slate-200 bg-white px-2 py-2 text-xs break-all">{{ $mfaSecretPlain }}</code>
                        @if(!empty($mfaProvisioningUri))
                            <button
                                type="button"
                                id="mfa-qr-open-btn"
                                class="rounded bg-ink text-white px-3 py-2 text-xs"
                            >
                                Show QR Code
                            </button>
                            <p class="text-[11px] text-slate-600">otpauth URI</p>
                            <code class="block rounded border border-slate-200 bg-white px-2 py-2 text-[11px] break-all">{{ $mfaProvisioningUri }}</code>
                        @endif
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs font-medium text-slate-700">2. Enable / Disable</p>
                <form method="POST" action="{{ route('admin.profile.mfa.enable') }}" class="mt-2 space-y-2">
                    @csrf
                    <input name="code" value="{{ old('code') }}" placeholder="Enter 6-digit code" inputmode="numeric" pattern="\d{6}" maxlength="6" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" required>
                    <button class="rounded bg-emerald-600 text-white px-3 py-2 text-xs disabled:cursor-not-allowed disabled:opacity-60" @disabled(! $mfaHasSecret)>Enable MFA</button>
                </form>
                @if(! $mfaHasSecret)
                    <p class="mt-2 text-[11px] text-slate-600">Generate setup secret first, then enter a 6-digit authenticator code.</p>
                @endif

                <form method="POST" action="{{ route('admin.profile.mfa.disable') }}" class="mt-3 space-y-2" onsubmit="return confirm('Disable MFA for your account?');">
                    @csrf
                    <input type="password" name="password" placeholder="Current password" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" @disabled($mfaPolicyRequired) required>
                    <button class="rounded bg-rose-600 text-white px-3 py-2 text-xs disabled:cursor-not-allowed disabled:opacity-60" @disabled($mfaPolicyRequired)>Disable MFA</button>
                </form>
                @if($mfaPolicyRequired)
                    <p class="mt-2 text-[11px] text-amber-700">Disable is blocked while global "Require MFA" policy is enabled.</p>
                @endif
            </div>
        </div>
    </div>

    @if(!empty($mfaProvisioningUri))
        @php
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='.rawurlencode((string) $mfaProvisioningUri);
        @endphp
        <div id="mfa-qr-modal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-slate-900/60 px-4">
            <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-slate-900">Scan MFA QR Code</h4>
                    <button type="button" id="mfa-qr-close-btn" class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800">X</button>
                </div>
                <p class="mt-1 text-xs text-slate-500">Scan with Microsoft Authenticator, Google Authenticator, or similar app.</p>
                <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 flex items-center justify-center">
                    <img src="{{ $qrUrl }}" alt="MFA QR code" class="h-64 w-64 rounded bg-white border border-slate-200 p-1" />
                </div>
                <div class="mt-3 flex justify-end">
                    <button type="button" id="mfa-qr-done-btn" class="rounded bg-skyline text-white px-3 py-2 text-xs">Done</button>
                </div>
            </div>
        </div>

        <script>
            (() => {
                const openBtn = document.getElementById('mfa-qr-open-btn');
                const closeBtn = document.getElementById('mfa-qr-close-btn');
                const doneBtn = document.getElementById('mfa-qr-done-btn');
                const modal = document.getElementById('mfa-qr-modal');
                if (!openBtn || !closeBtn || !doneBtn || !modal) return;

                const openModal = () => {
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                };
                const closeModal = () => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                };

                openBtn.addEventListener('click', openModal);
                closeBtn.addEventListener('click', closeModal);
                doneBtn.addEventListener('click', closeModal);
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) closeModal();
                });
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') closeModal();
                });
            })();
        </script>
    @endif
</x-admin-layout>
