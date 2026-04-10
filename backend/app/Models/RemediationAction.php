<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemediationAction extends Model
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
            'args' => 'array',
            'guardrail_snapshot' => 'array',
            'approval_required' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RemediationPlan::class, 'plan_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(RemediationActionResult::class, 'action_id');
    }
}
