<x-admin-layout title="Jobs" heading="Job Dispatch Center">
    @php
        $totalJobs = (int) $jobs->total();
        $statusCounts = [
            'pending' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'acked' => 0,
            'non_compliant' => 0,
            'cancelled' => 0,
        ];

        foreach ($jobs as $job) {
            $status = strtolower((string) ($job->status ?? ''));
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status]++;
            }
        }

        $activeJobs = $statusCounts['pending'] + $statusCounts['running'] + $statusCounts['acked'];
        $healthLabel = $statusCounts['failed'] === 0 && $statusCounts['non_compliant'] === 0
            ? 'stable'
            : (($statusCounts['failed'] + $statusCounts['non_compliant']) < max(3, (int) ceil($totalJobs * 0.15)) ? 'watch' : 'degraded');
        $healthTone = $healthLabel === 'stable'
            ? 'bg-emerald-100 text-emerald-700'
            : ($healthLabel === 'watch' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700');
        $deviceNameMap = collect($devices ?? [])->pluck('hostname', 'id');
        $groupNameMap = collect($groups ?? [])->pluck('name', 'id');
    @endphp

    <style>
        .jobs-shell {
            --jobs-border: #d8e1ef;
            --jobs-card: #ffffff;
            --jobs-metric-card: #f8fafc;
            --jobs-shadow: 0 10px 24px rgba(15, 23, 42, 0.07);
            --jobs-soft-shadow: 0 6px 14px rgba(15, 23, 42, 0.05);
        }
        .jobs-shell .jobs-panel {
            border: 1px solid var(--jobs-border);
            background: var(--jobs-card);
            box-shadow: var(--jobs-shadow);
        }
        .jobs-shell .jobs-card {
            border: 1px solid var(--jobs-border);
            background: #fff;
            box-shadow: var(--jobs-soft-shadow);
        }
        .jobs-shell .jobs-metric {
            border: 1px solid var(--jobs-border);
            background: var(--jobs-metric-card);
        }
        .jobs-shell .jobs-mono {
            font-family: "IBM Plex Mono", monospace;
        }
        .jobs-shell .jobs-kv {
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
        }
        .jobs-shell .jobs-reveal {
            animation: jobsFadeIn 340ms ease both;
        }
        @keyframes jobsFadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>

    <div class="jobs-shell space-y-4">
        <section class="jobs-panel rounded-2xl p-5 jobs-reveal">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Operations Orchestration</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">Remote Jobs Command Center</h2>
                    <p class="mt-1 text-sm text-slate-600">Dispatch endpoint actions, monitor execution states, and manage retry posture from one workspace.</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-slate-700">Kill switch: {{ $ops['kill_switch'] ? 'enabled' : 'disabled' }}</span>
                    <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-slate-700">Retry: {{ $ops['max_retries'] }} attempts</span>
                    <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-slate-700">Backoff: {{ $ops['base_backoff_seconds'] }}s</span>
                    <span class="rounded-full px-3 py-1 {{ $healthTone }}">Health: {{ $healthLabel }}</span>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5 jobs-reveal">
            <article class="jobs-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Jobs in View</p>
                <p class="mt-1 text-3xl font-bold text-slate-900 jobs-mono">{{ $totalJobs }}</p>
                <p class="mt-1 text-xs text-slate-500">Current paginated list total.</p>
            </article>
            <article class="jobs-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Active</p>
                <p class="mt-1 text-3xl font-bold text-sky-700 jobs-mono">{{ $activeJobs }}</p>
                <p class="mt-1 text-xs text-slate-500">Pending + running + acked.</p>
            </article>
            <article class="jobs-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Completed</p>
                <p class="mt-1 text-3xl font-bold text-emerald-700 jobs-mono">{{ $statusCounts['completed'] }}</p>
                <p class="mt-1 text-xs text-slate-500">Successfully finished jobs.</p>
            </article>
            <article class="jobs-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Failed</p>
                <p class="mt-1 text-3xl font-bold text-rose-700 jobs-mono">{{ $statusCounts['failed'] + $statusCounts['non_compliant'] }}</p>
                <p class="mt-1 text-xs text-slate-500">Failed and non-compliant jobs.</p>
            </article>
            <article class="jobs-metric rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Script Hashes</p>
                <p class="mt-1 text-3xl font-bold text-slate-900 jobs-mono">{{ count($ops['allowed_script_hashes']) }}</p>
                <p class="mt-1 text-xs text-slate-500">Allowlisted command hashes.</p>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-3 jobs-reveal">
            <div class="jobs-card rounded-2xl p-4">
                <h3 class="font-semibold text-slate-900">Queue New Job</h3>
                <p class="mt-1 text-xs text-slate-500">Fill required fields, apply a quick template, then dispatch.</p>

                <form method="POST" action="{{ route('admin.jobs.create') }}" class="mt-4 space-y-3">
                    @csrf
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Job Type</label>
                            <select id="job-type-select" name="job_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="install_package">install_package</option>
                                <option value="uninstall_package">uninstall_package</option>
                                <option value="install_msi">install_msi</option>
                                <option value="uninstall_msi">uninstall_msi</option>
                                <option value="install_exe">install_exe</option>
                                <option value="install_custom">install_custom</option>
                                <option value="uninstall_exe">uninstall_exe</option>
                                <option value="apply_policy">apply_policy</option>
                                <option value="run_command">run_command</option>
                                <option value="create_snapshot">create_snapshot</option>
                                <option value="restore_snapshot">restore_snapshot</option>
                                <option value="update_agent">update_agent</option>
                                <option value="uninstall_agent">uninstall_agent</option>
                                <option value="reconcile_software_inventory">reconcile_software_inventory</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Target Type</label>
                            <select name="target_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="device">device</option>
                                <option value="group">group</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Target</label>
                        <select name="target_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="" data-kind="all">Select target</option>
                            @foreach($devices as $device)
                                <option value="{{ $device->id }}" data-kind="device">Device: {{ $device->hostname }}</option>
                            @endforeach
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}" data-kind="group">Group: {{ $group->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Priority</label>
                            <input name="priority" type="number" min="1" max="1000" value="100" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Stagger Seconds</label>
                            <input name="stagger_seconds" type="number" min="0" max="3600" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="0 = immediate" />
                        </div>
                    </div>

                    <div id="run-command-options" class="space-y-3">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Run As (run_command)</label>
                                <select id="run-command-runas" name="run_as" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="default" selected>default (agent context)</option>
                                    <option value="elevated">elevated (admin)</option>
                                    <option value="system">system</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Timeout Seconds (run_command)</label>
                                <input id="run-command-timeout" name="timeout_seconds" type="number" min="30" max="3600" value="300" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Payload JSON</label>
                        <textarea id="payload-json-input" name="payload_json" class="w-full min-h-40 rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs" required>{"command":"whoami"}</textarea>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-2">
                        <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-600">Quick Templates</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" data-template-job="run_command" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">run_command</button>
                            <button type="button" data-template-job="create_snapshot" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">create_snapshot</button>
                            <button type="button" data-template-job="restore_snapshot" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">restore_snapshot</button>
                            <button type="button" data-template-job="update_agent" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">update_agent</button>
                            <button type="button" data-template-job="uninstall_agent" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">uninstall_agent</button>
                            <button type="button" data-template-job="install_custom" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">install_custom</button>
                            <button type="button" data-template-job="uninstall_exe" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">uninstall_exe</button>
                            <button type="button" data-template-job="reconcile_software_inventory" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">reconcile_software_inventory</button>
                        </div>
                    </div>

                    <p id="job-type-help" class="text-xs text-slate-500">Choose a template to auto-fill payload for the selected job type.</p>
                    <button class="rounded-lg bg-skyline px-4 py-2 text-sm font-medium text-white">Queue Job</button>
                </form>
            </div>

            <div class="jobs-card rounded-2xl p-4 xl:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="font-semibold text-slate-900">Jobs Timeline</h3>
                        <p class="text-xs text-slate-500">Monitor statuses, inspect details, and rerun jobs quickly.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('admin.jobs.store-clear') }}" onsubmit="return confirm('Store snapshot and clear completed jobs?');">
                            @csrf
                            <input type="hidden" name="scope" value="completed">
                            <input type="hidden" name="store_snapshot" value="1">
                            <button class="rounded-lg bg-amber-500 px-3 py-2 text-xs text-white">Store + Clear Completed</button>
                        </form>
                        <form method="POST" action="{{ route('admin.jobs.store-clear') }}" onsubmit="return confirm('Store snapshot and clear ALL jobs? This cannot be undone in UI.');">
                            @csrf
                            <input type="hidden" name="scope" value="all">
                            <input type="hidden" name="store_snapshot" value="1">
                            <button class="rounded-lg bg-rose-600 px-3 py-2 text-xs text-white">Store + Clear All</button>
                        </form>
                    </div>
                </div>

                <div class="mb-3 grid gap-2 sm:grid-cols-3">
                    <input id="jobs-quick-search" type="text" placeholder="Quick filter by type/target/status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm sm:col-span-2" />
                    <select id="jobs-status-filter" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All statuses</option>
                        <option value="pending">pending</option>
                        <option value="acked">acked</option>
                        <option value="running">running</option>
                        <option value="completed">completed</option>
                        <option value="failed">failed</option>
                        <option value="non_compliant">non_compliant</option>
                        <option value="cancelled">cancelled</option>
                    </select>
                </div>

                <div class="overflow-x-auto rounded-lg border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr class="text-left text-slate-500">
                                <th class="px-3 py-2">Job</th>
                                <th class="px-3 py-2">Type</th>
                                <th class="px-3 py-2">Target</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Priority</th>
                                <th class="px-3 py-2">Created</th>
                                <th class="px-3 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="jobs-table-body">
                            @foreach($jobs as $job)
                                @php
                                    $status = strtolower((string) $job->status);
                                    $statusTone = match ($status) {
                                        'success', 'completed' => 'bg-emerald-100 text-emerald-700',
                                        'failed', 'non_compliant', 'error' => 'bg-rose-100 text-rose-700',
                                        'running' => 'bg-sky-100 text-sky-700',
                                        'acked' => 'bg-indigo-100 text-indigo-700',
                                        'queued', 'pending' => 'bg-amber-100 text-amber-700',
                                        'cancelled', 'canceled' => 'bg-slate-200 text-slate-700',
                                        default => 'bg-slate-100 text-slate-700',
                                    };
                                    $targetId = (string) ($job->target_id ?? '');
                                    $targetType = strtolower((string) ($job->target_type ?? ''));
                                @endphp
                                <tr
                                    class="border-t border-slate-200 jobs-row"
                                    data-filter="{{ strtolower($job->job_type.' '.$job->target_type.' '.$job->target_id.' '.$job->status.' '.$job->id) }}"
                                    data-status="{{ $status }}"
                                >
                                    <td class="px-3 py-2 align-top">
                                        <p class="jobs-mono text-xs text-slate-700">{{ $job->id }}</p>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <p class="font-medium text-slate-800">{{ $job->job_type }}</p>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <p class="text-slate-700">{{ $job->target_type }}</p>
                                        @if($resolvedTargetName !== '')
                                            <p class="text-xs font-semibold text-slate-800">{{ $resolvedTargetName }}</p>
                                        @endif
                                        <p class="jobs-mono text-xs text-slate-500">{{ $job->target_id }}</p>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <span class="rounded-full px-2 py-1 text-xs {{ $statusTone }}">{{ $job->status }}</span>
                                        @if(($job->skipped_runs ?? 0) > 0)
                                            <p class="mt-1"><span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-700">Skipped installed: {{ $job->skipped_runs }}</span></p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 align-top jobs-mono text-slate-700">{{ $job->priority }}</td>
                                    <td class="px-3 py-2 align-top text-slate-600">{{ $job->created_at }}</td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <a href="{{ route('admin.jobs.show', $job->id) }}" class="rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-700">Details</a>
                                            <form method="POST" action="{{ route('admin.jobs.rerun', $job->id) }}" onsubmit="return confirm('Re-run this job with the same payload and target?');">
                                                @csrf
                                                <button class="rounded bg-skyline px-2 py-1 text-xs text-white">Re-run</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div id="jobs-empty-filter" class="mt-3 hidden rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                    No jobs match your quick filter.
                </div>
                <div class="mt-4">{{ $jobs->links() }}</div>
            </div>
        </section>

        <section class="jobs-card rounded-2xl p-4 jobs-reveal">
            <h3 class="font-semibold text-slate-900">Runtime Policy Snapshot</h3>
            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="jobs-kv">Kill Switch</p>
                    <p class="mt-1 text-sm font-medium text-slate-800">{{ $ops['kill_switch'] ? 'Enabled (dispatch paused)' : 'Disabled' }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="jobs-kv">Retry Policy</p>
                    <p class="mt-1 text-sm font-medium text-slate-800">max {{ $ops['max_retries'] }} attempts</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="jobs-kv">Backoff</p>
                    <p class="mt-1 text-sm font-medium text-slate-800">{{ $ops['base_backoff_seconds'] }} seconds</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="jobs-kv">Allowed Hashes</p>
                    <p class="mt-1 text-sm font-medium text-slate-800">{{ count($ops['allowed_script_hashes']) }}</p>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Change these in Overview > Operations Controls.</p>
        </section>
    </div>

    <script>
        (function () {
            const type = document.querySelector('select[name="target_type"]');
            const target = document.querySelector('select[name="target_id"]');
            const jobType = document.getElementById('job-type-select');
            const payload = document.getElementById('payload-json-input');
            const help = document.getElementById('job-type-help');
            const templateButtons = Array.from(document.querySelectorAll('.job-template'));
            const runCommandOptions = document.getElementById('run-command-options');
            const runCommandRunAs = document.getElementById('run-command-runas');
            const runCommandTimeout = document.getElementById('run-command-timeout');
            const quickSearch = document.getElementById('jobs-quick-search');
            const statusFilter = document.getElementById('jobs-status-filter');
            const rows = Array.from(document.querySelectorAll('.jobs-row'));
            const emptyNotice = document.getElementById('jobs-empty-filter');

            if (!type || !target) return;

            const options = Array.from(target.options);
            const templates = {
                run_command: {"script":"Get-ComputerInfo | Select-Object WindowsProductName,OsVersion"},
                create_snapshot: {"provider":"windows_restore_point","label":"Lab-Before-Exam","restore_point_type":"MODIFY_SETTINGS","include_vss":false,"vss_volumes":["C:"],"fail_on_vss_error":false},
                restore_snapshot: {"provider":"windows_restore_point","restore_point_description":"Lab-Before-Exam","reboot_now":true,"reboot_command":"shutdown.exe /r /t 0"},
                update_agent: {"download_url":"https://example.local/agent.zip","sha256":"<64-hex>","file_name":"dms-agent.zip","rollback_command":"sc start DmsAgent"},
                uninstall_agent: {"service_name":"DMSAgent","install_dir":"C:\\Program Files\\DMS Agent","data_dir":"C:\\ProgramData\\DMS","admin_confirmed":true,"admin_confirmed_at":"2026-02-26T00:00:00Z","admin_confirmation_ttl_minutes":30,"admin_confirmation_nonce":"<uuid>"},
                install_custom: {"download_url":"https://example.local/app.exe","sha256":"<64-hex>","file_name":"app.exe","silent_args":"/S","rollback_command":"\"C:\\Program Files\\Vendor\\App\\uninstall.exe\" /S"},
                uninstall_exe: {"command":"\"C:\\Program Files\\Vendor\\App\\uninstall.exe\" /S"},
                reconcile_software_inventory: {"sources":["registry_uninstall","winget_list","dpkg_list","rpm_list"]}
            };
            const helps = {
                run_command: 'run_command executes payload.script directly; allowlist and policy checks apply on agent.',
                create_snapshot: 'create_snapshot creates a Windows restore point and can optionally include VSS shadows.',
                restore_snapshot: 'restore_snapshot restores from sequence/description and may reboot immediately.',
                update_agent: 'update_agent accepts rollback_command when upgrade verification fails.',
                uninstall_agent: 'uninstall_agent may require tamper-protection confirmation fields.',
                install_custom: 'install_custom supports EXE/custom installer with silent args.',
                uninstall_exe: 'uninstall_exe executes the uninstall command via endpoint shell.',
                reconcile_software_inventory: 'Collects software inventory and writes back endpoint software state.'
            };

            function syncTargets() {
                const typeValue = type.value;
                options.forEach(function (opt) {
                    const kind = opt.getAttribute('data-kind') || '';
                    const visible = kind === 'all' || kind === typeValue;
                    opt.hidden = !visible;
                });
                const selected = target.selectedOptions[0];
                if (!selected || selected.hidden || selected.value === '') {
                    const firstVisible = options.find(function (o) { return !o.hidden && o.value !== ''; });
                    target.value = firstVisible ? firstVisible.value : '';
                }
            }

            function setTemplate(job) {
                if (!payload) return;
                if (templates[job]) {
                    payload.value = JSON.stringify(templates[job], null, 2);
                }
                if (help && helps[job]) {
                    help.textContent = helps[job];
                }
            }

            function syncRunCommandOptions() {
                if (!jobType || !runCommandOptions) return;
                const isRunCommand = jobType.value === 'run_command';
                runCommandOptions.classList.toggle('hidden', !isRunCommand);
                if (!isRunCommand) return;
                if (runCommandRunAs && !runCommandRunAs.value) runCommandRunAs.value = 'default';
                if (runCommandTimeout && !runCommandTimeout.value) runCommandTimeout.value = '300';
            }

            function applyJobsFilter() {
                if (!quickSearch || !statusFilter || rows.length === 0) return;
                const q = quickSearch.value.trim().toLowerCase();
                const status = statusFilter.value.trim().toLowerCase();
                let visibleCount = 0;

                rows.forEach(function (row) {
                    const text = row.getAttribute('data-filter') || '';
                    const rowStatus = row.getAttribute('data-status') || '';
                    const matchText = q === '' || text.includes(q);
                    const matchStatus = status === '' || rowStatus === status;
                    const show = matchText && matchStatus;
                    row.classList.toggle('hidden', !show);
                    if (show) visibleCount++;
                });

                if (emptyNotice) {
                    emptyNotice.classList.toggle('hidden', visibleCount !== 0);
                }
            }

            type.addEventListener('change', syncTargets);
            if (jobType) {
                jobType.addEventListener('change', function () {
                    const selected = jobType.value;
                    if (helps[selected] && help) {
                        help.textContent = helps[selected];
                    }
                    syncRunCommandOptions();
                });
            }
            templateButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const job = btn.getAttribute('data-template-job') || '';
                    if (jobType && job !== '') {
                        jobType.value = job;
                    }
                    setTemplate(job);
                    syncRunCommandOptions();
                });
            });
            if (quickSearch) quickSearch.addEventListener('input', applyJobsFilter);
            if (statusFilter) statusFilter.addEventListener('change', applyJobsFilter);

            syncTargets();
            if (jobType) {
                setTemplate(jobType.value);
                syncRunCommandOptions();
            }
            applyJobsFilter();
        })();
    </script>
</x-admin-layout>
