<x-admin-layout title="Autonomous Response Policies" heading="Endpoint Intelligence">
    @include('admin.endpoint-intelligence.autonomous.partials.subnav', [
        'title' => 'Autonomous Response Policies',
        'description' => 'Define confidence, scope, and execution mode for self-healing decisions.',
    ])

    <section class="mt-5 grid gap-5 xl:grid-cols-[0.95fr,1.05fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Create Policy</h3>
            <form method="POST" action="{{ route('admin.intelligence.autonomous.policies.store') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Name</label>
                        <input name="name" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3" required>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Scope Type</label>
                        <select name="scope_type" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3">
                            @foreach(['global', 'tenant', 'group', 'device', 'incident_type', 'finding_type'] as $scope)
                                <option value="{{ $scope }}">{{ \Illuminate\Support\Str::headline($scope) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Scope Id</label>
                        <input name="scope_id" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="optional">
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Trigger Type</label>
                        <input name="trigger_type" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3" value="any" required>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Autonomy Mode</label>
                        <select name="autonomy_mode" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3">
                            @foreach(['recommend_only', 'approval_required', 'auto_execute'] as $mode)
                                <option value="{{ $mode }}">{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $mode)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Minimum Risk Score</label>
                        <input type="number" name="minimum_risk_score" min="0" max="100" value="0" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3">
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Minimum Confidence</label>
                        <input type="number" name="minimum_confidence" min="0" max="100" value="70" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3">
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Max Actions / Hour</label>
                        <input type="number" name="max_actions_per_hour" min="1" max="100" value="4" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3">
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Cooldown Minutes</label>
                        <input type="number" name="cooldown_minutes" min="0" max="1440" value="30" class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3">
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Allowed Actions</label>
                        <select name="allowed_actions[]" multiple class="mt-1.5 h-56 w-full rounded-xl border border-slate-300 px-4 py-3">
                            @foreach($catalog as $entry)
                                <option value="{{ $entry['key'] }}">{{ $entry['display_name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.18em] text-slate-500">Blocked Actions</label>
                        <select name="blocked_actions[]" multiple class="mt-1.5 h-56 w-full rounded-xl border border-slate-300 px-4 py-3">
                            @foreach($catalog as $entry)
                                <option value="{{ $entry['key'] }}">{{ $entry['display_name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <label class="inline-flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    <input type="checkbox" name="requires_rollback_plan" value="1" class="rounded border-slate-300">
                    Require rollback plan for eligible actions
                </label>

                <label class="inline-flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    <input type="checkbox" name="enabled" value="1" checked class="rounded border-slate-300">
                    Enabled
                </label>

                <button class="rounded-xl bg-ink px-4 py-2.5 text-sm font-medium text-white">Save Policy</button>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900">Current Policies</h3>
            <div class="mt-4 space-y-4">
                @forelse($policies as $policy)
                    <form method="POST" action="{{ route('admin.intelligence.autonomous.policies.update', $policy->id) }}" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        @csrf
                        @method('PATCH')
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <input name="name" value="{{ $policy->name }}" class="w-full rounded-xl border border-slate-300 px-4 py-3 font-medium">
                            </div>
                            <div>
                                <label class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Scope</label>
                                <input name="scope_type" value="{{ $policy->scope_type }}" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3">
                            </div>
                            <div>
                                <label class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Scope Id</label>
                                <input name="scope_id" value="{{ $policy->scope_id }}" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3">
                            </div>
                            <div>
                                <label class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Trigger</label>
                                <input name="trigger_type" value="{{ $policy->trigger_type }}" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3">
                            </div>
                            <div>
                                <label class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Mode</label>
                                <select name="autonomy_mode" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3">
                                    @foreach(['recommend_only', 'approval_required', 'auto_execute'] as $mode)
                                        <option value="{{ $mode }}" @selected($policy->autonomy_mode === $mode)>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $mode)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-3 md:grid-cols-4">
                            <input type="number" name="minimum_risk_score" value="{{ $policy->minimum_risk_score }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Min risk">
                            <input type="number" name="minimum_confidence" value="{{ $policy->minimum_confidence }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Min confidence">
                            <input type="number" name="max_actions_per_hour" value="{{ $policy->max_actions_per_hour }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Max / hour">
                            <input type="number" name="cooldown_minutes" value="{{ $policy->cooldown_minutes }}" class="rounded-xl border border-slate-300 px-4 py-3" placeholder="Cooldown">
                        </div>
                        @foreach($policy->allowed_actions ?? [] as $allowedAction)
                            <input type="hidden" name="allowed_actions[]" value="{{ $allowedAction }}">
                        @endforeach
                        @foreach($policy->blocked_actions ?? [] as $blockedAction)
                            <input type="hidden" name="blocked_actions[]" value="{{ $blockedAction }}">
                        @endforeach
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            @foreach($policy->allowed_actions ?? [] as $allowedAction)
                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-emerald-700">allow {{ $allowedAction }}</span>
                            @endforeach
                            @foreach($policy->blocked_actions ?? [] as $blockedAction)
                                <span class="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-rose-700">block {{ $blockedAction }}</span>
                            @endforeach
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-4">
                            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="requires_rollback_plan" value="1" @checked($policy->requires_rollback_plan) class="rounded border-slate-300">
                                Require rollback
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="enabled" value="1" @checked($policy->enabled) class="rounded border-slate-300">
                                Enabled
                            </label>
                            <button class="rounded-xl bg-ink px-4 py-2 text-sm font-medium text-white">Update</button>
                        </div>
                    </form>
                @empty
                    <p class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">No autonomous response policies yet.</p>
                @endforelse
            </div>
        </article>
    </section>
</x-admin-layout>
