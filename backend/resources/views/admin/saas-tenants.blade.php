<x-admin-layout title="SaaS Tenants" heading="SaaS Tenant Administration">
    @php
        $tenantMap = collect($tenants ?? [])->keyBy('id');
        $activeTenant = $activeTenantId ? $tenantMap->get($activeTenantId) : null;
    @endphp

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-4 xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">Platform Context</h3>
                <a href="{{ route('admin.saas.dashboard') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                    Back To SaaS Dashboard
                </a>
            </div>
            <p class="mt-1 text-xs text-slate-500">
                Choose which tenant scope the platform super-admin operates under in daily admin pages.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if($activeTenant)
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                        Active Tenant: {{ $activeTenant->name }} ({{ $activeTenant->slug }})
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                        Active Scope: Platform (no tenant filter)
                    </span>
                @endif

                <form method="POST" action="{{ route('admin.saas.tenants.switch.platform') }}">
                    @csrf
                    <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Switch To Platform Scope
                    </button>
                </form>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-900">Create Tenant</h3>
            <form method="POST" action="{{ route('admin.saas.tenants.create') }}" class="mt-3 space-y-3">
                @csrf
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Tenant Name</label>
                    <input name="name" value="{{ old('name') }}" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Acme Corporation">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Slug</label>
                    <input name="slug" value="{{ old('slug') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="acme-corp">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Status</label>
                    <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="active" @selected(old('status', 'active') === 'active')>active</option>
                        <option value="inactive" @selected(old('status') === 'inactive')>inactive</option>
                    </select>
                </div>
                <button class="w-full rounded-lg bg-ink px-3 py-2 text-sm font-medium text-white">Create Tenant</button>
            </form>
        </section>
    </div>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
        <h3 class="text-sm font-semibold text-slate-900">Tenants</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Tenant</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Usage</th>
                        <th class="px-3 py-2 text-left">Context</th>
                        <th class="px-3 py-2 text-left">Manage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse(($tenants ?? []) as $tenant)
                        <tr id="tenant-{{ $tenant->id }}">
                            <td class="px-3 py-3 align-top">
                                <p class="font-medium text-slate-900">{{ $tenant->name }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $tenant->slug }}</p>
                                <p class="text-[11px] text-slate-400">ID: {{ $tenant->id }}</p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $tenant->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $tenant->status }}
                                </span>
                            </td>
                            <td class="px-3 py-3 align-top text-xs text-slate-600">
                                <p>Users: <span class="font-semibold text-slate-900">{{ (int) $tenant->users_count }}</span></p>
                                <p>Devices: <span class="font-semibold text-slate-900">{{ (int) $tenant->devices_count }}</span></p>
                                <p>Policies: <span class="font-semibold text-slate-900">{{ (int) $tenant->policies_count }}</span></p>
                                <p>Jobs: <span class="font-semibold text-slate-900">{{ (int) $tenant->jobs_count }}</span></p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                @if($activeTenantId === $tenant->id)
                                    <span class="inline-flex rounded-full bg-sky-100 px-2 py-1 text-xs font-semibold text-sky-700">active context</span>
                                @elseif($tenant->status !== 'active')
                                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">inactive tenant</span>
                                @else
                                    <form method="POST" action="{{ route('admin.saas.tenants.switch', $tenant->id) }}">
                                        @csrf
                                        <button class="rounded-lg border border-sky-300 px-2.5 py-1 text-xs font-medium text-sky-700 hover:bg-sky-50">
                                            Switch Context
                                        </button>
                                    </form>
                                @endif
                            </td>
                            <td class="px-3 py-3 align-top">
                                <form method="POST" action="{{ route('admin.saas.tenants.update', $tenant->id) }}" class="space-y-2">
                                    @csrf
                                    @method('PATCH')
                                    <input name="name" value="{{ old('name', $tenant->name) }}" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" placeholder="Tenant name">
                                    <input name="slug" value="{{ old('slug', $tenant->slug) }}" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono text-xs" placeholder="tenant-slug">
                                    <select name="status" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                        <option value="active" @selected($tenant->status === 'active')>active</option>
                                        <option value="inactive" @selected($tenant->status !== 'active')>inactive</option>
                                    </select>
                                    <button class="w-full rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">No tenants found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
        <h3 class="text-sm font-semibold text-slate-900">User To Tenant Assignment</h3>
        <p class="mt-1 text-xs text-slate-500">
            Assign users to a tenant or keep them in platform scope. When changed, roles from other tenant scopes are removed automatically.
        </p>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left">User</th>
                        <th class="px-3 py-2 text-left">Current Scope</th>
                        <th class="px-3 py-2 text-left">Assign</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse(($users ?? []) as $user)
                        <tr>
                            <td class="px-3 py-3 align-top">
                                <p class="font-medium text-slate-900">{{ $user->name }}</p>
                                <p class="text-xs text-slate-500">{{ $user->email }}</p>
                                <p class="text-[11px] {{ $user->is_active ? 'text-emerald-700' : 'text-rose-700' }}">
                                    {{ $user->is_active ? 'active' : 'inactive' }}
                                </p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                @php($scopeTenant = $user->tenant_id ? $tenantMap->get($user->tenant_id) : null)
                                @if($scopeTenant)
                                    <span class="inline-flex rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700">
                                        {{ $scopeTenant->name }}
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                                        platform
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-3 align-top">
                                <form method="POST" action="{{ route('admin.saas.users.tenant.assign') }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $user->id }}">
                                    <select name="tenant_id" class="min-w-[12rem] rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                        <option value="">platform</option>
                                        @foreach(($tenants ?? []) as $tenant)
                                            <option value="{{ $tenant->id }}" @selected($user->tenant_id === $tenant->id)>
                                                {{ $tenant->name }} ({{ $tenant->slug }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                        Update
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 py-6 text-center text-sm text-slate-500">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-admin-layout>
