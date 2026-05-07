<x-admin-layout title="Autonomous Decision Detail" heading="Endpoint Intelligence">
    @include('admin.endpoint-intelligence.autonomous.partials.subnav', [
        'title' => 'Decision Detail',
        'description' => 'Rationale, confidence evidence, and execution history for a single autonomous decision.',
    ])

    <section class="mt-5 grid gap-5 xl:grid-cols-[0.95fr,1.05fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">{{ $decision->recommended_action ?: 'manual_review' }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ $decision->trigger_source }} · {{ $decision->status }}</p>
                </div>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm text-slate-700">{{ number_format((float) $decision->confidence_score, 1) }} confidence</span>
            </div>
            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Rationale</p>
                <p class="mt-2 text-sm leading-6 text-slate-700">{{ $decision->rationale }}</p>
            </div>
            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Execution Context</p>
                <pre class="mt-2 overflow-x-auto text-xs text-slate-700">{{ json_encode($decision->input_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Confidence Evidence</h3>
            <div class="mt-4 space-y-3">
                @forelse($decision->confidenceEvidence as $evidence)
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-slate-900">{{ $evidence->factor_name }}</p>
                            <span class="text-xs text-slate-500">weight {{ number_format((float) $evidence->factor_weight, 2) }} · value {{ number_format((float) $evidence->factor_value, 1) }}</span>
                        </div>
                        @if(!empty($evidence->notes))
                            <pre class="mt-2 overflow-x-auto text-xs text-slate-600">{{ json_encode($evidence->notes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                @empty
                    <p class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">No evidence factors recorded.</p>
                @endforelse
            </div>

            <h3 class="mt-6 text-lg font-semibold text-slate-900">Execution Results</h3>
            <div class="mt-4 space-y-3">
                @forelse($decision->executionResults as $result)
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-slate-900">{{ $result->action_name }}</p>
                            <span class="text-xs text-slate-500">{{ $result->execution_status }}</span>
                        </div>
                        @if(!empty($result->command_payload))
                            <pre class="mt-2 overflow-x-auto text-xs text-slate-600">{{ json_encode($result->command_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                @empty
                    <p class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">No execution records yet.</p>
                @endforelse
            </div>
        </article>
    </section>
</x-admin-layout>
