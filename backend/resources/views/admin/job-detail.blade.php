<x-admin-layout title="Job Detail" heading="Job Detail">
    @php
        $extractConsoleText = static function ($payload, array $paths): string {
            if (! is_array($payload)) {
                return '';
            }
            foreach ($paths as $path) {
                $node = $payload;
                $ok = true;
                foreach (explode('.', $path) as $segment) {
                    if (is_array($node) && array_key_exists($segment, $node)) {
                        $node = $node[$segment];
                    } else {
                        $ok = false;
                        break;
                    }
                }
                if ($ok && is_scalar($node)) {
                    $value = trim((string) $node);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
            return '';
        };
        $extractValue = static function ($payload, array $paths): string {
            if (! is_array($payload)) {
                return '';
            }
            foreach ($paths as $path) {
                $node = $payload;
                $ok = true;
                foreach (explode('.', $path) as $segment) {
                    if (is_array($node) && array_key_exists($segment, $node)) {
                        $node = $node[$segment];
                    } else {
                        $ok = false;
                        break;
                    }
                }
                if (! $ok) {
                    continue;
                }
                if (is_bool($node)) {
                    return $node ? 'true' : 'false';
                }
                if (is_scalar($node)) {
                    $value = trim((string) $node);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
            return '';
        };

        $status = strtolower((string) ($job->status ?? ''));
        $statusTone = match ($status) {
            'completed' => 'bg-emerald-100 text-emerald-700',
            'failed', 'non_compliant' => 'bg-rose-100 text-rose-700',
            'running', 'acked' => 'bg-sky-100 text-sky-700',
            default => 'bg-amber-100 text-amber-700',
        };

        $runSummary = [
            'total' => $runs->count(),
            'success' => 0,
            'failed' => 0,
            'active' => 0,
        ];
        foreach ($runs as $r) {
            $rs = strtolower((string) ($r->status ?? ''));
            if ($rs === 'success') {
                $runSummary['success']++;
            } elseif (in_array($rs, ['failed', 'non_compliant'], true)) {
                $runSummary['failed']++;
            } elseif (in_array($rs, ['pending', 'acked', 'running'], true)) {
                $runSummary['active']++;
            }
        }
    @endphp
    <style>
        .jd-shell {
            --jd-border: #d7deea;
            --jd-panel-bg: #ffffff;
            --jd-panel-muted: #f8fafc;
            --jd-panel-strong: #eef2f7;
            --jd-subtle-text: #64748b;
            --jd-heading-text: #0f172a;
            --jd-body-text: #334155;
            --jd-shadow: 0 18px 40px rgba(15, 23, 42, 0.07);
        }

        html[data-theme="dark"] .jd-shell {
            --jd-border: #334155;
            --jd-panel-bg: #0f172a;
            --jd-panel-muted: #111c2d;
            --jd-panel-strong: #162235;
            --jd-subtle-text: #94a3b8;
            --jd-heading-text: #e2e8f0;
            --jd-body-text: #cbd5e1;
            --jd-shadow: 0 24px 60px rgba(2, 6, 23, 0.4);
        }

        .jd-shell .jd-panel,
        .jd-shell .jd-card {
            border: 1px solid var(--jd-border);
            background: var(--jd-panel-bg);
            box-shadow: var(--jd-shadow);
        }

        .jd-shell .jd-block,
        .jd-shell .jd-chip,
        .jd-shell .jd-pre,
        .jd-shell .jd-secondary-btn,
        .jd-shell .jd-run-card,
        .jd-shell .jd-failure {
            border-color: var(--jd-border) !important;
            background: var(--jd-panel-muted) !important;
        }

        .jd-shell .jd-label,
        .jd-shell .jd-mono,
        .jd-shell .jd-empty {
            color: var(--jd-subtle-text) !important;
        }

        html[data-theme="dark"] .jd-shell .text-slate-900 {
            color: var(--jd-heading-text) !important;
        }

        html[data-theme="dark"] .jd-shell .text-slate-800,
        html[data-theme="dark"] .jd-shell .text-slate-700,
        html[data-theme="dark"] .jd-shell .text-slate-600 {
            color: var(--jd-body-text) !important;
        }

        html[data-theme="dark"] .jd-shell .text-slate-500 {
            color: var(--jd-subtle-text) !important;
        }

        html[data-theme="dark"] .jd-shell .bg-white,
        html[data-theme="dark"] .jd-shell .bg-slate-50,
        html[data-theme="dark"] .jd-shell .bg-slate-100 {
            background-color: var(--jd-panel-muted) !important;
        }

        html[data-theme="dark"] .jd-shell .border-slate-200,
        html[data-theme="dark"] .jd-shell .border-slate-300 {
            border-color: var(--jd-border) !important;
        }

        html[data-theme="dark"] .jd-shell .border-rose-200 {
            border-color: rgba(251, 113, 133, 0.35) !important;
        }

        html[data-theme="dark"] .jd-shell .bg-rose-50 {
            background-color: rgba(127, 29, 29, 0.24) !important;
        }

        html[data-theme="dark"] .jd-shell .text-rose-900 {
            color: #fecdd3 !important;
        }

        html[data-theme="dark"] .jd-shell .text-rose-800,
        html[data-theme="dark"] .jd-shell .text-rose-700 {
            color: #fda4af !important;
        }

        html[data-theme="dark"] .jd-shell .hover\:bg-slate-50:hover {
            background-color: var(--jd-panel-strong) !important;
        }
    </style>
<div class="jd-shell space-y-4">
        <section class="jd-panel rounded-2xl p-5 jd-reveal">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Execution Forensics</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ $job->job_type }}</h2>
                    <p class="mt-1 jd-mono text-xs text-slate-500">{{ $job->id }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-xs {{ $statusTone }}">{{ $job->status }}</span>
                    <form method="POST" action="{{ route('admin.jobs.rerun', $job->id) }}" onsubmit="return confirm('Re-run this job with the same payload and target?');">
                        @csrf
                        <button class="rounded-lg bg-skyline px-3 py-2 text-xs text-white">Re-run Job</button>
                    </form>
                    <a href="{{ route('admin.jobs') }}" class="jd-secondary-btn rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 hover:bg-slate-50">Back to Jobs</a>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5 jd-reveal">
            <article class="jd-card rounded-xl p-3">
                <p class="jd-label">Target Type</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ $job->target_type }}</p>
            </article>
            <article class="jd-card rounded-xl p-3">
                <p class="jd-label">Priority</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ $job->priority }}</p>
            </article>
            <article class="jd-card rounded-xl p-3">
                <p class="jd-label">Runs Total</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">{{ $runSummary['total'] }}</p>
            </article>
            <article class="jd-card rounded-xl p-3">
                <p class="jd-label">Runs Success</p>
                <p class="mt-1 text-sm font-semibold text-emerald-700">{{ $runSummary['success'] }}</p>
            </article>
            <article class="jd-card rounded-xl p-3">
                <p class="jd-label">Runs Failed</p>
                <p class="mt-1 text-sm font-semibold text-rose-700">{{ $runSummary['failed'] }}</p>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-3 jd-reveal">
            <div class="jd-panel rounded-2xl p-4 xl:col-span-1">
                <h3 class="font-semibold text-slate-900">Job Metadata</h3>
                <div class="mt-3 space-y-3 text-sm">
                    <div>
                        <p class="jd-label">Target ID</p>
                        <p class="jd-mono mt-1 text-xs text-slate-700 break-all">{{ $job->target_id }}</p>
                    </div>
                    <div>
                        <p class="jd-label">Created</p>
                        <p class="mt-1 text-slate-800">{{ $job->created_at }}</p>
                    </div>
                    <div>
                        <p class="jd-label">Updated</p>
                        <p class="mt-1 text-slate-800">{{ $job->updated_at }}</p>
                    </div>
                    <div>
                        <p class="jd-label">Current Status</p>
                        <p class="mt-1"><span class="rounded-full px-2 py-1 text-xs {{ $statusTone }}">{{ $job->status }}</span></p>
                    </div>
                </div>
            </div>
            <div class="jd-panel rounded-2xl p-4 xl:col-span-2">
                <h3 class="font-semibold text-slate-900">Payload</h3>
                <p class="mt-1 text-xs text-slate-500">Original payload dispatched with this job.</p>
                <pre class="jd-pre mt-3 max-h-[24rem] overflow-auto whitespace-pre-wrap break-all rounded-xl border border-slate-200 bg-white p-3 text-xs">{{ json_encode($job->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </section>

        <section class="jd-panel rounded-2xl p-5 jd-reveal">
            <div class="mb-3 flex items-center justify-between gap-2">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Run Outcomes</h3>
                    <p class="text-xs text-slate-500">Detailed execution timeline per target endpoint.</p>
                </div>
                <span class="jd-chip rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $runs->count() }} runs</span>
            </div>
            <div class="space-y-3">
                @forelse($runs as $run)
                    @php
                        $payload = is_array($run->result_payload) ? $run->result_payload : [];
                        $stdout = $extractConsoleText($payload, ['result.StdOut', 'StdOut', 'stdout', 'output']);
                        $stderr = $extractConsoleText($payload, ['result.StdErr', 'StdErr', 'stderr', 'error_output']);
                        $resultError = $extractValue($payload, ['error', 'result.error', 'message', 'result.message']);
                        $rollbackAttempted = $extractValue($payload, ['rollback_attempted']);
                        $rollbackExitCode = $extractValue($payload, ['rollback_exit_code']);
                        $rollbackStdErr = $extractConsoleText($payload, ['rollback_stderr', 'result.rollback_stderr']);
                        $errorHeadline = trim((string) ($run->last_error ?: $resultError));
                        $runStatus = strtolower((string) ($run->status ?? ''));
                        $isFailed = in_array($runStatus, ['failed', 'non_compliant'], true);
                        $runTone = match ($runStatus) {
                            'success' => 'bg-emerald-100 text-emerald-700',
                            'failed', 'non_compliant' => 'bg-rose-100 text-rose-700',
                            'running', 'acked' => 'bg-sky-100 text-sky-700',
                            default => 'bg-amber-100 text-amber-700',
                        };
                    @endphp

                    <details class="jd-run-card rounded-xl border border-slate-200 bg-white p-3" {{ $isFailed ? 'open' : '' }}>
                        <summary class="list-none cursor-pointer">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="jd-mono text-xs text-slate-500">{{ $run->id }}</p>
                                    <p class="mt-1 text-sm font-medium text-slate-900">
                                        {{ $deviceNames[$run->device_id] ?? 'Unknown Device' }}
                                        <span class="jd-mono text-xs text-slate-500">({{ $run->device_id }})</span>
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">Attempt {{ $run->attempt_count ?? 0 }} | Updated {{ $run->updated_at }}</p>
                                </div>
                                <div class="text-right">
                                    <p><span class="rounded-full px-2 py-1 text-xs {{ $runTone }}">{{ $run->status }}</span></p>
                                    @if($isFailed)
                                        <form method="POST" action="{{ route('admin.job-runs.rerun', $run->id) }}" class="mt-2" onsubmit="return confirm('Re-run this failed run for this device?');">
                                            @csrf
                                            <button class="rounded bg-skyline px-2 py-1 text-[11px] text-white">Re-run This Run</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </summary>

                        @if($isFailed)
                            <div class="jd-failure mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs">
                                <p class="font-semibold text-rose-800">Failure Diagnostics</p>
                                <p class="mt-1 text-rose-700">{{ $errorHeadline !== '' ? $errorHeadline : 'No explicit error message was captured.' }}</p>
                                <div class="mt-2 grid gap-1 text-rose-800 sm:grid-cols-2">
                                    <p><span class="font-semibold">Exit Code:</span> {{ $run->exit_code ?? '-' }}</p>
                                    <p><span class="font-semibold">Started:</span> {{ $run->started_at ?: '-' }}</p>
                                    <p><span class="font-semibold">Finished:</span> {{ $run->finished_at ?: '-' }}</p>
                                    <p><span class="font-semibold">Next Retry:</span> {{ $run->next_retry_at ?: '-' }}</p>
                                    <p><span class="font-semibold">Rollback Attempted:</span> {{ $rollbackAttempted !== '' ? $rollbackAttempted : '-' }}</p>
                                    @if($rollbackExitCode !== '')
                                        <p><span class="font-semibold">Rollback Exit Code:</span> {{ $rollbackExitCode }}</p>
                                    @endif
                                </div>
                                @if($rollbackStdErr !== '')
                                    <div class="mt-2">
                                        <p class="mb-1 font-semibold text-rose-800">Rollback StdErr</p>
                                        <pre class="jd-pre max-h-44 overflow-auto whitespace-pre-wrap rounded border border-rose-200 bg-white p-2 text-[11px] text-rose-900">{{ $rollbackStdErr }}</pre>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="mt-3 grid gap-3 text-xs lg:grid-cols-2">
                            <div class="jd-block rounded-lg border border-slate-200 bg-slate-50 p-2">
                                <p><span class="font-semibold">Acked:</span> {{ $run->acked_at ?: '-' }}</p>
                                <p><span class="font-semibold">Started:</span> {{ $run->started_at ?: '-' }}</p>
                                <p><span class="font-semibold">Finished:</span> {{ $run->finished_at ?: '-' }}</p>
                                <p><span class="font-semibold">Next Retry:</span> {{ $run->next_retry_at ?: '-' }}</p>
                                <p><span class="font-semibold">Exit Code:</span> {{ $run->exit_code ?? '-' }}</p>
                                <p><span class="font-semibold">Last Error:</span> {{ $run->last_error ?: '-' }}</p>
                            </div>
                            <div>
                                <p class="mb-1 font-semibold">Result Payload</p>
                                <pre class="jd-pre max-h-56 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-white p-2">{{ json_encode($run->result_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-3 text-xs lg:grid-cols-2">
                            <div>
                                <p class="mb-1 font-semibold">Console StdOut</p>
                                <pre class="jd-pre max-h-72 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-white p-2">{{ $stdout !== '' ? $stdout : '-' }}</pre>
                            </div>
                            <div>
                                <p class="mb-1 font-semibold">Console StdErr</p>
                                <pre class="jd-pre max-h-72 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-white p-2">{{ $stderr !== '' ? $stderr : '-' }}</pre>
                            </div>
                        </div>

                        <div class="mt-3">
                            <p class="mb-1 text-xs font-semibold">Events</p>
                            <div class="max-h-52 space-y-2 overflow-auto">
                                @forelse(($eventsByRun[$run->id] ?? collect()) as $event)
                                    <div class="jd-block rounded border border-slate-200 bg-slate-50 p-2 text-xs">
                                        <p>{{ $event->event_type }} | {{ $event->created_at }}</p>
                                        <pre class="jd-pre mt-1 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-white p-2">{{ json_encode($event->event_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                @empty
                                    <p class="jd-empty text-xs text-slate-500">No events for this run.</p>
                                @endforelse
                            </div>
                        </div>
                    </details>
                @empty
                    <p class="jd-empty text-sm text-slate-500">No runs found for this job.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-admin-layout>
