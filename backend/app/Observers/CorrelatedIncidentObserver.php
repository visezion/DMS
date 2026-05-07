<?php

namespace App\Observers;

use App\Events\AutonomousTriggerDetected;
use App\Models\CorrelatedIncident;

class CorrelatedIncidentObserver
{
    public function created(CorrelatedIncident $incident): void
    {
        AutonomousTriggerDetected::dispatch([
            'trigger_source' => 'incident_created',
            'trigger_type' => (string) (data_get($incident->root_cause, 'trigger_type') ?: 'incident_detected'),
            'incident_id' => $incident->id,
            'device_id' => $incident->primary_device_id,
            'tenant_id' => $incident->tenant_id,
            'severity' => $incident->severity,
        ]);
    }
}
