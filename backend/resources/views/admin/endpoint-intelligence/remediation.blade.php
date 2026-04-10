<x-admin-layout title="Remediation Queue" heading="Remediation Queue">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="mt-5 grid gap-5 xl:grid-cols-[1.15fr,0.85fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Plans</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">Queue and current state</h3>
            <div class="mt-4 space-y-3">
                @forelse ($plans as $plan)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-slate-900">Plan {{ $plan->id }}</p>
                                <p class="text-xs text-slate-500">Risk {{ $plan->risk_level }} | {{ $plan->status }} | {{ $plan->actions->count() }} action(s)</p>
                            </div>
                            <div class="flex gap-2">
                                <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700" onclick="postJson('{{ route('admin.intelligence.remediation.plans.validate', $plan->id) }}')">Validate</button>
                                <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700" onclick="postJson('{{ route('admin.intelligence.remediation.plans.approve', $plan->id) }}')">Approve</button>
                                <button class="ei-button-primary rounded-lg border px-3 py-1.5 text-xs font-medium" onclick="postJson('{{ route('admin.intelligence.remediation.plans.execute', $plan->id) }}')">Execute</button>
                            </div>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead class="text-left uppercase tracking-wide text-slate-500">
                                    <tr><th class="pb-2">Action</th><th class="pb-2">Target</th><th class="pb-2">Status</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($plan->actions as $action)
                                        <tr>
                                            <td class="py-2 pr-3">{{ $action->action_type }}</td>
                                            <td class="py-2 pr-3">{{ $action->target_device_id ?? $action->target_group_id }}</td>
                                            <td class="py-2">{{ $action->status }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No remediation plans yet.</div>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Recent Results</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">Execution outcomes</h3>
            <div class="mt-4 space-y-3">
                @forelse ($recentResults as $result)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-slate-900">Action {{ $result->action_id }}</p>
                                <p class="text-xs text-slate-500">Job {{ $result->job_id }} | {{ $result->status }}</p>
                            </div>
                            <button class="ei-button-accent rounded-lg border px-3 py-1.5 text-xs" onclick="postJson('{{ route('admin.intelligence.remediation.actions.rollback', $result->action_id) }}')">Rollback</button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No action results yet.</div>
                @endforelse
            </div>
            <pre id="remediation-output" class="mt-4 rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs text-slate-100">No action output yet.</pre>
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
