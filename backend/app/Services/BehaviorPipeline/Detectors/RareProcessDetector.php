<?php

namespace App\Services\BehaviorPipeline\Detectors;

use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\Contracts\AnomalyDetector;

class RareProcessDetector implements AnomalyDetector
{
    public function key(): string
    {
        return 'rare_process_on_device';
    }

    public function detect(DeviceBehaviorLog $event, array $features): array
    {
        $process = trim((string) ($features['process_name'] ?? 'unknown'));
        $eventType = (string) ($features['event_type'] ?? 'unknown');

        if ($process === '' || $process === 'unknown') {
            return [
                'score' => 0.2,
                'confidence' => 0.2,
                'details' => ['reason' => 'missing_process_name'],
            ];
        }

        $windowStart = now()->subDays(30);
        $query = DeviceBehaviorLog::query()
            ->where('device_id', $event->device_id)
            ->whereIn('event_type', ['app_launch', 'file_access'])
            ->where('occurred_at', '>=', $windowStart)
            ->when($event->occurred_at !== null, fn ($q) => $q->where('occurred_at', '<', $event->occurred_at));

        $total = (int) (clone $query)->count();
        $processCount = (int) (clone $query)
            ->whereRaw('LOWER(COALESCE(process_name, "")) = ?', [mb_strtolower($process)])
            ->count();

        if ($total < 20) {
            return [
                'score' => $eventType === 'app_launch' ? 0.4 : 0.25,
                'confidence' => 0.3,
                'details' => [
                    'reason' => 'insufficient_device_history',
                    'window_events' => $total,
                    'process_count' => $processCount,
                ],
            ];
        }

        $probability = ($processCount + 1) / max(1, $total + 500);
        $score = 1.0 - exp(-(-log($probability) / 6.0));
        if ($eventType === 'user_logon') {
            $score *= 0.2;
        }

        $confidence = max(0.3, min(0.99, 0.3 + min($total, 3000) / 3000 * 0.69));

        return [
            'score' => round(max(0.0, min(1.0, $score)), 4),
            'confidence' => round($confidence, 4),
            'details' => [
                'window_events' => $total,
                'process_count' => $processCount,
                'probability' => round($probability, 6),
                'window_days' => 30,
            ],
        ];
    }
}
