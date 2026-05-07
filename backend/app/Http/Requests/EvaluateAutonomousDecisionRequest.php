<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EvaluateAutonomousDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trigger_source' => ['required', 'string', 'max:80'],
            'trigger_type' => ['required', 'string', 'max:80'],
            'device_id' => ['nullable', 'uuid'],
            'incident_id' => ['nullable', 'uuid'],
            'finding_id' => ['nullable', 'uuid'],
            'tenant_id' => ['nullable', 'uuid'],
            'severity' => ['nullable', 'string', 'max:16'],
            'risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'simulation' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'requested_mode' => ['nullable', 'string', 'max:32'],
        ];
    }
}
