<x-admin-layout title="Catalog" heading="Policy Catalog">
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 space-y-3">
            <h3 class="font-semibold">Create Catalog Preset</h3>
            <p class="text-xs text-slate-600">Create one-click templates for policy rules.</p>
            <form method="POST" action="{{ route('admin.policies.catalog.create') }}" class="grid gap-2">
                @csrf
                <input name="label" placeholder="Label (e.g. Disable USB Write)" required class="rounded border border-slate-300 px-2 py-2"/>
                <input name="name" placeholder="Policy Name" required class="rounded border border-slate-300 px-2 py-2"/>
                <input name="slug" placeholder="policy-slug" required class="rounded border border-slate-300 px-2 py-2"/>
                <input name="category" list="policy-category-options" placeholder="security/device_control" required class="rounded border border-slate-300 px-2 py-2"/>
                <input name="description" placeholder="Short description" class="rounded border border-slate-300 px-2 py-2"/>
                <select name="applies_to" class="rounded border border-slate-300 px-2 py-2">
                    <option value="both">Applies to: both device and group</option>
                    <option value="device">Applies to: device</option>
                    <option value="group">Applies to: group</option>
                </select>
                <select name="rule_type" class="rounded border border-slate-300 px-2 py-2">
                    @foreach(['registry','firewall','bitlocker','local_group','windows_update','scheduled_task','command'] as $ruleType)
                        <option value="{{ $ruleType }}">{{ $ruleType }}</option>
                    @endforeach
                </select>
                <textarea name="rule_json" class="rounded border border-slate-300 px-2 py-2 min-h-24 font-mono text-xs" required>{"path":"HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR","name":"Start","type":"DWORD","value":4}</textarea>
                <select name="remove_mode" class="rounded border border-slate-300 px-2 py-2">
                    <option value="auto">Remove mode: auto</option>
                    <option value="json">Remove mode: json</option>
                    <option value="command">Remove mode: command</option>
                </select>
                <select name="remove_rule_type" class="rounded border border-slate-300 px-2 py-2">
                    @foreach(['registry','scheduled_task','command','firewall','bitlocker','local_group','windows_update'] as $ruleType)
                        <option value="{{ $ruleType }}">{{ $ruleType }}</option>
                    @endforeach
                </select>
                <textarea name="remove_rule_json" class="rounded border border-slate-300 px-2 py-2 min-h-24 font-mono text-xs" placeholder='{"path":"HKLM\\...","name":"NoDrives","type":"DWORD","ensure":"absent"}'></textarea>
                <textarea name="remove_command" class="rounded border border-slate-300 px-2 py-2 min-h-20 font-mono text-xs" placeholder="reg delete HKLM\\... /v NoDrives /f"></textarea>
                <button class="rounded bg-ink text-white px-3 py-2 text-sm">Create Preset</button>
            </form>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4 space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-semibold">Catalog Presets</h3>
                <p class="text-xs text-slate-500">Default + Custom</p>
            </div>
            <input id="catalog-filter" type="text" placeholder="Filter by label, category, or rule type..." class="w-full rounded border border-slate-300 px-3 py-2 text-sm" />
            <div class="space-y-2 max-h-[36rem] overflow-auto">
                @forelse(($policyCatalog ?? []) as $item)
                    @php
                        $isCustom = (($item['source'] ?? 'default') === 'custom');
                        $filterText = strtolower(trim(($item['label'] ?? '').' '.($item['category'] ?? '').' '.($item['rule_type'] ?? '').' '.($item['description'] ?? '').' '.($item['applies_to'] ?? '').' '.($item['remove_mode'] ?? '')));
                        $ruleJsonPretty = json_encode(($item['rule_json'] ?? []), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        $removeRules = is_array($item['remove_rules'] ?? null) ? $item['remove_rules'] : [];
                        $removeMode = (string) ($item['remove_mode'] ?? ($removeRules !== [] ? 'json' : 'auto'));
                        $removeFirst = is_array($removeRules[0] ?? null) ? $removeRules[0] : [];
                        $removeType = (string) ($removeFirst['type'] ?? 'registry');
                        $removeConfig = is_array($removeFirst['config'] ?? null) ? $removeFirst['config'] : [];
                        $removeJsonPretty = json_encode($removeConfig, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        $removeCommand = (string) ($removeConfig['command'] ?? '');
                    @endphp
                    <div class="catalog-item text-xs border border-slate-200 rounded p-2" data-filter="{{ $filterText }}">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <p class="font-medium">{{ $item['label'] ?? '-' }}</p>
                                <p class="text-slate-500 font-mono">{{ $item['rule_type'] ?? '-' }} | {{ $item['category'] ?? '-' }}</p>
                                <p class="text-[11px] text-slate-400">{{ $item['slug'] ?? '-' }}</p>
                                <p class="text-[11px] text-slate-600 mt-1">{{ $item['description'] ?? '-' }}</p>
                                <p class="text-[11px] text-slate-500">Applies to: {{ $item['applies_to'] ?? 'both' }}</p>
                                <p class="text-[11px] text-slate-500">Remove policy: {{ $removeMode }} {{ $removeType !== '' ? '| '.$removeType : '' }}</p>
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-[11px] text-slate-600">View remove JSON</summary>
                                    <pre class="mt-1 rounded border border-slate-200 bg-white p-2 text-[11px] font-mono overflow-auto">{{ $removeJsonPretty ?: '{}' }}</pre>
                                </details>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($isCustom)
                                    <span class="rounded-full bg-emerald-100 text-emerald-700 px-2 py-1">custom</span>
                                @else
                                    <span class="rounded-full bg-slate-100 text-slate-700 px-2 py-1">default</span>
                                @endif
                                <button
                                    type="button"
                                    onclick="const d=this.closest('.catalog-item')?.querySelector('details'); if(d){d.open=!d.open;}"
                                    class="rounded bg-slate-700 text-white px-2 py-1"
                                >
                                    Edit
                                </button>
                                @if($isCustom)
                                    <form method="POST" action="{{ route('admin.policies.catalog.delete', $item['key'] ?? '') }}" onsubmit="return confirm('Remove this catalog preset?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded bg-red-600 text-white px-2 py-1">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <details class="mt-2 rounded border border-slate-200 p-2 bg-slate-50">
                            <summary class="cursor-pointer text-[11px] font-medium text-slate-700">Edit form</summary>
                            <form method="POST" action="{{ route('admin.policies.catalog.update', $item['key'] ?? '') }}" class="mt-2 grid gap-2">
                                @csrf
                                @method('PATCH')
                                <input name="label" value="{{ $item['label'] ?? '' }}" required class="rounded border border-slate-300 px-2 py-1.5"/>
                                <input name="name" value="{{ $item['name'] ?? '' }}" required class="rounded border border-slate-300 px-2 py-1.5"/>
                                <input name="slug" value="{{ $item['slug'] ?? '' }}" required class="rounded border border-slate-300 px-2 py-1.5"/>
                                <input name="category" list="policy-category-options" value="{{ $item['category'] ?? '' }}" required class="rounded border border-slate-300 px-2 py-1.5"/>
                                <input name="description" value="{{ $item['description'] ?? '' }}" class="rounded border border-slate-300 px-2 py-1.5"/>
                                <select name="applies_to" class="rounded border border-slate-300 px-2 py-1.5">
                                    <option value="both" {{ ($item['applies_to'] ?? 'both') === 'both' ? 'selected' : '' }}>Applies to both</option>
                                    <option value="device" {{ ($item['applies_to'] ?? '') === 'device' ? 'selected' : '' }}>Applies to device</option>
                                    <option value="group" {{ ($item['applies_to'] ?? '') === 'group' ? 'selected' : '' }}>Applies to group</option>
                                </select>
                                <select name="rule_type" class="rounded border border-slate-300 px-2 py-1.5">
                                    @foreach(['registry','firewall','bitlocker','local_group','windows_update','scheduled_task','command'] as $ruleType)
                                        <option value="{{ $ruleType }}" {{ ($item['rule_type'] ?? '') === $ruleType ? 'selected' : '' }}>{{ $ruleType }}</option>
                                    @endforeach
                                </select>
                                <textarea name="rule_json" required class="rounded border border-slate-300 px-2 py-1.5 min-h-24 font-mono text-[11px]">{{ $ruleJsonPretty ?: '{}' }}</textarea>
                                <select name="remove_mode" class="rounded border border-slate-300 px-2 py-1.5">
                                    <option value="auto" {{ $removeMode === 'auto' ? 'selected' : '' }}>Remove mode: auto</option>
                                    <option value="json" {{ $removeMode === 'json' ? 'selected' : '' }}>Remove mode: json</option>
                                    <option value="command" {{ $removeMode === 'command' ? 'selected' : '' }}>Remove mode: command</option>
                                </select>
                                <select name="remove_rule_type" class="rounded border border-slate-300 px-2 py-1.5">
                                    @foreach(['registry','scheduled_task','command','firewall','bitlocker','local_group','windows_update'] as $ruleType)
                                        <option value="{{ $ruleType }}" {{ $removeType === $ruleType ? 'selected' : '' }}>{{ $ruleType }}</option>
                                    @endforeach
                                </select>
                                <textarea name="remove_rule_json" class="rounded border border-slate-300 px-2 py-1.5 min-h-24 font-mono text-[11px]">{{ $removeJsonPretty ?: '{}' }}</textarea>
                                <textarea name="remove_command" class="rounded border border-slate-300 px-2 py-1.5 min-h-20 font-mono text-[11px]">{{ $removeCommand }}</textarea>
                                <div class="flex justify-end">
                                    <button class="rounded bg-ink text-white px-2 py-1">Save Changes</button>
                                </div>
                            </form>
                        </details>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No catalog presets found.</p>
                @endforelse
            </div>
        </div>
    </div>

    <datalist id="policy-category-options">
        @foreach(($policyCategories ?? []) as $cat)
            <option value="{{ $cat }}"></option>
        @endforeach
    </datalist>

    <script>
        (function () {
            const input = document.getElementById('catalog-filter');
            if (!input) return;
            input.addEventListener('input', function () {
                const q = input.value.toLowerCase().trim();
                document.querySelectorAll('.catalog-item').forEach(function (item) {
                    const hay = (item.dataset.filter || '').toLowerCase();
                    item.style.display = q === '' || hay.includes(q) ? '' : 'none';
                });
            });
        })();
    </script>
</x-admin-layout>
