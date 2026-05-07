<x-admin-layout title="Enroll Devices" heading="Enroll Devices">
    @php
        $status = (string) session('status', '');
        $agentPage = route('admin.agent');
        $generateInstallJsonUrl = route('admin.agent.releases.generate-json');
    @endphp

<div class="enroll-shell space-y-5">
    <section class="enroll-card overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-lg font-semibold">Enroll Devices</h3>
        </div>

        <div class="px-5 pt-3">
            <div class="flex flex-wrap gap-4 border-b border-slate-200 text-sm">
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 8a5 5 0 0 1 10 0v8a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V8Z"/><path d="M9 5 7 3M15 5l2-2M9 12h.01M15 12h.01"/></svg>
                    Any Android Device
                </span>
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10 5h4M11 18h2"/></svg>
                    iPad & iPhone
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

        <div class="grid gap-0 p-5 lg:grid-cols-12">
            <div class="lg:col-span-7 lg:pr-5">
                <div class="mb-3 flex items-center gap-2 border-b border-slate-200 text-sm">
                    <button type="button" data-enroll-tab-btn="browser" class="rounded-t-lg border border-b-0 border-slate-300 bg-sky-50 px-3 py-2 font-medium text-sky-700">Browser Based Enrollment</button>
                    <button type="button" data-enroll-tab-btn="agent" class="rounded-t-lg border border-b-0 border-slate-300 bg-white px-3 py-2 text-slate-500">Agent Based Enrollment</button>
                </div>

                <div data-enroll-tab-panel="browser" class="enroll-pane rounded-xl border border-slate-200 bg-slate-50/40 p-5">
                    <div class="mb-4">
                        <p class="font-semibold text-slate-900">Browser Based Enrollment</p>
                        <p class="mt-1 text-sm text-slate-700">Click the button below to generate a fresh token and open the PowerShell install script.</p>
                    </div>

                    <div class="enroll-feature-panel rounded-2xl border border-slate-200 bg-gradient-to-br from-white via-slate-50 to-sky-50/70 px-5 py-5 text-sm text-slate-700 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">One-Click Windows Installer</p>
                                <h4 class="text-lg font-semibold text-slate-900">PowerShell Install Script</h4>
                                <p class="mt-1 text-xs text-slate-600">Generates a fresh enrollment token, then opens the install script.</p>
                            </div>
                            <div class="flex flex-col items-end gap-1 text-xs">
                                <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-slate-600">Active release: {{ $activeRelease?->file_name ?? 'None' }}</span>
                                <span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-slate-700">Mode: Script</span>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-200 bg-white/80 p-3">
                                <p class="text-[11px] uppercase tracking-wide text-slate-500">Step 1</p>
                                <p class="text-sm font-semibold text-slate-900">Prepare token</p>
                                <p class="mt-1 text-xs text-slate-600">A fresh token is generated for this install session.</p>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-white/80 p-3">
                                <p class="text-[11px] uppercase tracking-wide text-slate-500">Step 2</p>
                                <p class="text-sm font-semibold text-slate-900">Launch installer</p>
                                <p class="mt-1 text-xs text-slate-600">Downloads the installer and requests admin approval.</p>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-white/80 p-3">
                                <p class="text-[11px] uppercase tracking-wide text-slate-500">Step 3</p>
                                <p class="text-sm font-semibold text-slate-900">Verify enrollment</p>
                                <p class="mt-1 text-xs text-slate-600">Device appears in the Devices list automatically.</p>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            @if($activeRelease)
                                <button
                                    type="button"
                                    id="browser-one-click-install"
                                    data-release-id="{{ $activeRelease->id }}"
                                    data-api-base-url="{{ $defaultApiBase }}"
                                    data-public-base-url="{{ $defaultPublicBase }}"
                                    class="rounded-lg bg-skyline px-4 py-2 text-sm font-medium text-white"
                                >
                                    One-Click Install Agent
                                </button>
                            @else
                                <span class="text-xs text-amber-700">No active release found. Activate a release in Agent Delivery first.</span>
                            @endif
                            <a href="{{ $agentPage }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700">Open Agent Delivery</a>
                        </div>
                        <div id="browser-one-click-status" class="mt-3 hidden rounded-lg border px-3 py-2 text-xs"></div>
                    </div>
                </div>

                <div data-enroll-tab-panel="agent" class="enroll-pane hidden rounded-xl border border-slate-200 bg-slate-50/40 p-5">
                    <p class="mb-3 font-semibold text-slate-900">Windows 10 & above, Windows 7 & 8.1</p>
                    <ol class="list-decimal space-y-2 pl-5 text-sm text-slate-700">
                        <li>Open <a href="{{ $agentPage }}" class="text-sky-700 underline">Agent Delivery</a> and prepare or activate an agent release.</li>
                        <li>Generate an enrollment token from the right panel.</li>
                        <li>Run the installer script on the target Windows device in PowerShell (Run as Administrator).</li>
                        <li>Use the Enrollment URL and Enrollment Code provided in the generated installer output.</li>
                        <li>Complete enrollment and verify device appears in <a href="{{ route('admin.devices') }}" class="text-sky-700 underline">Devices</a>.</li>
                    </ol>

                    <div class="mt-6">
                        <button type="submit" form="generate-install-script-form" class="rounded-lg bg-ink px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Generate Install Script</button>
                    </div>

                    <div class="enroll-side-panel mt-8 rounded-lg border border-slate-200 bg-white p-4">
                        <p class="font-semibold text-slate-900">Start Managing</p>
                        <p class="mt-1 text-sm text-slate-700">After enrollment, assign policies and packages from Policy Center and Application Management.</p>
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-200 pt-5 lg:col-span-5 lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0">
                <div class="space-y-4">
                    <div class="enroll-side-panel rounded-xl border border-slate-200 bg-slate-50/40 p-4">
                        <h4 class="mb-3 font-semibold text-slate-900">Generate Client Installer Link</h4>
                        @error('agent_generate')
                            <div class="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
                        @enderror
                        <form id="generate-install-script-form" method="POST" action="{{ route('admin.agent.releases.generate') }}" class="space-y-3">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">Release</label>
                                <select name="release_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    @foreach($releases as $release)
                                        <option value="{{ $release->id }}" @selected($activeRelease && $activeRelease->id === $release->id)>
                                            {{ $release->version }} ({{ $release->file_name }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">Expires (Hours)</label>
                                <input name="expires_hours" type="number" min="1" max="168" value="24" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">API Base URL</label>
                                <input name="api_base_url" value="{{ old('api_base_url', $defaultApiBase) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">Public Base URL</label>
                                <input name="public_base_url" value="{{ old('public_base_url', $defaultPublicBase) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

        @if($generated)
        <section class="enroll-success-panel mt-5 rounded-2xl border border-emerald-300 bg-emerald-50 p-5 shadow-sm">
            <h3 class="font-semibold text-emerald-900">Installer Ready</h3>
            <p class="mt-1 text-sm text-emerald-800">The install bundle has been prepared. Paste the PowerShell command into the target PC, or use the CMD launcher if you need Command Prompt.</p>
            <div class="mt-4 space-y-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-900">PowerShell Script To Paste</p>
                    <textarea id="agent-enrollment-powershell" readonly class="mt-1 min-h-24 w-full rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs font-mono text-slate-800">{{ $generated['copy_command'] ?? $generated['script_url'] }}</textarea>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" data-copy-target="agent-enrollment-powershell" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white">Copy PowerShell</button>
                        @if(!empty($generated['script_url']))
                            <a href="{{ $generated['script_url'] }}" target="_blank" rel="noopener" class="rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-800">Open `.ps1` Script</a>
                        @endif
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-900">Command Prompt Launcher</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        @if(!empty($generated['launcher_url']))
                            <a href="{{ $generated['launcher_url'] }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Download `install-dms-agent.cmd`</a>
                        @endif
                        @if(!empty($generated['download_url']))
                            <a href="{{ $generated['download_url'] }}" class="rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-800">Download Agent Package</a>
                        @endif
                    </div>
                    @if(!empty($generated['cmd_script']))
                        <textarea id="agent-enrollment-cmd" readonly class="mt-3 min-h-28 w-full rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs font-mono text-slate-800">{{ $generated['cmd_script'] }}</textarea>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <button type="button" data-copy-target="agent-enrollment-cmd" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Copy CMD Script</button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="mt-4 rounded-lg border border-emerald-200 bg-white px-4 py-3 text-xs text-emerald-900">
                Run the PowerShell command in an elevated PowerShell window, or download the `.cmd` file and run it as Administrator on the target Windows device.
            </div>
        </section>
    @endif

    <section class="enroll-table-panel mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Recent Enrollment Tokens</h3>
            <p class="text-xs text-slate-500">Latest 5 generated tokens.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-slate-500">
                        <th class="py-2">Token ID</th>
                        <th class="py-2">Created</th>
                        <th class="py-2">Expires</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Used By Device</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent_tokens as $token)
                        @php
                            $isExpired = $token->expires_at && $token->expires_at->isPast();
                            $isUsed = !empty($token->used_by_device_id);
                        @endphp
                        <tr class="border-b align-top">
                            <td class="py-2 font-mono text-xs text-slate-700">{{ $token->id }}</td>
                            <td class="py-2 text-xs text-slate-600">{{ $token->created_at }}</td>
                            <td class="py-2 text-xs text-slate-600">{{ $token->expires_at }}</td>
                            <td class="py-2">
                                @if($isUsed)
                                    <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-700">Used</span>
                                @elseif($isExpired)
                                    <span class="rounded-full bg-rose-100 px-2 py-1 text-xs text-rose-700">Expired</span>
                                @else
                                    <span class="rounded-full bg-sky-100 px-2 py-1 text-xs text-sky-700">Active</span>
                                @endif
                            </td>
                            <td class="py-2 text-xs text-slate-600">{{ $token->used_by_device_id ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-4 text-sm text-slate-500">No enrollment tokens created yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        (function () {
            const tabButtons = Array.from(document.querySelectorAll('[data-enroll-tab-btn]'));
            const tabPanels = Array.from(document.querySelectorAll('[data-enroll-tab-panel]'));
            function activateTab(name) {
                tabButtons.forEach(function (btn) {
                    const active = btn.getAttribute('data-enroll-tab-btn') === name;
                    btn.classList.toggle('bg-sky-50', active);
                    btn.classList.toggle('text-sky-700', active);
                    btn.classList.toggle('font-medium', active);
                    btn.classList.toggle('bg-white', !active);
                    btn.classList.toggle('text-slate-500', !active);
                });
                tabPanels.forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.getAttribute('data-enroll-tab-panel') !== name);
                });
            }
            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activateTab(btn.getAttribute('data-enroll-tab-btn'));
                });
            });
            activateTab('browser');

            const oneClickBtn = document.getElementById('browser-one-click-install');
            const oneClickStatus = document.getElementById('browser-one-click-status');
            if (oneClickBtn && oneClickStatus) {
                oneClickBtn.addEventListener('click', async function () {
                        const installMode = 'silent';
                    oneClickBtn.disabled = true;
                    oneClickBtn.textContent = 'Preparing...';
                    oneClickStatus.className = 'mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700';
                        oneClickStatus.textContent = 'Preparing install bundle...';
                    oneClickStatus.classList.remove('hidden');

                    try {
                        const response = await fetch(@json($generateInstallJsonUrl), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': @json(csrf_token())
                            },
                            body: JSON.stringify({
                                release_id: oneClickBtn.getAttribute('data-release-id'),
                                expires_hours: 87600,
                                api_base_url: oneClickBtn.getAttribute('data-api-base-url'),
                                public_base_url: oneClickBtn.getAttribute('data-public-base-url'),
                                install_mode: installMode
                            })
                        });
                        const data = await response.json().catch(function () { return {}; });

                        if (!response.ok) {
                            oneClickStatus.className = 'mt-3 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs text-rose-700';
                            oneClickStatus.textContent = data.message || 'Unable to prepare installer.';
                            return;
                        }

                        const bundle = data.bundle || {};
                        oneClickStatus.className = 'mt-3 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs text-emerald-800';
                        oneClickStatus.textContent = 'Installer ready. Opening install script...';
                        if (bundle.launcher_url || bundle.script_url) {
                            window.open(bundle.launcher_url || bundle.script_url, '_blank', 'noopener');
                        }
                    } catch (err) {
                        oneClickStatus.className = 'mt-3 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs text-rose-700';
                        oneClickStatus.textContent = 'Request failed while preparing the installer.';
                    } finally {
                        oneClickBtn.disabled = false;
                        oneClickBtn.textContent = 'One-Click Install Agent';
                    }
                });
            }

            Array.from(document.querySelectorAll('[data-copy-target]')).forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    const targetId = btn.getAttribute('data-copy-target');
                    const target = targetId ? document.getElementById(targetId) : null;
                    if (!target) {
                        return;
                    }

                    try {
                        await navigator.clipboard.writeText(target.value || target.textContent || '');
                        const original = btn.textContent;
                        btn.textContent = 'Copied';
                        setTimeout(function () {
                            btn.textContent = original;
                        }, 1500);
                    } catch (err) {
                        target.focus();
                        target.select();
                    }
                });
            });

        })();
    </script>
</div>
</x-admin-layout>
