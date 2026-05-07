<?php

namespace App\Listeners;

use App\Events\AutonomousTriggerDetected;
use App\Jobs\EvaluateAutonomousDecisionJob;

class QueueAutonomousEvaluation
{
    public function handle(AutonomousTriggerDetected $event): void
    {
        EvaluateAutonomousDecisionJob::dispatch($event->payload);
    }
}
