<x-admin-layout title="Packages" heading="Software Packages">
    <div class="grid gap-4 xl:grid-cols-4">
        <aside class="space-y-4 xl:col-span-1">
            <div class="rounded-2xl bg-white border border-slate-200 p-4">
                <h3 class="font-semibold mb-3">Create Package</h3>
                <form method="POST" action="{{ route('admin.packages.create') }}" class="space-y-3" id="create-package-form">
                    @csrf
                    <div>
                        <label class="text-xs text-slate-500">Package Name</label>
                        <input id="pkg-name" name="name" placeholder="Notepad++" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"/>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Slug</label>
                        <input id="pkg-slug" name="slug" placeholder="notepad-plus-plus" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"/>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Publisher</label>
                        <input name="publisher" placeholder="Publisher" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"/>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Type</label>
                        <select name="package_type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="winget">winget</option>
                            <option value="msi">msi</option>
                            <option value="exe">exe</option>
                            <option value="custom">custom</option>
                            <option value="config_file">config_file</option>
                        </select>
                    </div>
                    <button class="rounded-lg bg-skyline text-white px-4 py-2 text-sm w-full">Create Package</button>
                </form>
            </div>
        </aside>

        <section class="rounded-2xl bg-white border border-slate-200 p-4 xl:col-span-3">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold">Package Catalog</h3>
                    <p class="text-xs text-slate-500">Clean app-style layout for browsing and managing packages.</p>
                </div>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-600">
                    {{ $packages->total() }} apps
                </span>
            </div>

            @if($packages->count() > 0)
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($packages as $package)
                        @php
                            $type = strtolower((string) $package->package_type);
                            $tone = match($type) {
                                'msi' => 'bg-sky-600',
                                'exe' => 'bg-indigo-600',
                                'winget' => 'bg-emerald-600',
                                'config_file' => 'bg-amber-600',
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
                        @endphp
                        <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg">
                            <div class="h-1 w-full {{ $tone }}"></div>
                            <div class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex items-center gap-3">
                                        <div class="relative h-12 w-12 shrink-0">
                                            <img
                                                src="{{ $iconUrl }}"
                                                alt="{{ $package->name }} icon"
                                                class="h-12 w-12 rounded-2xl border border-slate-200 bg-white p-2 object-contain shadow-sm"
                                                loading="lazy"
                                                data-fallback-icon="{{ $fallbackIconUrl ?? '' }}"
                                                onerror="if(!this.dataset.fallbackTried && this.dataset.fallbackIcon){ this.dataset.fallbackTried='1'; this.src=this.dataset.fallbackIcon; return; } this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');"
                                            />
                                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl {{ $tone }} text-sm font-bold uppercase text-white shadow-sm hidden">
                                                {{ $nameInitials }}
                                            </div>
                                            <div class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-md border border-slate-200 bg-white shadow-sm" title="Windows package">
                                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 text-sky-600" fill="currentColor" aria-hidden="true">
                                                    <path d="M2 4.5 11 3v8H2v-6.5Zm10 6.5V2.9l10-1.4V11H12ZM2 13h9v8l-9-1.3V13Zm10 0h10v10.5L12 22v-9Z"/>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <h4 class="truncate text-[15px] font-semibold leading-tight" title="{{ $package->name }}">{{ $package->name }}</h4>
                                            <p class="mt-1 truncate text-xs text-slate-500" title="{{ $package->publisher ?: 'Unknown publisher' }}">
                                                {{ $package->publisher ?: 'Unknown publisher' }}
                                            </p>
                                        </div>
                                    </div>
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-medium uppercase text-slate-700">
                                        {{ $package->package_type }}
                                    </span>
                                </div>

                                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Last Updated</p>
                                    <p class="text-xs font-medium text-slate-700">{{ $package->updated_at }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 border-t border-slate-200 bg-slate-50/60 px-4 py-3">
                                <a href="{{ route('admin.packages.show', $package->id) }}" class="inline-flex flex-1 items-center justify-center rounded-lg bg-ink px-3 py-2 text-xs font-medium text-white">
                                    Open
                                </a>
                                <form class="flex-1" method="POST" action="{{ route('admin.packages.delete', $package->id) }}" onsubmit="return confirm('Delete package {{ $package->name }} and all versions/files/jobs?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-medium text-red-700">Delete</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                    <p class="text-sm font-medium text-slate-700">No packages yet</p>
                    <p class="mt-1 text-xs text-slate-500">Create your first package from the panel on the left.</p>
                </div>
            @endif

            <div class="mt-4">{{ $packages->links() }}</div>
        </section>
    </div>

    <script>
        (function () {
            const nameInput = document.getElementById('pkg-name');
            const slugInput = document.getElementById('pkg-slug');
            if (!nameInput || !slugInput) {
                return;
            }

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
        })();
    </script>
</x-admin-layout>
