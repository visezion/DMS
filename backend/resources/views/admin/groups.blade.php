<x-admin-layout title="Groups" heading="Device Groups">
    @php($memberCounts = $memberCounts ?? collect())
    @php($policyCounts = $policyCounts ?? collect())
    @php($packageCounts = $packageCounts ?? collect())

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl bg-white border border-slate-200 p-4">
            <h3 class="font-semibold mb-3">Create Group</h3>
            <form method="POST" action="{{ route('admin.groups.create') }}" class="space-y-3">
                @csrf
                <input name="name" placeholder="Group name" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <textarea name="description" placeholder="Description" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <select name="device_ids[]" multiple class="w-full min-h-32 rounded-lg border border-slate-300 px-3 py-2">
                    @foreach($devices as $device)
                        <option value="{{ $device->id }}">{{ $device->hostname }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg bg-skyline text-white px-4 py-2 text-sm">Create Group</button>
            </form>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 lg:col-span-2">
            <h3 class="font-semibold mb-3">Groups</h3>
            <p class="text-xs text-slate-500 mb-3">Open a group to manage members, policies, and packages in a dedicated detail page.</p>

            <div class="space-y-3">
                @forelse($groups as $group)
                    <div class="rounded-xl border border-slate-200 p-3 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ $group->name }}</p>
                            <p class="text-xs text-slate-500">{{ $group->description ?: 'No description' }}</p>
                            <p class="text-xs text-slate-500 mt-1">Updated: {{ $group->updated_at }}</p>
                        </div>
                        <div class="text-xs text-slate-600 flex items-center gap-2">
                            <span class="rounded-full bg-slate-100 px-2 py-1">Members: {{ (int) ($memberCounts[$group->id] ?? 0) }}</span>
                            <span class="rounded-full bg-sky-100 text-sky-700 px-2 py-1">Policies: {{ (int) ($policyCounts[$group->id] ?? 0) }}</span>
                            <span class="rounded-full bg-emerald-100 text-emerald-700 px-2 py-1">Packages: {{ (int) ($packageCounts[$group->id] ?? 0) }}</span>
                            <a href="{{ route('admin.groups.show', $group->id) }}" class="rounded bg-ink text-white px-3 py-1">Open</a>
                            <form method="POST" action="{{ route('admin.groups.delete', $group->id) }}" onsubmit="return confirm('Delete group {{ $group->name }}? Members will be detached and cleanup jobs will be queued.');">
                                @csrf
                                @method('DELETE')
                                <button class="rounded border border-red-300 text-red-700 px-3 py-1">Delete</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No groups yet.</p>
                @endforelse
            </div>

            <div class="mt-4">{{ $groups->links() }}</div>
        </div>
    </div>
</x-admin-layout>
