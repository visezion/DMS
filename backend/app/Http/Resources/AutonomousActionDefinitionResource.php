<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutonomousActionDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource['key'] ?? $this->action_key,
            'display_name' => $this->resource['display_name'] ?? $this->display_name,
            'description' => $this->resource['description'] ?? $this->description,
            'supported_target_types' => $this->resource['supported_target_types'] ?? $this->supported_target_types,
            'required_parameters' => $this->resource['required_parameters'] ?? $this->required_parameters,
            'safety_class' => $this->resource['safety_class'] ?? $this->safety_class,
            'reversible' => $this->resource['reversible'] ?? $this->reversible,
            'recommended_approval_mode' => $this->resource['recommended_approval_mode'] ?? $this->recommended_approval_mode,
            'cooldown_minutes' => $this->resource['cooldown_minutes'] ?? $this->cooldown_minutes,
            'requires_online' => $this->resource['requires_online'] ?? $this->requires_online,
            'supports_offline' => $this->resource['supports_offline'] ?? $this->supports_offline,
            'tenant_compatible' => $this->resource['tenant_compatible'] ?? $this->tenant_compatible,
            'execution_strategy' => $this->resource['execution_strategy'] ?? $this->execution_strategy,
            'default_payload' => $this->resource['default_payload'] ?? $this->default_payload,
            'enabled' => $this->resource['enabled'] ?? $this->enabled,
        ];
    }
}
