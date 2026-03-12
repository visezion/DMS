<?php

use App\Jobs\BackfillBehaviorDatasetJob;
use App\Jobs\ProcessBehaviorEventStreamJob;
use App\Jobs\RetrainAdaptiveBehaviorModelJob;
use App\Models\ControlPlaneSetting;
use App\Services\CommandEnvelopeSigner;
use App\Services\BehaviorPipeline\AdaptiveLearningService;
use App\Services\BehaviorAiModelTrainer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('dms:keys:rotate {kid?}', function (CommandEnvelopeSigner $signer) {
    $key = $signer->rotate($this->argument('kid'));
    $this->info('Activated signing key: '.$key->kid);
})->purpose('Rotate DMS command-signing key');

Artisan::command('dms:behavior:dataset:backfill {--days=30}', function () {
    $days = (int) $this->option('days');
    BackfillBehaviorDatasetJob::dispatch(max(1, $days));
    $this->info('Queued behavior dataset backfill job.');
})->purpose('Rebuild behavior training dataset from stored behavior logs');

Artisan::command('dms:behavior:train-ai {--days=30} {--min-events=200}', function (BehaviorAiModelTrainer $trainer) {
    $days = (int) $this->option('days');
    $minEvents = (int) $this->option('min-events');
    $result = $trainer->train($days, $minEvents);

    ControlPlaneSetting::query()->updateOrCreate(
        ['key' => 'behavior.ai_model_path'],
        ['value' => ['value' => (string) $result['path']], 'updated_by' => null]
    );
    ControlPlaneSetting::query()->updateOrCreate(
        ['key' => 'behavior.ai_model_trained_at'],
        ['value' => ['value' => (string) $result['trained_at']], 'updated_by' => null]
    );

    $this->info('Behavior AI model trained successfully.');
    $this->line('Model path: '.$result['path']);
    $this->line('Events used: '.(string) $result['events']);
    $this->line('Trained at: '.$result['trained_at']);
})->purpose('Train AI anomaly model from behavior dataset');

Artisan::command('dms:behavior:pipeline:retrain {--days=45} {--min-feedback=20}', function (AdaptiveLearningService $adaptiveLearningService) {
    $days = (int) $this->option('days');
    $minFeedback = (int) $this->option('min-feedback');
    $result = $adaptiveLearningService->retrain($days, $minFeedback);

    $this->info('Adaptive behavior model retrained successfully.');
    $this->line('Generated at: '.$result['generated_at']);
    $this->line('Feedback samples: '.(string) $result['feedback_samples']);
    $this->line('Recommended threshold: '.(string) $result['recommended_threshold']);
})->purpose('Retrain adaptive model from human policy feedback');

Artisan::command('dms:behavior:pipeline:queue-retrain {--days=45} {--min-feedback=20}', function () {
    $days = (int) $this->option('days');
    $minFeedback = (int) $this->option('min-feedback');
    RetrainAdaptiveBehaviorModelJob::dispatch(max(7, $days), max(5, $minFeedback))->onQueue('horizon');

    $this->info('Queued adaptive retraining job.');
})->purpose('Queue adaptive behavior model retraining');

Artisan::command('dms:behavior:pipeline:replay-stream {--limit=500}', function () {
    $limit = max(1, min(5000, (int) $this->option('limit')));
    $streamIds = \App\Models\AiEventStream::query()
        ->whereIn('status', ['queued', 'failed'])
        ->orderBy('created_at')
        ->limit($limit)
        ->pluck('id')
        ->values();

    foreach ($streamIds as $streamId) {
        ProcessBehaviorEventStreamJob::dispatch((string) $streamId)->onQueue('horizon');
    }

    $this->info('Queued '.$streamIds->count().' stream events for processing.');
})->purpose('Replay queued or failed stream events through AI pipeline');
