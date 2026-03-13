<?php

namespace App\Jobs;

use App\Models\AiEventStream;
use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\AnomalyDetectionEngine;
use App\Services\BehaviorPipeline\AutonomousRemediationEngine;
use App\Services\BehaviorPipeline\BehavioralBaselineModelingService;
use App\Services\BehaviorPipeline\BehaviorPolicyDecisionService;
use App\Services\BehaviorPipeline\PolicyRecommendationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBehaviorEventStreamJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly string $streamId)
    {
        $this->onQueue('horizon');
    }

    public function handle(
        AnomalyDetectionEngine $anomalyDetectionEngine,
        PolicyRecommendationEngine $policyRecommendationEngine,
        BehaviorPolicyDecisionService $decisionService,
        AutonomousRemediationEngine $autonomousRemediationEngine,
        BehavioralBaselineModelingService $baselineModelingService,
    ): void {
        $stream = AiEventStream::query()->find($this->streamId);
        if (! $stream) {
            return;
        }

        if ($stream->status === 'completed') {
            return;
        }

        $stream->status = 'processing';
        $stream->attempts = (int) $stream->attempts + 1;
        $stream->last_error = null;
        $stream->save();

        try {
            $event = DeviceBehaviorLog::query()->find($stream->behavior_log_id);
            if (! $event) {
                throw new \RuntimeException('Behavior log not found for stream event.');
            }

            $case = $anomalyDetectionEngine->detectAndPersist($stream, $event);
            if ($case !== null) {
                if ($case->status === 'pending_review') {
                    $policyRecommendationEngine->recommend($case);
                    $decisionService->maybeAutoApply($case);
                }

                // Autonomous remediation must never block core stream processing.
                try {
                    $autonomousRemediationEngine->executeForCase($case);
                } catch (\Throwable $remediationError) {
                    report($remediationError);
                }
            }

            // Baseline modeling must never block core stream processing.
            try {
                $baselineModelingService->ingestOutcome($event, $case);
            } catch (\Throwable $baselineError) {
                report($baselineError);
            }

            $stream->status = 'completed';
            $stream->processed_at = now();
            $stream->save();
        } catch (\Throwable $e) {
            $isFinalAttempt = $this->attempts() >= $this->tries;
            $stream->status = $isFinalAttempt ? 'failed' : 'queued';
            $stream->last_error = mb_substr($e->getMessage(), 0, 4000);
            $stream->save();

            if (! $isFinalAttempt) {
                throw $e;
            }
        }
    }
}
