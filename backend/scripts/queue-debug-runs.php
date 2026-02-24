<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\DmsJob; use App\Models\JobRun; use Illuminate\Support\Str;
$device='11111111-1111-4111-8111-111111111111';
$policyJob=DmsJob::query()->create(['id'=>(string)Str::uuid(),'job_type'=>'apply_policy','status'=>'queued','priority'=>100,'payload'=>['policy_version_id'=>(string)Str::uuid(),'rules'=>[['type'=>'registry','config'=>['path'=>'HKLM\\SOFTWARE\\DMS','name'=>'E2ESigDebug','type'=>'DWORD','value'=>1],'enforce'=>true]]],'target_type'=>'device','target_id'=>$device,'created_by'=>null]);
$policyRun=JobRun::query()->create(['id'=>(string)Str::uuid(),'job_id'=>$policyJob->id,'device_id'=>$device,'status'=>'pending','attempt_count'=>0,'next_retry_at'=>null]);
$pkgJob=DmsJob::query()->create(['id'=>(string)Str::uuid(),'job_type'=>'install_package','status'=>'queued','priority'=>100,'payload'=>['package_id'=>(string)Str::uuid(),'package_version_id'=>(string)Str::uuid(),'winget_id'=>'Notepad++.Notepad++'],'target_type'=>'device','target_id'=>$device,'created_by'=>null]);
$pkgRun=JobRun::query()->create(['id'=>(string)Str::uuid(),'job_id'=>$pkgJob->id,'device_id'=>$device,'status'=>'pending','attempt_count'=>0,'next_retry_at'=>null]);
echo $policyRun->id,PHP_EOL,$pkgRun->id,PHP_EOL;
