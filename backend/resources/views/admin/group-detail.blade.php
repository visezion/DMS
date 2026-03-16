<x-admin-layout title="Group Detail" heading="Group Detail">
    @php
        $members = $members ?? collect();
        $policies = $policies ?? collect();
        $packages = $packages ?? collect();
        $memberIds = $members->pluck('device_id')->all();
        $availableDevices = collect($devices ?? [])
            ->reject(fn ($device) => in_array($device->id, $memberIds, true))
            ->values();
    @endphp
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

    <section class="rounded-2xl border border-slate-200 bg-white p-4 mb-4 group-shell">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
            <div>
                <h4 class="font-semibold text-slate-900 flex items-center gap-2">
                    <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 4 6v6c0 5 3.5 7.8 8 9 4.5-1.2 8-4 8-9V6l-8-4Z"/><path d="m9.5 12 1.8 1.8L14.8 10" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    Kiosk Lockdown Bundle
                </h4>
                <p class="text-xs text-slate-500 mt-1">Applies composable lockdown controls to this group using your existing policy engine.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                Target: <span class="font-semibold text-slate-800">{{ $group->name }}</span>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.groups.kiosk-lockdown', $group->id) }}" class="space-y-3">
            @csrf
            @error('group_policy')
                <div class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
            @enderror
            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                @foreach(($kioskPresetMatrix ?? []) as $section)
                    @php
                        $toggle = (string) ($section['toggle'] ?? '');
                        $presetKeys = collect($section['preset_keys'] ?? [])->filter()->values();
                        $allAssigned = false;
                        if ($presetKeys->count() > 0) {
                            $allAssigned = true;
                            foreach ($presetKeys as $presetKey) {
                                if (!((bool) (($assignedKioskPresetMap ?? [])[$presetKey] ?? false))) {
                                    $allAssigned = false;
                                    break;
                                }
                            }
                        }
                        if ($toggle === '') {
                            continue;
                        }
                    @endphp
                    <label class="rounded-xl border px-3 py-2 {{ $allAssigned ? 'border-emerald-200 bg-emerald-50/70' : 'border-slate-200 bg-slate-50/70' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-slate-900">{{ $section['label'] ?? $toggle }}</span>
                            <span class="text-[11px] {{ $allAssigned ? 'text-emerald-700' : 'text-slate-500' }}">{{ $allAssigned ? 'assigned' : 'not fully assigned' }}</span>
                        </div>
                        <div class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                            <input type="checkbox" name="{{ $toggle }}" value="1" checked class="rounded border-slate-300">
                            Include in rollout
                        </div>
                    </label>
                @endforeach
            </div>

            <label class="flex items-center gap-2 text-xs text-slate-700">
                <input type="checkbox" name="queue_now" value="1" checked class="rounded border-slate-300">
                Queue apply_policy jobs to current group members now
            </label>

            <button class="rounded-lg bg-skyline text-white px-4 py-2 text-sm">Apply Kiosk Lockdown Bundle</button>
        </form>
    </section>

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-4 group-shell">
            <h4 class="font-semibold text-slate-900 mb-3 flex items-center gap-2">
                <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM8 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-5.5 8a5.5 5.5 0 0 1 11 0h-11ZM13 20a5 5 0 0 1 8.5-3.5V20H13Z"/></svg>
                Group Members
            </h4>
            <form method="POST" action="{{ route('admin.groups.members.add', $group->id) }}" class="searchable-select-form search-panel-shell rounded-xl space-y-2 mb-3" data-empty-label="No devices match this search." data-placeholder-label="Add device..." data-count-label="available">
                @csrf
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Add Member</p>
                        <p class="text-[11px] text-slate-500">Search by hostname, then add the selected device to this group.</p>
                    </div>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-600 searchable-select-count">
                        {{ $availableDevices->count() }} available
                    </span>
                </div>
                <div class="grid gap-2">
                    <input
                        type="search"
                        class="searchable-select-input rounded border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Search devices by hostname..."
                        autocomplete="off"
                    />
                    <div class="flex gap-2">
                        <select name="device_id" class="searchable-select-control flex-1 rounded border border-slate-300 px-2 py-1.5 text-sm" required>
                            <option value="">Add device...</option>
                            @foreach($availableDevices as $device)
                                <option value="{{ $device->id }}">{{ $device->hostname }}</option>
                            @endforeach
                        </select>
                        <button class="rounded bg-ink text-white px-3 py-1.5 text-xs">Add</button>
                    </div>
                    <p class="text-[11px] text-slate-500 searchable-select-empty hidden">No devices match this search.</p>
                </div>
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
            <form method="POST" action="{{ route('admin.groups.policies.add', $group->id) }}" class="searchable-select-form search-panel-shell rounded-xl space-y-2 mb-3" data-empty-label="No policies match this search." data-placeholder-label="Assign policy version..." data-count-label="versions">
                @csrf
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Assign Policy</p>
                        <p class="text-[11px] text-slate-500">Search by policy name, slug, version, or status before adding it to this group.</p>
                    </div>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-600 searchable-select-count">
                        {{ collect($policyVersionOptions ?? [])->count() }} versions
                    </span>
                </div>
                <div class="grid gap-2">
                    <input
                        type="search"
                        class="searchable-select-input rounded border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Search policy versions..."
                        autocomplete="off"
                    />
                    <select name="policy_version_id" class="searchable-select-control w-full rounded border border-slate-300 px-2 py-1.5 text-sm" required>
                        <option value="">Assign policy version...</option>
                        @foreach(($policyVersionOptions ?? collect()) as $option)
                            <option value="{{ $option->id }}">
                                {{ $option->policy_name }} ({{ $option->policy_slug }}) v{{ $option->version_number }} [{{ $option->status }}]
                            </option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-slate-500 searchable-select-empty hidden">No policies match this search.</p>
                </div>
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
            <form method="POST" action="{{ route('admin.groups.packages.add', $group->id) }}" class="searchable-select-form search-panel-shell rounded-xl space-y-2 mb-3" data-empty-label="No packages match this search." data-placeholder-label="Assign package version..." data-count-label="versions">
                @csrf
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Assign Package</p>
                        <p class="text-[11px] text-slate-500">Search by package name, slug, version, or type before deploying it to this group.</p>
                    </div>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-600 searchable-select-count">
                        {{ collect($packageVersionOptions ?? [])->count() }} versions
                    </span>
                </div>
                <div class="grid gap-2">
                    <input
                        type="search"
                        class="searchable-select-input rounded border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Search package versions..."
                        autocomplete="off"
                    />
                    <select name="package_version_id" class="searchable-select-control w-full rounded border border-slate-300 px-2 py-1.5 text-sm" required>
                        <option value="">Assign package version...</option>
                        @foreach(($packageVersionOptions ?? collect()) as $option)
                            <option value="{{ $option->id }}">
                                {{ $option->package_name }} ({{ $option->package_slug }}) v{{ $option->version }} [{{ $option->package_type }}]
                            </option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-slate-500 searchable-select-empty hidden">No packages match this search.</p>
                </div>
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
                    @php
                        $summary = is_array($pkg->run_summary ?? null)
                            ? $pkg->run_summary
                            : ['pending' => 0, 'running' => 0, 'acked' => 0, 'success' => 0, 'failed' => 0];
                    @endphp
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

    <script>
        (function () {
            document.querySelectorAll('.searchable-select-form').forEach(function (form) {
                const searchInput = form.querySelector('.searchable-select-input');
                const select = form.querySelector('.searchable-select-control');
                const countLabel = form.querySelector('.searchable-select-count');
                const emptyState = form.querySelector('.searchable-select-empty');
                if (!searchInput || !select) {
                    return;
                }

                const options = Array.from(select.options)
                    .filter(function (option) { return option.value !== ''; })
                    .map(function (option) {
                        return {
                            value: option.value,
                            label: option.textContent || '',
                        };
                    });
                const countLabelText = (form.getAttribute('data-count-label') || 'options').trim();
                const emptyLabel = (form.getAttribute('data-empty-label') || 'No matching options').trim();
                const placeholderLabel = (form.getAttribute('data-placeholder-label') || 'Select option...').trim();

                const renderOptions = function () {
                    const query = searchInput.value.trim().toLowerCase();
                    const previousValue = select.value;
                    const matches = options.filter(function (item) {
                        return query === '' || item.label.toLowerCase().includes(query);
                    });

                    select.innerHTML = '';
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = matches.length > 0 ? placeholderLabel : 'No matching options';
                    select.appendChild(placeholder);

                    matches.forEach(function (item) {
                        const option = document.createElement('option');
                        option.value = item.value;
                        option.textContent = item.label;
                        if (item.value === previousValue) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });

                    if (!matches.some(function (item) { return item.value === previousValue; })) {
                        select.value = '';
                    }

                    if (countLabel) {
                        countLabel.textContent = query === ''
                            ? matches.length + ' ' + countLabelText
                            : matches.length + ' match' + (matches.length === 1 ? '' : 'es');
                    }
                    if (emptyState) {
                        emptyState.classList.toggle('hidden', matches.length > 0);
                        emptyState.textContent = emptyLabel;
                    }
                };

                searchInput.addEventListener('input', renderOptions);
                renderOptions();
            });
        })();
    </script>
</x-admin-layout>
