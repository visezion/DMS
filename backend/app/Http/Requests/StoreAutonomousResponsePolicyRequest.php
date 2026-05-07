<?php

namespace App\Http\Requests;

use App\Domain\Autonomy\Enums\AutonomousDecisionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutonomousResponsePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'scope_type' => ['required', 'string', Rule::in(['global', 'tenant', 'group', 'device', 'incident_type', 'finding_type'])],
            'scope_id' => ['nullable', 'string', 'max:80'],
            'trigger_type' => ['required', 'string', 'max:80'],
            'minimum_risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'allowed_actions' => ['nullable', 'array'],
            'allowed_actions.*' => ['string', 'max:80'],
            'blocked_actions' => ['nullable', 'array'],
            'blocked_actions.*' => ['string', 'max:80'],
            'autonomy_mode' => ['required', 'string', Rule::in(AutonomousDecisionMode::all())],
            'minimum_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'requires_rollback_plan' => ['nullable', 'boolean'],
            'max_actions_per_hour' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
