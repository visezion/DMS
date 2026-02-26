<x-admin-layout title="Device Detail" heading="Device Detail">
    @php
        $agentBuild = is_array($device->tags) ? ($device->tags['agent_build'] ?? 'unknown') : 'unknown';
        $inventory = is_array($device->tags) ? ($device->tags['inventory'] ?? null) : null;
        $inventoryUpdatedAt = is_array($device->tags) ? ($device->tags['inventory_updated_at'] ?? '') : '';
        $inventoryCollectedAt = is_array($inventory) ? ($inventory['collected_at'] ?? '') : '';
        $runtimeDiagnostics = is_array($device->tags) ? ($device->tags['runtime_diagnostics'] ?? null) : null;
        $runtimeDiagnosticsUpdatedAt = is_array($device->tags) ? ($device->tags['runtime_diagnostics_updated_at'] ?? '') : '';
    @endphp
    <style>
        .win-panel {
            border-color: #d7deea;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .win-chip {
            border: 1px solid #d7deea;
            background: #f8fafc;
        }
        .win-section-title svg {
            color: #2563eb;
        }
    </style>

    <div class="rounded-2xl border bg-gradient-to-br from-slate-50 via-white to-slate-100 p-5 win-panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-4">
                <div class="h-24 w-24 rounded-2xl border border-sky-100 bg-sky-50 text-sky-700 flex items-center justify-center">
                    <svg class="h-16 w-16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M3 4.1L11 3v8H3V4.1zm9 6.9V2.8l10-1.3V11H12zm0 2h10v9.5L12 21v-8zm-1 8L3 19.9V13h8v8z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Windows Device Overview</p>
                    <h3 class="text-2xl font-semibold text-slate-900">{{ $device->hostname }}</h3>
                    <p id="agent-version-line" class="text-sm text-slate-600">{{ $device->os_name }} {{ $device->os_version }} | Agent {{ $device->agent_version }}</p>
                    <p id="agent-build" class="text-xs text-slate-500 font-mono mt-1">Build: {{ $agentBuild }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span id="device-status" class="rounded-full win-chip px-3 py-1 text-xs text-slate-700">Status: {{ $device->status }}</span>
                <span id="last-checkin" class="rounded-full win-chip px-3 py-1 text-xs text-slate-700">Last check-in: {{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'never' }}</span>
            </div>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Device ID</p>
                <p class="font-mono text-xs text-slate-700 break-all">{{ $device->id }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Operating System</p>
                <p class="text-sm font-medium text-slate-800">{{ $device->os_name ?: 'Unknown' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">OS Version</p>
                <p class="text-sm font-medium text-slate-800">{{ $device->os_version ?: 'Unknown' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Agent Version</p>
                <p class="text-sm font-medium text-slate-800">{{ $device->agent_version ?: 'Unknown' }}</p>
            </div>
        </div>
        <div class="mt-4 rounded-xl border border-red-200 bg-red-50/60 p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-sm font-semibold text-red-800">Danger Zone: Remote Agent Uninstall</p>
                    <p class="text-xs text-red-700">Queues <span class="font-mono">uninstall_agent</span> on this device. Requires your admin password.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('admin.devices.reboot', $device->id) }}" class="flex flex-wrap items-center gap-2 js-sensitive-action" data-action-label="reboot" data-action-title="Reboot Device" data-action-description="Restart this device now? Unsaved work on target device may be lost.">
                    @csrf
                    <input type="hidden" name="admin_password" value="">
                    <input type="hidden" name="priority" value="95">
                    <button class="inline-flex items-center gap-1 rounded-lg bg-blue-600 text-white px-3 py-2 text-xs font-medium hover:bg-blue-700">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 12a9 9 0 1 0 3-6.7"/>
                            <path d="M3 4v5h5"/>
                        </svg>
                        Reboot Device
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.devices.agent.uninstall', $device->id) }}" class="flex flex-wrap items-center gap-2 js-sensitive-action" data-action-label="uninstall" data-action-title="Uninstall Agent" data-action-description="Uninstall DMS agent from this device? This will remove remote management access after completion. It will also uninstall all deployed software and remove all applied policies from this device.">
                    @csrf
                    <input type="hidden" name="admin_password" value="">
                    <input type="hidden" name="priority" value="90">
                    <button class="rounded-lg bg-red-600 text-white px-3 py-2 text-xs font-medium hover:bg-red-700">Uninstall Agent</button>
                </form>
                </div>
            </div>
            @error('device_reboot')
                <p class="mt-2 rounded border border-red-300 bg-white px-2 py-1 text-xs text-red-700">{{ $message }}</p>
            @enderror
            @error('agent_uninstall')
                <p class="mt-2 rounded border border-red-300 bg-white px-2 py-1 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <br><hr><br>
        <div class="flex items-center justify-between gap-2 mb-2">
            <h4 class="font-semibold flex items-center gap-2 win-section-title">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M3 5a2 2 0 012-2h6v8H3V5zm10-2h6a2 2 0 012 2v6h-8V3zM3 13h8v8H5a2 2 0 01-2-2v-6zm10 0h8v6a2 2 0 01-2 2h-6v-8z"/>
                </svg>
                Device Inventory
            </h4>
            <div class="text-right">
                <p id="inventory-updated" class="text-xs text-slate-500">Synced: {{ $inventoryUpdatedAt !== '' ? $inventoryUpdatedAt : 'n/a' }}</p>
                <p id="inventory-collected" class="text-xs text-slate-500">Snapshot: {{ $inventoryCollectedAt !== '' ? $inventoryCollectedAt : 'n/a' }}</p>
            </div>
        </div>
        <div id="inventory-panel" class="space-y-3"></div>
        <details class="mt-3">
            <summary class="cursor-pointer text-xs text-slate-500">Raw inventory JSON</summary>
            <pre id="inventory-json" class="mt-2 text-xs bg-slate-50 border border-slate-200 rounded p-3 overflow-auto max-h-72">{{ json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 win-panel">
            <h4 class="font-semibold mb-3 flex items-center gap-2 win-section-title">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2l8 4v6c0 5-3.4 9.7-8 11-4.6-1.3-8-6-8-11V6l8-4zm-1 6v7l5-3.5L11 8z"/>
                </svg>
                Policies On This Device
            </h4>
            <div class="space-y-2 max-h-80 overflow-auto">
                @forelse(($effective_policies ?? collect()) as $policy)
                    @php
                        $runStatus = strtolower((string) ($policy->last_run_status ?? 'assigned'));
                    @endphp
                    <div class="rounded-xl border border-slate-200 p-3 text-xs bg-slate-50/60">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-medium">{{ $policy->policy_name }}</p>
                            @if(($policy->assignment_source ?? '') === 'device' && !empty($policy->assignment_id))
                                <form method="POST" action="{{ route('admin.devices.policies.remove', [$device->id, $policy->assignment_id]) }}" onsubmit="return confirm('Remove this device policy assignment and queue reconcile now?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded border border-red-300 text-red-700 px-2 py-0.5 hover:bg-red-50">Remove</button>
                                </form>
                            @endif
                        </div>
                        <p class="font-mono text-slate-500">{{ $policy->policy_slug }}</p>
                        <p>Version: {{ $policy->version_number }} | Source: {{ $policy->assignment_source }} | Policy status: {{ $policy->policy_version_status }}</p>
                        <p>
                            Device status:
                            @if($runStatus === 'success')
                                <span class="rounded-full bg-green-100 text-green-700 px-2 py-0.5">applied</span>
                            @elseif($runStatus === 'non_compliant')
                                <span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5">non_compliant</span>
                            @elseif($runStatus === 'failed')
                                <span class="rounded-full bg-red-100 text-red-700 px-2 py-0.5">failed</span>
                            @else
                                <span class="rounded-full bg-slate-100 text-slate-700 px-2 py-0.5">{{ $policy->last_run_status }}</span>
                            @endif
                        </p>
                        @if(!empty($policy->last_run_error))
                            <p>Last Error: {{ $policy->last_run_error }}</p>
                        @endif
                        @if($runStatus === 'failed' && !empty($policy->last_run_id))
                            <form method="POST" action="{{ route('admin.job-runs.rerun', $policy->last_run_id) }}" class="mt-2" onsubmit="return confirm('Re-run this failed policy apply on this device?');">
                                @csrf
                                <button class="rounded bg-skyline text-white px-2 py-1">Re-run</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No effective policies found for this device.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 win-panel">
            <h4 class="font-semibold mb-3 flex items-center gap-2 win-section-title">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M21 8.5l-9-5-9 5 9 5 9-5zM3 10.5l9 5 9-5V18l-9 5-9-5v-7.5z"/>
                </svg>
                Packages On This Device
            </h4>
            <div class="space-y-2 max-h-80 overflow-auto">
                @php
                    $packageGroups = collect($device_packages ?? collect())
                        ->groupBy(fn ($pkg) => ($pkg->package_slug ?? 'unknown').'||'.($pkg->package_name ?? 'Unknown package'));
                @endphp
                @forelse($packageGroups as $groupItems)
                    @php
                        $latest = $groupItems->sortByDesc(fn ($row) => $row->updated_at)->first();
                        $latestStatus = strtolower((string) ($latest->status ?? 'unknown'));
                        $installedCount = $groupItems->filter(fn ($row) => strtolower((string) ($row->status ?? '')) === 'success')->count();
                        $failedCount = $groupItems->filter(fn ($row) => strtolower((string) ($row->status ?? '')) === 'failed')->count();
                        $packageName = (string) ($latest->package_name ?? 'Unknown package');
                        $packageSlug = (string) ($latest->package_slug ?? '');
                        $packageInitial = strtoupper(substr($packageName, 0, 1));
                        $packageIconUrl = route('admin.packages.icon.windows-store', ['name' => $packageName, 'slug' => $packageSlug]);
                    @endphp
                    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-start gap-2 min-w-0">
                                <div class="h-10 w-10 rounded-lg border border-slate-200 bg-white overflow-hidden shrink-0 flex items-center justify-center">
                                    <img
                                        src="{{ $packageIconUrl }}"
                                        alt="{{ $packageName }} icon"
                                        class="h-full w-full object-cover"
                                        onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden');"
                                    >
                                    <span class="hidden text-sm font-semibold text-slate-600">{{ $packageInitial !== '' ? $packageInitial : '?' }}</span>
                                </div>
                                <div class="min-w-0">
                                <p class="font-medium truncate">{{ $latest->package_name ?? 'Unknown package' }}</p>
                                <p class="font-mono text-slate-500">{{ $latest->package_slug ?? '-' }} | versions: {{ $groupItems->count() }}</p>
                                <p class="text-[11px] text-slate-500 mt-1">ok: {{ $installedCount }} | fail: {{ $failedCount }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                @if($latest->already_installed)
                                    <span class="rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5">already installed</span>
                                @elseif($latestStatus === 'success')
                                    <span class="rounded-full bg-green-100 text-green-700 px-2 py-0.5">installed</span>
                                @elseif($latestStatus === 'failed')
                                    <span class="rounded-full bg-red-100 text-red-700 px-2 py-0.5">failed</span>
                                @else
                                    <span class="rounded-full bg-slate-100 text-slate-700 px-2 py-0.5">{{ $latest->status }}</span>
                                @endif
                                @if(!empty($latest->package_id))
                                    <div class="mt-2">
                                        <a href="{{ route('admin.packages.show', $latest->package_id) }}" class="rounded bg-skyline text-white px-2 py-1">Open package</a>
                                    </div>
                                @endif
                                @if(!empty($latest->package_version_id))
                                    <form method="POST" action="{{ route('admin.devices.packages.uninstall', $device->id) }}" class="mt-2" onsubmit="return confirm('Queue uninstall for {{ $latest->package_name }} on this device?');">
                                        @csrf
                                        <input type="hidden" name="package_version_id" value="{{ $latest->package_version_id }}">
                                        <button class="rounded bg-red-600 text-white px-2 py-1">Uninstall</button>
                                    </form>
                                @endif
                                @if($latestStatus === 'failed' && !empty($latest->run_id))
                                    <form method="POST" action="{{ route('admin.job-runs.rerun', $latest->run_id) }}" class="mt-2" onsubmit="return confirm('Re-run this failed package deployment on this device?');">
                                        @csrf
                                        <button class="rounded bg-skyline text-white px-2 py-1">Re-run</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No package activity found for this device.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 win-panel">
            <h4 class="font-semibold mb-3 flex items-center gap-2 win-section-title">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M4 4h16v2H4V4zm0 4h10v2H4V8zm0 4h16v2H4v-2zm0 4h10v2H4v-2z"/>
                </svg>
                Recent Job Runs
            </h4>
            <div id="job-runs-list" class="space-y-2 max-h-96 overflow-auto">
                @forelse($job_runs as $run)
                    <div class="rounded-xl border border-slate-200 p-3 text-xs bg-slate-50/60">
                        @php
                            $alreadyInstalled = is_array($run->result_payload ?? null)
                                ? (bool) ($run->result_payload['already_installed'] ?? false)
                                : false;
                        @endphp
                        <p class="font-mono">{{ $run->id }}</p>
                        <p>Status: {{ $run->status }} | Attempt: {{ $run->attempt_count ?? 0 }} | Next Retry: {{ $run->next_retry_at ?: '-' }}</p>
                        @if($alreadyInstalled)
                            <p><span class="inline-block rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5">Already Installed (Skipped)</span></p>
                        @endif
                        <p>Last Error: {{ $run->last_error ?: '-' }}</p>
                        @php
                            $sigDebug = is_array($run->result_payload ?? null) ? ($run->result_payload['signature_debug'] ?? null) : null;
                        @endphp
                        @if($run->last_error === 'E_SIG_INVALID' && is_array($sigDebug))
                            <div class="mt-1 rounded border border-amber-200 bg-amber-50 p-2 text-[11px] space-y-1">
                                <p>Signature Debug | kid: <span class="font-mono">{{ $sigDebug['kid'] ?? '-' }}</span> | alg: <span class="font-mono">{{ $sigDebug['alg'] ?? '-' }}</span> | known_kid: <span class="font-mono">{{ ($sigDebug['known_kid'] ?? false) ? 'yes' : 'no' }}</span></p>
                                @if(!empty($sigDebug['candidate_canonical_sha256']) && is_array($sigDebug['candidate_canonical_sha256']))
                                    <p>canonical sha256 candidates:</p>
                                    @foreach($sigDebug['candidate_canonical_sha256'] as $h)
                                        <p class="font-mono break-all">{{ $h }}</p>
                                    @endforeach
                                @endif
                                @if(!empty($sigDebug['candidate_digest_sha256']) && is_array($sigDebug['candidate_digest_sha256']))
                                    <p>digest sha256 candidates:</p>
                                    @foreach($sigDebug['candidate_digest_sha256'] as $h)
                                        <p class="font-mono break-all">{{ $h }}</p>
                                    @endforeach
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No job runs.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 win-panel">
            <h4 class="font-semibold mb-3 flex items-center gap-2 win-section-title">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M9 16.2l-3.5-3.5L4 14.2 9 19l11-11-1.5-1.5z"/>
                </svg>
                Policy Compliance
            </h4>
            <div class="space-y-2 max-h-96 overflow-auto">
                @forelse($compliance as $row)
                    <div class="rounded-xl border border-slate-200 p-3 text-xs bg-slate-50/60">
                        <p>Status: <span class="font-medium">{{ $row->status }}</span> | Checked: {{ $row->checked_at }}</p>
                        <p class="font-mono break-all">Check: {{ $row->compliance_check_id }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No compliance records.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 win-panel">
            <h4 class="font-semibold mb-3 flex items-center gap-2 win-section-title">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1zm2 3v2h10V7H7zm0 4v2h10v-2H7zm0 4v2h6v-2H7z"/>
                </svg>
                Assigned Policy Versions
            </h4>
            <div class="space-y-2 max-h-80 overflow-auto">
                @forelse($assigned_policy_versions as $assignment)
                    <div class="rounded-xl border border-slate-200 p-3 text-xs bg-slate-50/60">
                        <p class="font-mono">{{ $assignment->policy_version_id }}</p>
                        <p>Target: {{ $assignment->target_type }} / {{ $assignment->target_id }}</p>
                        <p>Updated: {{ $assignment->updated_at }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No assignments.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 win-panel">
            <h4 class="font-semibold mb-3 flex items-center gap-2 win-section-title">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 11h4v-2h-3V6h-2v7z"/>
                </svg>
                Audit Trail
            </h4>
            <div class="space-y-2 max-h-80 overflow-auto">
                @forelse($audit as $log)
                    <div class="rounded-xl border border-slate-200 p-3 text-xs bg-slate-50/60">
                        <p>{{ $log->action }} | {{ $log->created_at }}</p>
                        <p class="font-mono break-all">{{ $log->entity_type }} / {{ $log->entity_id }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No audit events.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div id="job-toast-container" class="fixed top-5 right-5 z-50 space-y-2 pointer-events-none"></div>

    <div id="device-action-modal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-[1px] px-4">
        <div class="flex min-h-full items-center justify-center">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h3 id="device-action-modal-title" class="text-base font-semibold text-slate-900">Confirm Action</h3>
                    <p class="mt-1 text-xs text-slate-600">This action requires admin password confirmation.</p>
                </div>
                <div class="space-y-3 px-5 py-4">
                    <div id="device-action-modal-description" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700"></div>
                    <div>
                        <label for="device-action-password" class="mb-1 block text-xs font-medium text-slate-600">Enter your admin password:</label>
                        <input id="device-action-password" type="password" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" autocomplete="current-password" />
                    </div>
                    <p id="device-action-modal-error" class="hidden rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">Password is required.</p>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
                    <button id="device-action-cancel" type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700">Cancel</button>
                    <button id="device-action-confirm" type="button" class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-medium text-white hover:bg-slate-900">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const deviceId = @json($device->id);
            const liveUrl = @json(route('admin.devices.live', $device->id));
            const jobRunsList = document.getElementById('job-runs-list');
            const statusNode = document.getElementById('device-status');
            const checkinNode = document.getElementById('last-checkin');
            const buildNode = document.getElementById('agent-build');
            const versionLineNode = document.getElementById('agent-version-line');
            const inventoryUpdatedNode = document.getElementById('inventory-updated');
            const inventoryCollectedNode = document.getElementById('inventory-collected');
            const inventoryPanelNode = document.getElementById('inventory-panel');
            const inventoryJsonNode = document.getElementById('inventory-json');
            const runtimeUpdatedNode = document.getElementById('runtime-updated');
            const runtimePanelNode = document.getElementById('runtime-diagnostics-panel');
            const toastContainer = document.getElementById('job-toast-container');
            const initialInventory = @json($inventory);
            const initialRuntimeDiagnostics = @json($runtimeDiagnostics);
            let polling = false;

            function escapeHtml(text) {
                return String(text ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function toast(message, isSuccess) {
                const item = document.createElement('div');
                item.className = 'pointer-events-auto rounded-lg border px-3 py-2 text-sm shadow-lg text-white ' + (isSuccess ? 'bg-green-600 border-green-700' : 'bg-red-600 border-red-700');
                item.textContent = message;
                toastContainer.appendChild(item);
                setTimeout(() => item.remove(), 4500);
            }

            function rememberNotification(runId, status) {
                const key = `dms_job_notified_${deviceId}_${runId}_${status}`;
                if (sessionStorage.getItem(key)) {
                    return false;
                }
                sessionStorage.setItem(key, '1');
                return true;
            }

            function applyDeviceStatus(statusValue) {
                if (!statusNode) {
                    return;
                }
                const normalized = String(statusValue || 'unknown').toLowerCase();
                statusNode.textContent = `Status: ${normalized}`;
                statusNode.classList.remove('text-slate-700', 'text-green-700', 'text-red-700', 'text-amber-700', 'bg-green-50', 'bg-red-50', 'bg-amber-50');
                if (normalized === 'online') {
                    statusNode.classList.add('text-green-700', 'bg-green-50');
                    return;
                }
                if (normalized === 'offline') {
                    statusNode.classList.add('text-red-700', 'bg-red-50');
                    return;
                }
                if (normalized === 'pending' || normalized === 'quarantined') {
                    statusNode.classList.add('text-amber-700', 'bg-amber-50');
                    return;
                }
                statusNode.classList.add('text-slate-700');
            }

            function renderRuns(runs) {
                if (!Array.isArray(runs) || runs.length === 0) {
                    jobRunsList.innerHTML = '<p class="text-sm text-slate-500">No job runs.</p>';
                    return;
                }

                jobRunsList.innerHTML = runs.map((run) => {
                    const lastError = run.last_error && run.last_error !== '' ? run.last_error : '-';
                    const nextRetry = run.next_retry_at && run.next_retry_at !== '' ? run.next_retry_at : '-';
                    const skipBadge = run.already_installed
                        ? '<p><span class="inline-block rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5">Already Installed (Skipped)</span></p>'
                        : '';
                    return `
                        <div class="rounded border border-slate-200 p-2 text-xs">
                            <p class="font-mono">${escapeHtml(run.id)}</p>
                            <p>Status: ${escapeHtml(run.status)} | Attempt: ${escapeHtml(run.attempt_count ?? 0)} | Next Retry: ${escapeHtml(nextRetry)}</p>
                            ${skipBadge}
                            <p>Last Error: ${escapeHtml(lastError)}</p>
                        </div>
                    `;
                }).join('');
            }

            function fmtBytes(value) {
                const n = Number(value);
                if (!Number.isFinite(n) || n <= 0) {
                    return '-';
                }
                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                let v = n;
                let i = 0;
                while (v >= 1024 && i < units.length - 1) {
                    v /= 1024;
                    i += 1;
                }
                return `${v.toFixed(v >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
            }

            function fmtPct(used, total) {
                const u = Number(used);
                const t = Number(total);
                if (!Number.isFinite(u) || !Number.isFinite(t) || t <= 0) {
                    return '-';
                }
                return `${Math.round((u / t) * 100)}%`;
            }

            function classifyUtilization(pct) {
                const n = Number(pct);
                if (!Number.isFinite(n)) {
                    return 'unknown';
                }
                if (n >= 90) {
                    return 'critical';
                }
                if (n >= 75) {
                    return 'warning';
                }
                return 'healthy';
            }

            function toneClass(tone) {
                if (tone === 'critical') {
                    return 'bg-red-100 text-red-700 border-red-200';
                }
                if (tone === 'warning') {
                    return 'bg-amber-100 text-amber-700 border-amber-200';
                }
                if (tone === 'healthy') {
                    return 'bg-emerald-100 text-emerald-700 border-emerald-200';
                }
                return 'bg-slate-100 text-slate-700 border-slate-200';
            }

            function toneLabel(tone) {
                if (tone === 'critical') {
                    return 'critical';
                }
                if (tone === 'warning') {
                    return 'warning';
                }
                if (tone === 'healthy') {
                    return 'healthy';
                }
                return 'unknown';
            }

            function renderInventory(inv) {
                if (!inventoryPanelNode) {
                    return;
                }
                if (!inv || typeof inv !== 'object') {
                    inventoryPanelNode.innerHTML = '<p class="text-sm text-slate-500">No inventory data yet.</p>';
                    return;
                }

                const cpu = inv.cpu || {};
                const mem = inv.memory || {};
                const disks = Array.isArray(inv.disks) ? inv.disks : [];
                const net = inv.network || {};
                const adapters = Array.isArray(net.adapters) ? net.adapters : [];
                const sessions = Array.isArray(inv.logged_in_sessions) ? inv.logged_in_sessions : [];
                const software = Array.isArray(inv.installed_software) ? inv.installed_software : [];
                const processes = Array.isArray(inv.running_processes) ? inv.running_processes : [];
                const services = Array.isArray(inv.services) ? inv.services : [];
                const geo = inv.geolocation || null;

                const totalMem = Number(mem.total_bytes || 0);
                const availMem = Number(mem.available_bytes || 0);
                const usedMem = (Number.isFinite(totalMem) && Number.isFinite(availMem)) ? Math.max(0, totalMem - availMem) : 0;
                const memPct = (Number.isFinite(totalMem) && totalMem > 0) ? Math.round((usedMem / totalMem) * 100) : null;
                const memTone = classifyUtilization(memPct);
                const diskPcts = disks.map((d) => {
                    const total = Number(d.total_bytes || 0);
                    const free = Number(d.free_bytes || 0);
                    if (!Number.isFinite(total) || total <= 0 || !Number.isFinite(free)) {
                        return null;
                    }
                    const used = Math.max(0, total - free);
                    return Math.round((used / total) * 100);
                }).filter((v) => Number.isFinite(v));
                const storageTone = diskPcts.length > 0 ? classifyUtilization(Math.max(...diskPcts)) : 'unknown';
                const runningServices = services.filter((s) => String(s.state || '').toUpperCase().includes('RUNNING')).length;
                const serviceHealth = services.length > 0 ? Math.round((runningServices / services.length) * 100) : null;
                const serviceTone = serviceHealth === null ? 'unknown' : (serviceHealth >= 50 ? 'healthy' : 'warning');

                inventoryPanelNode.innerHTML = `
                    <div class="grid gap-3 md:grid-cols-4">
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-[11px] text-slate-500 flex items-center gap-1"><span class="inline-flex text-sky-600"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M9 2h2v2h2V2h2v2h2a2 2 0 0 1 2 2v2h2v2h-2v2h2v2h-2v2h2v2h-2v2a2 2 0 0 1-2 2h-2v2h-2v-2h-2v2H9v-2H7a2 2 0 0 1-2-2v-2H3v-2h2v-2H3v-2h2v-2H3V8h2V6a2 2 0 0 1 2-2h2V2zm-2 4v12h10V6H7z"/></svg></span>CPU</p>
                            <p class="text-sm font-semibold">${escapeHtml(cpu.model || '-')}</p>
                            <p class="text-xs text-slate-600">${escapeHtml(cpu.logical_cores || '-')} cores | ${escapeHtml(cpu.architecture || '-')}</p>
                        </div>
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-[11px] text-slate-500 flex items-center gap-1"><span class="inline-flex text-indigo-600"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M3 7h18v10H3V7zm2 2v6h14V9H5zm1 7h2v2H6v-2zm4 0h2v2h-2v-2zm4 0h2v2h-2v-2z"/></svg></span>Memory</p>
                            <p class="text-sm font-semibold">${fmtBytes(usedMem)} / ${fmtBytes(totalMem)}</p>
                            <p class="text-xs text-slate-600">Used: ${fmtPct(usedMem, totalMem)} <span class="ml-1 rounded border px-1.5 py-0.5 ${toneClass(memTone)}">${toneLabel(memTone)}</span></p>
                        </div>
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-[11px] text-slate-500 flex items-center gap-1"><span class="inline-flex text-slate-700"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3H4V6zm0 5h16v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7zm3 2a1 1 0 1 0 0 2h.01a1 1 0 1 0 0-2H7z"/></svg></span>Disks</p>
                            <p class="text-sm font-semibold">${disks.length}</p>
                            <p class="text-xs text-slate-600">Fixed drives detected</p>
                        </div>
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-[11px] text-slate-500 flex items-center gap-1"><span class="inline-flex text-emerald-600"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3a9 9 0 0 0-9 9h2a7 7 0 0 1 14 0h2a9 9 0 0 0-9-9zm0 4a5 5 0 0 0-5 5h2a3 3 0 0 1 6 0h2a5 5 0 0 0-5-5zm-1 6v4h2v-4h-2zm0 6v2h2v-2h-2z"/></svg></span>Network</p>
                            <p class="text-sm font-semibold">${adapters.length} adapters</p>
                            <p class="text-xs text-slate-600">${escapeHtml((net.ip_addresses || []).slice(0, 2).join(', ') || '-')}</p>
                        </div>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-2">
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-sm font-semibold mb-2 flex items-center gap-1"><span class="inline-flex text-amber-600"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l8 4v6c0 5-3.4 9.7-8 11-4.6-1.3-8-6-8-11V6l8-4zm0 5a3 3 0 0 0-3 3v3h2v-3a1 1 0 1 1 2 0v4h-2v2h4v-6a3 3 0 0 0-3-3z"/></svg></span>Storage <span class="ml-1 rounded border px-1.5 py-0.5 ${toneClass(storageTone)}">${toneLabel(storageTone)}</span></p>
                            <div class="space-y-1 max-h-40 overflow-auto text-xs">
                                ${disks.length ? disks.map((d) => {
                                    const total = Number(d.total_bytes || 0);
                                    const free = Number(d.free_bytes || 0);
                                    const used = Math.max(0, total - free);
                                    return `<p><span class="font-mono">${escapeHtml(d.name || '-')}</span> ${fmtBytes(used)} / ${fmtBytes(total)} (${fmtPct(used, total)})</p>`;
                                }).join('') : '<p class="text-slate-500">No disk data.</p>'}
                            </div>
                        </div>
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-sm font-semibold mb-2 flex items-center gap-1"><span class="inline-flex text-sky-600"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4.33 0-8 2.17-8 5v1h16v-1c0-2.83-3.67-5-8-5z"/></svg></span>Logged-in Sessions</p>
                            <div class="space-y-1 max-h-40 overflow-auto text-xs">
                                ${sessions.length ? sessions.map((s) => `<p>${escapeHtml(s.username || '-')} | ${escapeHtml(s.state || '-')} | ${escapeHtml(s.session_name || '-')}</p>`).join('') : '<p class="text-slate-500">No session data.</p>'}
                            </div>
                        </div>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-3">
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-sm font-semibold mb-2">Installed Software (${software.length})</p>
                            <div class="space-y-1 max-h-44 overflow-auto text-xs">
                                ${software.length ? software.slice(0, 40).map((s) => `<p>${escapeHtml(s.name || '-')} ${s.version ? `<span class="text-slate-500">v${escapeHtml(s.version)}</span>` : ''}</p>`).join('') : '<p class="text-slate-500">No software data.</p>'}
                            </div>
                        </div>
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-sm font-semibold mb-2">Top Processes</p>
                            <div class="space-y-1 max-h-44 overflow-auto text-xs">
                                ${processes.length ? processes.slice(0, 40).map((p) => `<p>${escapeHtml(p.name || '-')} <span class="text-slate-500">PID ${escapeHtml(p.pid || '-')} | ${fmtBytes(p.memory_bytes)}</span></p>`).join('') : '<p class="text-slate-500">No process data.</p>'}
                            </div>
                        </div>
                        <div class="rounded border border-slate-200 p-3">
                            <p class="text-sm font-semibold mb-2">Services (${services.length})</p>
                            <div class="space-y-1 max-h-44 overflow-auto text-xs">
                                ${services.length ? services.slice(0, 50).map((s) => {
                                    const state = String(s.state || '').toUpperCase();
                                    const serviceStateTone = state.includes('RUNNING') ? 'healthy' : (state.includes('STOPPED') ? 'warning' : 'unknown');
                                    return `<div class="rounded border px-2 py-1 ${toneClass(serviceStateTone)}">${escapeHtml(s.name || '-')} <span>(${escapeHtml(s.state || '-')})</span></div>`;
                                }).join('') : '<p class="text-slate-500">No service data.</p>'}
                            </div>
                        </div>
                    </div>
                    <div class="rounded border border-slate-200 p-3 text-xs">
                        <p class="text-sm font-semibold mb-1">Geolocation</p>
                        ${geo && typeof geo === 'object'
                            ? '<p class="text-slate-600">Geolocation data collected.</p>'
                            : '<p class="text-slate-500">No geolocation data.</p>'}
                    </div>
                `;
            }

            function renderRuntimeDiagnostics(diag) {
                if (!runtimePanelNode) {
                    return;
                }

                const bypass = !!(diag && diag.signature_bypass_enabled === true);
                const debug = !!(diag && diag.signature_debug_enabled === true);
                const pid = diag && diag.process_id ? diag.process_id : '-';
                const path = diag && diag.process_path ? String(diag.process_path) : '-';

                runtimePanelNode.innerHTML = `
                    <div class="rounded border border-slate-200 p-3 text-xs">
                        <p class="text-slate-500">Signature Bypass</p>
                        <p class="font-semibold">${bypass ? '<span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5">enabled</span>' : '<span class="rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5">disabled</span>'}</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3 text-xs">
                        <p class="text-slate-500">Signature Debug</p>
                        <p class="font-semibold">${debug ? '<span class="rounded-full bg-sky-100 text-sky-700 px-2 py-0.5">enabled</span>' : '<span class="rounded-full bg-slate-100 text-slate-700 px-2 py-0.5">disabled</span>'}</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3 text-xs">
                        <p class="text-slate-500">Process</p>
                        <p class="font-mono">PID: ${escapeHtml(pid)}</p>
                        <p class="truncate" title="${escapeHtml(path)}">${escapeHtml(path)}</p>
                    </div>
                `;
            }

            async function poll() {
                if (polling) {
                    return;
                }
                polling = true;
                try {
                    const res = await fetch(liveUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                    if (!res.ok) {
                        return;
                    }
                    const data = await res.json();
                    if (data?.device) {
                        applyDeviceStatus(data.device.status ?? 'unknown');
                        checkinNode.textContent = `Last check-in: ${data.device.last_seen_human ?? 'never'}`;
                        buildNode.textContent = `Build: ${data.device.agent_build ?? 'unknown'}`;
                        const osLabel = @json(($device->os_name ?? '').' '.($device->os_version ?? ''));
                        versionLineNode.textContent = `${osLabel.trim()} | Agent ${data.device.agent_version ?? 'unknown'}`;
                        if (inventoryUpdatedNode) {
                            inventoryUpdatedNode.textContent = `Synced: ${data.device.inventory_updated_at || 'n/a'}`;
                        }
                        if (inventoryCollectedNode) {
                            inventoryCollectedNode.textContent = `Snapshot: ${(data.device.inventory && data.device.inventory.collected_at) ? data.device.inventory.collected_at : 'n/a'}`;
                        }
                        if (runtimeUpdatedNode) {
                            runtimeUpdatedNode.textContent = `Updated: ${data.device.runtime_diagnostics_updated_at || 'n/a'}`;
                        }
                        if (inventoryJsonNode) {
                            inventoryJsonNode.textContent = JSON.stringify(data.device.inventory || null, null, 2);
                        }
                        renderInventory(data.device.inventory || null);
                        renderRuntimeDiagnostics(data.device.runtime_diagnostics || null);
                    }

                    const runs = Array.isArray(data?.job_runs) ? data.job_runs : [];
                    renderRuns(runs);
                    for (const run of runs) {
                        if (!run || !run.id) {
                            continue;
                        }
                        if (run.status === 'success' && rememberNotification(run.id, run.status)) {
                            toast(`Job ${run.id} succeeded`, true);
                        } else if (run.status === 'failed' && rememberNotification(run.id, run.status)) {
                            const err = run.last_error ? ` (${run.last_error})` : '';
                            toast(`Job ${run.id} failed${err}`, false);
                        }
                    }
                } catch (e) {
                    // Keep UI stable if polling fails temporarily.
                } finally {
                    polling = false;
                }
            }

            renderInventory(initialInventory || null);
            renderRuntimeDiagnostics(initialRuntimeDiagnostics || null);
            applyDeviceStatus(@json($device->status));
            poll();
            setInterval(poll, 5000);
        })();
    </script>
    <script>
        (function () {
            const modal = document.getElementById('device-action-modal');
            const titleNode = document.getElementById('device-action-modal-title');
            const descNode = document.getElementById('device-action-modal-description');
            const passwordInput = document.getElementById('device-action-password');
            const errorNode = document.getElementById('device-action-modal-error');
            const cancelBtn = document.getElementById('device-action-cancel');
            const confirmBtn = document.getElementById('device-action-confirm');
            let activeForm = null;

            function closeModal() {
                modal?.classList.add('hidden');
                activeForm = null;
                if (passwordInput) passwordInput.value = '';
                errorNode?.classList.add('hidden');
            }

            function openModal(form) {
                activeForm = form;
                const actionLabel = String(form?.dataset?.actionLabel || '').toLowerCase();
                const actionTitle = String(form?.dataset?.actionTitle || 'Confirm Action');
                const actionDescription = String(form?.dataset?.actionDescription || 'Proceed with this action?');
                if (titleNode) titleNode.textContent = actionTitle;
                if (descNode) descNode.textContent = actionDescription;
                if (descNode) {
                    descNode.className = actionLabel === 'uninstall'
                        ? 'rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700'
                        : 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700';
                }
                if (confirmBtn) {
                    confirmBtn.textContent = actionLabel === 'uninstall' ? 'Confirm Uninstall' : 'Confirm Reboot';
                    confirmBtn.className = actionLabel === 'uninstall'
                        ? 'rounded-lg bg-red-600 px-3 py-2 text-xs font-medium text-white hover:bg-red-700'
                        : 'rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700';
                }
                modal?.classList.remove('hidden');
                if (passwordInput) {
                    passwordInput.value = '';
                    passwordInput.focus();
                }
                errorNode?.classList.add('hidden');
            }

            document.querySelectorAll('form.js-sensitive-action').forEach((form) => {
                form.addEventListener('submit', function (event) {
                    if (form.dataset.confirmed === '1') {
                        return true;
                    }
                    event.preventDefault();
                    openModal(form);
                    return false;
                });
            });

            cancelBtn?.addEventListener('click', closeModal);
            modal?.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) closeModal();
            });

            confirmBtn?.addEventListener('click', function () {
                if (!activeForm) return;
                const password = String(passwordInput?.value || '').trim();
                if (password === '') {
                    errorNode?.classList.remove('hidden');
                    return;
                }
                const pwdField = activeForm.querySelector('input[name="admin_password"]');
                if (!pwdField) {
                    closeModal();
                    return;
                }
                pwdField.value = password;
                activeForm.dataset.confirmed = '1';
                const formToSubmit = activeForm;
                closeModal();
                formToSubmit.submit();
            });
        })();
    </script>
</x-admin-layout>
