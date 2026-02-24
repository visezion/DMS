<?php

namespace Tests\Feature\Api;

use App\Models\DeviceGroup;
use App\Models\DmsJob;
use App\Models\EnrollmentToken;
use App\Models\PackageModel;
use App\Models\PackageVersion;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndDeviceLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_simulated_end_device_full_policy_and_software_lifecycle(): void
    {
        $admin = User::query()->create([
            'name' => 'E2E Admin',
            'email' => 'e2e-admin@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($admin);

        $rawEnrollmentToken = Str::random(48);
        EnrollmentToken::query()->create([
            'id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $rawEnrollmentToken),
            'expires_at' => now()->addHours(6),
            'created_by' => $admin->id,
        ]);

        $enroll = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $rawEnrollmentToken,
            'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----test',
            'device_facts' => [
                'hostname' => 'E2E-WIN-01',
                'os_name' => 'Windows 11',
                'os_version' => '23H2',
                'serial_number' => 'E2E-SERIAL-01',
                'agent_version' => '2.0.2',
                'agent_build' => 'e2e-build-001',
            ],
        ]);
        $enroll->assertStatus(201);
        $deviceId = (string) $enroll->json('device_id');
        $this->assertNotSame('', $deviceId);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'E2E Devices',
            'description' => 'End-device e2e group',
        ]);
        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $deviceId,
            'created_at' => now(),
        ]);

        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'E2E Hide Drives',
            'slug' => 'e2e-hide-drives',
            'category' => 'education/lab_lockdown',
            'status' => 'active',
        ]);
        $policyVersion = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'version_number' => 1,
            'status' => 'active',
            'created_by' => $admin->id,
            'published_at' => now(),
        ]);
        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $policyVersion->id,
            'order_index' => 0,
            'rule_type' => 'registry',
            'rule_config' => [
                'path' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer',
                'name' => 'NoDrives',
                'type' => 'DWORD',
                'value' => 67108863,
            ],
            'enforce' => true,
        ]);

        $this->post(route('admin.groups.policies.add', $group->id), [
            'policy_version_id' => $policyVersion->id,
            'queue_now' => 1,
        ])->assertRedirect();

        $applyPolicyCommand = $this->pickCommandByType($deviceId, 'apply_policy');
        $this->assertFalse((bool) data_get($applyPolicyCommand, 'envelope.payload.cleanup', false));
        $this->completeCommand(
            $deviceId,
            $applyPolicyCommand,
            'success',
            ['compliance_status' => 'compliant', 'message' => 'Policy applied']
        );

        $groupPolicyAssignmentId = (string) DB::table('policy_assignments')
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->where('policy_version_id', $policyVersion->id)
            ->value('id');
        $this->assertNotSame('', $groupPolicyAssignmentId);

        $this->delete(route('admin.groups.policies.remove', [$group->id, $groupPolicyAssignmentId]))
            ->assertRedirect();

        $cleanupPolicyCommand = $this->pickCommandByType($deviceId, 'apply_policy', function (array $command) {
            return (bool) data_get($command, 'envelope.payload.cleanup', false);
        });
        $this->assertTrue((bool) data_get($cleanupPolicyCommand, 'envelope.payload.cleanup', false));
        $this->assertSame(
            'absent',
            strtolower((string) data_get($cleanupPolicyCommand, 'envelope.payload.rules.0.config.ensure', ''))
        );
        $this->completeCommand(
            $deviceId,
            $cleanupPolicyCommand,
            'success',
            ['compliance_status' => 'compliant', 'message' => 'Cleanup applied']
        );

        $package = PackageModel::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Notepad++',
            'slug' => 'notepadplusplus.notepadplusplus',
            'publisher' => 'Notepad++',
            'package_type' => 'winget',
            'is_active' => true,
        ]);
        $packageVersion = PackageVersion::query()->create([
            'id' => (string) Str::uuid(),
            'package_id' => $package->id,
            'version' => '8.6.0',
            'channel' => 'stable',
            'install_args' => ['winget_id' => 'Notepad++.Notepad++'],
            'uninstall_args' => ['winget_id' => 'Notepad++.Notepad++'],
            'detection_rules' => ['type' => 'version', 'value' => '8.6.0'],
            'is_deprecated' => false,
        ]);

        $this->post(route('admin.groups.packages.add', $group->id), [
            'package_version_id' => $packageVersion->id,
            'priority' => 100,
            'stagger_seconds' => 0,
            'expires_hours' => 24,
        ])->assertRedirect();

        $installPackageCommand = $this->pickCommandByType($deviceId, 'install_package');
        $this->assertSame(
            $packageVersion->id,
            (string) data_get($installPackageCommand, 'envelope.payload.package_version_id')
        );
        $this->completeCommand(
            $deviceId,
            $installPackageCommand,
            'success',
            ['installed' => true, 'package_version_id' => $packageVersion->id]
        );

        $groupInstallJobId = (string) DmsJob::query()
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->where('job_type', 'install_package')
            ->where('payload->package_version_id', $packageVersion->id)
            ->latest('created_at')
            ->value('id');
        $this->assertNotSame('', $groupInstallJobId);

        $this->delete(route('admin.groups.packages.remove', [$group->id, $groupInstallJobId]))
            ->assertRedirect();

        $uninstallPackageCommand = $this->pickCommandByType($deviceId, 'uninstall_package');
        $this->assertSame(
            $packageVersion->id,
            (string) data_get($uninstallPackageCommand, 'envelope.payload.package_version_id')
        );
        $this->completeCommand(
            $deviceId,
            $uninstallPackageCommand,
            'success',
            ['removed' => true, 'package_version_id' => $packageVersion->id]
        );

        $this->assertDatabaseHas('devices', [
            'id' => $deviceId,
            'hostname' => 'E2E-WIN-01',
            'status' => 'online',
        ]);
        $this->assertDatabaseHas('job_runs', [
            'device_id' => $deviceId,
            'status' => 'success',
        ]);
    }

    private function pickCommandByType(string $deviceId, string $type, ?callable $filter = null): array
    {
        $checkin = $this->postJson('/api/v1/device/checkin', ['device_id' => $deviceId]);
        $checkin->assertStatus(200);
        $commands = $checkin->json('commands') ?? [];

        foreach ($commands as $command) {
            $commandType = strtolower((string) data_get($command, 'envelope.type', ''));
            if ($commandType !== strtolower($type)) {
                continue;
            }
            if ($filter !== null && ! $filter($command)) {
                continue;
            }
            return $command;
        }

        $available = collect($commands)->map(fn ($cmd) => (string) data_get($cmd, 'envelope.type', 'unknown'))->all();
        $this->fail('Expected command type ['.$type.'] not found. Available: '.implode(', ', $available));
    }

    private function completeCommand(string $deviceId, array $command, string $status, array $resultPayload): void
    {
        $jobRunId = (string) data_get($command, 'envelope.command_id', '');
        $this->assertNotSame('', $jobRunId);

        $this->postJson('/api/v1/device/job-ack', [
            'job_run_id' => $jobRunId,
            'device_id' => $deviceId,
        ])->assertStatus(200);

        $this->postJson('/api/v1/device/job-result', [
            'job_run_id' => $jobRunId,
            'device_id' => $deviceId,
            'status' => $status,
            'exit_code' => 0,
            'result_payload' => $resultPayload,
        ])->assertStatus(200);
    }
}

