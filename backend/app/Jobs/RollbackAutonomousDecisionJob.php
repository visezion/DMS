<?php

namespace App\Jobs;

use App\Domain\Autonomy\AutonomousDecisionExecutor;
use App\Models\AutonomousDecision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RollbackAutonomousDecisionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $decisionId
    ) {
    }

    public function handle(AutonomousDecisionExecutor $executor): void
    {
        $decision = AutonomousDecision::query()->find($this->decisionId);
        if (! $decision) {
            return;
        }

        $executor->rollback($decision);
    }
}
