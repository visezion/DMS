<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceGroup;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GroupAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(DeviceGroup::query()->paginate(25));
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate(['name' => ['required', 'string'], 'description' => ['nullable', 'string']]);
        $group = DeviceGroup::query()->create(['id' => (string) Str::uuid(), ...$payload]);
        $auditLogger->log('group.create', 'device_group', $group->id, null, $group->toArray(), $request->user()?->id);
        return response()->json($group, 201);
    }
}
