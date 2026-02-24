<x-admin-layout title="Access Control" heading="Role & Permission Management">
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 space-y-4">
            <h3 class="font-semibold">Create Role</h3>
            @error('access')
                <div class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
            @enderror
            <form method="POST" action="{{ route('admin.access.roles.create') }}" class="space-y-3">
                @csrf
                <input name="name" value="{{ old('name') }}" placeholder="Role name" class="w-full rounded-lg border border-slate-300 px-3 py-2" required />
                <input name="slug" value="{{ old('slug') }}" placeholder="role-slug" class="w-full rounded-lg border border-slate-300 px-3 py-2" required />
                <textarea name="description" placeholder="Description (optional)" class="w-full rounded-lg border border-slate-300 px-3 py-2 min-h-20">{{ old('description') }}</textarea>
                <div class="rounded-lg border border-slate-200 p-3 max-h-48 overflow-auto">
                    <p class="text-xs uppercase text-slate-500 mb-2">Initial permissions</p>
                    <div class="space-y-1">
                        @foreach(($permissions ?? []) as $permission)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}">
                                <span class="font-mono text-xs">{{ $permission->slug }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <button class="rounded-lg bg-ink text-white px-4 py-2 text-sm w-full">Create Role</button>
            </form>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 lg:col-span-2 space-y-4">
            <h3 class="font-semibold">Manage Roles</h3>
            <div class="space-y-4">
                @forelse(($roles ?? []) as $role)
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <div>
                                <p class="font-medium">{{ $role->name }}</p>
                                <p class="text-xs font-mono text-slate-500">{{ $role->slug }}</p>
                            </div>
                            <form method="POST" action="{{ route('admin.access.roles.delete', $role->id) }}">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-lg border border-red-300 text-red-700 px-3 py-1 text-xs" @disabled($role->slug === 'super-admin')>Delete</button>
                            </form>
                        </div>
                        <form method="POST" action="{{ route('admin.access.roles.permissions.update', $role->id) }}" class="space-y-2">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-1 md:grid-cols-2">
                                @php($rolePermissionIds = $role->permissions->pluck('id')->all())
                                @foreach(($permissions ?? []) as $permission)
                                    <label class="flex items-center gap-2 text-xs">
                                        <input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked(in_array($permission->id, $rolePermissionIds, true))>
                                        <span class="font-mono">{{ $permission->slug }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <button class="rounded-lg bg-skyline text-white px-3 py-2 text-xs">Save Role Permissions</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No roles found.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="rounded-2xl bg-white border border-slate-200 p-4 mt-4">
        <h3 class="font-semibold mb-3">Create Staff Account</h3>
        <form method="POST" action="{{ route('admin.access.users.create') }}" class="rounded-xl border border-slate-200 p-3 space-y-3 mb-4">
            @csrf
            <div class="grid gap-3 md:grid-cols-2">
                <input name="name" value="{{ old('name') }}" placeholder="Full name" class="w-full rounded-lg border border-slate-300 px-3 py-2" required />
                <input name="email" type="email" value="{{ old('email') }}" placeholder="staff@company.local" class="w-full rounded-lg border border-slate-300 px-3 py-2" required />
                <input name="password" type="password" placeholder="Password (min 8 chars)" class="w-full rounded-lg border border-slate-300 px-3 py-2" required />
                <input name="password_confirmation" type="password" placeholder="Confirm password" class="w-full rounded-lg border border-slate-300 px-3 py-2" required />
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" checked>
                Active account
            </label>
            <div class="rounded-lg border border-slate-200 p-3">
                <p class="text-xs uppercase text-slate-500 mb-2">Assign roles now</p>
                <div class="grid gap-1 md:grid-cols-3">
                    @foreach(($roles ?? []) as $role)
                        <label class="flex items-center gap-2 text-xs">
                            <input type="checkbox" name="role_ids[]" value="{{ $role->id }}">
                            <span>{{ $role->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <button class="rounded-lg bg-ink text-white px-4 py-2 text-sm">Create Staff</button>
        </form>

        <h3 class="font-semibold mb-3">Assign Roles To Users</h3>
        <div class="space-y-3">
            @forelse(($users ?? []) as $user)
                <form method="POST" action="{{ route('admin.access.users.roles.update', $user->id) }}" class="rounded-xl border border-slate-200 p-3 space-y-2">
                    @csrf
                    @method('PATCH')
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium">{{ $user->name }}</p>
                            <p class="text-xs text-slate-500">{{ $user->email }}</p>
                        </div>
                        <span class="text-xs {{ $user->is_active ? 'text-green-700' : 'text-red-700' }}">{{ $user->is_active ? 'active' : 'inactive' }}</span>
                    </div>
                    <div class="grid gap-1 md:grid-cols-3">
                        @php($userRoleIds = $user->roles->pluck('id')->all())
                        @foreach(($roles ?? []) as $role)
                            <label class="flex items-center gap-2 text-xs">
                                <input type="checkbox" name="role_ids[]" value="{{ $role->id }}" @checked(in_array($role->id, $userRoleIds, true))>
                                <span>{{ $role->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <button class="rounded-lg bg-ink text-white px-3 py-2 text-xs">Save User Roles</button>
                </form>
            @empty
                <p class="text-sm text-slate-500">No users found.</p>
            @endforelse
        </div>
    </div>
</x-admin-layout>
