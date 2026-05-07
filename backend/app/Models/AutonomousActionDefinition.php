<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutonomousActionDefinition extends Model
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
            'supported_target_types' => 'array',
            'required_parameters' => 'array',
            'default_payload' => 'array',
            'reversible' => 'boolean',
            'requires_online' => 'boolean',
            'supports_offline' => 'boolean',
            'tenant_compatible' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
