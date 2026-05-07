<x-admin-layout title="Autonomy Policy Settings" heading="Autonomy Policy Settings">
    @php
        $policiesTotal = (int) ($metrics['policies_total'] ?? 0);
        $activePolicies = (int) ($metrics['active_policies'] ?? 0);
        $globalPolicies = (int) ($metrics['global_policies'] ?? 0);
        $queuedRemediation = (int) ($metrics['queued_remediation'] ?? 0);
        $heroActions = [
            ['href' => route('admin.intelligence.remediation'), 'label' => 'Open Remediation'],
            ['href' => route('admin.intelligence.approvals'), 'label' => 'Open Approvals'],
            ['href' => route('admin.intelligence.actions'), 'label' => 'Open Action History'],
            ['href' => route('admin.intelligence.assistant'), 'label' => 'Ask AI Assistant'],
        ];
        $summaryCards = [
            ['label' => 'Policies Total', 'value' => $policiesTotal, 'description' => 'All autonomy policies currently stored.'],
            ['class' => 'rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-emerald-700', 'value_class' => 'mt-2 text-3xl font-semibold text-emerald-900', 'description_class' => 'mt-1 text-sm text-emerald-800', 'label' => 'Active Policies', 'value' => $activePolicies, 'description' => 'Policies that currently allow enforced boundaries.'],
            ['label' => 'Global Policies', 'value' => $globalPolicies, 'description' => 'Default rules that apply broadly across the platform.'],
            ['class' => 'rounded-2xl border border-blue-200 bg-blue-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-blue-700', 'value_class' => 'mt-2 text-3xl font-semibold text-blue-900', 'description_class' => 'mt-1 text-sm text-blue-800', 'label' => 'Queued Remediation', 'value' => $queuedRemediation, 'description' => 'Currently executing remediation that autonomy may influence.'],
        ];
    @endphp

    <div class="endpoint-intelligence-shell space-y-5">
        @include('admin.endpoint-intelligence.partials.smart-nav')
        @include('admin.endpoint-intelligence.partials.overview-hero', [
            'eyebrow' => 'Autonomy',
            'title' => 'Control how much the platform can act on its own',
            'description' => 'Autonomy policies define where automation is allowed, how aggressive it can be, and how many actions can run at the same time. Use this page to keep automation useful but controlled.',
            'actions' => $heroActions,
        ])

        @include('admin.endpoint-intelligence.partials.overview-stats', [
            'cards' => $summaryCards,
        ])

        <section class="grid gap-5 xl:grid-cols-[0.9fr,1.1fr]">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Create Or Update Policy</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Set clear automation boundaries</h3>
                    <p class="mt-1 text-sm text-slate-500">Use global for broad defaults, or target a tenant, group, or device when you need tighter control.</p>
                </div>

                <form id="autonomy-form" class="mt-4 space-y-4">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Scope Type</label>
                            <select name="scope_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="global">Global</option>
                                <option value="tenant">Tenant</option>
                                <option value="group">Group</option>
                                <option value="device">Device</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Autonomy Level</label>
                            <select name="autonomy_level" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="off">Off</option>
                                <option value="advisory" selected>Advisory</option>
                                <option value="semi_auto">Semi Auto</option>
                                <option value="auto">Auto</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Scope ID</label>
                            <input name="scope_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="Leave blank for global">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Max Parallel Actions</label>
                            <input name="max_parallel_actions" type="number" min="1" max="100" value="5" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                        <p class="font-medium text-slate-900">Level guide</p>
                        <div class="mt-2 space-y-2">
                            <p><strong>Off:</strong> no autonomous action.</p>
                            <p><strong>Advisory:</strong> recommend only, operator decides.</p>
                            <p><strong>Semi Auto:</strong> some actions can progress with tighter controls.</p>
                            <p><strong>Auto:</strong> the platform can act automatically within policy boundaries.</p>
                        </div>
                    </div>

                    <button class="ei-button-primary rounded-xl border px-4 py-2 text-sm font-medium">Save Policy</button>
                </form>

                <div class="mt-5">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Save Output</p>
                    <pre id="autonomy-output" class="mt-2 rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs text-slate-100">No policy update yet.</pre>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Current Policies</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">Active autonomy boundaries</h3>
                    <p class="mt-1 text-sm text-slate-500">These are the rules currently shaping autonomous behavior.</p>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($policies as $policy)
                        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-slate-900">{{ ucfirst((string) $policy->scope_type) }} {{ $policy->scope_id ?? 'default' }}</p>
                                        <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-600">{{ $policy->autonomy_level }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $policy->active ? 'border border-emerald-300 bg-emerald-50 text-emerald-700' : 'border border-slate-200 bg-white text-slate-500' }}">
                                            {{ $policy->active ? 'active' : 'inactive' }}
                                        </span>
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">Max parallel actions: {{ $policy->max_parallel_actions }}</p>
                                    @if (!empty($policy->updated_at))
                                        <p class="mt-1 text-xs text-slate-500">Updated {{ optional($policy->updated_at)->diffForHumans() }}</p>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                            No autonomy policies found yet.
                        </div>
                    @endforelse
                </div>
            </article>
        </section>
    </div>

    <script>
        document.getElementById('autonomy-form')?.addEventListener('submit', async function (event) {
            event.preventDefault();
            const form = event.currentTarget;
            const output = document.getElementById('autonomy-output');
            output.textContent = 'Saving autonomy policy...';

            const formData = new FormData(form);
            const payload = Object.fromEntries(formData.entries());
            if (payload.scope_type === 'global' && payload.scope_id === '') {
                delete payload.scope_id;
            }

            try {
                const response = await fetch('{{ route('admin.intelligence.autonomy.policies.save') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify(payload),
                });

                const responseText = await response.text();
                let parsed;

                try {
                    parsed = responseText ? JSON.parse(responseText) : {};
                } catch (error) {
                    parsed = {
                        message: response.ok ? 'Policy saved.' : 'Request failed before returning JSON.',
                        raw: responseText,
                    };
                }

                if (!response.ok) {
                    output.textContent = JSON.stringify({
                        status: response.status,
                        error: parsed.message ?? 'Unable to save autonomy policy.',
                        details: parsed.errors ?? parsed,
                    }, null, 2);
                    return;
                }

                output.textContent = JSON.stringify(parsed, null, 2);
            } catch (error) {
                output.textContent = JSON.stringify({
                    error: 'Network or browser error while saving autonomy policy.',
                    details: error instanceof Error ? error.message : String(error),
                }, null, 2);
            }
        });
    </script>
</x-admin-layout>
