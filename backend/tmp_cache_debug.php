<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$session = '5d332d2f-f19b-4387-96d2-b035a8e8cc92';
$key = 'remote_support:realtime:signals_admin_to_agent:' . $session;
$seqKey = 'remote_support:realtime:seq:signals_admin_to_agent:' . $session;
echo 'seq=' . Illuminate\Support\Facades\Cache::get($seqKey) . PHP_EOL;
$events = Illuminate\Support\Facades\Cache::get($key, []);
echo 'events=' . count($events) . PHP_EOL;
if (is_array($events)) {
    foreach (array_slice($events, -5) as $e) {
        echo json_encode($e) . PHP_EOL;
    }
}
