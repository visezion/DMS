<x-admin-layout title="Branding" heading="Branding">
    @php
        $branding = array_merge([
            'project_name' => 'DMS Admin',
            'project_tagline' => 'Centralized control for Windows fleet operations',
            'primary_color' => '#0EA5E9',
            'accent_color' => '#F97316',
            'background_color' => '#F1F5F9',
            'sidebar_tint' => '#FFFFFF',
            'border_radius_px' => 12,
            'logo_url' => null,
            'favicon_url' => null,
        ], is_array($branding ?? null) ? $branding : []);
    @endphp

    <div class="rounded-2xl bg-white border border-slate-200 p-4 mb-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="font-semibold">Branding Studio</h3>
                <p class="text-xs text-slate-600 mt-1">Customize project identity and UI color theme across the admin console.</p>
            </div>
            <a href="{{ route('admin.settings') }}" class="rounded bg-slate-100 text-slate-700 px-3 py-2 text-xs">Back to Settings</a>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl bg-white border border-slate-200 p-4 lg:col-span-2">
            <h3 class="font-semibold mb-3">Brand Settings</h3>
            <form method="POST" action="{{ route('admin.settings.branding.update') }}" enctype="multipart/form-data" class="grid gap-3 md:grid-cols-2">
                @csrf
                <div>
                    <label class="text-xs uppercase text-slate-500">Project Name</label>
                    <input name="project_name" value="{{ $branding['project_name'] }}" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Project Tagline</label>
                    <input name="project_tagline" value="{{ $branding['project_tagline'] }}" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Primary Color</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" value="{{ $branding['primary_color'] }}" data-target="primary_color" class="brand-color-picker h-10 w-14 rounded border border-slate-300 p-1" />
                        <input name="primary_color" value="{{ $branding['primary_color'] }}" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs uppercase" />
                    </div>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Accent Color</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" value="{{ $branding['accent_color'] }}" data-target="accent_color" class="brand-color-picker h-10 w-14 rounded border border-slate-300 p-1" />
                        <input name="accent_color" value="{{ $branding['accent_color'] }}" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs uppercase" />
                    </div>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Background Color</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" value="{{ $branding['background_color'] }}" data-target="background_color" class="brand-color-picker h-10 w-14 rounded border border-slate-300 p-1" />
                        <input name="background_color" value="{{ $branding['background_color'] }}" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs uppercase" />
                    </div>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Sidebar Tint</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" value="{{ $branding['sidebar_tint'] }}" data-target="sidebar_tint" class="brand-color-picker h-10 w-14 rounded border border-slate-300 p-1" />
                        <input name="sidebar_tint" value="{{ $branding['sidebar_tint'] }}" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs uppercase" />
                    </div>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Global Border Radius (px)</label>
                    <input type="number" min="0" max="32" name="border_radius_px" value="{{ (int) ($branding['border_radius_px'] ?? 12) }}" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
                    <p class="mt-1 text-xs text-slate-500">Applies to cards, forms, buttons, and panels across admin pages.</p>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Project Logo</label>
                    <input type="file" name="logo" accept="image/*" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-xs" />
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300">
                        Remove current logo
                    </label>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Favicon</label>
                    <input type="file" name="favicon" accept="image/*" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-xs" />
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="remove_favicon" value="1" class="rounded border-slate-300">
                        Remove current favicon
                    </label>
                </div>
                <div class="md:col-span-2 flex flex-wrap items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                        <input type="checkbox" name="reset_defaults" value="1" class="rounded border-slate-300">
                        Reset to default look
                    </label>
                    <button class="rounded bg-skyline text-white px-4 py-2 text-sm">Save Branding</button>
                </div>
            </form>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-4">
            <h3 class="font-semibold mb-3">Current Assets</h3>
            <div class="space-y-4 text-sm">
                <div>
                    <p class="text-xs uppercase text-slate-500">Logo</p>
                    @if(!empty($branding['logo_url']))
                        <img src="{{ $branding['logo_url'] }}" alt="Current logo" class="mt-2 max-h-20 rounded border border-slate-200 bg-slate-50 p-2" />
                    @else
                        <p class="mt-2 text-xs text-slate-500">No logo uploaded.</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">Favicon</p>
                    @if(!empty($branding['favicon_url']))
                        <img src="{{ $branding['favicon_url'] }}" alt="Current favicon" class="mt-2 h-10 w-10 rounded border border-slate-200 bg-slate-50 p-1" />
                    @else
                        <p class="mt-2 text-xs text-slate-500">No favicon uploaded.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            document.querySelectorAll('.brand-color-picker').forEach(function (picker) {
                picker.addEventListener('input', function () {
                    const target = picker.getAttribute('data-target');
                    if (!target) return;
                    const input = document.querySelector(`input[name="${target}"]`);
                    if (!input) return;
                    input.value = String(picker.value || '').toUpperCase();
                });
            });
        })();
    </script>
</x-admin-layout>
