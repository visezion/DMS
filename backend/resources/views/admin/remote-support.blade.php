<x-admin-layout title="Remote Support" heading="Remote Support">
    @php
        $pageDevices = $devices->getCollection();
        $isCurrentlyOnline = function ($device) {
            return $device->last_seen_at && $device->last_seen_at->gt(now()->subMinutes(2));
        };
        $effectiveStatus = function ($device) use ($isCurrentlyOnline) {
            if (in_array($device->status, ['pending', 'quarantined'], true)) {
                return $device->status;
            }

            return $isCurrentlyOnline($device) ? 'online' : 'offline';
        };
        $readyCount = $pageDevices->filter(fn ($device) => $effectiveStatus($device) === 'online')->count();
    @endphp

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Remote Support Queue</p>
                <h3 class="mt-1 text-xl font-semibold text-slate-900">Select a device to connect</h3>
                <p class="mt-1 text-sm text-slate-500">Choose a target endpoint, then open the device console.</p>
            </div>
            <a href="{{ route('admin.devices') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Devices</a>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Devices In View</p>
                <p class="text-2xl font-semibold text-slate-900">{{ $devices->count() }}</p>
            </article>
            <article class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-xs text-emerald-700">Connect Ready (page)</p>
                <p class="text-2xl font-semibold text-emerald-700">{{ $readyCount }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Total Fleet</p>
                <p class="text-2xl font-semibold text-slate-900">{{ $devices->total() }}</p>
            </article>
        </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-3 grid gap-2 lg:grid-cols-[auto,1fr,auto] lg:items-center">
            <h3 class="font-semibold">Device Targets</h3>
            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('admin.remote-support') }}" class="flex w-full items-center gap-2">
                    <input
                        type="text"
                        name="q"
                        value="{{ $searchQuery ?? '' }}"
                        placeholder="Search hostname, mesh ID, OS, or device ID..."
                        class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs"
                    />
                    <button class="rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white">Search</button>
                </form>
                @if(!empty($searchQuery))
                    <a href="{{ route('admin.remote-support') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700">Clear</a>
                @endif
            </div>
            <p class="text-xs text-slate-500 lg:text-right">Page {{ $devices->currentPage() }} of {{ $devices->lastPage() }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="pb-2">Device</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Mesh ID</th>
                        <th class="pb-2">Last Check-in</th>
                        <th class="pb-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($devices as $device)
                        @php
                            $currentStatus = $effectiveStatus($device);
                            $statusClass = match($currentStatus) {
                                'online' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                'quarantined' => 'bg-rose-100 text-rose-700 border-rose-200',
                                'pending' => 'bg-amber-100 text-amber-700 border-amber-200',
                                default => 'bg-slate-100 text-slate-700 border-slate-200',
                            };
                            $canConnect = $currentStatus === 'online';
                        @endphp
                        <tr>
                            <td class="py-2 pr-3">
                                <p class="font-medium text-slate-900">{{ $device->hostname }}</p>
                                <p class="font-mono text-[11px] text-slate-500">{{ $device->id }}</p>
                                <p class="text-[11px] text-slate-500">{{ $device->os_name }} {{ $device->os_version }}</p>
                            </td>
                            <td class="py-2 pr-3">
                                <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $statusClass }}">{{ $currentStatus }}</span>
                            </td>
                            <td class="py-2 pr-3">
                                <span class="font-mono text-xs text-slate-700">{{ $device->meshcentral_device_id ?: '-' }}</span>
                            </td>
                            <td class="py-2 pr-3 text-slate-600">{{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'never' }}</td>
                            <td class="py-2 text-right">
                                @if($canConnect)
                                    <a href="{{ route('admin.remote-support.show', $device->id) }}" class="inline-flex rounded-lg bg-skyline px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-600">Connect</a>
                                @else
                                    <span class="inline-flex rounded-lg border border-slate-300 bg-slate-100 px-3 py-1.5 text-xs text-slate-500">Unavailable</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-10 text-center">
                                <p class="text-sm font-medium text-slate-700">No devices found</p>
                                <p class="mt-1 text-xs text-slate-500">Try a different search or enroll a new endpoint.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5">{{ $devices->links() }}</div>
    </section>
</x-admin-layout>
