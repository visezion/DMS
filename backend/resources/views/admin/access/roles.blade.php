<x-admin-layout title="Role Library" heading="Access Control">
    @php
        $permissionGroups = collect($permissions ?? [])
            ->sortBy('slug')
            ->groupBy(fn ($permission) => \Illuminate\Support\Str::before((string) $permission->slug, '.') ?: 'misc');
        $selectedPermissionIds = collect(old('permission_ids', []))
            ->map(fn ($id) => (string) $id)
            ->all();
        $roleCollection = collect($roles ?? []);
        $protectedRoleCount = $roleCollection->where('slug', 'super-admin')->count();
        $customRoleCount = $roleCollection->reject(fn ($role) => (string) $role->slug === 'super-admin')->count();
        $totalAssignments = (int) $roleCollection->sum(fn ($role) => (int) ($role->users_count ?? 0));
        $shouldOpenCreateTab = old('name') !== null
            || old('slug') !== null
            || old('description') !== null;
    @endphp

    @include('admin.access.partials.subnav', [
        'active' => 'roles',
        'title' => 'Roles',
        'description' => 'See created roles in gallery view and open the create tab when you need a new one.',
    ])

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Role Overview</p>
                <h3 class="text-xl font-semibold">Role Gallery</h3>
                <p class="text-sm text-slate-500">Browse existing roles or switch to the create tab for a new role.</p>
            </div>
            <a href="{{ route('admin.access.users') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Open Users
            </a>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Total Roles</p>
                <p class="text-xl font-semibold text-slate-900">{{ $roleCollection->count() }}</p>
            </div>
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-3">
                <p class="text-xs text-sky-700">Custom Roles</p>
                <p class="text-xl font-semibold text-sky-700">{{ $customRoleCount }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-xs text-emerald-700">Protected Roles</p>
                <p class="text-xl font-semibold text-emerald-700">{{ $protectedRoleCount }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                <p class="text-xs text-amber-700">Role Assignments</p>
                <p class="text-xl font-semibold text-amber-700">{{ $totalAssignments }}</p>
            </div>
        </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-3 grid gap-2 lg:grid-cols-[auto,1fr,auto] lg:items-center">
            <h3 class="font-semibold">Roles</h3>
            <p class="text-xs text-slate-500">Default view shows created roles as cards. Switch tabs when you want to create a new role.</p>
            <div class="flex items-center justify-end gap-2">
                <button
                    type="button"
                    id="access-roles-gallery-toggle"
                    class="inline-flex items-center gap-2 rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white"
                    aria-pressed="true"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                        <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                        <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                        <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                        <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                    </svg>
                    <span>Gallery</span>
                </button>
                <button
                    type="button"
                    id="access-roles-create-toggle"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700"
                    aria-pressed="false"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                        <path d="M12 5v14"></path>
                        <path d="M5 12h14"></path>
                    </svg>
                    <span>Create Role</span>
                </button>
            </div>
        </div>

        <div id="access-roles-gallery-view" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse(($roles ?? []) as $role)
                @php
                    $rolePermissionIds = $role->permissions->pluck('id')->map(fn ($id) => (string) $id)->all();
                    $rolePermissionGroups = $role->permissions
                        ->sortBy('slug')
                        ->groupBy(fn ($permission) => \Illuminate\Support\Str::before((string) $permission->slug, '.') ?: 'misc');
                    $isSuperAdminRole = (string) $role->slug === 'super-admin';
                    $roleScopeLabel = $tenantScopedMode ? ($role->tenant_id ? 'Tenant Role' : 'Platform Role') : 'Global Role';
                @endphp
                <article class="rounded-xl border border-slate-200 bg-slate-50/50 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-sky-50 text-sky-700">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                                        <path d="M12 3 4 7v6c0 4.5 3 7.7 8 9 5-1.3 8-4.5 8-9V7l-8-4Z"></path>
                                        <path d="m9.5 12 1.8 1.8L14.8 10"></path>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <h4 class="truncate text-sm font-semibold text-slate-900">{{ $role->name }}</h4>
                                    <p class="truncate text-[11px] text-slate-500">{{ $role->slug }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600">{{ $roleScopeLabel }}</span>
                            @if($isSuperAdminRole)
                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">Protected</span>
                            @endif
                        </div>
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-1.5 text-[11px]">
                        <a href="{{ route('admin.access.users') }}" class="rounded-md border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50">
                            <p class="text-slate-500">Users</p>
                            <p class="font-medium text-slate-700">{{ (int) ($role->users_count ?? 0) }}</p>
                        </a>
                        <div class="rounded-md border border-slate-200 bg-white px-2 py-1">
                            <p class="text-slate-500">Permissions</p>
                            <p class="font-medium text-slate-700">{{ $role->permissions->count() }}</p>
                        </div>
                    </div>

                    @if(!empty($role->description))
                        <p class="mt-2 text-[11px] leading-5 text-slate-600">{{ $role->description }}</p>
                    @endif

                    @if(!$isSuperAdminRole)
                        <div class="mt-2 flex items-center justify-end">
                            <form method="POST" action="{{ route('admin.access.roles.delete', $role->id) }}" onsubmit="return confirm('Delete role {{ $role->name }}? This removes it from all assigned users.');">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md border border-rose-300 px-2.5 py-1 text-[11px] font-medium text-rose-700">Delete Role</button>
                            </form>
                        </div>
                    @endif

                    @if($isSuperAdminRole)
                        <div class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] text-emerald-800">
                            Super-admin stays full access and cannot be edited here.
                        </div>
                    @else
                        <details class="mt-2 rounded-md border border-slate-200 bg-white">
                            <summary class="cursor-pointer px-2 py-1.5 text-[11px] font-medium text-slate-600">Edit Permissions</summary>
                            <form method="POST" action="{{ route('admin.access.roles.permissions.update', $role->id) }}" class="grid gap-1.5 border-t border-slate-200 px-2 py-2">
                                @csrf
                                @method('PATCH')
                                @foreach($permissionGroups as $group => $groupPermissions)
                                    @php
                                        $groupLabel = \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group));
                                    @endphp
                                    <details class="rounded-md border border-slate-200 bg-slate-50">
                                        <summary class="cursor-pointer px-2 py-1.5 text-[11px] font-medium text-slate-700">
                                            {{ $groupLabel }}
                                            <span class="ml-1 text-slate-500">({{ $groupPermissions->count() }})</span>
                                        </summary>
                                        <div class="grid gap-1.5 border-t border-slate-200 px-2 py-2">
                                            @foreach($groupPermissions as $permission)
                                                <label class="flex items-start gap-2 rounded-md border border-slate-200 bg-white px-2 py-1.5 text-[11px] text-slate-700">
                                                    <input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked(in_array((string) $permission->id, $rolePermissionIds, true)) class="mt-0.5 rounded border-slate-300">
                                                    <span class="min-w-0">
                                                        <span class="block font-medium text-slate-900">{{ \Illuminate\Support\Str::headline(\Illuminate\Support\Str::after((string) $permission->slug, '.')) }}</span>
                                                        <span class="block font-mono text-[10px] text-slate-500">{{ $permission->slug }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                                <div class="flex items-center justify-between gap-2 pt-1">
                                    <span class="text-[11px] text-slate-500">Uncheck any permission you want to remove from this role.</span>
                                    <button class="rounded-md bg-ink px-2.5 py-1 text-[11px] font-medium text-white">Save Permissions</button>
                                </div>
                            </form>
                        </details>
                    @endif
                </article>
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
                    <p class="text-sm font-medium text-slate-700">No roles found</p>
                    <p class="mt-1 text-xs text-slate-500">Open the create tab to add the first role.</p>
                </div>
            @endforelse
        </div>

        <div id="access-roles-create-view" class="hidden">
            <div class="grid gap-4 xl:grid-cols-[1.1fr,0.9fr]">
                <article class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Create Role</p>
                            <h3 class="mt-2 text-lg font-semibold text-slate-900">Add a new role</h3>
                            <p class="mt-2 text-sm text-slate-600">Fill in the role name, slug, and select permissions. Keep roles small and specific.</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.access.roles.create') }}" class="mt-5 space-y-4">
                        @csrf
                        <div class="grid gap-3">
                            <div>
                                <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Role Name</label>
                                <input name="name" value="{{ old('name') }}" placeholder="Operations Analyst" class="mt-1.5 w-full rounded-2xl border border-slate-300 px-4 py-3" required />
                            </div>
                            <div>
                                <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Role Slug</label>
                                <input name="slug" value="{{ old('slug') }}" placeholder="operations-analyst" class="mt-1.5 w-full rounded-2xl border border-slate-300 px-4 py-3 font-mono text-sm" required />
                                <p class="mt-1 text-[11px] text-slate-500">Use lowercase letters, numbers, dots, dashes, or underscores.</p>
                            </div>
                            <div>
                                <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Description</label>
                                <textarea name="description" placeholder="Short description of what this role should manage." class="mt-1.5 min-h-24 w-full rounded-2xl border border-slate-300 px-4 py-3">{{ old('description') }}</textarea>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Permissions</p>
                                    <p class="mt-1 text-sm text-slate-600">Pick only the actions this role should have.</p>
                                </div>
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">{{ count($selectedPermissionIds) }} selected</span>
                            </div>
                            <div class="mt-4 space-y-3">
                                @foreach($permissionGroups as $group => $groupPermissions)
                                    @php
                                        $groupLabel = \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', (string) $group));
                                    @endphp
                                    <details class="rounded-xl border border-slate-200 bg-slate-50/70 p-3" {{ $loop->first ? 'open' : '' }}>
                                        <summary class="cursor-pointer list-none text-sm font-medium text-slate-800">
                                            {{ $groupLabel }}
                                            <span class="ml-2 text-xs font-normal text-slate-500">{{ $groupPermissions->count() }} permissions</span>
                                        </summary>
                                        <div class="mt-3 grid gap-2 lg:grid-cols-2">
                                            @foreach($groupPermissions as $permission)
                                                <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm">
                                                    <input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked(in_array((string) $permission->id, $selectedPermissionIds, true)) class="mt-0.5 rounded border-slate-300">
                                                    <span class="min-w-0">
                                                        <span class="block font-medium text-slate-900">{{ \Illuminate\Support\Str::headline(\Illuminate\Support\Str::after((string) $permission->slug, '.')) }}</span>
                                                        <span class="block font-mono text-[11px] text-slate-500">{{ $permission->slug }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button class="rounded-xl bg-ink px-4 py-2.5 text-sm font-medium text-white shadow-sm">Create Role</button>
                        </div>
                    </form>
                </article>

                <article class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Easy Setup</p>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <p class="text-sm font-medium text-slate-900">1. Name the role clearly</p>
                            <p class="mt-1 text-xs text-slate-500">Use names like `Staff`, `Operations Analyst`, or `Policy Manager`.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <p class="text-sm font-medium text-slate-900">2. Keep permissions focused</p>
                            <p class="mt-1 text-xs text-slate-500">Give only the permissions needed for the job. It makes access easier to understand later.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <p class="text-sm font-medium text-slate-900">3. Assign from Users page</p>
                            <p class="mt-1 text-xs text-slate-500">After creating the role, go to Users and assign it to the right people.</p>
                            <a href="{{ route('admin.access.users') }}" class="mt-3 inline-flex rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                Open Users
                            </a>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <script>
        (function () {
            const galleryView = document.getElementById('access-roles-gallery-view');
            const createView = document.getElementById('access-roles-create-view');
            const galleryToggle = document.getElementById('access-roles-gallery-toggle');
            const createToggle = document.getElementById('access-roles-create-toggle');
            const viewStorageKey = 'dms-access-roles-view';
            const createTabDefault = {{ $shouldOpenCreateTab ? 'true' : 'false' }};

            function setView(viewName) {
                const useCreate = viewName === 'create';

                if (galleryView) {
                    galleryView.classList.toggle('hidden', useCreate);
                }

                if (createView) {
                    createView.classList.toggle('hidden', !useCreate);
                }

                if (galleryToggle) {
                    galleryToggle.className = useCreate
                        ? 'inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700'
                        : 'inline-flex items-center gap-2 rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white';
                    galleryToggle.setAttribute('aria-pressed', useCreate ? 'false' : 'true');
                }

                if (createToggle) {
                    createToggle.className = useCreate
                        ? 'inline-flex items-center gap-2 rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white'
                        : 'inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700';
                    createToggle.setAttribute('aria-pressed', useCreate ? 'true' : 'false');
                }

                try {
                    localStorage.setItem(viewStorageKey, useCreate ? 'create' : 'gallery');
                } catch (error) {
                }
            }

            galleryToggle?.addEventListener('click', function () {
                setView('gallery');
            });

            createToggle?.addEventListener('click', function () {
                setView('create');
            });

            let savedView = createTabDefault ? 'create' : 'gallery';
            try {
                savedView = localStorage.getItem(viewStorageKey) || savedView;
            } catch (error) {
            }

            setView(savedView === 'create' ? 'create' : 'gallery');
        })();
    </script>
</x-admin-layout>
