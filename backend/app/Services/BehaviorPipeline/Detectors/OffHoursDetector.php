<?php

namespace App\Services\BehaviorPipeline\Detectors;

use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\Contracts\AnomalyDetector;

class OffHoursDetector implements AnomalyDetector
{
    public function key(): string
    {
        return 'off_hours_profile';
    }

    public function detect(DeviceBehaviorLog $event, array $features): array
    {
        $user = (string) ($features['user_name'] ?? 'unknown');
        $hour = (int) ($features['hour'] ?? 0);
        $eventType = (string) ($features['event_type'] ?? 'unknown');

        $baselineRows = DeviceBehaviorLog::query()
            ->where('device_id', $event->device_id)
            ->where('event_type', 'user_logon')
            ->whereRaw('LOWER(COALESCE(user_name, "")) = ?', [$user])
            ->where('occurred_at', '>=', now()->subDays(45))
            ->when($event->occurred_at !== null, fn ($q) => $q->where('occurred_at', '<', $event->occurred_at))
            ->orderBy('occurred_at')
            ->limit(2000)
            ->get(['occurred_at']);

        $total = $baselineRows->count();
        if ($total < 8) {
            return [
                'score' => $eventType === 'user_logon' ? 0.35 : 0.15,
                'confidence' => 0.25,
                'details' => [
                    'reason' => 'insufficient_baseline',
                    'baseline_events' => $total,
                ],
            ];
        }

        $histogram = array_fill(0, 24, 0);
        foreach ($baselineRows as $row) {
            $h = (int) (($row->occurred_at?->hour) ?? 0);
            $histogram[$h]++;
        }

        $avg = array_sum($histogram) / 24;
        $expectedHours = [];
        foreach ($histogram as $h => $count) {
            if ($count >= max(1.0, $avg * 0.75)) {
                $expectedHours[] = (int) $h;
            }
        }

        if ($expectedHours === []) {
            $expectedHours[] = array_keys($histogram, max($histogram), true)[0] ?? 8;
        }

        $distance = 12;
        foreach ($expectedHours as $expectedHour) {
            $direct = abs($hour - $expectedHour);
            $circular = min($direct, 24 - $direct);
            if ($circular < $distance) {
                $distance = $circular;
            }
        }

        $score = min(1.0, $distance / 8.0);
        if ($eventType !== 'user_logon') {
            $score *= 0.45;
        }

        $confidence = max(0.35, min(0.98, 0.35 + min($total, 200) / 200 * 0.63));

        return [
            'score' => round($score, 4),
            'confidence' => round($confidence, 4),
            'details' => [
                'baseline_events' => $total,
                'expected_hours' => $expectedHours,
                'observed_hour' => $hour,
                'hour_distance' => $distance,
            ],
        ];
    }
}
