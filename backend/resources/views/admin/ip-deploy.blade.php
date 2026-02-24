<x-admin-layout title="IP Deploy" heading="IP Range Agent Deploy">
    @php($result = $result ?? null)
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 space-y-4">
            <div>
                <h3 class="font-semibold mb-3">Scan / Install via SMB+RPC</h3>
                @error('ip_deploy')
                    <div class="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
                @enderror
                <form id="ip-deploy-form" method="POST" action="{{ route('admin.ip-deploy.run') }}" class="space-y-3">
                    @csrf
                    <label for="deploy_method" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Deploy Method</label>
                    <select id="deploy_method" name="deploy_method" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option value="smb_rpc" @selected(old('deploy_method', 'smb_rpc') === 'smb_rpc')>SMB/RPC Service Mode (default)</option>
                        <option value="psexec" @selected(old('deploy_method') === 'psexec')>PsExec Mode (alternative)</option>
                    </select>
                    <label for="release_id" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Agent Release</label>
                    <select id="release_id" name="release_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option value="">Select release (recommended)</option>
                        @foreach(($releases ?? []) as $release)
                            <option value="{{ $release->id }}" @selected(old('release_id', ($activeRelease->id ?? '')) === $release->id)>
                                {{ $release->version }} - {{ $release->file_name }}{{ $release->is_active ? ' (active)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <label for="expires_hours" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Signed URL Expiry (Hours)</label>
                    <input id="expires_hours" name="expires_hours" type="number" min="1" max="168" value="{{ old('expires_hours', 24) }}" placeholder="Signed URL expiry (hours)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label for="api_base_url" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">API Base URL</label>
                    <input id="api_base_url" name="api_base_url" value="{{ old('api_base_url', $defaultApiBase ?? '') }}" placeholder="API base URL (for generated links)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label for="public_base_url" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Public Base URL</label>
                    <input id="public_base_url" name="public_base_url" value="{{ old('public_base_url', $defaultPublicBase ?? '') }}" placeholder="Public base URL (for generated links)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label for="install_script_url" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Install Script URL (Optional)</label>
                    <input id="install_script_url" name="install_script_url" value="{{ old('install_script_url') }}" placeholder="Optional: paste signed install-script URL (override/manual)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label for="target_ip" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Single Target IP (Optional)</label>
                    <input id="target_ip" name="target_ip" value="{{ old('target_ip') }}" placeholder="Single IP (optional)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label for="ip_range_cidr" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Target CIDR Range (Optional)</label>
                    <input id="ip_range_cidr" name="ip_range_cidr" value="{{ old('ip_range_cidr') }}" placeholder="CIDR range (optional), e.g. 172.16.155.0/24" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label for="target_ips" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Target IP List (Optional)</label>
                    <textarea id="target_ips" name="target_ips" placeholder="Target IP list (optional, one per line)" class="w-full rounded-lg border border-slate-300 px-3 py-2 min-h-24">{{ old('target_ips') }}</textarea>
                    <label for="ip_deploy_username" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Username</label>
                    <input id="ip_deploy_username" name="username" value="{{ old('username') }}" placeholder="Username (DOMAIN\\user or HOST\\user)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label for="ip_deploy_password" class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Password</label>
                    <input id="ip_deploy_password" name="password" type="password" placeholder="Password" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input id="scan_only" type="checkbox" name="scan_only" value="1" @checked(old('scan_only'))> Scan only (no install, WhatIf)</label>
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="skip_port_checks" value="1" @checked(old('skip_port_checks'))> Skip port checks</label>
                    <div id="psexec-options" class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="auto_bootstrap" value="1" @checked(old('auto_bootstrap'))> PsExec only: auto bootstrap first</label>
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="bootstrap_only" value="1" @checked(old('bootstrap_only'))> PsExec only: bootstrap only (no install)</label>
                    </div>
                    <p class="text-xs text-slate-500">Install mode requires username/password and signed script URL (or selected release). Scan mode only validates target reachability.</p>
                    <button id="ip-deploy-submit" class="rounded-lg bg-ink text-white px-4 py-2 text-sm w-full">Run</button>
                </form>
                <p class="text-xs text-slate-500 mt-2">Uses standalone scripts: <span class="font-mono">backend/scripts/install-agent-by-smb-rpc.ps1</span> or <span class="font-mono">backend/scripts/install-agent-by-psexec.ps1</span></p>
            </div>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 lg:col-span-2 space-y-4">
            <h3 class="font-semibold">Execution Result</h3>

            @if($result)
                <div class="grid gap-3 md:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 p-3 bg-slate-50">
                        <p class="text-xs text-slate-500">Found</p>
                        <p class="text-xl font-semibold">{{ $result['summary']['found'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 bg-green-50">
                        <p class="text-xs text-green-700">{{ !empty($result['scan_only']) ? 'Reachable' : 'Installed' }}</p>
                        <p class="text-xl font-semibold text-green-700">{{ $result['summary']['success'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 bg-red-50">
                        <p class="text-xs text-red-700">Failed</p>
                        <p class="text-xl font-semibold text-red-700">{{ $result['summary']['failed'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 bg-slate-50">
                        <p class="text-xs text-slate-500">Exit Code</p>
                        <p class="text-xl font-semibold">{{ $result['exit_code'] ?? '-' }}</p>
                    </div>
                </div>

                @if(!empty($result['report_path']))
                    <p class="text-xs text-slate-600">Report: <span class="font-mono break-all">{{ $result['report_path'] }}</span></p>
                @endif
                @if(!empty($result['deploy_method']))
                    <p class="text-xs text-slate-600">Deploy method: <span class="font-mono">{{ $result['deploy_method'] }}</span> | Worker: <span class="font-mono">{{ $result['worker_script'] ?? '-' }}</span></p>
                @endif
                @if(!empty($result['auto_bootstrap']) || !empty($result['bootstrap_only']))
                    <p class="text-xs text-slate-600">Bootstrap flags:
                        <span class="font-mono">auto_bootstrap={{ !empty($result['auto_bootstrap']) ? 'true' : 'false' }}</span>,
                        <span class="font-mono">bootstrap_only={{ !empty($result['bootstrap_only']) ? 'true' : 'false' }}</span>
                    </p>
                @endif
                @if(!empty($result['hints']))
                    <div class="rounded-xl border border-amber-300 bg-amber-50 p-3">
                        <p class="text-xs uppercase text-amber-700 mb-2">Hints</p>
                        <ul class="text-xs text-amber-800 space-y-1">
                            @foreach(($result['hints'] ?? []) as $hint)
                                <li>{{ $hint }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="rounded-xl border border-slate-200 p-3 bg-slate-50">
                    <p class="text-xs uppercase text-slate-500 mb-2">Command Output</p>
                    <pre class="text-xs whitespace-pre-wrap font-mono text-slate-700 max-h-44 overflow-auto">{{ implode("\n", $result['command_output'] ?? []) }}</pre>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-slate-500">
                                <th class="py-2">IP</th>
                                <th class="py-2">Result</th>
                                <th class="py-2">Seconds</th>
                                <th class="py-2">Message</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse(($result['rows'] ?? []) as $row)
                            <tr class="border-b align-top">
                                <td class="py-2 font-mono text-xs">{{ $row['ip'] ?? '' }}</td>
                                <td class="py-2">
                                    @if(strtolower((string)($row['ok'] ?? '')) === 'true')
                                        <span class="rounded-full bg-green-100 text-green-700 px-2 py-1 text-xs">success</span>
                                    @else
                                        <span class="rounded-full bg-red-100 text-red-700 px-2 py-1 text-xs">failed</span>
                                    @endif
                                </td>
                                <td class="py-2">{{ $row['seconds'] ?? '' }}</td>
                                <td class="py-2 text-xs break-all">{{ $row['message'] ?? '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-slate-500">No rows parsed from report yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-slate-500">Run a scan/install from the left panel to view results.</p>
            @endif

            <div class="rounded-xl border border-slate-200 p-3 bg-slate-50">
                <p class="text-xs uppercase text-slate-500 mb-2">One-Time Bootstrap (if access denied)</p>
                <p class="text-xs text-slate-600 mb-2">Run once as Administrator on target PC, then retry IP deploy from dashboard.</p>
                <pre class="text-xs whitespace-pre-wrap font-mono text-slate-700 overflow-auto">powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\DMS\backend\scripts\bootstrap-enable-remote-deploy.ps1"</pre>
            </div>
        </div>
    </div>

    <div id="ip-deploy-progress-modal" class="hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center px-4">
        <div class="w-full max-w-3xl rounded-2xl bg-white border border-slate-200 shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 bg-slate-50">
                <div class="flex items-center gap-2">
                    <span class="h-3 w-3 rounded-full bg-red-400"></span>
                    <span class="h-3 w-3 rounded-full bg-amber-400"></span>
                    <span class="h-3 w-3 rounded-full bg-emerald-400"></span>
                    <span class="ml-2 text-xs font-mono text-slate-600">dms-ip-deploy-terminal</span>
                </div>
                <div id="ip-deploy-elapsed" class="text-xs font-mono text-slate-500">00:00</div>
            </div>
            <div class="px-4 py-3 border-b border-slate-200 bg-slate-50/70">
                <p id="ip-deploy-context" class="text-xs text-slate-600 font-mono">Preparing deployment context...</p>
            </div>
            <div id="ip-deploy-terminal" class="p-4 h-72 overflow-auto font-mono text-xs text-slate-800 space-y-1 bg-white">
                <div class="text-sky-700">[init] waiting...</div>
            </div>
            <div class="px-4 py-3 border-t border-slate-200 bg-slate-50">
                <p class="text-xs text-slate-500">Running in server-side mode. Final command output appears after completion.</p>
            </div>
        </div>
    </div>

    <template id="ip-deploy-log-line-template">
        <div class="flex gap-2">
            <span class="text-slate-400 log-time">00:00:00</span>
            <span class="log-level text-slate-600">[info]</span>
            <span class="log-text text-slate-800"></span>
        </div>
    </template>

    <script>
        (function () {
            const form = document.getElementById('ip-deploy-form');
            const submitBtn = document.getElementById('ip-deploy-submit');
            const modal = document.getElementById('ip-deploy-progress-modal');
            const terminal = document.getElementById('ip-deploy-terminal');
            const context = document.getElementById('ip-deploy-context');
            const elapsed = document.getElementById('ip-deploy-elapsed');
            const lineTemplate = document.getElementById('ip-deploy-log-line-template');
            const deployMethodEl = document.getElementById('deploy_method');
            const scanOnlyEl = document.getElementById('scan_only');
            const usernameEl = document.getElementById('ip_deploy_username');
            const passwordEl = document.getElementById('ip_deploy_password');
            const psexecOptionsEl = document.getElementById('psexec-options');
            if (!form || !submitBtn || !modal || !terminal || !context || !elapsed || !lineTemplate) return;

            function syncFormMode() {
                const deployMethod = deployMethodEl?.value || 'smb_rpc';
                const scanOnly = !!scanOnlyEl?.checked;
                if (usernameEl) usernameEl.required = !scanOnly;
                if (passwordEl) passwordEl.required = !scanOnly;
                if (psexecOptionsEl) {
                    psexecOptionsEl.classList.toggle('hidden', deployMethod !== 'psexec');
                }
            }
            deployMethodEl?.addEventListener('change', syncFormMode);
            scanOnlyEl?.addEventListener('change', syncFormMode);
            syncFormMode();

            function nowTime() {
                const d = new Date();
                const hh = String(d.getHours()).padStart(2, '0');
                const mm = String(d.getMinutes()).padStart(2, '0');
                const ss = String(d.getSeconds()).padStart(2, '0');
                return hh + ':' + mm + ':' + ss;
            }

            function addLine(level, text) {
                const frag = lineTemplate.content.cloneNode(true);
                const row = frag.querySelector('div');
                const levelEl = frag.querySelector('.log-level');
                const textEl = frag.querySelector('.log-text');
                const timeEl = frag.querySelector('.log-time');
                if (!row || !levelEl || !textEl || !timeEl) return;
                timeEl.textContent = nowTime();
                levelEl.textContent = '[' + level + ']';
                if (level === 'ok') levelEl.className = 'log-level text-emerald-300';
                if (level === 'warn') levelEl.className = 'log-level text-amber-300';
                if (level === 'run') levelEl.className = 'log-level text-sky-300';
                if (level === 'err') levelEl.className = 'log-level text-red-300';
                textEl.textContent = text;
                terminal.appendChild(frag);
                terminal.scrollTop = terminal.scrollHeight;
            }

            form.addEventListener('submit', function () {
                const deployMethod = form.querySelector('[name="deploy_method"]')?.value || 'smb_rpc';
                const targetIp = form.querySelector('[name="target_ip"]')?.value?.trim() || '';
                const cidr = form.querySelector('[name="ip_range_cidr"]')?.value?.trim() || '';
                const targetListRaw = form.querySelector('[name="target_ips"]')?.value || '';
                const targetListCount = targetListRaw
                    .split(/\r?\n/)
                    .map(v => v.trim())
                    .filter(v => v.length > 0 && !v.startsWith('#'))
                    .length;
                const scanOnly = !!form.querySelector('[name="scan_only"]')?.checked;

                const targetSummary = [
                    targetIp ? ('single=' + targetIp) : null,
                    cidr ? ('cidr=' + cidr) : null,
                    targetListCount > 0 ? ('list=' + targetListCount + ' ip(s)') : null
                ].filter(Boolean).join(' | ') || 'no target provided';

                context.textContent = 'method=' + deployMethod + ' | mode=' + (scanOnly ? 'scan-only' : 'install') + ' | ' + targetSummary;
                terminal.innerHTML = '';
                addLine('run', 'IP deploy request submitted from dashboard.');
                addLine('info', 'Preparing signed install URL and target set...');
                addLine('info', 'Executing worker script on server.');

                modal.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                submitBtn.textContent = 'Running...';

                const start = Date.now();
                const steps = [
                    { t: 1400, level: 'run', msg: 'Checking reachability and required ports per target...' },
                    { t: 3200, level: 'run', msg: 'Authenticating remote execution channel...' },
                    { t: 5200, level: 'run', msg: scanOnly ? 'Scan in progress. No install actions will be triggered.' : 'Install command dispatch in progress...' },
                    { t: 7800, level: 'warn', msg: 'Waiting for remote responses. Large ranges may take longer.' }
                ];
                steps.forEach(step => {
                    window.setTimeout(() => addLine(step.level, step.msg), step.t);
                });

                const timer = window.setInterval(() => {
                    const total = Math.floor((Date.now() - start) / 1000);
                    const mm = String(Math.floor(total / 60)).padStart(2, '0');
                    const ss = String(total % 60).padStart(2, '0');
                    elapsed.textContent = mm + ':' + ss;
                }, 250);

                form.dataset.progressTimerId = String(timer);
            });
        })();
    </script>
</x-admin-layout>
