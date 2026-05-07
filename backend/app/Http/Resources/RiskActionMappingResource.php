<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskActionMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'trigger_type' => $this->trigger_type,
            'minimum_severity' => $this->minimum_severity,
            'maximum_severity' => $this->maximum_severity,
            'minimum_risk_score' => (float) $this->minimum_risk_score,
            'maximum_risk_score' => $this->maximum_risk_score !== null ? (float) $this->maximum_risk_score : null,
            'candidate_actions' => $this->candidate_actions ?? [],
            'preconditions' => $this->preconditions ?? [],
            'rollback_metadata' => $this->rollback_metadata ?? [],
            'enabled' => (bool) $this->enabled,
            'priority' => (int) $this->priority,
            'updated_at' => $this->updated_at,
        ];
    }
}
