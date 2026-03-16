<x-admin-layout title="Packages" heading="Software Packages">
    @php
        $packageItems = $packages->getCollection();
        $typeLabels = [
            'winget' => 'Winget',
            'msi' => 'MSI',
            'exe' => 'EXE',
            'custom' => 'Custom',
            'config_file' => 'Config File',
            'archive_bundle' => 'Archive Bundle',
        ];
        $typeCounts = $packageItems
            ->countBy(fn ($package) => strtolower((string) ($package->package_type ?: 'custom')))
            ->sortKeys();
        $publisherCount = $packageItems
            ->pluck('publisher')
            ->map(fn ($publisher) => trim((string) $publisher))
            ->filter()
            ->unique()
            ->count();
        $sevenDaysAgo = now()->subDays(7);
        $recentlyUpdatedCount = $packageItems->filter(function ($package) use ($sevenDaysAgo) {
            return $package->updated_at && $package->updated_at->greaterThanOrEqualTo($sevenDaysAgo);
        })->count();
        $deploymentCountsByPackage = collect();
        collect($deploymentJobs ?? [])->each(function ($job) use ($versionsById, $deploymentCountsByPackage) {
            $payload = is_array($job->payload) ? $job->payload : [];
            $packageVersionId = (string) ($payload['package_version_id'] ?? '');
            if ($packageVersionId === '') {
                return;
            }

            $packageId = (string) optional($versionsById->get($packageVersionId))->package_id;
            if ($packageId === '') {
                return;
            }

            $deploymentCountsByPackage->put($packageId, ((int) $deploymentCountsByPackage->get($packageId, 0)) + 1);
        });
        $shouldOpenCreateModal = $errors->any() || filled(old('name')) || filled(old('slug')) || filled(old('publisher'));
    @endphp
