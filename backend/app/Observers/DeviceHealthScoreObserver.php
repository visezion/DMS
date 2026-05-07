<?php

namespace App\Observers;

use App\Events\AutonomousTriggerDetected;
use App\Models\DeviceHealthScore;

class DeviceHealthScoreObserver
{
    public function created(DeviceHealthScore $score): void
    {
        $band = strtolower((string) $score->band);
        if ($band !== strtolower((string) config('autonomous_response.health_degradation_band', 'critical'))) {
            return;
        }

        AutonomousTriggerDetected::dispatch([
            'trigger_source' => 'health_degradation',
            'trigger_type' => 'agent_health_degradation',
            'device_id' => $score->device_id,
            'tenant_id' => $score->tenant_id,
            'severity' => $band,
            'risk_score' => $score->predicted_failure_risk,
        ]);
    }
}
