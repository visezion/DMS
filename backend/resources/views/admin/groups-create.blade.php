<x-admin-layout title="Create Group" heading="Create Group">
    @php
        $totalDevices = (int) collect($devices ?? [])->count();
    @endphp
<div class="group-create-simple space-y-4">
        <section class="panel rounded-3xl p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Create Group</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">New Device Group</h2>
                    <p class="mt-1 text-sm text-slate-600">Create the group now. You can always add more members, policies, and packages later.</p>
                </div>
                <a href="{{ route('admin.groups') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700">Back to Groups</a>
            </div>
        </section>

        <section class="panel rounded-3xl p-6">
            <form method="POST" action="{{ route('admin.groups.create') }}" class="space-y-5">
                @csrf

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Group name</label>
                    <input name="name" placeholder="Finance Workstations - North" required class="field" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
                    <textarea name="description" placeholder="Short note about what this group is for." class="field min-h-28"></textarea>
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <label class="block text-sm font-medium text-slate-700">Members</label>
                        <span class="text-xs text-slate-500">{{ $totalDevices }} devices available</span>
                    </div>
                    <input id="group-device-search" type="text" placeholder="Search devices" class="field mb-2" />
                    <select id="group-device-select" name="device_ids[]" multiple class="field min-h-72">
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}">{{ $device->hostname }}</option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-slate-500">Optional. Hold Ctrl/Cmd to select multiple devices.</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                    Keep it simple: choose a clear name, add a short description, and select members only if you already know them.
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button class="primary-btn rounded-xl px-4 py-2.5 text-sm font-medium">Create Group</button>
                    <a href="{{ route('admin.groups') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700">Cancel</a>
                </div>
            </form>
        </section>
    </div>

    <script>
        (function () {
            const deviceSearch = document.getElementById('group-device-search');
            const deviceSelect = document.getElementById('group-device-select');
            if (!deviceSearch || !deviceSelect) return;

            const deviceOptions = Array.from(deviceSelect.options);
            deviceSearch.addEventListener('input', function () {
                const q = deviceSearch.value.trim().toLowerCase();
                deviceOptions.forEach(function (option) {
                    const show = q === '' || option.text.toLowerCase().includes(q);
                    option.hidden = !show;
                });
            });
        })();
    </script>
</x-admin-layout>
