<x-admin-layout title="Approval Center" heading="Approval Center">
    <div class="endpoint-intelligence-shell space-y-5">
    @include('admin.endpoint-intelligence.partials.smart-nav')
    @include('admin.endpoint-intelligence.partials.metric-cards', ['metrics' => $metrics])

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Pending Decisions</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">SLA-aware approval inbox</h3>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="pb-2">Request</th>
                        <th class="pb-2">Risk</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">Expires</th>
                        <th class="pb-2">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($approvals as $approval)
                        <tr>
                            <td class="py-2 pr-3">{{ $approval->request_type }} / {{ $approval->request_ref_id }}</td>
                            <td class="py-2 pr-3 capitalize">{{ $approval->risk_level }}</td>
                            <td class="py-2 pr-3 capitalize">{{ $approval->status }}</td>
                            <td class="py-2 pr-3">{{ optional($approval->expires_at)->diffForHumans() ?? 'none' }}</td>
                            <td class="py-2">
                                @if ($approval->status === 'pending')
                                    <div class="flex gap-2">
                                        <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700" onclick="approvalPost('{{ route('admin.intelligence.approvals.approve', $approval->id) }}')">Approve</button>
                                        <button class="ei-button-accent rounded-lg border px-3 py-1.5 text-xs" onclick="approvalPost('{{ route('admin.intelligence.approvals.reject', $approval->id) }}', { note: 'Rejected from approval center.' })">Reject</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <pre id="approval-output" class="mt-4 rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs text-slate-100">No approval action yet.</pre>
    </section>
    </div>

    <script>
        async function approvalPost(url, payload = {}) {
            const output = document.getElementById('approval-output');
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
                        error: json.message ?? 'Approval request failed.',
                        details: json.errors ?? json,
                    }, null, 2);
                    return;
                }

                output.textContent = JSON.stringify(json, null, 2);
            } catch (error) {
                output.textContent = JSON.stringify({
                    error: 'Approval request failed before the server returned JSON.',
                    details: error instanceof Error ? error.message : String(error),
                }, null, 2);
            }
        }
    </script>
</x-admin-layout>
