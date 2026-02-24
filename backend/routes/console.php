<?php

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
