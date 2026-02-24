<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$jobId = '5d5e9ab1-c339-450a-875e-47df05340260';
$runId = '3d2bae51-e477-43a6-b879-4e9352c49a1c';

$job = App\Models\DmsJob::find($jobId);
$run = App\Models\JobRun::find($runId);
$device = $run ? App\Models\Device::find($run->device_id) : null;

echo json_encode([
    'now' => now()->toDateTimeString(),
    'job_status' => $job?->status,
    'job_updated_at' => (string) ($job?->updated_at ?? ''),
    'run_status' => $run?->status,
    'attempt' => $run?->attempt_count,
    'next_retry_at' => $run?->next_retry_at,
    'last_error' => (string) ($run?->last_error ?? ''),
    'run_updated_at' => (string) ($run?->updated_at ?? ''),
    'device_hostname' => $device?->hostname,
    'device_status' => $device?->status,
    'last_seen_at' => $device?->last_seen_at,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
