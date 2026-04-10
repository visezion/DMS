<x-admin-layout title="Autonomy Policy Settings" heading="Autonomy Policy Settings">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="mt-5 grid gap-5 xl:grid-cols-[0.85fr,1.15fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Update Policy</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">Bound automation scope</h3>
            <form id="autonomy-form" class="mt-4 space-y-3">
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
                    <label class="mb-1 block text-xs font-medium text-slate-600">Scope ID</label>
                    <input name="scope_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="Optional scope target">
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
                <button class="ei-button-primary rounded-xl border px-4 py-2 text-sm font-medium">Save Policy</button>
            </form>
            <pre id="autonomy-output" class="mt-4 rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs text-slate-100">No policy update yet.</pre>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Current Policies</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">Active boundaries</h3>
            <div class="mt-4 space-y-3">
                @foreach ($policies as $policy)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-slate-900">{{ $policy->scope_type }} {{ $policy->scope_id ?? 'default' }}</p>
                                <p class="text-xs text-slate-500">{{ $policy->autonomy_level }} | max parallel {{ $policy->max_parallel_actions }}</p>
                            </div>
                            <span class="ei-chip {{ $policy->active ? 'ei-chip-primary' : '' }} px-2 py-1 text-xs font-medium">{{ $policy->active ? 'active' : 'inactive' }}</span>
                        </div>
                    </div>
                @endforeach
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
            const payload = Object.fromEntries(new FormData(form).entries());

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
