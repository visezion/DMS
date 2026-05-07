<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfidenceEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'factor_name' => $this->factor_name,
            'factor_weight' => (float) $this->factor_weight,
            'factor_value' => (float) $this->factor_value,
            'notes' => $this->notes ?? [],
        ];
    }
}
