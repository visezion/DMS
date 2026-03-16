<x-admin-layout title="SaaS Dashboard" heading="SaaS SuperAdmin Dashboard">
    @php
        $tenantMap = collect($tenants ?? [])->keyBy('id');
        $activeTenant = $activeTenantId ? $tenantMap->get($activeTenantId) : null;
    @endphp

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Tenants</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ (int) ($summary['tenants_total'] ?? 0) }}</p>
            <p class="mt-1 text-xs text-slate-500">
                Active {{ (int) ($summary['tenants_active'] ?? 0) }} / Inactive {{ (int) ($summary['tenants_inactive'] ?? 0) }}
            </p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Users</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ (int) ($summary['users_total'] ?? 0) }}</p>
            <p class="mt-1 text-xs text-slate-500">Platform accounts: {{ (int) ($summary['platform_users'] ?? 0) }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Devices</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ (int) ($summary['devices_total'] ?? 0) }}</p>
            <p class="mt-1 text-xs text-slate-500">Online now: {{ (int) ($summary['devices_online'] ?? 0) }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Jobs</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ (int) ($summary['jobs_total'] ?? 0) }}</p>
            <p class="mt-1 text-xs text-slate-500">Active queue: {{ (int) ($summary['jobs_active'] ?? 0) }}</p>
        </article>
        <article class="rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-rose-700">Failed Jobs</p>
            <p class="mt-2 text-3xl font-semibold text-rose-700">{{ (int) ($summary['jobs_failed'] ?? 0) }}</p>
            <p class="mt-1 text-xs text-rose-700">Review retries and delivery posture.</p>
        </article>
    </div>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Platform Context</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Scope daily admin operations to a tenant or clear scope for platform-wide actions.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.saas.tenants') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                    Open Tenant Management
                </a>
                <form method="POST" action="{{ route('admin.saas.tenants.switch.platform') }}">
                    @csrf
                    <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Switch To Platform Scope
                    </button>
                </form>
            </div>
        </div>
        <div class="mt-3">
            @if($activeTenant)
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                    Active Tenant: {{ $activeTenant->name }} ({{ $activeTenant->slug }})
                </span>
            @else
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    Active Scope: Platform (no tenant filter)
                </span>
            @endif
        </div>
    </section>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Tenant Fleet Monitor</h3>
                <p class="mt-1 text-xs text-slate-500">Cross-tenant view for operations, health, and quick scope switching.</p>
            </div>
            <a href="{{ route('admin.saas.tenants') }}" class="text-xs font-medium text-skyline hover:underline">Advanced tenant management</a>
        </div>

        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Tenant</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Users / Devices</th>
                        <th class="px-3 py-2 text-left">Jobs</th>
                        <th class="px-3 py-2 text-left">Last Audit Event</th>
                        <th class="px-3 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse(($tenants ?? []) as $tenant)
                        @php($lastAuditAt = $tenant->last_audit_at ? \Illuminate\Support\Carbon::parse($tenant->last_audit_at) : null)
                        <tr id="tenant-{{ $tenant->id }}">
                            <td class="px-3 py-3 align-top">
                                <p class="font-medium text-slate-900">{{ $tenant->name }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $tenant->slug }}</p>
                                <p class="text-[11px] text-slate-400">{{ $tenant->id }}</p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $tenant->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $tenant->status }}
                                </span>
                            </td>
                            <td class="px-3 py-3 align-top text-xs text-slate-600">
                                <p>Users: <span class="font-semibold text-slate-900">{{ (int) $tenant->users_count }}</span></p>
                                <p>Devices: <span class="font-semibold text-slate-900">{{ (int) $tenant->devices_count }}</span></p>
                                <p>Online: <span class="font-semibold text-slate-900">{{ (int) $tenant->online_devices_count }}</span></p>
                            </td>
                            <td class="px-3 py-3 align-top text-xs text-slate-600">
                                <p>Total: <span class="font-semibold text-slate-900">{{ (int) $tenant->jobs_total_count }}</span></p>
                                <p>Active: <span class="font-semibold text-slate-900">{{ (int) $tenant->jobs_active_count }}</span></p>
                                <p>Failed: <span class="font-semibold {{ (int) $tenant->jobs_failed_count > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ (int) $tenant->jobs_failed_count }}</span></p>
                            </td>
                            <td class="px-3 py-3 align-top text-xs text-slate-600">
                                @if($lastAuditAt)
                                    <p>{{ $lastAuditAt->diffForHumans() }}</p>
                                    <p class="text-[11px] text-slate-400">{{ $lastAuditAt->format('Y-m-d H:i:s') }}</p>
                                @else
                                    <p class="text-slate-500">No events</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="flex flex-wrap items-center gap-2">
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
                                    <a href="{{ route('admin.saas.tenants') }}#tenant-{{ $tenant->id }}" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                        Manage
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-sm text-slate-500">No tenants found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-admin-layout>
