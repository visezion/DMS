<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRiskActionMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'trigger_type' => ['required', 'string', 'max:80'],
            'minimum_severity' => ['nullable', 'string', 'max:16'],
            'maximum_severity' => ['nullable', 'string', 'max:16'],
            'minimum_risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'maximum_risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'candidate_actions' => ['required', 'array', 'min:1'],
            'candidate_actions.*.action_key' => ['required', 'string', 'max:80'],
            'candidate_actions.*.priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'candidate_actions.*.payload' => ['nullable', 'array'],
            'candidate_actions.*.preconditions' => ['nullable', 'array'],
            'candidate_actions.*.rollback_metadata' => ['nullable', 'array'],
            'preconditions' => ['nullable', 'array'],
            'rollback_metadata' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
