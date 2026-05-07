<x-admin-layout title="Access Control" heading="Role & Permission Management">
    @php
        $summary = $accessSummary ?? [
            'roles_total' => collect($roles ?? [])->count(),
            'permissions_total' => collect($permissions ?? [])->count(),
            'users_total' => collect($users ?? [])->count(),
            'users_active' => collect($users ?? [])->where('is_active', true)->count(),
        ];
        $permissionGroups = collect($permissions ?? [])
            ->sortBy('slug')
            ->groupBy(fn ($permission) => \Illuminate\Support\Str::before((string) $permission->slug, '.') ?: 'misc');
        $selectedPermissionIds = collect(old('permission_ids', []))
            ->map(fn ($id) => (string) $id)
            ->all();
        $selectedRoleIds = collect(old('role_ids', []))
            ->map(fn ($id) => (string) $id)
            ->all();
        $tenantScopedMode = ! (bool) config('dms.standalone_mode', true);
        $staffAssignableRoles = collect($roles ?? [])->filter(function ($role) use ($accessTenantId) {
            if ($accessTenantId === null) {
                return $role->tenant_id === null;
            }

            return (string) $role->tenant_id === (string) $accessTenantId;
        })->values();
    @endphp

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Roles</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $summary['roles_total'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Custom access profiles available for assignment.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Permissions</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $summary['permissions_total'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Action-level gates enforced across API and admin web flows.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Staff Accounts</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $summary['users_total'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Accounts currently visible in the active scope.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Active Accounts</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $summary['users_active'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Inactive accounts fail permission checks even with assigned roles.</p>
        </div>
    </section>

    @if($errors->hasAny(['access', 'role_ids', 'name', 'slug', 'email', 'password']))
        <section class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <p class="font-medium">Access control update failed.</p>
            <ul class="mt-2 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-4 grid gap-4 xl:grid-cols-[1.05fr,1.95fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-900">Create Role</h3>
                    <p class="mt-1 text-xs text-slate-500">Define a reusable permission bundle with clear intent and scoped access.</p>
                </div>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                    {{ $tenantScopedMode ? 'Scope-aware' : 'Standalone' }}
                </span>
            </div>

            <form method="POST" action="{{ route('admin.access.roles.create') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-3">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500">Role Name</label>
                        <input name="name" value="{{ old('name') }}" placeholder="Operations Analyst" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500">Role Slug</label>
                        <input name="slug" value="{{ old('slug') }}" placeholder="operations-analyst" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm" required />
                        <p class="mt-1 text-[11px] text-slate-500">Use lowercase letters, numbers, dots, dashes, or underscores.</p>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500">Description</label>
                        <textarea name="description" placeholder="What this role is for and what it should be allowed to do." class="mt-1 min-h-24 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('description') }}</textarea>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Initial Permissions</p>
                        <span class="text-[11px] text-slate-500">{{ count($selectedPermissionIds) }} selected</span>
                    </div>
                    <div class="mt-3 space-y-3">
                        @foreach($permissionGroups as $group => $groupPermissions)
                            @php
                                $groupLabel = \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group));
                            @endphp
                            <details class="rounded-lg border border-slate-200 bg-white p-3" {{ $loop->first ? 'open' : '' }}>
                                <summary class="cursor-pointer list-none text-sm font-medium text-slate-800">
                                    {{ $groupLabel }}
                                    <span class="ml-2 text-xs font-normal text-slate-500">{{ $groupPermissions->count() }} permissions</span>
                                </summary>
                                <div class="mt-3 grid gap-2">
                                    @foreach($groupPermissions as $permission)
                                        @php
                                            $permissionAction = \Illuminate\Support\Str::headline(\Illuminate\Support\Str::after((string) $permission->slug, '.'));
                                            $permissionDetail = trim((string) ($permission->description ?? '')) ?: $permissionAction.' access for '.$groupLabel.'.';
                                        @endphp
                                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                            <input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked(in_array((string) $permission->id, $selectedPermissionIds, true)) class="mt-0.5 rounded border-slate-300">
                                            <span class="min-w-0">
                                                <span class="block font-medium text-slate-900">{{ $permissionAction }}</span>
                                                <span class="block font-mono text-[11px] text-slate-500">{{ $permission->slug }}</span>
                                                <span class="mt-1 block text-xs text-slate-500">{{ $permissionDetail }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>

                <button class="w-full rounded-lg bg-ink px-4 py-2 text-sm text-white">Create Role</button>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-900">Manage Roles</h3>
                    <p class="mt-1 text-xs text-slate-500">Review scope, membership, and exact permission bundles before assigning roles to staff.</p>
                </div>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                    Detailed RBAC
                </span>
            </div>

            <div class="mt-4 space-y-4">
                @forelse(($roles ?? []) as $role)
                    @php
                        $rolePermissionIds = $role->permissions->pluck('id')->map(fn ($id) => (string) $id)->all();
                        $rolePermissionGroups = $role->permissions->sortBy('slug')->groupBy(fn ($permission) => \Illuminate\Support\Str::before((string) $permission->slug, '.') ?: 'misc');
                        $isSuperAdminRole = (string) $role->slug === 'super-admin';
                        $roleScopeLabel = $tenantScopedMode ? ($role->tenant_id ? 'Tenant Role' : 'Platform Role') : 'Global Role';
                    @endphp
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/40 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-base font-semibold text-slate-900">{{ $role->name }}</h4>
                                    <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600">{{ $roleScopeLabel }}</span>
                                    @if($isSuperAdminRole)
                                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-medium text-emerald-700">Protected</span>
                                    @endif
                                </div>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $role->slug }}</p>
                                @if(!empty($role->description))
                                    <p class="mt-2 text-sm text-slate-600">{{ $role->description }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1">{{ (int) ($role->users_count ?? 0) }} users</span>
                                <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1">{{ $role->permissions->count() }} permissions</span>
                                <form method="POST" action="{{ route('admin.access.roles.delete', $role->id) }}" onsubmit="return confirm('Delete role {{ $role->name }}? This removes it from all assigned users.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs text-rose-700 disabled:cursor-not-allowed disabled:opacity-60" @disabled($isSuperAdminRole)>Delete</button>
                                </form>
                            </div>
                        </div>

                        @if($isSuperAdminRole)
                            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3 text-sm text-emerald-800">
                                <p class="font-medium">Super-admin stays full access.</p>
                                <p class="mt-1 text-xs text-emerald-700">This role is intentionally fixed so API and admin web permission enforcement cannot be weakened accidentally.</p>
                            </div>
                        @else
                            <form method="POST" action="{{ route('admin.access.roles.permissions.update', $role->id) }}" class="mt-4 space-y-3">
                                @csrf
                                @method('PATCH')
                                @foreach($permissionGroups as $group => $groupPermissions)
                                    @php
                                        $groupLabel = \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group));
                                    @endphp
                                    <details class="rounded-xl border border-slate-200 bg-white p-3" {{ $rolePermissionGroups->has($group) ? 'open' : '' }}>
                                        <summary class="cursor-pointer list-none text-sm font-medium text-slate-800">
                                            {{ $groupLabel }}
                                            <span class="ml-2 text-xs font-normal text-slate-500">{{ $groupPermissions->count() }} available</span>
                                        </summary>
                                        <div class="mt-3 grid gap-2 lg:grid-cols-2">
                                            @foreach($groupPermissions as $permission)
                                                @php
                                                    $permissionAction = \Illuminate\Support\Str::headline(\Illuminate\Support\Str::after((string) $permission->slug, '.'));
                                                    $permissionDetail = trim((string) ($permission->description ?? '')) ?: $permissionAction.' access for '.$groupLabel.'.';
                                                @endphp
                                                <label class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                                    <input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked(in_array((string) $permission->id, $rolePermissionIds, true)) class="mt-0.5 rounded border-slate-300">
                                                    <span class="min-w-0">
                                                        <span class="block font-medium text-slate-900">{{ $permissionAction }}</span>
                                                        <span class="block font-mono text-[11px] text-slate-500">{{ $permission->slug }}</span>
                                                        <span class="mt-1 block text-xs text-slate-500">{{ $permissionDetail }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                                <div class="flex justify-end">
                                    <button class="rounded-lg bg-skyline px-4 py-2 text-sm text-white">Save Role Permissions</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No roles found.</p>
                @endforelse
            </div>
        </article>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-[1.05fr,1.95fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">Create Staff Account</h3>
            <p class="mt-1 text-xs text-slate-500">Create an admin user in the current scope and assign one or more matching roles immediately.</p>

            <form method="POST" action="{{ route('admin.access.users.create') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-3">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500">Full Name</label>
                        <input name="name" value="{{ old('name') }}" placeholder="Full name" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500">Email</label>
                        <input name="email" type="email" value="{{ old('email') }}" placeholder="staff@company.local" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="text-xs uppercase tracking-wide text-slate-500">Password</label>
                            <input name="password" type="password" placeholder="Password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-slate-500">Confirm Password</label>
                            <input name="password_confirmation" type="password" placeholder="Confirm password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                        </div>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" checked>
                    Active account
                </label>

                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Assign Roles Now</p>
                    <div class="mt-3 grid gap-2">
                        @forelse($staffAssignableRoles as $role)
                            <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
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

                <button class="w-full rounded-lg bg-ink px-4 py-2 text-sm text-white">Create Staff</button>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">Assign Roles To Users</h3>
            <p class="mt-1 text-xs text-slate-500">Each user card shows assigned roles and the effective permission set resolved from those roles.</p>

            <div class="mt-4 space-y-4">
                @forelse(($users ?? []) as $user)
                    @php
                        $userRoleIds = $user->roles->pluck('id')->map(fn ($id) => (string) $id)->all();
                        $userHasSuperAdmin = $user->roles->contains(fn ($role) => (string) $role->slug === 'super-admin');
                        $effectivePermissions = $userHasSuperAdmin
                            ? collect($permissions ?? [])->sortBy('slug')->values()
                            : $user->roles
                                ->flatMap(fn ($role) => $role->permissions)
                                ->unique('id')
                                ->sortBy('slug')
                                ->values();
                        $effectivePermissionGroups = $effectivePermissions->groupBy(fn ($permission) => \Illuminate\Support\Str::before((string) $permission->slug, '.') ?: 'misc');
                        $assignableRoles = collect($roles ?? [])->filter(function ($role) use ($user) {
                            if ($user->tenant_id === null) {
                                return $role->tenant_id === null;
                            }

                            return (string) $role->tenant_id === (string) $user->tenant_id;
                        })->values();
                    @endphp
                    <form method="POST" action="{{ route('admin.access.users.roles.update', $user->id) }}" class="rounded-2xl border border-slate-200 bg-slate-50/40 p-4">
                        @csrf
                        @method('PATCH')
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-base font-semibold text-slate-900">{{ $user->name }}</h4>
                                    <span class="rounded-full border px-2.5 py-1 text-[11px] font-medium {{ $user->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                    @if($tenantScopedMode)
                                        <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                            {{ $user->tenant_id ? 'Tenant User' : 'Platform User' }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-slate-600">{{ $user->email }}</p>
                            </div>
                            <div class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600">
                                {{ $effectivePermissions->count() }} effective permissions
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3">
                            <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Assigned Roles</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @forelse($user->roles as $role)
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-700">
                                        {{ $role->name }}
                                        <span class="ml-1 font-mono text-[10px] text-slate-500">{{ $role->slug }}</span>
                                    </span>
                                @empty
                                    <span class="text-sm text-slate-500">No roles assigned.</span>
                                @endforelse
                            </div>
                        </div>

                        <details class="mt-3 rounded-xl border border-slate-200 bg-white p-3">
                            <summary class="cursor-pointer list-none text-sm font-medium text-slate-800">
                                Effective Permissions
                                <span class="ml-2 text-xs font-normal text-slate-500">{{ $effectivePermissions->count() }} total</span>
                            </summary>
                            <div class="mt-3 space-y-3">
                                @forelse($effectivePermissionGroups as $group => $groupPermissions)
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group)) }}</p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach($groupPermissions as $permission)
                                                <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-mono text-slate-700">{{ $permission->slug }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">No effective permissions.</p>
                                @endforelse
                            </div>
                        </details>

                        <div class="mt-3 rounded-xl border border-slate-200 bg-white p-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Role Assignment</p>
                                <span class="text-[11px] text-slate-500">{{ $assignableRoles->count() }} roles match this user scope</span>
                            </div>
                            <div class="mt-3 grid gap-2 lg:grid-cols-2">
                                @forelse($assignableRoles as $role)
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                        <input type="checkbox" name="role_ids[]" value="{{ $role->id }}" @checked(in_array((string) $role->id, $userRoleIds, true)) class="mt-0.5 rounded border-slate-300">
                                        <span class="min-w-0">
                                            <span class="block font-medium text-slate-900">{{ $role->name }}</span>
                                            <span class="block font-mono text-[11px] text-slate-500">{{ $role->slug }}</span>
                                            @if(!empty($role->description))
                                                <span class="mt-1 block text-xs text-slate-500">{{ $role->description }}</span>
                                            @endif
                                        </span>
                                    </label>
                                @empty
                                    <p class="text-sm text-slate-500">No roles are assignable in this user scope.</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="mt-3 flex justify-end">
                            <button class="rounded-lg bg-ink px-4 py-2 text-sm text-white">Save User Roles</button>
                        </div>
                    </form>
                @empty
                    <p class="text-sm text-slate-500">No users found.</p>
                @endforelse
            </div>
        </article>
    </section>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
        <h3 class="text-base font-semibold text-slate-900">Permission Reference</h3>
        <p class="mt-1 text-xs text-slate-500">Use this to keep role design consistent. Group related actions together instead of creating overly broad roles.</p>
        <div class="mt-4 grid gap-4 xl:grid-cols-3">
            @foreach($permissionGroups as $group => $groupPermissions)
                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-slate-900">{{ \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group)) }}</p>
                        <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] text-slate-500">{{ $groupPermissions->count() }}</span>
                    </div>
                    <div class="mt-3 space-y-2">
                        @foreach($groupPermissions as $permission)
                            @php
                                $permissionAction = \Illuminate\Support\Str::headline(\Illuminate\Support\Str::after((string) $permission->slug, '.'));
                                $permissionDetail = trim((string) ($permission->description ?? '')) ?: $permissionAction.' access for '.\Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group)).'.';
                            @endphp
                            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="font-mono text-[11px] text-slate-700">{{ $permission->slug }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $permissionDetail }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-admin-layout>
