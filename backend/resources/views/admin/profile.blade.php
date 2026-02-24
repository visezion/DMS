<x-admin-layout title="My Profile" heading="My Profile">
    @php
        $profilePref = array_merge([
            'timezone' => config('app.timezone', 'UTC'),
            'locale' => 'en_US',
            'bio' => '',
            'avatar_url' => null,
        ], is_array($profilePref ?? null) ? $profilePref : []);
    @endphp

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl bg-white border border-slate-200 p-4">
            <h3 class="font-semibold">Account</h3>
            <p class="text-xs text-slate-500 mt-1">Your login identity and avatar.</p>
            <div class="mt-4 flex items-center gap-3">
                @if(!empty($profilePref['avatar_url']))
                    <img src="{{ $profilePref['avatar_url'] }}" alt="Avatar" class="h-14 w-14 rounded-full object-cover border border-slate-200">
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
</x-admin-layout>
