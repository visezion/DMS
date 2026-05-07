<?php

namespace App\Jobs;

use App\Domain\Autonomy\AutonomousResponseEngine;
use App\Domain\Autonomy\Enums\AutonomousDecisionStatus;
use App\Jobs\ExecuteAutonomousDecisionJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateAutonomousDecisionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public array $payload
    ) {
    }

    public function handle(AutonomousResponseEngine $engine): void
    {
        $decision = $engine->evaluate($this->payload);

        if ($decision instanceof \App\Models\AutonomousDecision
            && $decision->status === AutonomousDecisionStatus::EXECUTING
            && ! $decision->simulation
            && ! $decision->dry_run) {
            ExecuteAutonomousDecisionJob::dispatch($decision->id);
        }
    }
}
