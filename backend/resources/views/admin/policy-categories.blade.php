<x-admin-layout title="Policy Categories" heading="Policy Categories">
    @php
        $categoryRows = collect($categoryStats ?? []);
        $totalCategories = $categoryRows->count();
        $totalPolicies = (int) $categoryRows->sum(fn ($row) => (int) ($row['policy_count'] ?? 0));
        $totalPresets = (int) $categoryRows->sum(fn ($row) => (int) ($row['preset_count'] ?? 0));
        $inUseCount = $categoryRows->filter(fn ($row) => ((int) ($row['policy_count'] ?? 0) + (int) ($row['preset_count'] ?? 0)) > 0)->count();
    @endphp
<div class="policy-cat-shell space-y-4">
        <section class="panel rounded-3xl p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Category Governance</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">Policy Category Control</h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-600">Organize policy and catalog presets under stable category paths, then rename or retire them safely with usage visibility.</p>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4 text-xs">
                    <div class="soft-block rounded-2xl px-4 py-3">
                        <p class="text-slate-500">Categories</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $totalCategories }}</p>
                    </div>
                    <div class="soft-block rounded-2xl px-4 py-3">
                        <p class="text-slate-500">Policies</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $totalPolicies }}</p>
                    </div>
                    <div class="soft-block rounded-2xl px-4 py-3">
                        <p class="text-slate-500">Presets</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $totalPresets }}</p>
                    </div>
                    <div class="soft-block rounded-2xl px-4 py-3">
                        <p class="text-slate-500">In use</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $inUseCount }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[0.95fr,1.45fr]">
            <div class="panel rounded-3xl p-6">
                <h3 class="text-lg font-semibold text-slate-900">Add Category</h3>
                <p class="mt-1 text-xs text-slate-500">Use clear, reusable paths such as <span class="mono">security/device_control</span> or <span class="mono">operations/update_management</span>.</p>

                <form method="POST" action="{{ route('admin.policies.categories.create') }}" class="mt-5 space-y-4">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Category Path</label>
                        <input name="category" placeholder="new/category" required class="field mono text-sm" />
                    </div>
                    <div class="soft-block rounded-2xl p-4 text-sm text-slate-600">
                        Categories are used across policies and catalog presets. Keep naming stable so assignments remain easy to understand over time.
                    </div>
                    <div class="flex justify-end">
                        <button class="primary-btn rounded-xl px-4 py-2.5 text-sm font-medium">Add Category</button>
                    </div>
                </form>
            </div>

            <div class="space-y-4">
                <div class="panel rounded-3xl p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Category Directory</h3>
                            <p class="mt-1 text-xs text-slate-500">Usage counts help you decide when it is safe to rename or remove a category.</p>
                        </div>
                        <input id="policy-category-filter" type="text" placeholder="Search categories" class="field max-w-xs" />
                    </div>
                </div>

                <div class="cat-list">
                    @forelse($categoryRows as $row)
                        @php
                            $policyCount = (int) ($row['policy_count'] ?? 0);
                            $presetCount = (int) ($row['preset_count'] ?? 0);
                            $usageTotal = $policyCount + $presetCount;
                            $usageTone = $usageTotal === 0
                                ? 'bg-slate-100 text-slate-700'
                                : ($usageTotal >= 5 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
                            $name = (string) ($row['name'] ?? '');
                        @endphp
                        <article class="policy-category-card panel rounded-3xl p-5" data-filter="{{ strtolower($name) }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="mono text-sm font-semibold text-slate-900 break-all">{{ $name }}</p>
                                    <p class="mt-2 text-xs text-slate-500">Policy usage and preset dependency are shown below before rename or deletion.</p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-[11px] {{ $usageTone }}">
                                    {{ $usageTotal === 0 ? 'unused' : ($usageTotal >= 5 ? 'active' : 'light use') }}
                                </span>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <div class="soft-block rounded-2xl p-3 text-center">
                                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Policies</p>
                                    <p class="mt-1 text-xl font-semibold text-slate-900">{{ $policyCount }}</p>
                                </div>
                                <div class="soft-block rounded-2xl p-3 text-center">
                                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Presets</p>
                                    <p class="mt-1 text-xl font-semibold text-slate-900">{{ $presetCount }}</p>
                                </div>
                            </div>

                            <details class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <summary class="cursor-pointer text-sm font-medium text-slate-700">Rename category</summary>
                                <form method="POST" action="{{ route('admin.policies.categories.update') }}" class="mt-4 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="current_category" value="{{ $name }}">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">New Name</label>
                                        <input name="new_category" value="{{ $name }}" required class="field mono text-sm" />
                                    </div>
                                    <div class="flex justify-end">
                                        <button class="primary-btn rounded-xl px-4 py-2.5 text-sm font-medium">Rename</button>
                                    </div>
                                </form>
                            </details>

                            <details class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <summary class="cursor-pointer text-sm font-medium text-slate-700">Delete or replace category</summary>
                                <form method="POST" action="{{ route('admin.policies.categories.delete') }}" class="mt-4 space-y-3">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="category" value="{{ $name }}">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Replace With</label>
                                        <select name="replace_with" class="field">
                                            <option value="">Delete without replacement</option>
                                            @foreach(($policyCategories ?? []) as $opt)
                                                @if($opt !== $name)
                                                    <option value="{{ $opt }}">{{ $opt }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <p class="mt-2 text-[11px] text-slate-500">Choose a replacement if the category is currently in use by policies or presets.</p>
                                    </div>
                                    <div class="flex justify-end">
                                        <button class="rounded-xl border border-rose-300 bg-white px-4 py-2.5 text-sm font-medium text-rose-700">Delete Category</button>
                                    </div>
                                </form>
                            </details>
                        </article>
                    @empty
                        <div class="panel rounded-3xl p-6 text-sm text-slate-500">
                            No categories found.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>

    <script>
        (function () {
            const filterInput = document.getElementById('policy-category-filter');
            const cards = Array.from(document.querySelectorAll('.policy-category-card'));

            if (!filterInput || cards.length === 0) return;

            filterInput.addEventListener('input', function () {
                const q = filterInput.value.trim().toLowerCase();
                cards.forEach(function (card) {
                    const hay = card.getAttribute('data-filter') || '';
                    card.classList.toggle('hidden', q !== '' && !hay.includes(q));
                });
            });
        })();
    </script>
</x-admin-layout>
