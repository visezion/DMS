<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BehaviorAnomalyCase extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'risk_score' => 'float',
            'context' => 'array',
            'detector_weights' => 'array',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function signals(): HasMany
    {
        return $this->hasMany(BehaviorAnomalySignal::class, 'anomaly_case_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(BehaviorPolicyRecommendation::class, 'anomaly_case_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
