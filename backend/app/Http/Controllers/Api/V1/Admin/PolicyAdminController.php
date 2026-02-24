<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PolicyAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Policy::query()->paginate(25));
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string'],
            'slug' => ['required', 'string'],
            'category' => ['required', 'string'],
        ]);

        $policy = Policy::query()->create(['id' => (string) Str::uuid(), ...$payload]);
        $auditLogger->log('policy.create', 'policy', $policy->id, null, $policy->toArray(), $request->user()?->id);

        return response()->json($policy, 201);
    }

    public function createVersion(Request $request, string $policyId, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate([
            'version_number' => ['required', 'integer'],
            'rules' => ['required', 'array'],
        ]);

        $version = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policyId,
            'version_number' => $payload['version_number'],
            'status' => 'active',
            'created_by' => $request->user()?->id,
            'published_at' => now(),
        ]);

        foreach ($payload['rules'] as $idx => $rule) {
            PolicyRule::query()->create([
                'id' => (string) Str::uuid(),
                'policy_version_id' => $version->id,
                'order_index' => $idx,
                'rule_type' => $rule['type'],
                'rule_config' => $rule['config'] ?? [],
                'enforce' => $rule['mode'] !== 'audit',
            ]);
        }

        $auditLogger->log('policy.version.create', 'policy_version', $version->id, null, $version->toArray(), $request->user()?->id);
        return response()->json($version, 201);
    }
}
