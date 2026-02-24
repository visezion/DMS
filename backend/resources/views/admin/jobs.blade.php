<x-admin-layout title="Jobs" heading="Job Dispatch Center">
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl bg-white border border-slate-200 p-4">
            <h3 class="font-semibold mb-3">Queue New Job</h3>
            <form method="POST" action="{{ route('admin.jobs.create') }}" class="space-y-3">
                @csrf
                <label class="mb-1 block text-xs font-medium text-slate-600">Job Type</label>
                <select id="job-type-select" name="job_type" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option value="install_package">install_package</option>
                    <option value="uninstall_package">uninstall_package</option>
                    <option value="install_msi">install_msi</option>
                    <option value="uninstall_msi">uninstall_msi</option>
                    <option value="install_exe">install_exe</option>
                    <option value="install_custom">install_custom</option>
                    <option value="uninstall_exe">uninstall_exe</option>
                    <option value="apply_policy">apply_policy</option>
                    <option value="run_command">run_command</option>
                    <option value="update_agent">update_agent</option>
                    <option value="uninstall_agent">uninstall_agent</option>
                    <option value="reconcile_software_inventory">reconcile_software_inventory</option>
                </select>
                <label class="mb-1 block text-xs font-medium text-slate-600">Target Type</label>
                <select name="target_type" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option value="device">device</option>
                    <option value="group">group</option>
                </select>
                <label class="mb-1 block text-xs font-medium text-slate-600">Target</label>
                <select name="target_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option value="" data-kind="all">Select target</option>
                    @foreach($devices as $device)
                        <option value="{{ $device->id }}" data-kind="device">Device: {{ $device->hostname }}</option>
                    @endforeach
                    @foreach($groups as $group)
                        <option value="{{ $group->id }}" data-kind="group">Group: {{ $group->name }}</option>
                    @endforeach
                </select>
                <label class="mb-1 block text-xs font-medium text-slate-600">Priority</label>
                <input name="priority" type="number" min="1" max="1000" value="100" class="w-full rounded-lg border border-slate-300 px-3 py-2"/>
                <label class="mb-1 block text-xs font-medium text-slate-600">Stagger Seconds</label>
                <input name="stagger_seconds" type="number" min="0" max="3600" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Stagger seconds between group devices (0 = immediate)" />
                <label class="mb-1 block text-xs font-medium text-slate-600">Payload JSON</label>
                <textarea id="payload-json-input" name="payload_json" class="w-full rounded-lg border border-slate-300 px-3 py-2 min-h-36 font-mono text-xs" required>{"command":"whoami"}</textarea>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2">
                    <p class="text-[11px] font-semibold text-slate-600 uppercase tracking-wide mb-1">Quick Templates</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" data-template-job="run_command" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">run_command</button>
                        <button type="button" data-template-job="update_agent" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">update_agent</button>
                        <button type="button" data-template-job="uninstall_agent" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">uninstall_agent</button>
                        <button type="button" data-template-job="install_custom" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">install_custom</button>
                        <button type="button" data-template-job="uninstall_exe" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">uninstall_exe</button>
                        <button type="button" data-template-job="reconcile_software_inventory" class="job-template rounded border border-slate-300 bg-white px-2 py-1 text-[11px]">reconcile_software_inventory</button>
                    </div>
                </div>
                <p id="job-type-help" class="text-xs text-slate-500">Choose a template to auto-fill payload for the selected job type.</p>
                <button class="rounded-lg bg-skyline text-white px-4 py-2 text-sm">Queue Job</button>
            </form>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 lg:col-span-2 overflow-x-auto">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold">Jobs</h3>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.jobs.store-clear') }}" onsubmit="return confirm('Store snapshot and clear completed jobs?');">
                        @csrf
                        <input type="hidden" name="scope" value="completed">
                        <input type="hidden" name="store_snapshot" value="1">
                        <button class="rounded-lg bg-amber-500 text-white px-3 py-2 text-xs">Store + Clear Completed</button>
                    </form>
                    <form method="POST" action="{{ route('admin.jobs.store-clear') }}" onsubmit="return confirm('Store snapshot and clear ALL jobs? This cannot be undone in UI.');">
                        @csrf
                        <input type="hidden" name="scope" value="all">
                        <input type="hidden" name="store_snapshot" value="1">
                        <button class="rounded-lg bg-red-600 text-white px-3 py-2 text-xs">Store + Clear All</button>
                    </form>
                </div>
            </div>
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left text-slate-500"><th class="py-2">ID</th><th class="py-2">Type</th><th class="py-2">Target</th><th class="py-2">Status</th><th class="py-2">Priority</th><th class="py-2">Created</th><th class="py-2">Debug</th></tr></thead>
                <tbody>
                @foreach($jobs as $job)
                    <tr class="border-b">
                        <td class="py-2 font-mono text-xs">{{ $job->id }}</td>
                        <td class="py-2">{{ $job->job_type }}</td>
                        <td class="py-2">{{ $job->target_type }} / <span class="font-mono text-xs">{{ $job->target_id }}</span></td>
                        <td class="py-2">
                            <div>{{ $job->status }}</div>
                            @if(($job->skipped_runs ?? 0) > 0)
                                <div class="mt-1 text-xs"><span class="inline-block rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5">Skipped installed: {{ $job->skipped_runs }}</span></div>
                            @endif
                        </td>
                        <td class="py-2">{{ $job->priority }}</td>
                        <td class="py-2">{{ $job->created_at }}</td>
                        <td class="py-2"><a href="{{ route('admin.jobs.show', $job->id) }}" class="rounded bg-slate-100 text-slate-700 px-2 py-1 text-xs">Details</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="mt-4">{{ $jobs->links() }}</div>
        </div>
    </div>

    <div class="rounded-2xl bg-white border border-slate-200 p-4">
        <h3 class="font-semibold mb-3">Runtime Controls</h3>
        <p class="text-sm">Kill switch: <span class="font-medium">{{ $ops['kill_switch'] ? 'Enabled (dispatch paused)' : 'Disabled' }}</span></p>
        <p class="text-sm">Retry policy: max {{ $ops['max_retries'] }} attempts, base backoff {{ $ops['base_backoff_seconds'] }} seconds</p>
        <p class="text-sm">Allowed script hashes: {{ count($ops['allowed_script_hashes']) }}</p>
        <p class="text-xs text-slate-500 mt-2">Update these from Overview > Operations Controls.</p>
    </div>

    <script>
        (function () {
            const type = document.querySelector('select[name="target_type"]');
            const target = document.querySelector('select[name="target_id"]');
            const jobType = document.getElementById('job-type-select');
            const payload = document.getElementById('payload-json-input');
            const help = document.getElementById('job-type-help');
            const templateButtons = Array.from(document.querySelectorAll('.job-template'));
            if (!type || !target) return;
            const options = Array.from(target.options);
            const templates = {
                run_command: {"script":"Get-ComputerInfo | Select-Object WindowsProductName,OsVersion"},
                update_agent: {"download_url":"https://example.local/agent.zip","sha256":"<64-hex>","file_name":"dms-agent.zip","rollback_command":"sc start DmsAgent"},
                uninstall_agent: {"service_name":"DMSAgent","install_dir":"C:\\Program Files\\DMS Agent","data_dir":"C:\\ProgramData\\DMS"},
                install_custom: {"download_url":"https://example.local/app.exe","sha256":"<64-hex>","file_name":"app.exe","silent_args":"/S","rollback_command":"\"C:\\Program Files\\Vendor\\App\\uninstall.exe\" /S"},
                uninstall_exe: {"command":"\"C:\\Program Files\\Vendor\\App\\uninstall.exe\" /S"},
                reconcile_software_inventory: {"sources":["registry_uninstall","winget_list","dpkg_list","rpm_list"]}
            };
            const helps = {
                run_command: 'run_command executes directly with payload.script. script_sha256 is auto-derived; allowlist/approval can be optionally enforced on agent.',
                update_agent: 'update_agent supports rollback_command if update fails.',
                uninstall_agent: 'Schedules self-uninstall on target: stop/delete service, then remove install and data folders.',
                install_custom: 'install_custom uses the EXE/custom installer flow on managed Windows endpoints.',
                uninstall_exe: 'uninstall_exe executes command via OS shell on the endpoint.',
                reconcile_software_inventory: 'Collects deep software inventory (registry/winget/brew/dpkg/rpm) and stores it into device tags.'
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

            type.addEventListener('change', syncTargets);
            if (jobType) {
                jobType.addEventListener('change', function () {
                    const selected = jobType.value;
                    if (helps[selected] && help) {
                        help.textContent = helps[selected];
                    }
                });
            }
            templateButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const job = btn.getAttribute('data-template-job') || '';
                    if (jobType && job !== '') {
                        jobType.value = job;
                    }
                    setTemplate(job);
                });
            });
            syncTargets();
            if (jobType) {
                setTemplate(jobType.value);
            }
        })();
    </script>
</x-admin-layout>
