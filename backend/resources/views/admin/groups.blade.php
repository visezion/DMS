<x-admin-layout title="Groups" heading="Device Groups">
    @php
        $memberCounts = $memberCounts ?? collect();
        $policyCounts = $policyCounts ?? collect();
        $packageCounts = $packageCounts ?? collect();

        $groupRows = collect($groups->items())->map(function ($group) use ($memberCounts, $policyCounts, $packageCounts) {
            return [
                'model' => $group,
                'members' => (int) ($memberCounts[$group->id] ?? 0),
                'policies' => (int) ($policyCounts[$group->id] ?? 0),
                'packages' => (int) ($packageCounts[$group->id] ?? 0),
            ];
        });
    @endphp
<div class="groups-simple space-y-4">
        <section class="panel rounded-3xl p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Groups</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900">Manage Device Groups</h2>
                    <p class="mt-1 text-sm text-slate-600">Simple grid view for existing groups. Open a group to manage members, policies, and packages.</p>
                </div>
                <a href="{{ route('admin.groups.create-page') }}" class="primary-btn rounded-xl px-4 py-2.5 text-sm font-medium">Create Group</a>
            </div>

            <div class="mt-4">
                <input id="groups-search" type="text" placeholder="Search groups by name or description" class="field" />
            </div>
        </section>

        <section id="groups-grid" class="grid-cards">
            @forelse($groupRows as $entry)
                @php
                    $group = $entry['model'];
                    $members = $entry['members'];
                    $policies = $entry['policies'];
                    $packages = $entry['packages'];
                    $description = \Illuminate\Support\Str::limit($group->description ?: 'No description provided.', 52);
                    $searchText = strtolower(trim($group->name.' '.($group->description ?? '')));
                @endphp

                <article class="group-card rounded-2xl p-4" data-group-card data-search="{{ $searchText }}">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-slate-900">{{ $group->name }}</h3>
                        <p class="mt-1 max-w-2xl text-[1px] leading-5 text-slate-400">{{ $description }}</p>
                    </div>

                    <div class="metric-grid">
                        <div class="metric-box rounded-xl p-3">
                            <div class="metric-stack">
                                <span class="metric-icon metric-members rounded-lg" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9.5" cy="7" r="3"></circle>
                                        <path d="M20 21v-2a4 4 0 0 0-3-3.87"></path>
                                        <path d="M15 4.13a3 3 0 0 1 0 5.74"></path>
                                    </svg>
                                </span>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Members</p>
                                    <p class="mt-1 text-xl font-semibold leading-none text-slate-900">{{ $members }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="metric-box rounded-xl p-3">
                            <div class="metric-stack">
                                <span class="metric-icon metric-policies rounded-lg" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7l7-4z"></path>
                                        <path d="m9.5 12 1.8 1.8 3.7-4"></path>
                                    </svg>
                                </span>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Policies</p>
                                    <p class="mt-1 text-xl font-semibold leading-none text-slate-900">{{ $policies }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="metric-box rounded-xl p-3">
                            <div class="metric-stack">
                                <span class="metric-icon metric-packages rounded-lg" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 3 4 7l8 4 8-4-8-4z"></path>
                                        <path d="M4 7v10l8 4 8-4V7"></path>
                                        <path d="M12 11v10"></path>
                                    </svg>
                                </span>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Packages</p>
                                    <p class="mt-1 text-xl font-semibold leading-none text-slate-900">{{ $packages }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="inline-flex items-center gap-1.5 text-[11px] text-slate-500">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="8"></circle>
                            <path d="M12 8v4l2.5 2.5"></path>
                        </svg>
                        Updated {{ $group->updated_at }}
                    </p>

                    <div class="group-actions">
                        <a href="{{ route('admin.groups.show', $group->id) }}" class="primary-btn rounded-xl px-3 py-2 text-xs font-medium">Open Group</a>
                        <form method="POST" action="{{ route('admin.groups.delete', $group->id) }}" onsubmit="return confirm('Delete group {{ $group->name }}? Members will be detached and cleanup jobs will be queued.');">
                            @csrf
                            @method('DELETE')
                            <button class="destructive-btn rounded-xl px-3 py-2 text-xs font-medium">Delete</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty-state-shell rounded-3xl p-8 md:p-10">
                    <div class="mx-auto max-w-2xl text-center">
                        <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-slate-700 shadow-sm">
                            <svg viewBox="0 0 24 24" class="h-9 w-9" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <rect x="4" y="5" width="16" height="14" rx="3"></rect>
                                <path d="M8 10h8M8 14h5"></path>
                            </svg>
                        </div>
                        <p class="mt-5 text-[11px] uppercase tracking-[0.24em] text-slate-500">Group Workspace Ready</p>
                        <h3 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">No groups found</h3>
                        <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-600">
                            Your group catalog is empty. Create a structured rollout ring, department cohort, or policy target set to start organizing devices at scale.
                        </p>
                        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                            <a href="{{ route('admin.groups.create-page') }}" class="primary-btn rounded-xl px-4 py-2.5 text-sm font-medium">Create First Group</a>
                            <span class="empty-badge rounded-full px-3 py-2 text-xs text-slate-600">Tip: keep names stable and operational</span>
                        </div>
                    </div>
                </div>
            @endforelse
        </section>

        <div id="groups-empty-state" class="empty-state-shell hidden rounded-3xl p-8">
            <div class="text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-slate-700 shadow-sm">
                    <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <circle cx="11" cy="11" r="6"></circle>
                        <path d="m20 20-4.2-4.2"></path>
                    </svg>
                </div>
                <h3 class="mt-4 text-xl font-semibold text-slate-900">No groups match your search</h3>
                <p class="mt-2 text-sm text-slate-600">Try a different keyword or clear the search box to see the full group catalog again.</p>
            </div>
        </div>

        <div class="panel rounded-3xl p-4">
            {{ $groups->links() }}
        </div>
    </div>

    <script>
        (function () {
            const searchInput = document.getElementById('groups-search');
            const cards = Array.from(document.querySelectorAll('[data-group-card]'));
            const emptyState = document.getElementById('groups-empty-state');

            function applySearch() {
                const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
                let visible = 0;

                cards.forEach(function (card) {
                    const haystack = card.getAttribute('data-search') || '';
                    const show = q === '' || haystack.includes(q);
                    card.classList.toggle('hidden', !show);
                    if (show) visible++;
                });

                if (emptyState) {
                    emptyState.classList.toggle('hidden', visible !== 0);
                }
            }

            if (searchInput) {
                searchInput.addEventListener('input', applySearch);
            }
        })();
    </script>
</x-admin-layout>
