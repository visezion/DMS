<x-admin-layout title="Enroll Devices" heading="Enroll Devices">
    @php
        $status = (string) session('status', '');
        $currentToken = '';
        if (preg_match('/Enrollment token created:\s*([A-Za-z0-9]+)/', $status, $m)) {
            $currentToken = $m[1];
        }
        if ($currentToken === '' && !empty($generated['token'])) {
            $currentToken = (string) $generated['token'];
        }
        $enrollUrl = url('/api/v1/device/enroll');
        $agentPage = route('admin.agent');
    @endphp

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-3">
            <h3 class="text-lg font-semibold">Enroll Devices</h3>
        </div>

        <div class="px-5 pt-3">
            <div class="flex flex-wrap gap-4 border-b border-slate-200 text-sm">
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 8a5 5 0 0 1 10 0v8a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V8Z"/><path d="M9 5 7 3M15 5l2-2M9 12h.01M15 12h.01"/></svg>
                    Any Android Device
                </span>
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10 5h4M11 18h2"/></svg>
                    iPad & iPhone
                </span>
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14.5 7.5c.8-1 1.2-2.2 1.1-3.5-1.2.1-2.4.7-3.2 1.7-.7.8-1.2 2-1 3.2 1.2.1 2.3-.5 3.1-1.4Z"/><path d="M18 12.5c0 3.6-2.4 7.3-4.8 7.3-1.1 0-1.8-.6-2.9-.6-1.1 0-1.9.6-3 .6-2.4 0-4.8-3.6-4.8-7.2 0-2.8 1.8-4.6 3.6-4.6 1.1 0 2 .7 3 .7.9 0 2-.8 3.3-.8 1.2 0 2.2.5 2.9 1.4-2.6 1.4-2.3 4.9.7 6.1-.3.9-.7 1.8-1 2.1"/></svg>
                    macOS
                </span>
                <span class="inline-flex items-center gap-1.5 border-b-2 border-sky-500 px-1 py-2 font-semibold text-slate-900">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 text-sky-600" fill="currentColor" aria-hidden="true"><path d="M2 4.5 11 3v8H2v-6.5Zm10 6.5V2.9l10-1.4V11H12ZM2 13h9v8l-9-1.3V13Zm10 0h10v10.5L12 22v-9Z"/></svg>
                    Windows
                </span>
                <span class="inline-flex items-center gap-1.5 px-1 py-2 text-slate-500">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v4M7.5 5l2 2M16.5 5l-2 2"/><path d="M4 10h16v3a8 8 0 0 1-16 0v-3Z"/><path d="M9 18h6"/></svg>
                    Linux
                </span>
            </div>
        </div>

        <div class="mx-5 mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs italic text-amber-900">
            Note: This flow supports Windows endpoints managed by the DMS agent.
        </div>

        <div class="grid gap-0 p-5 lg:grid-cols-12">
            <div class="lg:col-span-7 lg:pr-5">
                <div class="mb-3 flex items-center gap-2 border-b border-slate-200 text-sm">
                    <button type="button" class="rounded-t-lg border border-b-0 border-slate-300 bg-white px-3 py-2 text-slate-500">Browser Based Enrollment</button>
                    <button type="button" class="rounded-t-lg border border-b-0 border-slate-300 bg-sky-50 px-3 py-2 font-medium text-sky-700">Agent Based Enrollment</button>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50/40 p-5">
                    <p class="mb-3 font-semibold text-slate-900">Windows 10 & above, Windows 7 & 8.1</p>
                    <ol class="list-decimal space-y-2 pl-5 text-sm text-slate-700">
                        <li>Open <a href="{{ $agentPage }}" class="text-sky-700 underline">Agent Delivery</a> and prepare or activate an agent release.</li>
                        <li>Generate an enrollment token from the right panel.</li>
                        <li>Run the installer script on the target Windows device in PowerShell (Run as Administrator).</li>
                        <li>Use the Enrollment URL and Enrollment Code provided in the generated installer output.</li>
                        <li>Complete enrollment and verify device appears in <a href="{{ route('admin.devices') }}" class="text-sky-700 underline">Devices</a>.</li>
                    </ol>

                    <div class="mt-8 rounded-lg border border-slate-200 bg-white p-4">
                        <p class="font-semibold text-slate-900">Start Managing</p>
                        <p class="mt-1 text-sm text-slate-700">After enrollment, assign policies and packages from Policy Center and Application Management.</p>
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-200 pt-5 lg:col-span-5 lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0">
                <div class="space-y-4">
                    <div class="rounded-xl border border-slate-200 bg-slate-50/40 p-4">
                        <h4 class="mb-3 font-semibold text-slate-900">Generate Client Installer Link</h4>
                        @error('agent_generate')
                            <div class="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</div>
                        @enderror
                        <form method="POST" action="{{ route('admin.agent.releases.generate') }}" class="space-y-3">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">Release</label>
                                <select name="release_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    @foreach($releases as $release)
                                        <option value="{{ $release->id }}" @selected($activeRelease && $activeRelease->id === $release->id)>
                                            {{ $release->version }} ({{ $release->file_name }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">Expires (Hours)</label>
                                <input name="expires_hours" type="number" min="1" max="168" value="24" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">API Base URL</label>
                                <input name="api_base_url" value="{{ old('api_base_url', $defaultApiBase) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs uppercase text-slate-500">Public Base URL</label>
                                <input name="public_base_url" value="{{ old('public_base_url', $defaultPublicBase) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <button class="rounded-lg bg-ink px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Generate Install Script</button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </section>

    @if($generated)
        <section class="mt-5 rounded-2xl border border-skyline/30 bg-skyline/10 p-5 shadow-sm">
            <h3 class="font-semibold">Generated Installer Bundle (expires: {{ $generated['expires_at'] }})</h3>
            <div class="mt-3 space-y-3">
                <div>
                    <p class="text-xs uppercase text-slate-500">Script URL</p>
                    <textarea readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-14">{{ $generated['script_url'] }}</textarea>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">Download URL</p>
                    <textarea readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-14">{{ $generated['download_url'] }}</textarea>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">Client One-Liner</p>
                    <textarea id="client-one-liner" readonly class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono min-h-20">{{ $generated['copy_command'] }}</textarea>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" data-copy-target="client-one-liner" class="rounded bg-skyline px-3 py-1 text-xs font-medium text-white">Copy One-Liner</button>
                        <a href="{{ $generated['script_url'] }}" target="_blank" class="rounded bg-ink px-3 py-1 text-xs font-medium text-white">Open Script URL</a>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Recent Enrollment Tokens</h3>
            <p class="text-xs text-slate-500">Latest 5 generated tokens.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-slate-500">
                        <th class="py-2">Token ID</th>
                        <th class="py-2">Created</th>
                        <th class="py-2">Expires</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Used By Device</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent_tokens as $token)
                        @php
                            $isExpired = $token->expires_at && $token->expires_at->isPast();
                            $isUsed = !empty($token->used_by_device_id);
                        @endphp
                        <tr class="border-b align-top">
                            <td class="py-2 font-mono text-xs text-slate-700">{{ $token->id }}</td>
                            <td class="py-2 text-xs text-slate-600">{{ $token->created_at }}</td>
                            <td class="py-2 text-xs text-slate-600">{{ $token->expires_at }}</td>
                            <td class="py-2">
                                @if($isUsed)
                                    <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-700">Used</span>
                                @elseif($isExpired)
                                    <span class="rounded-full bg-rose-100 px-2 py-1 text-xs text-rose-700">Expired</span>
                                @else
                                    <span class="rounded-full bg-sky-100 px-2 py-1 text-xs text-sky-700">Active</span>
                                @endif
                            </td>
                            <td class="py-2 text-xs text-slate-600">{{ $token->used_by_device_id ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-4 text-sm text-slate-500">No enrollment tokens created yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        (function () {
            document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    const targetId = btn.getAttribute('data-copy-target');
                    const target = targetId ? document.getElementById(targetId) : null;
                    if (!target) return;
                    try {
                        await navigator.clipboard.writeText(target.value);
                        const prev = btn.textContent;
                        btn.textContent = 'Copied';
                        setTimeout(function () { btn.textContent = prev; }, 1200);
                    } catch (e) {
                        target.select();
                        document.execCommand('copy');
                    }
                });
            });
            document.querySelectorAll('[data-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const text = btn.getAttribute('data-copy') || '';
                    if (!text) return;
                    navigator.clipboard.writeText(text);
                    const original = btn.textContent;
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = original; }, 1000);
                });
            });
        })();
    </script>
</x-admin-layout>
