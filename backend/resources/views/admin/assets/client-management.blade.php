<x-admin-layout title="Client Management" heading="Client Management">
    @include('admin.assets.partials.nav')

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Client Operations</p>
                <h2 class="mt-1 text-xl font-semibold text-slate-900">Track activity and link assets to deployment</h2>
                <p class="mt-1 text-sm text-slate-600">Manage endpoint state, review deployment touchpoints, and jump directly into package/job workflows.</p>
            </div>
            <form method="GET" action="{{ route('admin.assets.clients') }}" class="flex items-center gap-2">
                <input name="q" value="{{ $searchQuery }}" placeholder="Search host, serial, OS" class="w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <button class="rounded-lg bg-skyline px-3 py-2 text-xs font-medium text-white">Search</button>
            </form>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Matched Clients</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['matched_total'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Active</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ $metrics['active_total'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Inactive</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ $metrics['inactive_total'] }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Client</th>
                        <th class="px-3 py-2">State</th>
                        <th class="px-3 py-2">Software</th>
                        <th class="px-3 py-2">Last Deployment</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $client)
                        <tr class="border-t border-slate-200">
                            <td class="px-3 py-2 align-top">
                                <p class="font-medium text-slate-900">{{ $client->hostname }}</p>
                                <p class="text-xs text-slate-500">{{ $client->os_name }} {{ $client->os_version }} | Agent {{ $client->agent_version }}</p>
                                <p class="text-xs text-slate-500">SN: {{ $client->serial_number ?: 'n/a' }}</p>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <span class="rounded-full px-2 py-1 text-xs {{ $client->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $client->is_active ? 'Active' : 'Inactive' }}</span>
                                <p class="mt-1 text-xs text-slate-500">Last seen {{ $client->last_seen_at ? $client->last_seen_at->diffForHumans() : 'never' }}</p>
                                <p class="text-xs text-slate-500">Inventory {{ $client->inventory_updated_at !== '' ? $client->inventory_updated_at : 'n/a' }}</p>
                            </td>
                            <td class="px-3 py-2 align-top text-slate-700">
                                <p>{{ $client->software_count }} title(s)</p>
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if($client->last_deployment_type !== '')
                                    <p class="text-slate-900">{{ $client->last_deployment_type }}</p>
                                    <p class="text-xs text-slate-500">Status {{ $client->last_deployment_status !== '' ? $client->last_deployment_status : 'unknown' }}</p>
                                    <p class="text-xs text-slate-500">{{ $client->last_deployment_at ? \Carbon\Carbon::parse($client->last_deployment_at)->diffForHumans() : 'n/a' }}</p>
                                @else
                                    <p class="text-slate-500">No deployment activity</p>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="flex flex-wrap gap-1.5">
                                    <a href="{{ route('admin.devices.show', $client->id) }}" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50">Device</a>
                                    <a href="{{ route('admin.packages') }}" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50">Assign Software</a>
                                    <a href="{{ route('admin.jobs') }}" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50">Deploy Updates</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">No clients found for this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($clients, 'links'))
            <div class="mt-4">{{ $clients->links() }}</div>
        @endif
    </section>
</x-admin-layout>
