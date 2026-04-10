<?php

namespace App\Domain\Common;

use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DeviceTelemetryDataBuilder
{
    public function build(Device $device): array
    {
        $tags = is_array($device->tags) ? $device->tags : [];
        $inventory = $this->arrayValue($tags, 'inventory');
        $inventoryIdentity = $this->arrayValue($inventory, 'device_identity');
        $tagIdentity = $this->arrayValue($tags, 'device_identity');
        $runtime = $this->arrayValue($tags, 'runtime_diagnostics');
        $uwf = $this->arrayValue($tags, 'uwf_status');
        $windowsTelemetry = $this->arrayValue($inventory, 'windows_telemetry');
        $windowsTelemetryError = trim((string) data_get($windowsTelemetry, 'collection_error', ''));
        $windowsTelemetryMeta = [
            'supported' => data_get($windowsTelemetry, 'supported'),
            'collection_error' => $windowsTelemetryError !== '' ? $windowsTelemetryError : null,
            'stdout_tail' => data_get($windowsTelemetry, 'stdout_tail'),
            'stderr_tail' => data_get($windowsTelemetry, 'stderr_tail'),
            'collector' => data_get($windowsTelemetry, 'collector'),
            'collector_version' => data_get($windowsTelemetry, 'collector_version'),
            'collected_at' => data_get($windowsTelemetry, 'collected_at'),
        ];

        $identity = $this->arrayValue($windowsTelemetry, 'basic_device_identity');
        $systemHealth = $this->arrayValue($windowsTelemetry, 'system_health_and_performance');
        $eventLogs = $this->arrayValue($windowsTelemetry, 'windows_event_logs');
        $processActivity = $this->arrayValue($windowsTelemetry, 'process_and_application_activity');
        $security = $this->arrayValue($windowsTelemetry, 'security_posture') ?: $this->arrayValue($inventory, 'security_posture');
        $auth = $this->arrayValue($windowsTelemetry, 'authentication_and_user_activity');
        $fileStorage = $this->arrayValue($windowsTelemetry, 'file_and_storage_activity');
        $networkTelemetry = $this->arrayValue($windowsTelemetry, 'network_telemetry') ?: $this->arrayValue($inventory, 'network');
        $configState = $this->arrayValue($windowsTelemetry, 'configuration_and_policy_state');
        $smartOps = $this->arrayValue($windowsTelemetry, 'smart_operational_data');
        $windowsUpdate = $this->arrayValue($security, 'windows_update_status') ?: $this->arrayValue($inventory, 'windows_update');
        $inventoryProcesses = collect($inventory['running_processes'] ?? []);
        $inventoryServices = collect($inventory['services'] ?? []);
        $inventorySessions = collect($inventory['logged_in_sessions'] ?? []);

        $recentLogs = DeviceBehaviorLog::query()
            ->where('device_id', $device->id)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->orderByDesc('occurred_at')
            ->limit(250)
            ->get();

        $identity = $this->buildIdentityFallback($device, $identity, $inventoryIdentity, $tagIdentity, $inventory);
        $systemHealth = $this->buildSystemHealthFallback($systemHealth, $runtime, $inventory, $inventoryServices);
        $processActivity = $this->buildProcessActivityFallback($processActivity, $inventory, $inventoryProcesses, $inventoryServices);
        $security = $this->buildSecurityFallback($security, $inventoryServices, $inventoryProcesses, $windowsUpdate, $uwf);
        $auth = $this->buildAuthenticationFallback($auth, $inventorySessions);
        $fileStorage = $this->buildFileStorageFallback($fileStorage, $inventory);
        $configState = $this->buildConfigurationFallback($configState, $inventoryServices);
        $smartOps = $this->buildSmartOpsFallback($smartOps, $recentLogs);

        $latestDrive = collect($inventory['disks'] ?? $inventory['drives'] ?? [])
            ->firstWhere('name', 'C:')
            ?? collect($inventory['disks'] ?? $inventory['drives'] ?? [])->first()
            ?? [];

        $systemEventCounts = $this->arrayValue($eventLogs, 'important_event_counts_24h');
        $loginEvents = $this->arrayValue($auth, 'login_events');
        $battery = collect($systemHealth['battery_health'] ?? [])->first() ?: [];
        $temperature = collect($systemHealth['temperature_celsius'] ?? [])->first() ?: [];
        $networkStats = collect($networkTelemetry['bytes_sent_received'] ?? []);
        $localAdmins = collect($security['local_admin_accounts'] ?? []);
        $runningProcesses = collect($processActivity['running_processes'] ?? $inventory['running_processes'] ?? []);
        $installedSoftware = collect($processActivity['installed_software'] ?? $inventory['installed_software'] ?? []);
        $startupApplications = collect($processActivity['startup_applications'] ?? []);
        $scheduledTasks = collect($processActivity['scheduled_tasks'] ?? []);
        $dnsConfig = collect($configState['dns_configuration'] ?? []);
        $connections = collect($networkTelemetry['active_tcp_connections'] ?? []);

        $metrics = [
            'cpu_usage_percent' => $this->firstNumeric([
                data_get($runtime, 'cpu_usage_percent'),
                data_get($systemHealth, 'cpu_usage_percent'),
                data_get($runtime, 'cpu_percent'),
            ]),
            'memory_usage_percent' => $this->firstNumeric([
                data_get($runtime, 'memory_usage_percent'),
                data_get($systemHealth, 'memory_usage_percent'),
                data_get($runtime, 'memory_percent'),
                $this->resolveMemoryUsagePercent($inventory),
            ]),
            'memory_total_bytes' => $this->firstNumeric([
                data_get($systemHealth, 'memory_total_bytes'),
                data_get($inventory, 'memory.total_bytes'),
            ]),
            'memory_used_bytes' => $this->firstNumeric([
                data_get($systemHealth, 'memory_used_bytes'),
                $this->resolveMemoryUsedBytes($inventory),
            ]),
            'disk_free_percent' => $this->resolveDiskFreePercent($runtime, $systemHealth, $latestDrive),
            'disk_io_bytes_per_second' => $this->firstNumeric([
                data_get($systemHealth, 'disk_io_bytes_per_second'),
                data_get($runtime, 'disk_io_bytes_per_second'),
            ]),
            'boot_duration_seconds' => $this->firstNumeric([
                data_get($runtime, 'boot_duration_seconds'),
                data_get($runtime, 'boot_seconds'),
            ]),
            'boot_time_utc' => data_get($systemHealth, 'boot_time_utc'),
            'uptime_seconds' => $this->firstNumeric([
                data_get($runtime, 'uptime_seconds'),
                data_get($systemHealth, 'uptime_seconds'),
            ]),
            'battery_health_percent' => $this->firstNumeric([
                data_get($runtime, 'battery_health_percent'),
                data_get($battery, 'EstimatedChargeRemaining'),
            ], 100),
            'thermal_state_percent' => $this->firstNumeric([
                data_get($runtime, 'temperature_percent'),
                data_get($runtime, 'thermal_percent'),
                data_get($temperature, 'celsius'),
            ]),
            'service_failures_24h' => $this->firstInt([
                data_get($systemHealth, 'service_failures_24h'),
                data_get($systemEventCounts, 'service_failures_24h'),
                $this->countByTypes($recentLogs, ['service_failure'], 1),
            ]),
            'crash_count_7d' => $this->firstInt([
                data_get($smartOps, 'app_crash_frequency_7d'),
                $this->countByTypes($recentLogs, ['app_crash', 'application_crash', 'blue_screen', 'bsod'], 7),
            ]),
            'unexpected_shutdowns_7d' => $this->firstInt([
                data_get($smartOps, 'repeated_reboot_issues_7d'),
                $this->countByTypes($recentLogs, ['unexpected_shutdown', 'shutdown_unexpected'], 7),
            ]),
            'failed_logins_24h' => $this->firstInt([
                data_get($loginEvents, 'failed_logins_24h'),
                data_get($systemEventCounts, 'failed_logins_24h'),
                $this->countByTypes($recentLogs, ['failed_login', 'login_failed'], 1),
                $this->countByTypes($recentLogs, ['user_logon'], 1, fn (DeviceBehaviorLog $log): bool => (string) data_get($log->metadata ?? [], 'status') === 'failed'),
            ]),
            'successful_logins_24h' => $this->firstInt([
                data_get($loginEvents, 'successful_logins_24h'),
                data_get($systemEventCounts, 'successful_logins_24h'),
                $this->countByTypes($recentLogs, ['successful_login', 'login_success'], 1),
                $this->countByTypes($recentLogs, ['user_logon'], 1, fn (DeviceBehaviorLog $log): bool => (string) data_get($log->metadata ?? [], 'status') !== 'failed'),
            ]),
            'suspicious_powershell_24h' => $this->countSuspiciousPowershell($recentLogs, 1),
            'usb_events_24h' => $this->firstInt([
                count($security['usb_storage_or_external_device_insertions'] ?? []),
                $this->countByTypes($recentLogs, ['usb_inserted', 'usb_storage_connected'], 1),
            ]),
            'patch_gap_count' => $this->resolvePatchGapCount($windowsUpdate),
            'defender_enabled' => $this->resolveDefenderEnabled($security),
            'firewall_enabled' => $this->resolveFirewallEnabled($security),
            'bitlocker_enabled' => $this->resolveBitLockerEnabled($security),
            'secure_boot_enabled' => $this->boolValue($security, ['secure_boot_status'], true),
            'tpm_present' => $this->boolValue($this->arrayValue($security, 'tpm_presence_and_health'), ['TpmPresent', 'tpm_present'], true),
            'network_bytes_sent' => (float) $networkStats->sum(fn ($row) => is_numeric(data_get($row, 'SentBytes')) ? (float) data_get($row, 'SentBytes') : 0),
            'network_bytes_received' => (float) $networkStats->sum(fn ($row) => is_numeric(data_get($row, 'ReceivedBytes')) ? (float) data_get($row, 'ReceivedBytes') : 0),
            'external_connections_24h' => $this->firstInt([
                count($networkTelemetry['unusual_external_communication'] ?? []),
                count($networkTelemetry['frequent_outbound_destinations'] ?? []),
                $this->countExternalConnections($recentLogs, 1),
            ]),
            'running_process_count' => $runningProcesses->count(),
            'installed_software_count' => $installedSoftware->count(),
            'startup_application_count' => $startupApplications->count(),
            'scheduled_task_count' => $scheduledTasks->count(),
            'local_admin_account_count' => $localAdmins->count(),
            'dns_server_count' => $dnsConfig->sum(fn ($row) => count(data_get($row, 'ServerAddresses', []))),
            'tcp_connection_count' => $connections->count(),
            'agent_version' => (string) ($device->agent_version ?? ''),
            'hostname' => (string) ($device->hostname ?? data_get($identity, 'hostname', '')),
            'os_name' => (string) ($device->os_name ?? data_get($identity, 'windows_edition', '')),
            'os_version' => (string) ($device->os_version ?? data_get($identity, 'windows_build_number', '')),
            'serial_number' => (string) ($device->serial_number ?? data_get($identity, 'serial_number', '')),
            'manufacturer' => (string) data_get($identity, 'manufacturer', ''),
            'model' => (string) data_get($identity, 'model', ''),
            'windows_edition' => (string) data_get($identity, 'windows_edition', ''),
            'windows_build_number' => (string) data_get($identity, 'windows_build_number', ''),
            'bios_uefi_version' => (string) data_get($identity, 'bios_uefi_version', ''),
            'domain_joined' => (bool) data_get($identity, 'domain_joined', false),
            'azure_ad_joined' => (bool) data_get($identity, 'azure_ad_joined', false),
            'physical_location' => (string) data_get($identity, 'physical_location', ''),
            'network_adapter_count' => count(data_get($systemHealth, 'network_adapter_health', data_get($inventory, 'network.adapters', []))),
            'uwf_enabled' => $this->boolValue($uwf, ['enabled'], false),
            'behavior_last_ingested_at' => data_get($tags, 'behavior_telemetry.last_ingested_at'),
        ];

        $rawPayload = [
            'identity' => [
                'device_id' => $device->id,
                'hostname' => $metrics['hostname'],
                'os_name' => $metrics['os_name'],
                'os_version' => $metrics['os_version'],
                'serial_number' => $metrics['serial_number'],
                'manufacturer' => $metrics['manufacturer'],
                'model' => $metrics['model'],
                'windows_edition' => $metrics['windows_edition'],
                'windows_build_number' => $metrics['windows_build_number'],
                'bios_uefi_version' => $metrics['bios_uefi_version'],
                'domain_joined' => $metrics['domain_joined'],
                'azure_ad_joined' => $metrics['azure_ad_joined'],
                'physical_location' => $metrics['physical_location'],
            ],
            'runtime_diagnostics' => $runtime,
            'uwf_status' => $uwf,
            'inventory' => $inventory,
            'windows_telemetry_meta' => $windowsTelemetryMeta,
            'windows_telemetry' => [
                'basic_device_identity' => $identity,
                'system_health_and_performance' => $systemHealth,
                'windows_event_logs' => $eventLogs,
                'process_and_application_activity' => $processActivity,
                'security_posture' => $security,
                'authentication_and_user_activity' => $auth,
                'file_and_storage_activity' => $fileStorage,
                'network_telemetry' => $networkTelemetry,
                'configuration_and_policy_state' => $configState,
                'smart_operational_data' => $smartOps,
            ],
            'behavior_summary' => [
                'last_ingested_at' => data_get($tags, 'behavior_telemetry.last_ingested_at'),
                'last_batch_count' => data_get($tags, 'behavior_telemetry.last_batch_count'),
                'recent_event_count' => $recentLogs->count(),
                'recent_events' => $recentLogs->take(50)->map(fn (DeviceBehaviorLog $log) => [
                    'id' => $log->id,
                    'event_type' => $log->event_type,
                    'occurred_at' => optional($log->occurred_at)?->toIso8601String(),
                    'user_name' => $log->user_name,
                    'process_name' => $log->process_name,
                    'file_path' => $log->file_path,
                    'event_uid' => $log->event_uid,
                    'session_uid' => $log->session_uid,
                    'process_uid' => $log->process_uid,
                    'parent_process_uid' => $log->parent_process_uid,
                    'checkin_id' => $log->checkin_id,
                    'metadata' => $log->metadata,
                ])->values()->all(),
            ],
            'telemetry_coverage' => [
                'windows_telemetry_present' => $windowsTelemetryError === '' && $this->hasMeaningfulTelemetry($windowsTelemetry),
                'behavior_logs_present' => $recentLogs->isNotEmpty(),
                'runtime_diagnostics_present' => $this->hasMeaningfulTelemetry($runtime),
                'inventory_present' => $this->hasMeaningfulTelemetry($inventory),
                'security_posture_present' => $this->hasMeaningfulTelemetry($security),
                'network_telemetry_present' => $this->hasMeaningfulTelemetry($networkTelemetry),
                'configuration_state_present' => $this->hasMeaningfulTelemetry($configState),
            ],
        ];

        return [
            'snapshot_at' => now(),
            'metrics' => $metrics,
            'raw_payload' => $rawPayload,
            'features' => [
                'recent_behavior_event_count' => $recentLogs->count(),
                'risk_event_ratio' => round(
                    (($metrics['failed_logins_24h'] ?? 0)
                        + ($metrics['suspicious_powershell_24h'] ?? 0)
                        + ($metrics['usb_events_24h'] ?? 0))
                    / max(1, $recentLogs->count()),
                    4
                ),
                'device_status' => (string) ($device->status ?? 'unknown'),
                'telemetry_coverage' => $rawPayload['telemetry_coverage'],
                'inventory_software_count' => $metrics['installed_software_count'],
                'network_connection_count' => $metrics['tcp_connection_count'],
            ],
            'recent_logs' => $recentLogs,
        ];
    }

    private function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private function hasMeaningfulTelemetry(mixed $value): bool
    {
        if (!is_array($value)) {
            return $value !== null && $value !== '';
        }

        if ($value === []) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->hasMeaningfulTelemetry($item)) {
                return true;
            }
        }

        return false;
    }

    private function buildIdentityFallback(Device $device, array $identity, array $inventoryIdentity, array $tagIdentity, array $inventory): array
    {
        $cpuModel = trim((string) data_get($inventory, 'cpu.model', ''));

        return [
            'hostname' => (string) ($identity['hostname'] ?? $tagIdentity['hostname'] ?? $inventoryIdentity['hostname'] ?? $device->hostname ?? ''),
            'serial_number' => (string) ($identity['serial_number'] ?? $tagIdentity['serial_number'] ?? $inventoryIdentity['serial_number'] ?? $device->serial_number ?? ''),
            'manufacturer' => (string) ($identity['manufacturer'] ?? $tagIdentity['manufacturer'] ?? $inventoryIdentity['manufacturer'] ?? ''),
            'model' => (string) ($identity['model'] ?? $tagIdentity['model'] ?? $inventoryIdentity['model'] ?? $cpuModel),
            'windows_edition' => (string) ($identity['windows_edition'] ?? $tagIdentity['windows_edition'] ?? $inventoryIdentity['windows_edition'] ?? $device->os_name ?? ''),
            'windows_build_number' => (string) ($identity['windows_build_number'] ?? $tagIdentity['windows_build_number'] ?? $inventoryIdentity['windows_build_number'] ?? $device->os_version ?? ''),
            'bios_uefi_version' => (string) ($identity['bios_uefi_version'] ?? $tagIdentity['bios_uefi_version'] ?? $inventoryIdentity['bios_uefi_version'] ?? ''),
            'domain_joined' => (bool) ($identity['domain_joined'] ?? $tagIdentity['domain_joined'] ?? $inventoryIdentity['domain_joined'] ?? false),
            'azure_ad_joined' => (bool) ($identity['azure_ad_joined'] ?? $tagIdentity['azure_ad_joined'] ?? $inventoryIdentity['azure_ad_joined'] ?? false),
            'physical_location' => (string) ($identity['physical_location'] ?? $tagIdentity['physical_location'] ?? $inventoryIdentity['physical_location'] ?? ''),
        ];
    }

    private function buildSystemHealthFallback(array $systemHealth, array $runtime, array $inventory, Collection $inventoryServices): array
    {
        $memoryTotal = $this->firstNumeric([
            data_get($systemHealth, 'memory_total_bytes'),
            data_get($inventory, 'memory.total_bytes'),
        ]);
        $memoryUsed = $this->firstNumeric([
            data_get($systemHealth, 'memory_used_bytes'),
            $this->resolveMemoryUsedBytes($inventory),
        ]);
        $memoryUsagePercent = $this->firstNumeric([
            data_get($systemHealth, 'memory_usage_percent'),
            data_get($runtime, 'memory_usage_percent'),
            $this->resolveMemoryUsagePercent($inventory),
        ]);

        return [
            'cpu_usage_percent' => data_get($systemHealth, 'cpu_usage_percent', data_get($runtime, 'cpu_usage_percent')),
            'memory_usage_percent' => $memoryUsagePercent > 0 ? $memoryUsagePercent : data_get($systemHealth, 'memory_usage_percent'),
            'memory_total_bytes' => $memoryTotal > 0 ? $memoryTotal : data_get($systemHealth, 'memory_total_bytes'),
            'memory_used_bytes' => $memoryUsed > 0 ? $memoryUsed : data_get($systemHealth, 'memory_used_bytes'),
            'disk_space_per_drive' => $this->hasMeaningfulTelemetry(data_get($systemHealth, 'disk_space_per_drive'))
                ? data_get($systemHealth, 'disk_space_per_drive')
                : collect($inventory['disks'] ?? [])->map(fn ($disk) => [
                    'drive' => data_get($disk, 'name'),
                    'total_bytes' => data_get($disk, 'total_bytes'),
                    'free_bytes' => data_get($disk, 'free_bytes'),
                    'used_percent' => $this->resolveDiskUsedPercent($disk),
                ])->values()->all(),
            'uptime_seconds' => data_get($systemHealth, 'uptime_seconds', data_get($runtime, 'uptime_seconds')),
            'service_failures_24h' => data_get($systemHealth, 'service_failures_24h', 0),
            'running_services_status' => $this->hasMeaningfulTelemetry(data_get($systemHealth, 'running_services_status'))
                ? data_get($systemHealth, 'running_services_status')
                : [
                    'total' => $inventoryServices->count(),
                    'running' => $inventoryServices->filter(fn ($service) => strtoupper((string) data_get($service, 'state')) === 'RUNNING')->count(),
                    'stopped' => $inventoryServices->filter(fn ($service) => strtoupper((string) data_get($service, 'state')) === 'STOPPED')->count(),
                    'sample' => $inventoryServices->take(50)->values()->all(),
                ],
        ];
    }

    private function buildProcessActivityFallback(array $processActivity, array $inventory, Collection $inventoryProcesses, Collection $inventoryServices): array
    {
        return [
            'running_processes' => $this->hasMeaningfulTelemetry(data_get($processActivity, 'running_processes'))
                ? data_get($processActivity, 'running_processes')
                : $inventoryProcesses->take(160)->values()->all(),
            'installed_software' => $this->hasMeaningfulTelemetry(data_get($processActivity, 'installed_software'))
                ? data_get($processActivity, 'installed_software')
                : collect($inventory['installed_software'] ?? [])->take(500)->values()->all(),
            'startup_applications' => data_get($processActivity, 'startup_applications', []),
            'scheduled_tasks' => data_get($processActivity, 'scheduled_tasks', []),
            'windows_services' => $this->hasMeaningfulTelemetry(data_get($processActivity, 'windows_services'))
                ? data_get($processActivity, 'windows_services')
                : $inventoryServices->take(300)->values()->all(),
            'resource_hogs' => $this->hasMeaningfulTelemetry(data_get($processActivity, 'resource_hogs'))
                ? data_get($processActivity, 'resource_hogs')
                : $inventoryProcesses->sortByDesc(fn ($row) => (int) data_get($row, 'memory_bytes', 0))->take(40)->values()->all(),
        ];
    }

    private function buildSecurityFallback(array $security, Collection $inventoryServices, Collection $inventoryProcesses, array $windowsUpdate, array $uwf): array
    {
        $servicesByName = $inventoryServices
            ->mapWithKeys(fn ($service) => [strtolower((string) data_get($service, 'name')) => strtoupper((string) data_get($service, 'state'))]);

        $defenderRunning = $servicesByName->get('windefend') === 'RUNNING'
            || $inventoryProcesses->contains(fn ($process) => strcasecmp((string) data_get($process, 'name'), 'MsMpEng') === 0);
        $firewallRunning = $servicesByName->get('mpssvc') === 'RUNNING';

        return [
            'microsoft_defender_status' => data_get($security, 'microsoft_defender_status', [
                'AntivirusEnabled' => $defenderRunning,
                'RealTimeProtectionEnabled' => $defenderRunning,
            ]),
            'firewall_status' => data_get($security, 'firewall_status', [
                ['Name' => 'Local', 'Enabled' => $firewallRunning],
            ]),
            'bitlocker_encryption_status' => data_get($security, 'bitlocker_encryption_status', []),
            'secure_boot_status' => data_get($security, 'secure_boot_status'),
            'tpm_presence_and_health' => data_get($security, 'tpm_presence_and_health'),
            'windows_update_status' => data_get($security, 'windows_update_status', $windowsUpdate),
            'tamper_protection_status' => data_get($security, 'tamper_protection_status'),
            'real_time_protection_status' => data_get($security, 'real_time_protection_status', $defenderRunning),
            'local_admin_accounts' => data_get($security, 'local_admin_accounts', []),
            'uwf_state' => [
                'feature_enabled' => data_get($uwf, 'feature_enabled'),
                'feature_state' => data_get($uwf, 'feature_state'),
            ],
        ];
    }

    private function buildAuthenticationFallback(array $auth, Collection $inventorySessions): array
    {
        return [
            'login_events' => data_get($auth, 'login_events', []),
            'auth_event_samples' => data_get($auth, 'auth_event_samples', []),
            'logged_in_sessions' => $this->hasMeaningfulTelemetry(data_get($auth, 'logged_in_sessions'))
                ? data_get($auth, 'logged_in_sessions')
                : $inventorySessions->take(20)->values()->all(),
        ];
    }

    private function buildFileStorageFallback(array $fileStorage, array $inventory): array
    {
        return [
            'low_disk_alerts' => $this->hasMeaningfulTelemetry(data_get($fileStorage, 'low_disk_alerts'))
                ? data_get($fileStorage, 'low_disk_alerts')
                : collect($inventory['disks'] ?? [])
                    ->map(function ($disk) {
                        $freePercent = $this->resolveDiskFreePercent([], [], is_array($disk) ? $disk : []);
                        if ($freePercent >= 10) {
                            return null;
                        }

                        return [
                            'drive' => data_get($disk, 'name'),
                            'free_percent' => $freePercent,
                            'free_bytes' => data_get($disk, 'free_bytes'),
                            'total_bytes' => data_get($disk, 'total_bytes'),
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all(),
            'download_folder_activity' => data_get($fileStorage, 'download_folder_activity', []),
        ];
    }

    private function buildConfigurationFallback(array $configState, Collection $inventoryServices): array
    {
        $winRmState = $inventoryServices
            ->first(fn ($service) => strcasecmp((string) data_get($service, 'name'), 'WinRM') === 0);

        return [
            'windows_update_policy' => data_get($configState, 'windows_update_policy', []),
            'defender_policy' => data_get($configState, 'defender_policy', []),
            'dns_configuration' => data_get($configState, 'dns_configuration', []),
            'remote_management_state' => data_get($configState, 'remote_management_state', [
                'winrm_service' => $winRmState,
            ]),
            'powershell_execution_policy' => data_get($configState, 'powershell_execution_policy', []),
        ];
    }

    private function buildSmartOpsFallback(array $smartOps, Collection $recentLogs): array
    {
        return [
            'incident_count_per_device' => data_get($smartOps, 'incident_count_per_device', $recentLogs->count()),
            'repeated_reboot_issues_7d' => data_get($smartOps, 'repeated_reboot_issues_7d', $this->countByTypes($recentLogs, ['unexpected_shutdown', 'shutdown_unexpected'], 7)),
            'app_crash_frequency_7d' => data_get($smartOps, 'app_crash_frequency_7d', $this->countByTypes($recentLogs, ['app_crash', 'application_crash', 'blue_screen', 'bsod'], 7)),
            'patch_failure_count_7d' => data_get($smartOps, 'patch_failure_count_7d', 0),
            'health_trend_over_time' => data_get($smartOps, 'health_trend_over_time', []),
            'risk_trend_over_time' => data_get($smartOps, 'risk_trend_over_time', []),
        ];
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function firstNumeric(array $values, float $default = 0): float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return $default;
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function firstInt(array $values, int $default = 0): int
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (int) round((float) $value);
            }
        }

        return $default;
    }

    /**
     * @param  array<int,string>  $keys
     */
    private function boolValue(array $source, array $keys, bool $default = false): bool
    {
        foreach ($keys as $key) {
            $value = data_get($source, $key);
            if (is_bool($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (bool) $value;
            }

            if (is_string($value) && trim($value) !== '') {
                return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
            }
        }

        return $default;
    }

    private function resolveDiskFreePercent(array $runtime, array $systemHealth, array $drive): float
    {
        $runtimePercent = $this->firstNumeric([
            data_get($runtime, 'disk_free_percent'),
            data_get($runtime, 'system_drive_free_percent'),
        ], -1);
        if ($runtimePercent >= 0) {
            return $runtimePercent;
        }

        $systemDrivePercent = collect($systemHealth['disk_space_per_drive'] ?? [])
            ->first(fn ($item) => in_array((string) data_get($item, 'drive'), ['C:', 'C:\\'], true) && is_numeric(data_get($item, 'used_percent')));
        if (is_array($systemDrivePercent) && is_numeric(data_get($systemDrivePercent, 'used_percent'))) {
            return round(100 - (float) data_get($systemDrivePercent, 'used_percent'), 2);
        }

        $freeBytes = data_get($drive, 'free_bytes');
        $totalBytes = data_get($drive, 'total_bytes');
        if (is_numeric($freeBytes) && is_numeric($totalBytes) && (float) $totalBytes > 0) {
            return round((((float) $freeBytes / (float) $totalBytes) * 100), 2);
        }

        return 0;
    }

    private function resolveDiskUsedPercent(array $drive): ?float
    {
        $freeBytes = data_get($drive, 'free_bytes');
        $totalBytes = data_get($drive, 'total_bytes');

        if (! is_numeric($freeBytes) || ! is_numeric($totalBytes) || (float) $totalBytes <= 0) {
            return null;
        }

        return round((1 - ((float) $freeBytes / (float) $totalBytes)) * 100, 2);
    }

    private function resolveMemoryUsedBytes(array $inventory): float
    {
        $totalBytes = data_get($inventory, 'memory.total_bytes');
        $availableBytes = data_get($inventory, 'memory.available_bytes');

        if (! is_numeric($totalBytes) || ! is_numeric($availableBytes)) {
            return 0;
        }

        return max(0, (float) $totalBytes - (float) $availableBytes);
    }

    private function resolveMemoryUsagePercent(array $inventory): float
    {
        $totalBytes = data_get($inventory, 'memory.total_bytes');
        $availableBytes = data_get($inventory, 'memory.available_bytes');

        if (! is_numeric($totalBytes) || ! is_numeric($availableBytes) || (float) $totalBytes <= 0) {
            return 0;
        }

        return round((((float) $totalBytes - (float) $availableBytes) / (float) $totalBytes) * 100, 2);
    }

    private function resolvePatchGapCount(array $windowsUpdate): int
    {
        if (is_numeric(data_get($windowsUpdate, 'missing_patches'))) {
            return (int) data_get($windowsUpdate, 'missing_patches');
        }

        if (is_numeric(data_get($windowsUpdate, 'missing_patch_count'))) {
            return (int) data_get($windowsUpdate, 'missing_patch_count');
        }

        if (is_numeric(data_get($windowsUpdate, 'failed_patch_count'))) {
            return (int) data_get($windowsUpdate, 'failed_patch_count');
        }

        if (is_numeric(data_get($windowsUpdate, 'missing_patches.count'))) {
            return (int) data_get($windowsUpdate, 'missing_patches.count');
        }

        return 0;
    }

    private function resolveDefenderEnabled(array $security): bool
    {
        if (array_key_exists('defender_enabled', $security)) {
            return (bool) $security['defender_enabled'];
        }

        if (array_key_exists('microsoft_defender_enabled', $security)) {
            return (bool) $security['microsoft_defender_enabled'];
        }

        $status = $this->arrayValue($security, 'microsoft_defender_status');

        return $this->boolValue($status, ['AntivirusEnabled', 'RealTimeProtectionEnabled'], true);
    }

    private function resolveFirewallEnabled(array $security): bool
    {
        if (array_key_exists('firewall_enabled', $security)) {
            return (bool) $security['firewall_enabled'];
        }

        $profiles = collect($security['firewall_status'] ?? []);
        if ($profiles->isEmpty()) {
            return true;
        }

        return $profiles->contains(fn ($profile) => $this->boolValue(is_array($profile) ? $profile : [], ['Enabled', 'enabled'], false));
    }

    private function resolveBitLockerEnabled(array $security): bool
    {
        if (array_key_exists('bitlocker_enabled', $security)) {
            return (bool) $security['bitlocker_enabled'];
        }

        $volumes = collect($security['bitlocker_encryption_status'] ?? []);
        if ($volumes->isEmpty()) {
            return true;
        }

        return $volumes->contains(function ($volume): bool {
            $row = is_array($volume) ? $volume : [];
            $status = strtolower((string) data_get($row, 'ProtectionStatus', ''));
            $volumeStatus = strtolower((string) data_get($row, 'VolumeStatus', ''));

            return in_array($status, ['1', 'on', 'true', 'protectionon'], true)
                || str_contains($volumeStatus, 'fullyencrypted');
        });
    }

    /**
     * @param  Collection<int,DeviceBehaviorLog>  $logs
     * @param  array<int,string>  $types
     * @param  callable(DeviceBehaviorLog):bool|null  $extraFilter
     */
    private function countByTypes(Collection $logs, array $types, int $days, ?callable $extraFilter = null): int
    {
        $cutoff = now()->subDays($days);

        return $logs
            ->filter(fn (DeviceBehaviorLog $log) => $log->occurred_at instanceof Carbon && $log->occurred_at->greaterThanOrEqualTo($cutoff))
            ->filter(fn (DeviceBehaviorLog $log) => in_array((string) $log->event_type, $types, true))
            ->filter(fn (DeviceBehaviorLog $log) => $extraFilter ? $extraFilter($log) : true)
            ->count();
    }

    /**
     * @param  Collection<int,DeviceBehaviorLog>  $logs
     */
    private function countSuspiciousPowershell(Collection $logs, int $days): int
    {
        $cutoff = now()->subDays($days);

        return $logs
            ->filter(fn (DeviceBehaviorLog $log) => $log->occurred_at instanceof Carbon && $log->occurred_at->greaterThanOrEqualTo($cutoff))
            ->filter(function (DeviceBehaviorLog $log): bool {
                $processName = strtolower((string) ($log->process_name ?? ''));
                $commandLine = strtolower((string) data_get($log->metadata ?? [], 'command_line', ''));

                return str_contains($processName, 'powershell')
                    && (str_contains($commandLine, '-encodedcommand') || str_contains($commandLine, 'iex('));
            })
            ->count();
    }

    /**
     * @param  Collection<int,DeviceBehaviorLog>  $logs
     */
    private function countExternalConnections(Collection $logs, int $days): int
    {
        $cutoff = now()->subDays($days);

        return $logs
            ->filter(fn (DeviceBehaviorLog $log) => $log->occurred_at instanceof Carbon && $log->occurred_at->greaterThanOrEqualTo($cutoff))
            ->filter(function (DeviceBehaviorLog $log): bool {
                $remoteIp = (string) data_get($log->metadata ?? [], 'remote_ip', data_get($log->metadata ?? [], 'source_ip', ''));

                return (string) $log->event_type === 'network_connection'
                    && $remoteIp !== ''
                    && ! str_starts_with($remoteIp, '10.')
                    && ! str_starts_with($remoteIp, '192.168.')
                    && ! preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $remoteIp);
            })
            ->count();
    }
}
