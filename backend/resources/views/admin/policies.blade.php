<x-admin-layout title="Policies" heading="Policy Enforcement">
    <style>
        .policy-shell {
            border-color: #d7deea;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        }
        .policy-card {
            border: 1px solid #d7deea;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .policy-card:hover {
            transform: translateY(-2px);
            border-color: #93c5fd;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.12);
        }
        .policy-pill {
            border: 1px solid #d7deea;
            background: #ffffff;
        }
    </style>

    <section class="rounded-2xl bg-white border border-slate-200 p-4 space-y-4 policy-shell">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="h-4 w-4 text-sky-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2l8 4v6c0 5-3.4 9.7-8 11-4.6-1.3-8-6-8-11V6l8-4z"/>
                </svg>
                Policy Catalog
            </h3>
            <p class="text-xs text-slate-500">One-click fill, create policy, then manage created policies below.</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs text-slate-600 mb-2">One-Click Fill</p>
            <div class="flex flex-wrap gap-2">
                @foreach($policyCatalog as $catalogItem)
                    <button
                        type="button"
                        class="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs quick-catalog-btn hover:border-sky-300 hover:text-sky-700"
                        title="{{ $catalogItem['description'] ?? '' }}"
                        data-catalog='@json($catalogItem)'>{{ $catalogItem['label'] }}</button>
                @endforeach
            </div>
        </div>
        <div id="catalog-info" class="hidden rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs">
            <p class="font-semibold text-sky-900" id="catalog-info-label"></p>
            <p class="text-sky-800 mt-1" id="catalog-info-description"></p>
            <p class="text-sky-700 mt-1">Applies to: <span id="catalog-info-applies"></span></p>
            <p class="text-sky-700 mt-1">Remove policy: <span id="catalog-info-remove"></span></p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-3">
            <h3 class="font-semibold mb-3 flex items-center gap-2">
                <svg class="h-4 w-4 text-sky-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M11 5a1 1 0 112 0v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 010-2h6V5z"/>
                </svg>
                Create Policy
            </h3>
            <form method="POST" action="{{ route('admin.policies.create') }}" class="create-policy-form grid gap-2 lg:grid-cols-12">
                @csrf
                <div class="lg:col-span-4">
                    <label class="text-xs text-slate-500">Policy Name</label>
                    <input id="policy-name" name="name" placeholder="Block USB Storage" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 create-policy-name"/>
                </div>
                <div class="lg:col-span-4">
                    <label class="text-xs text-slate-500">Slug</label>
                    <input id="policy-slug" name="slug" placeholder="security-usb-storage-block" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 create-policy-slug"/>
                </div>
                <div class="lg:col-span-3">
                    <label class="text-xs text-slate-500">Category</label>
                    <select name="category" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 create-policy-category bg-white">
                        <option value="" selected disabled>Select category</option>
                        @foreach(($policyCategories ?? []) as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-1 flex items-end">
                    <button class="rounded-lg bg-skyline text-white px-4 py-2 text-sm w-full">Create</button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <label for="policy-search" class="text-xs text-slate-600">Search Created Policies</label>
            <div class="mt-1 flex items-center gap-2">
                <input id="policy-search" type="text" placeholder="Search name, slug, category" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                <span class="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-600">{{ $policies->total() }} total</span>
            </div>
        </div>

        <div id="policy-card-grid" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @forelse($policies as $policy)
                    @php
                        $versions = $versionsByPolicy[$policy->id] ?? collect();
                        $statusRaw = strtolower((string) ($policy->status ?? 'draft'));
                        $statusClass = 'bg-slate-100 text-slate-700';
                        if ($statusRaw === 'active') {
                            $statusClass = 'bg-emerald-100 text-emerald-700';
                        } elseif ($statusRaw === 'disabled') {
                            $statusClass = 'bg-amber-100 text-amber-700';
                        } elseif ($statusRaw === 'archived') {
                            $statusClass = 'bg-rose-100 text-rose-700';
                        }
                    @endphp
                    <article
                        class="policy-card rounded-xl p-4 space-y-3"
                        data-policy-search="{{ strtolower(($policy->name ?? '').' '.($policy->slug ?? '').' '.($policy->category ?? '')) }}">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h4 class="font-semibold text-slate-900 leading-tight">{{ $policy->name }}</h4>
                                <p class="font-mono text-[11px] text-slate-500 mt-1 break-all">{{ $policy->slug }}</p>
                            </div>
                            <svg class="h-5 w-5 text-sky-600 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M7 2h10a2 2 0 012 2v6h-2V4H7v16h10v-6h2v6a2 2 0 01-2 2H7a2 2 0 01-2-2V4a2 2 0 012-2zm7.59 7L13.17 7.59 17.76 3l1.41 1.41L14.59 9H22v2h-7.41l4.58 4.59L17.76 17l-4.59-4.59L14.59 11H9V9h5.59z"/>
                            </svg>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded-full px-2 py-1 {{ $statusClass }}">{{ $policy->status }}</span>
                            <span class="policy-pill rounded-full px-2 py-1 text-slate-700">{{ $policy->category }}</span>
                            <span class="policy-pill rounded-full px-2 py-1 text-slate-700">{{ $versions->count() }} versions</span>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                            <p>Updated: {{ $policy->updated_at }}</p>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <a href="{{ route('admin.policies.show', $policy->id) }}" class="rounded-lg bg-skyline text-white px-3 py-1.5 text-xs">Open</a>
                            <form method="POST" action="{{ route('admin.policies.delete', $policy->id) }}" onsubmit="return confirm('Delete policy {{ $policy->name }}? Delete versions first.');">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-xs">Delete</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="md:col-span-2 xl:col-span-3 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
                        No policies yet.
                    </div>
                @endforelse
        </div>

        <div class="mt-4">{{ $policies->links() }}</div>
    </section>

    <script>
        (function () {
            const nameInput = document.getElementById('policy-name');
            const slugInput = document.getElementById('policy-slug');
            const categoryInput = document.querySelector('.create-policy-category');
            const catalogInfo = document.getElementById('catalog-info');
            const catalogInfoLabel = document.getElementById('catalog-info-label');
            const catalogInfoDescription = document.getElementById('catalog-info-description');
            const catalogInfoApplies = document.getElementById('catalog-info-applies');
            const catalogInfoRemove = document.getElementById('catalog-info-remove');
            const policySearchInput = document.getElementById('policy-search');
            if (nameInput && slugInput) {
                nameInput.addEventListener('input', function () {
                    if (slugInput.dataset.touched === '1') return;
                    slugInput.value = nameInput.value
                        .toLowerCase()
                        .trim()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+|-+$/g, '');
                });

                slugInput.addEventListener('input', function () {
                    slugInput.dataset.touched = '1';
                });
            }

            document.querySelectorAll('.quick-catalog-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    let item = null;
                    try {
                        item = JSON.parse(btn.dataset.catalog || '{}');
                    } catch (e) {
                        item = null;
                    }
                    if (!item) {
                        return;
                    }
                    if (nameInput) nameInput.value = item.name || '';
                    if (slugInput) slugInput.value = item.slug || '';
                    if (categoryInput) {
                        const nextCategory = item.category || '';
                        if (nextCategory !== '') {
                            const hasOption = Array.from(categoryInput.options || []).some(function (opt) {
                                return opt.value === nextCategory;
                            });
                            if (!hasOption) {
                                const option = document.createElement('option');
                                option.value = nextCategory;
                                option.textContent = nextCategory;
                                categoryInput.appendChild(option);
                            }
                            categoryInput.value = nextCategory;
                        }
                    }
                    if (catalogInfo && catalogInfoLabel && catalogInfoDescription && catalogInfoApplies && catalogInfoRemove) {
                        catalogInfoLabel.textContent = item.label || 'Catalog preset';
                        catalogInfoDescription.textContent = item.description || 'No description';
                        catalogInfoApplies.textContent = item.applies_to || 'both';
                        const removeRules = Array.isArray(item.remove_rules) ? item.remove_rules : [];
                        const removeType = (removeRules[0] && removeRules[0].type) ? removeRules[0].type : '-';
                        catalogInfoRemove.textContent = `${item.remove_mode || 'auto'} | ${removeType}`;
                        catalogInfo.classList.remove('hidden');
                    }
                });
            });

            if (policySearchInput) {
                policySearchInput.addEventListener('input', function () {
                    const needle = String(policySearchInput.value || '').trim().toLowerCase();
                    document.querySelectorAll('[data-policy-search]').forEach(function (card) {
                        const hay = card.getAttribute('data-policy-search') || '';
                        card.classList.toggle('hidden', needle !== '' && !hay.includes(needle));
                    });
                });
            }
        })();
    </script>
</x-admin-layout>
