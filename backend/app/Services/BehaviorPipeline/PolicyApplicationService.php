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
        array $metadata = [],
    ): DmsJob {
        $payload = array_merge([
            'policy_version_id' => $policyVersionId,
            'reason' => $reason,
            'anomaly_case_id' => $anomalyCaseId,
            'recommendation_id' => $recommendationId,
            'created_at' => now()->toIso8601String(),
        ], $metadata);

        return $this->queueJobToDevice('apply_policy', $payload, $deviceId, 85, $createdBy);
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $metadata
     */
    public function applyPolicyRulesToDevice(
        string $deviceId,
        array $rules,
        string $reason,
        ?int $createdBy,
        ?string $anomalyCaseId = null,
        ?string $policyVersionId = null,
        array $metadata = [],
    ): DmsJob {
        $payload = array_merge([
            'policy_version_id' => $policyVersionId,
            'reason' => $reason,
            'anomaly_case_id' => $anomalyCaseId,
            'created_at' => now()->toIso8601String(),
            'rules' => $rules,
        ], $metadata);

        return $this->queueJobToDevice('apply_policy', $payload, $deviceId, 92, $createdBy);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function queueJobToDevice(
        string $jobType,
        array $payload,
        string $deviceId,
        int $priority = 90,
        ?int $createdBy = null,
    ): DmsJob {
        $job = DmsJob::query()->create([
            'job_type' => $jobType,
            'status' => 'queued',
            'priority' => max(1, min(1000, $priority)),
            'payload' => $payload,
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
