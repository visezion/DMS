<x-admin-layout title="Hardware Inventory" heading="Hardware Inventory">
    @php
        $fmtBytes = function (int $bytes): string {
            if ($bytes <= 0) {
                return '-';
            }
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $value = $bytes;
            $index = 0;
            while ($value >= 1024 && $index < count($units) - 1) {
                $value /= 1024;
                $index++;
            }
            return number_format($value, $value >= 10 || $index === 0 ? 0 : 1).' '.$units[$index];
        };
    @endphp

    @include('admin.assets.partials.nav')

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Hardware Tracking</p>
                <h2 class="mt-1 text-xl font-semibold text-slate-900">CPU, RAM, and storage inventory</h2>
                <p class="mt-1 text-sm text-slate-600">Use this view to monitor endpoint hardware posture and spot recent changes in reported hardware profiles.</p>
            </div>
            <form method="GET" action="{{ route('admin.assets.hardware') }}" class="flex items-center gap-2">
                <input name="q" value="{{ $searchQuery }}" placeholder="Search host, serial, OS" class="w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <button class="rounded-lg bg-skyline px-3 py-2 text-xs font-medium text-white">Search</button>
            </form>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Client</th>
                        <th class="px-3 py-2">CPU</th>
                        <th class="px-3 py-2">Memory</th>
                        <th class="px-3 py-2">Storage</th>
                        <th class="px-3 py-2">Snapshot</th>
                        <th class="px-3 py-2">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $device)
                        <tr class="border-t border-slate-200">
                            <td class="px-3 py-2 align-top">
                                <p class="font-medium text-slate-900">{{ $device->hostname }}</p>
                                <p class="text-xs text-slate-500">{{ $device->os_name }} {{ $device->os_version }}</p>
                                <p class="text-xs text-slate-500">SN: {{ $device->serial_number ?: 'n/a' }}</p>
                                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $device->status === 'online' ? 'bg-emerald-100 text-emerald-700' : ($device->status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700') }}">{{ $device->status }}</span>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <p class="text-slate-900">{{ $device->cpu_model !== '' ? $device->cpu_model : 'Not reported' }}</p>
                                <p class="text-xs text-slate-500">{{ $device->cpu_cores > 0 ? $device->cpu_cores.' cores' : 'Core count unknown' }}</p>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <p class="text-slate-900">{{ $fmtBytes($device->memory_total_bytes) }}</p>
                                <p class="text-xs text-slate-500">Total physical memory</p>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <p class="text-slate-900">{{ $device->disk_count }} disk(s)</p>
                                <p class="text-xs text-slate-500">{{ $fmtBytes($device->disk_total_bytes) }} total capacity</p>
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if($device->has_inventory)
                                    <p class="text-slate-900">{{ $device->inventory_updated_at !== '' ? $device->inventory_updated_at : 'reported' }}</p>
                                    <p class="text-xs text-slate-500">Last inventory sync</p>
                                @else
                                    <p class="text-slate-500">No hardware snapshot</p>
                                @endif
                                <p class="mt-1 text-xs text-slate-500">Last seen {{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'never' }}</p>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <a href="{{ route('admin.devices.show', $device->id) }}" class="inline-flex rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Device</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-sm text-slate-500">No devices found for this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($devices, 'links'))
            <div class="mt-4">{{ $devices->links() }}</div>
        @endif
    </section>
</x-admin-layout>
