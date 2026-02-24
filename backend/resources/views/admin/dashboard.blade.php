<x-admin-layout title="Overview" heading="Operations Overview">
    @php
        $onlineRate = ($metrics['devices_total'] ?? 0) > 0
            ? round((($metrics['devices_online'] ?? 0) / $metrics['devices_total']) * 100, 1)
            : 0;
        $complianceRate = $metrics['compliance_rate'] ?? 0;
        $jobSuccessRate = $metrics['job_success_rate'] ?? 0;
        $deviceTotal = max(1, (int) ($metrics['devices_total'] ?? 0));
        $pendingRate = round((((int) ($metrics['jobs_pending'] ?? 0)) / $deviceTotal) * 100, 1);
        $failureRate = round((((int) ($metrics['jobs_failed'] ?? 0)) / $deviceTotal) * 100, 1);
        $riskScore = max(0, min(100,
            (100 - (float) $onlineRate) * 0.35
            + (100 - (float) $complianceRate) * 0.35
            + min(100, (float) $failureRate * 4) * 0.30
        ));
        $riskTone = $riskScore >= 60 ? 'text-rose-700 bg-rose-50 border-rose-200' : ($riskScore >= 35 ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');
        $opsPressure = min(100, round(($pendingRate * 0.55) + (((int) ($metrics['retrying_runs'] ?? 0) / max(1, (int) ($metrics['jobs_pending'] ?? 1))) * 45), 1));
        $opsPressureTone = $opsPressure >= 70 ? 'text-rose-700' : ($opsPressure >= 40 ? 'text-amber-700' : 'text-emerald-700');
        $policiesPer100 = round((((int) ($metrics['policies_total'] ?? 0)) / $deviceTotal) * 100, 1);
        $packagesPer100 = round((((int) ($metrics['packages_total'] ?? 0)) / $deviceTotal) * 100, 1);
    @endphp

    <style>
        .dash-panel {
            border-color: #d7deea;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .dash-card {
            border: 1px solid #d7deea;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        .dash-meter {
            height: 10px;
            border-radius: 9999px;
            background: #e2e8f0;
            overflow: hidden;
        }
    </style>

    <div class="rounded-2xl border p-5 dash-panel bg-gradient-to-br from-slate-50 via-white to-slate-100">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Operations Command Center</p>
                <h3 class="text-2xl font-semibold text-slate-900">Fleet Runtime Overview</h3>
                <p class="text-sm text-slate-600 mt-1">Live health, deployment reliability, and control-plane safety in one view.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full border border-slate-300 bg-white px-3 py-1 text-slate-700">Devices: {{ $metrics['devices_total'] }}</span>
                <span class="rounded-full border border-slate-300 bg-white px-3 py-1 text-slate-700">Online: {{ $metrics['devices_online'] }}</span>
                <span class="rounded-full border border-slate-300 bg-white px-3 py-1 {{ $ops['kill_switch'] ? 'text-rose-700 bg-rose-50 border-rose-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200' }}">
                    Kill Switch: {{ $ops['kill_switch'] ? 'Enabled' : 'Disabled' }}
                </span>
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Devices Total</p>
            <p class="mt-1 text-3xl font-bold text-slate-900">{{ $metrics['devices_total'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Enrolled: {{ $metrics['devices_enrolled'] }}</p>
        </article>
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Jobs Pending</p>
            <p class="mt-1 text-3xl font-bold text-sky-600">{{ $metrics['jobs_pending'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Retry queue: {{ $metrics['retrying_runs'] }}</p>
        </article>
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Jobs Failed</p>
            <p class="mt-1 text-3xl font-bold text-rose-600">{{ $metrics['jobs_failed'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Replay rejects: {{ $metrics['replay_rejects'] }}</p>
        </article>
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Audit Events</p>
            <p class="mt-1 text-3xl font-bold text-slate-900">{{ $metrics['audit_events'] }}</p>
            <p class="mt-1 text-xs text-slate-500">All-time control-plane events</p>
        </article>
    </div>

    <div class="grid gap-3 lg:grid-cols-4">
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Fleet Risk Index</p>
            <div class="mt-1 flex items-end justify-between">
                <p class="text-3xl font-bold text-slate-900">{{ number_format($riskScore, 1) }}</p>
                <span class="rounded-full border px-2 py-1 text-[11px] {{ $riskTone }}">{{ $riskScore >= 60 ? 'High' : ($riskScore >= 35 ? 'Elevated' : 'Stable') }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Composite of availability, compliance, and failures.</p>
        </article>
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Dispatch Pressure</p>
            <p class="mt-1 text-3xl font-bold {{ $opsPressureTone }}">{{ $opsPressure }}%</p>
            <p class="mt-1 text-xs text-slate-500">Pending load + retry backlog intensity.</p>
        </article>
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Policy Density</p>
            <p class="mt-1 text-3xl font-bold text-slate-900">{{ $policiesPer100 }}</p>
            <p class="mt-1 text-xs text-slate-500">Policies per 100 managed devices.</p>
        </article>
        <article class="dash-card rounded-xl p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Package Density</p>
            <p class="mt-1 text-3xl font-bold text-slate-900">{{ $packagesPer100 }}</p>
            <p class="mt-1 text-xs text-slate-500">Packages per 100 managed devices.</p>
        </article>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="rounded-2xl border bg-white p-4 dash-panel xl:col-span-2">
            <h3 class="font-semibold text-slate-900">Health Scoreboard</h3>
            <p class="text-xs text-slate-500 mt-1">Key ratios for fleet availability, compliance, and execution quality.</p>
            <div class="mt-4 space-y-4">
                <div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-700">Online Device Rate</span>
                        <span class="font-semibold text-slate-900">{{ $onlineRate }}%</span>
                    </div>
                    <div class="dash-meter mt-1"><div class="h-full bg-emerald-500" style="width: {{ max(0, min(100, $onlineRate)) }}%"></div></div>
                </div>
                <div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-700">Compliance Rate</span>
                        <span class="font-semibold text-slate-900">{{ $metrics['compliance_rate'] !== null ? $metrics['compliance_rate'].'%' : 'N/A' }}</span>
                    </div>
                    <div class="dash-meter mt-1"><div class="h-full bg-sky-500" style="width: {{ max(0, min(100, (float) $complianceRate)) }}%"></div></div>
                </div>
                <div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-700">Job Success Rate</span>
                        <span class="font-semibold text-slate-900">{{ $metrics['job_success_rate'] !== null ? $metrics['job_success_rate'].'%' : 'N/A' }}</span>
                    </div>
                    <div class="dash-meter mt-1"><div class="h-full bg-indigo-500" style="width: {{ max(0, min(100, (float) $jobSuccessRate)) }}%"></div></div>
                </div>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase text-slate-500">Policies</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $metrics['policies_total'] }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase text-slate-500">Packages</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $metrics['packages_total'] }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase text-slate-500">Non-compliant</p>
                    <p class="text-lg font-semibold text-amber-700">{{ $metrics['compliance_non_compliant'] }}</p>
                </div>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase text-slate-500">Pending / Device</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $pendingRate }}%</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase text-slate-500">Failed / Device</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $failureRate }}%</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[11px] uppercase text-slate-500">Retry Ratio</p>
                    <p class="text-lg font-semibold text-slate-900">
                        {{ (int) ($metrics['jobs_pending'] ?? 0) > 0 ? round(((int) ($metrics['retrying_runs'] ?? 0) / max(1, (int) ($metrics['jobs_pending'] ?? 1))) * 100, 1).'%' : '0%' }}
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border bg-white p-4 dash-panel">
            <h3 class="font-semibold text-slate-900">Security & Control</h3>
            <div class="mt-3 space-y-3 text-sm">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs uppercase text-slate-500">Kill Switch</p>
                    <p class="font-semibold {{ $ops['kill_switch'] ? 'text-rose-700' : 'text-emerald-700' }}">{{ $ops['kill_switch'] ? 'Enabled (Dispatch Paused)' : 'Disabled (Dispatch Active)' }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs uppercase text-slate-500">Last Key Rotation</p>
                    <p class="font-medium text-slate-800">{{ $metrics['last_key_rotation'] ?: 'Never' }}</p>
                    <form method="POST" action="{{ route('admin.ops.rotate-key') }}" class="mt-2">
                        @csrf
                        <button class="rounded bg-ink text-white px-3 py-1.5 text-xs">Rotate Signing Key</button>
                    </form>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs uppercase text-slate-500">Replay Rejects</p>
                    <p class="text-lg font-semibold text-slate-900">{{ $metrics['replay_rejects'] }}</p>
                </div>
            </div>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-2xl border bg-white p-4 dash-panel">
            <h3 class="font-semibold text-slate-900 mb-3">Recent Devices</h3>
            <div class="space-y-2">
                @forelse($recent_devices as $device)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-medium text-slate-900">{{ $device->hostname }}</p>
                            <span class="text-xs px-2 py-1 rounded-full {{ $device->status === 'online' ? 'bg-green-100 text-green-700' : 'bg-slate-200 text-slate-700' }}">{{ $device->status }}</span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">{{ $device->os_name }} {{ $device->os_version }}</p>
                        <p class="text-xs text-slate-500">Last seen: {{ $device->last_seen_at ?: 'never' }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No devices yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border bg-white p-4 dash-panel">
            <h3 class="font-semibold text-slate-900 mb-3">Recent Job Runs</h3>
            <div class="space-y-2">
                @forelse($recent_jobs as $job)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <p class="font-mono text-xs text-slate-700 break-all">{{ $job->id }}</p>
                        <div class="mt-1 flex items-center justify-between">
                            <p class="text-sm text-slate-700">Status: <span class="font-medium">{{ $job->status }}</span></p>
                            <p class="text-xs text-slate-500">{{ $job->updated_at }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No job runs yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-admin-layout>
