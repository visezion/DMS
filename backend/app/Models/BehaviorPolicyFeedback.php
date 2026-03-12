<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorPolicyFeedback extends Model
{
    use HasFactory;
    use HasUuids;

    public $timestamps = false;
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(BehaviorPolicyRecommendation::class, 'recommendation_id');
    }

    public function anomalyCase(): BelongsTo
    {
        return $this->belongsTo(BehaviorAnomalyCase::class, 'anomaly_case_id');
    }
}

