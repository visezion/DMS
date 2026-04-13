<x-admin-layout title="Asset Management" heading="Asset Management">
    @php
        $freshnessThreshold = (int) data_get($intelligenceFreshness ?? [], 'stale_after_minutes', 120);
        $healthFreshnessAge = (string) data_get($intelligenceFreshness ?? [], 'health_latest.age_human', 'No data yet');
        $riskFreshnessAge = (string) data_get($intelligenceFreshness ?? [], 'risk_latest.age_human', 'No data yet');
        $findingFreshnessAge = (string) data_get($intelligenceFreshness ?? [], 'finding_latest.age_human', 'No active findings');
    @endphp
    @include('admin.assets.partials.nav')

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Asset Control</p>
        <h2 class="mt-1 text-xl font-semibold text-slate-900">What You Can Do with Assets</h2>
        <p class="mt-2 text-sm text-slate-600">Track devices, monitor hardware posture, inspect software inventory, and link assets directly to deployment workflows.</p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Tracked Devices</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['devices_total'] }}</p>
                <p class="text-xs text-slate-500">Active {{ $metrics['devices_active'] }} | inactive {{ $metrics['devices_inactive'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Hardware Snapshots</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['hardware_snapshots'] }}</p>
                <p class="text-xs text-slate-500">Updated in last 7 days: {{ $metrics['hardware_snapshots_recent'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Software Installations</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['software_installations_total'] }}</p>
                <p class="text-xs text-slate-500">Titles {{ $metrics['software_titles_total'] }} | unmanaged {{ $metrics['software_unauthorized_total'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Deployment Linked Assets</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $metrics['deployment_linked_assets'] }}</p>
                <p class="text-xs text-slate-500">Assets with package/update activity</p>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Endpoint Intelligence Wire</p>
                <h3 class="mt-1 text-base font-semibold text-slate-900">Freshness context for asset decisions</h3>
                <p class="mt-1 text-sm text-slate-600">Stale threshold {{ $freshnessThreshold }} minutes. Use this before acting on software or compliance outliers.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.intelligence.health') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Health</a>
                <a href="{{ route('admin.intelligence.risk') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Risk</a>
                <a href="{{ route('admin.intelligence.tuning') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Tuning</a>
            </div>
        </div>
        <div class="mt-3 grid gap-3 md:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Health Data Age</p>
                <p class="mt-1 text-base font-semibold text-slate-900">{{ $healthFreshnessAge }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Risk Data Age</p>
                <p class="mt-1 text-base font-semibold text-slate-900">{{ $riskFreshnessAge }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Findings Data Age</p>
                <p class="mt-1 text-base font-semibold text-slate-900">{{ $findingFreshnessAge }}</p>
            </article>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">A. Track Devices</h3>
            <p class="mt-1 text-sm text-slate-600">See all registered machines and quickly identify active and inactive systems.</p>
            <a href="{{ route('admin.assets.clients') }}" class="mt-3 inline-flex rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Client Management</a>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">B. Monitor Hardware Changes</h3>
            <p class="mt-1 text-sm text-slate-600">Review CPU, memory, and disk snapshots to spot RAM upgrades, disk changes, and platform drift.</p>
            <a href="{{ route('admin.assets.hardware') }}" class="mt-3 inline-flex rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Hardware Inventory</a>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">C. Software Tracking</h3>
            <p class="mt-1 text-sm text-slate-600">Detect installed software across assets and flag software outside managed package catalog coverage.</p>
            <a href="{{ route('admin.assets.software') }}" class="mt-3 inline-flex rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Open Software Inventory</a>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <h3 class="text-base font-semibold text-slate-900">D. License Awareness (Basic)</h3>
            <p class="mt-1 text-sm text-slate-600">Use installation counts per software title for manual license comparison and audit readiness.</p>
            <a href="{{ route('admin.assets.software') }}" class="mt-3 inline-flex rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Review Install Counts</a>
        </article>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <h3 class="text-base font-semibold text-slate-900">E. Link Assets to Deployment</h3>
        <p class="mt-1 text-sm text-slate-600">Assign software to specific assets, deploy updates, and control configurations through existing deployment workflows.</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="{{ route('admin.packages') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Software Packages</a>
            <a href="{{ route('admin.jobs') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Jobs & Deployment Queue</a>
            <a href="{{ route('admin.devices') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Devices</a>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Top Software</th>
                        <th class="px-3 py-2">Installations</th>
                        <th class="px-3 py-2">Devices</th>
                        <th class="px-3 py-2">Catalog Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topSoftware as $software)
                        <tr class="border-t border-slate-200">
                            <td class="px-3 py-2">
                                <p class="font-medium text-slate-900">{{ $software['name'] }}</p>
                                <p class="text-xs text-slate-500">{{ $software['publisher'] !== '' ? $software['publisher'] : 'Publisher unknown' }}</p>
                            </td>
                            <td class="px-3 py-2 text-slate-700">{{ $software['installations'] }}</td>
                            <td class="px-3 py-2 text-slate-700">{{ $software['devices'] }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-1 text-xs {{ $software['managed'] ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                    {{ $software['managed'] ? 'Managed' : 'Unmanaged' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6 text-center text-sm text-slate-500">No software inventory reported yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-admin-layout>
