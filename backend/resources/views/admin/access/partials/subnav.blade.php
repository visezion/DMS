@php
    $summary = $accessSummary ?? [
        'roles_total' => collect($roles ?? [])->count(),
        'users_total' => collect($users ?? [])->count(),
        'users_active' => collect($users ?? [])->where('is_active', true)->count(),
    ];
    $activeSection = $active ?? 'users';
    $scopeLabel = $tenantScopedMode
        ? ($accessTenantId ? 'Tenant Scope' : 'Platform Scope')
        : 'Standalone';
    $tabs = [
        ['id' => 'users', 'label' => 'Users', 'route' => 'admin.access.users'],
        ['id' => 'roles', 'label' => 'Roles', 'route' => 'admin.access.roles'],
    ];
@endphp

<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 lg:px-5">
        <div class="min-w-0">
            <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Access Control</p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold tracking-tight text-slate-900">{{ $title }}</h2>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">{{ $scopeLabel }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-[0.16em] text-slate-500">Users</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ $summary['users_total'] }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-[0.16em] text-emerald-700">Active</p>
                <p class="mt-1 text-lg font-semibold text-emerald-700">{{ $summary['users_active'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-[0.16em] text-slate-500">Roles</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ $summary['roles_total'] }}</p>
            </div>
        </div>
    </div>
    <div class="border-t border-slate-200 bg-slate-50/80 px-3 py-3 lg:px-4">
        <nav class="flex flex-wrap gap-2" aria-label="Access Control sections">
            @foreach($tabs as $tab)
                @php
                    $isActive = $activeSection === $tab['id'];
                @endphp
                <a
                    href="{{ route($tab['route']) }}"
                    class="{{ $isActive ? 'bg-skyline text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100' }} inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 text-sm font-medium transition"
                >
                    @if($tab['id'] === 'users')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9.5" cy="7" r="3"></circle>
                            <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 4.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    @elseif($tab['id'] === 'roles')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                            <path d="M12 3 4 7v6c0 4.5 3 7.7 8 9 5-1.3 8-4.5 8-9V7l-8-4Z"></path>
                            <path d="m9.5 12 1.8 1.8L14.8 10"></path>
                        </svg>
                    @endif
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
</section>
