<?php

namespace App\Jobs;

use App\Models\ControlPlaneSetting;
use App\Services\BehaviorAiModelTrainer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrainBehaviorAiModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $days = 30,
        private readonly int $minEvents = 200
    ) {
    }

    public function handle(BehaviorAiModelTrainer $trainer): void
    {
        $result = $trainer->train($this->days, $this->minEvents);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.ai_model_path'],
            ['value' => ['value' => (string) $result['path']], 'updated_by' => null]
        );
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.ai_model_trained_at'],
            ['value' => ['value' => (string) $result['trained_at']], 'updated_by' => null]
        );
    }
}
