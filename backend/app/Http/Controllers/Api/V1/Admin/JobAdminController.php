<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JobAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(DmsJob::query()->latest('created_at')->paginate(25));
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate([
            'job_type' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'target_type' => ['required', 'in:device,group'],
            'target_id' => ['required', 'string'],
            'priority' => ['nullable', 'integer'],
        ]);

        $job = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => $payload['job_type'],
            'payload' => $payload['payload'],
            'target_type' => $payload['target_type'],
            'target_id' => $payload['target_id'],
            'priority' => $payload['priority'] ?? 100,
            'status' => 'queued',
            'created_by' => $request->user()?->id,
        ]);

        if ($job->target_type === 'device') {
            JobRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_id' => $job->id,
                'device_id' => $job->target_id,
                'status' => 'pending',
            ]);
        } else {
            $devices = Device::query()->whereIn('id', function ($q) use ($job) {
                $q->from('device_group_memberships')->select('device_id')->where('device_group_id', $job->target_id);
            })->get();
            foreach ($devices as $device) {
                JobRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'device_id' => $device->id,
                    'status' => 'pending',
                ]);
            }
        }

        $auditLogger->log('job.create', 'job', $job->id, null, $job->toArray(), $request->user()?->id);

        return response()->json($job, 201);
    }
}
