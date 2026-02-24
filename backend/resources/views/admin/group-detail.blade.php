<x-admin-layout title="Group Detail" heading="Group Detail">
    @php($members = $members ?? collect())
    @php($policies = $policies ?? collect())
    @php($packages = $packages ?? collect())
    @php($memberIds = $members->pluck('device_id')->all())

    <style>
        .group-shell {
            border-color: #d7deea;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
    </style>

    <div class="mb-3 flex items-center justify-between gap-2">
        <a href="{{ route('admin.groups') }}" class="inline-flex items-center gap-1 text-sm text-slate-700 hover:text-black">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 19 8.5 12l7-7"/></svg>
            Back to Groups
        </a>
        <form method="POST" action="{{ route('admin.groups.delete', $group->id) }}" onsubmit="return confirm('Delete this group? Members will be detached and cleanup jobs will be queued.');">
            @csrf
            @method('DELETE')
            <button class="rounded border border-red-300 bg-white px-3 py-1.5 text-xs text-red-700 hover:bg-red-50">Delete Group</button>
        </form>
    </div>

    <div class="rounded-2xl border bg-gradient-to-br from-slate-50 via-white to-slate-100 p-5 mb-4 group-shell">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Device Group</p>
                <h3 class="text-2xl font-semibold text-slate-900 truncate">{{ $group->name }}</h3>
                <p class="text-sm text-slate-600 mt-1">{{ $group->description ?: 'No description' }}</p>
                <p class="text-xs text-slate-500 mt-1">Last update: {{ $group->updated_at }}</p>
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs min-w-[240px]">
                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-center">
                    <p class="uppercase tracking-wide text-slate-500">Members</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $members->count() }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-center">
                    <p class="uppercase tracking-wide text-slate-500">Policies</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $policies->count() }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-center">
                    <p class="uppercase tracking-wide text-slate-500">Packages</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $packages->count() }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-4 group-shell">
            <h4 class="font-semibold text-slate-900 mb-3 flex items-center gap-2">
                <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM8 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-5.5 8a5.5 5.5 0 0 1 11 0h-11ZM13 20a5 5 0 0 1 8.5-3.5V20H13Z"/></svg>
                Group Members
            </h4>
            <form method="POST" action="{{ route('admin.groups.members.add', $group->id) }}" class="flex gap-2 mb-3">
                @csrf
                <select name="device_id" class="flex-1 rounded border border-slate-300 px-2 py-1.5 text-sm" required>
                    <option value="">Add device...</option>
                    @foreach($devices as $device)
                        @if(!in_array($device->id, $memberIds, true))
                            <option value="{{ $device->id }}">{{ $device->hostname }}</option>
                        @endif
                    @endforeach
                </select>
                <button class="rounded bg-ink text-white px-3 py-1.5 text-xs">Add</button>
            </form>

            <div class="max-h-80 overflow-auto space-y-2 pr-1">
                @forelse($members as $member)
                    <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900 truncate">{{ $member->hostname }}</p>
                            <p class="text-[11px] text-slate-500">status: {{ $member->status }} | agent: {{ $member->agent_version }}</p>
                        </div>
                        <form method="POST" action="{{ route('admin.groups.members.remove', [$group->id, $member->device_id]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="rounded border border-red-300 text-red-700 px-2 py-1 text-xs">Remove</button>
                        </form>
                    </div>
                @empty
                    <p class="text-xs text-slate-500">No members yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 group-shell">
            <h4 class="font-semibold text-slate-900 mb-3 flex items-center gap-2">
                <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 4 7v6c0 5 3.5 7.8 8 9 4.5-1.2 8-4 8-9V7l-8-4Z"/></svg>
                Group Policies
            </h4>
            <form method="POST" action="{{ route('admin.groups.policies.add', $group->id) }}" class="space-y-2 mb-3">
                @csrf
                <select name="policy_version_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" required>
                    <option value="">Assign policy version...</option>
                    @foreach(($policyVersionOptions ?? collect()) as $option)
                        <option value="{{ $option->id }}">
                            {{ $option->policy_name }} ({{ $option->policy_slug }}) v{{ $option->version_number }} [{{ $option->status }}]
                        </option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="queue_now" value="1" checked>
                    Queue apply policy now
                </label>
                <button class="rounded bg-skyline text-white px-3 py-1.5 text-xs">Add Policy</button>
            </form>
            <div class="max-h-80 overflow-auto space-y-2 pr-1">
                @forelse($policies as $policy)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900 truncate">{{ $policy->policy_name }}</p>
                            <p class="text-[11px] text-slate-500 font-mono truncate">{{ $policy->policy_slug }}</p>
                            <p class="text-[11px] text-slate-500">version: {{ $policy->version_number }} | status: {{ $policy->policy_version_status }}</p>
                        </div>
                        <form method="POST" action="{{ route('admin.groups.policies.remove', [$group->id, $policy->assignment_id]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="rounded border border-red-300 text-red-700 px-2 py-1 text-xs">Remove</button>
                        </form>
                    </div>
                @empty
                    <p class="text-xs text-slate-500">No policy assignments for this group.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 group-shell">
            <h4 class="font-semibold text-slate-900 mb-3 flex items-center gap-2">
                <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>
                Group Packages
            </h4>
            @error('group_package')
                <div class="mb-2 rounded border border-red-300 bg-red-50 px-2 py-1 text-xs text-red-700">{{ $message }}</div>
            @enderror
            <form method="POST" action="{{ route('admin.groups.packages.add', $group->id) }}" class="space-y-2 mb-3">
                @csrf
                <select name="package_version_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" required>
                    <option value="">Assign package version...</option>
                    @foreach(($packageVersionOptions ?? collect()) as $option)
                        <option value="{{ $option->id }}">
                            {{ $option->package_name }} ({{ $option->package_slug }}) v{{ $option->version }} [{{ $option->package_type }}]
                        </option>
                    @endforeach
                </select>
                <div class="grid grid-cols-2 gap-2">
                    <input name="priority" type="number" min="1" max="1000" value="100" placeholder="Priority" class="rounded border border-slate-300 px-2 py-1.5 text-sm" />
                    <input name="stagger_seconds" type="number" min="0" max="3600" value="0" placeholder="Stagger (s)" class="rounded border border-slate-300 px-2 py-1.5 text-sm" />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <input name="expires_hours" type="number" min="1" max="168" value="24" placeholder="URL expiry (h)" class="rounded border border-slate-300 px-2 py-1.5 text-sm" />
                    <input name="public_base_url" value="{{ $defaultPublicBase ?? '' }}" placeholder="Public base URL" class="rounded border border-slate-300 px-2 py-1.5 text-sm" />
                </div>
                <button class="rounded bg-emerald-600 text-white px-3 py-1.5 text-xs">Add Package</button>
            </form>

            <div class="max-h-80 overflow-auto space-y-2 pr-1">
                @forelse($packages as $pkg)
                    @php($summary = is_array($pkg->run_summary ?? null) ? $pkg->run_summary : ['pending' => 0, 'running' => 0, 'acked' => 0, 'success' => 0, 'failed' => 0])
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900 truncate">{{ $pkg->package_name ?? 'Unknown package' }} <span class="text-[11px] text-slate-500">v{{ $pkg->package_version ?? '-' }}</span></p>
                                <p class="text-[11px] text-slate-500 font-mono truncate">{{ $pkg->package_slug ?? '-' }} | {{ $pkg->job_type }}</p>
                                <p class="text-[11px] text-slate-500">
                                    job: {{ $pkg->job_status }} |
                                    pending: {{ $summary['pending'] ?? 0 }},
                                    running: {{ $summary['running'] ?? 0 }},
                                    success: {{ $summary['success'] ?? 0 }},
                                    failed: {{ $summary['failed'] ?? 0 }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('admin.groups.packages.remove', [$group->id, $pkg->job_id]) }}" onsubmit="return confirm('Remove this package from group and queue uninstall on all current group devices?');">
                                @csrf
                                @method('DELETE')
                                <button class="rounded border border-red-300 text-red-700 px-2 py-1 text-xs">Remove</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-slate-500">No package assignments for this group.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-admin-layout>
