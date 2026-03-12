<?php

namespace App\Services\BehaviorPipeline\Detectors;

use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\BehaviorPipelineSettings;
use App\Services\BehaviorPipeline\Contracts\AnomalyDetector;

class BurstFileAccessDetector implements AnomalyDetector
{
    public function __construct(private readonly BehaviorPipelineSettings $settings)
    {
    }

    public function key(): string
    {
        return 'burst_file_access';
    }

    public function detect(DeviceBehaviorLog $event, array $features): array
    {
        $eventTime = $event->occurred_at ?? now();
        $user = strtolower(trim((string) ($features['user_name'] ?? 'unknown')));
        $windowMinutes = max(2, min(30, (int) round($this->settings->settingFloat('behavior.pipeline.file_burst_window_minutes', 8))));

        $windowStart = $eventTime->copy()->subMinutes($windowMinutes);
        $rows = DeviceBehaviorLog::query()
            ->where('device_id', $event->device_id)
            ->where('occurred_at', '>=', $windowStart)
            ->where('occurred_at', '<=', $eventTime)
            ->whereRaw('LOWER(COALESCE(user_name, "")) = ?', [$user])
            ->where('event_type', 'file_access')
            ->orderBy('occurred_at')
            ->get(['file_path', 'process_name', 'metadata', 'occurred_at']);

        $count = $rows->count();
        if ($count < 3) {
            return [
                'score' => 0.05,
                'confidence' => 0.20,
                'details' => [
                    'reason' => 'insufficient_file_access_volume',
                    'window_minutes' => $windowMinutes,
                    'file_access_events' => $count,
                ],
            ];
        }

        $sensitiveTokens = ['finance', 'payroll', 'salary', 'invoice', 'account', 'credential', 'password', 'secret', 'backup', 'hr'];
        $exfilExtensions = ['csv', 'xlsx', 'xls', 'zip', '7z', 'rar', 'pst', 'db', 'sqlite', 'bak'];
        $reconBins = ['powershell.exe', 'cmd.exe', 'wscript.exe', 'cscript.exe', 'python.exe'];

        $sensitiveHits = 0;
        $exfilExtHits = 0;
        $reconProcessHits = 0;
        $uniquePaths = [];
        $uniqueExt = [];

        foreach ($rows as $row) {
            $filePath = strtolower(trim((string) ($row->file_path ?? '')));
            $process = strtolower(trim((string) ($row->process_name ?? '')));
            $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
            if ($ext !== '') {
                $uniqueExt[$ext] = true;
            }
            if ($filePath !== '') {
                $uniquePaths[$filePath] = true;
            }

            foreach ($sensitiveTokens as $token) {
                if (str_contains($filePath, $token)) {
                    $sensitiveHits++;
                    break;
                }
            }
            if ($ext !== '' && in_array($ext, $exfilExtensions, true)) {
                $exfilExtHits++;
            }
            foreach ($reconBins as $bin) {
                if (str_contains($process, $bin)) {
                    $reconProcessHits++;
                    break;
                }
            }
        }

        $burstRatePerMinute = $count / max(1, $windowMinutes);
        $burstComponent = min(1.0, max(0.0, ($burstRatePerMinute - 1.2) / 4.0));
        $sensitiveComponent = min(1.0, (($sensitiveHits * 1.4) + ($exfilExtHits * 1.2)) / 14);
        $spreadComponent = min(1.0, count($uniquePaths) / 35);
        $extDiversityComponent = min(1.0, count($uniqueExt) / 7);
        $reconComponent = min(1.0, $reconProcessHits / 6);

        $score = ($burstComponent * 0.38)
            + ($sensitiveComponent * 0.30)
            + ($spreadComponent * 0.15)
            + ($extDiversityComponent * 0.07)
            + ($reconComponent * 0.10);

        $tags = is_array($features['tags'] ?? null) ? $features['tags'] : [];
        $isMachineAccount = (bool) ($features['is_machine_account'] ?? false);
        $isTrustedAgent = in_array('trusted_agent_activity', $tags, true) || in_array('managed_device_telemetry', $tags, true);
        if ($isMachineAccount && $isTrustedAgent) {
            $score -= 0.30;
        }

        $score = max(0.0, min(1.0, $score));
        $confidence = max(0.30, min(0.98, 0.30 + (min($count, 100) / 100) * 0.66));

        return [
            'score' => round($score, 4),
            'confidence' => round($confidence, 4),
            'details' => [
                'window_minutes' => $windowMinutes,
                'file_access_events' => $count,
                'burst_rate_per_minute' => round($burstRatePerMinute, 3),
                'sensitive_hits' => $sensitiveHits,
                'exfil_extension_hits' => $exfilExtHits,
                'recon_process_hits' => $reconProcessHits,
                'unique_paths' => count($uniquePaths),
                'unique_extensions' => count($uniqueExt),
            ],
        ];
    }
}
