<?php

namespace App\Services\BehaviorPipeline;

use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorRemediationExecution;
use App\Models\DmsJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AutonomousRemediationEngine
{
    private ?bool $tableReady = null;

    public function __construct(
        private readonly BehaviorPipelineSettings $settings,
        private readonly PolicyApplicationService $policyApplicationService,
    ) {
    }

    /**
     * @return Collection<int,BehaviorRemediationExecution>
     */
    public function executeForCase(BehaviorAnomalyCase $case): Collection
    {
        if (! $this->settings->settingBool('behavior.remediation.enabled', false)) {
            return collect();
        }
        if (! $this->hasTable()) {
            return collect();
        }

        $riskScore = $this->clamp((float) ($case->risk_score ?? 0.0));
        $minRisk = $this->clamp($this->settings->settingFloat('behavior.remediation.min_risk', 0.90));
        if ($riskScore < $minRisk) {
            return collect();
        }

        $context = is_array($case->context) ? $case->context : [];
        $features = is_array($context['features'] ?? null) ? $context['features'] : [];
        $signals = is_array($context['detector_signals'] ?? null) ? $context['detector_signals'] : [];

        $candidates = $this->buildCandidates($case, $features, $signals, $riskScore, $minRisk);
        if ($candidates === []) {
            return collect();
        }

        usort($candidates, fn (array $a, array $b) => ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0)));
        $maxActions = max(1, min(6, $this->settings->settingInt('behavior.remediation.max_actions_per_case', 2)));
        $selected = array_slice($candidates, 0, $maxActions);

        $executions = collect();
        foreach ($selected as $candidate) {
            $remediationKey = (string) ($candidate['key'] ?? '');
            if ($remediationKey === '') {
                continue;
            }
            $behaviorLogId = trim((string) ($case->behavior_log_id ?? ''));

            $exists = BehaviorRemediationExecution::query()
                ->where('anomaly_case_id', (string) $case->id)
                ->where('remediation_key', $remediationKey)
                ->exists();
            if ($exists) {
                continue;
            }

            try {
                $dispatch = $this->dispatchCandidate($case, $candidate);
                if ($dispatch === null) {
                    continue;
                }

                $job = $dispatch['job'];
                $execution = BehaviorRemediationExecution::query()->create([
                    'anomaly_case_id' => (string) $case->id,
                    'device_id' => (string) $case->device_id,
                    'behavior_log_id' => $behaviorLogId !== '' ? $behaviorLogId : null,
                    'remediation_key' => $remediationKey,
                    'action_type' => (string) ($candidate['action_type'] ?? 'unknown'),
                    'status' => 'queued',
                    'risk_score' => round($riskScore, 4),
                    'trigger_score' => round($this->clamp((float) ($candidate['score'] ?? 0.0)), 4),
                    'reason' => mb_substr((string) ($candidate['reason'] ?? ''), 0, 500),
                    'payload' => [
                        'candidate' => $candidate,
                        'dispatch_payload' => $dispatch['dispatch_payload'],
                    ],
                    'dispatched_job_id' => (string) $job->id,
                    'failure_reason' => null,
                    'detected_at' => $case->detected_at ?? now(),
                    'executed_at' => now(),
                ]);
            } catch (\Throwable $e) {
                report($e);
                $execution = BehaviorRemediationExecution::query()->create([
                    'anomaly_case_id' => (string) $case->id,
                    'device_id' => (string) $case->device_id,
                    'behavior_log_id' => $behaviorLogId !== '' ? $behaviorLogId : null,
                    'remediation_key' => $remediationKey,
                    'action_type' => (string) ($candidate['action_type'] ?? 'unknown'),
                    'status' => 'dispatch_failed',
                    'risk_score' => round($riskScore, 4),
                    'trigger_score' => round($this->clamp((float) ($candidate['score'] ?? 0.0)), 4),
                    'reason' => mb_substr((string) ($candidate['reason'] ?? ''), 0, 500),
                    'payload' => [
                        'candidate' => $candidate,
                    ],
                    'failure_reason' => mb_substr($e->getMessage(), 0, 3000),
                    'detected_at' => $case->detected_at ?? now(),
                    'executed_at' => now(),
                ]);
            }

            $executions->push($execution);
        }

        return $executions;
    }

    /**
     * @param array<string,mixed> $features
     * @param array<string,mixed> $signals
     * @return array<int,array<string,mixed>>
     */
    private function buildCandidates(
        BehaviorAnomalyCase $case,
        array $features,
        array $signals,
        float $riskScore,
        float $minRisk,
    ): array {
        $eventType = strtolower(trim((string) ($features['event_type'] ?? 'unknown')));
        $metadata = is_array($features['metadata'] ?? null) ? $features['metadata'] : [];
        $rawProcessName = (string) ($features['process_name_raw'] ?? $features['process_name'] ?? '');
        $processName = $this->sanitizeProcessName($rawProcessName);
        $appNameRaw = trim((string) ($features['app_name'] ?? ''));
        if ($appNameRaw === '' && $processName !== '') {
            $appNameRaw = pathinfo($processName, PATHINFO_FILENAME);
        }
        $appName = $this->sanitizeAppName($appNameRaw);

        $rareScore = $this->signalScore($signals, 'rare_process_on_device');
        $offHoursScore = $this->signalScore($signals, 'off_hours_profile');
        $multiSignalScore = $this->signalScore($signals, 'multi_signal_correlation_window');
        $burstAccessScore = $this->signalScore($signals, 'burst_file_access');
        $baselineScore = $this->signalScore($signals, 'behavioral_baseline_drift');

        $networkBytes = max(
            0.0,
            (float) ($this->numericMetadataValue($metadata, ['network_bytes_sent', 'network.bytes_sent', 'network.bytes.tx']) ?? 0.0)
            + (float) ($this->numericMetadataValue($metadata, ['network_bytes_received', 'network.bytes_received', 'network.bytes.rx']) ?? 0.0)
        );
        $networkConnections = (int) round((float) ($this->numericMetadataValue($metadata, ['network_connection_count', 'network_connections', 'network.connection_count']) ?? 0.0));
        $cpuPercent = (float) ($this->numericMetadataValue($metadata, ['cpu_percent', 'cpu', 'cpu.usage']) ?? 0.0);
        $memoryMb = (float) ($this->numericMetadataValue($metadata, ['memory_mb', 'memory', 'ram_mb']) ?? 0.0);
        $networkSpike = $networkBytes >= 2_000_000 || $networkConnections >= 24;
        $resourceSpike = $cpuPercent >= 90 || $memoryMb >= 1400;

        $candidates = [];

        $allowForceScan = $this->settings->settingBool('behavior.remediation.allow_force_scan', true);
        $scanCommand = trim($this->settings->settingString(
            'behavior.remediation.scan_command',
            'powershell.exe -NoProfile -Command "Start-MpScan -ScanType QuickScan"'
        ));
        if ($allowForceScan && $scanCommand !== '' && $riskScore >= $minRisk) {
            $score = $this->clamp(($riskScore * 0.80) + (max($rareScore, $baselineScore) * 0.20));
            $candidates[] = [
                'key' => 'force_system_scan',
                'action_type' => 'apply_rules',
                'reason_code' => 'autonomous_remediation_force_scan',
                'reason' => 'Risk threshold exceeded; forcing immediate endpoint security scan.',
                'score' => $score,
                'payload' => [
                    'rules' => [$this->commandRule($scanCommand, 'system', 1200)],
                ],
            ];
        }

        $allowEmergencyProfile = $this->settings->settingBool('behavior.remediation.allow_emergency_profile', true);
        $emergencyPolicyVersionId = trim($this->settings->settingString('behavior.remediation.emergency_policy_version_id', ''));
        if (
            $allowEmergencyProfile
            && $emergencyPolicyVersionId !== ''
            && $riskScore >= max($minRisk, 0.88)
            && max($multiSignalScore, $baselineScore, $rareScore, $offHoursScore) >= 0.62
        ) {
            $score = $this->clamp(($riskScore * 0.72) + (max($multiSignalScore, $baselineScore, $rareScore) * 0.28));
            $candidates[] = [
                'key' => 'apply_emergency_security_profile',
                'action_type' => 'apply_policy_version',
                'reason_code' => 'autonomous_remediation_emergency_profile',
                'reason' => 'High-confidence threat pattern triggered emergency hardening profile.',
                'score' => $score,
                'policy_version_id' => $emergencyPolicyVersionId,
                'payload' => [
                    'policy_version_id' => $emergencyPolicyVersionId,
                ],
            ];
        }

        $allowIsolateNetwork = $this->settings->settingBool('behavior.remediation.allow_isolate_network', false);
        $isolateCommand = trim($this->settings->settingString(
            'behavior.remediation.isolate_command',
            'netsh advfirewall set allprofiles state on && netsh advfirewall set allprofiles firewallpolicy blockinbound,blockoutbound'
        ));
        if (
            $allowIsolateNetwork
            && $isolateCommand !== ''
            && $riskScore >= 0.94
            && (max($multiSignalScore, $burstAccessScore, $baselineScore) >= 0.72 || $networkSpike)
        ) {
            $score = $this->clamp(($riskScore * 0.66) + (max($multiSignalScore, $burstAccessScore, $baselineScore) * 0.34));
            $candidates[] = [
                'key' => 'isolate_device_network',
                'action_type' => 'apply_rules',
                'reason_code' => 'autonomous_remediation_isolate_network',
                'reason' => 'Network activity profile is high risk; applying immediate network isolation.',
                'score' => $score,
                'payload' => [
                    'rules' => [$this->commandRule($isolateCommand, 'system', 900)],
                ],
            ];
        }

        $allowKillProcess = $this->settings->settingBool('behavior.remediation.allow_kill_process', false);
        if (
            $allowKillProcess
            && $processName !== ''
            && ! $this->isProtectedProcess($processName)
            && $riskScore >= 0.86
            && $rareScore >= 0.72
        ) {
            $score = $this->clamp(($riskScore * 0.65) + ($rareScore * 0.35));
            $killCommand = 'taskkill /F /IM "'.$processName.'" /T';
            $candidates[] = [
                'key' => 'kill_suspicious_process',
                'action_type' => 'apply_rules',
                'reason_code' => 'autonomous_remediation_kill_process',
                'reason' => 'Rare process execution exceeded remediation threshold and will be terminated.',
                'score' => $score,
                'payload' => [
                    'rules' => [$this->commandRule($killCommand, 'system', 600)],
                    'process_name' => $processName,
                ],
            ];
        }

        $allowUninstallSoftware = $this->settings->settingBool('behavior.remediation.allow_uninstall_software', false);
        if (
            $allowUninstallSoftware
            && $appName !== ''
            && $riskScore >= 0.90
            && $rareScore >= 0.84
            && in_array($eventType, ['app_launch', 'process_start', 'process_execution'], true)
        ) {
            $score = $this->clamp(($riskScore * 0.68) + ($rareScore * 0.32));
            $uninstallCommand = 'winget uninstall --name "'.$appName.'" --silent --disable-interactivity --accept-source-agreements';
            $candidates[] = [
                'key' => 'uninstall_suspicious_software',
                'action_type' => 'apply_rules',
                'reason_code' => 'autonomous_remediation_uninstall_software',
                'reason' => 'Suspicious application launch pattern detected; attempting immediate software removal.',
                'score' => $score,
                'payload' => [
                    'rules' => [$this->commandRule($uninstallCommand, 'system', 1800)],
                    'application_name' => $appName,
                ],
            ];
        }

        $allowRollbackPolicy = $this->settings->settingBool('behavior.remediation.allow_rollback_policy', false);
        $rollbackDescription = trim($this->settings->settingString(
            'behavior.remediation.rollback_restore_point_description',
            'DMS Baseline Safe Point'
        ));
        if (
            $allowRollbackPolicy
            && $rollbackDescription !== ''
            && $riskScore >= 0.97
            && ($baselineScore >= 0.80 || $resourceSpike)
        ) {
            $score = $this->clamp(($riskScore * 0.70) + (max($baselineScore, $multiSignalScore) * 0.30));
            $candidates[] = [
                'key' => 'rollback_policy_state',
                'action_type' => 'restore_snapshot',
                'reason_code' => 'autonomous_remediation_rollback',
                'reason' => 'Severe behavioral drift triggered automated rollback to a known safe restore point.',
                'score' => $score,
                'payload' => [
                    'provider' => 'windows_restore_point',
                    'restore_point_description' => $rollbackDescription,
                    'reboot_now' => $this->settings->settingBool('behavior.remediation.rollback_reboot_now', true),
                    'reboot_command' => 'shutdown.exe /r /t 0',
                ],
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array{job:DmsJob,dispatch_payload:array<string,mixed>}|null
     */
    private function dispatchCandidate(BehaviorAnomalyCase $case, array $candidate): ?array
    {
        $actionType = (string) ($candidate['action_type'] ?? '');
        $reasonCode = trim((string) ($candidate['reason_code'] ?? 'autonomous_remediation'));
        if ($reasonCode === '') {
            $reasonCode = 'autonomous_remediation';
        }

        $metadata = [
            'autonomous_remediation' => true,
            'autonomous_remediation_action' => (string) ($candidate['key'] ?? ''),
            'autonomous_remediation_reason' => (string) ($candidate['reason'] ?? ''),
            'autonomous_remediation_score' => round($this->clamp((float) ($candidate['score'] ?? 0.0)), 4),
            'autonomous_remediation_triggered_at' => now()->toIso8601String(),
        ];

        if ($actionType === 'apply_policy_version') {
            $policyVersionId = trim((string) ($candidate['policy_version_id'] ?? ''));
            if ($policyVersionId === '') {
                return null;
            }

            $job = $this->policyApplicationService->applyPolicyToDevice(
                (string) $case->device_id,
                $policyVersionId,
                $reasonCode,
                null,
                (string) $case->id,
                null,
                $metadata,
            );

            return [
                'job' => $job,
                'dispatch_payload' => [
                    'policy_version_id' => $policyVersionId,
                    'metadata' => $metadata,
                ],
            ];
        }

        if ($actionType === 'apply_rules') {
            $payload = is_array($candidate['payload'] ?? null) ? $candidate['payload'] : [];
            $rules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
            if ($rules === []) {
                return null;
            }

            $job = $this->policyApplicationService->applyPolicyRulesToDevice(
                (string) $case->device_id,
                $rules,
                $reasonCode,
                null,
                (string) $case->id,
                null,
                $metadata,
            );

            return [
                'job' => $job,
                'dispatch_payload' => [
                    'rules' => $rules,
                    'metadata' => $metadata,
                ],
            ];
        }

        if ($actionType === 'restore_snapshot') {
            $payload = is_array($candidate['payload'] ?? null) ? $candidate['payload'] : [];
            $jobPayload = array_merge($payload, $metadata);
            $job = $this->policyApplicationService->queueJobToDevice(
                'restore_snapshot',
                $jobPayload,
                (string) $case->device_id,
                95,
                null,
            );

            return [
                'job' => $job,
                'dispatch_payload' => $jobPayload,
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $signals
     */
    private function signalScore(array $signals, string $key): float
    {
        $signal = $signals[$key] ?? null;
        if (! is_array($signal)) {
            return 0.0;
        }
        if (array_key_exists('active', $signal) && ! (bool) $signal['active']) {
            return 0.0;
        }

        return $this->clamp((float) ($signal['score'] ?? 0.0));
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<int,string> $keys
     */
    private function numericMetadataValue(array $metadata, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }
            $value = $metadata[$key];
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function sanitizeProcessName(string $raw): string
    {
        $base = basename(str_replace('\\', '/', trim($raw)));
        $clean = preg_replace('/[^a-zA-Z0-9._-]/', '', $base) ?? '';
        return strtolower(trim($clean));
    }

    private function sanitizeAppName(string $raw): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9 ._()-]/', '', trim($raw)) ?? '';
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
        return trim($clean);
    }

    private function isProtectedProcess(string $processName): bool
    {
        $protected = [
            'system',
            'registry',
            'smss.exe',
            'csrss.exe',
            'wininit.exe',
            'services.exe',
            'winlogon.exe',
            'lsass.exe',
            'svchost.exe',
            'explorer.exe',
            'dwm.exe',
        ];

        return in_array(strtolower(trim($processName)), $protected, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function commandRule(string $command, string $runAs = 'system', int $timeoutSeconds = 900): array
    {
        $mode = strtolower(trim($runAs));
        if (! in_array($mode, ['default', 'elevated', 'system'], true)) {
            $mode = 'system';
        }

        return [
            'type' => 'command',
            'enforce' => true,
            'config' => [
                'command' => $command,
                'run_as' => $mode,
                'timeout_seconds' => max(30, min(3600, $timeoutSeconds)),
            ],
        ];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function hasTable(): bool
    {
        if ($this->tableReady === null) {
            $this->tableReady = Schema::hasTable('behavior_remediation_executions');
        }

        return $this->tableReady;
    }
}
