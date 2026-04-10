<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemediationPlan extends Model
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
            'summary' => 'array',
            'dry_run' => 'boolean',
            'simulation' => 'boolean',
            'requires_approval' => 'boolean',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RemediationAction::class, 'plan_id')->orderBy('action_order');
    }
}
