<?php

namespace App\Services\AiPower;

use App\Models\BehaviorAnomalyCase;
use App\Models\ComplianceResult;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceBehaviorDriftEvent;
use App\Models\DeviceBehaviorLog;
use App\Models\DeviceGroup;
use App\Models\DmsJob;
use App\Models\JobRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AiFunctionSystemService
{
    /**
     * @param array<string,mixed> $plan
     * @return array{
     *   domain:string,
     *   topic:string,
     *   title:string,
     *   summary:string,
     *   metrics:list<array{label:string,value:string}>,
     *   items:list<array{label:string,detail:string,severity:string}>,
     *   recommendations:list<string>,
     *   context:array<string,mixed>,
     *   needs_clarification:bool,
     *   clarification:string|null,
     *   generated_at:string
     * }
     */
    public function answer(string $instruction, array $plan = []): array
    {
        $instruction = trim($instruction);
        $query = $this->classify($instruction);
        $target = $this->resolveTarget($instruction, $plan);

        if (($target['scope'] ?? '') === 'group' && ! is_array($target['group'] ?? null)) {
            $clarification = trim((string) ($target['group_error'] ?? 'I could not resolve that group. Please provide the exact group name.'));

            return $this->result(
                'general',
                'group_target_unresolved',
                'Clarification Needed',
                'I could not resolve that group yet.',
                [],
                [],
                [],
                [
                    'query' => $query,
                    'target' => $target,
                ],
                true,
                $clarification
            );
        }

        $result = match ($query['domain']) {
            'health' => $this->health($query['topic'], $target),
            'anomaly' => $this->anomaly($query['topic'], $target),
            'security' => $this->security($query['topic']),
            'software' => $this->software($query['topic'], $target),
            'patch' => $this->patch($query['topic']),
            'network' => $this->network($query['topic']),
            'user' => $this->user($query['topic'], $target),
            'compliance' => $this->compliance($query['topic']),
            'incident' => $this->incident($query['topic'], $target),
            'recommendation' => $this->recommendation($query['topic']),
            'reporting' => $this->reporting($query['topic'], $target),
            'advanced' => $this->advanced($query['topic']),
            default => $this->overview(),
        };

        $result['context']['query'] = $query;
        $result['context']['target'] = $target;

        return $result;
    }

    /**
     * @return array{domain:string,topic:string}
     */
    private function classify(string $instruction): array
    {
        $text = mb_strtolower($instruction);
        $checkinWindowMinutes = $this->extractCheckinWindowMinutes($text);
        if ($checkinWindowMinutes !== null) {
            return ['domain' => 'health', 'topic' => 'not_checked_in_window:'.$checkinWindowMinutes];
        }
        if (
            preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bin\b/u', $text) === 1
            || preg_match('/^\s*in\s+[a-z0-9._\-\s]{2,}\s+group[?.! ]*$/u', $text) === 1
        ) {
            return ['domain' => 'reporting', 'topic' => 'device_count'];
        }
        if (preg_match('/\b(?:list|show)\b.*\bdevices?\b.*\bgroup\b/u', $text) === 1) {
            return ['domain' => 'reporting', 'topic' => 'device_count'];
        }
        if (preg_match('/^\s*(?:show|list)\s+(?:all\s+)?([a-z0-9][a-z0-9._\-\s]{1,80}?)\s+(?:devices?|machines?|hosts?|pcs?|computers?)\s*[?.! ]*$/u', $text, $m) === 1) {
            $candidate = trim((string) ($m[1] ?? ''));
            if (! $this->isGenericGroupQualifier($candidate)) {
                return ['domain' => 'reporting', 'topic' => 'device_count'];
            }
        }
        if (preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bonline\b/u', $text) === 1) {
            return ['domain' => 'reporting', 'topic' => 'online_count'];
        }
        if (preg_match('/\bhow\s+many\b.*\bdevices?\b.*\boffline\b/u', $text) === 1) {
            return ['domain' => 'reporting', 'topic' => 'offline_count'];
        }
        if (
            preg_match('/\b(ip|ip address|network ip)\b/u', $text) === 1
            && preg_match('/\b(devices?|machines?|computers?|hosts?)\b/u', $text) === 1
        ) {
            return ['domain' => 'reporting', 'topic' => 'device_ips'];
        }
        if ($this->containsAny($text, ['device names', 'device name', 'all device names', 'all devices names', 'all device name', 'all devices name', 'list all devices', 'show all devices', 'how all device name', 'available devices', 'devices available', 'show all available devices', 'show all availiable devices', 'what are devices available', 'what are the devices available'])) {
            return ['domain' => 'reporting', 'topic' => 'device_list'];
        }
        if ($this->containsAny($text, ['daily report', 'executive summary', 'top issues', 'security overview', 'summary of all devices', 'trend'])) {
            return ['domain' => 'reporting', 'topic' => 'summary'];
        }
        if ($this->containsAny($text, ['predict', 'hidden problems', 'preventive maintenance', 'early-stage cyber attacks', 'at risk of failure'])) {
            return ['domain' => 'advanced', 'topic' => 'predictive'];
        }
        if ($this->containsAny($text, ['what should i do', 'recommend', 'prioritize', 'critical', 'safe right now', 'worry'])) {
            return ['domain' => 'recommendation', 'topic' => 'next_steps'];
        }
        if ($this->containsAny($text, ['root cause', 'timeline', 'caused this', 'triggered', 'impact', 'fix this problem', 'device crash'])) {
            return ['domain' => 'incident', 'topic' => 'root_cause'];
        }
        if ($this->containsAny($text, [
            'not compliant',
            'non compliant',
            'non-compliant',
            'noncompliant',
            'policy violation',
            'bitlocker',
            'firewall',
            'usb restrictions',
            'compliance',
        ])) {
            return ['domain' => 'compliance', 'topic' => 'status'];
        }
        if ($this->containsAny($text, ['unusual login times', 'unusual login time', 'off-hours login', 'off hours login', 'outside working hours', 'outside business hours'])) {
            return ['domain' => 'user', 'topic' => 'off_hours'];
        }
        if ($this->containsAny($text, ['logged in', 'login history', 'inactive users', 'shared devices', 'session'])) {
            return ['domain' => 'user', 'topic' => 'activity'];
        }
        if ($this->containsAny($text, ['offline', 'not reachable', 'network issues', 'dns', 'ip address', 'connectivity', 'connection failures'])) {
            return ['domain' => 'network', 'topic' => 'status'];
        }
        if ($this->containsAny($text, ['missing updates', 'windows updates', 'critical patches', 'patch compliance', 'reboot after update', 'update success'])) {
            return ['domain' => 'patch', 'topic' => 'status'];
        }
        if ($this->containsAny($text, ['outdated software', 'outdated applications', 'which devices have outdated'])) {
            return ['domain' => 'software', 'topic' => 'outdated'];
        }
        if ($this->containsAny($text, ['unauthorized software', 'unauthorized application'])) {
            return ['domain' => 'software', 'topic' => 'unauthorized'];
        }
        if ($this->containsAny($text, ['recently installed', 'apps installed today', 'installed today'])) {
            return ['domain' => 'software', 'topic' => 'recent'];
        }
        if ($this->containsAny($text, ['installed software', 'software inventory', 'list software', 'list installed'])) {
            return ['domain' => 'software', 'topic' => 'inventory'];
        }
        if ($this->containsAny($text, ['software'])) {
            return ['domain' => 'software', 'topic' => 'inventory'];
        }
        if ($this->containsAny($text, ['multiple failed logins', 'failed login attempts', 'failed logins', 'failed login'])) {
            return ['domain' => 'security', 'topic' => 'failed_logins'];
        }
        if ($this->containsAny($text, ['usb storage', 'using usb', 'usb usage', 'removable storage', 'removable media'])) {
            return ['domain' => 'security', 'topic' => 'usb_activity'];
        }
        if ($this->containsAny($text, ['remote access', 'accessed remotely', 'remote login'])) {
            return ['domain' => 'security', 'topic' => 'remote_access'];
        }
        if ($this->containsAny($text, ['new admin account', 'admin account created', 'admin user created', 'created admin account', 'new local admin'])) {
            return ['domain' => 'security', 'topic' => 'admin_account_created'];
        }
        if ($this->containsAny($text, ['antivirus disabled', 'antivirus off', 'defender disabled', 'without antivirus'])) {
            return ['domain' => 'security', 'topic' => 'antivirus_disabled'];
        }
        if ($this->containsAny($text, ['suspicious activity', 'admin account', 'malware'])) {
            return ['domain' => 'security', 'topic' => 'risk'];
        }
        if ($this->containsAny($text, [
            'abnormal',
            'anomal',
            'changed behavior',
            'pattern normal',
            'risk of failure',
            'behaving differently',
            'behave differently',
            'different from others',
            'unusual about this device',
            'flagged as anomalous',
            'flagged anomalous',
            'compare this device to similar devices',
            'what caused this anomaly',
        ])) {
            return ['domain' => 'anomaly', 'topic' => 'risk'];
        }
        if ($this->containsAny($text, ['high cpu', 'cpu usage', 'cpu load'])) {
            return ['domain' => 'health', 'topic' => 'high_cpu'];
        }
        if ($this->containsAny($text, ['running out of memory', 'high memory', 'memory usage', 'ram usage'])) {
            return ['domain' => 'health', 'topic' => 'high_memory'];
        }
        if ($this->containsAny($text, ['low disk', 'disk space'])) {
            return ['domain' => 'health', 'topic' => 'low_disk'];
        }
        if ($this->containsAny($text, ['not checked in today', 'have not checked in today', 'checked in today'])) {
            return ['domain' => 'health', 'topic' => 'not_checked_in_today'];
        }
        if ($this->containsAny($text, ['need a restart', 'need restart', 'needs restart', 'pending restart'])) {
            return ['domain' => 'health', 'topic' => 'need_restart'];
        }
        if ($this->containsAny($text, ['overheating', 'overheat', 'high temperature'])) {
            return ['domain' => 'health', 'topic' => 'overheating'];
        }
        if ($this->containsAny($text, ['unhealthy', 'high cpu', 'slow', 'memory', 'low disk', 'checked in today', 'restart', 'crashes', 'overheating', 'health'])) {
            return ['domain' => 'health', 'topic' => 'status'];
        }

        return ['domain' => 'general', 'topic' => 'overview'];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array{
     *   query:string,
     *   scope:string,
     *   device:?array{id:string,hostname:string,status:string},
     *   group:?array{id:string,name:string,device_ids:list<string>},
     *   group_error:string,
     *   group_matches:list<array{id:string,name:string}>
     * }
     */
    private function resolveTarget(string $instruction, array $plan): array
    {
        $query = trim((string) ($plan['target_query'] ?? ''));
        $targetType = mb_strtolower(trim((string) ($plan['target_type'] ?? 'device')));
        if (! in_array($targetType, ['device', 'group'], true)) {
            $targetType = 'device';
        }

        $groupQuery = '';
        if ($targetType === 'group' && $query !== '' && ! $this->isAllScopeQuery($query)) {
            $groupQuery = $query;
        }
        if ($groupQuery === '') {
            $groupQuery = $this->extractGroupQuery($instruction);
        }

        if ($groupQuery !== '') {
            $groupResolution = $this->resolveGroupTarget($groupQuery);
            if ((bool) ($groupResolution['ok'] ?? false)) {
                $groupId = (string) ($groupResolution['id'] ?? '');
                $groupName = (string) ($groupResolution['name'] ?? $groupQuery);
                $deviceIds = DB::table('device_group_memberships')
                    ->where('device_group_id', $groupId)
                    ->pluck('device_id')
                    ->map(fn ($id): string => (string) $id)
                    ->values()
                    ->all();

                return [
                    'query' => $groupName,
                    'scope' => 'group',
                    'device' => null,
                    'group' => [
                        'id' => $groupId,
                        'name' => $groupName,
                        'device_ids' => $deviceIds,
                    ],
                    'group_error' => '',
                    'group_matches' => [],
                ];
            }

            return [
                'query' => $groupQuery,
                'scope' => 'group',
                'device' => null,
                'group' => null,
                'group_error' => (string) ($groupResolution['error'] ?? 'I could not resolve that group.'),
                'group_matches' => is_array($groupResolution['matches'] ?? null) ? $groupResolution['matches'] : [],
            ];
        }

        if ($query === '') {
            if (preg_match('/(?:device|host|hostname|computer)\s+([a-z0-9._\-]{2,}|[a-f0-9\-]{36})/i', $instruction, $m) === 1) {
                $query = trim((string) ($m[1] ?? ''));
            } elseif (preg_match('/(?:status|of|for|on)\s+([a-z0-9._\-]{2,}|[a-f0-9\-]{36})\s*$/i', $instruction, $m) === 1) {
                $query = trim((string) ($m[1] ?? ''));
            } elseif (preg_match('/(?:anomal(?:y|ies)|incident|issue|problem|root\s*cause|timeline|triggered|caused)\s+(?:on|for|of)?\s*([a-z0-9._\-]{2,}|[a-f0-9\-]{36})\s*$/i', $instruction, $m) === 1) {
                $query = trim((string) ($m[1] ?? ''));
            } elseif (
                preg_match('/\b(anomal(?:y|ies)|incident|issue|problem|root\s*cause|timeline|triggered|caused)\b/i', $instruction) === 1
                && preg_match('/\b([a-z][a-z0-9._\-]{4,}\d[a-z0-9._\-]*|[a-z0-9._\-]*-[a-z0-9._\-]{2,}|[a-f0-9\-]{36})\s*$/i', $instruction, $m) === 1
            ) {
                $query = trim((string) ($m[1] ?? ''));
            }
        }

        $device = null;
        if ($query !== '' && ! in_array(mb_strtolower($query), ['all', 'every', '*'], true)) {
            $found = Device::query()
                ->whereRaw('LOWER(hostname)=?', [mb_strtolower($query)])
                ->orWhere('id', $query)
                ->orWhere('hostname', 'like', '%'.$query.'%')
                ->orderBy('hostname')
                ->first();
            if ($found) {
                $device = [
                    'id' => (string) $found->id,
                    'hostname' => (string) ($found->hostname ?? ''),
                    'status' => (string) ($found->status ?? ''),
                ];
            }
        }

        return [
            'query' => $query,
            'scope' => is_array($device) ? 'device' : 'fleet',
            'device' => $device,
            'group' => null,
            'group_error' => '',
            'group_matches' => [],
        ];
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function health(string $topic, array $target): array
    {
        $devices = $this->scopedDevices($this->deviceSnapshot(), $target);
        $unhealthy = $devices->filter(function (array $d): bool {
            return $d['status'] !== 'online'
                || ($d['cpu'] ?? 0) >= 85
                || ($d['memory'] ?? 0) >= 85
                || ($d['disk_free'] ?? 100) <= 15
                || ($d['temp'] ?? 0) >= 80
                || (($d['last_seen_at'] instanceof CarbonInterface) && $d['last_seen_at']->lt(now()->subDay()));
        })->values();
        $highCpu = $devices->where('cpu', '>=', 85)->values();
        $highMemory = $devices->where('memory', '>=', 85)->values();
        $lowDisk = $devices->where('disk_free', '<=', 15)->values();
        $needRestart = $devices->where('pending_restart', true)->values();
        $overheating = $devices->where('temp', '>=', 80)->values();
        $notCheckedInToday = $devices->filter(function (array $d): bool {
            $seen = $d['last_seen_at'] ?? null;
            return ! ($seen instanceof CarbonInterface) || $seen->lt(now()->startOfDay());
        })->values();
        $staleByWindow = null;

        $focus = $unhealthy;
        $summary = $unhealthy->isEmpty() ? 'No unhealthy devices right now.' : $unhealthy->count().' devices are unhealthy or degraded.';
        if (str_starts_with($topic, 'not_checked_in_window:')) {
            $window = max(1, min(10080, (int) trim((string) str_replace('not_checked_in_window:', '', $topic))));
            $staleByWindow = $devices->filter(function (array $d) use ($window): bool {
                $seen = $d['last_seen_at'] ?? null;
                return ! ($seen instanceof CarbonInterface) || $seen->lt(now()->subMinutes($window));
            })->values();

            $focus = $staleByWindow;
            $unit = $window === 1 ? 'minute' : 'minutes';
            $summary = $staleByWindow->isEmpty()
                ? 'All devices checked in within the last '.$window.' '.$unit.'.'
                : $staleByWindow->count().' devices have not checked in within the last '.$window.' '.$unit.'.';
        } elseif ($topic === 'high_cpu') {
            $focus = $highCpu;
            $summary = $highCpu->isEmpty() ? 'No devices with high CPU usage right now.' : $highCpu->count().' devices have high CPU usage.';
        } elseif ($topic === 'high_memory') {
            $focus = $highMemory;
            $summary = $highMemory->isEmpty() ? 'No devices are running high memory usage right now.' : $highMemory->count().' devices are running high memory usage.';
        } elseif ($topic === 'low_disk') {
            $focus = $lowDisk;
            $summary = $lowDisk->isEmpty() ? 'No devices are low on disk space right now.' : $lowDisk->count().' devices are low on disk space.';
        } elseif ($topic === 'not_checked_in_today') {
            $focus = $notCheckedInToday;
            $summary = $notCheckedInToday->isEmpty() ? 'All devices checked in today.' : $notCheckedInToday->count().' devices have not checked in today.';
        } elseif ($topic === 'need_restart') {
            $focus = $needRestart;
            $summary = $needRestart->isEmpty() ? 'No devices currently need restart.' : $needRestart->count().' devices need restart.';
        } elseif ($topic === 'overheating') {
            $focus = $overheating;
            $summary = $overheating->isEmpty() ? 'No overheating devices right now.' : $overheating->count().' devices are overheating.';
        }
        if (($target['scope'] ?? '') === 'device' && is_array($target['device'] ?? null)) {
            $focus = $devices->where('id', (string) $target['device']['id'])->values();
        }

        return $this->result(
            'health',
            $topic,
            'Device Health & Status',
            $summary,
            [
                ['label' => 'Total devices', 'value' => (string) $devices->count()],
                ['label' => 'Unhealthy', 'value' => (string) $unhealthy->count()],
                ['label' => 'High CPU', 'value' => (string) $highCpu->count()],
                ['label' => 'High memory', 'value' => (string) $highMemory->count()],
                ['label' => 'Low disk', 'value' => (string) $lowDisk->count()],
                ['label' => 'Need restart', 'value' => (string) $needRestart->count()],
                ['label' => 'Not checked in today', 'value' => (string) $notCheckedInToday->count()],
                ['label' => 'Stale in custom window', 'value' => (string) (($staleByWindow instanceof Collection) ? $staleByWindow->count() : 0)],
            ],
            $this->deviceItems($focus, 12),
            [
                'Prioritize devices with high CPU/memory and low disk for immediate cleanup.',
                'Queue controlled restart for devices reporting pending restart.',
                'Investigate stale/offline devices that have not checked in today.',
            ],
            []
        );
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function anomaly(string $topic, array $target): array
    {
        $cases = BehaviorAnomalyCase::query()
            ->where('detected_at', '>=', now()->subDay())
            ->orderByDesc('risk_score')
            ->limit(100)
            ->get();

        if (($target['scope'] ?? '') === 'device' && is_array($target['device'] ?? null)) {
            $cases = BehaviorAnomalyCase::query()
                ->where('device_id', (string) $target['device']['id'])
                ->orderByDesc('detected_at')
                ->limit(30)
                ->get();
        }

        $high = $cases->filter(fn (BehaviorAnomalyCase $c): bool => ((float) $c->risk_score) >= 0.85);
        $drift = DeviceBehaviorDriftEvent::query()->where('detected_at', '>=', now()->startOfDay())->count();

        return $this->result(
            'anomaly',
            $topic,
            'Anomaly Detection & AI Insights',
            $cases->isEmpty() ? 'No anomalies detected for this scope.' : $cases->count().' anomalies found ('.$high->count().' high-risk).',
            [
                ['label' => 'Anomalies (24h)', 'value' => (string) $cases->count()],
                ['label' => 'High risk', 'value' => (string) $high->count()],
                ['label' => 'Behavior drifts today', 'value' => (string) $drift],
            ],
            $cases->take(12)->map(function (BehaviorAnomalyCase $c): array {
                return [
                    'label' => (string) ($c->device_id ?? 'unknown-device'),
                    'detail' => 'Risk '.round((float) $c->risk_score * 100, 1).'% | '.(string) ($c->severity ?? 'unknown').' | '.(string) ($c->summary ?? ''),
                    'severity' => $this->severity((float) $c->risk_score),
                ];
            })->values()->all(),
            [
                'Investigate high-risk anomalies first and correlate with job failures.',
                'Review behavior drift spikes as early warning for instability or attacks.',
            ],
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function security(string $topic): array
    {
        $logs = $this->behaviorLogs(72);
        $failedLogin = $logs->filter(fn (array $e): bool => $this->isFailedLogin($e));
        $usb = $logs->filter(fn (array $e): bool => $this->containsAny($this->eventText($e), ['usb', 'removable']));
        $remote = $logs->filter(fn (array $e): bool => $this->containsAny($this->eventText($e), ['mstsc', 'rdp', 'teamviewer', 'anydesk', 'vnc', 'remote']));
        $adminCreated = $logs->filter(fn (array $e): bool => $this->isAdminAccountCreationEvent($e));

        $deviceSnapshot = $this->deviceSnapshot();
        $weak = $deviceSnapshot->filter(function (array $d): bool {
            return $d['antivirus'] === false || $d['firewall'] === false || $d['bitlocker'] === false;
        })->values();
        $antivirusDisabledDevices = $deviceSnapshot->filter(fn (array $d): bool => $d['antivirus'] === false)->values();
        $nonCompliant = $this->latestCompliance()->whereIn('status', ['non_compliant', 'error'])->count();

        if ($topic === 'failed_logins') {
            $grouped = $failedLogin
                ->groupBy('device_id')
                ->map(function (Collection $rows): array {
                    $first = $rows->first();

                    return [
                        'device_id' => (string) ($first['device_id'] ?? ''),
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $rows->count(),
                        'latest_at' => $rows
                            ->map(fn (array $row): ?CarbonInterface => (($row['occurred_at'] ?? null) instanceof CarbonInterface ? $row['occurred_at'] : null))
                            ->filter()
                            ->sortDesc()
                            ->first(),
                    ];
                })
                ->values();
            $multiple = $grouped->filter(fn (array $row): bool => ((int) ($row['count'] ?? 0)) >= 2)->values();

            return $this->result(
                'security',
                $topic,
                'Security & Threat Detection',
                $multiple->isEmpty()
                    ? 'No devices had multiple failed logins in the last 72 hours.'
                    : $multiple->count().' devices had multiple failed logins in the last 72 hours.',
                [
                    ['label' => 'Failed login attempts (72h)', 'value' => (string) $failedLogin->count()],
                    ['label' => 'Devices with failed logins', 'value' => (string) $grouped->count()],
                    ['label' => 'Devices with multiple failed logins', 'value' => (string) $multiple->count()],
                ],
                $multiple->sortByDesc('count')->take(12)->map(function (array $row): array {
                    $latest = ($row['latest_at'] ?? null) instanceof CarbonInterface
                        ? $row['latest_at']->toDateTimeString()
                        : 'unknown-time';

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => (int) ($row['count'] ?? 0).' failed login attempt(s) | latest '.$latest,
                        'severity' => ((int) ($row['count'] ?? 0)) >= 5 ? 'high' : 'medium',
                    ];
                })->values()->all(),
                [
                    'Investigate repeated failed login bursts per device and correlate with source IP/user.',
                    'Lock down remote access and enforce account lockout/MFA where possible.',
                ],
                []
            );
        }

        if ($topic === 'usb_activity') {
            $grouped = $usb
                ->groupBy('device_id')
                ->map(function (Collection $rows): array {
                    $first = $rows->first();

                    return [
                        'device_id' => (string) ($first['device_id'] ?? ''),
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $rows->count(),
                        'latest_at' => $rows
                            ->map(fn (array $row): ?CarbonInterface => (($row['occurred_at'] ?? null) instanceof CarbonInterface ? $row['occurred_at'] : null))
                            ->filter()
                            ->sortDesc()
                            ->first(),
                    ];
                })
                ->values();

            return $this->result(
                'security',
                $topic,
                'Security & Threat Detection',
                $grouped->isEmpty()
                    ? 'No devices are using USB storage in the last 72 hours.'
                    : $grouped->count().' devices show USB storage activity in the last 72 hours.',
                [
                    ['label' => 'USB activity events (72h)', 'value' => (string) $usb->count()],
                    ['label' => 'Devices with USB activity', 'value' => (string) $grouped->count()],
                    ['label' => 'Failed logins (72h)', 'value' => (string) $failedLogin->count()],
                    ['label' => 'Non-compliant devices', 'value' => (string) $nonCompliant],
                ],
                $grouped->sortByDesc('count')->take(12)->map(function (array $row): array {
                    $latest = ($row['latest_at'] ?? null) instanceof CarbonInterface
                        ? $row['latest_at']->toDateTimeString()
                        : 'unknown-time';

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => (int) ($row['count'] ?? 0).' usb/removable event(s) | latest '.$latest,
                        'severity' => ((int) ($row['count'] ?? 0)) >= 5 ? 'high' : 'medium',
                    ];
                })->values()->all(),
                [
                    'Review removable-storage usage against policy and user role.',
                    'Apply USB restriction policy on devices with unexpected USB activity.',
                ],
                []
            );
        }

        if ($topic === 'remote_access') {
            $grouped = $remote
                ->groupBy('device_id')
                ->map(function (Collection $rows): array {
                    $first = $rows->first();

                    return [
                        'device_id' => (string) ($first['device_id'] ?? ''),
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $rows->count(),
                        'latest_at' => $rows
                            ->map(fn (array $row): ?CarbonInterface => (($row['occurred_at'] ?? null) instanceof CarbonInterface ? $row['occurred_at'] : null))
                            ->filter()
                            ->sortDesc()
                            ->first(),
                    ];
                })
                ->values();

            return $this->result(
                'security',
                $topic,
                'Security & Threat Detection',
                $grouped->isEmpty()
                    ? 'No remote-access activity detected in the last 72 hours.'
                    : $grouped->count().' devices show remote-access activity in the last 72 hours.',
                [
                    ['label' => 'Remote access events (72h)', 'value' => (string) $remote->count()],
                    ['label' => 'Devices with remote access', 'value' => (string) $grouped->count()],
                    ['label' => 'Failed logins (72h)', 'value' => (string) $failedLogin->count()],
                    ['label' => 'Non-compliant devices', 'value' => (string) $nonCompliant],
                ],
                $grouped->sortByDesc('count')->take(12)->map(function (array $row): array {
                    $latest = ($row['latest_at'] ?? null) instanceof CarbonInterface
                        ? $row['latest_at']->toDateTimeString()
                        : 'unknown-time';

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => (int) ($row['count'] ?? 0).' remote-access event(s) | latest '.$latest,
                        'severity' => ((int) ($row['count'] ?? 0)) >= 5 ? 'high' : 'medium',
                    ];
                })->values()->all(),
                [
                    'Validate remote-access sessions against approved support windows.',
                    'Require MFA and source-IP allowlisting for remote access channels.',
                ],
                []
            );
        }

        if ($topic === 'admin_account_created') {
            $grouped = $adminCreated
                ->groupBy('device_id')
                ->map(function (Collection $rows): array {
                    $first = $rows->first();
                    $latest = $rows
                        ->map(fn (array $row): ?CarbonInterface => (($row['occurred_at'] ?? null) instanceof CarbonInterface ? $row['occurred_at'] : null))
                        ->filter()
                        ->sortDesc()
                        ->first();
                    $accounts = $rows
                        ->map(fn (array $row): string => $this->adminAccountName($row))
                        ->filter(fn (string $name): bool => $name !== '')
                        ->unique()
                        ->values();

                    return [
                        'device_id' => (string) ($first['device_id'] ?? ''),
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $rows->count(),
                        'latest_at' => $latest,
                        'accounts' => $accounts->all(),
                    ];
                })
                ->values();

            return $this->result(
                'security',
                $topic,
                'Security & Threat Detection',
                $grouped->isEmpty()
                    ? 'No new admin account creation events detected in the last 72 hours.'
                    : $grouped->count().' devices show new admin account creation events in the last 72 hours.',
                [
                    ['label' => 'Admin account creation events (72h)', 'value' => (string) $adminCreated->count()],
                    ['label' => 'Devices with admin account creations', 'value' => (string) $grouped->count()],
                    ['label' => 'Non-compliant devices', 'value' => (string) $nonCompliant],
                ],
                $grouped->sortByDesc('count')->take(12)->map(function (array $row): array {
                    $latest = ($row['latest_at'] ?? null) instanceof CarbonInterface
                        ? $row['latest_at']->toDateTimeString()
                        : 'unknown-time';
                    $accounts = is_array($row['accounts'] ?? null) ? $row['accounts'] : [];
                    $accountText = $accounts !== [] ? ' | accounts: '.implode(', ', array_slice($accounts, 0, 3)) : '';

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => (int) ($row['count'] ?? 0).' admin-account event(s) | latest '.$latest.$accountText,
                        'severity' => 'high',
                    ];
                })->values()->all(),
                [
                    'Validate each new admin account against approved change records.',
                    'Remove unauthorized admin memberships and rotate credentials immediately.',
                ],
                []
            );
        }

        if ($topic === 'antivirus_disabled') {
            return $this->result(
                'security',
                $topic,
                'Security & Threat Detection',
                $antivirusDisabledDevices->isEmpty()
                    ? 'No devices have antivirus disabled right now.'
                    : $antivirusDisabledDevices->count().' devices have antivirus disabled right now.',
                [
                    ['label' => 'Devices with antivirus disabled', 'value' => (string) $antivirusDisabledDevices->count()],
                    ['label' => 'Weak security settings', 'value' => (string) $weak->count()],
                    ['label' => 'Non-compliant devices', 'value' => (string) $nonCompliant],
                ],
                $this->deviceItems($antivirusDisabledDevices, 12),
                [
                    'Re-enable endpoint antivirus and trigger an immediate threat-definition update.',
                    'Run quick scans on affected devices and rerun compliance checks.',
                ],
                []
            );
        }

        return $this->result(
            'security',
            $topic,
            'Security & Threat Detection',
            'Security scan complete: '.$failedLogin->count().' failed logins, '.$weak->count().' weak-security devices, '.$nonCompliant.' non-compliant devices.',
            [
                ['label' => 'Failed logins (72h)', 'value' => (string) $failedLogin->count()],
                ['label' => 'USB activity (72h)', 'value' => (string) $usb->count()],
                ['label' => 'Remote access events (72h)', 'value' => (string) $remote->count()],
                ['label' => 'Weak security settings', 'value' => (string) $weak->count()],
                ['label' => 'Non-compliant devices', 'value' => (string) $nonCompliant],
            ],
            $failedLogin->take(10)->map(function (array $e): array {
                return [
                    'label' => (string) ($e['hostname'] ?? $e['device_id'] ?? 'unknown-device'),
                    'detail' => ($e['occurred_at'] instanceof CarbonInterface ? $e['occurred_at']->toDateTimeString() : 'unknown-time').' | failed login',
                    'severity' => 'high',
                ];
            })->values()->all(),
            [
                'Review failed-login bursts and remote-access overlap for possible compromise.',
                'Re-enable antivirus/firewall/BitLocker where disabled and rerun compliance.',
            ],
            []
        );
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function software(string $topic, array $target): array
    {
        $devices = $this->deviceSnapshot();
        $rows = collect();
        foreach ($devices as $d) {
            foreach ($this->softwareRows($d) as $s) {
                $rows->push($s + ['device_id' => $d['id'], 'hostname' => $d['hostname']]);
            }
        }
        if (($target['scope'] ?? '') === 'device' && is_array($target['device'] ?? null)) {
            $rows = $rows->where('device_id', (string) $target['device']['id'])->values();
        }

        $outdated = $rows->where('outdated', true)->count();
        $unauthorized = $rows->where('unauthorized', true)->count();
        $installedToday = $rows->filter(fn (array $r): bool => ($r['installed_at'] instanceof CarbonInterface) && $r['installed_at']->isToday())->count();
        $outdatedRows = $rows->where('outdated', true)->values();
        $unauthorizedRows = $rows->where('unauthorized', true)->values();
        $installedTodayRows = $rows->filter(fn (array $r): bool => ($r['installed_at'] instanceof CarbonInterface) && $r['installed_at']->isToday())->values();

        if ($topic === 'outdated') {
            $byDevice = $outdatedRows
                ->groupBy('device_id')
                ->map(function (Collection $set): array {
                    $first = $set->first();
                    $sampleApps = $set->pluck('name')->filter()->unique()->take(3)->values()->all();

                    return [
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $set->count(),
                        'samples' => $sampleApps,
                    ];
                })
                ->sortByDesc('count')
                ->values();

            return $this->result(
                'software',
                $topic,
                'Software & Application Management',
                $byDevice->isEmpty()
                    ? 'No devices have outdated software right now.'
                    : $byDevice->count().' devices have outdated software.',
                [
                    ['label' => 'Devices with outdated software', 'value' => (string) $byDevice->count()],
                    ['label' => 'Outdated software records', 'value' => (string) $outdatedRows->count()],
                    ['label' => 'Unauthorized software', 'value' => (string) $unauthorized],
                    ['label' => 'Installed today', 'value' => (string) $installedToday],
                ],
                $byDevice->take(12)->map(function (array $row): array {
                    $samples = is_array($row['samples'] ?? null) ? $row['samples'] : [];
                    $detail = (string) ($row['count'] ?? 0).' outdated app(s)';
                    if ($samples !== []) {
                        $detail .= ' | '.implode(', ', array_map(fn ($name): string => (string) $name, $samples));
                    }

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => $detail,
                        'severity' => ((int) ($row['count'] ?? 0)) >= 10 ? 'high' : 'medium',
                    ];
                })->values()->all(),
                [
                    'Patch devices with the highest outdated-software counts first.',
                    'Update outdated software and remove unauthorized apps on high-risk endpoints.',
                ],
                []
            );
        }

        if ($topic === 'unauthorized') {
            $byDevice = $unauthorizedRows
                ->groupBy('device_id')
                ->map(function (Collection $set): array {
                    $first = $set->first();
                    $sampleApps = $set->pluck('name')->filter()->unique()->take(3)->values()->all();

                    return [
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $set->count(),
                        'samples' => $sampleApps,
                    ];
                })
                ->sortByDesc('count')
                ->values();

            return $this->result(
                'software',
                $topic,
                'Software & Application Management',
                $byDevice->isEmpty()
                    ? 'No unauthorized software detected right now.'
                    : $byDevice->count().' devices have unauthorized software.',
                [
                    ['label' => 'Devices with unauthorized software', 'value' => (string) $byDevice->count()],
                    ['label' => 'Unauthorized software records', 'value' => (string) $unauthorizedRows->count()],
                    ['label' => 'Outdated software', 'value' => (string) $outdated],
                    ['label' => 'Installed today', 'value' => (string) $installedToday],
                ],
                $byDevice->take(12)->map(function (array $row): array {
                    $samples = is_array($row['samples'] ?? null) ? $row['samples'] : [];
                    $detail = (string) ($row['count'] ?? 0).' unauthorized app(s)';
                    if ($samples !== []) {
                        $detail .= ' | '.implode(', ', array_map(fn ($name): string => (string) $name, $samples));
                    }

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => $detail,
                        'severity' => 'high',
                    ];
                })->values()->all(),
                [
                    'Remove unauthorized software and review allow-list policy assignments.',
                ],
                []
            );
        }

        if ($topic === 'recent') {
            $byDevice = $installedTodayRows
                ->groupBy('device_id')
                ->map(function (Collection $set): array {
                    $first = $set->first();
                    $latest = $set
                        ->map(fn (array $row): ?CarbonInterface => (($row['installed_at'] ?? null) instanceof CarbonInterface ? $row['installed_at'] : null))
                        ->filter()
                        ->sortDesc()
                        ->first();

                    return [
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $set->count(),
                        'latest_at' => $latest,
                    ];
                })
                ->sortByDesc('count')
                ->values();

            return $this->result(
                'software',
                $topic,
                'Software & Application Management',
                $byDevice->isEmpty()
                    ? 'No software was installed today.'
                    : $byDevice->count().' devices installed software today.',
                [
                    ['label' => 'Devices with installs today', 'value' => (string) $byDevice->count()],
                    ['label' => 'Installed today', 'value' => (string) $installedTodayRows->count()],
                    ['label' => 'Outdated software', 'value' => (string) $outdated],
                    ['label' => 'Unauthorized software', 'value' => (string) $unauthorized],
                ],
                $byDevice->take(12)->map(function (array $row): array {
                    $latest = ($row['latest_at'] ?? null) instanceof CarbonInterface
                        ? $row['latest_at']->toDateTimeString()
                        : 'unknown-time';

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => (string) ($row['count'] ?? 0).' app install(s) today | latest '.$latest,
                        'severity' => 'low',
                    ];
                })->values()->all(),
                [
                    'Validate newly installed software against approved software policy.',
                ],
                []
            );
        }

        $singleDeviceScope = ($target['scope'] ?? '') === 'device' && is_array($target['device'] ?? null);
        $targetHostname = $singleDeviceScope ? (string) ($target['device']['hostname'] ?? 'this device') : '';
        $deviceCount = $rows->pluck('device_id')->filter()->unique()->count();
        $summary = $singleDeviceScope
            ? ($rows->isEmpty()
                ? 'No installed software found on '.$targetHostname.'.'
                : 'Found '.$rows->count().' installed application'.($rows->count() === 1 ? '' : 's').' on '.$targetHostname.'.')
            : 'Processed '.$rows->count().' software records across '.$deviceCount.' device'.($deviceCount === 1 ? '' : 's').'.';

        return $this->result(
            'software',
            $topic,
            'Software & Application Management',
            $summary,
            [
                ['label' => 'Outdated software', 'value' => (string) $outdated],
                ['label' => 'Unauthorized software', 'value' => (string) $unauthorized],
                ['label' => 'Installed today', 'value' => (string) $installedToday],
                ['label' => 'Failed install jobs (7d)', 'value' => (string) DmsJob::query()->where('created_at', '>=', now()->subDays(7))->where('status', 'failed')->count()],
            ],
            $rows->take(12)->map(function (array $r) use ($singleDeviceScope): array {
                $detail = trim((string) ($r['version'] ?? '')) !== '' ? 'v'.$r['version'] : 'version unknown';
                if (($r['outdated'] ?? false) === true) {
                    $detail .= ' | outdated';
                }
                if (($r['unauthorized'] ?? false) === true) {
                    $detail .= ' | unauthorized';
                }
                return [
                    'label' => $singleDeviceScope
                        ? (string) ($r['name'] ?? 'unknown-software')
                        : ((string) ($r['hostname'] ?? 'unknown-device').' - '.(string) ($r['name'] ?? 'unknown-software')),
                    'detail' => $detail,
                    'severity' => ($r['unauthorized'] ?? false) ? 'high' : (($r['outdated'] ?? false) ? 'medium' : 'low'),
                ];
            })->values()->all(),
            [
                'Update outdated software and remove unauthorized apps first on high-risk endpoints.',
            ],
            []
        );
    }


    /**
     * @return array<string,mixed>
     */
    private function patch(string $topic): array
    {
        $devices = $this->deviceSnapshot();
        $missing = $devices->where('missing_updates', '>', 0);
        $critical = $devices->where('critical_updates', '>', 0);
        $failed = $devices->where('failed_updates', '>', 0);
        $needRestart = $devices->where('pending_restart', true);
        $successRate = $devices->count() > 0
            ? round((($devices->count() - $missing->count()) / $devices->count()) * 100, 1).'%' : '0%';

        return $this->result(
            'patch',
            $topic,
            'Patch & Update Management',
            $missing->isEmpty() ? 'All devices appear up to date.' : $missing->count().' devices are missing updates ('.$critical->count().' critical).',
            [
                ['label' => 'Missing updates', 'value' => (string) $missing->count()],
                ['label' => 'Critical patches needed', 'value' => (string) $critical->count()],
                ['label' => 'Failed updates', 'value' => (string) $failed->count()],
                ['label' => 'Need reboot after update', 'value' => (string) $needRestart->count()],
                ['label' => 'Update success rate', 'value' => $successRate],
            ],
            $this->deviceItems($critical->isNotEmpty() ? $critical : $missing, 12),
            [
                'Patch critical devices first, then remaining missing updates.',
                'Queue reboot for devices with pending restart to finalize update installation.',
            ],
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function network(string $topic): array
    {
        $devices = $this->deviceSnapshot();
        $offline = $devices->where('status', 'offline');
        $dns = $devices->where('dns_errors', '>', 0);
        $failures = $devices->where('connection_failures', '>', 0);
        $highUsage = $devices->where('network_usage', '>=', 80);
        $ipChanged = $devices->where('ip_changed', true);

        return $this->result(
            'network',
            $topic,
            'Network & Connectivity',
            $offline->isEmpty() ? 'No offline devices right now.' : $offline->count().' devices are offline and may be unreachable.',
            [
                ['label' => 'Offline', 'value' => (string) $offline->count()],
                ['label' => 'DNS problems', 'value' => (string) $dns->count()],
                ['label' => 'Connection failures', 'value' => (string) $failures->count()],
                ['label' => 'High network usage', 'value' => (string) $highUsage->count()],
                ['label' => 'IP changes detected', 'value' => (string) $ipChanged->count()],
            ],
            $this->deviceItems($offline->isNotEmpty() ? $offline : ($failures->isNotEmpty() ? $failures : $dns), 12),
            [
                'Run connectivity diagnostics on offline and high-failure devices.',
                'Validate DNS and gateway configuration on devices reporting resolver failures.',
            ],
            []
        );
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function user(string $topic, array $target): array
    {
        $logs = $this->behaviorLogs(30 * 24)->where('event_type', 'user_logon')->values();
        if (($target['scope'] ?? '') === 'device' && is_array($target['device'] ?? null)) {
            $logs = $logs->where('device_id', (string) $target['device']['id'])->values();
        }

        $today = $logs->filter(fn (array $e): bool => ($e['occurred_at'] instanceof CarbonInterface) && $e['occurred_at']->isToday());
        $failed = $logs->filter(fn (array $e): bool => $this->isFailedLogin($e));
        $offHours = $logs->filter(fn (array $e): bool => $this->isOffHours($e));
        $usersToday = $today->pluck('user_name')->filter()->unique()->count();
        $sharedDevices = $logs->groupBy('device_id')->filter(function (Collection $rows): bool {
            return $rows->pluck('user_name')->filter()->unique()->count() >= 2;
        })->count();

        if ($topic === 'off_hours') {
            $grouped = $offHours
                ->groupBy('device_id')
                ->map(function (Collection $rows): array {
                    $first = $rows->first();

                    return [
                        'device_id' => (string) ($first['device_id'] ?? ''),
                        'hostname' => (string) ($first['hostname'] ?? $first['device_id'] ?? 'unknown-device'),
                        'count' => $rows->count(),
                        'latest_at' => $rows
                            ->map(fn (array $row): ?CarbonInterface => (($row['occurred_at'] ?? null) instanceof CarbonInterface ? $row['occurred_at'] : null))
                            ->filter()
                            ->sortDesc()
                            ->first(),
                    ];
                })
                ->values();

            return $this->result(
                'user',
                $topic,
                'User Activity & Behavior',
                $grouped->isEmpty()
                    ? 'No unusual login times detected in the last 30 days.'
                    : $grouped->count().' devices have unusual/off-hours login activity.',
                [
                    ['label' => 'Off-hours logins', 'value' => (string) $offHours->count()],
                    ['label' => 'Devices with off-hours logins', 'value' => (string) $grouped->count()],
                    ['label' => 'Users logged today', 'value' => (string) $usersToday],
                    ['label' => 'Failed logins', 'value' => (string) $failed->count()],
                ],
                $grouped->sortByDesc('count')->take(12)->map(function (array $row): array {
                    $latest = ($row['latest_at'] ?? null) instanceof CarbonInterface
                        ? $row['latest_at']->toDateTimeString()
                        : 'unknown-time';

                    return [
                        'label' => (string) ($row['hostname'] ?? 'unknown-device'),
                        'detail' => (int) ($row['count'] ?? 0).' off-hours login(s) | latest '.$latest,
                        'severity' => ((int) ($row['count'] ?? 0)) >= 5 ? 'high' : 'medium',
                    ];
                })->values()->all(),
                [
                    'Review off-hours access by user role and expected schedule.',
                    'Investigate repeated off-hours logins with failed-login overlap.',
                ],
                []
            );
        }

        return $this->result(
            'user',
            $topic,
            'User Activity & Behavior',
            $today->count().' logins recorded today ('.$usersToday.' unique users).',
            [
                ['label' => 'Logins today', 'value' => (string) $today->count()],
                ['label' => 'Users logged today', 'value' => (string) $usersToday],
                ['label' => 'Failed logins', 'value' => (string) $failed->count()],
                ['label' => 'Off-hours logins', 'value' => (string) $offHours->count()],
                ['label' => 'Shared devices', 'value' => (string) $sharedDevices],
            ],
            $logs->sortByDesc('occurred_at')->take(12)->map(function (array $e): array {
                return [
                    'label' => (string) ($e['user_name'] ?: 'unknown-user').' @ '.(string) ($e['hostname'] ?: 'unknown-device'),
                    'detail' => ($e['occurred_at'] instanceof CarbonInterface) ? $e['occurred_at']->toDateTimeString() : 'unknown-time',
                    'severity' => $this->isOffHours($e) ? 'medium' : 'low',
                ];
            })->values()->all(),
            [
                'Review failed and off-hours logins for suspicious behavior.',
                'Audit shared devices for least-privilege and session hygiene.',
            ],
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function compliance(string $topic): array
    {
        $latest = $this->latestCompliance();
        $non = $latest->whereIn('status', ['non_compliant', 'error']);
        $devices = $this->deviceSnapshot();
        $weak = $devices->filter(fn (array $d): bool => $d['bitlocker'] === false || $d['firewall'] === false || $d['antivirus'] === false)->values();

        return $this->result(
            'compliance',
            $topic,
            'Policy & Compliance',
            $non->isEmpty() ? 'No non-compliant devices in latest results.' : $non->count().' devices are non-compliant or in error state.',
            [
                ['label' => 'Latest compliance rows', 'value' => (string) $latest->count()],
                ['label' => 'Non-compliant', 'value' => (string) $non->count()],
                ['label' => 'Weak security settings', 'value' => (string) $weak->count()],
                ['label' => 'Groups tracked', 'value' => (string) DeviceGroup::query()->count()],
            ],
            $non->take(10)->map(function (array $r): array {
                return [
                    'label' => (string) ($r['hostname'] ?? $r['device_id'] ?? 'unknown-device'),
                    'detail' => (string) ($r['status'] ?? 'unknown').' | '.(($r['checked_at'] instanceof CarbonInterface) ? $r['checked_at']->toDateTimeString() : 'unknown-time'),
                    'severity' => 'high',
                ];
            })->values()->all(),
            [
                'Apply remediation policy and rerun compliance checks on non-compliant devices.',
                'Enforce BitLocker/firewall/antivirus baselines where disabled.',
            ],
            [
                'group_comparison' => $this->groupComplianceSummary(),
            ]
        );
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function incident(string $topic, array $target): array
    {
        if (($target['scope'] ?? '') !== 'device' || ! is_array($target['device'] ?? null)) {
            return $this->result(
                'incident',
                $topic,
                'Incident Analysis',
                'Incident root-cause analysis needs a specific device target.',
                [],
                [],
                [],
                [],
                true,
                'Please provide exact device hostname or ID for timeline and root-cause analysis.'
            );
        }

        $deviceId = (string) ($target['device']['id'] ?? '');
        $deviceName = (string) ($target['device']['hostname'] ?? $deviceId);
        $runs = JobRun::query()->where('device_id', $deviceId)->where('created_at', '>=', now()->subDays(7))->orderByDesc('created_at')->limit(30)->get();
        $failedRuns = $runs->filter(fn (JobRun $r): bool => in_array((string) $r->status, ['failed', 'non_compliant'], true));
        $cases = BehaviorAnomalyCase::query()->where('device_id', $deviceId)->where('detected_at', '>=', now()->subDays(7))->orderByDesc('detected_at')->limit(30)->get();
        $topCase = $cases->sortByDesc('risk_score')->first();

        $rootCause = 'No clear root cause found yet.';
        if ($failedRuns->isNotEmpty()) {
            $first = $failedRuns->first();
            $rootCause = 'Likely trigger: failed job run near '.$first->created_at?->toDateTimeString().'.';
        } elseif ($topCase instanceof BehaviorAnomalyCase) {
            $rootCause = 'Likely trigger: anomaly risk '.round((float) $topCase->risk_score * 100, 1).'% at '.$topCase->detected_at?->toDateTimeString().'.';
        }

        return $this->result(
            'incident',
            $topic,
            'Incident & Root Cause: '.$deviceName,
            $rootCause,
            [
                ['label' => 'Failed job runs (7d)', 'value' => (string) $failedRuns->count()],
                ['label' => 'Anomalies (7d)', 'value' => (string) $cases->count()],
            ],
            $cases->take(10)->map(function (BehaviorAnomalyCase $c): array {
                return [
                    'label' => (string) $c->detected_at?->toDateTimeString(),
                    'detail' => 'Risk '.round((float) $c->risk_score * 100, 1).'% | '.(string) ($c->summary ?? ''),
                    'severity' => $this->severity((float) $c->risk_score),
                ];
            })->values()->all(),
            [
                'Correlate failed jobs and anomalies around the first failure timestamp.',
                'Apply targeted remediation policy and monitor this device for 24 hours.',
            ],
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $topic): array
    {
        $risk = $this->riskRanked(10);
        $critical = $risk->where('risk_score', '>=', 0.8)->count();

        return $this->result(
            'recommendation',
            $topic,
            'AI Recommendations & Decision Support',
            $critical > 0 ? $critical.' devices are critical-risk and need immediate action.' : 'No critical-risk devices right now.',
            [
                ['label' => 'Risk-ranked devices', 'value' => (string) $risk->count()],
                ['label' => 'Critical risk', 'value' => (string) $critical],
            ],
            $risk->map(function (array $r): array {
                return [
                    'label' => (string) ($r['hostname'] ?? $r['device_id'] ?? 'unknown-device'),
                    'detail' => 'Risk '.round(((float) ($r['risk_score'] ?? 0)) * 100, 1).'% | '.(string) ($r['reason'] ?? ''),
                    'severity' => $this->severity((float) ($r['risk_score'] ?? 0)),
                ];
            })->values()->all(),
            [
                'Isolate or closely monitor critical-risk endpoints.',
                'Patch/update/restart high-risk devices with known health or compliance drift.',
                'Automate recurring remediation steps with policies.',
            ],
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function reporting(string $topic, array $target): array
    {
        $devices = $this->scopedDevices($this->deviceSnapshot(), $target);
        $scopeLabel = 'all devices';
        if (($target['scope'] ?? '') === 'group' && is_array($target['group'] ?? null)) {
            $scopeLabel = 'group '.((string) ($target['group']['name'] ?? ''));
        } elseif (($target['scope'] ?? '') === 'device' && is_array($target['device'] ?? null)) {
            $scopeLabel = 'device '.((string) ($target['device']['hostname'] ?? ''));
        }

        if ($topic === 'device_count') {
            $summary = $devices->count().' devices found in '.$scopeLabel.'.';
            if (($target['scope'] ?? '') === 'group' && is_array($target['group'] ?? null)) {
                $summary = 'Group '.((string) ($target['group']['name'] ?? '')).' has '.$devices->count().' device'.($devices->count() === 1 ? '' : 's').'.';
            }

            return $this->result(
                'reporting',
                $topic,
                'Device Count',
                $summary,
                [
                    ['label' => 'Scope', 'value' => $scopeLabel],
                    ['label' => 'Total devices', 'value' => (string) $devices->count()],
                    ['label' => 'Online', 'value' => (string) $devices->where('status', 'online')->count()],
                    ['label' => 'Offline', 'value' => (string) $devices->where('status', 'offline')->count()],
                ],
                $this->deviceItems($devices, 20),
                [
                    'Ask "show device IP for HOSTNAME" to get direct connectivity details.',
                ],
                []
            );
        }

        if ($topic === 'online_count' || $topic === 'offline_count') {
            $online = $devices->where('status', 'online')->count();
            $offline = $devices->where('status', 'offline')->count();
            $wantOnline = $topic === 'online_count';
            $count = $wantOnline ? $online : $offline;

            return $this->result(
                'reporting',
                $topic,
                'Fleet Connectivity Summary',
                $count.' device'.($count === 1 ? ' is' : 's are').' '.($wantOnline ? 'online' : 'offline').'.',
                [
                    ['label' => 'Scope', 'value' => $scopeLabel],
                    ['label' => 'Online', 'value' => (string) $online],
                    ['label' => 'Offline', 'value' => (string) $offline],
                    ['label' => 'Total devices', 'value' => (string) $devices->count()],
                ],
                $this->deviceItems($devices, 20),
                [
                    'Ask "show all available devices" for names and live status.',
                ],
                []
            );
        }

        if ($topic === 'device_ips') {
            $knownIp = $devices->filter(fn (array $d): bool => trim((string) ($d['ip_address'] ?? '')) !== '')->count();
            $summary = $devices->isEmpty()
                ? 'No devices found in '.$scopeLabel.'.'
                : 'IP list for '.$scopeLabel.': '.$knownIp.' of '.$devices->count().' devices reported an IP.';

            $items = $devices->sortBy('hostname')->values()->map(function (array $d): array {
                $ip = trim((string) ($d['ip_address'] ?? ''));
                $parts = ['IP '.($ip !== '' ? $ip : 'unknown'), 'Status '.(string) ($d['status'] ?? 'unknown')];
                if (($d['last_seen_at'] ?? null) instanceof CarbonInterface) {
                    $parts[] = 'Seen '.$d['last_seen_at']->diffForHumans();
                }

                return [
                    'label' => (string) ($d['hostname'] ?? $d['id'] ?? 'unknown-device'),
                    'detail' => implode(' | ', $parts),
                    'severity' => (($d['status'] ?? '') === 'offline') ? 'medium' : 'low',
                ];
            })->all();

            return $this->result(
                'reporting',
                $topic,
                'Device IP Inventory',
                $summary,
                [
                    ['label' => 'Scope', 'value' => $scopeLabel],
                    ['label' => 'Total devices', 'value' => (string) $devices->count()],
                    ['label' => 'Devices with IP', 'value' => (string) $knownIp],
                    ['label' => 'Devices without IP', 'value' => (string) max(0, $devices->count() - $knownIp)],
                ],
                $items,
                [
                    'Reply with a hostname to see full status and interface details.',
                ],
                []
            );
        }

        if ($topic === 'device_list') {
            $items = $devices->sortBy('hostname')->values()->map(function (array $d): array {
                $parts = ['Status '.(string) ($d['status'] ?? 'unknown')];
                $ip = trim((string) ($d['ip_address'] ?? ''));
                if ($ip !== '') {
                    $parts[] = 'IP '.$ip;
                }
                if (($d['last_seen_at'] ?? null) instanceof CarbonInterface) {
                    $parts[] = 'Seen '.$d['last_seen_at']->diffForHumans();
                }

                return [
                    'label' => (string) ($d['hostname'] ?? $d['id'] ?? 'unknown-device'),
                    'detail' => implode(' | ', $parts),
                    'severity' => (($d['status'] ?? '') === 'offline') ? 'medium' : 'low',
                ];
            })->all();

            return $this->result(
                'reporting',
                $topic,
                'Device Inventory',
                $devices->isEmpty() ? 'No devices found.' : $devices->count().' devices found in '.$scopeLabel.'.',
                [
                    ['label' => 'Scope', 'value' => $scopeLabel],
                    ['label' => 'Total devices', 'value' => (string) $devices->count()],
                    ['label' => 'Online', 'value' => (string) $devices->where('status', 'online')->count()],
                    ['label' => 'Offline', 'value' => (string) $devices->where('status', 'offline')->count()],
                ],
                $items,
                [
                    'Ask "show device IP for HOSTNAME" to get direct connectivity details.',
                ],
                []
            );
        }

        $anomaly24 = BehaviorAnomalyCase::query()->where('detected_at', '>=', now()->subDay())->count();
        $failedJobs24 = DmsJob::query()->where('created_at', '>=', now()->subDay())->where('status', 'failed')->count();
        $non = $this->latestCompliance()->whereIn('status', ['non_compliant', 'error'])->count();
        $trendRef = BehaviorAnomalyCase::query()->whereBetween('detected_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()])->count();
        $trend = $trendRef > 0 ? round((($anomaly24 - $trendRef) / $trendRef) * 100, 1).'%' : ($anomaly24 > 0 ? '100%' : '0%');

        return $this->result(
            'reporting',
            $topic,
            'Reporting & Insights',
            'Executive summary generated for '.now()->toDateString().'.',
            [
                ['label' => 'Total devices', 'value' => (string) $devices->count()],
                ['label' => 'Online', 'value' => (string) $devices->where('status', 'online')->count()],
                ['label' => 'Offline', 'value' => (string) $devices->where('status', 'offline')->count()],
                ['label' => 'Anomalies (24h)', 'value' => (string) $anomaly24],
                ['label' => 'Failed jobs (24h)', 'value' => (string) $failedJobs24],
                ['label' => 'Non-compliant', 'value' => (string) $non],
                ['label' => 'Anomaly trend vs yesterday', 'value' => $trend],
            ],
            $this->riskRanked(8)->map(function (array $r): array {
                return [
                    'label' => (string) ($r['hostname'] ?? $r['device_id'] ?? 'unknown-device'),
                    'detail' => 'Risk '.round(((float) ($r['risk_score'] ?? 0)) * 100, 1).'% | '.(string) ($r['reason'] ?? ''),
                    'severity' => $this->severity((float) ($r['risk_score'] ?? 0)),
                ];
            })->values()->all(),
            [
                'Focus teams on top risk-ranked devices and failing update/compliance areas.',
            ],
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function advanced(string $topic): array
    {
        $risk = $this->riskRanked(12);

        return $this->result(
            'advanced',
            $topic,
            'Advanced AI Power',
            'Predictive risk model generated using health, anomaly, compliance, and behavior signals.',
            [
                ['label' => 'Predicted high-risk devices', 'value' => (string) $risk->where('risk_score', '>=', 0.7)->count()],
                ['label' => 'Anomalies (7d)', 'value' => (string) BehaviorAnomalyCase::query()->where('detected_at', '>=', now()->subDays(7))->count()],
                ['label' => 'Behavior drift (7d)', 'value' => (string) DeviceBehaviorDriftEvent::query()->where('detected_at', '>=', now()->subDays(7))->count()],
            ],
            $risk->map(function (array $r): array {
                return [
                    'label' => (string) ($r['hostname'] ?? $r['device_id'] ?? 'unknown-device'),
                    'detail' => 'Predicted risk '.round(((float) ($r['risk_score'] ?? 0)) * 100, 1).'% | '.(string) ($r['reason'] ?? ''),
                    'severity' => $this->severity((float) ($r['risk_score'] ?? 0)),
                ];
            })->values()->all(),
            [
                'Use predictions to schedule preventive maintenance before incidents.',
                'Feed analyst feedback into anomaly review for continuous accuracy improvement.',
            ],
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function overview(): array
    {
        $devices = $this->deviceSnapshot();
        $online = $devices->where('status', 'online')->count();
        $offline = $devices->where('status', 'offline')->count();
        $anomaly24 = BehaviorAnomalyCase::query()->where('detected_at', '>=', now()->subDay())->count();
        $non = $this->latestCompliance()->whereIn('status', ['non_compliant', 'error'])->count();

        return $this->result(
            'general',
            'overview',
            'AI Operations Overview',
            'Current posture: '.$online.' online, '.$offline.' offline, '.$anomaly24.' anomalies (24h), '.$non.' non-compliant.',
            [
                ['label' => 'Total devices', 'value' => (string) $devices->count()],
                ['label' => 'Online', 'value' => (string) $online],
                ['label' => 'Offline', 'value' => (string) $offline],
                ['label' => 'Anomalies (24h)', 'value' => (string) $anomaly24],
                ['label' => 'Non-compliant', 'value' => (string) $non],
            ],
            $this->riskRanked(8)->map(function (array $r): array {
                return [
                    'label' => (string) ($r['hostname'] ?? $r['device_id'] ?? 'unknown-device'),
                    'detail' => 'Risk '.round(((float) ($r['risk_score'] ?? 0)) * 100, 1).'% | '.(string) ($r['reason'] ?? ''),
                    'severity' => $this->severity((float) ($r['risk_score'] ?? 0)),
                ];
            })->values()->all(),
            [
                'Ask specific device/group questions for deeper analysis.',
            ],
            []
        );
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function deviceSnapshot(): Collection
    {
        return Device::query()->orderBy('hostname')->get()->map(function (Device $device): array {
            $tags = is_array($device->tags) ? $device->tags : [];
            $runtime = is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : [];
            $inventory = is_array($tags['inventory'] ?? null) ? $tags['inventory'] : [];

            $cpu = $this->number($runtime, ['cpu_usage_percent', 'cpu_percent', 'cpu_usage', 'cpu.load_percent']);
            $mem = $this->number($runtime, ['memory_usage_percent', 'memory_percent', 'ram_usage_percent']);
            $disk = $this->number($runtime, ['disk_free_percent', 'disk.free_percent', 'storage.free_percent']);
            if ($disk === null) {
                $disk = $this->number($inventory, ['disk_free_percent', 'storage.free_percent']);
            }

            $ip = $this->text($runtime, ['ip_address', 'network.ip', 'network.primary_ip']);
            $oldIp = $this->text($runtime, ['previous_ip_address', 'network.previous_ip']);
            $effectiveStatus = $this->effectiveDeviceStatus($device);

            return [
                'id' => (string) $device->id,
                'hostname' => (string) ($device->hostname ?? ''),
                'status' => $effectiveStatus,
                'last_seen_at' => $device->last_seen_at,
                'ip_address' => $ip,
                'cpu' => $cpu,
                'memory' => $mem,
                'disk_free' => $disk,
                'temp' => $this->number($runtime, ['cpu_temperature_celsius', 'cpu_temp_c', 'temperature_c']),
                'network_usage' => $this->number($runtime, ['network_usage_percent', 'network.utilization_percent']),
                'pending_restart' => $this->bool($runtime, ['pending_restart', 'reboot_required', 'windows_update_reboot_required']) ?? false,
                'missing_updates' => (int) round($this->number($inventory, ['missing_updates', 'updates.missing_count']) ?? 0),
                'critical_updates' => (int) round($this->number($inventory, ['critical_updates_missing', 'updates.critical_missing_count']) ?? 0),
                'failed_updates' => (int) round($this->number($inventory, ['failed_updates', 'updates.failed_count']) ?? 0),
                'dns_errors' => (int) round($this->number($runtime, ['dns_error_count', 'dns.errors']) ?? 0),
                'connection_failures' => (int) round($this->number($runtime, ['connection_failures', 'network.connection_failures']) ?? 0),
                'antivirus' => $this->bool($runtime, ['antivirus_enabled', 'security.antivirus_enabled', 'defender.enabled']),
                'firewall' => $this->bool($runtime, ['firewall_enabled', 'security.firewall_enabled']),
                'bitlocker' => $this->bool($runtime, ['bitlocker_enabled', 'security.bitlocker_enabled']),
                'ip_changed' => ($ip !== '' && $oldIp !== '' && $ip !== $oldIp),
                'tags' => $tags,
                'inventory' => $inventory,
            ];
        })->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function behaviorLogs(int $hours): Collection
    {
        $devices = Device::query()->get(['id', 'hostname'])->keyBy('id');
        return DeviceBehaviorLog::query()->where('occurred_at', '>=', now()->subHours($hours))->orderByDesc('occurred_at')->limit(3000)->get()
            ->map(function (DeviceBehaviorLog $log) use ($devices): array {
                $meta = is_array($log->metadata) ? $log->metadata : [];
                $host = (string) ($devices[(string) $log->device_id]->hostname ?? '');
                return [
                    'device_id' => (string) $log->device_id,
                    'hostname' => $host,
                    'event_type' => (string) ($log->event_type ?? ''),
                    'occurred_at' => $log->occurred_at,
                    'user_name' => trim((string) ($log->user_name ?? '')),
                    'process_name' => trim((string) ($log->process_name ?? '')),
                    'file_path' => trim((string) ($log->file_path ?? '')),
                    'metadata' => $meta,
                ];
            })->values();
    }

    /**
     * @return Collection<int,array{device_id:string,hostname:string,status:string,checked_at:?CarbonInterface}>
     */
    private function latestCompliance(): Collection
    {
        $rows = ComplianceResult::query()->orderByDesc('checked_at')->limit(5000)->get();
        $hostnames = Device::query()->pluck('hostname', 'id');
        $map = [];
        foreach ($rows as $row) {
            $deviceId = (string) $row->device_id;
            if (isset($map[$deviceId])) {
                continue;
            }
            $map[$deviceId] = [
                'device_id' => $deviceId,
                'hostname' => (string) ($hostnames[$deviceId] ?? $deviceId),
                'status' => (string) ($row->status ?? ''),
                'checked_at' => $row->checked_at,
            ];
        }
        return collect(array_values($map));
    }

    /**
     * @return array<string,array{name:string,total:int,non_compliant:int,rate:string}>
     */
    private function groupComplianceSummary(): array
    {
        $latest = $this->latestCompliance()->keyBy('device_id');
        $result = [];
        foreach (DeviceGroup::query()->get(['id', 'name']) as $group) {
            $ids = DB::table('device_group_memberships')->where('device_group_id', $group->id)->pluck('device_id')->map(fn ($id): string => (string) $id)->all();
            $total = count($ids);
            $bad = 0;
            foreach ($ids as $id) {
                $row = $latest->get($id);
                if (is_array($row) && in_array((string) ($row['status'] ?? ''), ['non_compliant', 'error'], true)) {
                    $bad++;
                }
            }
            $result[(string) $group->id] = [
                'name' => (string) ($group->name ?? ''),
                'total' => $total,
                'non_compliant' => $bad,
                'rate' => $total > 0 ? round(($bad / $total) * 100, 1).'%' : '0%',
            ];
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $device
     * @return list<array{name:string,version:string,outdated:bool,unauthorized:bool,installed_at:?CarbonInterface}>
     */
    private function softwareRows(array $device): array
    {
        $inventory = is_array($device['inventory'] ?? null) ? $device['inventory'] : [];
        $tags = is_array($device['tags'] ?? null) ? $device['tags'] : [];
        $src = [];
        foreach ([$inventory['software'] ?? null, $inventory['installed_software'] ?? null, $inventory['applications'] ?? null, $tags['software_inventory'] ?? null] as $set) {
            if (is_array($set)) {
                $src = array_merge($src, $set);
            }
        }

        $rows = [];
        foreach ($src as $s) {
            if (! is_array($s)) {
                continue;
            }
            $name = trim((string) ($s['name'] ?? $s['display_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'version' => trim((string) ($s['version'] ?? '')),
                'outdated' => (bool) ($s['outdated'] ?? $s['is_outdated'] ?? false),
                'unauthorized' => (bool) ($s['unauthorized'] ?? false),
                'installed_at' => $this->parseDate($s['installed_at'] ?? null),
            ];
        }
        return $rows;
    }

    /**
     * @return Collection<int,array{device_id:string,hostname:string,risk_score:float,reason:string}>
     */
    private function riskRanked(int $limit): Collection
    {
        $devices = $this->deviceSnapshot();
        $anom = BehaviorAnomalyCase::query()->where('detected_at', '>=', now()->subDays(7))->get()->groupBy('device_id');
        $compliance = $this->latestCompliance()->keyBy('device_id');

        $rows = [];
        foreach ($devices as $d) {
            $risk = 0.0;
            $reason = [];
            if (($d['status'] ?? '') !== 'online') {
                $risk += 0.22;
                $reason[] = 'offline';
            }
            if (($d['cpu'] ?? 0) >= 85) {
                $risk += 0.16;
                $reason[] = 'high_cpu';
            }
            if (($d['memory'] ?? 0) >= 85) {
                $risk += 0.14;
                $reason[] = 'high_memory';
            }
            if (($d['disk_free'] ?? 100) <= 15) {
                $risk += 0.18;
                $reason[] = 'low_disk';
            }
            if (($d['temp'] ?? 0) >= 80) {
                $risk += 0.15;
                $reason[] = 'overheat';
            }
            if (($d['pending_restart'] ?? false) === true) {
                $risk += 0.10;
                $reason[] = 'pending_restart';
            }
            $cases = $anom->get((string) $d['id']);
            if ($cases instanceof Collection && $cases->isNotEmpty()) {
                $risk += min(0.45, ((float) $cases->max('risk_score')) * 0.45);
                $reason[] = 'anomaly';
            }
            $c = $compliance->get((string) $d['id']);
            if (is_array($c) && in_array((string) ($c['status'] ?? ''), ['non_compliant', 'error'], true)) {
                $risk += 0.2;
                $reason[] = 'non_compliant';
            }
            $rows[] = [
                'device_id' => (string) $d['id'],
                'hostname' => (string) ($d['hostname'] ?? $d['id']),
                'risk_score' => max(0.0, min(1.0, round($risk, 4))),
                'reason' => implode(', ', array_slice(array_unique($reason), 0, 4)),
            ];
        }
        usort($rows, fn (array $a, array $b): int => ($b['risk_score'] <=> $a['risk_score']));
        return collect(array_slice($rows, 0, max(1, $limit)));
    }

    private function effectiveDeviceStatus(Device $device): string
    {
        $raw = mb_strtolower(trim((string) ($device->status ?? 'unknown')));
        if ($raw === 'offline') {
            return 'offline';
        }

        $isFresh = $device->last_seen_at instanceof CarbonInterface
            && $device->last_seen_at->gte(now()->subMinutes($this->onlineWindowMinutes()));

        if ($raw === 'online') {
            return $isFresh ? 'online' : 'offline';
        }

        if ($isFresh) {
            return 'online';
        }

        return $raw !== '' ? $raw : 'unknown';
    }

    private function onlineWindowMinutes(): int
    {
        $fallback = max(1, (int) config('services.openai.ai_power_online_window_minutes', 2));
        $configured = $this->settingInt('jobs.online_window_minutes', $fallback);

        return max(1, min(120, $configured));
    }

    /**
     * @param Collection<int,array<string,mixed>> $devices
     * @param array<string,mixed> $target
     * @return Collection<int,array<string,mixed>>
     */
    private function scopedDevices(Collection $devices, array $target): Collection
    {
        $scope = (string) ($target['scope'] ?? 'fleet');
        if ($scope === 'device' && is_array($target['device'] ?? null)) {
            $id = (string) ($target['device']['id'] ?? '');
            if ($id !== '') {
                return $devices->where('id', $id)->values();
            }
        }

        if ($scope === 'group' && is_array($target['group'] ?? null)) {
            $ids = is_array($target['group']['device_ids'] ?? null) ? $target['group']['device_ids'] : [];
            if ($ids === []) {
                return collect();
            }
            $idMap = array_fill_keys(array_map('strval', $ids), true);

            return $devices->filter(function (array $d) use ($idMap): bool {
                return isset($idMap[(string) ($d['id'] ?? '')]);
            })->values();
        }

        return $devices->values();
    }

    private function isAllScopeQuery(string $query): bool
    {
        $normalized = mb_strtolower(trim($query));

        return in_array($normalized, ['all', 'all devices', 'every', 'everyone', '*'], true);
    }

    private function extractGroupQuery(string $instruction): string
    {
        if (preg_match('/^\s*(?:show|list|display|give|tell|which|what(?:\s+are)?)\s+(?:all\s+)?([a-z0-9][a-z0-9._\-\s]{1,80}?)\s+group\s+(?:devices?|machines?|hosts?|pcs?|computers?)\b/i', $instruction, $m) === 1) {
            return $this->cleanGroupQuery((string) ($m[1] ?? ''));
        }
        if (preg_match('/^\s*(?:all\s+)?([a-z0-9][a-z0-9._\-\s]{1,80}?)\s+group\s+(?:devices?|machines?|hosts?|pcs?|computers?)\b/i', $instruction, $m) === 1) {
            return $this->cleanGroupQuery((string) ($m[1] ?? ''));
        }
        if (preg_match('/^\s*(?:show|list|display|give|tell)\s+(?:all\s+)?([a-z0-9][a-z0-9._\-\s]{1,80}?)\s+(?:devices?|machines?|hosts?|pcs?|computers?)\s*[?.! ]*$/i', $instruction, $m) === 1) {
            $candidate = $this->cleanGroupQuery((string) ($m[1] ?? ''));
            if (! $this->isGenericGroupQualifier($candidate)) {
                return $candidate;
            }
        }
        if (preg_match('/\bin\s+(?:the\s+)?["\']?([^"\']{2,120})["\']?\s+group\b/i', $instruction, $m) === 1) {
            return $this->cleanGroupQuery((string) ($m[1] ?? ''));
        }
        if (preg_match('/\bgroup\s+["\']?([^"\']{2,120})["\']?\b/i', $instruction, $m) === 1) {
            return $this->cleanGroupQuery((string) ($m[1] ?? ''));
        }
        if (preg_match('/\bhow\s+many\b.*\bdevices?\b.*\bin\s+(?:the\s+)?([a-z0-9][a-z0-9._\-\s]{1,80})(?:[?.!]|$)/i', $instruction, $m) === 1) {
            return $this->cleanGroupQuery((string) ($m[1] ?? ''));
        }

        return '';
    }

    private function cleanGroupQuery(string $value): string
    {
        $query = trim((string) preg_replace('/\s+/', ' ', trim($value, " \t\n\r\0\x0B\"'")));
        if ($query === '') {
            return '';
        }
        $query = trim((string) preg_replace('/\b(?:group|devices?|machines?|hosts?|pcs?|computers?)\b.*$/i', '', $query));

        $query = trim($query);
        if ($this->isGenericGroupQualifier($query)) {
            return '';
        }

        return $query;
    }

    private function isGenericGroupQualifier(string $value): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, [
            'all',
            'all available',
            'available',
            'availiable',
            'me',
            'my',
            'our',
            'we',
            'us',
            'online',
            'offline',
            'connected',
            'active',
            'current',
            'current available',
            'the',
        ], true);
    }

    /**
     * @return array{
     *   ok:bool,
     *   id?:string,
     *   name?:string,
     *   error?:string,
     *   matches?:list<array{id:string,name:string}>
     * }
     */
    private function resolveGroupTarget(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'ok' => false,
                'error' => 'No target group found in instruction.',
            ];
        }

        $exact = DeviceGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($query)])
            ->orWhere('id', $query)
            ->limit(4)
            ->get(['id', 'name']);
        if ($exact->count() === 1) {
            $group = $exact->first();

            return [
                'ok' => true,
                'id' => (string) ($group->id ?? ''),
                'name' => (string) ($group->name ?? $query),
            ];
        }

        $matches = DeviceGroup::query()
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('name', 'like', '%'.$query.'%')
                    ->orWhere('id', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name']);
        if ($matches->count() === 1) {
            $group = $matches->first();

            return [
                'ok' => true,
                'id' => (string) ($group->id ?? ''),
                'name' => (string) ($group->name ?? $query),
            ];
        }
        if ($matches->isEmpty()) {
            return [
                'ok' => false,
                'error' => 'No group matched target query: '.$query,
            ];
        }

        return [
            'ok' => false,
            'error' => 'Multiple groups matched. Use exact group name or ID.',
            'matches' => $matches->map(fn (DeviceGroup $group): array => [
                'id' => (string) $group->id,
                'name' => (string) ($group->name ?? ''),
            ])->values()->all(),
        ];
    }

    private function extractCheckinWindowMinutes(string $text): ?int
    {
        if (preg_match('/\bnot checked in\b/u', $text) !== 1) {
            return null;
        }

        if (preg_match('/\b(?:in\s+)?(\d+)\s*(m|min|mins|minute|minutes)\s*(?:ago)?\b/u', $text, $m) === 1) {
            return max(1, min(10080, (int) ($m[1] ?? 1)));
        }
        if (preg_match('/\b(?:in\s+)?(\d+)\s*(h|hr|hrs|hour|hours)\s*(?:ago)?\b/u', $text, $m) === 1) {
            return max(1, min(10080, (int) ($m[1] ?? 1) * 60));
        }
        if (preg_match('/\b(?:in\s+)?(\d+)\s*(d|day|days)\s*(?:ago)?\b/u', $text, $m) === 1) {
            return max(1, min(10080, (int) ($m[1] ?? 1) * 1440));
        }

        return null;
    }

    private function settingInt(string $key, int $default): int
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;

        return is_numeric($value) ? (int) round((float) $value) : $default;
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return list<array{label:string,detail:string,severity:string}>
     */
    private function deviceItems(Collection $rows, int $limit): array
    {
        return $rows->take($limit)->map(function (array $d): array {
            $parts = ['Status '.(string) ($d['status'] ?? 'unknown')];
            if (is_numeric($d['cpu'] ?? null)) {
                $parts[] = 'CPU '.round((float) $d['cpu'], 1).'%';
            }
            if (is_numeric($d['memory'] ?? null)) {
                $parts[] = 'RAM '.round((float) $d['memory'], 1).'%';
            }
            if (is_numeric($d['disk_free'] ?? null)) {
                $parts[] = 'Disk free '.round((float) $d['disk_free'], 1).'%';
            }
            if (is_numeric($d['temp'] ?? null)) {
                $parts[] = 'Temp '.round((float) $d['temp'], 1).'C';
            }
            if (($d['last_seen_at'] ?? null) instanceof CarbonInterface) {
                $parts[] = 'Seen '.$d['last_seen_at']->diffForHumans();
            }

            $sev = 'low';
            if (($d['status'] ?? '') !== 'online' || (($d['disk_free'] ?? 100) <= 12) || (($d['temp'] ?? 0) >= 80)) {
                $sev = 'high';
            } elseif ((($d['cpu'] ?? 0) >= 85) || (($d['memory'] ?? 0) >= 85) || (($d['pending_restart'] ?? false) === true)) {
                $sev = 'medium';
            }

            return [
                'label' => (string) ($d['hostname'] ?? $d['id'] ?? 'unknown-device'),
                'detail' => implode(' | ', $parts),
                'severity' => $sev,
            ];
        })->values()->all();
    }

    /**
     * @param array<string,mixed> $event
     */
    private function eventText(array $event): string
    {
        $meta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        return mb_strtolower(
            (string) ($event['event_type'] ?? '').' '
            .(string) ($event['user_name'] ?? '').' '
            .(string) ($event['process_name'] ?? '').' '
            .(string) ($event['file_path'] ?? '').' '
            .(string) ($meta['status'] ?? '').' '
            .(string) ($meta['result'] ?? '').' '
            .(string) ($meta['outcome'] ?? '').' '
            .(string) ($meta['message'] ?? '')
        );
    }

    /**
     * @param array<string,mixed> $event
     */
    private function isFailedLogin(array $event): bool
    {
        if ((string) ($event['event_type'] ?? '') !== 'user_logon') {
            return false;
        }
        return $this->containsAny($this->eventText($event), ['fail', 'invalid', 'denied']);
    }

    /**
     * @param array<string,mixed> $event
     */
    private function isAdminAccountCreationEvent(array $event): bool
    {
        $eventType = mb_strtolower(trim((string) ($event['event_type'] ?? '')));
        $process = mb_strtolower(trim((string) ($event['process_name'] ?? '')));
        $meta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $message = mb_strtolower(trim((string) ($meta['message'] ?? '')));
        $action = mb_strtolower(trim((string) ($meta['action'] ?? '')));
        $group = mb_strtolower(trim((string) ($meta['group'] ?? $meta['target_group'] ?? '')));
        $role = mb_strtolower(trim((string) ($meta['role'] ?? '')));
        $accountType = mb_strtolower(trim((string) ($meta['account_type'] ?? '')));

        $text = trim(implode(' ', [
            $eventType,
            $process,
            $message,
            $action,
            $group,
            $role,
            $accountType,
        ]));

        if ($text === '') {
            return false;
        }

        if ($this->containsAny($eventType, ['admin_account_created', 'local_admin_created', 'admin_user_created'])) {
            return true;
        }

        $looksLikeCreate = $this->containsAny($text, ['create', 'created', 'new account', 'add user', 'added user']);
        $looksLikeAdmin = $this->containsAny($text, ['admin', 'administrator', 'administrators']);
        $looksLikeMembershipChange = $this->containsAny($text, ['group member', 'group_membership', 'membership']);

        return ($looksLikeCreate && $looksLikeAdmin) || ($looksLikeMembershipChange && $looksLikeAdmin);
    }

    /**
     * @param array<string,mixed> $event
     */
    private function adminAccountName(array $event): string
    {
        $meta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        foreach (['created_user', 'target_user', 'account_name', 'user_name', 'username', 'account'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($event['user_name'] ?? ''));
    }

    /**
     * @param array<string,mixed> $event
     */
    private function isOffHours(array $event): bool
    {
        return ($event['occurred_at'] instanceof CarbonInterface)
            && (((int) $event['occurred_at']->hour) < 7 || ((int) $event['occurred_at']->hour) > 19);
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     */
    private function number(array $source, array $keys): ?float
    {
        foreach ($keys as $k) {
            $v = $this->path($source, $k);
            if (is_int($v) || is_float($v)) {
                return (float) $v;
            }
            if (is_string($v)) {
                $v = str_replace('%', '', trim($v));
                if ($v !== '' && is_numeric($v)) {
                    return (float) $v;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     */
    private function bool(array $source, array $keys): ?bool
    {
        foreach ($keys as $k) {
            $v = $this->path($source, $k);
            if (is_bool($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return ((float) $v) !== 0.0;
            }
            if (is_string($v)) {
                $b = filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($b !== null) {
                    return $b;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     */
    private function text(array $source, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $this->path($source, $k);
            if (is_scalar($v)) {
                $t = trim((string) $v);
                if ($t !== '') {
                    return $t;
                }
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $source
     */
    private function path(array $source, string $path): mixed
    {
        $node = $source;
        foreach (explode('.', $path) as $seg) {
            if (! is_array($node) || ! array_key_exists($seg, $node)) {
                return null;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return now()->parse($value);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    private function severity(float $risk): string
    {
        return $risk >= 0.80 ? 'high' : ($risk >= 0.50 ? 'medium' : 'low');
    }

    private function containsAny(string $text, array $needles): bool
    {
        $hay = mb_strtolower($text);
        foreach ($needles as $needle) {
            $n = mb_strtolower(trim((string) $needle));
            if ($n !== '' && str_contains($hay, $n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{label:string,value:string}> $metrics
     * @param list<array{label:string,detail:string,severity:string}> $items
     * @param list<string> $recommendations
     * @param array<string,mixed> $context
     * @return array{
     *   domain:string,
     *   topic:string,
     *   title:string,
     *   summary:string,
     *   metrics:list<array{label:string,value:string}>,
     *   items:list<array{label:string,detail:string,severity:string}>,
     *   recommendations:list<string>,
     *   context:array<string,mixed>,
     *   needs_clarification:bool,
     *   clarification:string|null,
     *   generated_at:string
     * }
     */
    private function result(
        string $domain,
        string $topic,
        string $title,
        string $summary,
        array $metrics,
        array $items,
        array $recommendations,
        array $context,
        bool $needsClarification = false,
        ?string $clarification = null
    ): array {
        return [
            'domain' => $domain,
            'topic' => $topic,
            'title' => $title,
            'summary' => $summary,
            'metrics' => array_values($metrics),
            'items' => array_values($items),
            'recommendations' => array_values($recommendations),
            'context' => $context,
            'needs_clarification' => $needsClarification,
            'clarification' => $clarification,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
