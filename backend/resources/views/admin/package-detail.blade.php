<x-admin-layout title="Package Detail" heading="Package Detail">
    <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <p class="text-xs uppercase tracking-wide text-slate-500">Software Package</p>
                <h3 class="text-2xl font-semibold leading-tight">{{ $package->name }}</h3>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-mono text-slate-700">{{ $package->slug }}</span>
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{{ $package->publisher ?: 'No publisher' }}</span>
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-medium uppercase">{{ $package->package_type }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.packages') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Back to Packages</a>
                <form method="POST" action="{{ route('admin.packages.delete', $package->id) }}" onsubmit="return confirm('Delete package {{ $package->name }} and all versions/files/jobs?');">
                    @csrf
                    @method('DELETE')
                    <button class="rounded-lg bg-red-600 px-3 py-2 text-sm text-white hover:bg-red-700">Delete Package</button>
                </form>
            </div>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h4 class="text-lg font-semibold">Add Version</h4>
                <p class="text-xs text-slate-500">Create a new release artifact and detection profile.</p>
            </div>
            <form id="package-version-upload-form" method="POST" action="{{ route('admin.packages.versions.create', $package->id) }}" enctype="multipart/form-data" class="grid gap-3">
                @csrf
                @if($package->package_type === 'config_file')
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800">
                        Config file package: upload a config artifact and set target path. Deploy will copy file remotely and optionally restart a service.
                    </div>
                @endif
                @if($package->package_type === 'archive_bundle')
                    <div class="rounded-lg border border-violet-200 bg-violet-50 p-3 text-xs text-violet-800">
                        Archive bundle package: upload a ZIP archive and set install_args_json with at least {"extract_to":"C:\\path\\folder"}. Optional keys: clean_target, strip_top_level, post_install_command, keep_artifact.
                    </div>
                @endif
                <div class="grid gap-2 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Version</label>
                        <input name="version" placeholder="e.g. 8.7.0" required class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Channel</label>
                        <input name="channel" placeholder="stable" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Source URI (Optional, requires SHA256)</label>
                    <div class="flex gap-2">
                        <input id="source-uri-input" name="source_uri" placeholder="https://..." class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                        <button id="fetch-sha256-btn" type="button" class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Fetch SHA256</button>
                    </div>
                    <p id="fetch-sha256-status" class="mt-1 text-xs text-slate-500"></p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">SHA256 (Optional, required with Source URI)</label>
                    <input id="sha256-input" name="sha256" placeholder="SHA256 checksum" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Artifact File</label>
                    <input name="artifact" type="file" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Install Args JSON</label>
                    <input name="install_args_json" placeholder='{{ $package->package_type === "archive_bundle" ? "e.g. {\"extract_to\":\"C:\\\\Apps\\\\MyApp\",\"clean_target\":false,\"strip_top_level\":true,\"post_install_command\":\"powershell -File C:\\\\Apps\\\\MyApp\\\\post.ps1\"}" : "e.g. {\"silent_args\":\"/S\"}" }}' class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Uninstall Args JSON</label>
                    <input name="uninstall_args_json" placeholder='{{ $package->package_type === "archive_bundle" ? "e.g. {\"remove_path\":\"C:\\\\Apps\\\\MyApp\"} or {\"command\":\"cmd /c rmdir /s /q C:\\\\Apps\\\\MyApp\"}" : "e.g. {\"product_code\":\"{GUID}\"} or {\"command\":\"\\\"C:\\\\Program Files\\\\App\\\\uninstall.exe\\\" /S\"}" }}' class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                </div>
                @if($package->package_type === 'config_file')
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Config Target Path</label>
                        <input name="config_target_path" placeholder="e.g. C:\ProgramData\Vendor\appsettings.json" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full" required/>
                    </div>
                    <div class="grid gap-2 md:grid-cols-2">
                        <label class="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2.5 text-xs">
                            <input type="checkbox" name="backup_existing" value="1" checked>
                            Backup existing target file to .bak
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Restart Service (Optional)</label>
                            <input name="restart_service" placeholder="e.g. Spooler" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                        </div>
                    </div>
                @endif
                <div class="grid gap-2 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Detection Type</label>
                        <select name="detection_type" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full">
                            <option value="registry">registry</option>
                            <option value="file">file</option>
                            <option value="product_code">product_code</option>
                            <option value="version">version</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Detection Value</label>
                        <input name="detection_value" placeholder="Detection value" required class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                    </div>
                </div>
                <button id="package-version-upload-submit" class="rounded-lg bg-ink px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800">Save Version</button>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h4 class="text-lg font-semibold">Deployment History</h4>
                <p class="text-xs text-slate-500">Recent rollout jobs and run summary.</p>
            </div>
            <div class="max-h-96 space-y-2 overflow-auto pr-1">
                @forelse($deploymentJobs as $job)
                    @php($summary = $runSummaryByJob[$job->id] ?? null)
                    <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-xs">
                        <p class="mb-1 font-mono text-[11px] text-slate-600">{{ $job->id }}</p>
                        <p class="text-slate-700">
                            <span class="font-medium">Target:</span>
                            {{ $job->target_type === 'group'
                                ? 'Group: '.($groupNames[$job->target_id] ?? $job->target_id)
                                : 'Device: '.($deviceNames[$job->target_id] ?? $job->target_id) }}
                        </p>
                        <p class="text-slate-700"><span class="font-medium">Job:</span> {{ $job->job_type }} | <span class="font-medium">Status:</span> {{ $job->status }} | <span class="font-medium">Created:</span> {{ $job->created_at }}</p>
                        @if($summary)
                            <p class="text-slate-700"><span class="font-medium">Runs:</span> {{ $summary->total_runs }} | <span class="font-medium">Success:</span> {{ $summary->success_runs }} | <span class="font-medium">Failed:</span> {{ $summary->failed_runs }} | <span class="font-medium">Pending:</span> {{ $summary->pending_runs }}</p>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                        <p class="text-sm text-slate-500">No deployments yet.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4">
            <h4 class="text-lg font-semibold">Versions</h4>
            <p class="text-xs text-slate-500">Deploy and manage package versions.</p>
        </div>
        <div class="space-y-4">
            @forelse($versions as $version)
                @php($file = $filesByVersion[$version->id] ?? null)
                <article class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <p class="font-medium">v{{ $version->version }} <span class="text-slate-500">({{ $version->channel }})</span></p>
                            <p class="text-xs text-slate-500">Artifact: {{ $file?->file_name ?? 'none (winget/source URI)' }}</p>
                        </div>
                        <form method="POST" action="{{ route('admin.packages.versions.delete', [$package->id, $version->id]) }}" onsubmit="return confirm('Delete version {{ $version->version }} and related files/jobs?');">
                            @csrf
                            @method('DELETE')
                            <button class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs text-red-700 hover:bg-red-50">Delete Version</button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('admin.packages.versions.deploy', $version->id) }}" class="mt-3 grid gap-2 deploy-form">
                        @csrf
                        <div class="grid gap-2 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Target Scope</label>
                                <select name="target_scope" class="deploy-target-scope rounded-lg border border-slate-300 px-3 py-2.5 w-full">
                                    <option value="device">device</option>
                                    <option value="group">group</option>
                                    <option value="all">all devices</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Target Device/Group</label>
                                <select name="target_id" class="deploy-target-id rounded-lg border border-slate-300 px-3 py-2.5 w-full">
                                    <option value="" data-kind="all">Select target device/group</option>
                                    @foreach($devices as $device)
                                        <option value="{{ $device->id }}" data-kind="device">Device: {{ $device->hostname }}</option>
                                    @endforeach
                                    @foreach($groups as $group)
                                        <option value="{{ $group->id }}" data-kind="group">Group: {{ $group->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-2 md:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Priority</label>
                                <input name="priority" type="number" min="1" max="1000" value="100" placeholder="100" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Stagger Seconds</label>
                                <input name="stagger_seconds" type="number" min="0" max="3600" value="0" placeholder="0" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">Signed URL Hours</label>
                                <input name="expires_hours" type="number" min="1" max="168" value="24" placeholder="24" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600">Public Base URL</label>
                            <input name="public_base_url" placeholder="e.g. http://172.16.x.x/DMS/backend/public" class="rounded-lg border border-slate-300 px-3 py-2.5 w-full"/>
                        </div>
                        <button class="rounded-lg bg-skyline px-3 py-2.5 text-sm font-medium text-white hover:bg-sky-600">Deploy This Version</button>
                    </form>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                    <p class="text-sm text-slate-500">No versions found for this package.</p>
                </div>
            @endforelse
        </div>
    </section>

    <div id="upload-progress-modal" class="hidden fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-6">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
            <div class="flex items-start gap-3">
                <div class="h-8 w-8 animate-pulse rounded-full bg-sky-100 text-sky-700 flex items-center justify-center text-sm font-bold">i</div>
                <div class="min-w-0">
                    <p class="text-base font-semibold text-slate-900">Uploading package artifact</p>
                    <p class="mt-1 text-sm text-slate-600">Upload in progress. Keep this tab open until it completes.</p>
                </div>
            </div>
            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full w-2/3 animate-pulse rounded-full bg-skyline"></div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const uploadForm = document.getElementById('package-version-upload-form');
            const uploadModal = document.getElementById('upload-progress-modal');
            const uploadSubmit = document.getElementById('package-version-upload-submit');
            const sourceUriInput = document.getElementById('source-uri-input');
            const shaInput = document.getElementById('sha256-input');
            const fetchShaBtn = document.getElementById('fetch-sha256-btn');
            const fetchShaStatus = document.getElementById('fetch-sha256-status');
            if (uploadForm && uploadModal && uploadSubmit) {
                uploadForm.addEventListener('submit', function () {
                    uploadModal.classList.remove('hidden');
                    uploadSubmit.disabled = true;
                    uploadSubmit.classList.add('opacity-70', 'cursor-not-allowed');
                });
            }
            if (fetchShaBtn && sourceUriInput && shaInput && fetchShaStatus) {
                fetchShaBtn.addEventListener('click', async function () {
                    const sourceUri = (sourceUriInput.value || '').trim();
                    if (!sourceUri) {
                        fetchShaStatus.textContent = 'Enter Source URI first.';
                        fetchShaStatus.className = 'mt-1 text-xs text-red-600';
                        return;
                    }

                    fetchShaBtn.disabled = true;
                    fetchShaStatus.textContent = 'Fetching and hashing...';
                    fetchShaStatus.className = 'mt-1 text-xs text-slate-500';

                    try {
                        const res = await fetch(@json(route('admin.packages.hash-from-uri')), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': @json(csrf_token()),
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({ source_uri: sourceUri })
                        });

                        const data = await res.json();
                        if (!res.ok) {
                            throw new Error(data.error || 'Failed to fetch SHA256.');
                        }

                        shaInput.value = data.sha256 || '';
                        const mb = data.size_bytes ? (Number(data.size_bytes) / (1024 * 1024)).toFixed(2) : '0.00';
                        fetchShaStatus.textContent = `SHA256 loaded. Size: ${mb} MB`;
                        fetchShaStatus.className = 'mt-1 text-xs text-emerald-700';
                    } catch (error) {
                        fetchShaStatus.textContent = error instanceof Error ? error.message : 'Failed to fetch SHA256.';
                        fetchShaStatus.className = 'mt-1 text-xs text-red-600';
                    } finally {
                        fetchShaBtn.disabled = false;
                    }
                });
            }

            document.querySelectorAll('.deploy-form').forEach(function (form) {
                const scope = form.querySelector('.deploy-target-scope');
                const target = form.querySelector('.deploy-target-id');
                if (!scope || !target) return;
                const options = Array.from(target.options);

                function syncTargetState() {
                    const scopeValue = scope.value;
                    options.forEach(function (opt) {
                        const kind = opt.getAttribute('data-kind') || '';
                        const visible = kind === 'all' || kind === scopeValue;
                        opt.hidden = !visible;
                    });

                    const all = scopeValue === 'all';
                    target.disabled = all;
                    target.required = !all;
                    if (all) {
                        target.value = '';
                        return;
                    }

                    const selected = target.selectedOptions[0];
                    if (!selected || selected.hidden) {
                        const firstVisible = options.find(function (o) { return !o.hidden && o.value !== ''; });
                        target.value = firstVisible ? firstVisible.value : '';
                    }
                }

                scope.addEventListener('change', syncTargetState);
                syncTargetState();
            });
        })();
    </script>
</x-admin-layout>
