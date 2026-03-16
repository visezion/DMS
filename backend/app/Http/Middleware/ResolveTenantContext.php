<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\Device;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantContext = app(TenantContext::class);
        $tenantContext->setTenantId($this->resolveTenantId($request));

        return $next($request);
    }

    private function resolveTenantId(Request $request): ?string
    {
        $user = $request->user() ?? $this->resolveUserFromBearerToken($request);
        if ($user && ! empty($user->tenant_id)) {
            $tenantId = (string) $user->tenant_id;
            $this->storeSessionTenant($request, $tenantId);

            return $tenantId;
        }

        // Only authenticated users without a fixed tenant can switch context.
        if (! $user) {
            return $this->resolveTenantIdFromDevicePayload($request);
        }

        $sessionTenant = $this->readSessionTenant($request);
        if (is_string($sessionTenant) && $sessionTenant !== '' && $this->tenantExists($sessionTenant)) {
            return $sessionTenant;
        }

        $headerTenantId = trim((string) $request->header('X-Tenant-Id', ''));
        if ($headerTenantId !== '' && $this->tenantExists($headerTenantId)) {
            $this->storeSessionTenant($request, $headerTenantId);
            return $headerTenantId;
        }

        $headerTenantSlug = trim((string) $request->header('X-Tenant-Slug', ''));
        if ($headerTenantSlug !== '') {
            $tenantId = Tenant::query()
                ->where('slug', $headerTenantSlug)
                ->where('status', 'active')
                ->value('id');
            if (is_string($tenantId) && $tenantId !== '') {
                $this->storeSessionTenant($request, $tenantId);
                return $tenantId;
            }
        }

        return null;
    }

    private function resolveTenantIdFromDevicePayload(Request $request): ?string
    {
        $path = trim($request->path(), '/');
        if (! str_starts_with($path, 'api/v1/device/')) {
            return null;
        }

        $deviceId = trim((string) $request->input('device_id', ''));
        if ($deviceId === '') {
            return null;
        }

        $tenantId = Device::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $deviceId)
            ->value('tenant_id');

        return is_string($tenantId) && trim($tenantId) !== '' ? (string) $tenantId : null;
    }

    private function readSessionTenant(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get('active_tenant_id');

        return is_string($value) ? $value : null;
    }

    private function storeSessionTenant(Request $request, string $tenantId): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put('active_tenant_id', $tenantId);
    }

    private function tenantExists(string $tenantId): bool
    {
        return Tenant::query()
            ->where('id', $tenantId)
            ->where('status', 'active')
            ->exists();
    }

    private function resolveUserFromBearerToken(Request $request): mixed
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (! $accessToken) {
            return null;
        }

        return $accessToken->tokenable;
    }
}
