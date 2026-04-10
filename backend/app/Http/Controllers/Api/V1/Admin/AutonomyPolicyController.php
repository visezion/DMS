<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Remediation\AutonomyPolicyUpsertService;
use App\Http\Controllers\Controller;
use App\Models\AutonomyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutonomyPolicyController extends Controller
{
    public function __construct(
        private readonly AutonomyPolicyUpsertService $autonomyPolicies,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(AutonomyPolicy::query()->latest('updated_at')->get());
    }

    public function upsert(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scope_type' => ['required', 'in:global,tenant,group,device'],
            'scope_id' => ['nullable', 'string', 'max:64', 'required_unless:scope_type,global'],
            'autonomy_level' => ['required', 'in:off,advisory,semi_auto,auto'],
            'allowed_actions' => ['nullable', 'array'],
            'blocked_conditions' => ['nullable', 'array'],
            'maintenance_windows' => ['nullable', 'array'],
            'max_parallel_actions' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $policy = $this->autonomyPolicies->upsert($payload);

        return response()->json($policy);
    }
}
