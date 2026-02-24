<x-admin-layout title="Settings" heading="Configuration">
    @php
        $customCatalog = $customCatalog ?? [];
        $signatureBypassEnabled = (bool) ($signatureBypassEnabled ?? false);
    @endphp

    <div class="rounded-2xl bg-white border border-slate-200 p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="font-semibold">Settings</h3>
            <p class="text-xs text-slate-600 mt-1">General system controls and environment options.</p>
        </div>
        <a href="{{ route('admin.settings.branding') }}" class="rounded bg-skyline text-white px-4 py-2 text-sm">Open Branding</a>
    </div>

    <div class="rounded-2xl bg-white border border-slate-200 p-4 mb-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="font-semibold">Signature Bypass (Dev Only)</h3>
                <p class="text-xs text-slate-600 mt-1">
                    Toggles <span class="font-mono">DMS_SIGNATURE_BYPASS</span> in backend environment and stores central setting.
                    Do not keep enabled in production.
                </p>
                <p class="text-xs mt-2">
                    Current: 
                    @if($signatureBypassEnabled)
                        <span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5">enabled</span>
                    @else
                        <span class="rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5">disabled</span>
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('admin.settings.signature-bypass') }}" onsubmit="return confirm('Change signature bypass mode? Use enabled only for development/testing.');" class="flex items-center gap-2">
                @csrf
                <input type="hidden" name="signature_bypass_enabled" value="0">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="signature_bypass_enabled" value="1" {{ $signatureBypassEnabled ? 'checked' : '' }} class="rounded border-slate-300">
                    Enable bypass
                </label>
                <button class="rounded bg-ink text-white px-3 py-2 text-xs">Save</button>
            </form>
        </div>
    </div>

    <div class="rounded-2xl bg-white border border-slate-200 p-4 mb-4">
        <h3 class="font-semibold mb-3">Create Enrollment Token</h3>
        <form method="POST" action="{{ route('admin.devices.enrollment-token') }}" class="space-y-3 max-w-md">
            @csrf
            <div>
                <label class="text-xs uppercase text-slate-500">Expires (hours)</label>
                <input name="expires_hours" type="number" min="1" max="720" value="24" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"/>
            </div>
            <button class="rounded-lg bg-skyline text-white px-4 py-2 text-sm">Generate Token</button>
        </form>
    </div>

    <div class="rounded-2xl bg-white border border-slate-200 p-4 mb-4">
        <h3 class="font-semibold text-slate-900">Operations Controls</h3>
        <p class="text-xs text-slate-500 mt-1">Tune runtime safety and retry behavior for command delivery.</p>
        <form method="POST" action="{{ route('admin.ops.update') }}" class="grid gap-3 md:grid-cols-2 mt-4">
            @csrf
            <label class="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                <input type="checkbox" name="kill_switch" value="1" @checked(($ops['kill_switch'] ?? false))>
                Pause all command dispatch (Kill Switch)
            </label>
            <div>
                <label class="text-xs uppercase text-slate-500">Max Retries</label>
                <input name="max_retries" type="number" min="0" max="10" value="{{ $ops['max_retries'] ?? 3 }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-2" />
            </div>
            <div>
                <label class="text-xs uppercase text-slate-500">Base Backoff (Seconds)</label>
                <input name="base_backoff_seconds" type="number" min="5" max="1800" value="{{ $ops['base_backoff_seconds'] ?? 30 }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-2" />
            </div>
            <div class="md:col-span-2">
                <label class="text-xs uppercase text-slate-500">Allowed Script SHA256 (One Per Line)</label>
                <textarea name="allowed_script_hashes" class="mt-1 w-full rounded border border-slate-300 px-2 py-2 min-h-28 font-mono text-xs">{{ implode("\n", $ops['allowed_script_hashes'] ?? []) }}</textarea>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                <input type="checkbox" name="auto_allow_run_command_hashes" value="1" @checked(($ops['auto_allow_run_command_hashes'] ?? false))>
                Auto-allow new run_command script hashes (generated from payload.script)
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                <input type="checkbox" name="delete_cleanup_before_uninstall" value="1" @checked(($ops['delete_cleanup_before_uninstall'] ?? false))>
                On device delete: remove assigned policies and uninstall packages on target before uninstalling agent
            </label>
            <div class="md:col-span-2">
                <button class="rounded bg-skyline text-white px-4 py-2 text-sm">Save Ops Settings</button>
            </div>
        </form>
    </div>

    <datalist id="policy-category-options">
        @foreach(($policyCategories ?? []) as $cat)
            <option value="{{ $cat }}"></option>
        @endforeach
    </datalist>
</x-admin-layout>
