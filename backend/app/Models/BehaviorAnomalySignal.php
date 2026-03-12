<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorAnomalySignal extends Model
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
            'confidence' => 'float',
            'weight' => 'float',
            'details' => 'array',
        ];
    }

    public function anomalyCase(): BelongsTo
    {
        return $this->belongsTo(BehaviorAnomalyCase::class, 'anomaly_case_id');
    }
}

