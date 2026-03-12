<?php

namespace App\Services\BehaviorPipeline\Detectors;

use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\BehaviorPipelineSettings;
use App\Services\BehaviorPipeline\Contracts\AnomalyDetector;

class MultiSignalCorrelationDetector implements AnomalyDetector
{
    public function __construct(private readonly BehaviorPipelineSettings $settings)
    {
    }

    public function key(): string
    {
        return 'multi_signal_correlation_window';
    }

    public function detect(DeviceBehaviorLog $event, array $features): array
    {
        $eventTime = $event->occurred_at ?? now();
        $windowMinutes = max(2, min(90, (int) round($this->settings->settingFloat('behavior.pipeline.correlation_window_minutes', 12))));
        $windowStart = $eventTime->copy()->subMinutes($windowMinutes);
        $user = strtolower(trim((string) ($features['user_name'] ?? 'unknown')));

        $events = DeviceBehaviorLog::query()
            ->where('device_id', $event->device_id)
            ->where('occurred_at', '>=', $windowStart)
            ->where('occurred_at', '<=', $eventTime)
            ->whereRaw('LOWER(COALESCE(user_name, "")) = ?', [$user])
            ->orderBy('occurred_at')
            ->get(['event_type', 'process_name', 'file_path', 'metadata', 'occurred_at']);

        $sampleCount = $events->count();
        if ($sampleCount < 4) {
            return [
                'score' => 0.12,
                'confidence' => 0.25,
                'details' => [
                    'reason' => 'insufficient_window_samples',
                    'window_minutes' => $windowMinutes,
                    'sample_count' => $sampleCount,
                ],
            ];
        }

        $reconBins = ['powershell.exe', 'cmd.exe', 'sc.exe', 'quser.exe', 'query.exe', 'wmic.exe', 'net.exe', 'whoami.exe'];
        $sensitiveTokens = ['finance', 'payroll', 'salary', 'hr', 'invoice', 'account', 'credential', 'password', 'secret', 'backup'];

        $burstCount = 0;
        $fileAccessCount = 0;
        $sensitiveFileHits = 0;
        $reconHits = 0;
        $commandSequenceHits = 0;
        $uniqueProcesses = [];

        foreach ($events as $row) {
            $eventType = strtolower(trim((string) ($row->event_type ?? 'unknown')));
            $process = strtolower(trim((string) ($row->process_name ?? '')));
            $filePath = strtolower(trim((string) ($row->file_path ?? '')));
            $metadata = is_array($row->metadata) ? $row->metadata : [];

            if (in_array($eventType, ['app_launch', 'file_access', 'user_logon'], true)) {
                $burstCount++;
            }
            if ($eventType === 'file_access') {
                $fileAccessCount++;
            }
            if ($process !== '') {
                $base = basename(str_replace('\\', '/', $process));
                $uniqueProcesses[$base] = true;
            }

            foreach ($sensitiveTokens as $token) {
                if ($token !== '' && str_contains($filePath, $token)) {
                    $sensitiveFileHits++;
                    break;
                }
            }

            foreach ($reconBins as $bin) {
                if ($bin !== '' && (str_contains($process, $bin) || str_contains($filePath, $bin))) {
                    $reconHits++;
                    break;
                }
            }

            $sequence = $metadata['command_sequence'] ?? ($metadata['process_chain'] ?? []);
            if (is_array($sequence)) {
                $joined = strtolower(implode(' ', array_map(fn ($step) => (string) $step, $sequence)));
                $matched = 0;
                foreach ($reconBins as $bin) {
                    if (str_contains($joined, $bin)) {
                        $matched++;
                    }
                }
                if ($matched >= 2) {
                    $commandSequenceHits++;
                }
            }
        }

        $burstScore = min(1.0, max(0.0, ($burstCount - 8) / 24));
        $fileBurstScore = min(1.0, (($fileAccessCount * 0.8) + ($sensitiveFileHits * 1.4)) / 18);
        $reconScore = min(1.0, ($reconHits + ($commandSequenceHits * 1.2)) / 6);
        $processFanoutScore = min(1.0, (count($uniqueProcesses) - 3) / 10);
        $offHoursScore = ((int) $eventTime->hour <= 5 || (int) $eventTime->hour >= 22) ? 0.25 : 0.0;

        $activeSignals = 0;
        foreach ([$burstScore, $fileBurstScore, $reconScore, $processFanoutScore] as $component) {
            if ($component >= 0.35) {
                $activeSignals++;
            }
        }
        $correlationBoost = $activeSignals >= 2 ? min(1.0, ($activeSignals - 1) / 3) : 0.0;

        $score = ($burstScore * 0.24)
            + ($fileBurstScore * 0.26)
            + ($reconScore * 0.26)
            + ($processFanoutScore * 0.12)
            + ($correlationBoost * 0.12)
            + $offHoursScore;

        $tags = is_array($features['tags'] ?? null) ? $features['tags'] : [];
        $isMachineAccount = (bool) ($features['is_machine_account'] ?? false);
        $hasTrustedTag = in_array('trusted_agent_activity', $tags, true) || in_array('managed_device_telemetry', $tags, true);
        if ($isMachineAccount && $hasTrustedTag) {
            $score -= 0.25;
        }

        $score = max(0.0, min(1.0, $score));
        $confidence = max(0.30, min(0.98, 0.30 + (min($sampleCount, 50) / 50) * 0.66));

        return [
            'score' => round($score, 4),
            'confidence' => round($confidence, 4),
            'details' => [
                'window_minutes' => $windowMinutes,
                'sample_count' => $sampleCount,
                'burst_count' => $burstCount,
                'file_access_count' => $fileAccessCount,
                'sensitive_file_hits' => $sensitiveFileHits,
                'recon_hits' => $reconHits,
                'command_sequence_hits' => $commandSequenceHits,
                'unique_processes' => count($uniqueProcesses),
                'active_signals' => $activeSignals,
                'correlation_boost' => round($correlationBoost, 4),
            ],
        ];
    }
}
