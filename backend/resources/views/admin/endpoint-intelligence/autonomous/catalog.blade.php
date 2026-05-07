<x-admin-layout title="Autonomous Action Catalog" heading="Endpoint Intelligence">
    @include('admin.endpoint-intelligence.autonomous.partials.subnav', [
        'title' => 'Action Catalog',
        'description' => 'Review safety class, reversibility, target support, and execution strategy for autonomous actions.',
    ])

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Action</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Safety</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Approval</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Targets</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Execution</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @foreach($catalog as $entry)
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-900">{{ $entry['display_name'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $entry['key'] }}</p>
                                <p class="mt-2 text-xs text-slate-600">{{ $entry['description'] }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ $entry['safety_class'] }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $entry['recommended_approval_mode'] }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ implode(', ', $entry['supported_target_types'] ?? []) }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $entry['execution_strategy'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-admin-layout>
