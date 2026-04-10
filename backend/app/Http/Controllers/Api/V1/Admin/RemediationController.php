<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Remediation\RemediationPlannerService;
use App\Http\Controllers\Controller;
use App\Models\ActionRollback;
use App\Models\AiRecommendation;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\RemediationAction;
use App\Models\RemediationPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RemediationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(RemediationPlan::query()->with('actions')->latest('created_at')->paginate(25));
    }

    public function createFromRecommendation(string $recommendationId, Request $request, RemediationPlannerService $planner): JsonResponse
    {
        $recommendation = AiRecommendation::query()->findOrFail($recommendationId);

        return response()->json($planner->createPlanFromRecommendation($recommendation, $request->user()), 201);
    }

    public function validatePlan(string $planId): JsonResponse
    {
        $plan = RemediationPlan::query()->with('actions')->findOrFail($planId);

        return response()->json([
            'plan' => $plan,
            'ready' => $plan->actions->every(fn (RemediationAction $action) => in_array($action->status, ['pending', 'queued'], true)),
        ]);
    }

    public function approve(string $planId, Request $request, RemediationPlannerService $planner): JsonResponse
    {
        $plan = RemediationPlan::query()->with('actions')->findOrFail($planId);

        return response()->json($planner->approve($plan, $request->user()));
    }

    public function execute(string $planId, Request $request, RemediationPlannerService $planner): JsonResponse
    {
        $plan = RemediationPlan::query()->with('actions')->findOrFail($planId);

        return response()->json($planner->execute($plan, $request->user()));
    }

    public function rollback(string $actionId): JsonResponse
    {
        $action = RemediationAction::query()->findOrFail($actionId);
        $latestResult = $action->results()->latest('created_at')->first();
        $rollbackHint = is_array(data_get($latestResult?->evidence ?? [], 'rollback_hint')) ? data_get($latestResult?->evidence ?? [], 'rollback_hint') : [];
        $rollbackActionType = (string) ($rollbackHint['job_type'] ?? 'manual_review');
        $rollbackArgs = is_array($rollbackHint['payload'] ?? null)
            ? $rollbackHint['payload']
            : (is_array($rollbackHint) ? $rollbackHint : ['action_id' => $action->id]);
        if ($rollbackActionType === 'run_command' && trim((string) ($rollbackArgs['script'] ?? '')) === '') {
            $rollbackCommand = trim((string) ($rollbackArgs['command'] ?? ''));
            if ($rollbackCommand !== '') {
                $rollbackArgs['script'] = $rollbackCommand;
                unset($rollbackArgs['command']);
            }
        }

        $rollback = ActionRollback::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $action->tenant_id,
            'action_result_id' => $latestResult?->id,
            'rollback_action_type' => $rollbackActionType,
            'rollback_args' => $rollbackArgs,
            'status' => ($rollbackHint['possible'] ?? false) ? 'queued' : 'requested',
            'result' => [],
            'started_at' => now(),
        ]);

        if (($rollbackHint['possible'] ?? false) && $action->target_device_id) {
            $job = DmsJob::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $action->tenant_id,
                'job_type' => $rollbackActionType === 'manual_review' ? 'run_command' : $rollbackActionType,
                'payload' => $rollbackArgs,
                'target_type' => 'device',
                'target_id' => $action->target_device_id,
                'priority' => 80,
                'status' => 'queued',
            ]);

            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $action->target_device_id,
                'status' => 'pending',
            ]);

            $rollback->update([
                'result' => [
                    'rollback_job_id' => $job->id,
                ],
            ]);
        }

        return response()->json($rollback, 201);
    }
}
