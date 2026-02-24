<?php

declare(strict_types=1);

$runId = $argv[1] ?? '';
if ($runId === '') {
    fwrite(STDERR, "Usage: php backend/scripts/inspect-run.php <job_run_id>\n");
    exit(1);
}

$db = new PDO('sqlite:'.__DIR__.'/../database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$qRun = $db->prepare('select id, job_id, device_id, status, attempt_count, last_error, next_retry_at, updated_at from job_runs where id = :id');
$qRun->execute([':id' => $runId]);
$run = $qRun->fetch(PDO::FETCH_ASSOC);
if (! $run) {
    fwrite(STDERR, "Run not found: {$runId}\n");
    exit(2);
}

$qJob = $db->prepare('select id, job_type, payload, target_type, target_id, created_at from jobs where id = :id');
$qJob->execute([':id' => $run['job_id']]);
$job = $qJob->fetch(PDO::FETCH_ASSOC);

$qEvents = $db->prepare('select event_type, event_payload, created_at from job_events where job_run_id = :id order by created_at desc limit 10');
$qEvents->execute([':id' => $runId]);
$events = $qEvents->fetchAll(PDO::FETCH_ASSOC);

echo "RUN\n";
echo json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL;
echo "JOB\n";
echo json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL;
echo "EVENTS\n";
echo json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
