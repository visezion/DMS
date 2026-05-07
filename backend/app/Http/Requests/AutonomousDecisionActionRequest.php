<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutonomousDecisionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
