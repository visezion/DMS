<x-admin-layout title="Policy Detail" heading="Policy Detail">
    <style>
        .policy-shell {
            border-color: #d7deea;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .policy-subtle {
            border: 1px solid #d7deea;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }
    </style>

    <div class="rounded-2xl border p-5 mb-4 policy-shell bg-gradient-to-br from-slate-50 via-white to-slate-100">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Policy Overview</p>
                <h3 class="text-2xl font-semibold text-slate-900">{{ $policy->name }}</h3>
                <p class="text-sm text-slate-600 mt-1">{{ $policy->slug }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full border border-slate-300 bg-white px-2 py-1 text-slate-700">Category: {{ $policy->category }}</span>
                    <span class="rounded-full border border-slate-300 bg-white px-2 py-1 text-slate-700">Status: {{ $policy->status }}</span>
                    <span class="rounded-full border border-slate-300 bg-white px-2 py-1 text-slate-700">Versions: {{ $versions->count() }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.policies') }}" class="rounded bg-slate-100 text-slate-700 px-3 py-2 text-sm">Back to Policies</a>
                <form method="POST" action="{{ route('admin.policies.delete', $policy->id) }}" onsubmit="return confirm('Delete this policy? Versions must be deleted first.')">
                    @csrf
                    @method('DELETE')
                    <button class="rounded bg-rose-600 text-white px-3 py-2 text-sm">Delete Policy</button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-5">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 policy-shell lg:col-span-3">
            <h3 class="font-semibold mb-3">Policy Settings</h3>
            <form method="POST" action="{{ route('admin.policies.update', $policy->id) }}" class="grid gap-2">
                @csrf
                @method('PATCH')
                <label class="text-xs text-slate-500">Policy Name</label>
                <input name="name" value="{{ $policy->name }}" required class="rounded border border-slate-300 px-3 py-2"/>
                <label class="text-xs text-slate-500">Slug</label>
                <input name="slug" value="{{ $policy->slug }}" required class="rounded border border-slate-300 px-3 py-2"/>
                <label class="text-xs text-slate-500">Category</label>
                <select name="category" required class="rounded border border-slate-300 px-3 py-2 bg-white">
                    @php
                        $categories = collect($policyCategories ?? []);
                        $hasCurrentCategory = $categories->contains($policy->category);
                    @endphp
                    @if(!$hasCurrentCategory && !empty($policy->category))
                        <option value="{{ $policy->category }}" selected>{{ $policy->category }}</option>
                    @endif
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}" @selected($policy->category === $cat)>{{ $cat }}</option>
                    @endforeach
                </select>
                <label class="text-xs text-slate-500">Status</label>
                <select name="status" class="rounded border border-slate-300 px-3 py-2">
                    <option value="draft" @selected($policy->status === 'draft')>draft</option>
                    <option value="active" @selected($policy->status === 'active')>active</option>
                    <option value="retired" @selected($policy->status === 'retired')>retired</option>
                </select>
                <button class="rounded bg-ink text-white px-3 py-2 text-sm">Save Policy Changes</button>
            </form>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 space-y-2 policy-shell lg:col-span-2">
            <h3 class="font-semibold">One Click Policy Catalog</h3>
            <p class="text-xs text-slate-600">Preset library used by publish/edit forms.</p>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700 space-y-2 max-h-64 overflow-auto">
                @foreach($rulePresetJson as $presetType => $presetConfig)
                    <div class="policy-subtle rounded-lg p-2">
                        <p class="font-semibold text-slate-800">{{ $presetType }}</p>
                        <p class="font-mono text-[11px] break-all mt-1">{{ json_encode($presetConfig, JSON_UNESCAPED_SLASHES) }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="rounded-2xl bg-white border border-slate-200 p-4 space-y-3 mt-4 policy-shell">
        <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold">Publish New Version</p>
            <p class="text-xs text-slate-500">Auto mode fills apply + remove details. Enable custom only when needed.</p>
        </div>
        <form method="POST" action="{{ route('admin.policies.versions.create', $policy->id) }}" class="grid gap-2 md:grid-cols-2 policy-rule-form">
            @csrf
            <label class="text-xs text-slate-500">Version Number</label>
            <input name="version_number" type="number" min="1" value="{{ $nextVersion }}" required class="rounded border border-slate-300 px-2 py-1"/>
            <label class="text-xs text-slate-500">Apply Mode</label>
            <select name="apply_mode" class="rounded border border-slate-300 px-2 py-1 apply-mode-select">
                <option value="json">Apply mode: JSON</option>
                <option value="command">Apply mode: Command</option>
            </select>
            <label class="text-xs text-slate-500">Rule Type</label>
            <select name="rule_type" class="rounded border border-slate-300 px-2 py-1 rule-type-select apply-json-field">
                <option value="firewall">firewall</option>
                <option value="registry">registry</option>
                <option value="bitlocker">bitlocker</option>
                <option value="local_group">local_group</option>
                <option value="windows_update">windows_update</option>
                <option value="scheduled_task">scheduled_task</option>
                <option value="command">command</option>
                <option value="baseline_profile">baseline_profile</option>
                <option value="reboot_restore_mode">reboot_restore_mode</option>
                <option value="uwf">uwf</option>
            </select>
            <label class="text-xs text-slate-500">Assignment Target Type</label>
            <select name="target_type" class="rounded border border-slate-300 px-2 py-1">
                <option value="">No assignment</option>
                <option value="group">group</option>
                <option value="device">device</option>
            </select>
            <label class="text-xs text-slate-500">Assignment Target</label>
            <select name="target_id" class="rounded border border-slate-300 px-2 py-1">
                <option value="" data-kind="all">Select target</option>
                @foreach($groups as $group)
                    <option value="{{ $group->id }}" data-kind="group">Group: {{ $group->name }}</option>
                @endforeach
                @foreach($devices as $device)
                    <option value="{{ $device->id }}" data-kind="device">Device: {{ $device->hostname }}</option>
                @endforeach
            </select>
            <label class="text-xs flex items-center gap-2 md:col-span-2">
                <input type="checkbox" name="assign_now" value="1" checked />
                Assign now and queue apply_policy job
            </label>
            <div class="md:col-span-2 flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                <button type="button" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs use-preset-btn">Use Preset</button>
                <select class="rounded border border-slate-300 px-2 py-1 text-xs policy-catalog-select">
                    <option value="">Catalog preset...</option>
                    @foreach($policyCatalog as $catalogItem)
                        <option value='@json($catalogItem)'>{{ $catalogItem['label'] }}</option>
                    @endforeach
                </select>
                <button type="button" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs apply-catalog-btn">Apply Catalog</button>
                <label class="text-xs flex items-center gap-2">
                    <input type="checkbox" class="custom-json-toggle" />
                    custom JSON / command
                </label>
            </div>
            <p class="md:col-span-2 text-[11px] text-slate-500">Auto mode fills apply + remove details (including remove path/task when possible). Enable custom to edit manually.</p>
            <p class="md:col-span-2 text-xs text-slate-600 catalog-info-hint hidden"></p>
            <label class="md:col-span-2 text-xs text-slate-500">Apply JSON</label>
            <textarea name="rule_json" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-28 rule-json-input apply-json-field" required>{{ json_encode($rulePresetJson['firewall'] ?? ['enabled' => true, 'profiles' => ['domain', 'private', 'public']], JSON_UNESCAPED_SLASHES) }}</textarea>
            <div class="md:col-span-2 grid gap-2 md:grid-cols-2 rounded border border-slate-200 bg-slate-50 p-3 apply-uwf-field hidden">
                <p class="md:col-span-2 text-xs font-semibold text-slate-700">UWF Options</p>
                <label class="text-xs text-slate-500">Ensure</label>
                <select name="apply_uwf_ensure" class="rounded border border-slate-300 px-2 py-1">
                    <option value="present" selected>present (enable/protect)</option>
                    <option value="absent">absent (disable/unprotect)</option>
                </select>
                <label class="text-xs text-slate-500">Volume</label>
                <input name="apply_uwf_volume" value="C:" class="rounded border border-slate-300 px-2 py-1" />
                <label class="text-xs flex items-center gap-2">
                    <input type="checkbox" name="apply_uwf_enable_feature" value="1" checked />
                    enable feature
                </label>
                <label class="text-xs flex items-center gap-2">
                    <input type="checkbox" name="apply_uwf_enable_filter" value="1" checked />
                    enable filter
                </label>
                <label class="text-xs flex items-center gap-2">
                    <input type="checkbox" name="apply_uwf_protect_volume" value="1" checked />
                    protect volume
                </label>
                <label class="text-xs flex items-center gap-2">
                    <input type="checkbox" name="apply_uwf_reboot_now" value="1" />
                    reboot now
                </label>
                <label class="text-xs flex items-center gap-2">
                    <input type="checkbox" name="apply_uwf_reboot_if_pending" value="1" checked />
                    reboot if pending
                </label>
                <div></div>
                <label class="text-xs text-slate-500">Max Reboot Attempts</label>
                <input name="apply_uwf_max_reboot_attempts" type="number" min="1" max="10" value="2" class="rounded border border-slate-300 px-2 py-1" />
                <label class="text-xs text-slate-500">Reboot Cooldown (Minutes)</label>
                <input name="apply_uwf_reboot_cooldown_minutes" type="number" min="1" max="240" value="30" class="rounded border border-slate-300 px-2 py-1" />
                <label class="md:col-span-2 text-xs text-slate-500">Reboot Command (optional)</label>
                <input name="apply_uwf_reboot_command" value='shutdown.exe /r /t 30 /c "Enabling UWF protection"' class="rounded border border-slate-300 px-2 py-1 md:col-span-2" />
                <label class="md:col-span-2 text-xs text-slate-500">File Exclusions (one per line)</label>
                <textarea name="apply_uwf_file_exclusions" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-16" placeholder="C:\ProgramData\DMS\State&#10;C:\ProgramData\DMS\Logs&#10;C:\ProgramData\DMS\Uwf">C:\ProgramData\DMS\State
C:\ProgramData\DMS\Logs
C:\ProgramData\DMS\Uwf</textarea>
                <label class="md:col-span-2 text-xs text-slate-500">Registry Exclusions (one per line)</label>
                <textarea name="apply_uwf_registry_exclusions" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-16" placeholder="HKLM\SOFTWARE\DMS">HKLM\SOFTWARE\DMS</textarea>
                <label class="md:col-span-2 text-xs flex items-center gap-2">
                    <input type="checkbox" name="apply_uwf_fail_on_unsupported_edition" value="1" />
                    fail when Windows edition does not support UWF
                </label>
                <p class="md:col-span-2 text-xs font-semibold text-slate-700 mt-1">Overlay Options (optional)</p>
                <label class="text-xs text-slate-500">Overlay Type</label>
                <select name="apply_uwf_overlay_type" class="rounded border border-slate-300 px-2 py-1">
                    <option value="">leave unchanged</option>
                    <option value="ram">RAM</option>
                    <option value="disk">DISK</option>
                </select>
                <label class="text-xs text-slate-500">Overlay Max Size (MB)</label>
                <input name="apply_uwf_overlay_max_size_mb" type="number" min="128" max="1048576" placeholder="e.g. 4096" class="rounded border border-slate-300 px-2 py-1" />
                <label class="text-xs text-slate-500">Overlay Warning Threshold (MB)</label>
                <input name="apply_uwf_overlay_warning_threshold_mb" type="number" min="64" max="1048576" placeholder="e.g. 3072" class="rounded border border-slate-300 px-2 py-1" />
                <label class="text-xs text-slate-500">Overlay Critical Threshold (MB)</label>
                <input name="apply_uwf_overlay_critical_threshold_mb" type="number" min="64" max="1048576" placeholder="e.g. 3584" class="rounded border border-slate-300 px-2 py-1" />
            </div>
            <label class="md:col-span-2 text-xs text-slate-500 apply-command-label hidden">Apply Command</label>
            <textarea name="apply_command" placeholder="Apply command (example: reg add HKLM\\...)" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-24 apply-command-field hidden"></textarea>
            <label class="text-xs text-slate-500 apply-command-option-label hidden">Run As</label>
            <select name="apply_run_as" class="rounded border border-slate-300 px-2 py-1 apply-command-option-field hidden">
                <option value="default" selected>default (agent context)</option>
                <option value="elevated">elevated (admin)</option>
                <option value="system">system</option>
            </select>
            <label class="text-xs text-slate-500 apply-command-option-label hidden">Timeout Seconds</label>
            <input name="apply_timeout_seconds" type="number" min="30" max="3600" value="300" class="rounded border border-slate-300 px-2 py-1 apply-command-option-field hidden" />
            <label class="md:col-span-2 text-xs text-slate-500">Remove Mode</label>
            <select name="remove_mode" class="rounded border border-slate-300 px-2 py-1 remove-mode-select md:col-span-2">
                <option value="auto">Remove mode: Auto (generated)</option>
                <option value="json">Remove mode: JSON</option>
                <option value="command">Remove mode: Command</option>
            </select>
            <label class="text-xs text-slate-500 remove-json-type-label hidden">Remove Rule Type</label>
            <select name="remove_rule_type" class="rounded border border-slate-300 px-2 py-1 remove-json-field">
                <option value="registry">registry</option>
                <option value="scheduled_task">scheduled_task</option>
                <option value="command">command</option>
                <option value="firewall">firewall</option>
                <option value="bitlocker">bitlocker</option>
                <option value="local_group">local_group</option>
                <option value="windows_update">windows_update</option>
                <option value="reboot_restore_mode">reboot_restore_mode</option>
                <option value="uwf">uwf</option>
            </select>
            <label class="md:col-span-2 text-xs text-slate-500 remove-json-label hidden">Remove JSON</label>
            <textarea name="remove_rule_json" placeholder='{"path":"HKLM\\...","name":"NoDrives","type":"DWORD","ensure":"absent"}' class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-24 remove-json-field hidden"></textarea>
            <label class="md:col-span-2 text-xs text-slate-500 remove-command-label hidden">Remove Command</label>
            <textarea name="remove_command" placeholder="Remove command (example: reg delete HKLM\\... /v NoDrives /f)" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-24 remove-command-field hidden"></textarea>
            <button class="rounded bg-skyline text-white px-3 py-2 text-sm md:col-span-2">Publish Version</button>
        </form>
    </div>

    <div class="space-y-4 mt-4">
        @forelse($versions as $version)
            @php
                $rule = ($rulesByVersion[$version->id] ?? collect())->first();
                $assignments = $assignmentsByVersion[$version->id] ?? collect();
                $removeProfile = $removalProfilesByVersion[$version->id] ?? null;
                $removeRule = is_array($removeProfile['rules'][0] ?? null) ? $removeProfile['rules'][0] : null;
                $removeRuleType = strtolower((string) ($removeRule['type'] ?? ''));
                $removeRuleConfig = is_array($removeRule['config'] ?? null) ? $removeRule['config'] : null;
                $removeModeDefault = $removeRuleType === 'command' ? 'command' : ($removeRuleConfig ? 'json' : 'auto');
                $removeRuleJsonDefault = $removeRuleConfig ? json_encode($removeRuleConfig, JSON_UNESCAPED_SLASHES) : '';
                $removeCommandDefault = $removeRuleType === 'command' ? (string) ($removeRuleConfig['command'] ?? '') : '';
                $applyModeDefault = ($rule && ($rule->rule_type ?? '') === 'command') ? 'command' : 'json';
                $applyCommandDefault = ($applyModeDefault === 'command' && is_array($rule->rule_config ?? null)) ? (string) (($rule->rule_config ?? [])['command'] ?? '') : '';
                $applyRunAsDefault = ($applyModeDefault === 'command' && is_array($rule->rule_config ?? null))
                    ? (string) (($rule->rule_config ?? [])['run_as'] ?? 'default')
                    : 'default';
                if (!in_array($applyRunAsDefault, ['default', 'elevated', 'system'], true)) {
                    $applyRunAsDefault = 'default';
                }
                $applyTimeoutDefault = ($applyModeDefault === 'command' && is_array($rule->rule_config ?? null))
                    ? (int) (($rule->rule_config ?? [])['timeout_seconds'] ?? 300)
                    : 300;
                $applyTimeoutDefault = max(30, min(3600, $applyTimeoutDefault));
                $uwfConfigDefault = is_array($rule->rule_config ?? null) ? ($rule->rule_config ?? []) : [];
                $applyUwfEnsureDefault = strtolower((string) ($uwfConfigDefault['ensure'] ?? 'present'));
                if (!in_array($applyUwfEnsureDefault, ['present', 'absent'], true)) {
                    $applyUwfEnsureDefault = 'present';
                }
                $applyUwfVolumeDefault = trim((string) ($uwfConfigDefault['volume'] ?? 'C:'));
                if ($applyUwfVolumeDefault === '') {
                    $applyUwfVolumeDefault = 'C:';
                }
                $applyUwfEnableFeatureDefault = array_key_exists('enable_feature', $uwfConfigDefault) ? (bool) $uwfConfigDefault['enable_feature'] : true;
                $applyUwfEnableFilterDefault = array_key_exists('enable_filter', $uwfConfigDefault) ? (bool) $uwfConfigDefault['enable_filter'] : true;
                $applyUwfProtectVolumeDefault = array_key_exists('protect_volume', $uwfConfigDefault) ? (bool) $uwfConfigDefault['protect_volume'] : true;
                $applyUwfRebootNowDefault = (bool) ($uwfConfigDefault['reboot_now'] ?? false);
                $applyUwfRebootIfPendingDefault = array_key_exists('reboot_if_pending', $uwfConfigDefault) ? (bool) $uwfConfigDefault['reboot_if_pending'] : true;
                $applyUwfMaxRebootAttemptsDefault = max(1, min(10, (int) ($uwfConfigDefault['max_reboot_attempts'] ?? 2)));
                $applyUwfRebootCooldownDefault = max(1, min(240, (int) ($uwfConfigDefault['reboot_cooldown_minutes'] ?? 30)));
                $applyUwfRebootCommandDefault = (string) ($uwfConfigDefault['reboot_command'] ?? 'shutdown.exe /r /t 30 /c "Enabling UWF protection"');
                $applyUwfFileExclusionsDefault = is_array($uwfConfigDefault['file_exclusions'] ?? null)
                    ? array_values(array_filter(array_map(fn ($v) => trim((string) $v), $uwfConfigDefault['file_exclusions']), fn ($v) => $v !== ''))
                    : ['C:\\ProgramData\\DMS\\State', 'C:\\ProgramData\\DMS\\Logs', 'C:\\ProgramData\\DMS\\Uwf'];
                $applyUwfRegistryExclusionsDefault = is_array($uwfConfigDefault['registry_exclusions'] ?? null)
                    ? array_values(array_filter(array_map(fn ($v) => trim((string) $v), $uwfConfigDefault['registry_exclusions']), fn ($v) => $v !== ''))
                    : ['HKLM\\SOFTWARE\\DMS'];
                $applyUwfFailUnsupportedEditionDefault = (bool) ($uwfConfigDefault['fail_on_unsupported_edition'] ?? false);
                $applyUwfOverlayTypeDefault = strtolower(trim((string) ($uwfConfigDefault['overlay_type'] ?? '')));
                if (!in_array($applyUwfOverlayTypeDefault, ['ram', 'disk'], true)) {
                    $applyUwfOverlayTypeDefault = '';
                }
                $applyUwfOverlayMaxSizeDefault = array_key_exists('overlay_max_size_mb', $uwfConfigDefault)
                    ? max(128, min(1048576, (int) $uwfConfigDefault['overlay_max_size_mb']))
                    : null;
                $applyUwfOverlayWarningDefault = array_key_exists('overlay_warning_threshold_mb', $uwfConfigDefault)
                    ? max(64, min(1048576, (int) $uwfConfigDefault['overlay_warning_threshold_mb']))
                    : null;
                $applyUwfOverlayCriticalDefault = array_key_exists('overlay_critical_threshold_mb', $uwfConfigDefault)
                    ? max(64, min(1048576, (int) $uwfConfigDefault['overlay_critical_threshold_mb']))
                    : null;
            @endphp
            <div class="rounded-xl bg-white border border-slate-200 p-3 policy-shell">
                <details class="group" @if($loop->first) open @endif>
                    <summary class="list-none cursor-pointer flex items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-sm">v{{ $version->version_number }}</p>
                            <span class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] text-slate-700">{{ $version->status }}</span>
                            <span class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] text-slate-700">{{ $assignments->count() }} assignments</span>
                        </div>
                        <span class="text-xs text-slate-500 group-open:hidden">Expand</span>
                        <span class="text-xs text-slate-500 hidden group-open:inline">Collapse</span>
                    </summary>

                    <div class="mt-3 space-y-3">
                        <div class="flex justify-end">
                            <form method="POST" action="{{ route('admin.policies.versions.delete', [$policy->id, $version->id]) }}" onsubmit="return confirm('Delete this version and all assignments?')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded bg-rose-600 text-white px-2 py-1 text-xs">Delete Version</button>
                            </form>
                        </div>

                <form method="POST" action="{{ route('admin.policies.versions.update', [$policy->id, $version->id]) }}" class="grid gap-2 md:grid-cols-2 policy-rule-form rounded-xl border border-slate-200 bg-slate-50 p-3">
                    @csrf
                    @method('PATCH')
                    <label class="text-xs text-slate-500">Version Number</label>
                    <input name="version_number" type="number" min="1" value="{{ $version->version_number }}" required class="rounded border border-slate-300 px-2 py-1"/>
                    <label class="text-xs text-slate-500">Version Status</label>
                    <select name="status" class="rounded border border-slate-300 px-2 py-1">
                        <option value="draft" @selected($version->status === 'draft')>draft</option>
                        <option value="active" @selected($version->status === 'active')>active</option>
                        <option value="retired" @selected($version->status === 'retired')>retired</option>
                    </select>
                    <label class="text-xs text-slate-500">Apply Mode</label>
                    <select name="apply_mode" class="rounded border border-slate-300 px-2 py-1 apply-mode-select">
                        <option value="json" @selected($applyModeDefault === 'json')>Apply mode: JSON</option>
                        <option value="command" @selected($applyModeDefault === 'command')>Apply mode: Command</option>
                    </select>
                    <label class="text-xs text-slate-500">Rule Type</label>
                    <select name="rule_type" class="rounded border border-slate-300 px-2 py-1 rule-type-select apply-json-field">
                        @foreach(['firewall','registry','bitlocker','local_group','windows_update','scheduled_task','command','baseline_profile','reboot_restore_mode','uwf'] as $type)
                            <option value="{{ $type }}" @selected(($rule->rule_type ?? 'registry') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    <label class="text-xs flex items-center gap-2">
                        <input type="checkbox" name="enforce" value="1" @checked(($rule->enforce ?? true)) />
                        enforce
                    </label>
                    <div class="md:col-span-2 flex items-center gap-2">
                        <button type="button" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs use-preset-btn">Use Preset</button>
                        <select class="rounded border border-slate-300 px-2 py-1 text-xs policy-catalog-select">
                            <option value="">Catalog preset...</option>
                            @foreach($policyCatalog as $catalogItem)
                                <option value='@json($catalogItem)'>{{ $catalogItem['label'] }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs apply-catalog-btn">Apply Catalog</button>
                        <label class="text-xs flex items-center gap-2">
                            <input type="checkbox" class="custom-json-toggle" />
                            custom JSON / command
                        </label>
                    </div>
                    <p class="md:col-span-2 text-[11px] text-slate-500">Auto mode fills apply + remove details. Enable custom to edit manually.</p>
                    <p class="md:col-span-2 text-xs text-slate-600 catalog-info-hint hidden"></p>
                    <label class="md:col-span-2 text-xs text-slate-500">Apply JSON</label>
                    <textarea name="rule_json" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-24 rule-json-input apply-json-field" required>{{ json_encode($rule->rule_config ?? ['required' => true], JSON_UNESCAPED_SLASHES) }}</textarea>
                    <div class="md:col-span-2 grid gap-2 md:grid-cols-2 rounded border border-slate-200 bg-white p-3 apply-uwf-field hidden">
                        <p class="md:col-span-2 text-xs font-semibold text-slate-700">UWF Options</p>
                        <label class="text-xs text-slate-500">Ensure</label>
                        <select name="apply_uwf_ensure" class="rounded border border-slate-300 px-2 py-1">
                            <option value="present" @selected($applyUwfEnsureDefault === 'present')>present (enable/protect)</option>
                            <option value="absent" @selected($applyUwfEnsureDefault === 'absent')>absent (disable/unprotect)</option>
                        </select>
                        <label class="text-xs text-slate-500">Volume</label>
                        <input name="apply_uwf_volume" value="{{ $applyUwfVolumeDefault }}" class="rounded border border-slate-300 px-2 py-1" />
                        <label class="text-xs flex items-center gap-2">
                            <input type="checkbox" name="apply_uwf_enable_feature" value="1" @checked($applyUwfEnableFeatureDefault) />
                            enable feature
                        </label>
                        <label class="text-xs flex items-center gap-2">
                            <input type="checkbox" name="apply_uwf_enable_filter" value="1" @checked($applyUwfEnableFilterDefault) />
                            enable filter
                        </label>
                        <label class="text-xs flex items-center gap-2">
                            <input type="checkbox" name="apply_uwf_protect_volume" value="1" @checked($applyUwfProtectVolumeDefault) />
                            protect volume
                        </label>
                        <label class="text-xs flex items-center gap-2">
                            <input type="checkbox" name="apply_uwf_reboot_now" value="1" @checked($applyUwfRebootNowDefault) />
                            reboot now
                        </label>
                        <label class="text-xs flex items-center gap-2">
                            <input type="checkbox" name="apply_uwf_reboot_if_pending" value="1" @checked($applyUwfRebootIfPendingDefault) />
                            reboot if pending
                        </label>
                        <div></div>
                        <label class="text-xs text-slate-500">Max Reboot Attempts</label>
                        <input name="apply_uwf_max_reboot_attempts" type="number" min="1" max="10" value="{{ $applyUwfMaxRebootAttemptsDefault }}" class="rounded border border-slate-300 px-2 py-1" />
                        <label class="text-xs text-slate-500">Reboot Cooldown (Minutes)</label>
                        <input name="apply_uwf_reboot_cooldown_minutes" type="number" min="1" max="240" value="{{ $applyUwfRebootCooldownDefault }}" class="rounded border border-slate-300 px-2 py-1" />
                        <label class="md:col-span-2 text-xs text-slate-500">Reboot Command (optional)</label>
                        <input name="apply_uwf_reboot_command" value="{{ $applyUwfRebootCommandDefault }}" class="rounded border border-slate-300 px-2 py-1 md:col-span-2" />
                        <label class="md:col-span-2 text-xs text-slate-500">File Exclusions (one per line)</label>
                        <textarea name="apply_uwf_file_exclusions" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-16">{{ implode("\n", $applyUwfFileExclusionsDefault) }}</textarea>
                        <label class="md:col-span-2 text-xs text-slate-500">Registry Exclusions (one per line)</label>
                        <textarea name="apply_uwf_registry_exclusions" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-16">{{ implode("\n", $applyUwfRegistryExclusionsDefault) }}</textarea>
                        <label class="md:col-span-2 text-xs flex items-center gap-2">
                            <input type="checkbox" name="apply_uwf_fail_on_unsupported_edition" value="1" @checked($applyUwfFailUnsupportedEditionDefault) />
                            fail when Windows edition does not support UWF
                        </label>
                        <p class="md:col-span-2 text-xs font-semibold text-slate-700 mt-1">Overlay Options (optional)</p>
                        <label class="text-xs text-slate-500">Overlay Type</label>
                        <select name="apply_uwf_overlay_type" class="rounded border border-slate-300 px-2 py-1">
                            <option value="" @selected($applyUwfOverlayTypeDefault === '')>leave unchanged</option>
                            <option value="ram" @selected($applyUwfOverlayTypeDefault === 'ram')>RAM</option>
                            <option value="disk" @selected($applyUwfOverlayTypeDefault === 'disk')>DISK</option>
                        </select>
                        <label class="text-xs text-slate-500">Overlay Max Size (MB)</label>
                        <input name="apply_uwf_overlay_max_size_mb" type="number" min="128" max="1048576" value="{{ $applyUwfOverlayMaxSizeDefault ?? '' }}" class="rounded border border-slate-300 px-2 py-1" />
                        <label class="text-xs text-slate-500">Overlay Warning Threshold (MB)</label>
                        <input name="apply_uwf_overlay_warning_threshold_mb" type="number" min="64" max="1048576" value="{{ $applyUwfOverlayWarningDefault ?? '' }}" class="rounded border border-slate-300 px-2 py-1" />
                        <label class="text-xs text-slate-500">Overlay Critical Threshold (MB)</label>
                        <input name="apply_uwf_overlay_critical_threshold_mb" type="number" min="64" max="1048576" value="{{ $applyUwfOverlayCriticalDefault ?? '' }}" class="rounded border border-slate-300 px-2 py-1" />
                    </div>
                    <label class="md:col-span-2 text-xs text-slate-500 apply-command-label hidden">Apply Command</label>
                    <textarea name="apply_command" placeholder="Apply command" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-24 apply-command-field {{ $applyModeDefault === 'command' ? '' : 'hidden' }}">{{ $applyCommandDefault }}</textarea>
                    <label class="text-xs text-slate-500 apply-command-option-label {{ $applyModeDefault === 'command' ? '' : 'hidden' }}">Run As</label>
                    <select name="apply_run_as" class="rounded border border-slate-300 px-2 py-1 apply-command-option-field {{ $applyModeDefault === 'command' ? '' : 'hidden' }}">
                        <option value="default" @selected($applyRunAsDefault === 'default')>default (agent context)</option>
                        <option value="elevated" @selected($applyRunAsDefault === 'elevated')>elevated (admin)</option>
                        <option value="system" @selected($applyRunAsDefault === 'system')>system</option>
                    </select>
                    <label class="text-xs text-slate-500 apply-command-option-label {{ $applyModeDefault === 'command' ? '' : 'hidden' }}">Timeout Seconds</label>
                    <input name="apply_timeout_seconds" type="number" min="30" max="3600" value="{{ $applyTimeoutDefault }}" class="rounded border border-slate-300 px-2 py-1 apply-command-option-field {{ $applyModeDefault === 'command' ? '' : 'hidden' }}" />
                    <label class="md:col-span-2 text-xs text-slate-500">Remove Mode</label>
                    <select name="remove_mode" class="rounded border border-slate-300 px-2 py-1 remove-mode-select md:col-span-2">
                        <option value="auto" @selected($removeModeDefault === 'auto')>Remove mode: Auto (generated)</option>
                        <option value="json" @selected($removeModeDefault === 'json')>Remove mode: JSON</option>
                        <option value="command" @selected($removeModeDefault === 'command')>Remove mode: Command</option>
                    </select>
                    <label class="text-xs text-slate-500 remove-json-type-label hidden">Remove Rule Type</label>
                    <select name="remove_rule_type" class="rounded border border-slate-300 px-2 py-1 remove-json-field {{ $removeModeDefault === 'json' ? '' : 'hidden' }}">
                        @foreach(['registry','scheduled_task','command','firewall','bitlocker','local_group','windows_update','baseline_profile','reboot_restore_mode','uwf'] as $type)
                            <option value="{{ $type }}" @selected($removeRuleType === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    <label class="md:col-span-2 text-xs text-slate-500 remove-json-label hidden">Remove JSON</label>
                    <textarea name="remove_rule_json" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-24 remove-json-field {{ $removeModeDefault === 'json' ? '' : 'hidden' }}" placeholder='{"path":"HKLM\\...","name":"NoDrives","type":"DWORD","ensure":"absent"}'>{{ $removeRuleJsonDefault }}</textarea>
                    <label class="md:col-span-2 text-xs text-slate-500 remove-command-label hidden">Remove Command</label>
                    <textarea name="remove_command" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-24 remove-command-field {{ $removeModeDefault === 'command' ? '' : 'hidden' }}" placeholder="Remove command">{{ $removeCommandDefault }}</textarea>
                    <button class="rounded bg-ink text-white px-3 py-1 text-sm md:col-span-2">Save Version Changes</button>
                </form>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 space-y-2">
                    <p class="text-xs font-semibold">Assignments</p>
                    @forelse($assignments as $assignment)
                        @php
                            $targetName = $assignment->target_type === 'device'
                                ? ($assignmentDeviceNames[$assignment->target_id] ?? $assignment->target_id)
                                : ($assignmentGroupNames[$assignment->target_id] ?? $assignment->target_id);
                        @endphp
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span>{{ $assignment->target_type }}: {{ $targetName }}</span>
                            <form method="POST" action="{{ route('admin.policies.versions.assignments.delete', [$policy->id, $version->id, $assignment->id]) }}" onsubmit="return confirm('Remove this assignment?')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded bg-slate-700 text-white px-2 py-1">Remove</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-xs text-slate-500">No assignments yet.</p>
                    @endforelse

                    <form method="POST" action="{{ route('admin.policies.versions.assignments.create', [$policy->id, $version->id]) }}" class="grid gap-2 md:grid-cols-3">
                        @csrf
                        <label class="text-xs text-slate-500 md:col-span-1">Target Type</label>
                        <label class="text-xs text-slate-500 md:col-span-1">Target</label>
                        <span class="text-xs text-slate-500 md:col-span-1">Queue</span>
                        <select name="target_type" class="rounded border border-slate-300 px-2 py-1 text-xs">
                            <option value="group">group</option>
                            <option value="device">device</option>
                        </select>
                        <select name="target_id" class="rounded border border-slate-300 px-2 py-1 text-xs">
                            <option value="" data-kind="all">Select target</option>
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}" data-kind="group">Group: {{ $group->name }}</option>
                            @endforeach
                            @foreach($devices as $device)
                                <option value="{{ $device->id }}" data-kind="device">Device: {{ $device->hostname }}</option>
                            @endforeach
                        </select>
                        <label class="text-xs flex items-center gap-2">
                            <input type="checkbox" name="queue_job" value="1" checked />
                            queue job now
                        </label>
                        <button class="rounded bg-skyline text-white px-2 py-1 text-xs md:col-span-3">Add Assignment</button>
                    </form>
                </div>
                    </div>
                </details>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-slate-500">No versions yet.</div>
        @endforelse
    </div>

    <script>
        (function () {
            document.querySelectorAll('form').forEach(function (formEl) {
                const type = formEl.querySelector('select[name="target_type"]');
                const target = formEl.querySelector('select[name="target_id"]');
                if (!type || !target) return;
                const options = Array.from(target.options);

                function syncTargets() {
                    const typeValue = type.value;
                    options.forEach(function (opt) {
                        const kind = opt.getAttribute('data-kind') || '';
                        const visible = kind === 'all' || kind === typeValue;
                        opt.hidden = !visible;
                    });

                    const noAssignment = typeValue === '';
                    target.disabled = noAssignment;
                    target.required = !noAssignment;
                    if (noAssignment) {
                        target.value = '';
                        return;
                    }

                    const current = target.selectedOptions[0];
                    if (!current || current.hidden) {
                        const firstVisible = options.find(function (o) { return !o.hidden && o.value !== ''; });
                        target.value = firstVisible ? firstVisible.value : '';
                    }
                }

                type.addEventListener('change', syncTargets);
                syncTargets();
            });

            const presets = @json($rulePresetJson);
            const catalog = @json($policyCatalog);
            const formatPreset = function (ruleType) {
                const value = presets[ruleType] || { required: true };
                return JSON.stringify(value);
            };

            document.querySelectorAll('.policy-rule-form').forEach(function (form) {
                const typeSelect = form.querySelector('.rule-type-select');
                const jsonInput = form.querySelector('.rule-json-input');
                const applyModeSelect = form.querySelector('.apply-mode-select');
                const applyJsonFields = form.querySelectorAll('.apply-json-field');
                const applyUwfFields = form.querySelectorAll('.apply-uwf-field');
                const applyCommandField = form.querySelector('.apply-command-field');
                const applyCommandOptionFields = form.querySelectorAll('.apply-command-option-field');
                const applyCommandOptionLabels = form.querySelectorAll('.apply-command-option-label');
                const applyRunAsField = form.querySelector('select[name="apply_run_as"]');
                const applyTimeoutField = form.querySelector('input[name="apply_timeout_seconds"]');
                const applyUwfEnsureField = form.querySelector('select[name="apply_uwf_ensure"]');
                const applyUwfVolumeField = form.querySelector('input[name="apply_uwf_volume"]');
                const applyUwfEnableFeatureField = form.querySelector('input[name="apply_uwf_enable_feature"]');
                const applyUwfEnableFilterField = form.querySelector('input[name="apply_uwf_enable_filter"]');
                const applyUwfProtectVolumeField = form.querySelector('input[name="apply_uwf_protect_volume"]');
                const applyUwfRebootNowField = form.querySelector('input[name="apply_uwf_reboot_now"]');
                const applyUwfRebootIfPendingField = form.querySelector('input[name="apply_uwf_reboot_if_pending"]');
                const applyUwfMaxAttemptsField = form.querySelector('input[name="apply_uwf_max_reboot_attempts"]');
                const applyUwfCooldownField = form.querySelector('input[name="apply_uwf_reboot_cooldown_minutes"]');
                const applyUwfRebootCommandField = form.querySelector('input[name="apply_uwf_reboot_command"]');
                const applyUwfFileExclusionsField = form.querySelector('textarea[name="apply_uwf_file_exclusions"]');
                const applyUwfRegistryExclusionsField = form.querySelector('textarea[name="apply_uwf_registry_exclusions"]');
                const applyUwfFailUnsupportedEditionField = form.querySelector('input[name="apply_uwf_fail_on_unsupported_edition"]');
                const applyUwfOverlayTypeField = form.querySelector('select[name="apply_uwf_overlay_type"]');
                const applyUwfOverlayMaxSizeField = form.querySelector('input[name="apply_uwf_overlay_max_size_mb"]');
                const applyUwfOverlayWarningField = form.querySelector('input[name="apply_uwf_overlay_warning_threshold_mb"]');
                const applyUwfOverlayCriticalField = form.querySelector('input[name="apply_uwf_overlay_critical_threshold_mb"]');
                const removeModeSelect = form.querySelector('.remove-mode-select');
                const removeJsonFields = form.querySelectorAll('.remove-json-field');
                const removeCommandField = form.querySelector('.remove-command-field');
                const applyCommandLabel = form.querySelector('.apply-command-label');
                const removeJsonTypeLabel = form.querySelector('.remove-json-type-label');
                const removeJsonLabel = form.querySelector('.remove-json-label');
                const removeCommandLabel = form.querySelector('.remove-command-label');
                const removeRuleTypeSelect = form.querySelector('select[name="remove_rule_type"]');
                const removeRuleJsonInput = form.querySelector('textarea[name="remove_rule_json"]');
                const customToggle = form.querySelector('.custom-json-toggle');
                const usePresetButton = form.querySelector('.use-preset-btn');
                const catalogSelect = form.querySelector('.policy-catalog-select');
                const applyCatalogButton = form.querySelector('.apply-catalog-btn');
                const catalogInfoHint = form.querySelector('.catalog-info-hint');
                if (!typeSelect || !jsonInput || !customToggle || !usePresetButton) {
                    return;
                }

                const applyPreset = function () {
                    jsonInput.value = formatPreset(typeSelect.value);
                };
                const tryParseJson = function (value) {
                    try {
                        const parsed = JSON.parse(value || '{}');
                        return parsed && typeof parsed === 'object' ? parsed : {};
                    } catch (e) {
                        return {};
                    }
                };
                const defaultUwfConfig = function () {
                    return {
                        ensure: 'present',
                        enable_feature: true,
                        enable_filter: true,
                        protect_volume: true,
                        volume: 'C:',
                        reboot_now: false,
                        reboot_if_pending: true,
                        max_reboot_attempts: 2,
                        reboot_cooldown_minutes: 30,
                        reboot_command: 'shutdown.exe /r /t 30 /c "Enabling UWF protection"',
                        file_exclusions: ['C:\\ProgramData\\DMS\\State', 'C:\\ProgramData\\DMS\\Logs', 'C:\\ProgramData\\DMS\\Uwf'],
                        registry_exclusions: ['HKLM\\SOFTWARE\\DMS'],
                        fail_on_unsupported_edition: false,
                        overlay_type: '',
                        overlay_max_size_mb: null,
                        overlay_warning_threshold_mb: null,
                        overlay_critical_threshold_mb: null,
                    };
                };
                const parseListFromText = function (value) {
                    const raw = String(value || '');
                    return Array.from(new Set(raw
                        .split(/\r?\n|,|;/g)
                        .map(function (item) { return item.trim(); })
                        .filter(function (item) { return item.length > 0; })));
                };
                const normalizeUwfConfig = function (rawConfig) {
                    const cfg = Object.assign(defaultUwfConfig(), rawConfig || {});
                    cfg.ensure = String(cfg.ensure || 'present').toLowerCase() === 'absent' ? 'absent' : 'present';
                    cfg.volume = String(cfg.volume || 'C:').trim() || 'C:';
                    cfg.enable_feature = Boolean(cfg.enable_feature);
                    cfg.enable_filter = Boolean(cfg.enable_filter);
                    cfg.protect_volume = Boolean(cfg.protect_volume);
                    cfg.reboot_now = Boolean(cfg.reboot_now);
                    cfg.reboot_if_pending = Boolean(cfg.reboot_if_pending);
                    const maxAttempts = Number(cfg.max_reboot_attempts);
                    cfg.max_reboot_attempts = Number.isFinite(maxAttempts) ? Math.max(1, Math.min(10, Math.trunc(maxAttempts))) : 2;
                    const cooldown = Number(cfg.reboot_cooldown_minutes);
                    cfg.reboot_cooldown_minutes = Number.isFinite(cooldown) ? Math.max(1, Math.min(240, Math.trunc(cooldown))) : 30;
                    cfg.reboot_command = String(cfg.reboot_command || '').trim();
                    cfg.file_exclusions = Array.isArray(cfg.file_exclusions)
                        ? Array.from(new Set(cfg.file_exclusions.map(function (item) { return String(item || '').trim(); }).filter(function (item) { return item.length > 0; })))
                        : [];
                    cfg.registry_exclusions = Array.isArray(cfg.registry_exclusions)
                        ? Array.from(new Set(cfg.registry_exclusions.map(function (item) { return String(item || '').trim(); }).filter(function (item) { return item.length > 0; })))
                        : [];
                    cfg.fail_on_unsupported_edition = Boolean(cfg.fail_on_unsupported_edition);
                    cfg.overlay_type = ['ram', 'disk'].includes(String(cfg.overlay_type || '').toLowerCase())
                        ? String(cfg.overlay_type || '').toLowerCase()
                        : '';
                    const overlayMax = Number(cfg.overlay_max_size_mb);
                    cfg.overlay_max_size_mb = Number.isFinite(overlayMax) && overlayMax >= 128
                        ? Math.max(128, Math.min(1048576, Math.trunc(overlayMax)))
                        : null;
                    const overlayWarn = Number(cfg.overlay_warning_threshold_mb);
                    cfg.overlay_warning_threshold_mb = Number.isFinite(overlayWarn) && overlayWarn >= 64
                        ? Math.max(64, Math.min(1048576, Math.trunc(overlayWarn)))
                        : null;
                    const overlayCritical = Number(cfg.overlay_critical_threshold_mb);
                    cfg.overlay_critical_threshold_mb = Number.isFinite(overlayCritical) && overlayCritical >= 64
                        ? Math.max(64, Math.min(1048576, Math.trunc(overlayCritical)))
                        : null;
                    if (cfg.overlay_warning_threshold_mb !== null
                        && cfg.overlay_critical_threshold_mb !== null
                        && cfg.overlay_critical_threshold_mb <= cfg.overlay_warning_threshold_mb) {
                        cfg.overlay_critical_threshold_mb = cfg.overlay_warning_threshold_mb + 1;
                    }
                    return cfg;
                };
                const syncUwfFieldsFromJson = function () {
                    const config = normalizeUwfConfig(tryParseJson(jsonInput.value));
                    if (applyUwfEnsureField) applyUwfEnsureField.value = config.ensure;
                    if (applyUwfVolumeField) applyUwfVolumeField.value = config.volume;
                    if (applyUwfEnableFeatureField) applyUwfEnableFeatureField.checked = config.enable_feature;
                    if (applyUwfEnableFilterField) applyUwfEnableFilterField.checked = config.enable_filter;
                    if (applyUwfProtectVolumeField) applyUwfProtectVolumeField.checked = config.protect_volume;
                    if (applyUwfRebootNowField) applyUwfRebootNowField.checked = config.reboot_now;
                    if (applyUwfRebootIfPendingField) applyUwfRebootIfPendingField.checked = config.reboot_if_pending;
                    if (applyUwfMaxAttemptsField) applyUwfMaxAttemptsField.value = String(config.max_reboot_attempts);
                    if (applyUwfCooldownField) applyUwfCooldownField.value = String(config.reboot_cooldown_minutes);
                    if (applyUwfRebootCommandField) applyUwfRebootCommandField.value = config.reboot_command;
                    if (applyUwfFileExclusionsField) applyUwfFileExclusionsField.value = config.file_exclusions.join('\n');
                    if (applyUwfRegistryExclusionsField) applyUwfRegistryExclusionsField.value = config.registry_exclusions.join('\n');
                    if (applyUwfFailUnsupportedEditionField) applyUwfFailUnsupportedEditionField.checked = config.fail_on_unsupported_edition;
                    if (applyUwfOverlayTypeField) applyUwfOverlayTypeField.value = config.overlay_type;
                    if (applyUwfOverlayMaxSizeField) applyUwfOverlayMaxSizeField.value = config.overlay_max_size_mb !== null ? String(config.overlay_max_size_mb) : '';
                    if (applyUwfOverlayWarningField) applyUwfOverlayWarningField.value = config.overlay_warning_threshold_mb !== null ? String(config.overlay_warning_threshold_mb) : '';
                    if (applyUwfOverlayCriticalField) applyUwfOverlayCriticalField.value = config.overlay_critical_threshold_mb !== null ? String(config.overlay_critical_threshold_mb) : '';
                };
                const syncJsonFromUwfFields = function () {
                    const config = normalizeUwfConfig({
                        ensure: applyUwfEnsureField ? applyUwfEnsureField.value : 'present',
                        volume: applyUwfVolumeField ? applyUwfVolumeField.value : 'C:',
                        enable_feature: applyUwfEnableFeatureField ? applyUwfEnableFeatureField.checked : true,
                        enable_filter: applyUwfEnableFilterField ? applyUwfEnableFilterField.checked : true,
                        protect_volume: applyUwfProtectVolumeField ? applyUwfProtectVolumeField.checked : true,
                        reboot_now: applyUwfRebootNowField ? applyUwfRebootNowField.checked : false,
                        reboot_if_pending: applyUwfRebootIfPendingField ? applyUwfRebootIfPendingField.checked : true,
                        max_reboot_attempts: applyUwfMaxAttemptsField ? applyUwfMaxAttemptsField.value : 2,
                        reboot_cooldown_minutes: applyUwfCooldownField ? applyUwfCooldownField.value : 30,
                        reboot_command: applyUwfRebootCommandField ? applyUwfRebootCommandField.value : '',
                        file_exclusions: applyUwfFileExclusionsField ? parseListFromText(applyUwfFileExclusionsField.value) : [],
                        registry_exclusions: applyUwfRegistryExclusionsField ? parseListFromText(applyUwfRegistryExclusionsField.value) : [],
                        fail_on_unsupported_edition: applyUwfFailUnsupportedEditionField ? applyUwfFailUnsupportedEditionField.checked : false,
                        overlay_type: applyUwfOverlayTypeField ? applyUwfOverlayTypeField.value : '',
                        overlay_max_size_mb: applyUwfOverlayMaxSizeField ? applyUwfOverlayMaxSizeField.value : '',
                        overlay_warning_threshold_mb: applyUwfOverlayWarningField ? applyUwfOverlayWarningField.value : '',
                        overlay_critical_threshold_mb: applyUwfOverlayCriticalField ? applyUwfOverlayCriticalField.value : '',
                    });
                    jsonInput.value = JSON.stringify(config);
                };
                const deriveAutoRemove = function () {
                    const applyType = (typeSelect.value || '').toLowerCase();
                    const applyConfig = tryParseJson(jsonInput.value);
                    if (applyType === 'registry') {
                        const path = (applyConfig.path || '').toString().trim();
                        const name = (applyConfig.name || '').toString().trim();
                        if (path !== '' && name !== '') {
                            const type = (applyConfig.type || 'STRING').toString();
                            return {
                                type: 'registry',
                                config: { path: path, name: name, type: type, ensure: 'absent' },
                            };
                        }
                    }
                    if (applyType === 'scheduled_task') {
                        const taskName = (applyConfig.task_name || '').toString().trim();
                        if (taskName !== '') {
                            return {
                                type: 'scheduled_task',
                                config: { task_name: taskName, ensure: 'absent' },
                            };
                        }
                    }
                    if (applyType === 'command') {
                        const command = (applyConfig.command || '').toString().trim();
                        if (command !== '') {
                            return {
                                type: 'command',
                                config: { command: command },
                            };
                        }
                    }
                    if (applyType === 'uwf') {
                        const volume = (applyConfig.volume || 'C:').toString().trim() || 'C:';
                        return {
                            type: 'uwf',
                            config: {
                                ensure: 'absent',
                                enable_feature: false,
                                enable_filter: false,
                                protect_volume: false,
                                volume: volume,
                            },
                        };
                    }
                    return { type: 'registry', config: { ensure: 'absent' } };
                };
                const applyAutoDefaults = function () {
                    if (!removeModeSelect || !removeRuleTypeSelect || !removeRuleJsonInput) {
                        return;
                    }
                    removeModeSelect.value = 'auto';
                    const autoRemove = deriveAutoRemove();
                    removeRuleTypeSelect.value = autoRemove.type;
                    removeRuleJsonInput.value = JSON.stringify(autoRemove.config);
                    if (applyModeSelect) {
                        applyModeSelect.value = 'json';
                    }
                    if (applyCommandField) {
                        applyCommandField.value = '';
                    }
                    if (applyRunAsField) {
                        applyRunAsField.value = 'default';
                    }
                    if (applyTimeoutField) {
                        applyTimeoutField.value = '300';
                    }
                    if (removeCommandField) {
                        removeCommandField.value = '';
                    }
                    if ((typeSelect.value || '').toLowerCase() === 'uwf') {
                        syncUwfFieldsFromJson();
                        syncJsonFromUwfFields();
                    }
                };
                const applyCatalog = function () {
                    if (!catalogSelect || !catalogSelect.value) {
                        return;
                    }
                    let item = null;
                    try {
                        item = JSON.parse(catalogSelect.value);
                    } catch (e) {
                        item = null;
                    }
                    if (!item) {
                        return;
                    }
                    typeSelect.value = item.rule_type || typeSelect.value;
                    const itemRuleJson = (item.rule_json && typeof item.rule_json === 'object') ? item.rule_json : { required: true };
                    jsonInput.value = JSON.stringify(itemRuleJson);
                    if (applyModeSelect) {
                        const isCommand = String(item.rule_type || '').toLowerCase() === 'command';
                        applyModeSelect.value = isCommand ? 'command' : 'json';
                    }
                    if (applyCommandField) {
                        applyCommandField.value = String(itemRuleJson.command || '');
                    }
                    if (applyRunAsField) {
                        const runAs = String(itemRuleJson.run_as || 'default').toLowerCase();
                        applyRunAsField.value = ['default', 'elevated', 'system'].includes(runAs) ? runAs : 'default';
                    }
                    if (applyTimeoutField) {
                        const timeoutRaw = Number(itemRuleJson.timeout_seconds || 300);
                        const timeout = Number.isFinite(timeoutRaw) ? Math.max(30, Math.min(3600, Math.trunc(timeoutRaw))) : 300;
                        applyTimeoutField.value = String(timeout);
                    }
                    if (removeModeSelect) {
                        removeModeSelect.value = String(item.remove_mode || 'auto');
                    }
                    if (removeRuleTypeSelect || removeRuleJsonInput || removeCommandField) {
                        const removeRules = Array.isArray(item.remove_rules) ? item.remove_rules : [];
                        const removeRule = removeRules.length > 0 && typeof removeRules[0] === 'object' ? removeRules[0] : null;
                        const removeType = String((removeRule && removeRule.type) || 'registry');
                        const removeConfig = (removeRule && typeof removeRule.config === 'object' && removeRule.config !== null) ? removeRule.config : {};
                        if (removeRuleTypeSelect) {
                            removeRuleTypeSelect.value = removeType;
                        }
                        if (removeRuleJsonInput) {
                            removeRuleJsonInput.value = JSON.stringify(removeConfig);
                        }
                        if (removeCommandField) {
                            removeCommandField.value = String(removeConfig.command || '');
                        }
                    }
                    customToggle.checked = false;
                    syncModes();
                };
                const updateCatalogHint = function () {
                    if (!catalogSelect || !catalogInfoHint || !catalogSelect.value) {
                        if (catalogInfoHint) catalogInfoHint.classList.add('hidden');
                        return;
                    }
                    let item = null;
                    try {
                        item = JSON.parse(catalogSelect.value);
                    } catch (e) {
                        item = null;
                    }
                    if (!item || !catalogInfoHint) {
                        return;
                    }
                    const desc = item.description || 'No description';
                    const applies = item.applies_to || 'both';
                    catalogInfoHint.textContent = `${desc} | applies to: ${applies}`;
                    catalogInfoHint.classList.remove('hidden');
                };

                typeSelect.addEventListener('change', function () {
                    if (!customToggle.checked) {
                        applyPreset();
                        applyAutoDefaults();
                        syncModes();
                    }
                });

                usePresetButton.addEventListener('click', function () {
                    applyPreset();
                    customToggle.checked = false;
                    applyAutoDefaults();
                    syncModes();
                });

                if (applyCatalogButton) {
                    applyCatalogButton.addEventListener('click', applyCatalog);
                }
                if (catalogSelect) {
                    catalogSelect.addEventListener('change', updateCatalogHint);
                    updateCatalogHint();
                }

                const syncModes = function () {
                    const isCustom = !!customToggle.checked;

                    const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                    const isUwfJsonMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'uwf';

                    applyJsonFields.forEach(function (el) {
                        if (el === typeSelect) return;
                        if (el === jsonInput) {
                            el.classList.toggle('hidden', isCustom ? applyMode !== 'json' : false);
                            return;
                        }
                        el.classList.toggle('hidden', isCustom ? applyMode !== 'json' : true);
                    });
                    if (applyCommandField) {
                        applyCommandField.classList.toggle('hidden', !isCustom || applyMode !== 'command');
                        applyCommandField.required = isCustom && applyMode === 'command';
                    }
                    applyCommandOptionFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isCustom || applyMode !== 'command');
                    });
                    applyCommandOptionLabels.forEach(function (el) {
                        el.classList.toggle('hidden', !isCustom || applyMode !== 'command');
                    });
                    if (applyCommandLabel) {
                        applyCommandLabel.classList.toggle('hidden', !isCustom || applyMode !== 'command');
                    }
                    if (jsonInput) {
                        jsonInput.required = true;
                        jsonInput.readOnly = !isCustom;
                    }
                    applyUwfFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isUwfJsonMode);
                        el.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !isUwfJsonMode;
                        });
                    });
                    if (isUwfJsonMode) {
                        syncUwfFieldsFromJson();
                        if (!isCustom) {
                            syncJsonFromUwfFields();
                        }
                    }

                    const removeMode = removeModeSelect ? removeModeSelect.value : 'auto';
                    removeJsonFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isCustom || removeMode !== 'json');
                        if (el.tagName === 'TEXTAREA') {
                            el.required = isCustom && removeMode === 'json';
                        }
                    });
                    if (removeCommandField) {
                        removeCommandField.classList.toggle('hidden', !isCustom || removeMode !== 'command');
                        removeCommandField.required = isCustom && removeMode === 'command';
                    }
                    if (removeJsonTypeLabel) {
                        removeJsonTypeLabel.classList.toggle('hidden', !isCustom || removeMode !== 'json');
                    }
                    if (removeJsonLabel) {
                        removeJsonLabel.classList.toggle('hidden', !isCustom || removeMode !== 'json');
                    }
                    if (removeCommandLabel) {
                        removeCommandLabel.classList.toggle('hidden', !isCustom || removeMode !== 'command');
                    }
                };

                if (applyModeSelect) {
                    applyModeSelect.addEventListener('change', syncModes);
                }
                [
                    applyUwfEnsureField,
                    applyUwfVolumeField,
                    applyUwfEnableFeatureField,
                    applyUwfEnableFilterField,
                    applyUwfProtectVolumeField,
                    applyUwfRebootNowField,
                    applyUwfRebootIfPendingField,
                    applyUwfMaxAttemptsField,
                    applyUwfCooldownField,
                    applyUwfRebootCommandField,
                    applyUwfFileExclusionsField,
                    applyUwfRegistryExclusionsField,
                    applyUwfFailUnsupportedEditionField,
                    applyUwfOverlayTypeField,
                    applyUwfOverlayMaxSizeField,
                    applyUwfOverlayWarningField,
                    applyUwfOverlayCriticalField,
                ].forEach(function (el) {
                    if (!el) return;
                    el.addEventListener('change', function () {
                        const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                        const isUwfMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'uwf';
                        if (isUwfMode) {
                            syncJsonFromUwfFields();
                        }
                    });
                });
                if (removeModeSelect) {
                    removeModeSelect.addEventListener('change', function () {
                        if (removeModeSelect.value !== 'auto') {
                            customToggle.checked = true;
                        }
                        syncModes();
                    });
                }
                customToggle.addEventListener('change', function () {
                    if (!customToggle.checked) {
                        applyAutoDefaults();
                    }
                    syncModes();
                });
                if (!customToggle.checked) {
                    applyAutoDefaults();
                }
                syncModes();
            });
        })();
    </script>
</x-admin-layout>
