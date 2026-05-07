<x-admin-layout title="Autonomous Decisions" heading="Endpoint Intelligence">
    @include('admin.endpoint-intelligence.autonomous.partials.subnav', [
        'title' => 'Autonomous Decisions Dashboard',
        'description' => 'Review pending approvals, auto-executed actions, failures, and rollback-ready responses.',
    ])

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-3 md:grid-cols-4">
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-amber-700">Pending Approval</p>
                <p class="mt-2 text-2xl font-semibold text-amber-800">{{ $metrics['pending_approval'] }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-emerald-700">Auto Executed 24h</p>
                <p class="mt-2 text-2xl font-semibold text-emerald-800">{{ $metrics['auto_executed_24h'] }}</p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-rose-700">Failed 24h</p>
                <p class="mt-2 text-2xl font-semibold text-rose-800">{{ $metrics['failed_24h'] }}</p>
            </div>
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-sky-700">Rolled Back 7d</p>
                <p class="mt-2 text-2xl font-semibold text-sky-800">{{ $metrics['rolled_back_7d'] }}</p>
            </div>
        </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Trigger</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Action</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Confidence</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Mode</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wide text-slate-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($decisions as $decision)
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.intelligence.autonomous.decisions.show', $decision->id) }}" class="font-medium text-slate-900 hover:text-skyline">
                                    {{ $decision->trigger_source }}
                                </a>
                                <p class="mt-1 text-xs text-slate-500">{{ data_get($decision->input_context, 'device.hostname', $decision->device_id ?: 'fleet') }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ $decision->recommended_action ?: 'manual_review' }}</td>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ number_format((float) $decision->confidence_score, 1) }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $decision->decision_mode)) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-700">{{ $decision->status }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    @if($decision->status === 'pending_approval')
                                        <form method="POST" action="{{ route('admin.intelligence.autonomous.decisions.approve', $decision->id) }}">
                                            @csrf
                                            <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.intelligence.autonomous.decisions.reject', $decision->id) }}">
                                            @csrf
                                            <button class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-medium text-rose-700">Reject</button>
                                        </form>
                                    @endif
                                    @if(in_array($decision->status, ['approved', 'generated'], true))
                                        <form method="POST" action="{{ route('admin.intelligence.autonomous.decisions.execute', $decision->id) }}">
                                            @csrf
                                            <button class="rounded-lg border border-sky-300 px-3 py-1.5 text-xs font-medium text-sky-700">Execute</button>
                                        </form>
                                    @endif
                                    @if(!empty($decision->execution_reference) && in_array($decision->status, ['executed', 'failed'], true))
                                        <form method="POST" action="{{ route('admin.intelligence.autonomous.decisions.rollback', $decision->id) }}">
                                            @csrf
                                            <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700">Rollback</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">No autonomous decisions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $decisions->links() }}
        </div>
    </section>
</x-admin-layout>
