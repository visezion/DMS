<x-admin-layout title="Agent Delivery" heading="Agent Delivery and Client Install">
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-lg font-semibold">Agent Delivery</h3>
        </div>
        <div class="px-5 pt-3">
            <div class="flex flex-wrap gap-4 border-b border-slate-200 text-sm">
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 8a5 5 0 0 1 10 0v8a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V8Z"/><path d="M9 5 7 3M15 5l2-2M9 12h.01M15 12h.01"/></svg>
                    Android
                </span>
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10 5h4M11 18h2"/></svg>
                    iOS
                </span>
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14.5 7.5c.8-1 1.2-2.2 1.1-3.5-1.2.1-2.4.7-3.2 1.7-.7.8-1.2 2-1 3.2 1.2.1 2.3-.5 3.1-1.4Z"/><path d="M18 12.5c0 3.6-2.4 7.3-4.8 7.3-1.1 0-1.8-.6-2.9-.6-1.1 0-1.9.6-3 .6-2.4 0-4.8-3.6-4.8-7.2 0-2.8 1.8-4.6 3.6-4.6 1.1 0 2 .7 3 .7.9 0 2-.8 3.3-.8 1.2 0 2.2.5 2.9 1.4-2.6 1.4-2.3 4.9.7 6.1-.3.9-.7 1.8-1 2.1"/></svg>
                    macOS
                </span>
                <span class="inline-flex items-center gap-1.5 border-b-2 border-sky-500 px-1 py-2 font-semibold text-slate-900">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 text-sky-600" fill="currentColor" aria-hidden="true"><path d="M2 4.5 11 3v8H2v-6.5Zm10 6.5V2.9l10-1.4V11H12ZM2 13h9v8l-9-1.3V13Zm10 0h10v10.5L12 22v-9Z"/></svg>
                    Windows
                </span>
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v4M7.5 5l2 2M16.5 5l-2 2"/><path d="M4 10h16v3a8 8 0 0 1-16 0v-3Z"/><path d="M9 18h6"/></svg>
                    Linux
                </span>
            </div>
        </div>
        <div class="mx-5 mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs italic text-amber-900">
            Agent delivery operations for Windows endpoints. Keep one stable active release for production rollout.
        </div>
        <div class="p-5">
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="mb-4 flex flex-wrap gap-2 border-b border-slate-200 pb-3">
                <button type="button" class="agent-tab-btn rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700" data-agent-tab="runtime">Runtime</button>
                <button type="button" class="agent-tab-btn rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700" data-agent-tab="build">Build & Upload</button>
                <button type="button" class="agent-tab-btn rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700" data-agent-tab="deploy">Remote Update Deployment</button>
            </div>

            <div class="space-y-4">
                <div data-agent-tab-panel="runtime">
                    <div>
                        <h3 class="mb-3 font-semibold">Agent Backend Server</h3>
                        @error('agent_backend')
                            <div class="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
                        @enderror
                        <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50/50 p-3">
                            <p id="agent-backend-status-line" class="text-sm">
                                Status:
                                @if(($backendServer['running'] ?? false))
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">running</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700">not running</span>
                                @endif
                            </p>
                            <p id="agent-backend-endpoint-line" class="font-mono text-xs text-slate-500">{{ $backendServer['host'] ?? '127.0.0.1' }}:{{ $backendServer['port'] ?? 8000 }}</p>
                            <form method="POST" action="{{ route('admin.agent.backend.start') }}">
                                @csrf
                                <button class="rounded-lg bg-ink px-4 py-2 text-sm text-white">Start Agent Backend</button>
                            </form>
                            <p class="text-xs text-slate-500">Start command: <span class="font-mono">python -m uvicorn app.main:app --host 127.0.0.1 --port 8000</span></p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <h3 class="mb-3 font-semibold">Test API Connectivity</h3>
                        <form method="POST" action="{{ route('admin.agent.test-connectivity') }}" class="space-y-3 rounded-lg border border-slate-200 bg-slate-50/50 p-3">
                            @csrf
                            <label class="block text-xs uppercase text-slate-500">API Base URL</label>
                            <input name="api_base_url" value="{{ old('api_base_url', $defaultApiBase) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                            <button class="rounded-lg bg-skyline px-4 py-2 text-sm text-white">Run Connectivity Test</button>
                        </form>
                        <p class="mt-2 text-xs text-slate-500">Runs from server-side. Confirms agent-relevant endpoints are reachable before deployment.</p>
                    </div>
                </div>

                <div data-agent-tab-panel="build" class="hidden">
                    <div>
                        <h3 class="mb-3 font-semibold">Auto Build Agent (One Click)</h3>
                        <form id="autobuild-form" method="POST" action="{{ route('admin.agent.releases.autobuild') }}" class="space-y-3 rounded-lg border border-slate-200 bg-slate-50/50 p-3">
                            @csrf
                            <label class="block text-xs uppercase text-slate-500">Version</label>
                            <input name="version" required placeholder="Version (e.g. 1.0.0)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                            <label class="block text-xs uppercase text-slate-500">Runtime</label>
                            <input name="runtime" value="win-x64" placeholder="Runtime" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="self_contained" value="1"> Self-contained publish (larger build, needs more disk)</label>
                            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="activate_after_build" value="1" checked> Activate after build</label>
                            <button id="autobuild-submit" class="rounded-lg bg-ink px-4 py-2 text-sm text-white">Build and Register</button>
                        </form>
                        <p class="mt-2 text-xs text-slate-500">Runs on the server hosting Laravel. Requires .NET 8 SDK and PowerShell.</p>
                    </div>

                    <div class="mt-4">
                        <h3 class="mb-3 font-semibold">Upload Agent Installer</h3>
                        <form method="POST" action="{{ route('admin.agent.releases.upload') }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-slate-200 bg-slate-50/50 p-3">
                            @csrf
                            <label class="block text-xs uppercase text-slate-500">Version</label>
                            <input name="version" required placeholder="Version (e.g. 1.0.0)" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                            <label class="block text-xs uppercase text-slate-500">Platform</label>
                            <input name="platform" value="windows-x64" placeholder="Platform" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                            <label class="block text-xs uppercase text-slate-500">Installer File</label>
                            <input name="installer" type="file" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                            <label class="block text-xs uppercase text-slate-500">Notes</label>
                            <textarea name="notes" placeholder="Notes" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                            <button class="rounded-lg bg-skyline px-4 py-2 text-sm text-white">Upload Release</button>
                        </form>
                    </div>
                </div>

                <div data-agent-tab-panel="deploy" class="hidden">
                    <div>
                        <h3 class="mb-3 font-semibold">Push Agent Update (Remote)</h3>
                        @error('agent_push_update')
                            <div class="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
                        @enderror
                        <form method="POST" action="{{ route('admin.agent.push-update') }}" class="space-y-3 rounded-lg border border-slate-200 bg-slate-50/50 p-3">
                            @csrf
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="mb-1 block text-xs uppercase text-slate-500">Release</label>
                                    <select name="release_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                        @foreach($releases as $release)
                                            <option value="{{ $release->id }}" @selected($activeRelease && $activeRelease->id === $release->id)>
                                                {{ $release->version }} ({{ $release->file_name }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs uppercase text-slate-500">Target Scope</label>
                                    <select name="target_scope" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                        <option value="all">all devices</option>
                                        <option value="group">group</option>
                                        <option value="device">device</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs uppercase text-slate-500">Target</label>
                                    <select name="target_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                        <option value="" data-kind="all">(for scope=all, leave empty)</option>
                                        @foreach($groups as $group)
                                            <option value="{{ $group->id }}" data-kind="group">Group: {{ $group->name }}</option>
                                        @endforeach
                                        @foreach($devices as $device)
                                            <option value="{{ $device->id }}" data-kind="device">Device: {{ $device->hostname }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <details class="rounded-lg border border-slate-200 bg-white p-3">
                                <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500">Advanced Options</summary>
                                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label class="mb-1 block text-xs uppercase text-slate-500">Stagger (Sec)</label>
                                        <input name="stagger_seconds" type="number" min="0" max="3600" value="15" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs uppercase text-slate-500">Expires (Hrs)</label>
                                        <input name="expires_hours" type="number" min="1" max="168" value="24" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs uppercase text-slate-500">Priority</label>
                                        <input name="priority" type="number" min="1" max="1000" value="100" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label class="mb-1 block text-xs uppercase text-slate-500">Public Base URL</label>
                                        <input name="public_base_url" value="{{ old('public_base_url', $defaultPublicBase) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                                    </div>
                                </div>
                            </details>
                            <button class="rounded-lg bg-ink px-4 py-2 text-sm text-white">Queue Agent Update</button>
                        </form>
                        <p class="mt-2 text-xs text-slate-500">After queueing, monitor `Jobs` and verify new `Agent` + `Build` values in `Devices`.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 lg:col-span-2">
            <h3 class="font-semibold">Agent Releases</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2">Version</th>
                        <th class="py-2">File</th>
                        <th class="py-2">SHA256</th>
                        <th class="py-2">Size</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($releases as $release)
                        <tr class="border-b align-top">
                            <td class="py-2 font-medium">{{ $release->version }}</td>
                            <td class="py-2">{{ $release->file_name }}</td>
                            <td class="py-2 font-mono text-xs break-all">{{ $release->sha256 }}</td>
                            <td class="py-2">{{ number_format($release->size_bytes / 1024, 1) }} KB</td>
                            <td class="py-2">
                                @if($release->is_active)
                                    <span class="rounded-full bg-green-100 text-green-700 px-2 py-1 text-xs">Active</span>
                                @else
                                    <span class="rounded-full bg-slate-100 text-slate-600 px-2 py-1 text-xs">Inactive</span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if(!$release->is_active)
                                    <div class="flex items-center gap-2">
                                        <form method="POST" action="{{ route('admin.agent.releases.activate', $release->id) }}">
                                            @csrf
                                            <button class="rounded bg-skyline text-white px-2 py-1 text-xs">Make Active</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.agent.releases.delete', $release->id) }}" onsubmit="return confirm('Delete this release permanently?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded bg-red-600 text-white px-2 py-1 text-xs">Delete</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-slate-500">No releases uploaded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if($generated)
                <div class="rounded-xl border border-skyline/30 bg-skyline/10 p-4 space-y-3">
                    <h4 class="font-semibold">Generated Installer Bundle (expires: {{ $generated['expires_at'] }})</h4>
                    <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Use the exact one-liner below on the client PC as Administrator. Do not replace it with placeholders.
                    </div>
                    <div>
                        <p class="text-xs uppercase text-slate-500">Script URL</p>
                        <textarea readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-16">{{ $generated['script_url'] }}</textarea>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-slate-500">Download URL</p>
                        <textarea readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-16">{{ $generated['download_url'] }}</textarea>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-slate-500">Client One-Liner</p>
                        <textarea id="client-one-liner" readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-20">{{ $generated['copy_command'] }}</textarea>
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" data-copy-target="client-one-liner" class="rounded bg-skyline text-white px-3 py-1 text-xs">Copy One-Liner</button>
                            <a href="{{ $generated['script_url'] }}" target="_blank" class="rounded bg-ink text-white px-3 py-1 text-xs">Open Script URL</a>
                        </div>
                    </div>
                    @if(!empty($generated['cmd_script']))
                        <div>
                            <p class="text-xs uppercase text-slate-500">CMD Script</p>
                            <textarea id="client-cmd-script" readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-24">{{ $generated['cmd_script'] }}</textarea>
                            <div class="mt-2 flex items-center gap-2">
                                <button type="button" data-copy-target="client-cmd-script" class="rounded bg-slate-900 text-white px-3 py-1 text-xs">Copy CMD Script</button>
                                @if(!empty($generated['launcher_url']))
                                    <a href="{{ $generated['launcher_url'] }}" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs text-slate-700">Download `.cmd`</a>
                                @endif
                            </div>
                        </div>
                    @endif
                    <div>
                        <p class="text-xs uppercase text-slate-500">Public Base URL Used</p>
                        <textarea readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-12">{{ $generated['public_base_url'] ?? '' }}</textarea>
                    </div>
                    <p class="text-xs text-slate-600">Share the PowerShell one-liner or the CMD script with the client PC. Both download the active installer and set `DMS_API_BASE_URL` + `DMS_ENROLLMENT_TOKEN` machine variables.</p>
                </div>
            @endif

            @if(session('agent_build_log'))
                <div class="rounded-xl border border-slate-300 bg-slate-50 p-4">
                    <h4 class="font-semibold mb-2">Latest Build Output</h4>
                    <pre class="text-xs whitespace-pre-wrap font-mono text-slate-700">{{ session('agent_build_log') }}</pre>
                </div>
            @endif

            @if($connectivity)
                <div class="rounded-xl border {{ $connectivity['all_good'] ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50' }} p-4 space-y-3">
                    <h4 class="font-semibold">Connectivity Report ({{ $connectivity['tested_at'] }})</h4>
                    <p class="text-sm">API Base URL: <span class="font-mono text-xs">{{ $connectivity['api_base_url'] }}</span></p>
                    <div class="space-y-2">
                        @foreach($connectivity['results'] as $name => $result)
                            <div class="rounded-lg bg-white border border-slate-200 p-3">
                                <div class="flex items-center justify-between">
                                    <p class="font-medium capitalize">{{ $name }}</p>
                                    <span class="text-xs px-2 py-1 rounded-full {{ $result['ok'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                        {{ $result['ok'] ? 'reachable' : 'failed' }}
                                    </span>
                                </div>
                                <p class="text-xs font-mono break-all mt-1">{{ $result['url'] }}</p>
                                <p class="text-xs mt-1">Status: {{ $result['status'] }} | Latency: {{ $result['latency_ms'] }} ms</p>
                                @if(!empty($result['error']))
                                    <p class="text-xs text-red-700 mt-1">{{ $result['error'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
        </div>
    </section>
    <div id="autobuild-progress-modal" class="hidden fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm flex items-center justify-center px-4">
        <div class="w-full max-w-md rounded-2xl bg-white border border-slate-200 p-6">
            <div class="flex items-start gap-3">
                <div class="mt-1 h-5 w-5 rounded-full border-2 border-slate-300 border-t-skyline animate-spin"></div>
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Building Agent Release</h3>
                    <p class="mt-1 text-sm text-slate-600">
                        Build and register is in progress. Do not close this tab until the request completes.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const tabButtons = Array.from(document.querySelectorAll('.agent-tab-btn'));
            const tabPanels = Array.from(document.querySelectorAll('[data-agent-tab-panel]'));
            function activateAgentTab(tabName) {
                tabButtons.forEach(function (btn) {
                    const active = btn.getAttribute('data-agent-tab') === tabName;
                    btn.classList.toggle('bg-slate-50', active);
                    btn.classList.toggle('bg-white', !active);
                    btn.classList.toggle('border-sky-300', active);
                    btn.classList.toggle('text-sky-700', active);
                });
                tabPanels.forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.getAttribute('data-agent-tab-panel') !== tabName);
                });
            }
            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activateAgentTab(btn.getAttribute('data-agent-tab'));
                });
            });
            activateAgentTab('runtime');

            document.querySelectorAll('form').forEach(function (formEl) {
                const scope = formEl.querySelector('select[name="target_scope"]');
                const target = formEl.querySelector('select[name="target_id"]');
                if (!scope || !target) return;
                const options = Array.from(target.options);

                function syncTargetOptions() {
                    const scopeValue = scope.value;
                    options.forEach(function (opt) {
                        const kind = opt.getAttribute('data-kind') || '';
                        const visible = kind === 'all' || kind === scopeValue;
                        opt.hidden = !visible;
                    });
                    const allScope = scopeValue === 'all';
                    target.disabled = allScope;
                    target.required = !allScope;
                    if (allScope) {
                        target.value = '';
                        return;
                    }
                    const current = target.selectedOptions[0];
                    if (!current || current.hidden) {
                        const firstVisible = options.find(function (o) { return !o.hidden && o.value !== ''; });
                        target.value = firstVisible ? firstVisible.value : '';
                    }
                }

                scope.addEventListener('change', syncTargetOptions);
                syncTargetOptions();
            });

            const form = document.getElementById('autobuild-form');
            const submitBtn = document.getElementById('autobuild-submit');
            const modal = document.getElementById('autobuild-progress-modal');

            if (!form || !submitBtn || !modal) {
                // Still allow backend polling even if build form is not present.
            }

            if (form && submitBtn && modal) {
                form.addEventListener('submit', function () {
                    modal.classList.remove('hidden');
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                    submitBtn.textContent = 'Building...';
                });
            }

            document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    const targetId = btn.getAttribute('data-copy-target');
                    const target = targetId ? document.getElementById(targetId) : null;
                    if (!target) {
                        return;
                    }

                    try {
                        await navigator.clipboard.writeText(target.value);
                        btn.textContent = 'Copied';
                        setTimeout(function () {
                            btn.textContent = 'Copy One-Liner';
                        }, 1500);
                    } catch (e) {
                        target.select();
                        document.execCommand('copy');
                    }
                });
            });
        })();
    </script>
</x-admin-layout>
