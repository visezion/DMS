<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutonomousDecision extends Model
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
            'input_context' => 'array',
            'recommended_payload' => 'array',
            'alternative_actions' => 'array',
            'explanation' => 'array',
            'simulation' => 'boolean',
            'dry_run' => 'boolean',
            'confidence_score' => 'float',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AutonomousResponsePolicy::class, 'policy_id');
    }

    public function executionResults(): HasMany
    {
        return $this->hasMany(AutonomousExecutionResult::class, 'decision_id');
    }

    public function confidenceEvidence(): HasMany
    {
        return $this->hasMany(ConfidenceEvidence::class, 'decision_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(ThreatFinding::class, 'finding_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(CorrelatedIncident::class, 'incident_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
