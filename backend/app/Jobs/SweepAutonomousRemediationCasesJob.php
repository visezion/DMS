<?php

namespace App\Jobs;

use App\Models\BehaviorAnomalyCase;
use App\Models\ControlPlaneSetting;
use App\Services\BehaviorPipeline\AutonomousRemediationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

class SweepAutonomousRemediationCasesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly int $days = 14,
        private readonly int $limit = 2000,
        private readonly bool $pendingOnly = true,
    ) {
        $this->onQueue('horizon');
    }

    public function handle(AutonomousRemediationEngine $engine): void
    {
        if (! Schema::hasTable('behavior_anomaly_cases') || ! Schema::hasTable('behavior_remediation_executions')) {
            return;
        }

        $days = max(1, min(365, $this->days));
        $limit = max(10, min(50000, $this->limit));
        $windowStart = now()->subDays($days);
        $statuses = $this->pendingOnly
            ? ['pending_review']
            : ['pending_review', 'approved', 'auto_applied'];

        $cases = BehaviorAnomalyCase::query()
            ->whereIn('status', $statuses)
            ->where('detected_at', '>=', $windowStart)
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get();

        $scanned = 0;
        $executedCases = 0;
        $executionsCreated = 0;
        $failedCases = 0;

        foreach ($cases as $case) {
            $scanned++;
            try {
                $created = $engine->executeForCase($case);
                $count = $created->count();
                if ($count > 0) {
                    $executedCases++;
                    $executionsCreated += $count;
                }
            } catch (\Throwable $e) {
                $failedCases++;
                report($e);
            }
        }

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.remediation.last_sweep_completed_at'],
            ['value' => ['value' => now()->toIso8601String()]]
        );
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.remediation.last_sweep_result'],
            ['value' => ['value' => [
                'days' => $days,
                'limit' => $limit,
                'pending_only' => $this->pendingOnly,
                'statuses' => $statuses,
                'scanned' => $scanned,
                'executed_cases' => $executedCases,
                'executions_created' => $executionsCreated,
                'failed_cases' => $failedCases,
                'window_start' => $windowStart->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]]]
        );
    }
}

