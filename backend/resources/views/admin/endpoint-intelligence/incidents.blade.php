<x-admin-layout title="Correlated Incident Explorer" heading="Correlated Incident Explorer">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Incidents</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">Merged telemetry narratives</h3>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="pb-2">Title</th>
                        <th class="pb-2">Severity</th>
                        <th class="pb-2">Confidence</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Timeline</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($incidents as $incident)
                        <tr>
                            <td class="py-2 pr-3">
                                <div class="font-medium text-slate-900">{{ $incident->title }}</div>
                                <div class="text-xs text-slate-500">{{ $incident->summary }}</div>
                            </td>
                            <td class="py-2 pr-3 capitalize">{{ $incident->severity }}</td>
                            <td class="py-2 pr-3">{{ $incident->confidence }}</td>
                            <td class="py-2 pr-3 capitalize">{{ $incident->status }}</td>
                            <td class="py-2"><a class="ei-link" href="{{ route('admin.intelligence.incidents.timeline', $incident->id) }}">Open timeline</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-slate-500">No correlated incidents yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    </div>
</x-admin-layout>
