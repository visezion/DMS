<?php

namespace App\Observers;

use App\Events\AutonomousTriggerDetected;
use App\Models\JobRun;

class JobRunObserver
{
    public function created(JobRun $jobRun): void
    {
        if ((string) $jobRun->status !== 'failed' || empty($jobRun->device_id)) {
            return;
        }

        $failedCount = JobRun::query()
            ->where('device_id', $jobRun->device_id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($failedCount < (int) config('autonomous_response.failed_jobs_threshold', 3)) {
            return;
        }

        AutonomousTriggerDetected::dispatch([
            'trigger_source' => 'job_failure_burst',
            'trigger_type' => 'repeated_agent_failure',
            'device_id' => $jobRun->device_id,
            'severity' => 'high',
        ]);
    }
}
