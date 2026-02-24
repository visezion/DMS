<?php

declare(strict_types=1);

use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DmsJob;
use App\Models\JobRun;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function out(string $msg): void
{
    fwrite(STDOUT, $msg.PHP_EOL);
}

$targetId = $argv[1] ?? '';
$timeoutSeconds = isset($argv[2]) ? max(30, (int) $argv[2]) : 180;

$device = null;
if ($targetId !== '') {
    $device = Device::query()->find($targetId);
}
if (! $device) {
    $device = Device::query()
        ->where('status', 'online')
        ->orderByDesc('last_seen_at')
        ->first();
}
if (! $device) {
    out('ERROR: No online device found.');
    exit(2);
}

ControlPlaneSetting::query()->updateOrCreate(
    ['key' => 'security.signature_bypass_enabled'],
    ['value' => ['value' => false], 'updated_by' => null]
);
out('Bypass setting forced to disabled in control-plane settings.');
out('Target device: '.$device->id.' ('.$device->hostname.')');

$createdBy = null;

$policyJob = DmsJob::query()->create([
    'id' => (string) Str::uuid(),
    'job_type' => 'apply_policy',
    'status' => 'queued',
    'priority' => 100,
    'payload' => [
        'policy_version_id' => (string) Str::uuid(),
        'rules' => [[
            'type' => 'registry',
            'config' => [
                'path' => 'HKLM\\SOFTWARE\\DMS',
                'name' => 'E2EPolicyTest',
                'type' => 'DWORD',
                'value' => 1,
            ],
            'enforce' => true,
        ]],
    ],
    'target_type' => 'device',
    'target_id' => $device->id,
    'created_by' => $createdBy,
]);
$policyRun = JobRun::query()->create([
    'id' => (string) Str::uuid(),
    'job_id' => $policyJob->id,
    'device_id' => $device->id,
    'status' => 'pending',
    'attempt_count' => 0,
    'next_retry_at' => null,
]);

$packageJob = DmsJob::query()->create([
    'id' => (string) Str::uuid(),
    'job_type' => 'install_exe',
    'status' => 'queued',
    'priority' => 100,
    'payload' => [
        // Keep E2E independent from winget PATH/user-profile differences.
        'path' => 'C:\\Windows\\System32\\cmd.exe',
        'silent_args' => '/c exit 0',
        'file_name' => 'cmd.exe',
    ],
    'target_type' => 'device',
    'target_id' => $device->id,
    'created_by' => $createdBy,
]);
$packageRun = JobRun::query()->create([
    'id' => (string) Str::uuid(),
    'job_id' => $packageJob->id,
    'device_id' => $device->id,
    'status' => 'pending',
    'attempt_count' => 0,
    'next_retry_at' => null,
]);

out('Queued policy run: '.$policyRun->id);
out('Queued package run: '.$packageRun->id);
out('Waiting up to '.$timeoutSeconds.'s for results...');

$runIds = [$policyRun->id, $packageRun->id];
$deadline = time() + $timeoutSeconds;
while (time() <= $deadline) {
    $runs = JobRun::query()->whereIn('id', $runIds)->get()->keyBy('id');
    $active = false;
    foreach ($runIds as $id) {
        $run = $runs->get($id);
        if (! $run) {
            continue;
        }
        if (in_array((string) $run->status, ['pending', 'acked', 'running'], true)) {
            $active = true;
            break;
        }
    }
    if (! $active) {
        break;
    }
    sleep(5);
}

$runs = JobRun::query()->whereIn('id', $runIds)->get()->keyBy('id');
foreach ($runIds as $id) {
    $run = $runs->get($id);
    if (! $run) {
        out('Run missing: '.$id);
        continue;
    }
    out('---');
    out('Run: '.$run->id);
    out('Status: '.(string) $run->status);
    out('Attempt: '.(string) ($run->attempt_count ?? 0));
    out('Last Error: '.(string) ($run->last_error ?? '-'));
    out('Next Retry: '.($run->next_retry_at ? $run->next_retry_at->toDateTimeString() : '-'));
    $payload = is_array($run->result_payload) ? $run->result_payload : null;
    if ($payload !== null) {
        out('Result Payload: '.json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}

out('Done.');
