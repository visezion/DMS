<x-admin-layout title="Risk To Action Mappings" heading="Endpoint Intelligence">
    @include('admin.endpoint-intelligence.autonomous.partials.subnav', [
        'title' => 'Risk To Action Mapping Manager',
        'description' => 'Map findings and incidents into candidate response actions and priorities.',
    ])

    <section class="mt-5 grid gap-5 xl:grid-cols-[0.9fr,1.1fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Create Mapping</h3>
            <form method="POST" action="{{ route('admin.intelligence.autonomous.mappings.store') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <input name="name" class="w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="Malware Containment" required>
                    </div>
                    <input name="trigger_type" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="malware_detected" required>
                    <input name="minimum_risk_score" type="number" min="0" max="100" value="0" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Minimum risk score">
                    <input name="minimum_severity" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="low / medium / high">
                    <input name="maximum_severity" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="optional max severity">
                    <input name="priority" type="number" min="1" max="1000" value="100" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Mapping priority">
                    <input name="maximum_risk_score" type="number" min="0" max="100" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="optional max risk score">
                </div>
                <div class="space-y-3">
                    @for($i = 0; $i < 3; $i++)
                        <div class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-[1.2fr,0.45fr]">
                            <select name="candidate_actions[{{ $i }}][action_key]" class="rounded-xl border border-slate-300 px-4 py-3">
                                @foreach($catalog as $entry)
                                    <option value="{{ $entry['key'] }}">{{ $entry['display_name'] }}</option>
                                @endforeach
                            </select>
                            <input type="number" name="candidate_actions[{{ $i }}][priority]" min="1" max="1000" value="{{ $i + 1 }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Priority">
                        </div>
                    @endfor
                </div>
                <label class="inline-flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    <input type="checkbox" name="enabled" value="1" checked class="rounded border-slate-300">
                    Enabled
                </label>
                <button class="rounded-xl bg-ink px-4 py-2.5 text-sm font-medium text-white">Save Mapping</button>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Current Mappings</h3>
            <div class="mt-4 space-y-4">
                @forelse($mappings as $mapping)
                    <form method="POST" action="{{ route('admin.intelligence.autonomous.mappings.update', $mapping->id) }}" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        @csrf
                        @method('PATCH')
                        <div class="grid gap-4 md:grid-cols-2">
                            <input name="name" value="{{ $mapping->name }}" class="rounded-xl border border-slate-300 px-4 py-3">
                            <input name="trigger_type" value="{{ $mapping->trigger_type }}" class="rounded-xl border border-slate-300 px-4 py-3">
                            <input name="minimum_severity" value="{{ $mapping->minimum_severity }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Minimum severity">
                            <input name="maximum_severity" value="{{ $mapping->maximum_severity }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Maximum severity">
                            <input type="number" name="minimum_risk_score" value="{{ $mapping->minimum_risk_score }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Minimum risk">
                            <input type="number" name="maximum_risk_score" value="{{ $mapping->maximum_risk_score }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Maximum risk">
                        </div>
                        <div class="mt-3 space-y-2">
                            @foreach(($mapping->candidate_actions ?? []) as $index => $candidate)
                                <div class="grid gap-3 md:grid-cols-[1.2fr,0.45fr]">
                                    <select name="candidate_actions[{{ $index }}][action_key]" class="rounded-xl border border-slate-300 px-4 py-3">
                                        @foreach($catalog as $entry)
                                            <option value="{{ $entry['key'] }}" @selected(($candidate['action_key'] ?? '') === $entry['key'])>{{ $entry['display_name'] }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" name="candidate_actions[{{ $index }}][priority]" value="{{ $candidate['priority'] ?? ($index + 1) }}" class="rounded-xl border border-slate-300 px-4 py-3">
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-4">
                            <input type="number" name="priority" value="{{ $mapping->priority }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Mapping priority">
                            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="enabled" value="1" @checked($mapping->enabled) class="rounded border-slate-300">
                                Enabled
                            </label>
                            <button class="rounded-xl bg-ink px-4 py-2 text-sm font-medium text-white">Update Mapping</button>
                        </div>
                    </form>
                @empty
                    <p class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">No risk-to-action mappings yet.</p>
                @endforelse
            </div>
        </article>
    </section>
</x-admin-layout>
