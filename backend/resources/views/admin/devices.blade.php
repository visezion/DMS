<x-admin-layout title="Devices" heading="Device Fleet">
    @php
        $pageDevices = $devices->getCollection();
        $isCurrentlyOnline = function ($device) {
            return $device->last_seen_at && $device->last_seen_at->gt(now()->subMinutes(2));
        };
        $effectiveStatus = function ($device) use ($isCurrentlyOnline) {
            if (in_array($device->status, ['pending', 'quarantined'], true)) {
                return $device->status;
            }
            return $isCurrentlyOnline($device) ? 'online' : 'offline';
        };
        $onlineCount = $pageDevices->filter(fn ($d) => $effectiveStatus($d) === 'online')->count();
        $offlineCount = $pageDevices->filter(fn ($d) => $effectiveStatus($d) === 'offline')->count();
        $quarantinedCount = $pageDevices->where('status', 'quarantined')->count();
        $pendingCount = $pageDevices->where('status', 'pending')->count();
    @endphp

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Fleet Overview</p>
                <h3 class="text-xl font-semibold">Managed Devices</h3>
                <p class="text-sm text-slate-500">Operational view with quick actions and device-level controls.</p>
            </div>
            <a href="{{ route('admin.enroll-devices') }}" class="rounded-lg bg-skyline px-4 py-2 text-sm font-medium text-white hover:bg-sky-600">Enroll New Device</a>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Total Fleet</p>
                <p class="text-xl font-semibold text-slate-900">{{ $devices->total() }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-xs text-emerald-700">Online (page)</p>
                <p class="text-xl font-semibold text-emerald-700">{{ $onlineCount }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Offline (page)</p>
                <p class="text-xl font-semibold text-slate-700">{{ $offlineCount }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                <p class="text-xs text-amber-700">Pending (page)</p>
                <p class="text-xl font-semibold text-amber-700">{{ $pendingCount }}</p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3">
                <p class="text-xs text-rose-700">Quarantined (page)</p>
                <p class="text-xl font-semibold text-rose-700">{{ $quarantinedCount }}</p>
            </div>
        </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        @error('device_delete')
            <div class="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
        @enderror
        <div class="mb-3 grid gap-2 lg:grid-cols-[auto,1fr,auto] lg:items-center">
            <h3 class="font-semibold">Device List</h3>
            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('admin.devices') }}" class="flex w-full items-center gap-2">
                    <input
                        type="text"
                        name="q"
                        value="{{ $searchQuery ?? '' }}"
                        placeholder="Search hostname, mesh ID, OS, device ID..."
                        class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs"
                    />
                    <button class="rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white">Search</button>
                </form>
                @if(!empty($searchQuery))
                    <a href="{{ route('admin.devices') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700">Clear</a>
                @endif
            </div>
            <p class="text-xs text-slate-500 lg:text-right">Page {{ $devices->currentPage() }} of {{ $devices->lastPage() }} | Showing {{ $devices->count() }} / {{ $devices->total() }}</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @forelse($devices as $device)
                @php
                    $currentStatus = $effectiveStatus($device);
                    $statusClass = match($currentStatus) {
                        'online' => 'bg-emerald-100 text-emerald-700',
                        'quarantined' => 'bg-rose-100 text-rose-700',
                        'pending' => 'bg-amber-100 text-amber-700',
                        default => 'bg-slate-100 text-slate-700',
                    };
                    $presenceClass = $currentStatus === 'online' ? 'text-emerald-600' : 'text-slate-400';
                @endphp
                <article class="rounded-xl border border-slate-200 bg-slate-50/50 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="inline-flex h-4 w-4 items-center justify-center rounded bg-sky-50 text-sky-600">
                                    <svg viewBox="0 0 24 24" class="h-3 w-3" fill="currentColor" aria-hidden="true">
                                        <path d="M2 4.5 11 3v8H2v-6.5Zm10 6.5V2.9l10-1.4V11H12ZM2 13h9v8l-9-1.3V13Zm10 0h10v10.5L12 22v-9Z"/>
                                    </svg>
                                </span>
                                <span class="h-2 w-2 rounded-full {{ $presenceClass === 'text-emerald-600' ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
                                <h4 class="truncate text-sm font-semibold text-slate-900">{{ $device->hostname }}</h4>
                            </div>
                            <p class="mt-0.5 truncate text-[11px] text-slate-500">{{ $device->os_name }} {{ $device->os_version }}</p>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusClass }}">{{ $currentStatus }}</span>
                    </div>

                    <div class="mt-2 grid grid-cols-1 gap-1.5 text-[11px]">
                        <div class="rounded-md border border-slate-200 bg-white px-2 py-1">
                            <p class="text-slate-500">Last Check-in</p>
                            <p class="font-medium text-slate-700">{{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'never' }}</p>
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <a href="{{ route('admin.devices.show', $device->id) }}" class="rounded-md bg-slate-800 px-2.5 py-1 text-[11px] font-medium text-white">Details</a>
                        <form method="POST" action="{{ route('admin.devices.reenroll', $device->id) }}" onsubmit="return confirm('Re-enroll this device? Existing identity will be revoked.');">
                            @csrf
                            <button class="rounded-md bg-amber-600 px-2.5 py-1 text-[11px] font-medium text-white">Re-enroll</button>
                        </form>
                        <form method="POST" action="{{ route('admin.devices.delete', $device->id) }}" class="device-delete-form" onsubmit="return handleDeviceDeleteSubmit(event);">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="admin_password" value="">
                            <button class="rounded-md bg-rose-600 px-2.5 py-1 text-[11px] font-medium text-white">Delete</button>
                        </form>
                    </div>

                    <details class="mt-2 rounded-md border border-slate-200 bg-white">
                        <summary class="cursor-pointer px-2 py-1.5 text-[11px] font-medium text-slate-600">Quick Edit</summary>
                        <form method="POST" action="{{ route('admin.devices.update', $device->id) }}" class="grid gap-1.5 border-t border-slate-200 px-2 py-2">
                            @csrf
                            @method('PATCH')
                            <select name="status" class="w-full rounded-md border border-slate-300 px-2 py-1 text-[11px]">
                                @foreach(['pending','online','offline','quarantined'] as $status)
                                    <option value="{{ $status }}" @selected($device->status === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                            <input name="meshcentral_device_id" placeholder="mesh id" value="{{ $device->meshcentral_device_id }}" class="w-full rounded-md border border-slate-300 px-2 py-1 text-[11px]"/>
                            <button class="rounded-md bg-ink px-2.5 py-1 text-[11px] font-medium text-white">Save</button>
                        </form>
                    </details>
                </article>
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
                    <p class="text-sm font-medium text-slate-700">No devices found</p>
                    <p class="mt-1 text-xs text-slate-500">Use Enroll Devices to onboard your first endpoint.</p>
                </div>
            @endforelse
        </div>

        <div class="mt-5">{{ $devices->links() }}</div>
    </section>

    <div id="delete-device-modal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-[1px] px-4">
        <div class="flex min-h-full items-center justify-center">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900">Delete Device</h3>
                    <p class="mt-1 text-xs text-slate-600">This action is protected and requires admin password confirmation.</p>
                </div>
                <div class="space-y-3 px-5 py-4">
                    <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                        Delete this device? Agent uninstall will be queued first, then the record is auto-removed after success.
                    </div>
                    <div>
                        <label for="delete-device-password" class="mb-1 block text-xs font-medium text-slate-600">Enter your admin password to confirm device delete:</label>
                        <input id="delete-device-password" type="password" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" autocomplete="current-password" />
                    </div>
                    <p id="delete-device-modal-error" class="hidden rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">Password is required.</p>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
                    <button id="delete-device-cancel" type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700">Cancel</button>
                    <button id="delete-device-confirm" type="button" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-medium text-white hover:bg-rose-700">Confirm Delete</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('delete-device-modal');
            const passwordInput = document.getElementById('delete-device-password');
            const errorNode = document.getElementById('delete-device-modal-error');
            const cancelBtn = document.getElementById('delete-device-cancel');
            const confirmBtn = document.getElementById('delete-device-confirm');
            let activeForm = null;

            function closeModal() {
                if (!modal) return;
                modal.classList.add('hidden');
                if (passwordInput) passwordInput.value = '';
                if (errorNode) errorNode.classList.add('hidden');
                activeForm = null;
            }

            function openModal(form) {
                activeForm = form;
                if (!modal) return;
                modal.classList.remove('hidden');
                if (passwordInput) {
                    passwordInput.value = '';
                    passwordInput.focus();
                }
                if (errorNode) errorNode.classList.add('hidden');
            }

            window.handleDeviceDeleteSubmit = function (event) {
                event.preventDefault();
                const form = event.target;
                if (!form || form.dataset.confirmedDelete === '1') {
                    return true;
                }
                openModal(form);
                return false;
            };

            cancelBtn?.addEventListener('click', closeModal);
            modal?.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });

            confirmBtn?.addEventListener('click', function () {
                if (!activeForm) return;
                const password = (passwordInput?.value || '').trim();
                if (password === '') {
                    errorNode?.classList.remove('hidden');
                    return;
                }

                const passwordField = activeForm.querySelector('input[name="admin_password"]');
                if (!passwordField) {
                    closeModal();
                    return;
                }

                passwordField.value = password;
                const formToSubmit = activeForm;
                formToSubmit.dataset.confirmedDelete = '1';
                closeModal();
                formToSubmit.submit();
            });
        })();
    </script>
</x-admin-layout>
