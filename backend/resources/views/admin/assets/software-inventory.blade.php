<x-admin-layout title="Software Inventory" heading="Software Inventory">
    @include('admin.assets.partials.nav')

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Software Tracking</p>
                <h2 class="mt-1 text-xl font-semibold text-slate-900">Installed software visibility</h2>
                <p class="mt-1 text-sm text-slate-600">Detect installed software, identify unmanaged software, and use installation counts for manual license awareness.</p>
            </div>
            <form method="GET" action="{{ route('admin.assets.software') }}" class="flex items-center gap-2">
                <input name="q" value="{{ $searchQuery }}" placeholder="Search software, publisher, version" class="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <button class="rounded-lg bg-skyline px-3 py-2 text-xs font-medium text-white">Search</button>
            </form>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Software Titles</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['software_titles_total'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Total Installations</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['software_installations_total'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Reporting Devices</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['software_devices_total'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Unmanaged Titles</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ $metrics['software_unauthorized_total'] }}</p>
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-12">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 xl:col-span-8">
            <h3 class="text-base font-semibold text-slate-900">Software Installation Counts</h3>
            <p class="mt-1 text-xs text-slate-500">Use install counts to compare against purchased licenses (manual process).</p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Software</th>
                            <th class="px-3 py-2">Publisher</th>
                            <th class="px-3 py-2">Installations</th>
                            <th class="px-3 py-2">Devices</th>
                            <th class="px-3 py-2">Catalog</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($softwareRows as $software)
                            <tr class="border-t border-slate-200">
                                <td class="px-3 py-2 align-top">
                                    <p class="font-medium text-slate-900">{{ $software['name'] }}</p>
                                    <p class="text-xs text-slate-500">{{ !empty($software['versions']) ? implode(', ', array_slice($software['versions'], 0, 3)) : 'Version unknown' }}</p>
                                </td>
                                <td class="px-3 py-2 text-slate-700">{{ $software['publisher'] !== '' ? $software['publisher'] : '-' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ $software['installations'] }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ $software['devices'] }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-1 text-xs {{ $software['managed'] ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $software['managed'] ? 'Managed' : 'Unmanaged' }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">No software inventory rows found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <aside class="rounded-2xl border border-slate-200 bg-white p-4 xl:col-span-4">
            <h3 class="text-base font-semibold text-slate-900">Unmanaged Software Signals</h3>
            <div class="mt-3 space-y-2 max-h-[26rem] overflow-auto">
                @forelse($unauthorizedRows as $row)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                        <p class="font-medium text-amber-900">{{ $row['name'] }}</p>
                        <p class="text-xs text-amber-800">Installations {{ $row['installations'] }} | devices {{ $row['devices'] }}</p>
                        <p class="text-xs text-amber-800">{{ $row['publisher'] !== '' ? $row['publisher'] : 'Publisher unknown' }}</p>
                    </div>
                @empty
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">No unmanaged software detected for current filter.</div>
                @endforelse
            </div>
        </aside>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <h3 class="text-base font-semibold text-slate-900">Clients with Highest Software Footprint</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Client</th>
                        <th class="px-3 py-2">Installed Software</th>
                        <th class="px-3 py-2">Inventory Snapshot</th>
                        <th class="px-3 py-2">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devicesBySoftwareCount as $client)
                        <tr class="border-t border-slate-200">
                            <td class="px-3 py-2">
                                <p class="font-medium text-slate-900">{{ $client->hostname }}</p>
                                <p class="text-xs text-slate-500">Last seen {{ $client->last_seen_at ? $client->last_seen_at->diffForHumans() : 'never' }}</p>
                            </td>
                            <td class="px-3 py-2 text-slate-700">{{ $client->software_count }}</td>
                            <td class="px-3 py-2 text-slate-700">{{ $client->inventory_updated_at !== '' ? $client->inventory_updated_at : 'n/a' }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('admin.devices.show', $client->id) }}" class="inline-flex rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Device</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6 text-center text-sm text-slate-500">No device software inventory available yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-admin-layout>
