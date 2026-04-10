<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActionGuardrail extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'arg_schema' => 'array',
            'forbidden_patterns' => 'array',
            'allow_conditions' => 'array',
            'deny_conditions' => 'array',
            'requires_rollback_plan' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
