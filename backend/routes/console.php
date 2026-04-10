<?php

use App\Jobs\BackfillBehaviorDatasetJob;
use App\Models\ApprovalRequest;
use App\Services\CommandEnvelopeSigner;
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

Artisan::command('dms:approvals:sweep', function () {
    $expired = ApprovalRequest::query()
        ->where('status', 'pending')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->update([
            'status' => 'expired',
            'decision_note' => 'Expired by scheduled approval sweep.',
            'decided_at' => now(),
        ]);

    $breached = ApprovalRequest::query()
        ->where('status', 'pending')
        ->where('created_at', '<=', now()->subMinutes(30))
        ->count();

    $this->info('Expired approvals: '.$expired);
    $this->info('Pending approvals past SLA: '.$breached);
})->purpose('Expire old approval requests and report SLA breaches')->everyFiveMinutes();
