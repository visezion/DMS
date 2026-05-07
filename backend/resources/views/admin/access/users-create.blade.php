<x-admin-layout title="Create Staff Account" heading="Access Control">
    @include('admin.access.partials.subnav', [
        'active' => 'create-user',
        'title' => 'Create Staff Account',
        'description' => 'Provision a new admin account in a dedicated workflow with scoped role assignment and clear onboarding guardrails.',
    ])

    @php
        $selectedRoleIds = collect(old('role_ids', []))->map(fn ($id) => (string) $id)->all();
    @endphp

    <section class="grid gap-4 xl:grid-cols-[1.1fr,0.9fr]">
        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Provisioning</p>
                    <h3 class="mt-2 text-xl font-semibold text-slate-900">Create a new operator profile</h3>
                    <p class="mt-2 text-sm text-slate-600">New accounts inherit the active tenant scope automatically. Only matching roles from that scope can be assigned here.</p>
                </div>
                <a href="{{ route('admin.access.users') }}" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">Back To Users</a>
            </div>

            <form method="POST" action="{{ route('admin.access.users.create') }}" class="mt-5 space-y-5">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Full Name</label>
                        <input name="name" value="{{ old('name') }}" placeholder="Full name" class="mt-1.5 w-full rounded-2xl border border-slate-300 px-4 py-3" required />
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Email</label>
                        <input name="email" type="email" value="{{ old('email') }}" placeholder="staff@company.local" class="mt-1.5 w-full rounded-2xl border border-slate-300 px-4 py-3" required />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Password</label>
                        <input name="password" type="password" placeholder="Minimum 8 characters" class="mt-1.5 w-full rounded-2xl border border-slate-300 px-4 py-3" required />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Confirm Password</label>
                        <input name="password_confirmation" type="password" placeholder="Repeat password" class="mt-1.5 w-full rounded-2xl border border-slate-300 px-4 py-3" required />
                    </div>
                </div>

                <label class="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', '1')) class="rounded border-slate-300">
                    Activate this account immediately
                </label>

                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Initial Roles</p>
                            <p class="mt-1 text-sm text-slate-600">Assign one or more roles that match the active scope.</p>
                        </div>
                        <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600">{{ count($selectedRoleIds) }} selected</span>
                    </div>
                    <div class="mt-4 grid gap-3">
                        @forelse($staffAssignableRoles as $role)
                            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm">
                                <input type="checkbox" name="role_ids[]" value="{{ $role->id }}" @checked(in_array((string) $role->id, $selectedRoleIds, true)) class="mt-0.5 rounded border-slate-300">
                                <span class="min-w-0">
                                    <span class="block font-medium text-slate-900">{{ $role->name }}</span>
                                    <span class="block font-mono text-[11px] text-slate-500">{{ $role->slug }}</span>
                                    @if(!empty($role->description))
                                        <span class="mt-1 block text-xs text-slate-500">{{ $role->description }}</span>
                                    @endif
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">No roles are available in the current scope.</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">The account will be created in the current access scope and role validation is enforced server-side.</p>
                    <button class="inline-flex items-center rounded-xl bg-ink px-5 py-2.5 text-sm font-medium text-white shadow-sm">Create Staff Account</button>
                </div>
            </form>
        </article>

        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Provisioning Guide</p>
            <div class="mt-4 space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <h3 class="text-base font-semibold text-slate-900">Recommended sequence</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Create the account, assign the narrowest role bundle that matches the operator’s job, then review the resulting effective permissions from the Users page.</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <h3 class="text-base font-semibold text-slate-900">Guardrails already enforced</h3>
                    <ul class="mt-2 space-y-2 text-sm leading-6 text-slate-600">
                        <li>Only roles from the current tenant or platform scope are accepted.</li>
                        <li>Email uniqueness is enforced at validation time.</li>
                        <li>Inactive users cannot pass permission checks until reactivated.</li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <h3 class="text-base font-semibold text-slate-900">Next step after creation</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Return to the user directory to confirm the new account, inspect role assignment, and review the exact effective permission set.</p>
                    <a href="{{ route('admin.access.users') }}" class="mt-4 inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">Open User Directory</a>
                </div>
            </div>
        </article>
    </section>
</x-admin-layout>
