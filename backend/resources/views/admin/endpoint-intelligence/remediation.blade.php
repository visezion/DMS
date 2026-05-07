<x-admin-layout title="Remediation Queue" heading="Remediation Queue">
    @php
        $plansTotal = (int) ($metrics['plans_total'] ?? 0);
        $pendingApproval = (int) ($metrics['pending_approval'] ?? 0);
        $executing = (int) ($metrics['executing'] ?? 0);
        $rollbacksAvailable = (int) ($metrics['rollbacks_available'] ?? 0);
        $heroBadges = [
            ['class' => 'ei-chip ei-chip-primary', 'label' => 'Plans: '.$plansTotal],
            ['class' => 'ei-chip', 'label' => 'Pending approval: '.$pendingApproval],
            ['class' => 'ei-chip', 'label' => 'Executing: '.$executing],
        ];
        $heroActions = [
            ['href' => route('admin.intelligence.approvals'), 'label' => 'Open Approvals'],
            ['href' => route('admin.intelligence.actions'), 'label' => 'Open Action History'],
            ['href' => route('admin.intelligence.risk'), 'label' => 'Open Risk'],
            ['href' => route('admin.intelligence.autonomy'), 'label' => 'Open Autonomy'],
        ];
        $summaryCards = [
            ['label' => 'Plans Total', 'value' => $plansTotal, 'description' => 'All remediation plans currently tracked.'],
            ['class' => 'rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-amber-700', 'value_class' => 'mt-2 text-3xl font-semibold text-amber-900', 'description_class' => 'mt-1 text-sm text-amber-800', 'label' => 'Pending Approval', 'value' => $pendingApproval, 'description' => 'Plans waiting for a human decision.'],
            ['class' => 'rounded-2xl border border-blue-200 bg-blue-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-blue-700', 'value_class' => 'mt-2 text-3xl font-semibold text-blue-900', 'description_class' => 'mt-1 text-sm text-blue-800', 'label' => 'Executing', 'value' => $executing, 'description' => 'Plans currently being carried out.'],
            ['class' => 'rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-emerald-700', 'value_class' => 'mt-2 text-3xl font-semibold text-emerald-900', 'description_class' => 'mt-1 text-sm text-emerald-800', 'label' => 'Rollbacks Available', 'value' => $rollbacksAvailable, 'description' => 'Actions that can be reversed if needed.'],
        ];
        $statusTone = static function (?string $status): string {
            return match (strtolower((string) $status)) {
                'pending_approval' => 'border-amber-300 bg-amber-50 text-amber-700',
                'executing', 'running', 'queued' => 'border-blue-300 bg-blue-50 text-blue-700',
                'approved', 'validated', 'ready' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
                'failed', 'rejected' => 'border-rose-300 bg-rose-50 text-rose-700',
                default => 'border-slate-300 bg-slate-50 text-slate-700',
            };
        };
        $riskTone = static function (?string $risk): string {
            return match (strtolower((string) $risk)) {
                'critical', 'high' => 'border-rose-300 bg-rose-50 text-rose-700',
                'medium', 'moderate' => 'border-amber-300 bg-amber-50 text-amber-700',
                default => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            };
        };
        $planGuidance = static function (?string $status): string {
            return match (strtolower((string) $status)) {
                'pending_approval' => 'Approve this plan before execution can begin.',
                'executing', 'running' => 'Execution is in progress. Monitor result updates before making more changes.',
                'approved', 'validated', 'ready' => 'This plan is ready for execution when you confirm the target and action list.',
                'failed' => 'Review the related results and consider rollback or manual intervention.',
                default => 'Review the action list, then validate or execute as needed.',
            };
        };
        $nextStep = static function (?string $status): array {
            return match (strtolower((string) $status)) {
                'pending_approval' => [
                    'label' => 'Approve this plan',
                    'description' => 'A human decision is required before it can run.',
                ],
                'approved', 'validated', 'ready' => [
                    'label' => 'Execute when ready',
                    'description' => 'The plan has passed review and is ready to run.',
                ],
                'executing', 'running', 'queued' => [
                    'label' => 'Monitor progress',
                    'description' => 'Wait for results before retrying or changing direction.',
                ],
                'failed' => [
                    'label' => 'Review the failure',
                    'description' => 'Check the result log and decide whether rollback is needed.',
                ],
                default => [
                    'label' => 'Validate first',
                    'description' => 'Confirm scope and action safety before approving or executing.',
                ],
            };
        };
        $resultTone = static function (?string $status): string {
            return match (strtolower((string) $status)) {
                'succeeded', 'success', 'completed', 'executed' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
                'running', 'queued', 'executing' => 'border-blue-300 bg-blue-50 text-blue-700',
                'rolled_back' => 'border-amber-300 bg-amber-50 text-amber-700',
                'failed', 'error', 'rejected' => 'border-rose-300 bg-rose-50 text-rose-700',
                default => 'border-slate-300 bg-slate-50 text-slate-700',
            };
        };
    @endphp

    <div class="endpoint-intelligence-shell space-y-5">
        @include('admin.endpoint-intelligence.partials.smart-nav')
        @include('admin.endpoint-intelligence.partials.overview-hero', [
            'eyebrow' => 'Remediation',
            'title' => 'Review, approve, execute, and roll back response actions',
            'description' => 'This page is the operational queue for remediation plans. Use it to validate a plan, approve it when required, execute it safely, and monitor recent outcomes.',
            'badges' => $heroBadges,
            'actions' => $heroActions,
        ])

        @include('admin.endpoint-intelligence.partials.overview-stats', [
            'cards' => $summaryCards,
        ])

        <section class="grid gap-4 xl:grid-cols-[1.1fr,0.9fr]">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">How To Use This Page</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">One simple flow for every plan</h3>
                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">1. Review</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">Open the plan card and confirm the target, risk, and actions.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">2. Decide</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">Validate first. Approve only if the plan is waiting for human review.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">3. Act</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">Execute when ready, then confirm the result or roll back if needed.</p>
                    </div>
                </div>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">What Needs Attention</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">Operator focus</h3>
                <div class="mt-4 space-y-3 text-sm text-slate-600">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p class="font-semibold text-amber-900">{{ $pendingApproval }} plan(s) waiting for approval</p>
                        <p class="mt-1 text-amber-800">Start with these if you are the reviewer for sensitive or blocked actions.</p>
                    </div>
                    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                        <p class="font-semibold text-blue-900">{{ $executing }} plan(s) currently running</p>
                        <p class="mt-1 text-blue-800">Avoid repeated retries until the live result shows success or failure.</p>
                    </div>
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <p class="font-semibold text-emerald-900">{{ $rollbacksAvailable }} result(s) can be rolled back</p>
                        <p class="mt-1 text-emerald-800">Use rollback only when the result caused impact or the change needs to be reversed.</p>
                    </div>
                </div>
            </article>
        </section>

        <section class="grid gap-5 xl:grid-cols-[1.2fr,0.8fr]">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Plan Queue</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">Current remediation plans</h3>
                        <p class="mt-1 text-sm text-slate-500">Each card shows what the plan is, where it is in the flow, and the next safe action.</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="ei-chip px-2.5 py-1">Pending approval {{ $pendingApproval }}</span>
                        <span class="ei-chip px-2.5 py-1">Executing {{ $executing }}</span>
                        <span class="ei-chip px-2.5 py-1">Rollbacks {{ $rollbacksAvailable }}</span>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($plans as $plan)
                        @php
                            $actionCount = $plan->actions->count();
                            $targetCount = $plan->actions
                                ->map(fn ($action) => $action->target_device_id ?? $action->target_group_id)
                                ->filter()
                                ->unique()
                                ->count();
                            $previewActions = $plan->actions
                                ->pluck('action_type')
                                ->filter()
                                ->unique()
                                ->take(3)
                                ->values();
                            $step = $nextStep($plan->status);
                            $normalizedStatus = strtolower((string) $plan->status);
                            $canValidate = ! in_array($normalizedStatus, ['executing', 'running', 'queued'], true);
                            $canApprove = in_array($normalizedStatus, ['pending_approval'], true);
                            $canExecute = in_array($normalizedStatus, ['approved', 'validated', 'ready'], true);
                        @endphp
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-slate-900">Plan {{ $plan->id }}</p>
                                        <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $statusTone($plan->status) }}">
                                            {{ $plan->status }}
                                        </span>
                                        <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $riskTone($plan->risk_level) }}">
                                            Risk {{ $plan->risk_level }}
                                        </span>
                                    </div>
                                    <p class="mt-2 text-sm text-slate-600">
                                        {{ $actionCount }} action(s) across {{ $targetCount > 0 ? $targetCount : 'n/a' }} target(s). Created {{ optional($plan->created_at)->diffForHumans() }}.
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $planGuidance($plan->status) }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if ($canValidate)
                                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700" onclick="postJson('{{ route('admin.intelligence.remediation.plans.validate', $plan->id) }}')">Validate</button>
                                    @endif
                                    @if ($canApprove)
                                        <button type="button" class="ei-button-accent rounded-lg border px-3 py-1.5 text-xs font-medium" onclick="postJson('{{ route('admin.intelligence.remediation.plans.approve', $plan->id) }}')">Approve</button>
                                    @endif
                                    @if ($canExecute)
                                        <button type="button" class="ei-button-primary rounded-lg border px-3 py-1.5 text-xs font-medium" onclick="postJson('{{ route('admin.intelligence.remediation.plans.execute', $plan->id) }}')">Execute</button>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-4 grid gap-3 lg:grid-cols-3">
                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Scope</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $targetCount > 0 ? $targetCount : 'No' }} target{{ $targetCount === 1 ? '' : 's' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $actionCount }} action{{ $actionCount === 1 ? '' : 's' }} in this plan.</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Main Actions</p>
                                    @if ($previewActions->isNotEmpty())
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($previewActions as $previewAction)
                                                <span class="ei-chip px-2.5 py-1 text-xs">{{ $previewAction }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="mt-2 text-xs text-slate-500">No actions attached yet.</p>
                                    @endif
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Next Step</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $step['label'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $step['description'] }}</p>
                                </div>
                            </div>

                            <details class="mt-4 rounded-xl border border-slate-200 bg-white">
                                <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-slate-700">
                                    View full action list
                                </summary>
                                <div class="border-t border-slate-200 px-4 py-3">
                                    <div class="grid gap-3">
                                        @foreach ($plan->actions as $action)
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="text-sm font-semibold text-slate-900">{{ $action->action_type }}</p>
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            Target: {{ $action->target_device_id ?? $action->target_group_id ?? 'n/a' }}
                                                        </p>
                                                    </div>
                                                    <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $statusTone($action->status) }}">
                                                        {{ $action->status }}
                                                    </span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </details>
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                            No remediation plans yet.
                        </div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Recent Results</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Latest execution outcomes</h3>
                    <p class="mt-1 text-sm text-slate-500">Use this panel to confirm what happened before you retry, approve another change, or roll back.</p>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($recentResults as $result)
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-slate-900">Action {{ $result->action_id }}</p>
                                        <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $resultTone($result->status) }}">
                                            {{ $result->status }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">Job {{ $result->job_id ?? 'n/a' }} • {{ optional($result->created_at)->diffForHumans() }}</p>
                                </div>
                                <button type="button" class="ei-button-accent rounded-lg border px-3 py-1.5 text-xs" onclick="postJson('{{ route('admin.intelligence.remediation.actions.rollback', $result->action_id) }}')">
                                    Rollback
                                </button>
                            </div>
                            @if (!empty($result->output_log))
                                <details class="mt-3 rounded-xl border border-slate-200 bg-white">
                                    <summary class="cursor-pointer list-none px-3 py-2 text-xs font-medium text-slate-700">View output log</summary>
                                    <pre class="border-t border-slate-200 px-3 py-3 text-xs whitespace-pre-wrap text-slate-700">{{ $result->output_log }}</pre>
                                </details>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                            No action results yet.
                        </div>
                    @endforelse
                </div>

                <details class="mt-5 rounded-xl border border-slate-200 bg-slate-50">
                    <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-slate-700">Open live request output</summary>
                    <div class="border-t border-slate-200 px-4 py-4">
                        <p class="text-xs text-slate-500">Server responses for validate, approve, execute, and rollback appear here.</p>
                        <pre id="remediation-output" class="mt-3 rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs text-slate-100">No action output yet.</pre>
                    </div>
                </details>
            </article>
        </section>
    </div>

    <script>
        async function postJson(url, payload = {}) {
            const output = document.getElementById('remediation-output');
            output.textContent = 'Running ' + url + ' ...';

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify(payload),
                });

                const raw = await response.text();
                const json = raw ? JSON.parse(raw) : {};

                if (!response.ok) {
                    output.textContent = JSON.stringify({
                        status: response.status,
                        error: json.message ?? 'Remediation request failed.',
                        details: json.errors ?? json,
                    }, null, 2);
                    return;
                }

                output.textContent = JSON.stringify(json, null, 2);
            } catch (error) {
                output.textContent = JSON.stringify({
                    error: 'Remediation request failed before the server returned JSON.',
                    details: error instanceof Error ? error.message : String(error),
                }, null, 2);
            }
        }
    </script>
</x-admin-layout>
