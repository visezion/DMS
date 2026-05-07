<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThreatFinding extends Model
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
            'evidence' => 'array',
            'confidence' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function autonomousDecisions(): HasMany
    {
        return $this->hasMany(AutonomousDecision::class, 'finding_id');
    }
}
