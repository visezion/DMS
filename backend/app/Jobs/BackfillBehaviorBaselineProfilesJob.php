<?php

namespace App\Jobs;

use App\Models\ControlPlaneSetting;
use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\BehavioralBaselineModelingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

class BackfillBehaviorBaselineProfilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly int $days = 30,
        private readonly int $limit = 5000,
    ) {
        $this->onQueue('horizon');
    }

    public function handle(BehavioralBaselineModelingService $baselineModelingService): void
    {
        if (! Schema::hasTable('device_behavior_logs') || ! Schema::hasTable('device_behavior_baselines')) {
            return;
        }

        $days = max(1, min(365, $this->days));
        $limit = max(100, min(200000, $this->limit));
        $windowStart = now()->subDays($days);

        $processed = 0;
        $failed = 0;

        $query = DeviceBehaviorLog::query()
            ->where('occurred_at', '>=', $windowStart)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit);

        foreach ($query->cursor() as $event) {
            try {
                $baselineModelingService->ingestOutcome($event, null);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                report($e);
            }
        }

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.baseline.last_backfill_completed_at'],
            ['value' => ['value' => now()->toIso8601String()]]
        );
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.baseline.last_backfill_result'],
            ['value' => ['value' => [
                'days' => $days,
                'limit' => $limit,
                'processed' => $processed,
                'failed' => $failed,
                'window_start' => $windowStart->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]]]
        );
    }
}

