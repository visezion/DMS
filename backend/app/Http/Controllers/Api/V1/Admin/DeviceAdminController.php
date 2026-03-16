<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\EnrollmentToken;
use App\Support\TenantContext;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Device::query()->latest('updated_at')->paginate(25));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Device::query()->findOrFail($id));
    }

    public function update(Request $request, string $id, AuditLogger $auditLogger): JsonResponse
    {
        $device = Device::query()->findOrFail($id);
        $before = $device->toArray();
        $device->update($request->only(['status', 'meshcentral_device_id', 'tags']));
        $auditLogger->log('device.update', 'device', $device->id, $before, $device->toArray(), $request->user()?->id);

        return response()->json($device);
    }

    public function createEnrollmentToken(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $data = $request->validate([
            'expires_at' => ['nullable', 'date'],
            'tenant_id' => ['nullable', 'uuid'],
        ]);

        $effectiveTenantId = $tenantId ?? ($data['tenant_id'] ?? null);

        $rawToken = Str::random(64);
        $token = EnrollmentToken::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $effectiveTenantId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $data['expires_at'] ?? now()->addDay(),
            'created_by' => $request->user()?->id,
        ]);

        $auditLogger->log('enrollment_token.create', 'enrollment_token', $token->id, null, ['expires_at' => $token->expires_at], $request->user()?->id);

        return response()->json([
            'id' => $token->id,
            'token' => $rawToken,
            'expires_at' => $token->expires_at,
        ], 201);
    }
}
