<?php

namespace App\Jobs;

use App\Models\DeviceBehaviorLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class AppendBehaviorEventsToDatasetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param list<string> $eventIds */
    public function __construct(private readonly array $eventIds)
    {
    }

    public function handle(): void
    {
        if ($this->eventIds === []) {
            return;
        }

        $events = DeviceBehaviorLog::query()
            ->whereIn('id', $this->eventIds)
            ->orderBy('occurred_at')
            ->get(['id', 'device_id', 'event_type', 'occurred_at', 'user_name', 'process_name', 'file_path', 'metadata']);

        if ($events->isEmpty()) {
            return;
        }

        $lines = [];
        foreach ($events as $event) {
            $line = [
                'id' => $event->id,
                'device_id' => $event->device_id,
                'event_type' => $event->event_type,
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                'user_name' => $event->user_name,
                'process_name' => $event->process_name,
                'file_path' => $event->file_path,
                'metadata' => $event->metadata,
            ];
            $lines[] = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $payload = implode("\n", $lines)."\n";
        $datasetPath = 'behavior_models/datasets/latest.jsonl';

        if (Storage::disk('local')->exists($datasetPath)) {
            Storage::disk('local')->append($datasetPath, trim($payload));
            return;
        }

        Storage::disk('local')->put($datasetPath, $payload);
    }
}
