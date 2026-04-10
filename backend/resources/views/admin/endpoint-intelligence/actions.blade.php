<x-admin-layout title="Action History" heading="Action History">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="mt-5 grid gap-5 xl:grid-cols-[1.1fr,0.9fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Execution Results</p>
            <div class="mt-4 space-y-3">
                @foreach ($results as $result)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-slate-900">Action {{ $result->action_id }}</p>
                                <p class="text-xs text-slate-500">Job {{ $result->job_id }} | status {{ $result->status }} | exit {{ $result->exit_code ?? 'n/a' }}</p>
                            </div>
                            <span class="rounded-full bg-slate-200 px-2 py-1 text-xs text-slate-700">{{ optional($result->finished_at)->diffForHumans() }}</span>
                        </div>
                        @if(!empty($result->evidence))
                            <pre class="mt-2 whitespace-pre-wrap break-all text-xs text-slate-600">{{ json_encode($result->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                @endforeach
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Rollback Records</p>
            <div class="mt-4 space-y-3">
                @forelse ($rollbacks as $rollback)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-slate-900">{{ $rollback->rollback_action_type }}</p>
                                <p class="text-xs text-slate-500">Result {{ $rollback->action_result_id }} | {{ $rollback->status }}</p>
                            </div>
                            <span class="text-xs text-slate-500">{{ optional($rollback->created_at)->diffForHumans() }}</span>
                        </div>
                        <pre class="mt-2 whitespace-pre-wrap break-all text-xs text-slate-600">{{ json_encode($rollback->rollback_args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No rollback records yet.</div>
                @endforelse
            </div>
        </article>
    </section>
    </div>
</x-admin-layout>
