<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutonomousResponsePolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'trigger_type' => $this->trigger_type,
            'minimum_risk_score' => (float) $this->minimum_risk_score,
            'allowed_actions' => $this->allowed_actions ?? [],
            'blocked_actions' => $this->blocked_actions ?? [],
            'autonomy_mode' => $this->autonomy_mode,
            'minimum_confidence' => (float) $this->minimum_confidence,
            'requires_rollback_plan' => (bool) $this->requires_rollback_plan,
            'max_actions_per_hour' => (int) $this->max_actions_per_hour,
            'cooldown_minutes' => (int) $this->cooldown_minutes,
            'enabled' => (bool) $this->enabled,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at,
        ];
    }
}