<div class="package-library space-y-6">
        <section class="panel-surface rounded-3xl p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-3xl">
                    <div class="flex flex-wrap gap-2 text-[10px] uppercase tracking-[0.2em] text-slate-500">
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5">Software Library</span>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5">Deployment Catalog</span>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5">{{ now()->format('D, M j') }}</span>
                    </div>
                    <h2 class="mt-2 text-xl font-semibold text-slate-900 md:text-2xl">Software package catalog</h2>
                    <p class="mt-1.5 text-sm leading-5 text-slate-600">
                        Manage your software inventory, keep package types organized, and open detailed rollout history for each package from one place.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700">
                        {{ $packages->total() }} total packages
                    </span>
                    <button type="button" id="open-package-create-modal" class="rounded-xl bg-ink px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                        Create Package
                    </button>
                </div>
            </div>

            <div class="mt-4 grid gap-2 xl:grid-cols-[minmax(0,1fr),auto] xl:items-center">
                <label class="catalog-search flex items-center gap-3 rounded-2xl px-4 py-2.5">
                    <svg viewBox="0 0 24 24" class="h-5 w-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="11" cy="11" r="6"></circle>
                        <path d="m20 20-3.5-3.5"></path>
                    </svg>
                    <input id="package-catalog-search" type="text" placeholder="Search software by name, publisher, type, or slug" class="w-full border-0 bg-transparent p-0 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" />
                </label>

                <div id="package-type-filters" class="flex flex-wrap gap-2">
                    <button type="button" data-package-filter="all" class="filter-chip is-active rounded-full px-3 py-2 text-xs font-medium">All software</button>
                    @foreach($typeCounts as $type => $count)
                        <button type="button" data-package-filter="{{ $type }}" class="filter-chip rounded-full px-3 py-2 text-xs font-medium">
                            {{ $typeLabels[$type] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $type)) }}
                            <span class="ml-1 text-[10px] opacity-70">{{ $count }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </section>
        <br>
        <section class="panel-surface rounded-3xl p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="mt-1 text-xl font-semibold text-slate-900">Software packages</h3>
                    <p class="mt-1 text-sm text-slate-500">Browse software cards, open package details, or remove packages from the catalog.</p>
                </div>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-600">
                    {{ $packages->total() }} packages in library
                </span>
            </div>

            @if($packages->count() > 0)
                <div id="package-catalog-grid" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @foreach($packages as $package)
                        @php
                            $type = strtolower((string) $package->package_type);
                            $tone = match($type) {
                                'msi' => 'bg-sky-600',
                                'exe' => 'bg-indigo-600',
                                'winget' => 'bg-emerald-600',
                                'config_file' => 'bg-amber-600',
                                'archive_bundle' => 'bg-violet-600',
                                default => 'bg-slate-600',
                            };
                            $brandIconMap = [
                                '7zip' => '7zip',
                                '7-zip' => '7zip',
                                'adobe-acrobat' => 'adobeacrobatreader',
                                'adobe-reader' => 'adobeacrobatreader',
                                'after-effects' => 'adobeaftereffects',
                                'android-studio' => 'androidstudio',
                                'anydesk' => 'anydesk',
                                'arc-browser' => 'arc',
                                'audacity' => 'audacity',
                                'autocad' => 'autodesk',
                                'battle-net' => 'battledotnet',
                                'bitwarden' => 'bitwarden',
                                'blender' => 'blender',
                                'brave' => 'brave',
                                'charles-proxy' => 'charles',
                                'chrome' => 'googlechrome',
                                'citrix' => 'citrix',
                                'clickup' => 'clickup',
                                'cloudflare-warp' => 'cloudflare',
                                'cmder' => 'cmder',
                                'dbeaver' => 'dbeaver',
                                'discord' => 'discord',
                                'docker' => 'docker',
                                'draw-io' => 'diagramsdotnet',
                                'dropbox' => 'dropbox',
                                'edge' => 'microsoftedge',
                                'evernote' => 'evernote',
                                'excel' => 'microsoftexcel',
                                'figma' => 'figma',
                                'filezilla' => 'filezilla',
                                'firefox' => 'firefoxbrowser',
                                'flutter' => 'flutter',
                                'forticlient' => 'fortinet',
                                'foxit' => 'foxit',
                                'gimp' => 'gimp',
                                'git' => 'git',
                                'github' => 'github',
                                'gitkraken' => 'gitkraken',
                                'gitlab' => 'gitlab',
                                'google-drive' => 'googledrive',
                                'grammarly' => 'grammarly',
                                'grafana' => 'grafana',
                                'heidisql' => 'mariadb',
                                'hyper-v' => 'microsoft',
                                'insomnia' => 'insomnia',
                                'intellij' => 'intellijidea',
                                'java' => 'openjdk',
                                'jetbrains' => 'jetbrains',
                                'jira' => 'jira',
                                'joplin' => 'joplin',
                                'kaspersky' => 'kaspersky',
                                'krita' => 'krita',
                                'kubernetes' => 'kubernetes',
                                'lastpass' => 'lastpass',
                                'libreoffice' => 'libreoffice',
                                'line' => 'line',
                                'microsoft-office' => 'microsoftoffice',
                                'mongodb-compass' => 'mongodb',
                                'mysql-workbench' => 'mysql',
                                'navicat' => 'mysql',
                                'nextcloud' => 'nextcloud',
                                'nodejs' => 'nodedotjs',
                                'node' => 'nodedotjs',
                                'notepad-plus-plus' => 'notepadplusplus',
                                'notion' => 'notion',
                                'nvidia' => 'nvidia',
                                'obs' => 'obsstudio',
                                'office' => 'microsoftoffice',
                                'onedrive' => 'microsoftonedrive',
                                'onenote' => 'microsoftonenote',
                                'openvpn' => 'openvpn',
                                'opera' => 'opera',
                                'oracle-vm' => 'virtualbox',
                                'outlook' => 'microsoftoutlook',
                                'paint-net' => 'dotnet',
                                'phpstorm' => 'phpstorm',
                                'postman' => 'postman',
                                'powershell' => 'powershell',
                                'premiere-pro' => 'adobepremierepro',
                                'protonvpn' => 'protonvpn',
                                'pycharm' => 'pycharm',
                                'python' => 'python',
                                'qbittorrent' => 'qbittorrent',
                                'quickbooks' => 'intuit',
                                'redis' => 'redis',
                                'rubymine' => 'rubymine',
                                'rufus' => 'rufus',
                                'sap-gui' => 'sap',
                                'signal' => 'signal',
                                'skype' => 'skype',
                                'slack' => 'slack',
                                'spotify' => 'spotify',
                                'sql-server-management-studio' => 'microsoftsqlserver',
                                'steam' => 'steam',
                                'sublime-text' => 'sublimetext',
                                'surfshark' => 'surfshark',
                                'tableau' => 'tableau',
                                'teamviewer' => 'teamviewer',
                                'telegram' => 'telegram',
                                'thunderbird' => 'thunderbird',
                                'trello' => 'trello',
                                'utorrent' => 'bittorrent',
                                'visual-studio' => 'visualstudio',
                                'visual-studio-code' => 'visualstudiocode',
                                'vlc' => 'vlcmediaplayer',
                                'vmware' => 'vmware',
                                'vscode' => 'visualstudiocode',
                                'webex' => 'webex',
                                'whatsapp' => 'whatsapp',
                                'winrar' => 'winrar',
                                'winscp' => 'winscp',
                                'wireguard' => 'wireguard',
                                'word' => 'microsoftword',
                                'wps-office' => 'wpsoffice',
                                'xampp' => 'xampp',
                                'xampp-control' => 'xampp',
                                'zoom' => 'zoom',
                            ];
                            $lookupKey = strtolower(trim((string) $package->name.' '.(string) $package->slug));
                            $lookupKey = preg_replace('/[^a-z0-9]+/', '-', $lookupKey) ?? $lookupKey;
                            $simpleIconKey = null;
                            foreach ($brandIconMap as $needle => $iconKey) {
                                if (str_contains($lookupKey, $needle)) {
                                    $simpleIconKey = $iconKey;
                                    break;
                                }
                            }
                            if (! $simpleIconKey) {
                                $autoIconKey = preg_replace('/[^a-z0-9]/', '', $lookupKey) ?? '';
                                if ($autoIconKey !== '') {
                                    $simpleIconKey = $autoIconKey;
                                }
                            }
                            $fallbackIconUrl = $simpleIconKey ? 'https://cdn.simpleicons.org/'.$simpleIconKey : null;
                            if (! $fallbackIconUrl) {
                                $publisher = trim((string) ($package->publisher ?? ''));
                                $publisherHost = preg_replace('#^https?://#', '', $publisher);
                                $publisherHost = trim((string) explode('/', (string) $publisherHost)[0]);
                                if ($publisherHost !== '' && str_contains($publisherHost, '.')) {
                                    $fallbackIconUrl = 'https://www.google.com/s2/favicons?domain='.$publisherHost.'&sz=128';
                                }
                            }
                            $iconUrl = route('admin.packages.icon.windows-store', ['name' => $package->name, 'slug' => $package->slug]);
                            $nameInitials = collect(preg_split('/\s+/', trim((string) $package->name)))
                                ->filter(fn ($part) => $part !== '')
                                ->take(2)
                                ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                                ->implode('');
                            if ($nameInitials === '') {
                                $nameInitials = strtoupper(substr((string) $package->slug, 0, 2)) ?: 'AP';
                            }
                            $typeLabel = $typeLabels[$type] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $type));
                            $packageVersions = collect($versionsByPackage->get($package->id, collect()));
                            $latestVersion = optional($packageVersions->first())->version;
                            $deploymentCount = (int) $deploymentCountsByPackage->get($package->id, 0);
                            $packageSearch = strtolower(trim(implode(' ', [
                                $package->name,
                                $package->slug,
                                $package->publisher,
                                $package->package_type,
                            ])));
                        @endphp
                        <article class="software-card rounded-2xl p-4" data-package-card data-package-type="{{ $type }}" data-package-search="{{ $packageSearch }}">
                            <div class="flex items-start gap-3">
                                <div class="relative h-14 w-14 shrink-0">
                                    <img
                                        src="{{ $iconUrl }}"
                                        alt="{{ $package->name }} icon"
                                        class="h-14 w-14 rounded-xl border border-slate-200 bg-white p-2 object-contain shadow-sm"
                                        loading="lazy"
                                        data-fallback-icon="{{ $fallbackIconUrl ?? '' }}"
                                        onerror="if(!this.dataset.fallbackTried && this.dataset.fallbackIcon){ this.dataset.fallbackTried='1'; this.src=this.dataset.fallbackIcon; return; } this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');"
                                    />
                                    <div class="flex hidden h-14 w-14 items-center justify-center rounded-xl {{ $tone }} text-sm font-bold uppercase text-white shadow-sm">
                                        {{ $nameInitials }}
                                    </div>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h4 class="truncate text-[15px] font-semibold text-slate-900" title="{{ $package->name }}">{{ $package->name }}</h4>
                                            <p class="mt-1 truncate text-xs text-slate-500" title="{{ $package->publisher ?: 'Unknown publisher' }}">
                                                {{ $package->publisher ?: 'Unknown publisher' }}
                                            </p>
                                        </div>
                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-medium uppercase tracking-wide text-slate-700">
                                            {{ $typeLabel }}
                                        </span>
                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-500">
                                        <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 font-mono text-slate-600">{{ $package->slug }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                <div class="meta-box rounded-xl p-3 text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-slate-500">Versions</p>
                                    <p class="mt-1 text-lg font-semibold leading-none text-slate-900">{{ $packageVersions->count() }}</p>
                                </div>
                                <div class="meta-box rounded-xl p-3 text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-slate-500">Deployments</p>
                                    <p class="mt-1 text-lg font-semibold leading-none text-slate-900">{{ $deploymentCount }}</p>
                                </div>
                                <div class="meta-box rounded-xl p-3 text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-slate-500">Latest</p>
                                    <p class="mt-1 text-sm font-semibold leading-none text-slate-900">{{ $latestVersion ?: 'None' }}</p>
                                </div>
                            </div>

                            <div class="meta-box rounded-xl px-3 py-2.5">
                                <p class="text-[10px] uppercase tracking-wide text-slate-500">Last Updated</p>
                                <p class="mt-1 text-xs font-medium text-slate-700">{{ $package->updated_at }}</p>
                            </div>

                            <div class="mt-auto flex items-center gap-2">
                                <a href="{{ route('admin.packages.show', $package->id) }}" class="inline-flex flex-1 items-center justify-center rounded-xl bg-ink px-3 py-2.5 text-xs font-medium text-white hover:bg-slate-800">
                                    Open Package
                                </a>
                                <form class="flex-1" method="POST" action="{{ route('admin.packages.delete', $package->id) }}" onsubmit="return confirm('Delete package {{ $package->name }} and all versions/files/jobs?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="w-full rounded-xl bg-red-600 px-3 py-2.5 text-xs font-medium text-white hover:bg-red-700">Delete</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div id="packages-filter-empty" class="hidden rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
                    <p class="text-sm font-medium text-slate-700">No software matches the current filter</p>
                    <p class="mt-1 text-xs text-slate-500">Change the search text or select another package type.</p>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
                    <p class="text-sm font-medium text-slate-700">No software packages yet</p>
                    <p class="mt-1 text-xs text-slate-500">Create your first software package from the modal to start building the catalog.</p>
                    <button type="button" data-open-package-modal class="mt-5 rounded-xl bg-ink px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800">
                        Create Package
                    </button>
                </div>
            @endif

            <div class="mt-4">{{ $packages->links() }}</div>
        </section>
    </div>

    <div id="create-package-modal" class="{{ $shouldOpenCreateModal ? '' : 'hidden' }} fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
        <div class="modal-backdrop absolute inset-0" data-package-modal-close></div>

        <div class="modal-panel relative w-full max-w-2xl rounded-3xl p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Create Software Package</p>
                    <h3 class="mt-1 text-2xl font-semibold text-slate-900">Add a new package to the catalog</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Create the package shell first, then open it to add versions, detection rules, and deployment artifacts.
                    </p>
                </div>
                <button type="button" data-package-modal-close class="rounded-full border border-slate-200 bg-white p-2 text-slate-500 hover:text-slate-900" aria-label="Close create package modal">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18"></path>
                    </svg>
                </button>
            </div>

            @if($errors->any())
                <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <p class="font-medium">Package could not be created.</p>
                    <ul class="mt-2 space-y-1 text-xs">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.packages.create') }}" class="mt-5 space-y-4" id="create-package-form">
                @csrf

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="pkg-name" class="text-xs font-medium text-slate-600">Package Name</label>
                        <input id="pkg-name" name="name" value="{{ old('name') }}" placeholder="Notepad++" required class="form-field mt-1" />
                        @error('name')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="pkg-slug" class="text-xs font-medium text-slate-600">Slug</label>
                        <input id="pkg-slug" name="slug" value="{{ old('slug') }}" placeholder="notepad-plus-plus" required class="form-field mt-1" />
                        @error('slug')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="pkg-publisher" class="text-xs font-medium text-slate-600">Publisher</label>
                        <input id="pkg-publisher" name="publisher" value="{{ old('publisher') }}" placeholder="Publisher" class="form-field mt-1" />
                        @error('publisher')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label for="pkg-type" class="text-xs font-medium text-slate-600">Package Type</label>
                        <select id="pkg-type" name="package_type" class="form-field mt-1">
                            @foreach($typeLabels as $typeValue => $label)
                                <option value="{{ $typeValue }}" @selected(old('package_type', 'winget') === $typeValue)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('package_type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                    <button type="button" data-package-modal-close class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button class="rounded-xl bg-ink px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800">
                        Create Package
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const nameInput = document.getElementById('pkg-name');
            const slugInput = document.getElementById('pkg-slug');
            const modal = document.getElementById('create-package-modal');
            const openButtons = Array.from(document.querySelectorAll('#open-package-create-modal, [data-open-package-modal]'));
            const closeButtons = Array.from(document.querySelectorAll('[data-package-modal-close]'));
            const searchInput = document.getElementById('package-catalog-search');
            const filterButtons = Array.from(document.querySelectorAll('[data-package-filter]'));
            const cards = Array.from(document.querySelectorAll('[data-package-card]'));
            const emptyState = document.getElementById('packages-filter-empty');
            let activeType = 'all';

            function normalizeText(value) {
                return (value || '')
                    .toString()
                    .toLowerCase()
                    .normalize('NFKD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9]+/g, ' ')
                    .trim();
            }

            const indexedCards = cards.map(function (card) {
                return {
                    node: card,
                    type: normalizeText(card.dataset.packageType || ''),
                    search: normalizeText(card.dataset.packageSearch || card.textContent || ''),
                };
            });

            function setModalState(open) {
                if (!modal) {
                    return;
                }

                modal.classList.toggle('hidden', !open);
                document.body.style.overflow = open ? 'hidden' : '';
            }

            function applyFilters() {
                const query = normalizeText(searchInput ? searchInput.value : '');
                let visible = 0;

                indexedCards.forEach(function (entry) {
                    const matchesType = activeType === 'all' || entry.type === activeType;
                    const matchesQuery = query === '' || entry.search.includes(query);
                    const show = matchesType && matchesQuery;

                    entry.node.classList.toggle('hidden', !show);
                    if (show) {
                        visible += 1;
                    }
                });

                if (emptyState) {
                    emptyState.classList.toggle('hidden', visible !== 0);
                }
            }

            if (nameInput && slugInput) {
                nameInput.addEventListener('input', function () {
                    if (slugInput.dataset.touched === '1') {
                        return;
                    }

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

            openButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setModalState(true);
                });
            });

            closeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setModalState(false);
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setModalState(false);
                }
            });

            if (searchInput) {
                searchInput.addEventListener('input', applyFilters);
                searchInput.addEventListener('search', applyFilters);
                searchInput.addEventListener('change', applyFilters);
                searchInput.addEventListener('keyup', applyFilters);
            }

            filterButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    activeType = (button.dataset.packageFilter || 'all').toLowerCase();
                    filterButtons.forEach(function (item) {
                        item.classList.toggle('is-active', item === button);
                    });
                    applyFilters();
                });
            });

            applyFilters();
            setModalState(@json($shouldOpenCreateModal));
        })();
    </script>
</x-admin-layout>
