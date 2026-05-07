<?php

namespace App\Observers;

use App\Events\AutonomousTriggerDetected;
use App\Models\ThreatFinding;

class ThreatFindingObserver
{
    public function created(ThreatFinding $finding): void
    {
        AutonomousTriggerDetected::dispatch([
            'trigger_source' => 'finding_created',
            'trigger_type' => (string) $finding->finding_type,
            'finding_id' => $finding->id,
            'device_id' => $finding->device_id,
            'tenant_id' => $finding->tenant_id,
            'severity' => $finding->severity,
        ]);
    }
}
