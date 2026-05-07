<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrelatedIncident extends Model
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
            'root_cause' => 'array',
            'confidence' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(IncidentTimeline::class, 'incident_id')->orderByDesc('version');
    }

    public function autonomousDecisions(): HasMany
    {
        return $this->hasMany(AutonomousDecision::class, 'incident_id');
    }
}
