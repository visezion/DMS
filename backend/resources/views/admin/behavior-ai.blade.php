<x-admin-layout title="AI Control Center" heading="AI Control Center">
    @php
        $approvalDenominator = $stats['feedback_approved_30d'] + $stats['feedback_rejected_30d'];
        $approvalRatio = $approvalDenominator > 0
            ? round(($stats['feedback_approved_30d'] / $approvalDenominator) * 100, 1)
            : null;
        $approvalTone = $approvalRatio === null
            ? 'text-slate-700 bg-slate-100 border-slate-200'
            : ($approvalRatio >= 70 ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : ($approvalRatio >= 45 ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-rose-700 bg-rose-50 border-rose-200'));
        $queueRunning = (bool) ($runtime['queue_running'] ?? false);
        $schedulerRunning = (bool) ($runtime['scheduler_running'] ?? false);
        $runtimeHealthy = $queueRunning && $schedulerRunning;
        $operationsBacklog = (int) $stats['stream_queued'] + (int) $stats['stream_failed'] + (int) $stats['cases_pending'];
    @endphp

    <style>
        .ai-shell {
            --ai-bg: #ffffff;
            --ai-border: #d8e1ef;
            --ai-soft: #eef4ff;
            --ai-shadow: 0 10px 26px rgba(15, 23, 42, 0.08);
            --ai-shadow-soft: 0 6px 18px rgba(15, 23, 42, 0.06);
            --ai-text: #0f172a;
            --ai-muted: #64748b;
        }
        .ai-shell .ai-panel {
            border: 1px solid var(--ai-border);
            background: var(--ai-bg);
            box-shadow: var(--ai-shadow-soft);
        }
        .ai-shell .ai-title {
            color: var(--ai-text);
            letter-spacing: 0.01em;
        }
        .ai-shell .ai-chip {
            border: 1px solid var(--ai-border);
            background: #ffffff;
            color: #334155;
        }
        .ai-shell .ai-metric {
            border: 1px solid var(--ai-border);
            background: #f8fafc;
            box-shadow: var(--ai-shadow-soft);
        }
        .ai-shell .ai-metric-number {
            font-family: "IBM Plex Mono", monospace;
        }
        .ai-shell .ai-op-card {
            border: 1px solid var(--ai-border);
            background: #ffffff;
            box-shadow: var(--ai-shadow-soft);
        }
        .ai-shell .ai-op-card:hover {
            box-shadow: var(--ai-shadow);
            transform: translateY(-1px);
            transition: 180ms ease;
        }
        .ai-shell .ai-streamline {
            height: 6px;
            border-radius: 9999px;
            background: #e2e8f0;
            overflow: hidden;
        }
        .ai-shell .ai-streamline > span {
            display: block;
            height: 100%;
        }
        .ai-shell .ai-filter-shell {
            border: 1px solid var(--ai-border);
            background: #ffffff;
            box-shadow: var(--ai-shadow-soft);
        }
        .ai-shell .ai-feed-card {
            border: 1px solid var(--ai-border);
            background: #ffffff;
            box-shadow: var(--ai-shadow-soft);
        }
        .ai-shell .ai-kv-label {
            color: var(--ai-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .ai-shell .ai-reveal {
            animation: aiFadeIn 360ms ease both;
        }
        .ai-shell .ai-live-chip {
            border: 1px solid var(--ai-border);
            background: #ffffff;
            color: #334155;
        }
        @keyframes aiFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>

    <div class="ai-shell space-y-4">
        <section class="ai-panel rounded-2xl p-5 ai-reveal">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Behavior Intelligence Platform</p>
                    <h2 class="mt-1 text-2xl font-semibold ai-title">AI Operations Cockpit</h2>
                    <p class="mt-1 text-sm text-slate-600">Stream ingestion, anomaly intelligence, and policy automation in one operational control surface.</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="ai-chip rounded-full px-3 py-1">Anomaly threshold: {{ $threshold }}</span>
                    <span class="ai-chip rounded-full px-3 py-1">Last retrain: {{ $lastRetrainedAt }}</span>
                    <span class="rounded-full border px-3 py-1 {{ $approvalTone }}">
                        30d approval: {{ $approvalRatio !== null ? $approvalRatio.'%' : 'N/A' }}
                    </span>
                </div>
            </div>
            <div id="ai-auto-refresh-controls" class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                <span id="ai-auto-refresh-state" class="ai-live-chip rounded-full px-3 py-1">Auto-update: initializing...</span>
                <label for="ai-auto-refresh-interval" class="text-slate-500">Interval</label>
                <select id="ai-auto-refresh-interval" class="rounded border border-slate-300 bg-white px-2 py-1 text-xs">
                    <option value="15">15s</option>
                    <option value="30">30s</option>
                    <option value="60">60s</option>
                    <option value="120">120s</option>
                </select>
                <button type="button" id="ai-auto-refresh-toggle" class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700">Pause</button>
                <button type="button" id="ai-auto-refresh-now" class="rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700">Refresh now</button>
                <span id="ai-auto-refresh-last-sync" class="text-slate-500"></span>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full px-2.5 py-1 {{ $runtimeHealthy ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                    Runtime {{ $runtimeHealthy ? 'healthy' : 'degraded' }}
                </span>
                <span class="rounded-full px-2.5 py-1 {{ $operationsBacklog <= 25 ? 'bg-emerald-100 text-emerald-700' : ($operationsBacklog <= 200 ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') }}">
                    Backlog {{ $operationsBacklog }}
                </span>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">
                    Feedback records {{ $stats['feedback_total'] }}
                </span>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 ai-reveal">
            <article class="ai-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Queued Stream Events</p>
                <p class="mt-1 text-3xl font-bold ai-metric-number text-slate-900">{{ $stats['stream_queued'] }}</p>
                <div class="mt-2 ai-streamline"><span class="bg-sky-500" style="width: {{ min(100, max(4, $stats['stream_queued'])) }}%"></span></div>
                <p class="mt-2 text-xs text-slate-500">Waiting to be processed by detection workers.</p>
            </article>
            <article class="ai-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Failed Stream Events</p>
                <p class="mt-1 text-3xl font-bold ai-metric-number text-rose-700">{{ $stats['stream_failed'] }}</p>
                <div class="mt-2 ai-streamline"><span class="bg-rose-500" style="width: {{ min(100, max(4, $stats['stream_failed'])) }}%"></span></div>
                <p class="mt-2 text-xs text-slate-500">Need replay or parser inspection.</p>
            </article>
            <article class="ai-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Pending AI Cases</p>
                <p class="mt-1 text-3xl font-bold ai-metric-number text-amber-700">{{ $stats['cases_pending'] }}</p>
                <div class="mt-2 ai-streamline"><span class="bg-amber-500" style="width: {{ min(100, max(4, $stats['cases_pending'])) }}%"></span></div>
                <p class="mt-2 text-xs text-slate-500">Cases waiting for analyst decision.</p>
            </article>
            <article class="ai-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Applied Recommendations</p>
                <p class="mt-1 text-3xl font-bold ai-metric-number text-emerald-700">{{ $stats['recommendations_applied'] }}</p>
                <div class="mt-2 ai-streamline"><span class="bg-emerald-500" style="width: {{ min(100, max(4, $stats['recommendations_applied'])) }}%"></span></div>
                <p class="mt-2 text-xs text-slate-500">Policy actions executed successfully.</p>
            </article>
            <article class="ai-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Queue Worker</p>
                <p class="mt-1 text-3xl font-bold ai-metric-number {{ $queueRunning ? 'text-emerald-700' : 'text-rose-700' }}">{{ $queueRunning ? 'UP' : 'DOWN' }}</p>
                <p class="mt-2 text-xs text-slate-500">`queue:work --queue=horizon` runtime state.</p>
            </article>
            <article class="ai-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Scheduler</p>
                <p class="mt-1 text-3xl font-bold ai-metric-number {{ $schedulerRunning ? 'text-emerald-700' : 'text-rose-700' }}">{{ $schedulerRunning ? 'UP' : 'DOWN' }}</p>
                <p class="mt-2 text-xs text-slate-500">`schedule:work` runtime state.</p>
            </article>
        </section>

        <section class="grid gap-3 lg:grid-cols-2 xl:grid-cols-4 ai-reveal">
            <div class="ai-op-card rounded-xl p-4 xl:col-span-1">
                <h3 class="font-semibold text-slate-900">Runtime Control</h3>
                <p class="mt-1 text-xs text-slate-500">Start worker and scheduler when they are offline.</p>
                @error('ai_runtime')
                    <div class="mt-2 rounded border border-rose-300 bg-rose-50 px-2 py-1 text-xs text-rose-700">{{ $message }}</div>
                @enderror
                <div class="mt-3 space-y-2 text-xs">
                    <p>Queue: <span id="behavior-runtime-queue-line" class="rounded-full px-2 py-0.5 {{ $queueRunning ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ $queueRunning ? 'running' : 'not running' }}</span></p>
                    <p>Scheduler: <span id="behavior-runtime-scheduler-line" class="rounded-full px-2 py-0.5 {{ $schedulerRunning ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ $schedulerRunning ? 'running' : 'not running' }}</span></p>
                </div>
                <form method="POST" action="{{ route('admin.behavior-ai.runtime.start') }}" class="mt-3">
                    @csrf
                    <button class="rounded bg-ink px-3 py-1.5 text-xs font-semibold text-white">Start AI Runtime</button>
                </form>
            </div>

            <form method="POST" action="{{ route('admin.behavior-ai.replay') }}" class="ai-op-card rounded-xl p-4 xl:col-span-1">
                @csrf
                <h3 class="font-semibold text-slate-900">Replay Stream</h3>
                <p class="mt-1 text-xs text-slate-500">Requeue queued/failed stream events for reprocessing.</p>
                <div class="mt-3 flex items-center gap-2">
                    <input type="number" name="limit" min="1" max="5000" value="200" class="w-28 rounded border border-slate-300 px-2 py-1.5 text-sm" />
                    <button class="rounded bg-ink px-3 py-1.5 text-xs font-semibold text-white">Queue Replay</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.behavior-ai.train-now') }}" class="ai-op-card rounded-xl p-4 xl:col-span-2">
                @csrf
                <h3 class="font-semibold text-slate-900">Train Now</h3>
                <p class="mt-1 text-xs text-slate-500">Runs a chained operation: dataset backfill followed by full AI model training.</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <input type="number" name="days" min="1" max="180" value="30" class="rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="Days of data" />
                    <input type="number" name="min_events" min="50" max="100000" value="200" class="rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="Minimum events" />
                </div>
                <button class="mt-2 rounded bg-skyline px-3 py-1.5 text-xs font-semibold text-white">Queue Train Now</button>
            </form>

            <form method="POST" action="{{ route('admin.behavior-ai.retrain') }}" class="ai-op-card rounded-xl p-4 lg:col-span-2 xl:col-span-4">
                @csrf
                <h3 class="font-semibold text-slate-900">Adaptive Retraining</h3>
                <p class="mt-1 text-xs text-slate-500">Update detector weights and policy acceptance priors using analyst feedback.</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <input type="number" name="days" min="7" max="365" value="45" class="rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="Feedback window (days)" />
                    <input type="number" name="min_feedback" min="5" max="100000" value="20" class="rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="Minimum feedback samples" />
                </div>
                <button class="mt-2 rounded bg-skyline px-3 py-1.5 text-xs font-semibold text-white">Queue Retrain</button>
            </form>
        </section>

        <section class="ai-filter-shell rounded-xl p-4 ai-reveal">
            <form method="GET" class="grid gap-2 lg:grid-cols-6">
                <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search case/device/policy" class="rounded border border-slate-300 px-2 py-2 text-sm lg:col-span-2" />
                <select name="case_status" class="rounded border border-slate-300 px-2 py-2 text-sm">
                    <option value="">Case status</option>
                    @foreach(['pending_review','approved','dismissed','auto_applied'] as $status)
                        <option value="{{ $status }}" @selected($filters['case_status'] === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                <select name="severity" class="rounded border border-slate-300 px-2 py-2 text-sm">
                    <option value="">Severity</option>
                    @foreach(['low','medium','high'] as $severity)
                        <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ $severity }}</option>
                    @endforeach
                </select>
                <select name="recommendation_status" class="rounded border border-slate-300 px-2 py-2 text-sm">
                    <option value="">Recommendation status</option>
                    @foreach(['pending','approved','rejected','applied','auto_applied'] as $status)
                        <option value="{{ $status }}" @selected($filters['recommendation_status'] === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                <div class="flex gap-2">
                    <button class="rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700">Filter</button>
                    <a href="{{ route('admin.behavior-ai.index') }}" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">Reset</a>
                </div>
            </form>
        </section>

        <section class="grid gap-4 xl:grid-cols-2 ai-reveal">
            <div class="ai-feed-card rounded-2xl p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold text-slate-900">Anomaly Cases</h3>
                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $cases->total() }} total</span>
                </div>
                <div class="space-y-3">
                    @forelse($cases as $case)
                        <article class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="font-medium text-slate-900">{{ $case->summary }}</p>
                                <span class="rounded-full px-2 py-1 text-xs {{ $case->severity === 'high' ? 'bg-rose-100 text-rose-700' : ($case->severity === 'medium' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700') }}">{{ $case->severity }}</span>
                            </div>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <div>
                                    <p class="ai-kv-label">Case ID</p>
                                    <p class="font-mono text-xs text-slate-700">{{ $case->id }}</p>
                                </div>
                                <div>
                                    <p class="ai-kv-label">Device</p>
                                    <p class="text-xs text-slate-700">
                                        @if(($case->device_name_display ?? '') !== '')
                                            <span class="font-semibold text-slate-800">{{ $case->device_name_display }}</span>
                                            <span class="text-slate-400">|</span>
                                        @endif
                                        <span class="font-mono">{{ $case->device_id }}</span>
                                    </p>
                                </div>
                                <div>
                                    <p class="ai-kv-label">Event</p>
                                    <p class="text-xs text-slate-700">
                                        <span class="font-medium">{{ $case->event_type_display ?? 'unknown' }}</span>
                                        @if(($case->app_name_display ?? null) !== null)
                                            <span class="text-slate-400">|</span>
                                            <span class="font-semibold text-slate-800">{{ $case->app_name_display }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div>
                                    <p class="ai-kv-label">User</p>
                                    <p class="text-xs text-slate-700">{{ $case->user_name_display ?? 'n/a' }}</p>
                                </div>
                            </div>
                            @if(($case->process_name_display ?? null) !== null && ($case->event_type_display ?? '') !== 'app_launch')
                                <p class="mt-2 text-xs text-slate-600">Process: <span class="font-mono text-[11px]">{{ $case->process_name_display }}</span></p>
                            @endif
                            @if(($case->file_path_display ?? null) !== null)
                                <p class="mt-1 text-xs text-slate-600">File Path: <span class="font-mono text-[11px]">{{ $case->file_path_display }}</span></p>
                            @endif
                            @if(! empty($case->pattern_hints_display))
                                <p class="mt-1 text-xs text-slate-600">Patterns: <span class="text-slate-800">{{ implode(' | ', $case->pattern_hints_display) }}</span></p>
                            @endif
                            <div class="mt-2 flex items-center justify-between text-xs">
                                <span class="text-slate-500">Risk score <span class="font-semibold text-slate-900">{{ number_format((float) $case->risk_score, 4) }}</span></span>
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-slate-600">{{ $case->status }}</span>
                            </div>
                        </article>
                    @empty
                        <p class="text-sm text-slate-500">No anomaly cases yet.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $cases->links() }}</div>
            </div>

            <div class="ai-feed-card rounded-2xl p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold text-slate-900">Policy Recommendations</h3>
                    <div class="flex items-center gap-2">
                        @if($stats['recommendations_pending'] > 0)
                            <form method="POST" action="{{ route('admin.behavior-ai.review.approve-all-pending') }}" onsubmit="return confirm('Approve all pending recommendations?');">
                                @csrf
                                <button class="rounded border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">Approve All Pending ({{ $stats['recommendations_pending'] }})</button>
                            </form>
                        @endif
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $recommendations->total() }} total</span>
                    </div>
                </div>
                @error('recommendation_bulk_review')
                    <div class="mb-3 rounded border border-rose-300 bg-rose-50 px-2 py-1 text-xs text-rose-700">{{ $message }}</div>
                @enderror
                <div class="space-y-3">
                    @forelse($recommendations as $recommendation)
                        <article class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium text-slate-900">{{ strtoupper($recommendation->recommended_action) }}</p>
                                    <p class="mt-1 text-xs text-slate-600">Case: <span class="font-mono">{{ $recommendation->anomaly_case_id }}</span></p>
                                    @if(($recommendation->case_summary_display ?? null) !== null)
                                        <p class="mt-1 text-xs text-slate-700">What happened: <span class="font-medium text-slate-900">{{ $recommendation->case_summary_display }}</span></p>
                                    @endif
                                </div>
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">Rank #{{ $recommendation->rank }}</span>
                            </div>

                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <div>
                                    <p class="ai-kv-label">Device</p>
                                    <p class="text-xs text-slate-700">
                                        @if(($recommendation->case_device_name_display ?? null) !== null)
                                            <span class="font-semibold text-slate-800">{{ $recommendation->case_device_name_display }}</span>
                                            <span class="text-slate-400">|</span>
                                        @endif
                                        <span class="font-mono">{{ $recommendation->case_device_id_display ?? 'unknown-device' }}</span>
                                    </p>
                                </div>
                                <div>
                                    <p class="ai-kv-label">Event</p>
                                    <p class="text-xs text-slate-700">
                                        <span class="font-medium">{{ $recommendation->case_event_type_display ?? 'unknown' }}</span>
                                        @if(($recommendation->case_app_name_display ?? null) !== null)
                                            <span class="text-slate-400">|</span>
                                            <span class="font-semibold text-slate-800">{{ $recommendation->case_app_name_display }}</span>
                                        @elseif(($recommendation->case_process_name_display ?? null) !== null)
                                            <span class="text-slate-400">|</span>
                                            <span class="font-mono text-[11px]">{{ $recommendation->case_process_name_display }}</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                            @if(($recommendation->case_user_name_display ?? null) !== null)
                                <p class="mt-1 text-xs text-slate-600">User: <span class="font-medium">{{ $recommendation->case_user_name_display }}</span></p>
                            @endif
                            @if(($recommendation->case_file_path_display ?? null) !== null)
                                <p class="mt-1 text-xs text-slate-600">File Path: <span class="font-mono text-[11px]">{{ $recommendation->case_file_path_display }}</span></p>
                            @endif
                            @if(! empty($recommendation->case_pattern_hints_display ?? []))
                                <p class="mt-1 text-xs text-slate-600">Patterns: <span class="text-slate-800">{{ implode(' | ', $recommendation->case_pattern_hints_display) }}</span></p>
                            @endif
                            @if($recommendation->policy_version_id)
                                <p class="mt-1 text-xs text-slate-600">Policy version: <span class="font-mono">{{ $recommendation->policy_version_id }}</span></p>
                            @endif

                            <div class="mt-2 flex items-center justify-between text-xs">
                                <span class="text-slate-500">Score <span class="font-semibold text-slate-900">{{ number_format((float) $recommendation->score, 4) }}</span></span>
                                <span class="rounded-full px-2 py-1 text-xs {{ in_array($recommendation->status, ['applied','auto_applied'], true) ? 'bg-emerald-100 text-emerald-700' : ($recommendation->status === 'rejected' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ $recommendation->status }}</span>
                            </div>

                            @if($recommendation->status === 'pending')
                                <form method="POST" action="{{ route('admin.behavior-ai.review', $recommendation->id) }}" class="mt-3 rounded border border-slate-200 bg-slate-50 p-2 space-y-2">
                                    @csrf
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <select name="decision" class="rounded border border-slate-300 px-2 py-1.5 text-xs" required>
                                            <option value="approved">Approve</option>
                                            <option value="rejected">Reject</option>
                                            <option value="edited">Approve with edited policy</option>
                                            <option value="false_positive">Mark false positive</option>
                                            <option value="false_negative">Mark false negative</option>
                                        </select>
                                        <input type="text" name="selected_policy_version_id" placeholder="Policy version UUID (optional)" class="rounded border border-slate-300 px-2 py-1.5 text-xs" />
                                    </div>
                                    <textarea name="note" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-xs" placeholder="Reviewer note"></textarea>
                                    <button class="rounded bg-ink px-3 py-1.5 text-xs font-semibold text-white">Submit Review</button>
                                </form>
                            @else
                                <p class="mt-2 text-xs text-slate-500">Reviewed at {{ $recommendation->reviewed_at ?? 'N/A' }} by {{ $recommendation->reviewed_by ?? 'system' }}</p>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-slate-500">No recommendations generated yet.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $recommendations->links() }}</div>
            </div>
        </section>
    </div>
    <script>
        (function () {
            const root = document.querySelector('.ai-shell');
            const stateEl = document.getElementById('ai-auto-refresh-state');
            const intervalEl = document.getElementById('ai-auto-refresh-interval');
            const toggleEl = document.getElementById('ai-auto-refresh-toggle');
            const nowEl = document.getElementById('ai-auto-refresh-now');
            const syncEl = document.getElementById('ai-auto-refresh-last-sync');
            if (!root || !stateEl || !intervalEl || !toggleEl || !nowEl || !syncEl) {
                return;
            }

            const keyPrefix = 'behavior.ai.autorefresh.';
            const keyEnabled = keyPrefix + 'enabled';
            const keyInterval = keyPrefix + 'interval';
            const allowedIntervals = [15, 30, 60, 120];

            let enabled = localStorage.getItem(keyEnabled);
            enabled = enabled === null ? true : enabled === '1';
            let intervalSec = Number(localStorage.getItem(keyInterval) || '30');
            if (!allowedIntervals.includes(intervalSec)) {
                intervalSec = 30;
            }
            let dirtyForm = false;
            let nextRefreshAt = Date.now() + intervalSec * 1000;
            intervalEl.value = String(intervalSec);

            const forms = Array.from(root.querySelectorAll('form'));
            forms.forEach(function (form) {
                form.addEventListener('input', function () {
                    dirtyForm = true;
                });
                form.addEventListener('submit', function () {
                    dirtyForm = false;
                });
            });

            function isEditing() {
                const active = document.activeElement;
                if (!active) return false;
                if (!(active instanceof HTMLElement)) return false;
                const tag = (active.tagName || '').toUpperCase();
                return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
            }

            function formatTime(date) {
                return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }

            function renderState(remainingSec, pausedReason) {
                if (!enabled) {
                    stateEl.textContent = 'Auto-update: paused';
                    stateEl.className = 'ai-live-chip rounded-full px-3 py-1 text-slate-700';
                    toggleEl.textContent = 'Resume';
                    return;
                }

                if (pausedReason !== '') {
                    stateEl.textContent = 'Auto-update: ' + pausedReason;
                    stateEl.className = 'ai-live-chip rounded-full px-3 py-1 text-amber-700';
                    toggleEl.textContent = 'Pause';
                    return;
                }

                stateEl.textContent = 'Auto-update in ' + remainingSec + 's';
                stateEl.className = 'ai-live-chip rounded-full px-3 py-1 text-emerald-700';
                toggleEl.textContent = 'Pause';
            }

            function refreshNow() {
                localStorage.setItem(keyEnabled, enabled ? '1' : '0');
                localStorage.setItem(keyInterval, String(intervalSec));
                window.location.reload();
            }

            intervalEl.addEventListener('change', function () {
                const candidate = Number(intervalEl.value || '30');
                intervalSec = allowedIntervals.includes(candidate) ? candidate : 30;
                localStorage.setItem(keyInterval, String(intervalSec));
                nextRefreshAt = Date.now() + intervalSec * 1000;
            });

            toggleEl.addEventListener('click', function () {
                enabled = !enabled;
                localStorage.setItem(keyEnabled, enabled ? '1' : '0');
                if (enabled) {
                    nextRefreshAt = Date.now() + intervalSec * 1000;
                }
            });

            nowEl.addEventListener('click', refreshNow);
            syncEl.textContent = 'Last sync: ' + formatTime(new Date());

            setInterval(function () {
                const pausedByEditing = isEditing() || dirtyForm;
                const pausedReason = pausedByEditing ? 'paused while editing' : '';
                const remaining = Math.max(0, Math.ceil((nextRefreshAt - Date.now()) / 1000));
                renderState(remaining, pausedReason);

                if (!enabled || document.hidden) {
                    return;
                }

                if (Date.now() >= nextRefreshAt) {
                    if (pausedByEditing) {
                        nextRefreshAt = Date.now() + 10 * 1000;
                        return;
                    }
                    refreshNow();
                }
            }, 1000);
        })();
    </script>
</x-admin-layout>
