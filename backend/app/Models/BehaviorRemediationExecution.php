<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorRemediationExecution extends Model
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
            'trigger_score' => 'float',
            'payload' => 'array',
            'detected_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function anomalyCase(): BelongsTo
    {
        return $this->belongsTo(BehaviorAnomalyCase::class, 'anomaly_case_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(DmsJob::class, 'dispatched_job_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}

