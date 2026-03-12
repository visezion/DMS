<?php

namespace App\Jobs;

use App\Models\DeviceBehaviorLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class BackfillBehaviorDatasetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $days = 30)
    {
    }

    public function handle(): void
    {
        $days = max(1, min(180, $this->days));
        $events = DeviceBehaviorLog::query()
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderBy('occurred_at')
            ->get(['id', 'device_id', 'event_type', 'occurred_at', 'user_name', 'process_name', 'file_path', 'metadata']);

        $lines = [];
        foreach ($events as $event) {
            $lines[] = json_encode([
                'id' => $event->id,
                'device_id' => $event->device_id,
                'event_type' => $event->event_type,
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                'user_name' => $event->user_name,
                'process_name' => $event->process_name,
                'file_path' => $event->file_path,
                'metadata' => $event->metadata,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        Storage::disk('local')->put('behavior_models/datasets/latest.jsonl', implode("\n", $lines).($lines !== [] ? "\n" : ''));
    }
}
