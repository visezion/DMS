<x-admin-layout title="Policy Detail" heading="Policy Detail">
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
                <option value="dns">dns</option>
                <option value="network_adapter">network_adapter</option>
                <option value="time">time</option>
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
            <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-dns-field hidden dns-shell">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-sky-700">DNS Policy Setup</p>
                        <h4 class="mt-1 text-sm font-semibold text-slate-900">Windows DNS server assignment</h4>
                        <p class="mt-1 text-[11px] text-slate-600">This mirrors the wording admins see in Windows Settings for <span class="font-medium text-slate-700">Network &amp; Internet</span>: choose the adapter, then choose whether DNS comes from DHCP or a manual list.</p>
                    </div>
                    <div class="dns-callout max-w-sm text-[11px] text-slate-600">
                        <p class="font-semibold text-slate-800">Windows terms</p>
                        <p class="mt-1"><span class="font-mono text-slate-800">Interface Alias</span> is the friendly adapter name such as <span class="font-mono text-slate-800">Ethernet</span> or <span class="font-mono text-slate-800">Wi-Fi</span>. Use it unless you need a hardware description or a fixed interface index.</p>
                    </div>
                </div>
                <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <div class="dns-card space-y-3">
                        <p class="dns-card-title">1. Target The Windows Adapter</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Identify Adapter By</label>
                            <div>
                                <select name="apply_dns_selector_type" class="w-full rounded border border-slate-300 px-2 py-1">
                                    <option value="alias" selected>Interface Alias (Recommended)</option>
                                    <option value="index">Interface Index</option>
                                    <option value="description">Interface Description</option>
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">Use the same identifier Windows shows in Network Connections or adapter properties.</p>
                            </div>
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-dns-selector-row" data-selector="alias">
                            <label class="text-xs text-slate-500">Windows Interface Alias</label>
                            <div>
                                <input name="apply_dns_interface_alias" value="Ethernet" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Ethernet or Wi-Fi" />
                                <p class="mt-1 text-[11px] text-slate-500">Examples: <span class="font-mono">Ethernet</span>, <span class="font-mono">Wi-Fi</span>, <span class="font-mono">Ethernet 2</span>.</p>
                            </div>
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-dns-selector-row hidden" data-selector="index">
                            <label class="text-xs text-slate-500">Interface Index</label>
                            <div>
                                <input name="apply_dns_interface_index" type="number" min="1" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="12" />
                                <p class="mt-1 text-[11px] text-slate-500">Use the numeric adapter index only when you already know the exact Windows interface number.</p>
                            </div>
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-dns-selector-row hidden" data-selector="description">
                            <label class="text-xs text-slate-500">Interface Description</label>
                            <div>
                                <input name="apply_dns_interface_description" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Intel(R) Ethernet Connection" />
                                <p class="mt-1 text-[11px] text-slate-500">Matches the hardware description shown on the Windows adapter details page.</p>
                            </div>
                        </div>
                    </div>
                    <div class="dns-card space-y-3">
                        <p class="dns-card-title">2. DNS Server Assignment</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Assignment Mode</label>
                            <div>
                                <select name="apply_dns_mode" class="w-full rounded border border-slate-300 px-2 py-1">
                                    <option value="static" selected>Manual (Static DNS)</option>
                                    <option value="automatic">Automatic (DHCP / router)</option>
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">Automatic restores DNS from DHCP. Manual writes the server list below, like the Windows manual DNS screen.</p>
                            </div>
                        </div>
                        <div class="grid gap-3 apply-dns-servers-row">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label class="text-xs text-slate-500">Preferred DNS Server</label>
                                    <input name="apply_dns_server_preferred" value="10.0.0.10" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.10" />
                                </div>
                                <div>
                                    <label class="text-xs text-slate-500">Alternate DNS Server</label>
                                    <input name="apply_dns_server_alternate" value="10.0.0.11" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.11" />
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-slate-500">Additional DNS Servers (optional)</label>
                                <textarea name="apply_dns_server_additional" class="mt-1 min-h-16 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.12&#10;10.0.0.13"></textarea>
                                <p class="mt-1 text-[11px] text-slate-500">Only use this when you need more than the preferred and alternate DNS entries.</p>
                            </div>
                        </div>
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                            <input type="checkbox" name="apply_dns_dry_run" value="1" />
                            dry run only: check compliance without changing the device
                        </label>
                    </div>
                </div>
            </div>
            <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-time-field hidden">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-amber-700">Time Policy</p>
                        <h4 class="mt-1 text-sm font-semibold text-slate-900">NTP + timezone alignment</h4>
                        <p class="mt-1 text-[11px] text-slate-600">Keep device clocks accurate by setting the Windows time zone and time sync source.</p>
                    </div>
                    <div class="max-w-sm text-[11px] text-slate-600">
                        <p class="font-semibold text-slate-800">Windows identifiers</p>
                        <p class="mt-1"><span class="font-mono text-slate-800">Timezone</span> must match a Windows time zone ID (for example <span class="font-mono text-slate-800">UTC</span>, <span class="font-mono text-slate-800">Pacific Standard Time</span>).</p>
                    </div>
                </div>
                <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <div class="space-y-3">
                        <p class="text-xs font-semibold text-slate-700">1. Time Zone</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Windows Time Zone ID</label>
                            <div>
                                <input name="apply_time_timezone" value="UTC" list="time-zone-options" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="UTC" />
                                <p class="mt-1 text-[11px] text-slate-500">Example: <span class="font-mono">Pacific Standard Time</span>.</p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <p class="text-xs font-semibold text-slate-700">2. Time Sync Source</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Sync Mode</label>
                            <div>
                                <select name="apply_time_sync_mode" class="w-full rounded border border-slate-300 px-2 py-1">
                                    <option value="manual" selected>Manual NTP servers</option>
                                    <option value="domhier">Domain hierarchy</option>
                                    <option value="all">All sources</option>
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">Use manual for explicit NTP servers or domhier for domain-controlled time.</p>
                            </div>
                        </div>
                        <div class="grid gap-2 apply-time-servers-row">
                            <label class="text-xs text-slate-500">NTP Servers (one per line)</label>
                            <textarea name="apply_time_ntp_servers" class="min-h-16 w-full rounded border border-slate-300 px-2 py-1" placeholder="time.windows.com&#10;pool.ntp.org"></textarea>
                            <p class="text-[11px] text-slate-500">Only required when sync mode is manual.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                <input type="checkbox" name="apply_time_resync" value="1" checked />
                                force resync after apply
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                <input type="checkbox" name="apply_time_dry_run" value="1" />
                                dry run only
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-windows-update-field hidden">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-indigo-700">Windows Update Policy</p>
                        <h4 class="mt-1 text-sm font-semibold text-slate-900">WSUS source + update window</h4>
                        <p class="mt-1 text-[11px] text-slate-600">Control update source (Microsoft vs WSUS) and set required Windows Update hours.</p>
                    </div>
                    <div class="max-w-sm text-[11px] text-slate-600">
                        <p class="font-semibold text-slate-800">WSUS URL format</p>
                        <p class="mt-1"><span class="font-mono text-slate-800">http://wsus:8530</span> or <span class="font-mono text-slate-800">https://wsus.domain:8531</span>.</p>
                    </div>
                </div>
                <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <div class="space-y-3">
                        <p class="text-xs font-semibold text-slate-700">1. Active Hours</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Active Hours Start (0-23)</label>
                            <input name="apply_update_active_start" type="number" min="0" max="23" value="8" class="w-full rounded border border-slate-300 px-2 py-1" />
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Active Hours End (0-23)</label>
                            <input name="apply_update_active_end" type="number" min="0" max="23" value="17" class="w-full rounded border border-slate-300 px-2 py-1" />
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Force Install Window (optional)</label>
                            <input name="apply_update_force_window" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="22:00-02:00" />
                        </div>
                    </div>
                    <div class="space-y-3">
                        <p class="text-xs font-semibold text-slate-700">2. Update Source</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Source</label>
                            <select name="apply_update_source" class="w-full rounded border border-slate-300 px-2 py-1">
                                <option value="microsoft" selected>Microsoft Update</option>
                                <option value="wsus">WSUS Server</option>
                            </select>
                        </div>
                        <div class="grid gap-2 apply-update-wsus-row">
                            <label class="text-xs text-slate-500">WSUS Server URL</label>
                            <input name="apply_update_wsus_server" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="http://wsus:8530" />
                        </div>
                        <div class="grid gap-2 apply-update-wsus-row">
                            <label class="text-xs text-slate-500">WSUS Status Server (optional)</label>
                            <input name="apply_update_wsus_status_server" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="http://wsus:8530" />
                        </div>
                        <div class="apply-update-wsus-row">
                            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                <input type="checkbox" name="apply_update_same_status" value="1" checked />
                                use WSUS URL for status server
                            </label>
                        </div>
                        <div class="grid gap-2 apply-update-wsus-row">
                            <label class="text-xs text-slate-500">Target Group (optional)</label>
                            <input name="apply_update_target_group" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Accounting-Laptops" />
                        </div>
                        <div class="flex flex-wrap gap-2 apply-update-wsus-row">
                            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                <input type="checkbox" name="apply_update_target_group_enabled" value="1" checked />
                                enable target group
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                <input type="checkbox" name="apply_update_block_internet" value="1" />
                                block internet updates
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-network-field hidden network-shell">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-700">IPv4 Policy Setup</p>
                        <h4 class="mt-1 text-sm font-semibold text-slate-900">Windows IP assignment</h4>
                        <p class="mt-1 text-[11px] text-slate-600">This follows the Windows adapter workflow: choose the interface, then choose whether IPv4 is <span class="font-medium text-slate-700">Automatic (DHCP)</span> or <span class="font-medium text-slate-700">Manual (Static IP)</span>.</p>
                    </div>
                    <div class="network-callout max-w-sm text-[11px] text-slate-600">
                        <p class="font-semibold text-slate-800">How this maps</p>
                        <p class="mt-1">Automatic switches the adapter back to DHCP. Manual writes a fixed IPv4 address, subnet, and optional default gateway for the selected Windows interface.</p>
                    </div>
                </div>
                <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <div class="network-card space-y-3">
                        <p class="network-card-title">1. Target The Windows Adapter</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">Identify Adapter By</label>
                            <div>
                                <select name="apply_network_selector_type" class="w-full rounded border border-slate-300 px-2 py-1">
                                    <option value="alias" selected>Interface Alias (Recommended)</option>
                                    <option value="index">Interface Index</option>
                                    <option value="description">Interface Description</option>
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">Use the same identifier shown in Windows Network Connections or adapter properties.</p>
                            </div>
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-network-selector-row" data-selector="alias">
                            <label class="text-xs text-slate-500">Windows Interface Alias</label>
                            <div>
                                <input name="apply_network_interface_alias" value="Ethernet" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Ethernet or Wi-Fi" />
                                <p class="mt-1 text-[11px] text-slate-500">Examples: <span class="font-mono">Ethernet</span>, <span class="font-mono">Wi-Fi</span>, <span class="font-mono">Ethernet 2</span>.</p>
                            </div>
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-network-selector-row hidden" data-selector="index">
                            <label class="text-xs text-slate-500">Interface Index</label>
                            <div>
                                <input name="apply_network_interface_index" type="number" min="1" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="12" />
                                <p class="mt-1 text-[11px] text-slate-500">Use the numeric Windows interface index only when you manage adapters by ID.</p>
                            </div>
                        </div>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-network-selector-row hidden" data-selector="description">
                            <label class="text-xs text-slate-500">Interface Description</label>
                            <div>
                                <input name="apply_network_interface_description" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Intel(R) Ethernet Connection" />
                                <p class="mt-1 text-[11px] text-slate-500">Matches the hardware description shown in Windows adapter properties.</p>
                            </div>
                        </div>
                    </div>
                    <div class="network-card space-y-3">
                        <p class="network-card-title">2. IPv4 Assignment</p>
                        <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                            <label class="text-xs text-slate-500">IPv4 Assignment</label>
                            <div>
                                <select name="apply_network_ipv4_mode" class="w-full rounded border border-slate-300 px-2 py-1">
                                    <option value="static" selected>Manual (Static IP)</option>
                                    <option value="dhcp">Automatic (DHCP)</option>
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">Choose Automatic to restore DHCP. Choose Manual to set a fixed IPv4 address, subnet mask, and gateway.</p>
                            </div>
                        </div>
                        <div class="grid gap-3 apply-network-static-row">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label class="text-xs text-slate-500">IPv4 Address</label>
                                    <input name="apply_network_address" value="10.0.0.25" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.25" />
                                </div>
                                <div>
                                    <label class="text-xs text-slate-500">Subnet Mask</label>
                                    <input name="apply_network_subnet_mask" value="255.255.255.0" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="255.255.255.0" />
                                </div>
                            </div>
                            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_160px] md:items-end">
                                <div>
                                    <label class="text-xs text-slate-500">Default Gateway (optional)</label>
                                    <input name="apply_network_gateway" value="10.0.0.1" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.1" />
                                </div>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                    <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Stored As</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-800 apply-network-prefix-display">/24 prefix length</p>
                                </div>
                            </div>
                            <p class="text-[11px] text-slate-500">Windows uses subnet masks such as <span class="font-mono">255.255.255.0</span>. DMS stores the same value internally as a prefix length such as <span class="font-mono">/24</span>.</p>
                        </div>
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                            <input type="checkbox" name="apply_network_dry_run" value="1" />
                            dry run only: check compliance without changing the device
                        </label>
                    </div>
                </div>
            </div>
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
            <div class="md:col-span-2 grid gap-2 md:grid-cols-2 rounded border border-slate-200 bg-slate-50 p-3 apply-scheduled-task-field hidden">
                <p class="md:col-span-2 text-xs font-semibold text-slate-700">Scheduled Task Builder</p>
                <label class="text-xs text-slate-500">Task Template</label>
                <select name="apply_scheduled_template" class="rounded border border-slate-300 px-2 py-1">
                    <option value="power_daily_shutdown">Power: Daily Shutdown</option>
                    <option value="power_weekly_reboot">Power: Weekly Reboot</option>
                    <option value="power_daily_reboot">Power: Daily Reboot</option>
                    <option value="custom">Custom Task</option>
                </select>
                <label class="text-xs text-slate-500">Task Name</label>
                <input name="apply_scheduled_task_name" value="DmsRecurringPowerSchedule" class="rounded border border-slate-300 px-2 py-1" />
                <label class="text-xs text-slate-500">Schedule</label>
                <select name="apply_scheduled_schedule" class="rounded border border-slate-300 px-2 py-1">
                    <option value="daily" selected>daily</option>
                    <option value="weekly">weekly</option>
                    <option value="hourly">hourly</option>
                    <option value="onstart">onstart</option>
                    <option value="onlogon">onlogon</option>
                </select>
                <label class="text-xs text-slate-500 apply-scheduled-time-label">Time</label>
                <input name="apply_scheduled_time" type="time" value="23:00" class="rounded border border-slate-300 px-2 py-1 apply-scheduled-time-field" />
                <label class="text-xs text-slate-500">Action</label>
                <select name="apply_scheduled_action" class="rounded border border-slate-300 px-2 py-1">
                    <option value="shutdown" selected>shutdown</option>
                    <option value="reboot">reboot</option>
                    <option value="custom">custom command</option>
                </select>
                <label class="text-xs text-slate-500">Delay Seconds</label>
                <input name="apply_scheduled_delay_seconds" type="number" min="0" max="86400" value="300" class="rounded border border-slate-300 px-2 py-1 apply-scheduled-power-field" />
                <label class="text-xs flex items-center gap-2 apply-scheduled-power-field">
                    <input type="checkbox" name="apply_scheduled_force" value="1" checked />
                    force apps closed
                </label>
                <div class="apply-scheduled-power-field"></div>
                <label class="md:col-span-2 text-xs text-slate-500 apply-scheduled-power-field">Message (optional)</label>
                <input name="apply_scheduled_message" value="Scheduled daily shutdown by DMS policy" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 apply-scheduled-power-field" />
                <label class="md:col-span-2 text-xs text-slate-500 apply-scheduled-custom-label hidden">Custom Command</label>
                <textarea name="apply_scheduled_custom_command" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-20 apply-scheduled-custom-field hidden" placeholder='e.g. powershell.exe -NoProfile -Command "Stop-Computer -Force"'></textarea>
                <p class="md:col-span-2 text-[11px] text-slate-500">Choose a template or customize task name, schedule, and action. Custom command gives full control.</p>
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
                <option value="dns">dns</option>
                <option value="network_adapter">network_adapter</option>
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
                        @foreach(['firewall','dns','network_adapter','time','registry','bitlocker','local_group','windows_update','scheduled_task','command','baseline_profile','reboot_restore_mode','uwf'] as $type)
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
                    <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-dns-field hidden dns-shell">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-sky-700">DNS Policy Setup</p>
                                <h4 class="mt-1 text-sm font-semibold text-slate-900">Windows DNS server assignment</h4>
                                <p class="mt-1 text-[11px] text-slate-600">Configure DNS with the same concepts Windows shows on each endpoint: pick the adapter, then choose manual or automatic DNS server assignment.</p>
                            </div>
                            <div class="dns-callout max-w-sm text-[11px] text-slate-600">
                                <p class="font-semibold text-slate-800">What admins usually use</p>
                                <p class="mt-1"><span class="font-mono text-slate-800">Interface Alias</span> is normally the easiest choice because it matches labels like <span class="font-mono text-slate-800">Ethernet</span> or <span class="font-mono text-slate-800">Wi-Fi</span>.</p>
                            </div>
                        </div>
                        <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                            <div class="dns-card space-y-3">
                                <p class="dns-card-title">1. Target The Windows Adapter</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Identify Adapter By</label>
                                    <div>
                                        <select name="apply_dns_selector_type" class="w-full rounded border border-slate-300 px-2 py-1">
                                            <option value="alias" selected>Interface Alias (Recommended)</option>
                                            <option value="index">Interface Index</option>
                                            <option value="description">Interface Description</option>
                                        </select>
                                        <p class="mt-1 text-[11px] text-slate-500">Use the same identifier Windows shows in adapter settings.</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-dns-selector-row" data-selector="alias">
                                    <label class="text-xs text-slate-500">Windows Interface Alias</label>
                                    <div>
                                        <input name="apply_dns_interface_alias" value="Ethernet" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Ethernet or Wi-Fi" />
                                        <p class="mt-1 text-[11px] text-slate-500">Examples: <span class="font-mono">Ethernet</span>, <span class="font-mono">Wi-Fi</span>, <span class="font-mono">Ethernet 2</span>.</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-dns-selector-row hidden" data-selector="index">
                                    <label class="text-xs text-slate-500">Interface Index</label>
                                    <div>
                                        <input name="apply_dns_interface_index" type="number" min="1" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="12" />
                                        <p class="mt-1 text-[11px] text-slate-500">Useful when you manage adapters by the exact Windows index number.</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-dns-selector-row hidden" data-selector="description">
                                    <label class="text-xs text-slate-500">Interface Description</label>
                                    <div>
                                        <input name="apply_dns_interface_description" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Intel(R) Ethernet Connection" />
                                        <p class="mt-1 text-[11px] text-slate-500">Matches the hardware description shown in Windows adapter properties.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="dns-card space-y-3">
                                <p class="dns-card-title">2. DNS Server Assignment</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Assignment Mode</label>
                                    <div>
                                        <select name="apply_dns_mode" class="w-full rounded border border-slate-300 px-2 py-1">
                                            <option value="static" selected>Manual (Static DNS)</option>
                                            <option value="automatic">Automatic (DHCP / router)</option>
                                        </select>
                                        <p class="mt-1 text-[11px] text-slate-500">Switch to automatic when you want the device to take DNS from DHCP instead of a policy-defined list.</p>
                                    </div>
                                </div>
                                <div class="grid gap-3 apply-dns-servers-row">
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <div>
                                            <label class="text-xs text-slate-500">Preferred DNS Server</label>
                                            <input name="apply_dns_server_preferred" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.10" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-slate-500">Alternate DNS Server</label>
                                            <input name="apply_dns_server_alternate" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.11" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-slate-500">Additional DNS Servers (optional)</label>
                                        <textarea name="apply_dns_server_additional" class="mt-1 min-h-16 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.12&#10;10.0.0.13"></textarea>
                                        <p class="mt-1 text-[11px] text-slate-500">Use this only when the adapter should have more than two DNS servers.</p>
                                    </div>
                                </div>
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                    <input type="checkbox" name="apply_dns_dry_run" value="1" />
                                    dry run only: check compliance without changing the device
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-time-field hidden">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-amber-700">Time Policy</p>
                                <h4 class="mt-1 text-sm font-semibold text-slate-900">NTP + timezone alignment</h4>
                                <p class="mt-1 text-[11px] text-slate-600">Align device time by setting the Windows time zone and time sync source.</p>
                            </div>
                            <div class="max-w-sm text-[11px] text-slate-600">
                                <p class="font-semibold text-slate-800">Windows identifiers</p>
                                <p class="mt-1"><span class="font-mono text-slate-800">Timezone</span> must be a Windows time zone ID (example: <span class="font-mono text-slate-800">UTC</span>).</p>
                            </div>
                        </div>
                        <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                            <div class="space-y-3">
                                <p class="text-xs font-semibold text-slate-700">1. Time Zone</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Windows Time Zone ID</label>
                                    <div>
                                        <input name="apply_time_timezone" value="UTC" list="time-zone-options" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="UTC" />
                                        <p class="mt-1 text-[11px] text-slate-500">Example: <span class="font-mono">Pacific Standard Time</span>.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <p class="text-xs font-semibold text-slate-700">2. Time Sync Source</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Sync Mode</label>
                                    <div>
                                        <select name="apply_time_sync_mode" class="w-full rounded border border-slate-300 px-2 py-1">
                                            <option value="manual" selected>Manual NTP servers</option>
                                            <option value="domhier">Domain hierarchy</option>
                                            <option value="all">All sources</option>
                                        </select>
                                        <p class="mt-1 text-[11px] text-slate-500">Manual uses the server list below. Domain hierarchy follows AD time.</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 apply-time-servers-row">
                                    <label class="text-xs text-slate-500">NTP Servers (one per line)</label>
                                    <textarea name="apply_time_ntp_servers" class="min-h-16 w-full rounded border border-slate-300 px-2 py-1" placeholder="time.windows.com&#10;pool.ntp.org"></textarea>
                                    <p class="text-[11px] text-slate-500">Only required when sync mode is manual.</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                        <input type="checkbox" name="apply_time_resync" value="1" checked />
                                        force resync after apply
                                    </label>
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                        <input type="checkbox" name="apply_time_dry_run" value="1" />
                                        dry run only
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-windows-update-field hidden">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-indigo-700">Windows Update Policy</p>
                                <h4 class="mt-1 text-sm font-semibold text-slate-900">WSUS source + update window</h4>
                                <p class="mt-1 text-[11px] text-slate-600">Define the update source (Microsoft vs WSUS) and required active hours.</p>
                            </div>
                            <div class="max-w-sm text-[11px] text-slate-600">
                                <p class="font-semibold text-slate-800">WSUS URL format</p>
                                <p class="mt-1"><span class="font-mono text-slate-800">http://wsus:8530</span> or <span class="font-mono text-slate-800">https://wsus.domain:8531</span>.</p>
                            </div>
                        </div>
                        <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                            <div class="space-y-3">
                                <p class="text-xs font-semibold text-slate-700">1. Active Hours</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Active Hours Start (0-23)</label>
                                    <input name="apply_update_active_start" type="number" min="0" max="23" value="8" class="w-full rounded border border-slate-300 px-2 py-1" />
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Active Hours End (0-23)</label>
                                    <input name="apply_update_active_end" type="number" min="0" max="23" value="17" class="w-full rounded border border-slate-300 px-2 py-1" />
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Force Install Window (optional)</label>
                                    <input name="apply_update_force_window" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="22:00-02:00" />
                                </div>
                            </div>
                            <div class="space-y-3">
                                <p class="text-xs font-semibold text-slate-700">2. Update Source</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Source</label>
                                    <select name="apply_update_source" class="w-full rounded border border-slate-300 px-2 py-1">
                                        <option value="microsoft" selected>Microsoft Update</option>
                                        <option value="wsus">WSUS Server</option>
                                    </select>
                                </div>
                                <div class="grid gap-2 apply-update-wsus-row">
                                    <label class="text-xs text-slate-500">WSUS Server URL</label>
                                    <input name="apply_update_wsus_server" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="http://wsus:8530" />
                                </div>
                                <div class="grid gap-2 apply-update-wsus-row">
                                    <label class="text-xs text-slate-500">WSUS Status Server (optional)</label>
                                    <input name="apply_update_wsus_status_server" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="http://wsus:8530" />
                                </div>
                                <div class="apply-update-wsus-row">
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                        <input type="checkbox" name="apply_update_same_status" value="1" checked />
                                        use WSUS URL for status server
                                    </label>
                                </div>
                                <div class="grid gap-2 apply-update-wsus-row">
                                    <label class="text-xs text-slate-500">Target Group (optional)</label>
                                    <input name="apply_update_target_group" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Accounting-Laptops" />
                                </div>
                                <div class="flex flex-wrap gap-2 apply-update-wsus-row">
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                        <input type="checkbox" name="apply_update_target_group_enabled" value="1" checked />
                                        enable target group
                                    </label>
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                        <input type="checkbox" name="apply_update_block_internet" value="1" />
                                        block internet updates
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-2 space-y-3 rounded-xl border p-4 apply-network-field hidden network-shell">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-700">IPv4 Policy Setup</p>
                                <h4 class="mt-1 text-sm font-semibold text-slate-900">Windows IP assignment</h4>
                                <p class="mt-1 text-[11px] text-slate-600">Configure IPv4 with the same concepts admins see in Windows: target the adapter, then choose DHCP or a manual static IP configuration.</p>
                            </div>
                            <div class="network-callout max-w-sm text-[11px] text-slate-600">
                                <p class="font-semibold text-slate-800">DHCP vs static</p>
                                <p class="mt-1">Automatic returns the adapter to DHCP. Manual keeps the selected adapter on a fixed IPv4 address and optional gateway.</p>
                            </div>
                        </div>
                        <div class="grid gap-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                            <div class="network-card space-y-3">
                                <p class="network-card-title">1. Target The Windows Adapter</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">Identify Adapter By</label>
                                    <div>
                                        <select name="apply_network_selector_type" class="w-full rounded border border-slate-300 px-2 py-1">
                                            <option value="alias" selected>Interface Alias (Recommended)</option>
                                            <option value="index">Interface Index</option>
                                            <option value="description">Interface Description</option>
                                        </select>
                                        <p class="mt-1 text-[11px] text-slate-500">Use the same identifier Windows shows in adapter settings.</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-network-selector-row" data-selector="alias">
                                    <label class="text-xs text-slate-500">Windows Interface Alias</label>
                                    <div>
                                        <input name="apply_network_interface_alias" value="Ethernet" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Ethernet or Wi-Fi" />
                                        <p class="mt-1 text-[11px] text-slate-500">Examples: <span class="font-mono">Ethernet</span>, <span class="font-mono">Wi-Fi</span>, <span class="font-mono">Ethernet 2</span>.</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-network-selector-row hidden" data-selector="index">
                                    <label class="text-xs text-slate-500">Interface Index</label>
                                    <div>
                                        <input name="apply_network_interface_index" type="number" min="1" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="12" />
                                        <p class="mt-1 text-[11px] text-slate-500">Useful when you manage adapters by the exact Windows index number.</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr] apply-network-selector-row hidden" data-selector="description">
                                    <label class="text-xs text-slate-500">Interface Description</label>
                                    <div>
                                        <input name="apply_network_interface_description" class="w-full rounded border border-slate-300 px-2 py-1" placeholder="Intel(R) Ethernet Connection" />
                                        <p class="mt-1 text-[11px] text-slate-500">Matches the hardware description shown in Windows adapter properties.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="network-card space-y-3">
                                <p class="network-card-title">2. IPv4 Assignment</p>
                                <div class="grid gap-2 md:grid-cols-[minmax(0,180px)_1fr]">
                                    <label class="text-xs text-slate-500">IPv4 Assignment</label>
                                    <div>
                                        <select name="apply_network_ipv4_mode" class="w-full rounded border border-slate-300 px-2 py-1">
                                            <option value="static" selected>Manual (Static IP)</option>
                                            <option value="dhcp">Automatic (DHCP)</option>
                                        </select>
                                        <p class="mt-1 text-[11px] text-slate-500">Choose Automatic to restore DHCP. Choose Manual to set a fixed IPv4 address, subnet mask, and gateway.</p>
                                    </div>
                                </div>
                                <div class="grid gap-3 apply-network-static-row">
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <div>
                                            <label class="text-xs text-slate-500">IPv4 Address</label>
                                            <input name="apply_network_address" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.25" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-slate-500">Subnet Mask</label>
                                            <input name="apply_network_subnet_mask" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="255.255.255.0" />
                                        </div>
                                    </div>
                                    <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_160px] md:items-end">
                                        <div>
                                            <label class="text-xs text-slate-500">Default Gateway (optional)</label>
                                            <input name="apply_network_gateway" class="mt-1 w-full rounded border border-slate-300 px-2 py-1" placeholder="10.0.0.1" />
                                        </div>
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                            <p class="text-[11px] uppercase tracking-[0.16em] text-slate-500">Stored As</p>
                                            <p class="mt-1 text-sm font-semibold text-slate-800 apply-network-prefix-display">/24 prefix length</p>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-slate-500">Windows uses subnet masks such as <span class="font-mono">255.255.255.0</span>. DMS stores the same value internally as a prefix length such as <span class="font-mono">/24</span>.</p>
                                </div>
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                    <input type="checkbox" name="apply_network_dry_run" value="1" />
                                    dry run only: check compliance without changing the device
                                </label>
                            </div>
                        </div>
                    </div>
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
                    <div class="md:col-span-2 grid gap-2 md:grid-cols-2 rounded border border-slate-200 bg-white p-3 apply-scheduled-task-field hidden">
                        <p class="md:col-span-2 text-xs font-semibold text-slate-700">Scheduled Task Builder</p>
                        <label class="text-xs text-slate-500">Task Template</label>
                        <select name="apply_scheduled_template" class="rounded border border-slate-300 px-2 py-1">
                            <option value="power_daily_shutdown">Power: Daily Shutdown</option>
                            <option value="power_weekly_reboot">Power: Weekly Reboot</option>
                            <option value="power_daily_reboot">Power: Daily Reboot</option>
                            <option value="custom">Custom Task</option>
                        </select>
                        <label class="text-xs text-slate-500">Task Name</label>
                        <input name="apply_scheduled_task_name" value="DmsRecurringPowerSchedule" class="rounded border border-slate-300 px-2 py-1" />
                        <label class="text-xs text-slate-500">Schedule</label>
                        <select name="apply_scheduled_schedule" class="rounded border border-slate-300 px-2 py-1">
                            <option value="daily" selected>daily</option>
                            <option value="weekly">weekly</option>
                            <option value="hourly">hourly</option>
                            <option value="onstart">onstart</option>
                            <option value="onlogon">onlogon</option>
                        </select>
                        <label class="text-xs text-slate-500 apply-scheduled-time-label">Time</label>
                        <input name="apply_scheduled_time" type="time" value="23:00" class="rounded border border-slate-300 px-2 py-1 apply-scheduled-time-field" />
                        <label class="text-xs text-slate-500">Action</label>
                        <select name="apply_scheduled_action" class="rounded border border-slate-300 px-2 py-1">
                            <option value="shutdown" selected>shutdown</option>
                            <option value="reboot">reboot</option>
                            <option value="custom">custom command</option>
                        </select>
                        <label class="text-xs text-slate-500">Delay Seconds</label>
                        <input name="apply_scheduled_delay_seconds" type="number" min="0" max="86400" value="300" class="rounded border border-slate-300 px-2 py-1 apply-scheduled-power-field" />
                        <label class="text-xs flex items-center gap-2 apply-scheduled-power-field">
                            <input type="checkbox" name="apply_scheduled_force" value="1" checked />
                            force apps closed
                        </label>
                        <div class="apply-scheduled-power-field"></div>
                        <label class="md:col-span-2 text-xs text-slate-500 apply-scheduled-power-field">Message (optional)</label>
                        <input name="apply_scheduled_message" value="Scheduled daily shutdown by DMS policy" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 apply-scheduled-power-field" />
                        <label class="md:col-span-2 text-xs text-slate-500 apply-scheduled-custom-label hidden">Custom Command</label>
                        <textarea name="apply_scheduled_custom_command" class="rounded border border-slate-300 px-2 py-1 md:col-span-2 min-h-20 apply-scheduled-custom-field hidden" placeholder='e.g. powershell.exe -NoProfile -Command "Stop-Computer -Force"'></textarea>
                        <p class="md:col-span-2 text-[11px] text-slate-500">Choose a template or customize task name, schedule, and action. Custom command gives full control.</p>
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
                        @foreach(['registry','scheduled_task','command','firewall','dns','network_adapter','time','bitlocker','local_group','windows_update','baseline_profile','reboot_restore_mode','uwf'] as $type)
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

    <datalist id="time-zone-options">
        <option value="Afghanistan Standard Time"></option>
        <option value="Alaskan Standard Time"></option>
        <option value="Aleutian Standard Time"></option>
        <option value="Altai Standard Time"></option>
        <option value="Arab Standard Time"></option>
        <option value="Arabian Standard Time"></option>
        <option value="Arabic Standard Time"></option>
        <option value="Argentina Standard Time"></option>
        <option value="Astrakhan Standard Time"></option>
        <option value="Atlantic Standard Time"></option>
        <option value="AUS Central Standard Time"></option>
        <option value="Aus Central W. Standard Time"></option>
        <option value="AUS Eastern Standard Time"></option>
        <option value="Azerbaijan Standard Time"></option>
        <option value="Azores Standard Time"></option>
        <option value="Bahia Standard Time"></option>
        <option value="Bangladesh Standard Time"></option>
        <option value="Belarus Standard Time"></option>
        <option value="Bougainville Standard Time"></option>
        <option value="Canada Central Standard Time"></option>
        <option value="Cape Verde Standard Time"></option>
        <option value="Caucasus Standard Time"></option>
        <option value="Cen. Australia Standard Time"></option>
        <option value="Central America Standard Time"></option>
        <option value="Central Asia Standard Time"></option>
        <option value="Central Brazilian Standard Time"></option>
        <option value="Central Europe Standard Time"></option>
        <option value="Central European Standard Time"></option>
        <option value="Central Pacific Standard Time"></option>
        <option value="Central Standard Time"></option>
        <option value="Central Standard Time (Mexico)"></option>
        <option value="Chatham Islands Standard Time"></option>
        <option value="China Standard Time"></option>
        <option value="Cuba Standard Time"></option>
        <option value="Dateline Standard Time"></option>
        <option value="E. Africa Standard Time"></option>
        <option value="E. Australia Standard Time"></option>
        <option value="E. Europe Standard Time"></option>
        <option value="E. South America Standard Time"></option>
        <option value="Easter Island Standard Time"></option>
        <option value="Eastern Standard Time"></option>
        <option value="Eastern Standard Time (Mexico)"></option>
        <option value="Egypt Standard Time"></option>
        <option value="Ekaterinburg Standard Time"></option>
        <option value="Fiji Standard Time"></option>
        <option value="FLE Standard Time"></option>
        <option value="Georgian Standard Time"></option>
        <option value="GMT Standard Time"></option>
        <option value="Greenland Standard Time"></option>
        <option value="Greenwich Standard Time"></option>
        <option value="GTB Standard Time"></option>
        <option value="Haiti Standard Time"></option>
        <option value="Hawaiian Standard Time"></option>
        <option value="India Standard Time"></option>
        <option value="Iran Standard Time"></option>
        <option value="Israel Standard Time"></option>
        <option value="Jordan Standard Time"></option>
        <option value="Kaliningrad Standard Time"></option>
        <option value="Korea Standard Time"></option>
        <option value="Libya Standard Time"></option>
        <option value="Line Islands Standard Time"></option>
        <option value="Lord Howe Standard Time"></option>
        <option value="Magadan Standard Time"></option>
        <option value="Magallanes Standard Time"></option>
        <option value="Marquesas Standard Time"></option>
        <option value="Mauritius Standard Time"></option>
        <option value="Middle East Standard Time"></option>
        <option value="Montevideo Standard Time"></option>
        <option value="Morocco Standard Time"></option>
        <option value="Mountain Standard Time"></option>
        <option value="Mountain Standard Time (Mexico)"></option>
        <option value="Myanmar Standard Time"></option>
        <option value="N. Central Asia Standard Time"></option>
        <option value="Namibia Standard Time"></option>
        <option value="Nepal Standard Time"></option>
        <option value="New Zealand Standard Time"></option>
        <option value="Newfoundland Standard Time"></option>
        <option value="Norfolk Standard Time"></option>
        <option value="North Asia East Standard Time"></option>
        <option value="North Asia Standard Time"></option>
        <option value="North Korea Standard Time"></option>
        <option value="Omsk Standard Time"></option>
        <option value="Pacific SA Standard Time"></option>
        <option value="Pacific Standard Time"></option>
        <option value="Pacific Standard Time (Mexico)"></option>
        <option value="Pakistan Standard Time"></option>
        <option value="Paraguay Standard Time"></option>
        <option value="Qyzylorda Standard Time"></option>
        <option value="Romance Standard Time"></option>
        <option value="Russia Time Zone 10"></option>
        <option value="Russia Time Zone 11"></option>
        <option value="Russia Time Zone 3"></option>
        <option value="Russian Standard Time"></option>
        <option value="SA Eastern Standard Time"></option>
        <option value="SA Pacific Standard Time"></option>
        <option value="SA Western Standard Time"></option>
        <option value="Saint Pierre Standard Time"></option>
        <option value="Sakhalin Standard Time"></option>
        <option value="Samoa Standard Time"></option>
        <option value="Sao Tome Standard Time"></option>
        <option value="Saratov Standard Time"></option>
        <option value="SE Asia Standard Time"></option>
        <option value="Singapore Standard Time"></option>
        <option value="South Africa Standard Time"></option>
        <option value="South Sudan Standard Time"></option>
        <option value="Sri Lanka Standard Time"></option>
        <option value="Sudan Standard Time"></option>
        <option value="Syria Standard Time"></option>
        <option value="Taipei Standard Time"></option>
        <option value="Tasmania Standard Time"></option>
        <option value="Tocantins Standard Time"></option>
        <option value="Tokyo Standard Time"></option>
        <option value="Tomsk Standard Time"></option>
        <option value="Tonga Standard Time"></option>
        <option value="Transbaikal Standard Time"></option>
        <option value="Turkey Standard Time"></option>
        <option value="Turks And Caicos Standard Time"></option>
        <option value="Ulaanbaatar Standard Time"></option>
        <option value="US Eastern Standard Time"></option>
        <option value="US Mountain Standard Time"></option>
        <option value="UTC"></option>
        <option value="UTC+12"></option>
        <option value="UTC+13"></option>
        <option value="UTC-02"></option>
        <option value="UTC-08"></option>
        <option value="UTC-09"></option>
        <option value="UTC-11"></option>
        <option value="Venezuela Standard Time"></option>
        <option value="Vladivostok Standard Time"></option>
        <option value="Volgograd Standard Time"></option>
        <option value="W. Australia Standard Time"></option>
        <option value="W. Central Africa Standard Time"></option>
        <option value="W. Europe Standard Time"></option>
        <option value="W. Mongolia Standard Time"></option>
        <option value="West Asia Standard Time"></option>
        <option value="West Bank Standard Time"></option>
        <option value="West Pacific Standard Time"></option>
        <option value="Yakutsk Standard Time"></option>
        <option value="Yukon Standard Time"></option>
    </datalist>
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
                const applyDnsFields = form.querySelectorAll('.apply-dns-field');
                const applyDnsSelectorRows = form.querySelectorAll('.apply-dns-selector-row');
                const applyDnsServersRow = form.querySelector('.apply-dns-servers-row');
                const applyDnsSelectorTypeField = form.querySelector('select[name="apply_dns_selector_type"]');
                const applyDnsInterfaceAliasField = form.querySelector('input[name="apply_dns_interface_alias"]');
                const applyDnsInterfaceIndexField = form.querySelector('input[name="apply_dns_interface_index"]');
                const applyDnsInterfaceDescriptionField = form.querySelector('input[name="apply_dns_interface_description"]');
                const applyDnsModeField = form.querySelector('select[name="apply_dns_mode"]');
                const applyDnsPreferredServerField = form.querySelector('input[name="apply_dns_server_preferred"]');
                const applyDnsAlternateServerField = form.querySelector('input[name="apply_dns_server_alternate"]');
                const applyDnsAdditionalServersField = form.querySelector('textarea[name="apply_dns_server_additional"]');
                const applyDnsDryRunField = form.querySelector('input[name="apply_dns_dry_run"]');
                const applyNetworkFields = form.querySelectorAll('.apply-network-field');
                const applyNetworkSelectorRows = form.querySelectorAll('.apply-network-selector-row');
                const applyNetworkStaticRow = form.querySelector('.apply-network-static-row');
                const applyNetworkSelectorTypeField = form.querySelector('select[name="apply_network_selector_type"]');
                const applyNetworkInterfaceAliasField = form.querySelector('input[name="apply_network_interface_alias"]');
                const applyNetworkInterfaceIndexField = form.querySelector('input[name="apply_network_interface_index"]');
                const applyNetworkInterfaceDescriptionField = form.querySelector('input[name="apply_network_interface_description"]');
                const applyNetworkIpv4ModeField = form.querySelector('select[name="apply_network_ipv4_mode"]');
                const applyNetworkAddressField = form.querySelector('input[name="apply_network_address"]');
                const applyNetworkSubnetMaskField = form.querySelector('input[name="apply_network_subnet_mask"]');
                const applyNetworkGatewayField = form.querySelector('input[name="apply_network_gateway"]');
                const applyNetworkDryRunField = form.querySelector('input[name="apply_network_dry_run"]');
                const applyNetworkPrefixDisplay = form.querySelector('.apply-network-prefix-display');
                const applyTimeFields = form.querySelectorAll('.apply-time-field');
                const applyTimeTimezoneField = form.querySelector('input[name="apply_time_timezone"]');
                const applyTimeSyncModeField = form.querySelector('select[name="apply_time_sync_mode"]');
                const applyTimeNtpServersField = form.querySelector('textarea[name="apply_time_ntp_servers"]');
                const applyTimeServersRow = form.querySelector('.apply-time-servers-row');
                const applyTimeResyncField = form.querySelector('input[name="apply_time_resync"]');
                const applyTimeDryRunField = form.querySelector('input[name="apply_time_dry_run"]');
                const applyWindowsUpdateFields = form.querySelectorAll('.apply-windows-update-field');
                const applyUpdateActiveStartField = form.querySelector('input[name="apply_update_active_start"]');
                const applyUpdateActiveEndField = form.querySelector('input[name="apply_update_active_end"]');
                const applyUpdateForceWindowField = form.querySelector('input[name="apply_update_force_window"]');
                const applyUpdateSourceField = form.querySelector('select[name="apply_update_source"]');
                const applyUpdateWsusServerField = form.querySelector('input[name="apply_update_wsus_server"]');
                const applyUpdateWsusStatusField = form.querySelector('input[name="apply_update_wsus_status_server"]');
                const applyUpdateTargetGroupField = form.querySelector('input[name="apply_update_target_group"]');
                const applyUpdateTargetEnableField = form.querySelector('input[name="apply_update_target_group_enabled"]');
                const applyUpdateBlockInternetField = form.querySelector('input[name="apply_update_block_internet"]');
                const applyUpdateSameStatusField = form.querySelector('input[name="apply_update_same_status"]');
                const applyUpdateWsusRows = form.querySelectorAll('.apply-update-wsus-row');
                const applyUwfFields = form.querySelectorAll('.apply-uwf-field');
                const applyScheduledTaskFields = form.querySelectorAll('.apply-scheduled-task-field');
                const applyScheduledTemplateField = form.querySelector('select[name="apply_scheduled_template"]');
                const applyScheduledTaskNameField = form.querySelector('input[name="apply_scheduled_task_name"]');
                const applyScheduledScheduleField = form.querySelector('select[name="apply_scheduled_schedule"]');
                const applyScheduledTimeLabel = form.querySelector('.apply-scheduled-time-label');
                const applyScheduledTimeField = form.querySelector('input[name="apply_scheduled_time"]');
                const applyScheduledActionField = form.querySelector('select[name="apply_scheduled_action"]');
                const applyScheduledDelayField = form.querySelector('input[name="apply_scheduled_delay_seconds"]');
                const applyScheduledForceField = form.querySelector('input[name="apply_scheduled_force"]');
                const applyScheduledMessageField = form.querySelector('input[name="apply_scheduled_message"]');
                const applyScheduledCustomLabel = form.querySelector('.apply-scheduled-custom-label');
                const applyScheduledCustomField = form.querySelector('textarea[name="apply_scheduled_custom_command"]');
                const applyScheduledPowerFields = form.querySelectorAll('.apply-scheduled-power-field');
                const scheduledTaskNeedsTime = function (schedule) {
                    const normalized = String(schedule || '').toLowerCase();
                    return normalized !== 'onstart' && normalized !== 'onlogon';
                };
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
                const defaultDnsConfig = function () {
                    return {
                        selector_type: 'alias',
                        interface_alias: 'Ethernet',
                        interface_index: null,
                        interface_description: '',
                        mode: 'static',
                        servers: ['10.0.0.10', '10.0.0.11'],
                        dry_run: false,
                    };
                };
                const defaultTimeConfig = function () {
                    return {
                        timezone: 'UTC',
                        sync_mode: 'manual',
                        ntp_servers: ['time.windows.com', 'pool.ntp.org'],
                        resync: true,
                        dry_run: false,
                    };
                };
                const defaultWindowsUpdateConfig = function () {
                    return {
                        active_hours_start: 8,
                        active_hours_end: 17,
                        force_install_window: '',
                        update_source: 'microsoft',
                        wsus_server: '',
                        wsus_status_server: '',
                        target_group: '',
                        enable_target_group: true,
                        block_internet_updates: false,
                    };
                };
                const normalizeDnsConfig = function (rawConfig) {
                    const cfg = Object.assign(defaultDnsConfig(), rawConfig || {});
                    const interfaceAlias = String(cfg.interface_alias || '').trim();
                    const interfaceDescription = String(cfg.interface_description || '').trim();
                    const rawIndex = Number(cfg.interface_index);
                    const interfaceIndex = Number.isFinite(rawIndex) && rawIndex > 0 ? Math.trunc(rawIndex) : null;
                    const explicitSelectorType = String(cfg.selector_type || '').toLowerCase();
                    const inferredSelectorType = interfaceAlias !== ''
                        ? 'alias'
                        : (interfaceIndex !== null ? 'index' : (interfaceDescription !== '' ? 'description' : 'alias'));
                    const selectorType = ['alias', 'index', 'description'].includes(explicitSelectorType)
                        ? explicitSelectorType
                        : inferredSelectorType;

                    cfg.selector_type = selectorType;
                    cfg.interface_alias = selectorType === 'alias' ? interfaceAlias : '';
                    cfg.interface_index = selectorType === 'index' ? interfaceIndex : null;
                    cfg.interface_description = selectorType === 'description' ? interfaceDescription : '';
                    cfg.mode = String(cfg.mode || 'static').toLowerCase() === 'automatic' ? 'automatic' : 'static';
                    cfg.servers = Array.isArray(cfg.servers)
                        ? Array.from(new Set(cfg.servers.map(function (item) { return String(item || '').trim(); }).filter(function (item) { return item.length > 0; })))
                        : parseListFromText(cfg.servers || '');
                    if (cfg.mode === 'static' && cfg.servers.length === 0) {
                        cfg.servers = defaultDnsConfig().servers.slice();
                    }
                    if (cfg.mode === 'automatic') {
                        cfg.servers = [];
                    }
                    cfg.dry_run = Boolean(cfg.dry_run);
                    return cfg;
                };
                const normalizeTimeConfig = function (rawConfig) {
                    const cfg = Object.assign(defaultTimeConfig(), rawConfig || {});
                    cfg.timezone = String(cfg.timezone || '').trim();
                    const syncMode = String(cfg.sync_mode || '').toLowerCase();
                    cfg.sync_mode = ['manual', 'domhier', 'all'].includes(syncMode) ? syncMode : 'manual';
                    cfg.ntp_servers = Array.isArray(cfg.ntp_servers)
                        ? Array.from(new Set(cfg.ntp_servers.map(function (item) { return String(item || '').trim(); }).filter(function (item) { return item.length > 0; })))
                        : parseListFromText(cfg.ntp_servers || '');
                    if (cfg.sync_mode !== 'manual') {
                        cfg.ntp_servers = [];
                    }
                    cfg.resync = Boolean(cfg.resync);
                    cfg.dry_run = Boolean(cfg.dry_run);
                    return cfg;
                };
                const normalizeWindowsUpdateConfig = function (rawConfig) {
                    const cfg = Object.assign(defaultWindowsUpdateConfig(), rawConfig || {});
                    const start = Number(cfg.active_hours_start);
                    const end = Number(cfg.active_hours_end);
                    cfg.active_hours_start = Number.isFinite(start) ? Math.min(23, Math.max(0, Math.trunc(start))) : defaultWindowsUpdateConfig().active_hours_start;
                    cfg.active_hours_end = Number.isFinite(end) ? Math.min(23, Math.max(0, Math.trunc(end))) : defaultWindowsUpdateConfig().active_hours_end;
                    cfg.force_install_window = String(cfg.force_install_window || '').trim();
                    const source = String(cfg.update_source || '').toLowerCase();
                    const inferredSource = String(cfg.wsus_server || '').trim() !== '' ? 'wsus' : 'microsoft';
                    cfg.update_source = ['microsoft', 'wsus'].includes(source) ? source : inferredSource;
                    cfg.wsus_server = String(cfg.wsus_server || '').trim();
                    cfg.wsus_status_server = String(cfg.wsus_status_server || '').trim();
                    cfg.target_group = String(cfg.target_group || '').trim();
                    cfg.enable_target_group = Boolean(cfg.enable_target_group);
                    cfg.block_internet_updates = Boolean(cfg.block_internet_updates);
                    if (cfg.update_source !== 'wsus') {
                        cfg.wsus_server = '';
                        cfg.wsus_status_server = '';
                        cfg.target_group = '';
                        cfg.enable_target_group = true;
                        cfg.block_internet_updates = false;
                    }
                    return cfg;
                };
                const syncDnsFieldVisibility = function (enabled) {
                    applyDnsFields.forEach(function (section) {
                        section.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled;
                        });
                    });
                    const selectorType = applyDnsSelectorTypeField ? applyDnsSelectorTypeField.value : 'alias';
                    applyDnsSelectorRows.forEach(function (row) {
                        const matches = (row.getAttribute('data-selector') || '') === selectorType;
                        row.classList.toggle('hidden', !enabled || !matches);
                        row.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled || !matches;
                        });
                    });

                    const isStatic = (applyDnsModeField ? applyDnsModeField.value : 'static') === 'static';
                    if (applyDnsServersRow) {
                        applyDnsServersRow.classList.toggle('hidden', !enabled || !isStatic);
                        applyDnsServersRow.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled || !isStatic;
                        });
                    }
                };
                const syncTimeFieldVisibility = function (enabled) {
                    applyTimeFields.forEach(function (section) {
                        section.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled;
                        });
                    });
                    const isManual = (applyTimeSyncModeField ? applyTimeSyncModeField.value : 'manual') === 'manual';
                    if (applyTimeServersRow) {
                        applyTimeServersRow.classList.toggle('hidden', !enabled || !isManual);
                        applyTimeServersRow.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled || !isManual;
                        });
                    }
                };
                const syncWindowsUpdateFieldVisibility = function (enabled) {
                    applyWindowsUpdateFields.forEach(function (section) {
                        section.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled;
                        });
                    });
                    const isWsus = (applyUpdateSourceField ? applyUpdateSourceField.value : 'microsoft') === 'wsus';
                    applyUpdateWsusRows.forEach(function (row) {
                        row.classList.toggle('hidden', !enabled || !isWsus);
                        row.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled || !isWsus;
                        });
                    });
                    const sameStatus = applyUpdateSameStatusField ? applyUpdateSameStatusField.checked : false;
                    if (applyUpdateWsusStatusField) {
                        applyUpdateWsusStatusField.disabled = !enabled || !isWsus || sameStatus;
                    }
                    if (applyUpdateWsusServerField && applyUpdateWsusStatusField && sameStatus) {
                        applyUpdateWsusStatusField.value = applyUpdateWsusServerField.value || '';
                    }
                };
                const syncDnsFieldsFromJson = function () {
                    const config = normalizeDnsConfig(tryParseJson(jsonInput.value));
                    if (applyDnsSelectorTypeField) applyDnsSelectorTypeField.value = config.selector_type;
                    if (applyDnsInterfaceAliasField) applyDnsInterfaceAliasField.value = config.interface_alias || defaultDnsConfig().interface_alias;
                    if (applyDnsInterfaceIndexField) applyDnsInterfaceIndexField.value = config.interface_index !== null ? String(config.interface_index) : '';
                    if (applyDnsInterfaceDescriptionField) applyDnsInterfaceDescriptionField.value = config.interface_description;
                    if (applyDnsModeField) applyDnsModeField.value = config.mode;
                    if (applyDnsPreferredServerField) applyDnsPreferredServerField.value = config.servers[0] || '';
                    if (applyDnsAlternateServerField) applyDnsAlternateServerField.value = config.servers[1] || '';
                    if (applyDnsAdditionalServersField) applyDnsAdditionalServersField.value = config.servers.slice(2).join('\n');
                    if (applyDnsDryRunField) applyDnsDryRunField.checked = config.dry_run;
                    syncDnsFieldVisibility(true);
                };
                const syncTimeFieldsFromJson = function () {
                    const config = normalizeTimeConfig(tryParseJson(jsonInput.value));
                    if (applyTimeTimezoneField) applyTimeTimezoneField.value = config.timezone || defaultTimeConfig().timezone;
                    if (applyTimeSyncModeField) applyTimeSyncModeField.value = config.sync_mode;
                    if (applyTimeNtpServersField) applyTimeNtpServersField.value = config.ntp_servers.join('\n');
                    if (applyTimeResyncField) applyTimeResyncField.checked = config.resync;
                    if (applyTimeDryRunField) applyTimeDryRunField.checked = config.dry_run;
                    syncTimeFieldVisibility(true);
                };
                const syncWindowsUpdateFieldsFromJson = function () {
                    const config = normalizeWindowsUpdateConfig(tryParseJson(jsonInput.value));
                    if (applyUpdateActiveStartField) applyUpdateActiveStartField.value = String(config.active_hours_start);
                    if (applyUpdateActiveEndField) applyUpdateActiveEndField.value = String(config.active_hours_end);
                    if (applyUpdateForceWindowField) applyUpdateForceWindowField.value = config.force_install_window || '';
                    if (applyUpdateSourceField) applyUpdateSourceField.value = config.update_source;
                    if (applyUpdateWsusServerField) applyUpdateWsusServerField.value = config.wsus_server;
                    if (applyUpdateWsusStatusField) applyUpdateWsusStatusField.value = config.wsus_status_server;
                    if (applyUpdateTargetGroupField) applyUpdateTargetGroupField.value = config.target_group;
                    if (applyUpdateTargetEnableField) applyUpdateTargetEnableField.checked = config.enable_target_group;
                    if (applyUpdateBlockInternetField) applyUpdateBlockInternetField.checked = config.block_internet_updates;
                    if (applyUpdateSameStatusField) {
                        const sameStatus = config.wsus_status_server === '' || config.wsus_status_server === config.wsus_server;
                        applyUpdateSameStatusField.checked = sameStatus;
                        if (sameStatus && applyUpdateWsusStatusField) {
                            applyUpdateWsusStatusField.value = config.wsus_server;
                        }
                    }
                    syncWindowsUpdateFieldVisibility(true);
                };
                const syncJsonFromDnsFields = function () {
                    const dnsServers = [];
                    const preferredDns = applyDnsPreferredServerField ? String(applyDnsPreferredServerField.value || '').trim() : '';
                    const alternateDns = applyDnsAlternateServerField ? String(applyDnsAlternateServerField.value || '').trim() : '';
                    if (preferredDns !== '') {
                        dnsServers.push(preferredDns);
                    }
                    if (alternateDns !== '') {
                        dnsServers.push(alternateDns);
                    }
                    parseListFromText(applyDnsAdditionalServersField ? applyDnsAdditionalServersField.value : '').forEach(function (server) {
                        if (!dnsServers.includes(server)) {
                            dnsServers.push(server);
                        }
                    });
                    const config = normalizeDnsConfig({
                        selector_type: applyDnsSelectorTypeField ? applyDnsSelectorTypeField.value : 'alias',
                        interface_alias: applyDnsInterfaceAliasField ? applyDnsInterfaceAliasField.value : '',
                        interface_index: applyDnsInterfaceIndexField ? applyDnsInterfaceIndexField.value : '',
                        interface_description: applyDnsInterfaceDescriptionField ? applyDnsInterfaceDescriptionField.value : '',
                        mode: applyDnsModeField ? applyDnsModeField.value : 'static',
                        servers: dnsServers,
                        dry_run: applyDnsDryRunField ? applyDnsDryRunField.checked : false,
                    });
                    const nextConfig = {
                        mode: config.mode,
                    };
                    if (config.selector_type === 'alias' && config.interface_alias !== '') {
                        nextConfig.interface_alias = config.interface_alias;
                    } else if (config.selector_type === 'index' && config.interface_index !== null) {
                        nextConfig.interface_index = config.interface_index;
                    } else if (config.selector_type === 'description' && config.interface_description !== '') {
                        nextConfig.interface_description = config.interface_description;
                    }
                    if (config.mode === 'static') {
                        nextConfig.servers = config.servers;
                    }
                    if (config.dry_run) {
                        nextConfig.dry_run = true;
                    }
                    jsonInput.value = JSON.stringify(nextConfig);
                };
                const syncJsonFromTimeFields = function () {
                    const config = normalizeTimeConfig({
                        timezone: applyTimeTimezoneField ? applyTimeTimezoneField.value : '',
                        sync_mode: applyTimeSyncModeField ? applyTimeSyncModeField.value : 'manual',
                        ntp_servers: parseListFromText(applyTimeNtpServersField ? applyTimeNtpServersField.value : ''),
                        resync: applyTimeResyncField ? applyTimeResyncField.checked : true,
                        dry_run: applyTimeDryRunField ? applyTimeDryRunField.checked : false,
                    });
                    const nextConfig = {};
                    if (config.timezone !== '') {
                        nextConfig.timezone = config.timezone;
                    }
                    if (config.sync_mode !== '') {
                        nextConfig.sync_mode = config.sync_mode;
                    }
                    if (config.sync_mode === 'manual') {
                        nextConfig.ntp_servers = config.ntp_servers;
                    }
                    if (config.resync) {
                        nextConfig.resync = true;
                    }
                    if (config.dry_run) {
                        nextConfig.dry_run = true;
                    }
                    jsonInput.value = JSON.stringify(nextConfig);
                };
                const syncJsonFromWindowsUpdateFields = function () {
                    const rawStart = applyUpdateActiveStartField ? Number(applyUpdateActiveStartField.value) : 8;
                    const rawEnd = applyUpdateActiveEndField ? Number(applyUpdateActiveEndField.value) : 17;
                    const sameStatus = applyUpdateSameStatusField ? applyUpdateSameStatusField.checked : false;
                    const config = normalizeWindowsUpdateConfig({
                        active_hours_start: Number.isFinite(rawStart) ? rawStart : 8,
                        active_hours_end: Number.isFinite(rawEnd) ? rawEnd : 17,
                        force_install_window: applyUpdateForceWindowField ? applyUpdateForceWindowField.value : '',
                        update_source: applyUpdateSourceField ? applyUpdateSourceField.value : 'microsoft',
                        wsus_server: applyUpdateWsusServerField ? applyUpdateWsusServerField.value : '',
                        wsus_status_server: (applyUpdateWsusStatusField && !sameStatus) ? applyUpdateWsusStatusField.value : '',
                        target_group: applyUpdateTargetGroupField ? applyUpdateTargetGroupField.value : '',
                        enable_target_group: applyUpdateTargetEnableField ? applyUpdateTargetEnableField.checked : true,
                        block_internet_updates: applyUpdateBlockInternetField ? applyUpdateBlockInternetField.checked : false,
                    });
                    const nextConfig = {
                        active_hours_start: config.active_hours_start,
                        active_hours_end: config.active_hours_end,
                    };
                    if (config.force_install_window !== '') {
                        nextConfig.force_install_window = config.force_install_window;
                    }
                    if (config.update_source !== '') {
                        nextConfig.update_source = config.update_source;
                    }
                    if (config.update_source === 'wsus') {
                        if (config.wsus_server !== '') {
                            nextConfig.wsus_server = config.wsus_server;
                        }
                        if (config.wsus_status_server !== '') {
                            nextConfig.wsus_status_server = config.wsus_status_server;
                        }
                        if (config.target_group !== '') {
                            nextConfig.target_group = config.target_group;
                            nextConfig.enable_target_group = config.enable_target_group;
                        }
                        if (config.block_internet_updates) {
                            nextConfig.block_internet_updates = true;
                        }
                    }
                    jsonInput.value = JSON.stringify(nextConfig);
                };
                const prefixLengthToSubnetMask = function (prefixLength) {
                    const prefix = Number(prefixLength);
                    if (!Number.isFinite(prefix) || prefix < 0 || prefix > 32) {
                        return '';
                    }
                    let remainingBits = Math.trunc(prefix);
                    const octets = [];
                    for (let index = 0; index < 4; index += 1) {
                        const bits = Math.max(0, Math.min(8, remainingBits));
                        const value = bits === 0 ? 0 : 256 - Math.pow(2, 8 - bits);
                        octets.push(String(value));
                        remainingBits -= bits;
                    }
                    return octets.join('.');
                };
                const subnetMaskToPrefixLength = function (mask) {
                    const value = String(mask || '').trim();
                    if (value === '') {
                        return null;
                    }
                    const parts = value.split('.');
                    if (parts.length !== 4) {
                        return null;
                    }
                    const bits = [];
                    for (let index = 0; index < parts.length; index += 1) {
                        const part = parts[index];
                        if (!/^\d+$/.test(part)) {
                            return null;
                        }
                        const octet = Number(part);
                        if (!Number.isInteger(octet) || octet < 0 || octet > 255) {
                            return null;
                        }
                        bits.push(octet.toString(2).padStart(8, '0'));
                    }
                    const bitString = bits.join('');
                    if (!/^1*0*$/.test(bitString)) {
                        return null;
                    }
                    const firstZero = bitString.indexOf('0');
                    return firstZero === -1 ? 32 : firstZero;
                };
                const updateNetworkPrefixDisplay = function (enabled) {
                    if (!applyNetworkPrefixDisplay) {
                        return;
                    }
                    if (!enabled) {
                        applyNetworkPrefixDisplay.textContent = '/24 prefix length';
                        return;
                    }
                    const ipv4Mode = applyNetworkIpv4ModeField ? applyNetworkIpv4ModeField.value : 'static';
                    if (ipv4Mode === 'dhcp') {
                        applyNetworkPrefixDisplay.textContent = 'DHCP managed';
                        return;
                    }
                    const subnetMask = applyNetworkSubnetMaskField ? applyNetworkSubnetMaskField.value : '';
                    const prefixLength = subnetMaskToPrefixLength(subnetMask);
                    if (String(subnetMask || '').trim() === '') {
                        applyNetworkPrefixDisplay.textContent = 'Enter subnet mask';
                        return;
                    }
                    if (prefixLength === null || prefixLength < 1 || prefixLength > 32) {
                        applyNetworkPrefixDisplay.textContent = 'Invalid subnet mask';
                        return;
                    }
                    applyNetworkPrefixDisplay.textContent = `/${prefixLength} prefix length`;
                };
                const defaultNetworkAdapterConfig = function () {
                    return {
                        selector_type: 'alias',
                        interface_alias: 'Ethernet',
                        interface_index: null,
                        interface_description: '',
                        ipv4_mode: 'static',
                        address: '10.0.0.25',
                        prefix_length: 24,
                        gateway: '10.0.0.1',
                        dry_run: false,
                    };
                };
                const normalizeNetworkAdapterConfig = function (rawConfig) {
                    const cfg = Object.assign(defaultNetworkAdapterConfig(), rawConfig || {});
                    const interfaceAlias = String(cfg.interface_alias || '').trim();
                    const interfaceDescription = String(cfg.interface_description || '').trim();
                    const rawIndex = Number(cfg.interface_index);
                    const interfaceIndex = Number.isFinite(rawIndex) && rawIndex > 0 ? Math.trunc(rawIndex) : null;
                    const explicitSelectorType = String(cfg.selector_type || '').toLowerCase();
                    const inferredSelectorType = interfaceAlias !== ''
                        ? 'alias'
                        : (interfaceIndex !== null ? 'index' : (interfaceDescription !== '' ? 'description' : 'alias'));
                    const selectorType = ['alias', 'index', 'description'].includes(explicitSelectorType)
                        ? explicitSelectorType
                        : inferredSelectorType;
                    const rawPrefix = Number(cfg.prefix_length);
                    const subnetMaskPrefix = subnetMaskToPrefixLength(cfg.subnet_mask);
                    let prefixLength = Number.isFinite(rawPrefix) ? Math.trunc(rawPrefix) : null;
                    if (prefixLength === null || prefixLength < 1 || prefixLength > 32) {
                        prefixLength = subnetMaskPrefix;
                    }
                    if (prefixLength !== null && (prefixLength < 1 || prefixLength > 32)) {
                        prefixLength = null;
                    }

                    cfg.selector_type = selectorType;
                    cfg.interface_alias = selectorType === 'alias' ? interfaceAlias : '';
                    cfg.interface_index = selectorType === 'index' ? interfaceIndex : null;
                    cfg.interface_description = selectorType === 'description' ? interfaceDescription : '';
                    cfg.ipv4_mode = String(cfg.ipv4_mode || 'static').toLowerCase() === 'dhcp' ? 'dhcp' : 'static';
                    cfg.address = String(cfg.address || '').trim();
                    cfg.prefix_length = prefixLength;
                    cfg.gateway = String(cfg.gateway || '').trim();
                    cfg.dry_run = Boolean(cfg.dry_run);
                    return cfg;
                };
                const syncNetworkFieldVisibility = function (enabled) {
                    applyNetworkFields.forEach(function (section) {
                        section.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled;
                        });
                    });
                    const selectorType = applyNetworkSelectorTypeField ? applyNetworkSelectorTypeField.value : 'alias';
                    applyNetworkSelectorRows.forEach(function (row) {
                        const matches = (row.getAttribute('data-selector') || '') === selectorType;
                        row.classList.toggle('hidden', !enabled || !matches);
                        row.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled || !matches;
                        });
                    });

                    const isStatic = (applyNetworkIpv4ModeField ? applyNetworkIpv4ModeField.value : 'static') === 'static';
                    if (applyNetworkStaticRow) {
                        applyNetworkStaticRow.classList.toggle('hidden', !enabled || !isStatic);
                        applyNetworkStaticRow.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled || !isStatic;
                        });
                    }
                    updateNetworkPrefixDisplay(enabled);
                };
                const syncNetworkFieldsFromJson = function () {
                    const config = normalizeNetworkAdapterConfig(tryParseJson(jsonInput.value));
                    if (applyNetworkSelectorTypeField) applyNetworkSelectorTypeField.value = config.selector_type;
                    if (applyNetworkInterfaceAliasField) applyNetworkInterfaceAliasField.value = config.interface_alias || defaultNetworkAdapterConfig().interface_alias;
                    if (applyNetworkInterfaceIndexField) applyNetworkInterfaceIndexField.value = config.interface_index !== null ? String(config.interface_index) : '';
                    if (applyNetworkInterfaceDescriptionField) applyNetworkInterfaceDescriptionField.value = config.interface_description;
                    if (applyNetworkIpv4ModeField) applyNetworkIpv4ModeField.value = config.ipv4_mode;
                    if (applyNetworkAddressField) applyNetworkAddressField.value = config.address;
                    if (applyNetworkSubnetMaskField) applyNetworkSubnetMaskField.value = prefixLengthToSubnetMask(config.prefix_length);
                    if (applyNetworkGatewayField) applyNetworkGatewayField.value = config.gateway;
                    if (applyNetworkDryRunField) applyNetworkDryRunField.checked = config.dry_run;
                    syncNetworkFieldVisibility(true);
                };
                const syncJsonFromNetworkFields = function () {
                    const subnetMask = applyNetworkSubnetMaskField ? applyNetworkSubnetMaskField.value : '';
                    const prefixLength = subnetMaskToPrefixLength(subnetMask);
                    const config = normalizeNetworkAdapterConfig({
                        selector_type: applyNetworkSelectorTypeField ? applyNetworkSelectorTypeField.value : 'alias',
                        interface_alias: applyNetworkInterfaceAliasField ? applyNetworkInterfaceAliasField.value : '',
                        interface_index: applyNetworkInterfaceIndexField ? applyNetworkInterfaceIndexField.value : '',
                        interface_description: applyNetworkInterfaceDescriptionField ? applyNetworkInterfaceDescriptionField.value : '',
                        ipv4_mode: applyNetworkIpv4ModeField ? applyNetworkIpv4ModeField.value : 'static',
                        address: applyNetworkAddressField ? applyNetworkAddressField.value : '',
                        prefix_length: prefixLength,
                        subnet_mask: subnetMask,
                        gateway: applyNetworkGatewayField ? applyNetworkGatewayField.value : '',
                        dry_run: applyNetworkDryRunField ? applyNetworkDryRunField.checked : false,
                    });
                    const nextConfig = {
                        ipv4_mode: config.ipv4_mode,
                    };
                    if (config.selector_type === 'alias' && config.interface_alias !== '') {
                        nextConfig.interface_alias = config.interface_alias;
                    } else if (config.selector_type === 'index' && config.interface_index !== null) {
                        nextConfig.interface_index = config.interface_index;
                    } else if (config.selector_type === 'description' && config.interface_description !== '') {
                        nextConfig.interface_description = config.interface_description;
                    }
                    if (config.ipv4_mode === 'static') {
                        if (config.address !== '') {
                            nextConfig.address = config.address;
                        }
                        if (config.prefix_length !== null) {
                            nextConfig.prefix_length = config.prefix_length;
                        }
                        if (config.gateway !== '') {
                            nextConfig.gateway = config.gateway;
                        }
                    }
                    if (config.dry_run) {
                        nextConfig.dry_run = true;
                    }
                    jsonInput.value = JSON.stringify(nextConfig);
                    updateNetworkPrefixDisplay(true);
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
                const scheduledTemplates = {
                    power_daily_shutdown: {
                        task_name: 'DmsRecurringPowerSchedule',
                        schedule: 'daily',
                        time: '23:00',
                        action: 'shutdown',
                        delay_seconds: 300,
                        force: true,
                        message: 'Scheduled daily shutdown by DMS policy',
                    },
                    power_weekly_reboot: {
                        task_name: 'WeeklyMaintenanceReboot',
                        schedule: 'weekly',
                        time: '04:00',
                        action: 'reboot',
                        delay_seconds: 60,
                        force: false,
                        message: 'Scheduled maintenance reboot',
                    },
                    power_daily_reboot: {
                        task_name: 'DailyMaintenanceReboot',
                        schedule: 'daily',
                        time: '03:30',
                        action: 'reboot',
                        delay_seconds: 60,
                        force: true,
                        message: 'Scheduled daily reboot by DMS policy',
                    },
                };
                const buildPowerScheduledCommand = function (action, delaySeconds, force, message) {
                    const normalizedAction = action === 'reboot' ? 'reboot' : 'shutdown';
                    const modeFlag = normalizedAction === 'reboot' ? '/r' : '/s';
                    const delay = Number.isFinite(Number(delaySeconds))
                        ? Math.max(0, Math.min(86400, Math.trunc(Number(delaySeconds))))
                        : 300;
                    const commandParts = ['shutdown.exe', modeFlag, '/t', String(delay)];
                    if (force) {
                        commandParts.push('/f');
                    }
                    const messageText = String(message || '').trim();
                    if (messageText !== '') {
                        const escaped = messageText.replace(/"/g, '\\"');
                        commandParts.push('/c', `"${escaped}"`);
                    }
                    return commandParts.join(' ');
                };
                const parseScheduledPowerCommand = function (command) {
                    const parsed = {
                        action: 'custom',
                        delay_seconds: 300,
                        force: true,
                        message: '',
                        custom_command: String(command || '').trim(),
                    };
                    const value = String(command || '').trim();
                    if (!/^shutdown(\.exe)?\b/i.test(value)) {
                        return parsed;
                    }
                    if (/\s\/r\b/i.test(value)) {
                        parsed.action = 'reboot';
                    } else if (/\s\/s\b/i.test(value)) {
                        parsed.action = 'shutdown';
                    } else {
                        parsed.action = 'custom';
                        return parsed;
                    }
                    const delayMatch = value.match(/\s\/t\s+(\d+)/i);
                    if (delayMatch) {
                        const delay = Number(delayMatch[1]);
                        if (Number.isFinite(delay)) {
                            parsed.delay_seconds = Math.max(0, Math.min(86400, Math.trunc(delay)));
                        }
                    }
                    parsed.force = /\s\/f\b/i.test(value);
                    const messageMatch = value.match(/\s\/c\s+"([^"]*)"/i) || value.match(/\s\/c\s+(.+)$/i);
                    if (messageMatch) {
                        parsed.message = String(messageMatch[1] || '').trim().replace(/\\"/g, '"');
                    }
                    parsed.custom_command = '';
                    return parsed;
                };
                const defaultScheduledTaskConfig = function () {
                    return {
                        task_name: 'DmsRecurringPowerSchedule',
                        ensure: 'present',
                        schedule: 'daily',
                        time: '23:00',
                        command: 'shutdown.exe /s /t 300 /f /c "Scheduled daily shutdown by DMS policy"',
                    };
                };
                const normalizeScheduledTaskConfig = function (rawConfig) {
                    const cfg = Object.assign(defaultScheduledTaskConfig(), rawConfig || {});
                    cfg.task_name = String(cfg.task_name || '').trim() || defaultScheduledTaskConfig().task_name;
                    cfg.ensure = 'present';
                    const scheduleRaw = String(cfg.schedule || 'daily').toLowerCase().trim();
                    cfg.schedule = ['daily', 'weekly', 'hourly', 'onstart', 'onlogon'].includes(scheduleRaw) ? scheduleRaw : 'daily';
                    const rawTime = String(cfg.time || '').trim();
                    const isTimeValid = /^\d{2}:\d{2}$/.test(rawTime);
                    cfg.time = scheduledTaskNeedsTime(cfg.schedule)
                        ? (isTimeValid ? rawTime : defaultScheduledTaskConfig().time)
                        : '';
                    cfg.command = String(cfg.command || '').trim();
                    if (cfg.command === '') {
                        cfg.command = defaultScheduledTaskConfig().command;
                    }
                    return cfg;
                };
                const detectScheduledTemplate = function (config, parsedPower) {
                    if (parsedPower.action === 'shutdown'
                        && config.task_name === scheduledTemplates.power_daily_shutdown.task_name
                        && config.schedule === scheduledTemplates.power_daily_shutdown.schedule) {
                        return 'power_daily_shutdown';
                    }
                    if (parsedPower.action === 'reboot'
                        && config.task_name === scheduledTemplates.power_weekly_reboot.task_name
                        && config.schedule === scheduledTemplates.power_weekly_reboot.schedule) {
                        return 'power_weekly_reboot';
                    }
                    if (parsedPower.action === 'reboot'
                        && config.task_name === scheduledTemplates.power_daily_reboot.task_name
                        && config.schedule === scheduledTemplates.power_daily_reboot.schedule) {
                        return 'power_daily_reboot';
                    }
                    return 'custom';
                };
                const syncScheduledFieldVisibility = function (enabled) {
                    applyScheduledTaskFields.forEach(function (section) {
                        section.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled;
                        });
                    });
                    const schedule = applyScheduledScheduleField ? applyScheduledScheduleField.value : 'daily';
                    const needsTime = scheduledTaskNeedsTime(schedule);
                    if (applyScheduledTimeLabel) {
                        applyScheduledTimeLabel.classList.toggle('hidden', !enabled || !needsTime);
                    }
                    if (applyScheduledTimeField) {
                        applyScheduledTimeField.classList.toggle('hidden', !enabled || !needsTime);
                        applyScheduledTimeField.disabled = !enabled || !needsTime;
                    }
                    const isCustomAction = (applyScheduledActionField ? applyScheduledActionField.value : 'shutdown') === 'custom';
                    applyScheduledPowerFields.forEach(function (el) {
                        el.classList.toggle('hidden', !enabled || isCustomAction);
                        el.querySelectorAll('input,select,textarea').forEach(function (input) {
                            input.disabled = !enabled || isCustomAction;
                        });
                    });
                    if (applyScheduledCustomLabel) {
                        applyScheduledCustomLabel.classList.toggle('hidden', !enabled || !isCustomAction);
                    }
                    if (applyScheduledCustomField) {
                        applyScheduledCustomField.classList.toggle('hidden', !enabled || !isCustomAction);
                        applyScheduledCustomField.disabled = !enabled || !isCustomAction;
                    }
                };
                const syncScheduledFieldsFromJson = function () {
                    const config = normalizeScheduledTaskConfig(tryParseJson(jsonInput.value));
                    const parsedPower = parseScheduledPowerCommand(config.command);
                    if (applyScheduledTaskNameField) {
                        applyScheduledTaskNameField.value = config.task_name;
                    }
                    if (applyScheduledScheduleField) {
                        applyScheduledScheduleField.value = config.schedule;
                    }
                    if (applyScheduledTimeField) {
                        applyScheduledTimeField.value = config.time;
                    }
                    if (applyScheduledActionField) {
                        applyScheduledActionField.value = parsedPower.action;
                    }
                    if (applyScheduledDelayField) {
                        applyScheduledDelayField.value = String(parsedPower.delay_seconds);
                    }
                    if (applyScheduledForceField) {
                        applyScheduledForceField.checked = parsedPower.force;
                    }
                    if (applyScheduledMessageField) {
                        applyScheduledMessageField.value = parsedPower.message;
                    }
                    if (applyScheduledCustomField) {
                        applyScheduledCustomField.value = parsedPower.custom_command;
                    }
                    if (applyScheduledTemplateField) {
                        applyScheduledTemplateField.value = detectScheduledTemplate(config, parsedPower);
                    }
                    syncScheduledFieldVisibility(true);
                };
                const syncJsonFromScheduledFields = function () {
                    const config = normalizeScheduledTaskConfig({
                        task_name: applyScheduledTaskNameField ? applyScheduledTaskNameField.value : '',
                        schedule: applyScheduledScheduleField ? applyScheduledScheduleField.value : 'daily',
                        time: applyScheduledTimeField ? applyScheduledTimeField.value : '',
                        command: '',
                    });
                    const action = applyScheduledActionField ? applyScheduledActionField.value : 'shutdown';
                    if (action === 'custom') {
                        config.command = String(applyScheduledCustomField ? applyScheduledCustomField.value : '').trim();
                    } else {
                        config.command = buildPowerScheduledCommand(
                            action,
                            applyScheduledDelayField ? applyScheduledDelayField.value : 300,
                            applyScheduledForceField ? applyScheduledForceField.checked : true,
                            applyScheduledMessageField ? applyScheduledMessageField.value : ''
                        );
                    }
                    if (config.command === '') {
                        config.command = defaultScheduledTaskConfig().command;
                    }
                    const nextConfig = {
                        task_name: config.task_name,
                        ensure: 'present',
                        schedule: config.schedule,
                        command: config.command,
                    };
                    if (scheduledTaskNeedsTime(config.schedule) && config.time !== '') {
                        nextConfig.time = config.time;
                    }
                    jsonInput.value = JSON.stringify(nextConfig);
                };
                const applyScheduledTemplate = function (templateKey) {
                    const template = scheduledTemplates[templateKey] || null;
                    if (!template) {
                        if (applyScheduledActionField) {
                            applyScheduledActionField.value = 'custom';
                        }
                        syncScheduledFieldVisibility(true);
                        syncJsonFromScheduledFields();
                        return;
                    }
                    if (applyScheduledTaskNameField) {
                        applyScheduledTaskNameField.value = template.task_name;
                    }
                    if (applyScheduledScheduleField) {
                        applyScheduledScheduleField.value = template.schedule;
                    }
                    if (applyScheduledTimeField) {
                        applyScheduledTimeField.value = template.time;
                    }
                    if (applyScheduledActionField) {
                        applyScheduledActionField.value = template.action;
                    }
                    if (applyScheduledDelayField) {
                        applyScheduledDelayField.value = String(template.delay_seconds);
                    }
                    if (applyScheduledForceField) {
                        applyScheduledForceField.checked = Boolean(template.force);
                    }
                    if (applyScheduledMessageField) {
                        applyScheduledMessageField.value = template.message;
                    }
                    if (applyScheduledCustomField) {
                        applyScheduledCustomField.value = '';
                    }
                    syncScheduledFieldVisibility(true);
                    syncJsonFromScheduledFields();
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
                    if (applyType === 'dns') {
                        const selector = {};
                        const interfaceAlias = (applyConfig.interface_alias || '').toString().trim();
                        const interfaceDescription = (applyConfig.interface_description || '').toString().trim();
                        const interfaceIndex = Number(applyConfig.interface_index || 0);
                        if (interfaceAlias !== '') selector.interface_alias = interfaceAlias;
                        if (interfaceDescription !== '') selector.interface_description = interfaceDescription;
                        if (Number.isFinite(interfaceIndex) && interfaceIndex > 0) selector.interface_index = Math.trunc(interfaceIndex);
                        return {
                            type: 'dns',
                            config: Object.assign(selector, { mode: 'automatic' }),
                        };
                    }
                    if (applyType === 'network_adapter') {
                        const selector = {};
                        const interfaceAlias = (applyConfig.interface_alias || '').toString().trim();
                        const interfaceDescription = (applyConfig.interface_description || '').toString().trim();
                        const interfaceIndex = Number(applyConfig.interface_index || 0);
                        if (interfaceAlias !== '') selector.interface_alias = interfaceAlias;
                        if (interfaceDescription !== '') selector.interface_description = interfaceDescription;
                        if (Number.isFinite(interfaceIndex) && interfaceIndex > 0) selector.interface_index = Math.trunc(interfaceIndex);
                        return {
                            type: 'network_adapter',
                            config: Object.assign(selector, { ipv4_mode: 'dhcp' }),
                        };
                    }
                    if (applyType === 'time') {
                        return {
                            type: 'time',
                            config: { sync_mode: 'domhier' },
                        };
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
                    const typeValue = (typeSelect.value || '').toLowerCase();
                    if (typeValue === 'dns') {
                        syncDnsFieldsFromJson();
                        syncJsonFromDnsFields();
                    }
                    if (typeValue === 'network_adapter') {
                        syncNetworkFieldsFromJson();
                        syncJsonFromNetworkFields();
                    }
                    if (typeValue === 'time') {
                        syncTimeFieldsFromJson();
                        syncJsonFromTimeFields();
                    }
                    if (typeValue === 'windows_update') {
                        syncWindowsUpdateFieldsFromJson();
                        syncJsonFromWindowsUpdateFields();
                    }
                    if (typeValue === 'uwf') {
                        syncUwfFieldsFromJson();
                        syncJsonFromUwfFields();
                    }
                    if (typeValue === 'scheduled_task') {
                        syncScheduledFieldsFromJson();
                        syncJsonFromScheduledFields();
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
                    const applyType = (typeSelect.value || '').toLowerCase();
                    const isDnsJsonMode = applyMode === 'json' && applyType === 'dns';
                    const isNetworkJsonMode = applyMode === 'json' && applyType === 'network_adapter';
                    const isTimeJsonMode = applyMode === 'json' && applyType === 'time';
                    const isWindowsUpdateJsonMode = applyMode === 'json' && applyType === 'windows_update';
                    const isUwfJsonMode = applyMode === 'json' && applyType === 'uwf';
                    const isScheduledTaskJsonMode = applyMode === 'json' && applyType === 'scheduled_task';

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
                    applyDnsFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isDnsJsonMode);
                    });
                    if (isDnsJsonMode) {
                        syncDnsFieldsFromJson();
                        if (!isCustom) {
                            syncJsonFromDnsFields();
                        }
                        syncDnsFieldVisibility(true);
                    } else {
                        syncDnsFieldVisibility(false);
                    }
                    applyNetworkFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isNetworkJsonMode);
                    });
                    if (isNetworkJsonMode) {
                        syncNetworkFieldsFromJson();
                        if (!isCustom) {
                            syncJsonFromNetworkFields();
                        }
                        syncNetworkFieldVisibility(true);
                    } else {
                        syncNetworkFieldVisibility(false);
                    }
                    applyTimeFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isTimeJsonMode);
                    });
                    if (isTimeJsonMode) {
                        syncTimeFieldsFromJson();
                        if (!isCustom) {
                            syncJsonFromTimeFields();
                        }
                        syncTimeFieldVisibility(true);
                    } else {
                        syncTimeFieldVisibility(false);
                    }
                    applyWindowsUpdateFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isWindowsUpdateJsonMode);
                    });
                    if (isWindowsUpdateJsonMode) {
                        syncWindowsUpdateFieldsFromJson();
                        if (!isCustom) {
                            syncJsonFromWindowsUpdateFields();
                        }
                        syncWindowsUpdateFieldVisibility(true);
                    } else {
                        syncWindowsUpdateFieldVisibility(false);
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
                    applyScheduledTaskFields.forEach(function (el) {
                        el.classList.toggle('hidden', !isScheduledTaskJsonMode);
                    });
                    if (isScheduledTaskJsonMode) {
                        syncScheduledFieldsFromJson();
                        if (!isCustom) {
                            syncJsonFromScheduledFields();
                        }
                        syncScheduledFieldVisibility(true);
                    } else {
                        syncScheduledFieldVisibility(false);
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
                [
                    applyTimeTimezoneField,
                    applyTimeSyncModeField,
                    applyTimeNtpServersField,
                    applyTimeResyncField,
                    applyTimeDryRunField,
                ].forEach(function (el) {
                    if (!el) return;
                    el.addEventListener('change', function () {
                        const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                        const isTimeMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'time';
                        if (isTimeMode) {
                            syncTimeFieldVisibility(true);
                            syncJsonFromTimeFields();
                        }
                    });
                });
                [
                    applyUpdateActiveStartField,
                    applyUpdateActiveEndField,
                    applyUpdateForceWindowField,
                    applyUpdateSourceField,
                    applyUpdateWsusServerField,
                    applyUpdateWsusStatusField,
                    applyUpdateTargetGroupField,
                    applyUpdateTargetEnableField,
                    applyUpdateBlockInternetField,
                    applyUpdateSameStatusField,
                ].forEach(function (el) {
                    if (!el) return;
                    const syncUpdates = function () {
                        const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                        const isUpdateMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'windows_update';
                        if (isUpdateMode) {
                            syncWindowsUpdateFieldVisibility(true);
                            syncJsonFromWindowsUpdateFields();
                        }
                    };
                    el.addEventListener('change', syncUpdates);
                    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                        el.addEventListener('input', syncUpdates);
                    }
                });
                if (applyUpdateWsusServerField) {
                    const syncWsusServer = function () {
                        const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                        const isUpdateMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'windows_update';
                        if (!isUpdateMode) {
                            return;
                        }
                        const wsusValue = String(applyUpdateWsusServerField.value || '').trim();
                        if (wsusValue !== '' && applyUpdateSourceField && applyUpdateSourceField.value !== 'wsus') {
                            applyUpdateSourceField.value = 'wsus';
                        }
                        if (applyUpdateSameStatusField && applyUpdateSameStatusField.checked && applyUpdateWsusStatusField) {
                            applyUpdateWsusStatusField.value = wsusValue;
                        }
                        syncWindowsUpdateFieldVisibility(true);
                        syncJsonFromWindowsUpdateFields();
                    };
                    applyUpdateWsusServerField.addEventListener('input', syncWsusServer);
                    applyUpdateWsusServerField.addEventListener('change', syncWsusServer);
                }
                [
                    applyDnsSelectorTypeField,
                    applyDnsInterfaceAliasField,
                    applyDnsInterfaceIndexField,
                    applyDnsInterfaceDescriptionField,
                    applyDnsModeField,
                    applyDnsPreferredServerField,
                    applyDnsAlternateServerField,
                    applyDnsAdditionalServersField,
                    applyDnsDryRunField,
                ].forEach(function (el) {
                    if (!el) return;
                    const syncDns = function () {
                        const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                        const isDnsMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'dns';
                        if (isDnsMode) {
                            syncDnsFieldVisibility(true);
                            syncJsonFromDnsFields();
                        }
                    };
                    el.addEventListener('change', syncDns);
                    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                        el.addEventListener('input', syncDns);
                    }
                });
                [
                    applyNetworkSelectorTypeField,
                    applyNetworkInterfaceAliasField,
                    applyNetworkInterfaceIndexField,
                    applyNetworkInterfaceDescriptionField,
                    applyNetworkIpv4ModeField,
                    applyNetworkAddressField,
                    applyNetworkSubnetMaskField,
                    applyNetworkGatewayField,
                    applyNetworkDryRunField,
                ].forEach(function (el) {
                    if (!el) return;
                    const syncNetwork = function () {
                        const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                        const isNetworkMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'network_adapter';
                        if (isNetworkMode) {
                            syncNetworkFieldVisibility(true);
                            syncJsonFromNetworkFields();
                        }
                    };
                    el.addEventListener('change', syncNetwork);
                    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                        el.addEventListener('input', syncNetwork);
                    }
                });
                [
                    applyScheduledTemplateField,
                    applyScheduledTaskNameField,
                    applyScheduledScheduleField,
                    applyScheduledTimeField,
                    applyScheduledActionField,
                    applyScheduledDelayField,
                    applyScheduledForceField,
                    applyScheduledMessageField,
                    applyScheduledCustomField,
                ].forEach(function (el) {
                    if (!el) return;
                    const syncScheduled = function () {
                        const applyMode = applyModeSelect ? applyModeSelect.value : 'json';
                        const isScheduledMode = applyMode === 'json' && (typeSelect.value || '').toLowerCase() === 'scheduled_task';
                        if (!isScheduledMode) {
                            return;
                        }
                        if (el === applyScheduledTemplateField) {
                            applyScheduledTemplate(applyScheduledTemplateField ? applyScheduledTemplateField.value : 'custom');
                            return;
                        }
                        syncScheduledFieldVisibility(true);
                        syncJsonFromScheduledFields();
                    };
                    el.addEventListener('change', syncScheduled);
                    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                        el.addEventListener('input', syncScheduled);
                    }
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
