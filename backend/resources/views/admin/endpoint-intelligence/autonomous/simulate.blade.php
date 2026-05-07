<x-admin-layout title="Autonomous Response Simulation" heading="Endpoint Intelligence">
    @include('admin.endpoint-intelligence.autonomous.partials.subnav', [
        'title' => 'Simulation And Manual Evaluation',
        'description' => 'Preview confidence, rationale, and execution mode before allowing autonomous actions.',
    ])

    <section class="mt-5 grid gap-5 xl:grid-cols-[0.95fr,1.05fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Simulate Decision</h3>
            <form method="POST" action="{{ route('admin.intelligence.autonomous.simulate.run') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <input name="trigger_source" value="manual_simulation" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Trigger source" required>
                    <input name="trigger_type" value="malware_detected" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Trigger type" required>
                    <select name="device_id" class="rounded-xl border border-slate-300 px-4 py-3">
                        <option value="">Select device</option>
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}">{{ $device->hostname }}</option>
                        @endforeach
                    </select>
                    <select name="finding_id" class="rounded-xl border border-slate-300 px-4 py-3">
                        <option value="">Select finding</option>
                        @foreach($findings as $finding)
                            <option value="{{ $finding->id }}">{{ $finding->finding_type }} · {{ $finding->device_id }}</option>
                        @endforeach
                    </select>
                    <select name="incident_id" class="rounded-xl border border-slate-300 px-4 py-3">
                        <option value="">Select incident</option>
                        @foreach($incidents as $incident)
                            <option value="{{ $incident->id }}">{{ $incident->title }}</option>
                        @endforeach
                    </select>
                    <input name="severity" value="high" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Severity">
                    <input type="number" name="risk_score" value="85" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Risk score">
                    <input name="requested_mode" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Optional requested mode">
                </div>
                <button class="rounded-xl bg-ink px-4 py-2.5 text-sm font-medium text-white">Run Simulation</button>
            </form>

            <h3 class="mt-8 text-lg font-semibold text-slate-900">Manual Evaluation</h3>
            <form method="POST" action="{{ route('admin.intelligence.autonomous.evaluate') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <input name="trigger_source" value="manual_evaluation" class="rounded-xl border border-slate-300 px-4 py-3" required>
                    <input name="trigger_type" value="suspicious_login" class="rounded-xl border border-slate-300 px-4 py-3" required>
                    <select name="device_id" class="rounded-xl border border-slate-300 px-4 py-3">
                        <option value="">Select device</option>
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}">{{ $device->hostname }}</option>
                        @endforeach
                    </select>
                    <input name="severity" value="high" class="rounded-xl border border-slate-300 px-4 py-3">
                </div>
                <button class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700">Create Decision</button>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Simulation Preview</h3>
            @if($preview)
                <div class="mt-4 space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Recommended Action</p>
                        <p class="mt-2 text-lg font-semibold text-slate-900">{{ $preview['recommended_action'] ?? 'manual_review' }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Mode / Status</p>
                        <p class="mt-2 text-sm text-slate-700">{{ $preview['decision_mode'] }} · {{ $preview['status'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Rationale</p>
                        <p class="mt-2 text-sm leading-6 text-slate-700">{{ $preview['rationale'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Preview JSON</p>
                        <pre class="mt-2 overflow-x-auto text-xs text-slate-600">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            @else
                <p class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">Run a simulation to preview the selected action, confidence, and execution mode.</p>
            @endif
        </article>
    </section>
</x-admin-layout>
