<x-admin-layout title="Job Detail" heading="Job Detail">
    @php
        $extractConsoleText = static function ($payload, array $paths): string {
            if (!is_array($payload)) {
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
            if (!is_array($payload)) {
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
    @endphp
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Job Type</p>
                <h3 class="text-xl font-semibold">{{ $job->job_type }}</h3>
                <p class="mt-1 text-xs font-mono text-slate-500">{{ $job->id }}</p>
            </div>
            <a href="{{ route('admin.jobs') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 hover:bg-slate-50">Back to Jobs</a>
        </div>

        <div class="grid gap-2 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Target</p>
                <p class="text-xs font-medium text-slate-900">{{ $job->target_type }}</p>
                <p class="truncate font-mono text-[11px] text-slate-500">{{ $job->target_id }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Status</p>
                <p class="text-sm font-semibold text-slate-900">{{ $job->status }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Priority</p>
                <p class="text-sm font-semibold text-slate-900">{{ $job->priority }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Created</p>
                <p class="text-xs font-medium text-slate-900">{{ $job->created_at }}</p>
            </div>
        </div>

        <div>
            <p class="mb-2 text-sm font-semibold">Payload</p>
            <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">{{ json_encode($job->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h4 class="mb-3 text-lg font-semibold">Run Outcomes</h4>
        <div class="space-y-3">
            @forelse($runs as $run)
                @php
                    $payload = is_array($run->result_payload) ? $run->result_payload : [];
                    $stdout = $extractConsoleText($payload, ['result.StdOut', 'StdOut', 'stdout', 'output']);
                    $stderr = $extractConsoleText($payload, ['result.StdErr', 'StdErr', 'stderr', 'error_output']);
                @endphp
                @php
                    $resultError = $extractValue($payload, ['error', 'result.error', 'message', 'result.message']);
                    $rollbackAttempted = $extractValue($payload, ['rollback_attempted']);
                    $rollbackExitCode = $extractValue($payload, ['rollback_exit_code']);
                    $rollbackStdErr = $extractConsoleText($payload, ['rollback_stderr', 'result.rollback_stderr']);
                    $errorHeadline = trim((string) ($run->last_error ?: $resultError));
                    $isFailed = strtolower((string) ($run->status ?? '')) === 'failed';
                @endphp
                <details class="rounded-xl border border-slate-200 bg-slate-50/60 p-3" {{ $isFailed ? 'open' : '' }}>
                    <summary class="cursor-pointer list-none">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <div>
                                <p class="font-mono text-xs">{{ $run->id }}</p>
                                <p class="font-medium">Device: {{ $deviceNames[$run->device_id] ?? 'Unknown' }} <span class="text-slate-500 font-mono">({{ $run->device_id }})</span></p>
                            </div>
                            <div class="text-right">
                                <p>
                                    Status:
                                    @if($isFailed)
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">{{ $run->status }}</span>
                                    @elseif(strtolower((string) $run->status) === 'success')
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ $run->status }}</span>
                                    @else
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ $run->status }}</span>
                                    @endif
                                    | Attempt: {{ $run->attempt_count ?? 0 }}
                                </p>
                                <p class="text-xs text-slate-500">Updated: {{ $run->updated_at }}</p>
                            </div>
                        </div>
                    </summary>

                    @if($isFailed)
                        <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs">
                            <p class="font-semibold text-red-800">Failure Diagnostics</p>
                            <p class="mt-1 text-red-700">{{ $errorHeadline !== '' ? $errorHeadline : 'No explicit error message was captured.' }}</p>
                            <div class="mt-2 grid gap-1 text-red-800 sm:grid-cols-2">
                                <p><span class="font-semibold">Run ID:</span> <span class="font-mono">{{ $run->id }}</span></p>
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
                                    <p class="mb-1 font-semibold text-red-800">Rollback StdErr</p>
                                    <pre class="max-h-44 overflow-auto rounded border border-red-200 bg-white p-2 whitespace-pre-wrap text-[11px] text-red-900">{{ $rollbackStdErr }}</pre>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="mt-3 grid gap-3 text-xs lg:grid-cols-2">
                        <div class="space-y-1">
                            <p>Acked: {{ $run->acked_at ?: '-' }}</p>
                            <p>Started: {{ $run->started_at ?: '-' }}</p>
                            <p>Finished: {{ $run->finished_at ?: '-' }}</p>
                            <p>Next Retry: {{ $run->next_retry_at ?: '-' }}</p>
                            <p>Exit Code: {{ $run->exit_code ?? '-' }}</p>
                            <p>Last Error: {{ $run->last_error ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="font-semibold mb-1">Result Payload</p>
                            <pre class="max-h-56 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-white p-2">{{ json_encode($run->result_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3 text-xs lg:grid-cols-2">
                        <div>
                            <p class="font-semibold mb-1">Console StdOut</p>
                            <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-white p-2">{{ $stdout !== '' ? $stdout : '-' }}</pre>
                        </div>
                        <div>
                            <p class="font-semibold mb-1">Console StdErr</p>
                            <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-white p-2">{{ $stderr !== '' ? $stderr : '-' }}</pre>
                        </div>
                    </div>
                    <div class="mt-3">
                        <p class="font-semibold text-xs mb-1">Events</p>
                        <div class="space-y-2 max-h-48 overflow-auto">
                            @forelse(($eventsByRun[$run->id] ?? collect()) as $event)
                                <div class="rounded border border-slate-200 bg-white p-2 text-xs">
                                    <p>{{ $event->event_type }} | {{ $event->created_at }}</p>
                                    <pre class="mt-1 overflow-auto whitespace-pre-wrap break-all rounded border border-slate-200 bg-slate-50 p-2">{{ json_encode($event->event_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            @empty
                                <p class="text-xs text-slate-500">No events for this run.</p>
                            @endforelse
                        </div>
                    </div>
                </details>
            @empty
                <p class="text-sm text-slate-500">No runs found for this job.</p>
            @endforelse
        </div>
    </div>
</x-admin-layout>
