<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RiskActionMapping extends Model
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
            'candidate_actions' => 'array',
            'preconditions' => 'array',
            'rollback_metadata' => 'array',
            'enabled' => 'boolean',
            'minimum_risk_score' => 'float',
            'maximum_risk_score' => 'float',
        ];
    }
}
