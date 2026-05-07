<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutonomousResponsePolicy extends Model
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
            'allowed_actions' => 'array',
            'blocked_actions' => 'array',
            'enabled' => 'boolean',
            'requires_rollback_plan' => 'boolean',
            'minimum_risk_score' => 'float',
            'minimum_confidence' => 'float',
        ];
    }
}
