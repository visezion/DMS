<x-admin-layout title="Access Control" heading="Access Control">
    @include('admin.access.partials.subnav', [
        'active' => 'overview',
        'title' => 'Simple Access Management',
        'description' => 'Use Users to create accounts and assign roles. Use Roles to control what each role can do.',
    ])

    @php
        $inactiveUsers = collect($users ?? [])->where('is_active', false)->count();
        $superAdminUsers = collect($users ?? [])->filter(
            fn ($user) => $user->roles->contains(fn ($role) => (string) $role->slug === 'super-admin')
        )->count();
        $customRoles = collect($roles ?? [])->reject(fn ($role) => (string) $role->slug === 'super-admin')->count();
    @endphp

    <section class="grid gap-4 xl:grid-cols-[1.25fr,0.75fr]">
        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Start Here</p>
            <h3 class="mt-2 text-xl font-semibold text-slate-900">Two pages are enough for daily admin work</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                Go to <strong>Users</strong> to create staff accounts and assign roles.
                Go to <strong>Roles</strong> to decide what each role can access.
            </p>
            <div class="mt-5 grid gap-3 md:grid-cols-2">
                <a href="{{ route('admin.access.users') }}" class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 transition hover:border-skyline/40 hover:bg-skyline/5">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Users</p>
                    <p class="mt-2 text-lg font-semibold text-slate-900">Create users and assign roles</p>
                    <p class="mt-2 text-sm text-slate-600">Add staff, review account status, and update each user’s role set.</p>
                </a>
                <a href="{{ route('admin.access.roles') }}" class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 transition hover:border-skyline/40 hover:bg-skyline/5">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Roles</p>
                    <p class="mt-2 text-lg font-semibold text-slate-900">Manage role permissions</p>
                    <p class="mt-2 text-sm text-slate-600">Create roles, keep them clean, and control what each role can do.</p>
                </a>
            </div>
        </article>

        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Quick Status</p>
            <div class="mt-4 space-y-3">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Super admins</p>
                        <span class="text-2xl font-semibold text-slate-900">{{ $superAdminUsers }}</span>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Inactive accounts</p>
                        <span class="text-2xl font-semibold text-slate-900">{{ $inactiveUsers }}</span>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Custom roles</p>
                        <span class="text-2xl font-semibold text-slate-900">{{ $customRoles }}</span>
                    </div>
                </div>
            </div>
        </article>
    </section>
</x-admin-layout>
