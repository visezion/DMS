<?php

namespace App\Support;

class TenantContext
{
    private ?string $tenantId = null;

    public function setTenantId(?string $tenantId): void
    {
        $value = is_string($tenantId) ? trim($tenantId) : null;
        $this->tenantId = $value !== '' ? $value : null;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }
}
