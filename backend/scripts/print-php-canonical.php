<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$signer = app(App\Services\CommandEnvelopeSigner::class);
$data = json_decode(file_get_contents(__DIR__.'/command-sample-new.json'), true);
$env = $data['commands'][0]['envelope'];
$c = $signer->canonicalJson($env);
echo 'CANONICAL:'.PHP_EOL.$c.PHP_EOL;
echo 'CANONICAL_SHA256='.hash('sha256',$c).PHP_EOL;
echo 'DIGEST_SHA256='.hash('sha256',hash('sha256',$c,true)).PHP_EOL;
