<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutonomousExecutionResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision_id' => $this->decision_id,
            'action_name' => $this->action_name,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'execution_status' => $this->execution_status,
            'command_payload' => $this->command_payload ?? [],
            'output_log' => $this->output_log,
            'rollback_available' => (bool) $this->rollback_available,
            'rollback_status' => $this->rollback_status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
