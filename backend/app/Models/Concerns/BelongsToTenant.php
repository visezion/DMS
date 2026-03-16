<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait BelongsToTenant
{
    /**
     * @var array<string,bool>
     */
    protected static array $tenantColumnCache = [];

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $model = $builder->getModel();
            if (! static::hasTenantColumn($model)) {
                return;
            }

            $tenantId = app(TenantContext::class)->tenantId();
            if ($tenantId === null) {
                return;
            }

            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function (Model $model): void {
            if (! static::hasTenantColumn($model)) {
                return;
            }

            $tenantId = app(TenantContext::class)->tenantId();
            if ($tenantId === null) {
                return;
            }

            if (empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query
            ->withoutGlobalScope('tenant')
            ->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    protected static function hasTenantColumn(Model $model): bool
    {
        $table = $model->getTable();
        if (array_key_exists($table, static::$tenantColumnCache)) {
            return static::$tenantColumnCache[$table];
        }

        return static::$tenantColumnCache[$table] = Schema::hasColumn($table, 'tenant_id');
    }
}
