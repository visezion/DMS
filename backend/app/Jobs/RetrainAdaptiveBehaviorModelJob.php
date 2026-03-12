<?php

namespace App\Jobs;

use App\Services\BehaviorPipeline\AdaptiveLearningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetrainAdaptiveBehaviorModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $windowDays = 45,
        private readonly int $minFeedback = 20,
    ) {
        $this->onQueue('horizon');
    }

    public function handle(AdaptiveLearningService $adaptiveLearningService): void
    {
        $adaptiveLearningService->retrain($this->windowDays, $this->minFeedback);
    }
}
