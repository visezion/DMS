<x-admin-layout title="Permission Library" heading="Access Control">
    @php
        $permissionGroups = collect($permissions ?? [])
            ->sortBy('slug')
            ->groupBy(fn ($permission) => \Illuminate\Support\Str::before((string) $permission->slug, '.') ?: 'misc');
        $protectedRoles = collect($roles ?? [])->filter(fn ($role) => (string) $role->slug === 'super-admin')->count();
    @endphp

    @include('admin.access.partials.subnav', [
        'active' => 'permissions',
        'title' => 'Permission Library',
        'description' => 'Review action-level entitlements by domain, see which roles currently carry them, and use that map before expanding production access.',
    ])

    <section class="grid gap-4 lg:grid-cols-3">
        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Permission Domains</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $permissionGroups->count() }}</p>
            <p class="mt-2 text-sm text-slate-600">Permissions are grouped by domain prefix so it is easier to reason about operational scope.</p>
        </article>
        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Role Profiles</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ collect($roles ?? [])->count() }}</p>
            <p class="mt-2 text-sm text-slate-600">Use the role library to bundle permissions by responsibility instead of granting broad one-off access.</p>
        </article>
        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Protected Roles</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $protectedRoles }}</p>
            <p class="mt-2 text-sm text-slate-600">The protected super-admin role remains fixed so the highest-privilege path cannot be edited by mistake.</p>
        </article>
    </section>

    <section class="space-y-4">
        @foreach($permissionGroups as $group => $groupPermissions)
            @php
                $groupLabel = \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group));
            @endphp
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">{{ $groupLabel }}</p>
                        <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ $groupPermissions->count() }} permissions in this domain</h3>
                    </div>
                    <a href="{{ route('admin.access.roles') }}" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">Manage Roles</a>
                </div>

                <div class="mt-5 grid gap-3 xl:grid-cols-2">
                    @foreach($groupPermissions as $permission)
                        @php
                            $permissionAction = \Illuminate\Support\Str::headline(\Illuminate\Support\Str::after((string) $permission->slug, '.'));
                            $permissionDetail = trim((string) ($permission->description ?? '')) ?: $permissionAction.' access for '.$groupLabel.'.';
                            $coverageRoles = collect($roles ?? [])->filter(
                                fn ($role) => $role->permissions->contains(fn ($rolePermission) => (string) $rolePermission->id === (string) $permission->id)
                            )->values();
                        @endphp
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h4 class="text-base font-semibold text-slate-900">{{ $permissionAction }}</h4>
                                    <p class="mt-1 font-mono text-[11px] text-slate-500">{{ $permission->slug }}</p>
                                </div>
                                <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600">{{ $coverageRoles->count() }} roles</span>
                            </div>
                            <p class="mt-3 text-sm text-slate-600">{{ $permissionDetail }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @forelse($coverageRoles as $role)
                                    <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-700">
                                        {{ $role->name }}
                                        <span class="ml-1 font-mono text-[10px] text-slate-500">{{ $role->slug }}</span>
                                    </span>
                                @empty
                                    <span class="text-sm text-slate-500">Not assigned to any role yet.</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>
</x-admin-layout>
