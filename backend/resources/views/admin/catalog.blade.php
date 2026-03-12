<x-admin-layout title="Catalog" heading="Policy Catalog">
    @php
        $catalogItems = collect($policyCatalog ?? [])->map(function ($item) {
            $isCustom = (($item['source'] ?? 'default') === 'custom');
            $ruleJsonPretty = json_encode(($item['rule_json'] ?? []), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $removeRules = is_array($item['remove_rules'] ?? null) ? $item['remove_rules'] : [];
            $removeMode = (string) ($item['remove_mode'] ?? ($removeRules !== [] ? 'json' : 'auto'));
            $removeFirst = is_array($removeRules[0] ?? null) ? $removeRules[0] : [];
            $removeType = (string) ($removeFirst['type'] ?? 'registry');
            $removeConfig = is_array($removeFirst['config'] ?? null) ? $removeFirst['config'] : [];
            $removeJsonPretty = json_encode($removeConfig, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $removeCommand = (string) ($removeConfig['command'] ?? '');
            $filterText = strtolower(trim(
                ($item['label'] ?? '').' '.
                ($item['category'] ?? '').' '.
                ($item['rule_type'] ?? '').' '.
                ($item['description'] ?? '').' '.
                ($item['applies_to'] ?? '').' '.
                $removeMode
            ));

            return [
                'item' => $item,
                'is_custom' => $isCustom,
                'rule_json_pretty' => $ruleJsonPretty ?: '{}',
                'remove_mode' => $removeMode,
                'remove_type' => $removeType,
                'remove_json_pretty' => $removeJsonPretty ?: '{}',
                'remove_command' => $removeCommand,
                'filter_text' => $filterText,
            ];
        });

        $totalCatalog = $catalogItems->count();
        $customCount = $catalogItems->where('is_custom', true)->count();
        $defaultCount = $totalCatalog - $customCount;
        $ruleTypes = $catalogItems->pluck('item.rule_type')->filter()->unique()->count();
    @endphp

    <style>
        .catalog-shell {
            --catalog-border: #d7dee8;
            --catalog-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
            --catalog-soft: #f8fafc;
        }
        .catalog-shell .panel {
            border: 1px solid var(--catalog-border);
            background: #ffffff;
            box-shadow: var(--catalog-shadow);
        }
        .catalog-shell .field {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: var(--brand-radius-xl);
            background: #ffffff;
            padding: 0.8rem 0.95rem;
            color: #0f172a;
            font-size: 0.925rem;
        }
        .catalog-shell .field:focus {
            outline: none;
            border-color: #0f172a;
            box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.08);
        }
        .catalog-shell .primary-btn {
            background: #0f172a;
            color: #ffffff;
        }
        .catalog-shell .primary-btn:hover {
            background: #1e293b;
        }
        .catalog-shell .soft-block {
            border: 1px solid var(--catalog-border);
            background: var(--catalog-soft);
        }
        .catalog-shell .mono {
            font-family: "IBM Plex Mono", monospace;
        }
        .catalog-shell .catalog-list {
            display: grid;
            gap: 1rem;
        }
    </style>

    <div class="catalog-shell space-y-4">
        <section class="panel rounded-3xl p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Policy Catalog</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">Preset Library</h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-600">Create reusable rule templates for faster policy authoring, then maintain them from one catalog workspace.</p>
                </div>
                <div class="grid gap-2 sm:grid-cols-3 text-xs">
                    <div class="soft-block rounded-2xl px-4 py-3">
                        <p class="text-slate-500">Total presets</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $totalCatalog }}</p>
                    </div>
                    <div class="soft-block rounded-2xl px-4 py-3">
                        <p class="text-slate-500">Custom</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $customCount }}</p>
                    </div>
                    <div class="soft-block rounded-2xl px-4 py-3">
                        <p class="text-slate-500">Rule types</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $ruleTypes }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-[1.05fr,1.45fr]">
            <div class="panel rounded-3xl p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Create Catalog Preset</h3>
                        <p class="mt-1 text-xs text-slate-500">Build a reusable one-click policy template with apply and remove behavior.</p>
                    </div>
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] text-slate-600">Reusable template</span>
                </div>

                <form method="POST" action="{{ route('admin.policies.catalog.create') }}" class="mt-5 space-y-4">
                    @csrf

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Label</label>
                            <input name="label" placeholder="Disable USB Write" required class="field" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Policy Name</label>
                            <input name="name" placeholder="USB Storage Lockdown" required class="field" />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Slug</label>
                            <input name="slug" placeholder="usb-storage-lockdown" required class="field" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Category</label>
                            <input name="category" list="policy-category-options" placeholder="security/device_control" required class="field" />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Description</label>
                        <input name="description" placeholder="Short note for admins using this preset" class="field" />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Applies To</label>
                            <select name="applies_to" class="field">
                                <option value="both">Device and group</option>
                                <option value="device">Device only</option>
                                <option value="group">Group only</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Rule Type</label>
                            <select id="catalog-create-rule-type" name="rule_type" class="field">
                                @foreach(['registry','firewall','dns','network_adapter','bitlocker','local_group','windows_update','scheduled_task','command','baseline_profile','reboot_restore_mode','uwf'] as $ruleType)
                                    <option value="{{ $ruleType }}">{{ $ruleType }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="soft-block rounded-2xl p-4">
                        <div class="flex items-center justify-between gap-2">
                            <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Apply Rule JSON</label>
                            <button type="button" id="catalog-fill-rule-json" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-[11px] text-slate-700">Use sample</button>
                        </div>
                        <textarea id="catalog-create-rule-json" name="rule_json" class="field mono mt-2 min-h-36 text-xs" required>{"path":"HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR","name":"Start","type":"DWORD","value":4}</textarea>
                    </div>

                    <div class="soft-block rounded-2xl p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Remove Behavior</p>
                            <span class="text-[11px] text-slate-500">What should happen when policy is removed</span>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Remove Mode</label>
                                <select name="remove_mode" class="field">
                                    <option value="auto">auto</option>
                                    <option value="json">json</option>
                                    <option value="command">command</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Remove Rule Type</label>
                                <select name="remove_rule_type" class="field">
                                    @foreach(['registry','scheduled_task','command','firewall','dns','network_adapter','bitlocker','local_group','windows_update','baseline_profile','reboot_restore_mode','uwf'] as $ruleType)
                                        <option value="{{ $ruleType }}">{{ $ruleType }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <textarea name="remove_rule_json" class="field mono min-h-28 text-xs" placeholder='{"path":"HKLM\\...","name":"NoDrives","type":"DWORD","ensure":"absent"}'></textarea>
                        <textarea name="remove_command" class="field mono min-h-24 text-xs" placeholder="reg delete HKLM\\... /v NoDrives /f"></textarea>
                    </div>

                    <div class="flex justify-end">
                        <button class="primary-btn rounded-xl px-4 py-2.5 text-sm font-medium">Create Preset</button>
                    </div>
                </form>
            </div>

            <div class="space-y-4">
                <div class="panel rounded-3xl p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Catalog Presets</h3>
                            <p class="mt-1 text-xs text-slate-500">Browse default and custom presets, search quickly, and expand any card to edit or inspect removal behavior.</p>
                        </div>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] text-slate-600">Default + custom</span>
                    </div>
                    <div class="mt-4">
                        <input id="catalog-filter" type="text" placeholder="Filter by label, category, rule type, or applies-to..." class="field" />
                    </div>
                </div>

                <div class="catalog-list">
                    @forelse($catalogItems as $entry)
                        @php
                            $item = $entry['item'];
                            $isCustom = $entry['is_custom'];
                        @endphp
                        <article class="catalog-item panel rounded-3xl p-5" data-filter="{{ $entry['filter_text'] }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-lg font-semibold text-slate-900">{{ $item['label'] ?? '-' }}</h4>
                                        <span class="rounded-full {{ $isCustom ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }} px-2.5 py-1 text-[11px]">
                                            {{ $isCustom ? 'custom' : 'default' }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-600">{{ $item['description'] ?? 'No description provided.' }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2 text-[11px]">
                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-600">{{ $item['rule_type'] ?? '-' }}</span>
                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-600">{{ $item['category'] ?? '-' }}</span>
                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-600">applies: {{ $item['applies_to'] ?? 'both' }}</span>
                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-600">remove: {{ $entry['remove_mode'] }}{{ $entry['remove_type'] !== '' ? ' / '.$entry['remove_type'] : '' }}</span>
                                    </div>
                                    <p class="mono mt-3 text-[11px] text-slate-500">{{ $item['slug'] ?? '-' }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        onclick="const d=this.closest('.catalog-item')?.querySelector('.catalog-edit-shell'); if(d){d.open=!d.open;}"
                                        class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700"
                                    >
                                        Edit
                                    </button>
                                    @if($isCustom)
                                        <form method="POST" action="{{ route('admin.policies.catalog.delete', $item['key'] ?? '') }}" onsubmit="return confirm('Remove this catalog preset?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-xl border border-rose-300 bg-white px-3 py-2 text-xs font-medium text-rose-700">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            <details class="mt-4 catalog-edit-shell rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <summary class="cursor-pointer text-sm font-medium text-slate-700">Edit preset</summary>
                                <form method="POST" action="{{ route('admin.policies.catalog.update', $item['key'] ?? '') }}" class="mt-4 space-y-4">
                                    @csrf
                                    @method('PATCH')

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <input name="label" value="{{ $item['label'] ?? '' }}" required class="field" />
                                        <input name="name" value="{{ $item['name'] ?? '' }}" required class="field" />
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <input name="slug" value="{{ $item['slug'] ?? '' }}" required class="field mono text-xs" />
                                        <input name="category" list="policy-category-options" value="{{ $item['category'] ?? '' }}" required class="field" />
                                    </div>

                                    <input name="description" value="{{ $item['description'] ?? '' }}" class="field" />

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <select name="applies_to" class="field">
                                            <option value="both" {{ ($item['applies_to'] ?? 'both') === 'both' ? 'selected' : '' }}>Applies to both</option>
                                            <option value="device" {{ ($item['applies_to'] ?? '') === 'device' ? 'selected' : '' }}>Applies to device</option>
                                            <option value="group" {{ ($item['applies_to'] ?? '') === 'group' ? 'selected' : '' }}>Applies to group</option>
                                        </select>
                                        <select name="rule_type" class="field">
                                            @foreach(['registry','firewall','dns','network_adapter','bitlocker','local_group','windows_update','scheduled_task','command','baseline_profile','reboot_restore_mode','uwf'] as $ruleType)
                                                <option value="{{ $ruleType }}" {{ ($item['rule_type'] ?? '') === $ruleType ? 'selected' : '' }}>{{ $ruleType }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <textarea name="rule_json" required class="field mono min-h-32 text-xs">{{ $entry['rule_json_pretty'] }}</textarea>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <select name="remove_mode" class="field">
                                            <option value="auto" {{ $entry['remove_mode'] === 'auto' ? 'selected' : '' }}>Remove mode: auto</option>
                                            <option value="json" {{ $entry['remove_mode'] === 'json' ? 'selected' : '' }}>Remove mode: json</option>
                                            <option value="command" {{ $entry['remove_mode'] === 'command' ? 'selected' : '' }}>Remove mode: command</option>
                                        </select>
                                        <select name="remove_rule_type" class="field">
                                            @foreach(['registry','scheduled_task','command','firewall','dns','network_adapter','bitlocker','local_group','windows_update','baseline_profile','reboot_restore_mode','uwf'] as $ruleType)
                                                <option value="{{ $ruleType }}" {{ $entry['remove_type'] === $ruleType ? 'selected' : '' }}>{{ $ruleType }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <textarea name="remove_rule_json" class="field mono min-h-28 text-xs">{{ $entry['remove_json_pretty'] }}</textarea>
                                    <textarea name="remove_command" class="field mono min-h-24 text-xs">{{ $entry['remove_command'] }}</textarea>

                                    <div class="flex justify-end">
                                        <button class="primary-btn rounded-xl px-4 py-2.5 text-sm font-medium">Save Changes</button>
                                    </div>
                                </form>
                            </details>
                        </article>
                    @empty
                        <div class="panel rounded-3xl p-6 text-sm text-slate-500">
                            No catalog presets found.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>

    <datalist id="policy-category-options">
        @foreach(($policyCategories ?? []) as $cat)
            <option value="{{ $cat }}"></option>
        @endforeach
    </datalist>

    <script>
        (function () {
            const input = document.getElementById('catalog-filter');
            const ruleType = document.getElementById('catalog-create-rule-type');
            const ruleJson = document.getElementById('catalog-create-rule-json');
            const fillBtn = document.getElementById('catalog-fill-rule-json');
            const presets = @json($rulePresetJson ?? []);

            if (input) {
                input.addEventListener('input', function () {
                    const q = input.value.toLowerCase().trim();
                    document.querySelectorAll('.catalog-item').forEach(function (item) {
                        const hay = (item.dataset.filter || '').toLowerCase();
                        item.style.display = q === '' || hay.includes(q) ? '' : 'none';
                    });
                });
            }

            function fillRuleJson() {
                if (!ruleType || !ruleJson) return;
                const selected = ruleType.value;
                const preset = presets[selected];
                if (!preset) return;
                ruleJson.value = JSON.stringify(preset, null, 2);
            }

            if (fillBtn) {
                fillBtn.addEventListener('click', fillRuleJson);
            }
        })();
    </script>
</x-admin-layout>
