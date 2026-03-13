<x-admin-layout title="Autonomous Remediation" heading="Autonomous Remediation">
    @php
        $settings = is_array($remediation['settings'] ?? null) ? $remediation['settings'] : [];
        $stats = is_array($remediation['stats'] ?? null) ? $remediation['stats'] : [];
        $filters = is_array($remediation['filters'] ?? null) ? $remediation['filters'] : [];
        $actionOptions = is_array($remediation['action_options'] ?? null) ? $remediation['action_options'] : [];
        $tablesReady = (bool) ($remediation['tables_ready'] ?? false);
        $enabled = (bool) ($settings['enabled'] ?? false);
    @endphp

    <div class="space-y-4">
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Behavior Intelligence</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">Autonomous Remediation Engine</h2>
                    <p class="mt-1 text-sm text-slate-600">Automatically executes approved corrective actions when anomaly risk and policy triggers are met.</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs {{ $enabled ? 'bg-rose-100 text-rose-700' : 'bg-slate-200 text-slate-700' }}">
                    {{ $enabled ? 'Engine Enabled' : 'Engine Disabled' }}
                </span>
            </div>
            @if(! $tablesReady)
                <p class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    Remediation execution tables are not available yet. Run database migrations first.
                </p>
            @endif
        </section>

        <section class="grid gap-4 xl:grid-cols-12">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 xl:col-span-4">
                <h3 class="font-semibold text-slate-900">Remediation Settings</h3>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Actions (7d)</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ (int) ($stats['actions_7d'] ?? 0) }}</p>
                        <p class="text-xs text-slate-500">24h {{ (int) ($stats['actions_24h'] ?? 0) }} | devices {{ (int) ($stats['devices_7d'] ?? 0) }}</p>
                    </div>
                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">Execution Health</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ (int) ($stats['job_active'] ?? 0) }}</p>
                        <p class="text-xs text-slate-500">active | failed {{ (int) (($stats['dispatch_failed_7d'] ?? 0) + ($stats['job_failed_7d'] ?? 0)) }}</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.behavior-remediation.settings') }}" class="mt-4 space-y-3">
                    @csrf

                    <input type="hidden" name="remediation_enabled" value="0" />
                    <label class="flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="remediation_enabled" value="1" @checked($enabled) class="rounded border-slate-300 text-skyline focus:ring-skyline" />
                        Enable autonomous remediation
                    </label>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="text-xs text-slate-600">
                            Minimum risk score
                            <input type="number" step="0.01" min="0.50" max="0.99" name="min_risk" value="{{ number_format((float) ($settings['min_risk'] ?? 0.90), 2, '.', '') }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="text-xs text-slate-600">
                            Max actions per case
                            <input type="number" min="1" max="6" name="max_actions_per_case" value="{{ (int) ($settings['max_actions_per_case'] ?? 2) }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                    </div>

                    <div class="rounded border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-semibold text-slate-700">Action policy toggles</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach([
                                ['name' => 'allow_force_scan', 'label' => 'Force system scan'],
                                ['name' => 'allow_emergency_profile', 'label' => 'Apply emergency profile'],
                                ['name' => 'allow_isolate_network', 'label' => 'Isolate device network'],
                                ['name' => 'allow_kill_process', 'label' => 'Kill suspicious process'],
                                ['name' => 'allow_uninstall_software', 'label' => 'Uninstall suspicious software'],
                                ['name' => 'allow_rollback_policy', 'label' => 'Rollback policy state'],
                            ] as $toggle)
                                <label class="flex items-center gap-2 text-xs text-slate-700">
                                    <input type="hidden" name="{{ $toggle['name'] }}" value="0" />
                                    <input type="checkbox" name="{{ $toggle['name'] }}" value="1" @checked((bool) ($settings[$toggle['name']] ?? false)) class="rounded border-slate-300 text-skyline focus:ring-skyline" />
                                    {{ $toggle['label'] }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <label class="text-xs text-slate-600">
                        Emergency security profile (policy version)
                        <select name="emergency_policy_version_id" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm">
                            <option value="">No automatic emergency profile</option>
                            @foreach($remediation['emergency_policies'] as $policyVersion)
                                @php
                                    $policyId = (string) ($policyVersion['id'] ?? '');
                                    $policyName = (string) ($policyVersion['policy_name'] ?? 'Unknown');
                                    $policyVersionNumber = (int) ($policyVersion['version_number'] ?? 0);
                                    $policyStatus = (string) ($policyVersion['status'] ?? 'unknown');
                                @endphp
                                <option value="{{ $policyId }}" @selected(((string) ($settings['emergency_policy_version_id'] ?? '')) === $policyId)>
                                    {{ $policyName }} v{{ $policyVersionNumber }} ({{ $policyStatus }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="text-xs text-slate-600">
                        Force scan command
                        <input type="text" name="scan_command" value="{{ (string) ($settings['scan_command'] ?? '') }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                    </label>

                    <label class="text-xs text-slate-600">
                        Isolate command
                        <input type="text" name="isolate_command" value="{{ (string) ($settings['isolate_command'] ?? '') }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                    </label>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="text-xs text-slate-600">
                            Rollback restore-point description
                            <input type="text" name="rollback_restore_point_description" value="{{ (string) ($settings['rollback_restore_point_description'] ?? '') }}" class="mt-1.5 w-full rounded border border-slate-300 px-2.5 py-2 text-sm" />
                        </label>
                        <label class="flex items-end gap-2 pb-2 text-xs text-slate-700">
                            <input type="hidden" name="rollback_reboot_now" value="0" />
                            <input type="checkbox" name="rollback_reboot_now" value="1" @checked((bool) ($settings['rollback_reboot_now'] ?? true)) class="rounded border-slate-300 text-skyline focus:ring-skyline" />
                            Reboot immediately after rollback
                        </label>
                    </div>

                    <button class="rounded bg-skyline px-3 py-1.5 text-xs font-semibold text-white">Save Remediation Settings</button>
                </form>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 xl:col-span-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">Recent Remediation Actions</h3>
                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $remediation['executions']->total() }} total</span>
                </div>

                <form method="GET" class="mt-3 grid gap-2 sm:grid-cols-4">
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search by action, case, device, job" class="rounded border border-slate-300 px-2 py-2 text-sm sm:col-span-2" />
                    <select name="action" class="rounded border border-slate-300 px-2 py-2 text-sm">
                        <option value="">Action type</option>
                        @foreach($actionOptions as $actionKey => $actionLabel)
                            <option value="{{ $actionKey }}" @selected(($filters['action'] ?? '') === $actionKey)>{{ $actionLabel }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded border border-slate-300 px-2 py-2 text-sm">
                        <option value="">Execution status</option>
                        @foreach(['queued','running','success','failed','dispatch_failed'] as $statusKey)
                            <option value="{{ $statusKey }}" @selected(($filters['status'] ?? '') === $statusKey)>{{ $statusKey }}</option>
                        @endforeach
                    </select>
                    <button class="rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700 sm:col-span-4">Filter Actions</button>
                </form>

                <div class="mt-3 space-y-2.5">
                    @forelse($remediation['executions'] as $execution)
                        @php
                            $effectiveStatus = (string) ($execution->dispatched_job_status ?: $execution->status);
                            $statusTone = match ($effectiveStatus) {
                                'success' => 'bg-emerald-100 text-emerald-700',
                                'failed' => 'bg-rose-100 text-rose-700',
                                'running' => 'bg-amber-100 text-amber-700',
                                'dispatch_failed' => 'bg-rose-100 text-rose-700',
                                default => 'bg-slate-200 text-slate-700',
                            };
                        @endphp
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">
                                        {{ $actionOptions[(string) $execution->remediation_key] ?? (string) $execution->remediation_key }}
                                        <span class="text-slate-400">|</span>
                                        <span class="font-mono text-[11px] text-slate-600">{{ $execution->id }}</span>
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Device {{ $execution->device_hostname ?: 'unknown' }}
                                        <span class="text-slate-400">|</span>
                                        <span class="font-mono">{{ $execution->device_id }}</span>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <span class="rounded-full px-2 py-1 text-xs {{ $statusTone }}">{{ $effectiveStatus }}</span>
                                    <p class="mt-1 text-xs text-slate-600">Risk {{ number_format((float) $execution->risk_score, 4) }} | Trigger {{ number_format((float) $execution->trigger_score, 4) }}</p>
                                </div>
                            </div>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <p class="text-xs text-slate-600">Case: <span class="font-mono text-[11px]">{{ $execution->anomaly_case_id ?? 'n/a' }}</span></p>
                                <p class="text-xs text-slate-600">Job: <span class="font-mono text-[11px]">{{ $execution->dispatched_job_id ?? 'not queued' }}</span></p>
                            </div>
                            @if((string) ($execution->reason ?? '') !== '')
                                <p class="mt-2 text-xs text-slate-600">Reason: <span class="text-slate-800">{{ $execution->reason }}</span></p>
                            @endif
                            @if((string) ($execution->failure_reason ?? '') !== '')
                                <p class="mt-1 text-xs text-rose-700">Failure: {{ $execution->failure_reason }}</p>
                            @endif
                            <p class="mt-1 text-xs text-slate-500">Executed {{ $execution->executed_at ? $execution->executed_at->diffForHumans() : 'recently' }}</p>
                        </div>
                    @empty
                        <p class="rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-sm text-slate-500">No remediation executions match the current filter.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $remediation['executions']->links() }}</div>
            </article>
        </section>
    </div>
</x-admin-layout>

