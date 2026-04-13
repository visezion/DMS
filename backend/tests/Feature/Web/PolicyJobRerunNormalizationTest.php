<?php

namespace Tests\Feature\Web;

use App\Models\Device;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicyJobRerunNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rerun_job_normalizes_scheduled_task_command_escaping_for_apply_policy_payload(): void
    {
        $user = User::factory()->create();
        $device = $this->createDevice();
        $source = $this->createApplyPolicyJobWithPlainScheduledTaskCommand($device->id);

        $this->actingAs($user)
            ->post(route('admin.jobs.rerun', $source->id))
            ->assertRedirect();

        $cloned = DmsJob::query()
            ->where('id', '!=', $source->id)
            ->where('job_type', 'apply_policy')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($cloned);
        $command = (string) data_get($cloned?->payload, 'rules.0.config.command', '');
        $this->assertStringContainsString('\\"Daily non-persistent lab reset\\"', $command);
    }

    public function test_rerun_specific_run_normalizes_scheduled_task_command_escaping_for_apply_policy_payload(): void
    {
        $user = User::factory()->create();
        $device = $this->createDevice();
        $source = $this->createApplyPolicyJobWithPlainScheduledTaskCommand($device->id);
        $run = JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $source->id,
            'device_id' => $device->id,
            'status' => 'failed',
        ]);

        $this->actingAs($user)
            ->post(route('admin.job-runs.rerun', $run->id))
            ->assertRedirect();

        $cloned = DmsJob::query()
            ->where('id', '!=', $source->id)
            ->where('job_type', 'apply_policy')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($cloned);
        $command = (string) data_get($cloned?->payload, 'rules.0.config.command', '');
        $this->assertStringContainsString('\\"Daily non-persistent lab reset\\"', $command);
    }

    private function createDevice(): Device
    {
        return Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'RERUN-SCHEDULED-TASK-PC',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '2.0.0',
            'status' => 'online',
        ]);
    }

    private function createApplyPolicyJobWithPlainScheduledTaskCommand(string $deviceId): DmsJob
    {
        return DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'apply_policy',
            'status' => 'failed',
            'priority' => 100,
            'payload' => [
                'policy_version_id' => (string) Str::uuid(),
                'rules' => [[
                    'type' => 'scheduled_task',
                    'config' => [
                        'task_name' => 'LabDailyReboot',
                        'ensure' => 'present',
                        'schedule' => 'daily',
                        'command' => 'shutdown.exe /r /t 60 /f /c "Daily non-persistent lab reset"',
                        'time' => '01:34',
                    ],
                    'enforce' => true,
                ]],
            ],
            'target_type' => 'device',
            'target_id' => $deviceId,
        ]);
    }
}

