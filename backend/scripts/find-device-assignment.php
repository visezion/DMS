<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$device = App\Models\Device::query()->first();
if (!$device) { echo "NO_DEVICE\n"; exit; }
$assignment = DB::table('policy_assignments')->where('target_type','device')->where('target_id',$device->id)->first();
if (!$assignment) { echo "NO_ASSIGNMENT\n"; exit; }

echo "DEVICE={$device->id}\n";
echo "ASSIGNMENT={$assignment->id}\n";
