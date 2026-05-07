<x-admin-layout title="Access Control" heading="Access Control">
    @include('admin.access.partials.subnav', [
        'active' => 'users',
        'title' => 'Users',
        'description' => 'Create staff accounts from a popup and manage role assignments in gallery view.',
    ])

    @php
        $userCollection = collect($users ?? []);
        $platformUsers = $userCollection->whereNull('tenant_id')->count();
        $tenantUsers = $userCollection->whereNotNull('tenant_id')->count();
        $activeUsers = $userCollection->where('is_active', true)->count();
        $inactiveUsers = $userCollection->where('is_active', false)->count();
        $superAdminUsers = $userCollection->filter(
            fn ($user) => $user->roles->contains(fn ($role) => (string) $role->slug === 'super-admin')
        )->count();
        $selectedRoleIds = collect(old('role_ids', []))->map(fn ($id) => (string) $id)->all();
        $createUserErrorKeys = ['name', 'email', 'password', 'password_confirmation'];
        if (old('name') !== null || old('email') !== null) {
            $createUserErrorKeys[] = 'role_ids';
        }
        $shouldOpenCreateModal = old('name') !== null
            || old('email') !== null
            || $errors->hasAny($createUserErrorKeys);
        $createUserErrors = collect($createUserErrorKeys)
            ->flatMap(fn ($key) => $errors->get($key))
            ->unique()
            ->values();
        $editingUserId = (string) old('edited_user_id', '');
        $shouldShowUserEditErrors = $editingUserId !== '';
        $userEditErrorKeys = ['display_name', 'login_email', 'new_password', 'new_password_confirmation', 'role_ids'];
        $userEditErrors = collect($userEditErrorKeys)
            ->flatMap(fn ($key) => $errors->get($key))
            ->unique()
            ->values();
    @endphp

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">User Overview</p>
                <h3 class="text-xl font-semibold">Admin Users</h3>
                <p class="text-sm text-slate-500">Gallery view with quick role editing and popup user creation.</p>
            </div>
            <button
                type="button"
                id="open-access-user-modal"
                class="rounded-lg bg-skyline px-4 py-2 text-sm font-medium text-white hover:bg-sky-600"
            >
                Add User
            </button>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Total Users</p>
                <p class="text-xl font-semibold text-slate-900">{{ $userCollection->count() }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-xs text-emerald-700">Active</p>
                <p class="text-xl font-semibold text-emerald-700">{{ $activeUsers }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Inactive</p>
                <p class="text-xl font-semibold text-slate-700">{{ $inactiveUsers }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                <p class="text-xs text-amber-700">Platform Users</p>
                <p class="text-xl font-semibold text-amber-700">{{ $platformUsers }}</p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3">
                <p class="text-xs text-rose-700">Super Admins</p>
                <p class="text-xl font-semibold text-rose-700">{{ $superAdminUsers }}</p>
            </div>
        </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        @if($shouldShowUserEditErrors && $userEditErrors->isNotEmpty())
            <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <p class="font-medium">User update could not be saved.</p>
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach($userEditErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mb-3 grid gap-2 lg:grid-cols-[auto,1fr,auto] lg:items-center">
            <h3 class="font-semibold">User Gallery</h3>
            <p class="text-xs text-slate-500">Open a card to edit the user profile, status, password, and roles.</p>
            <div class="flex items-center justify-end gap-2">
                <button
                    type="button"
                    id="access-users-gallery-toggle"
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
                    id="access-users-table-toggle"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700"
                    aria-pressed="false"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4" aria-hidden="true">
                        <path d="M4 6h16"></path>
                        <path d="M4 12h16"></path>
                        <path d="M4 18h16"></path>
                    </svg>
                    <span>Table</span>
                </button>
            </div>
        </div>

        <p class="mb-3 text-xs text-slate-500">Showing {{ $userCollection->count() }} users | Tenant users {{ $tenantUsers }}</p>

        <div id="access-users-gallery-view" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @forelse(($users ?? []) as $user)
                @php
                    $userRoleIds = $user->roles->pluck('id')->map(fn ($id) => (string) $id)->all();
                    $assignableRoles = collect($roles ?? [])->filter(function ($role) use ($user) {
                        if ($user->tenant_id === null) {
                            return $role->tenant_id === null;
                        }

                        return (string) $role->tenant_id === (string) $user->tenant_id;
                    })->values();
                    $userInitial = strtoupper(substr(trim((string) $user->name), 0, 1) ?: 'U');
                    $userHasSuperAdmin = $user->roles->contains(fn ($role) => (string) $role->slug === 'super-admin');
                    $statusClass = $user->is_active
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-slate-100 text-slate-700';
                    $isEditingUser = $editingUserId === (string) $user->id;
                    $editDisplayName = $isEditingUser ? old('display_name', $user->name) : $user->name;
                    $editLoginEmail = $isEditingUser ? old('login_email', $user->email) : $user->email;
                    $editRoleIds = $isEditingUser
                        ? collect(old('role_ids', []))->map(fn ($id) => (string) $id)->all()
                        : $userRoleIds;
                    $editIsActive = $isEditingUser ? old('is_active') !== null : (bool) $user->is_active;
                    $isCurrentUser = (int) auth()->id() === (int) $user->id;
                @endphp
                <article id="access-user-card-{{ $user->id }}" class="rounded-xl border border-slate-200 bg-slate-50/50 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-sky-50 text-xs font-semibold text-sky-700">
                                    {{ $userInitial }}
                                </span>
                                <div class="min-w-0">
                                    <h4 class="truncate text-sm font-semibold text-slate-900">{{ $user->name }}</h4>
                                    <p class="truncate text-[11px] text-slate-500">{{ $user->email }}</p>
                                </div>
                            </div>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusClass }}">
                            {{ $user->is_active ? 'active' : 'inactive' }}
                        </span>
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-1.5 text-[11px]">
                        <div class="rounded-md border border-slate-200 bg-white px-2 py-1">
                            <p class="text-slate-500">Scope</p>
                            <p class="font-medium text-slate-700">
                                @if($tenantScopedMode)
                                    {{ $user->tenant_id ? 'Tenant' : 'Platform' }}
                                @else
                                    Global
                                @endif
                            </p>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-white px-2 py-1">
                            <p class="text-slate-500">Roles</p>
                            <p class="font-medium text-slate-700">{{ $user->roles->count() }}</p>
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @forelse($user->roles as $role)
                            <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-700">
                                {{ $role->name }}
                            </span>
                        @empty
                            <span class="text-[11px] text-slate-500">No roles assigned</span>
                        @endforelse
                    @if($userHasSuperAdmin)
                        <span class="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] text-rose-700">super-admin</span>
                    @endif
                    </div>

                    <div class="mt-2 flex items-center justify-between gap-2">
                        <p class="text-[11px] text-slate-500">
                            {{ $isCurrentUser ? 'Current session account.' : 'Edit details or remove this account.' }}
                        </p>
                        @if(!$isCurrentUser)
                            <form method="POST" action="{{ route('admin.access.users.delete', $user->id) }}" onsubmit="return confirm('Delete user {{ $user->email }}? This removes their access immediately.');">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md border border-rose-300 px-2.5 py-1 text-[11px] font-medium text-rose-700">Delete User</button>
                            </form>
                        @endif
                    </div>

                    <details class="mt-2 rounded-md border border-slate-200 bg-white" {{ $isEditingUser ? 'open' : '' }}>
                        <summary class="cursor-pointer px-2 py-1.5 text-[11px] font-medium text-slate-600">Edit User</summary>
                        <form method="POST" action="{{ route('admin.access.users.update', $user->id) }}" class="grid gap-3 border-t border-slate-200 px-2 py-2">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="edited_user_id" value="{{ $user->id }}">

                            <div class="grid gap-2 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-medium uppercase tracking-[0.16em] text-slate-500">Name</label>
                                    <input
                                        type="text"
                                        name="display_name"
                                        value="{{ $editDisplayName }}"
                                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        required
                                    />
                                </div>
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-medium uppercase tracking-[0.16em] text-slate-500">Email</label>
                                    <input
                                        type="email"
                                        name="login_email"
                                        value="{{ $editLoginEmail }}"
                                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                        required
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] font-medium uppercase tracking-[0.16em] text-slate-500">New Password</label>
                                    <input
                                        type="password"
                                        name="new_password"
                                        placeholder="Leave blank to keep current"
                                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] font-medium uppercase tracking-[0.16em] text-slate-500">Confirm Password</label>
                                    <input
                                        type="password"
                                        name="new_password_confirmation"
                                        placeholder="Repeat new password"
                                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900"
                                    />
                                </div>
                            </div>

                            <label class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] font-medium text-slate-700">
                                <input type="checkbox" name="is_active" value="1" @checked($editIsActive) class="rounded border-slate-300">
                                Active account
                            </label>

                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[11px] font-medium uppercase tracking-[0.16em] text-slate-500">Roles</p>
                                    <span class="text-[11px] text-slate-500">Uncheck roles to remove access.</span>
                                </div>
                            @forelse($assignableRoles as $role)
                                <label class="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-[11px] text-slate-700">
                                    <input type="checkbox" name="role_ids[]" value="{{ $role->id }}" @checked(in_array((string) $role->id, $editRoleIds, true)) class="mt-0.5 rounded border-slate-300">
                                    <span class="min-w-0">
                                        <span class="block font-medium text-slate-900">{{ $role->name }}</span>
                                        <span class="block font-mono text-[10px] text-slate-500">{{ $role->slug }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="rounded-md border border-dashed border-slate-200 bg-slate-50 px-2 py-2 text-[11px] text-slate-500">No roles are assignable in this user scope.</p>
                            @endforelse
                            </div>

                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[11px] text-slate-500">Save profile, access state, and role changes together.</span>
                                <button class="rounded-md bg-ink px-2.5 py-1 text-[11px] font-medium text-white">Save User</button>
                            </div>
                        </form>
                    </details>
                </article>
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
                    <p class="text-sm font-medium text-slate-700">No users found</p>
                    <p class="mt-1 text-xs text-slate-500">Use Add User to create your first admin account.</p>
                </div>
            @endforelse
        </div>

        <div id="access-users-table-view" class="hidden overflow-hidden rounded-xl border border-slate-200">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">User</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Scope</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Roles</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse(($users ?? []) as $user)
                            @php
                                $tableUserHasSuperAdmin = $user->roles->contains(fn ($role) => (string) $role->slug === 'super-admin');
                            @endphp
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-sky-50 text-xs font-semibold text-sky-700">
                                            {{ strtoupper(substr(trim((string) $user->name), 0, 1) ?: 'U') }}
                                        </span>
                                        <span class="font-medium text-slate-900">{{ $user->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $user->email }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $user->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">
                                        {{ $user->is_active ? 'active' : 'inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-600">
                                    @if($tenantScopedMode)
                                        {{ $user->tenant_id ? 'Tenant' : 'Platform' }}
                                    @else
                                        Global
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse($user->roles as $role)
                                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] text-slate-700">
                                                {{ $role->name }}
                                            </span>
                                        @empty
                                            <span class="text-[11px] text-slate-500">No roles assigned</span>
                                        @endforelse
                                        @if($tableUserHasSuperAdmin)
                                            <span class="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] text-rose-700">super-admin</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            data-access-user-card-target="{{ $user->id }}"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50"
                                        >
                                            Edit
                                        </button>
                                        @if((int) auth()->id() !== (int) $user->id)
                                            <form method="POST" action="{{ route('admin.access.users.delete', $user->id) }}" onsubmit="return confirm('Delete user {{ $user->email }}? This removes their access immediately.');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="rounded-md border border-rose-300 px-3 py-1.5 text-[11px] font-medium text-rose-700">Delete</button>
                                            </form>
                                        @else
                                            <span class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-[11px] text-slate-500">Current session</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center">
                                    <p class="text-sm font-medium text-slate-700">No users found</p>
                                    <p class="mt-1 text-xs text-slate-500">Use Add User to create your first admin account.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div id="access-user-modal" class="{{ $shouldOpenCreateModal ? '' : 'hidden' }} fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4 py-6 backdrop-blur-sm">
        <div class="absolute inset-0" data-access-user-modal-close></div>

        <div class="relative z-10 w-full max-w-3xl overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_30px_80px_rgba(15,23,42,0.24)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Create User</p>
                    <h3 class="mt-1 text-2xl font-semibold text-slate-900">Add a staff account</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Create the account, choose active status, and assign roles before closing the popup.
                    </p>
                </div>
                <button type="button" data-access-user-modal-close class="rounded-full border border-slate-200 bg-white p-2 text-slate-500 hover:text-slate-900" aria-label="Close create user modal">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18"></path>
                    </svg>
                </button>
            </div>

            @if($createUserErrors->isNotEmpty())
                <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <p class="font-medium">User could not be created.</p>
                    <ul class="mt-2 space-y-1 text-xs">
                        @foreach($createUserErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.access.users.create') }}" class="mt-5 space-y-5" id="access-create-user-form">
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
                    Active account
                </label>

                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Roles</p>
                            <p class="mt-1 text-sm text-slate-600">Choose one or more roles for this user.</p>
                        </div>
                        <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600">{{ count($selectedRoleIds) }} selected</span>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
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

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <button type="button" data-access-user-modal-close class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button class="rounded-xl bg-ink px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                        Create User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('access-user-modal');
            const openButtons = Array.from(document.querySelectorAll('#open-access-user-modal, [data-open-access-user-modal]'));
            const closeButtons = Array.from(document.querySelectorAll('[data-access-user-modal-close]'));
            const galleryView = document.getElementById('access-users-gallery-view');
            const tableView = document.getElementById('access-users-table-view');
            const galleryToggle = document.getElementById('access-users-gallery-toggle');
            const tableToggle = document.getElementById('access-users-table-toggle');
            const cardEditButtons = Array.from(document.querySelectorAll('[data-access-user-card-target]'));
            const viewStorageKey = 'dms-access-users-view';

            function setModalState(open) {
                if (!modal) {
                    return;
                }

                modal.classList.toggle('hidden', !open);
                document.body.style.overflow = open ? 'hidden' : '';
            }

            function setView(viewName) {
                const useTable = viewName === 'table';

                if (galleryView) {
                    galleryView.classList.toggle('hidden', useTable);
                }

                if (tableView) {
                    tableView.classList.toggle('hidden', !useTable);
                }

                if (galleryToggle) {
                    galleryToggle.className = useTable
                        ? 'inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700'
                        : 'inline-flex items-center gap-2 rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white';
                    galleryToggle.setAttribute('aria-pressed', useTable ? 'false' : 'true');
                }

                if (tableToggle) {
                    tableToggle.className = useTable
                        ? 'inline-flex items-center gap-2 rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white'
                        : 'inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700';
                    tableToggle.setAttribute('aria-pressed', useTable ? 'true' : 'false');
                }

                try {
                    localStorage.setItem(viewStorageKey, useTable ? 'table' : 'gallery');
                } catch (error) {
                }
            }

            openButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setModalState(true);
                });
            });

            closeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setModalState(false);
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                    setModalState(false);
                }
            });

            galleryToggle?.addEventListener('click', function () {
                setView('gallery');
            });

            tableToggle?.addEventListener('click', function () {
                setView('table');
            });

            cardEditButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const userId = button.getAttribute('data-access-user-card-target');
                    const card = userId ? document.getElementById('access-user-card-' + userId) : null;

                    setView('gallery');

                    if (card) {
                        const details = card.querySelector('details');
                        if (details) {
                            details.setAttribute('open', 'open');
                        }

                        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });

            if (modal) {
                setModalState(!modal.classList.contains('hidden'));
            }

            let savedView = 'gallery';
            try {
                savedView = localStorage.getItem(viewStorageKey) || 'gallery';
            } catch (error) {
            }
            setView(savedView === 'table' ? 'table' : 'gallery');

            @if($shouldShowUserEditErrors && $editingUserId !== '')
                setView('gallery');
                const editedUserCard = document.getElementById('access-user-card-{{ $editingUserId }}');
                if (editedUserCard) {
                    editedUserCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            @endif
        })();
    </script>
</x-admin-layout>
