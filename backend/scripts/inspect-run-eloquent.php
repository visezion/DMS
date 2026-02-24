<?php

declare(strict_types=1);

use App\Models\DmsJob;
use App\Models\JobRun;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$runId = $argv[1] ?? '';
if ($runId === '') {
    fwrite(STDERR, "Usage: php backend/scripts/inspect-run-eloquent.php <job_run_id>\n");
    exit(1);
}

$run = JobRun::query()->find($runId);
if (! $run) {
    fwrite(STDERR, "Run not found: {$runId}\n");
    exit(2);
}
$job = DmsJob::query()->find($run->job_id);
if (! $job) {
    fwrite(STDERR, "Job not found: {$run->job_id}\n");
    exit(3);
}

$payload = $job->payload;
$type = gettype($payload);
echo "run_id={$run->id}\n";
echo "job_id={$job->id}\n";
echo "payload_type={$type}\n";
if (is_array($payload)) {
    echo "payload_json=".json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} else {
    echo "payload_raw=".var_export($payload, true)."\n";
}
