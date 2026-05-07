<?php

namespace App\Observers;

use App\Events\AutonomousTriggerDetected;
use App\Models\DeviceRiskScore;

class DeviceRiskScoreObserver
{
    public function created(DeviceRiskScore $score): void
    {
        if ((float) $score->score < (float) config('autonomous_response.risk_spike_threshold', 75)) {
            return;
        }

        AutonomousTriggerDetected::dispatch([
            'trigger_source' => 'risk_spike',
            'trigger_type' => 'sharply_elevated_device_risk',
            'device_id' => $score->device_id,
            'tenant_id' => $score->tenant_id,
            'severity' => $score->severity,
            'risk_score' => $score->score,
        ]);
    }
}
