<?php

namespace App\Services\BehaviorPipeline;

use App\Models\DmsJob;
use App\Models\JobRun;

class PolicyApplicationService
{
    public function applyPolicyToDevice(
        string $deviceId,
        string $policyVersionId,
        string $reason,
        ?int $createdBy,
        ?string $anomalyCaseId = null,
        ?string $recommendationId = null,
    ): DmsJob {
        $job = DmsJob::query()->create([
            'job_type' => 'apply_policy',
            'status' => 'queued',
            'priority' => 85,
            'payload' => [
                'policy_version_id' => $policyVersionId,
                'reason' => $reason,
                'anomaly_case_id' => $anomalyCaseId,
                'recommendation_id' => $recommendationId,
                'created_at' => now()->toIso8601String(),
            ],
            'target_type' => 'device',
            'target_id' => $deviceId,
            'created_by' => $createdBy,
        ]);

        JobRun::query()->create([
            'job_id' => $job->id,
            'device_id' => $deviceId,
            'status' => 'pending',
        ]);

        return $job;
    }
}
