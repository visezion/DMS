<x-admin-layout :title="'Telemetry: '.$device->hostname" :heading="'Telemetry: '.$device->hostname">
    @php
        $agentSections = [
            'Runtime Diagnostics' => data_get($rawPayload, 'runtime_diagnostics', []),
            'UWF Status' => data_get($rawPayload, 'uwf_status', []),
            'Inventory' => data_get($rawPayload, 'inventory', []),
            'Behavior Summary' => $behaviorSummary,
        ];

        $windowsSections = [
            'Basic Device Identity' => data_get($windowsTelemetry, 'basic_device_identity', []),
            'System Health and Performance' => data_get($windowsTelemetry, 'system_health_and_performance', []),
            'Windows Event Logs' => data_get($windowsTelemetry, 'windows_event_logs', []),
            'Process and Application Activity' => data_get($windowsTelemetry, 'process_and_application_activity', []),
            'Security Posture' => data_get($windowsTelemetry, 'security_posture', []),
            'Authentication and User Activity' => data_get($windowsTelemetry, 'authentication_and_user_activity', []),
            'File and Storage Activity' => data_get($windowsTelemetry, 'file_and_storage_activity', []),
            'Network Telemetry' => data_get($windowsTelemetry, 'network_telemetry', []),
            'Configuration and Policy State' => data_get($windowsTelemetry, 'configuration_and_policy_state', []),
            'Smart Operational Data' => data_get($windowsTelemetry, 'smart_operational_data', []),
        ];

        $displaySnapshotAt = $isLivePreview
            ? ($livePreviewGeneratedAt ?? null)
            : ($snapshot?->snapshot_at ?? null);
    @endphp

    <div class="space-y-4 sm:space-y-5">
        @if ($isLivePreview)
            <section class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                Stored intelligence snapshot is not available yet. Showing a live telemetry preview derived from the latest device check-in payload.
            </section>
        @endif

        <section class="rounded-xl border border-[#d7deea] bg-white p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1 space-y-2">
                    <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Device Telemetry</p>
                    <h2 class="break-words text-lg font-semibold text-slate-900 sm:text-xl">{{ $device->hostname }}</h2>
                    <div class="flex flex-wrap gap-2 text-[11px] text-slate-600 sm:text-xs">
                        <span class="rounded-lg border border-[#d7deea] px-3 py-1">
                            {{ $isLivePreview ? 'Live preview generated' : 'Snapshot' }}: {{ optional($displaySnapshotAt)->format('Y-m-d H:i:s') ?? 'Not available' }}
                        </span>
                        <span class="rounded-lg border border-[#d7deea] px-3 py-1">Behavior events: {{ data_get($behaviorSummary, 'recent_event_count', 0) }}</span>
                        @if ($collectorError)
                            <span class="rounded-lg border border-[#d7deea] px-3 py-1">Collector: {{ $collectorError }}</span>
                        @endif
                    </div>
                </div>
                <div class="grid w-full gap-2 sm:flex sm:w-auto sm:shrink-0 sm:flex-wrap">
                    <a href="{{ route('admin.intelligence.health.device', $device->id) }}" class="rounded-lg border border-[#d7deea] px-3 py-2 text-center text-sm text-slate-700">Device Health</a>
                    <a href="{{ route('admin.intelligence.executive', $device->id) }}" class="rounded-lg border border-[#d7deea] px-3 py-2 text-center text-sm text-slate-700">Executive Summary</a>
                    <a href="{{ route('admin.intelligence.assistant') }}" class="rounded-lg border border-[#d7deea] px-3 py-2 text-center text-sm text-slate-700">AI Assistant</a>
                </div>
            </div>
            <div class="mt-4 flex max-w-full flex-wrap gap-2 text-[11px] text-slate-600 sm:text-xs">
                @foreach (array_keys($agentSections) as $sectionTitle)
                    <a href="#{{ \Illuminate\Support\Str::slug($sectionTitle) }}" class="max-w-full rounded-lg border border-[#d7deea] px-3 py-1 break-words text-left">{{ $sectionTitle }}</a>
                @endforeach
                @foreach (array_keys($windowsSections) as $sectionTitle)
                    <a href="#{{ \Illuminate\Support\Str::slug($sectionTitle) }}" class="max-w-full rounded-lg border border-[#d7deea] px-3 py-1 break-words text-left">{{ $sectionTitle }}</a>
                @endforeach
                @if ($collectorError || filled(data_get($windowsTelemetryMeta, 'collector')))
                    <a href="#collector-metadata" class="max-w-full rounded-lg border border-[#d7deea] px-3 py-1 break-words text-left">Collector Metadata</a>
                @endif
                <a href="#raw-snapshot" class="max-w-full rounded-lg border border-[#d7deea] px-3 py-1 break-words text-left">Raw Snapshot</a>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[0.95fr,1.05fr] xl:gap-5">
            <article class="rounded-xl border border-[#d7deea] bg-white p-4 sm:p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Identity</p>
                <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">Device identity and ownership</h3>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'hostname' => data_get($identity, 'hostname'),
                        'serial_number' => data_get($identity, 'serial_number'),
                        'manufacturer' => data_get($identity, 'manufacturer'),
                        'model' => data_get($identity, 'model'),
                        'windows_edition' => data_get($identity, 'windows_edition'),
                        'windows_build_number' => data_get($identity, 'windows_build_number'),
                        'bios_uefi_version' => data_get($identity, 'bios_uefi_version'),
                        'physical_location' => data_get($identity, 'physical_location'),
                    ] as $label => $value)
                        <div class="rounded-lg border border-[#d7deea] bg-slate-50 p-3">
                            <dt class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">{{ str($label)->replace('_', ' ') }}</dt>
                            <dd class="mt-1 break-words text-sm font-medium text-slate-900">{{ filled($value) ? $value : 'Not provided' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </article>

            <article class="rounded-xl border border-[#d7deea] bg-white p-4 sm:p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Derived Metrics</p>
                <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">Engine input metrics</h3>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'cpu_usage_percent',
                        'memory_usage_percent',
                        'disk_free_percent',
                        'failed_logins_24h',
                        'patch_gap_count',
                        'external_connections_24h',
                        'running_process_count',
                        'installed_software_count',
                    ] as $metricKey)
                        <div class="rounded-lg border border-[#d7deea] bg-slate-50 p-3">
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">{{ str($metricKey)->replace('_', ' ') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">
                                @php($value = data_get($metrics, $metricKey))
                                {{ ($value === null || $value === '') ? 'N/A' : $value }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </article>
        </section>

        <section class="space-y-4">
            <div>
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Agent Payload</p>
                <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">Everything the endpoint agent stored for this snapshot</h3>
            </div>
            <div class="grid gap-4 xl:grid-cols-2 xl:gap-5">
                @foreach ($agentSections as $sectionTitle => $sectionData)
                    <article id="{{ \Illuminate\Support\Str::slug($sectionTitle) }}" class="rounded-xl border border-[#d7deea] bg-white p-4 sm:p-5">
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Agent Section</p>
                        <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">{{ $sectionTitle }}</h3>
                        <pre class="mt-4 max-w-full overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-[#d7deea] bg-slate-50 p-3 text-[11px] leading-5 text-slate-800 sm:p-4 sm:text-xs sm:leading-6">{{ json_encode($sectionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Windows Telemetry</p>
                <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">Full collected Windows telemetry sections</h3>
            </div>
            <div class="grid gap-4 xl:grid-cols-2 xl:gap-5">
                @foreach ($windowsSections as $sectionTitle => $sectionData)
                    <article id="{{ \Illuminate\Support\Str::slug($sectionTitle) }}" class="rounded-xl border border-[#d7deea] bg-white p-4 sm:p-5">
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Windows Section</p>
                        <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">{{ $sectionTitle }}</h3>
                        <pre class="mt-4 max-w-full overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-[#d7deea] bg-slate-50 p-3 text-[11px] leading-5 text-slate-800 sm:p-4 sm:text-xs sm:leading-6">{{ json_encode($sectionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </article>
                @endforeach
            </div>
        </section>

        @if ($collectorError || filled(data_get($windowsTelemetryMeta, 'collector')))
            <section id="collector-metadata" class="rounded-xl border border-[#d7deea] bg-white p-4 sm:p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Collector Metadata</p>
                <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">Windows telemetry collector state</h3>
                <pre class="mt-4 max-w-full overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-[#d7deea] bg-slate-50 p-3 text-[11px] leading-5 text-slate-800 sm:p-4 sm:text-xs sm:leading-6">{{ json_encode($windowsTelemetryMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </section>
        @endif

        <section id="raw-snapshot" class="rounded-xl border border-[#d7deea] bg-white p-4 sm:p-5">
            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 sm:text-xs">Raw Snapshot</p>
            <h3 class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">Complete stored payload</h3>
            <pre class="mt-4 max-w-full overflow-x-auto whitespace-pre-wrap break-all rounded-lg border border-[#d7deea] bg-slate-50 p-3 text-[11px] leading-5 text-slate-800 sm:p-4 sm:text-xs sm:leading-6">{{ json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
    </div>
</x-admin-layout>
