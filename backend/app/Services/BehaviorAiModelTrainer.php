<?php

namespace App\Services;

use App\Models\DeviceBehaviorLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BehaviorAiModelTrainer
{
    public function train(int $days = 30, int $minEvents = 200): array
    {
        $days = max(1, min(180, $days));
        $minEvents = max(50, min(100000, $minEvents));

        $dataset = $this->loadDataset($days);
        if ($dataset->count() < $minEvents) {
            throw new \RuntimeException("Insufficient behavior events for training. Need at least {$minEvents}, found {$dataset->count()}.");
        }

        $eventTypeCounts = [];
        $hourByType = [];
        $userHourCounts = [];
        $processCounts = [];
        $fileExtCounts = [];

        foreach ($dataset as $row) {
            $type = (string) ($row['event_type'] ?? 'unknown');
            $hour = (int) ($row['hour'] ?? 0);
            $user = mb_strtolower((string) ($row['user_name'] ?? 'unknown'));
            $process = mb_strtolower((string) ($row['process_name'] ?? 'unknown'));
            $ext = mb_strtolower((string) ($row['file_ext'] ?? 'none'));

            $eventTypeCounts[$type] = (int) ($eventTypeCounts[$type] ?? 0) + 1;
            $hourByType[$type][$hour] = (int) ($hourByType[$type][$hour] ?? 0) + 1;
            $userHourCounts[$user][$hour] = (int) ($userHourCounts[$user][$hour] ?? 0) + 1;
            $processCounts[$type][$process] = (int) ($processCounts[$type][$process] ?? 0) + 1;
            $fileExtCounts[$ext] = (int) ($fileExtCounts[$ext] ?? 0) + 1;
        }

        $model = [
            'version' => 1,
            'algorithm' => 'isolation-forest-inspired-statistical-ensemble',
            'trained_at' => now()->toIso8601String(),
            'window_days' => $days,
            'total_events' => $dataset->count(),
            'event_type_counts' => $eventTypeCounts,
            'hour_by_type' => $hourByType,
            'user_hour_counts' => $userHourCounts,
            'process_counts' => $processCounts,
            'file_ext_counts' => $fileExtCounts,
            'threshold' => 0.82,
        ];

        $path = 'behavior_models/current-model.json';
        Storage::disk('local')->put($path, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'path' => $path,
            'events' => $dataset->count(),
            'trained_at' => $model['trained_at'],
            'algorithm' => $model['algorithm'],
        ];
    }

    private function loadDataset(int $days): Collection
    {
        $path = 'behavior_models/datasets/latest.jsonl';
        if (Storage::disk('local')->exists($path)) {
            $raw = Storage::disk('local')->get($path);
            $lines = preg_split('/\r\n|\n|\r/', (string) $raw) ?: [];
            $items = collect($lines)
                ->map(fn (string $line) => trim($line))
                ->filter()
                ->map(function (string $line) {
                    $row = json_decode($line, true);
                    return is_array($row) ? $this->normalizeRow($row) : null;
                })
                ->filter()
                ->values();

            if ($items->isNotEmpty()) {
                return $items->filter(function (array $row) use ($days) {
                    $occurred = isset($row['occurred_at']) ? strtotime((string) $row['occurred_at']) : false;
                    if ($occurred === false) {
                        return false;
                    }
                    return $occurred >= now()->subDays($days)->getTimestamp();
                })->values();
            }
        }

        return DeviceBehaviorLog::query()
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderBy('occurred_at')
            ->get(['event_type', 'occurred_at', 'user_name', 'process_name', 'file_path'])
            ->map(function (DeviceBehaviorLog $log) {
                return $this->normalizeRow([
                    'event_type' => $log->event_type,
                    'occurred_at' => optional($log->occurred_at)->toIso8601String(),
                    'user_name' => $log->user_name,
                    'process_name' => $log->process_name,
                    'file_path' => $log->file_path,
                ]);
            })
            ->filter()
            ->values();
    }

    private function normalizeRow(array $row): ?array
    {
        $occurredAt = isset($row['occurred_at']) ? strtotime((string) $row['occurred_at']) : false;
        if ($occurredAt === false) {
            return null;
        }

        $filePath = (string) ($row['file_path'] ?? '');
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        return [
            'event_type' => (string) ($row['event_type'] ?? 'unknown'),
            'occurred_at' => gmdate('c', $occurredAt),
            'hour' => (int) gmdate('G', $occurredAt),
            'user_name' => trim((string) ($row['user_name'] ?? '')),
            'process_name' => trim((string) ($row['process_name'] ?? '')),
            'file_ext' => $ext !== '' ? (string) $ext : 'none',
        ];
    }
}
