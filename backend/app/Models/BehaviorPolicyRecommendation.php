<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BehaviorPolicyRecommendation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'rationale' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function anomalyCase(): BelongsTo
    {
        return $this->belongsTo(BehaviorAnomalyCase::class, 'anomaly_case_id');
    }

    public function feedbackEntries(): HasMany
    {
        return $this->hasMany(BehaviorPolicyFeedback::class, 'recommendation_id');
    }
}

