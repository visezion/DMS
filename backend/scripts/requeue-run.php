<?php

declare(strict_types=1);

$runId = $argv[1] ?? '';
if ($runId === '') {
    fwrite(STDERR, "Usage: php backend/scripts/requeue-run.php <job_run_id>\n");
    exit(1);
}

$dbPath = __DIR__.'/../database/database.sqlite';
$pdo = new PDO('sqlite:'.$dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$select = $pdo->prepare('select id, status, attempt_count, last_error, next_retry_at from job_runs where id = :id');
$select->execute([':id' => $runId]);
$row = $select->fetch(PDO::FETCH_ASSOC);
if (! $row) {
    fwrite(STDERR, "Job run not found: {$runId}\n");
    exit(2);
}

fwrite(STDOUT, "Before: ".json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL);

$update = $pdo->prepare("update job_runs set status='pending', last_error=NULL, next_retry_at=NULL where id = :id");
$update->execute([':id' => $runId]);

$select->execute([':id' => $runId]);
$after = $select->fetch(PDO::FETCH_ASSOC);
fwrite(STDOUT, "After: ".json_encode($after, JSON_UNESCAPED_SLASHES).PHP_EOL);
