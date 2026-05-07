<x-admin-layout title="Action History" heading="Action History">
    @php
        $completedActions = (int) ($metrics['completed_actions'] ?? 0);
        $failedActions = (int) ($metrics['failed_actions'] ?? 0);
        $rollbackRecords = (int) ($metrics['rollback_records'] ?? 0);
        $successResults = (int) ($metrics['success_results'] ?? 0);
        $heroActions = [
            ['href' => route('admin.intelligence.remediation'), 'class' => 'ei-button-primary rounded-xl px-4 py-3 text-sm font-medium text-white', 'label' => 'Open Remediation'],
            ['href' => route('admin.intelligence.approvals'), 'label' => 'Open Approvals'],
            ['href' => route('admin.intelligence.risk'), 'label' => 'Open Risk'],
            ['href' => route('admin.intelligence.incidents'), 'label' => 'Open Incidents'],
        ];
        $summaryCards = [
            ['class' => 'rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-emerald-700', 'value_class' => 'mt-2 text-3xl font-semibold text-emerald-900', 'description_class' => 'mt-1 text-sm text-emerald-800', 'label' => 'Completed Actions', 'value' => $completedActions, 'description' => 'Actions marked completed in the remediation flow.'],
            ['class' => 'rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-rose-700', 'value_class' => 'mt-2 text-3xl font-semibold text-rose-900', 'description_class' => 'mt-1 text-sm text-rose-800', 'label' => 'Failed Actions', 'value' => $failedActions, 'description' => 'Failures that may need manual review or rollback.'],
            ['label' => 'Rollback Records', 'value' => $rollbackRecords, 'description' => 'Stored rollback entries for reversible actions.'],
            ['class' => 'rounded-2xl border border-blue-200 bg-blue-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-blue-700', 'value_class' => 'mt-2 text-3xl font-semibold text-blue-900', 'description_class' => 'mt-1 text-sm text-blue-800', 'label' => 'Successful Results', 'value' => $successResults, 'description' => 'Recorded results that completed successfully.'],
        ];
    @endphp

    <div class="endpoint-intelligence-shell space-y-5">
        @include('admin.endpoint-intelligence.partials.smart-nav')
        @include('admin.endpoint-intelligence.partials.overview-hero', [
            'eyebrow' => 'Action History',
            'title' => 'Review what ran, what failed, and what was rolled back',
            'description' => 'This page is the audit-style history for remediation outcomes. Use it to confirm results, spot failures, and inspect rollback records.',
            'actions' => $heroActions,
        ])

        @include('admin.endpoint-intelligence.partials.overview-stats', [
            'cards' => $summaryCards,
        ])

        <section class="grid gap-5 xl:grid-cols-[1.1fr,0.9fr]">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Execution Results</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Latest action outcomes</h3>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($results as $result)
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-slate-900">Action {{ $result->action_id }}</p>
                                        <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs capitalize text-slate-600">
                                            {{ $result->status }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Job {{ $result->job_id ?? 'n/a' }} | Exit {{ $result->exit_code ?? 'n/a' }} | Finished {{ optional($result->finished_at)->diffForHumans() }}
                                    </p>
                                </div>
                            </div>

                            @if (!empty($result->evidence))
                                <details class="mt-3 rounded-xl border border-slate-200 bg-white p-3">
                                    <summary class="cursor-pointer text-xs font-medium text-slate-700">View evidence</summary>
                                    <pre class="mt-3 whitespace-pre-wrap break-all text-xs text-slate-600">{{ json_encode($result->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                            No execution results yet.
                        </div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Rollback Records</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Reversal history</h3>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($rollbacks as $rollback)
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">{{ $rollback->rollback_action_type }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Result {{ $rollback->action_result_id }} | {{ $rollback->status }} | {{ optional($rollback->created_at)->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                            <pre class="mt-3 whitespace-pre-wrap break-all text-xs text-slate-600">{{ json_encode($rollback->rollback_args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                            No rollback records yet.
                        </div>
                    @endforelse
                </div>
            </article>
        </section>
    </div>
</x-admin-layout>
