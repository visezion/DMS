<?php

namespace App\Models\Builders;

use App\Models\ControlPlaneSetting;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class ControlPlaneSettingBuilder extends Builder
{
    public function find($id, $columns = ['*'])
    {
        if (! is_string($id)) {
            return parent::find($id, $columns);
        }

        $scoped = $this->scopedKey($id);
        if ($scoped !== $id) {
            $setting = parent::find($scoped, $columns);
            if ($setting !== null) {
                return $setting;
            }
        }

        return parent::find($id, $columns);
    }

    public function updateOrCreate(array $attributes, array $values = [])
    {
        if (isset($attributes['key']) && is_string($attributes['key'])) {
            $tenantId = $this->tenantId();
            $attributes['key'] = $this->scopedKey($attributes['key']);
            if ($tenantId !== null) {
                $values['tenant_id'] = $tenantId;
            }
        }

        return parent::updateOrCreate($attributes, $values);
    }

    private function scopedKey(string $key): string
    {
        return ControlPlaneSetting::scopedKey($key, $this->tenantId());
    }

    private function tenantId(): ?string
    {
        if (! app()->bound(TenantContext::class)) {
            return null;
        }

        return app(TenantContext::class)->tenantId();
    }
}
