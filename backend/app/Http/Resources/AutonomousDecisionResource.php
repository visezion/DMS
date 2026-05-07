<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutonomousDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'device_id' => $this->device_id,
            'incident_id' => $this->incident_id,
            'finding_id' => $this->finding_id,
            'policy_id' => $this->policy_id,
            'trigger_source' => $this->trigger_source,
            'input_context' => $this->input_context ?? [],
            'recommended_action' => $this->recommended_action,
            'recommended_payload' => $this->recommended_payload ?? [],
            'alternative_actions' => $this->alternative_actions ?? [],
            'confidence_score' => (float) $this->confidence_score,
            'rationale' => $this->rationale,
            'explanation' => $this->explanation ?? [],
            'decision_mode' => $this->decision_mode,
            'status' => $this->status,
            'simulation' => (bool) $this->simulation,
            'dry_run' => (bool) $this->dry_run,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'executed_at' => $this->executed_at,
            'execution_reference' => $this->execution_reference,
            'rollback_reference' => $this->rollback_reference,
            'failure_reason' => $this->failure_reason,
            'evidence' => ConfidenceEvidenceResource::collection($this->whenLoaded('confidenceEvidence')),
            'execution_results' => AutonomousExecutionResultResource::collection($this->whenLoaded('executionResults')),
            'created_at' => $this->created_at,
        ];
    }
}
