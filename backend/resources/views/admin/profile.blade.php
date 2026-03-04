<x-admin-layout title="My Profile" heading="My Profile">
    @php
        $profilePref = array_merge([
            'timezone' => config('app.timezone', 'UTC'),
            'locale' => 'en_US',
            'bio' => '',
            'avatar_url' => null,
        ], is_array($profilePref ?? null) ? $profilePref : []);
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
            <h3 class="font-semibold">Account</h3>
            <p class="text-xs text-slate-500 mt-1">Your login identity and avatar.</p>
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
            <h3 class="font-semibold">Profile & Preferences</h3>
            <form method="POST" action="{{ route('admin.profile.update') }}" enctype="multipart/form-data" class="grid gap-3 md:grid-cols-2 mt-4">
                @csrf
                <div>
                    <label class="text-xs uppercase text-slate-500">Full Name</label>
                    <input name="name" value="{{ old('name', $user->name) }}" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Email</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Time Zone</label>
                    <input name="timezone" value="{{ old('timezone', $profilePref['timezone']) }}" placeholder="UTC" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Locale</label>
                    <input name="locale" value="{{ old('locale', $profilePref['locale']) }}" placeholder="en_US" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase text-slate-500">Bio</label>
                    <textarea name="bio" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 min-h-24">{{ old('bio', $profilePref['bio']) }}</textarea>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Avatar</label>
                    <input type="file" name="avatar" accept="image/*" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-xs">
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="remove_avatar" value="1" class="rounded border-slate-300">
                        Remove current avatar
                    </label>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">New Password</label>
                    <input type="password" name="password" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <label class="text-xs uppercase text-slate-500 mt-2 block">Confirm New Password</label>
                    <input type="password" name="password_confirmation" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div class="md:col-span-2">
                    <button class="rounded bg-skyline text-white px-4 py-2 text-sm">Save Profile</button>
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
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ ($user->mfa_enabled ?? false) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                {{ ($user->mfa_enabled ?? false) ? 'Enabled' : 'Disabled' }}
            </span>
        </div>

        @error('profile_mfa')
            <div class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
        @enderror

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs font-medium text-slate-700">1. Setup Secret</p>
                <form method="POST" action="{{ route('admin.profile.mfa.setup') }}" class="mt-2">
                    @csrf
                    <button class="rounded bg-skyline text-white px-3 py-2 text-xs">Generate / Rotate Secret</button>
                </form>
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
                    <input name="code" placeholder="Enter 6-digit code" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" required>
                    <button class="rounded bg-emerald-600 text-white px-3 py-2 text-xs">Enable MFA</button>
                </form>

                <form method="POST" action="{{ route('admin.profile.mfa.disable') }}" class="mt-3 space-y-2" onsubmit="return confirm('Disable MFA for your account?');">
                    @csrf
                    <input type="password" name="password" placeholder="Current password" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" required>
                    <button class="rounded bg-rose-600 text-white px-3 py-2 text-xs">Disable MFA</button>
                </form>
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
