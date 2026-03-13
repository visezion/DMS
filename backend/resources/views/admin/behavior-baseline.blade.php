<x-admin-layout title="Behavioral Baseline" heading="Behavioral Baseline">
    @php
        $baselineSettings = is_array($baseline['settings'] ?? null) ? $baseline['settings'] : [];
        $baselineStats = is_array($baseline['stats'] ?? null) ? $baseline['stats'] : [];
        $baselineFilters = is_array($baseline['filters'] ?? null) ? $baseline['filters'] : [];
        $baselineEnabled = (bool) ($baselineSettings['enabled'] ?? false);
        $baselineReadyCount = (int) ($baselineStats['profiles_ready'] ?? 0);
        $baselineProfileCount = (int) ($baselineStats['profiles_total'] ?? 0);
        $baselineCoverage = $baselineProfileCount > 0 ? round(($baselineReadyCount / $baselineProfileCount) * 100, 1) : 0;
        $baselineDrift24h = (int) ($baselineStats['drift_events_24h'] ?? 0);
        $baselineDrift7d = (int) ($baselineStats['drift_events_7d'] ?? 0);
        $baselineTablesReady = (bool) ($baseline['tables_ready'] ?? false);
    @endphp

    <div class="space-y-4">
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Behavior Intelligence</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">Behavioral Baseline Center</h2>
                    <p class="mt-1 text-sm text-slate-600">Manage baseline learning, review drift events, and inspect per-device baseline readiness.</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs {{ $baselineEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">
                    {{ $baselineEnabled ? 'Enabled' : 'Disabled' }}
                </span>
            </div>
            @if(! $baselineTablesReady)
                <p class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    Baseline tables are not available yet. Run database migrations first.
                </p>
            @endif
        </section>

        <section class="grid gap-4 xl:grid-cols-12">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 xl:col-span-4">
                <h3 class="font-semibold text-slate-900">Baseline Settings</h3>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Profiles</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $baselineProfileCount }}</p>
                        <p class="text-xs text-slate-500">Ready {{ $baselineReadyCount }} | coverage {{ $baselineCoverage }}%</p>
                    </div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Drift Events</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $baselineDrift24h }}</p>
                        <p class="text-xs text-slate-500">24h detected | 7d total {{ $baselineDrift7d }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.behavior-baseline.settings') }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="baseline_enabled" value="0" />
                    <label class="flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="baseline_enabled" value="1" @checked($baselineEnabled) class="rounded border-slate-300 text-skyline focus:ring-skyline" />
                        Enable baseline modeling
                    </label>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="text-xs text-slate-600">
                            Min samples
                            <input type="number" name="min_samples" min="5" max="500" value="{{ (int) ($baselineSettings['min_samples'] ?? 30) }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="text-xs text-slate-600">
                            Login samples
                            <input type="number" name="min_login_samples" min="3" max="300" value="{{ (int) ($baselineSettings['min_login_samples'] ?? 12) }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="text-xs text-slate-600">
                            Numeric samples
                            <input type="number" name="min_numeric_samples" min="5" max="600" value="{{ (int) ($baselineSettings['min_numeric_samples'] ?? 20) }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="text-xs text-slate-600">
                            Max category bins
                            <input type="number" name="max_category_bins" min="30" max="1000" value="{{ (int) ($baselineSettings['max_category_bins'] ?? 240) }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="text-xs text-slate-600">
                            Drift event threshold
                            <input type="number" step="0.01" name="drift_event_threshold" min="0.40" max="0.99" value="{{ number_format((float) ($baselineSettings['drift_event_threshold'] ?? 0.68), 2, '.', '') }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="text-xs text-slate-600">
                            Category threshold
                            <input type="number" step="0.01" name="category_drift_threshold" min="0.40" max="0.99" value="{{ number_format((float) ($baselineSettings['category_drift_threshold'] ?? 0.70), 2, '.', '') }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                    </div>
                    <button class="rounded bg-skyline px-3 py-1.5 text-xs font-semibold text-white">Save Baseline Settings</button>
                </form>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 xl:col-span-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">Behavioral Drift Feed</h3>
                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $baseline['drift_events']->total() }} total</span>
                </div>
                <form method="GET" class="mt-3 grid gap-2 sm:grid-cols-4">
                    <input type="text" name="device_q" value="{{ $baselineFilters['device_q'] ?? '' }}" placeholder="Device, log id, case id" class="rounded border border-slate-300 px-2 py-2 text-sm sm:col-span-2" />
                    <select name="severity" class="rounded border border-slate-300 px-2 py-2 text-sm">
                        <option value="">Severity</option>
                        @foreach(['low','medium','high'] as $severity)
                            <option value="{{ $severity }}" @selected(($baselineFilters['severity'] ?? '') === $severity)>{{ $severity }}</option>
                        @endforeach
                    </select>
                    <button class="rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700">Filter Drift</button>
                </form>
                <div class="mt-3 space-y-2.5">
                    @forelse($baseline['drift_events'] as $driftEvent)
                        @php
                            $driftCategories = is_array($driftEvent->drift_categories ?? null) ? $driftEvent->drift_categories : [];
                        @endphp
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">
                                        {{ $driftEvent->device_hostname ?: 'Unknown device' }}
                                        <span class="text-slate-400">|</span>
                                        <span class="font-mono text-[11px] text-slate-600">{{ $driftEvent->device_id }}</span>
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">Detected {{ $driftEvent->detected_at ? $driftEvent->detected_at->diffForHumans() : 'recently' }}</p>
                                </div>
                                <div class="text-right">
                                    <span class="rounded-full px-2 py-1 text-xs {{ $driftEvent->severity === 'high' ? 'bg-amber-100 text-amber-800' : ($driftEvent->severity === 'medium' ? 'bg-amber-50 text-amber-700' : 'bg-slate-200 text-slate-700') }}">{{ $driftEvent->severity }}</span>
                                    <p class="mt-1 text-xs text-slate-600">Score {{ number_format((float) $driftEvent->drift_score, 4) }}</p>
                                </div>
                            </div>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <p class="text-xs text-slate-600">Behavior log: <span class="font-mono text-[11px]">{{ $driftEvent->behavior_log_id ?? 'n/a' }}</span></p>
                                <p class="text-xs text-slate-600">Anomaly case: <span class="font-mono text-[11px]">{{ $driftEvent->anomaly_case_id ?? 'n/a' }}</span></p>
                            </div>
                            @if($driftCategories !== [])
                                <p class="mt-2 text-xs text-slate-600">Categories: <span class="text-slate-800">{{ implode(', ', $driftCategories) }}</span></p>
                            @endif
                        </div>
                    @empty
                        <p class="rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-sm text-slate-500">No baseline drift events match the current filter.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $baseline['drift_events']->links() }}</div>
            </article>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h3 class="font-semibold text-slate-900">Device Baseline Profiles</h3>
                <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $baseline['profiles']->total() }} total</span>
            </div>
            <div class="space-y-2.5">
                @forelse($baseline['profiles'] as $profile)
                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $profile->device_hostname ?: 'Unknown device' }}
                                <span class="text-slate-400">|</span>
                                <span class="font-mono text-[11px] text-slate-600">{{ $profile->device_id }}</span>
                            </p>
                            <span class="rounded-full px-2 py-1 text-xs {{ (int) $profile->sample_count >= (int) ($baselineSettings['min_samples'] ?? 30) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                {{ (int) $profile->sample_count >= (int) ($baselineSettings['min_samples'] ?? 30) ? 'ready' : 'warming' }}
                            </span>
                        </div>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            <p class="text-xs text-slate-600">Samples: <span class="font-semibold text-slate-900">{{ (int) $profile->sample_count }}</span></p>
                            <p class="text-xs text-slate-600">Last event: <span class="text-slate-800">{{ $profile->last_event_at ? $profile->last_event_at->diffForHumans() : 'n/a' }}</span></p>
                            <p class="text-xs text-slate-600">Updated: <span class="text-slate-800">{{ $profile->last_model_update_at ? $profile->last_model_update_at->diffForHumans() : 'n/a' }}</span></p>
                        </div>
                    </div>
                @empty
                    <p class="rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-sm text-slate-500">No baseline profiles yet. Profiles are created automatically when events are processed.</p>
                @endforelse
            </div>
            <div class="mt-3">{{ $baseline['profiles']->links() }}</div>
        </section>
    </div>
</x-admin-layout>
