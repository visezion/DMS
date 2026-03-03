<?php

namespace Tests\Feature\Api;

use App\Models\DeviceGroup;
use App\Models\DmsJob;
use App\Models\EnrollmentToken;
use App\Models\JobRun;
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

class FullServerToAgentWorkflowE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_full_server_to_agent_workflow_covers_uwf_policy_package_deploy_and_operational_commands(): void
    {
        $admin = User::query()->create([
            'name' => 'Full E2E Admin',
            'email' => 'full-e2e-admin@example.com',
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
            'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----full-e2e-test',
            'device_facts' => [
                'hostname' => 'FULL-E2E-CLIENT-01',
                'os_name' => 'Windows 11',
                'os_version' => '23H2',
                'serial_number' => 'FULL-E2E-SERIAL-01',
                'agent_version' => '2.0.2',
                'agent_build' => 'full-e2e-build-001',
            ],
        ]);
        $enroll->assertStatus(201);
        $deviceId = (string) $enroll->json('device_id');
        $this->assertNotSame('', $deviceId);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Full E2E Group',
            'description' => 'Full server-to-agent e2e coverage group',
        ]);
        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $deviceId,
            'created_at' => now(),
        ]);

        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Full E2E UWF Policy',
            'slug' => 'full-e2e-uwf-policy',
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
            'rule_type' => 'uwf',
            'rule_config' => [
                'ensure' => 'present',
                'enable_feature' => true,
                'enable_filter' => true,
                'protect_volume' => true,
                'volume' => 'C:',
                'reboot_now' => true,
                'reboot_if_pending' => true,
                'max_reboot_attempts' => 2,
                'reboot_cooldown_minutes' => 30,
                'reboot_command' => 'shutdown.exe /r /t 30 /c "Enabling UWF protection"',
            ],
            'enforce' => true,
        ]);
        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $policyVersion->id,
            'order_index' => 1,
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
            'public_base_url' => 'http://127.0.0.1',
        ])->assertRedirect();

        $this->queueWebJob('run_command', 'device', $deviceId, [
            'script' => 'whoami',
        ], 95);
        $this->queueWebJob('reconcile_software_inventory', 'device', $deviceId, [], 95);
        $this->queueWebJob('create_snapshot', 'device', $deviceId, [
            'provider' => 'windows_restore_point',
            'label' => 'Full-E2E-PreChange',
            'dry_run' => true,
        ], 94);
        $this->queueWebJob('restore_snapshot', 'device', $deviceId, [
            'provider' => 'windows_restore_point',
            'restore_point_description' => 'Full-E2E-PreChange',
            'reboot_now' => false,
            'dry_run' => true,
        ], 94);
        $this->queueWebJob('update_agent', 'device', $deviceId, [
            'download_url' => 'https://example.invalid/dms-agent.zip',
            'sha256' => str_repeat('a', 64),
            'file_name' => 'dms-agent-test.zip',
            'release_id' => (string) Str::uuid(),
            'release_version' => '9.9.9',
        ], 96);
        $this->queueWebJob('uninstall_agent', 'device', $deviceId, [
            'service_name' => 'DMSAgent',
            'install_dir' => 'C:\\Program Files\\DMS Agent',
            'data_dir' => 'C:\\ProgramData\\DMS',
            'delete_device_after_uninstall' => false,
        ], 10);

        $seenTypes = [];
        $applyPolicyPayloads = [];

        $this->drainCommands($deviceId, $seenTypes, $applyPolicyPayloads);

        $groupPolicyAssignmentId = (string) DB::table('policy_assignments')
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->where('policy_version_id', $policyVersion->id)
            ->value('id');
        $this->assertNotSame('', $groupPolicyAssignmentId);
        $this->delete(route('admin.groups.policies.remove', [$group->id, $groupPolicyAssignmentId]))
            ->assertRedirect();

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

        $this->drainCommands($deviceId, $seenTypes, $applyPolicyPayloads);

        $uniqueTypes = array_values(array_unique($seenTypes));
        foreach ([
            'apply_policy',
            'install_package',
            'uninstall_package',
            'run_command',
            'reconcile_software_inventory',
            'create_snapshot',
            'restore_snapshot',
            'update_agent',
            'uninstall_agent',
        ] as $expectedType) {
            $this->assertContains($expectedType, $uniqueTypes, 'Expected command type not dispatched: '.$expectedType);
        }

        $enforceApplyPolicyPayload = collect($applyPolicyPayloads)->first(function (array $payload) {
            return ! (bool) ($payload['cleanup'] ?? false);
        });
        $cleanupApplyPolicyPayload = collect($applyPolicyPayloads)->first(function (array $payload) {
            return (bool) ($payload['cleanup'] ?? false);
        });

        $this->assertNotNull($enforceApplyPolicyPayload);
        $this->assertNotNull($cleanupApplyPolicyPayload);
        $enforceUwfRule = collect((array) ($enforceApplyPolicyPayload['rules'] ?? []))
            ->first(fn ($rule) => strtolower((string) ($rule['type'] ?? '')) === 'uwf');
        $cleanupAbsentRule = collect((array) ($cleanupApplyPolicyPayload['rules'] ?? []))
            ->first(fn ($rule) => strtolower((string) ($rule['config']['ensure'] ?? '')) === 'absent');

        $this->assertNotNull($enforceUwfRule);
        $this->assertNotNull($cleanupAbsentRule);
        $this->assertSame('present', strtolower((string) ($enforceUwfRule['config']['ensure'] ?? '')));
        $this->assertTrue((bool) ($enforceUwfRule['config']['enable_feature'] ?? false));
        $this->assertSame('c:', strtolower((string) ($enforceUwfRule['config']['volume'] ?? '')));

        $this->assertSame(
            0,
            JobRun::query()->where('device_id', $deviceId)->where('status', 'pending')->count(),
            'Expected all queued command runs to complete.'
        );
        $this->assertGreaterThan(
            0,
            JobRun::query()->where('device_id', $deviceId)->where('status', 'success')->count(),
            'Expected successful command runs for the full command matrix.'
        );

        $this->assertDatabaseHas('compliance_results', [
            'device_id' => $deviceId,
            'status' => 'compliant',
        ]);

        $finalCheckin = $this->postJson('/api/v1/device/checkin', ['device_id' => $deviceId]);
        $finalCheckin->assertStatus(200);
        $this->assertSame([], $finalCheckin->json('commands'));
    }

    private function queueWebJob(string $jobType, string $targetType, string $targetId, array $payload, int $priority = 100): void
    {
        $this->post(route('admin.jobs.create'), [
            'job_type' => $jobType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'priority' => $priority,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->assertRedirect();
    }

    private function drainCommands(string $deviceId, array &$seenTypes, array &$applyPolicyPayloads): void
    {
        for ($iteration = 0; $iteration < 30; $iteration++) {
            $checkin = $this->postJson('/api/v1/device/checkin', ['device_id' => $deviceId]);
            $checkin->assertStatus(200);
            $this->assertNotSame('', (string) $checkin->json('server_time'));

            $commands = $checkin->json('commands') ?? [];
            if ($commands === []) {
                return;
            }

            foreach ($commands as $command) {
                $type = strtolower((string) data_get($command, 'envelope.type', ''));
                $this->assertNotSame('', $type);
                $seenTypes[] = $type;

                $payload = data_get($command, 'envelope.payload');
                if ($type === 'apply_policy' && is_array($payload)) {
                    $applyPolicyPayloads[] = $payload;
                }

                $this->completeCommand(
                    $deviceId,
                    $command,
                    'success',
                    $this->resultPayloadForType($type)
                );
            }
        }

        $this->fail('Command drain loop exceeded safety limit (30 iterations).');
    }

    private function resultPayloadForType(string $type): array
    {
        return match ($type) {
            'apply_policy' => ['compliance_status' => 'compliant', 'message' => 'Policy processed'],
            'run_command' => ['stdout' => 'ok', 'stderr' => ''],
            'reconcile_software_inventory' => ['inventory' => [['name' => 'Notepad++', 'version' => '8.6.0']]],
            'create_snapshot' => ['snapshot_created' => true, 'dry_run' => true],
            'restore_snapshot' => ['snapshot_restored' => true, 'dry_run' => true, 'reboot_queued' => false],
            'install_package' => ['installed' => true],
            'uninstall_package' => ['removed' => true],
            'update_agent' => ['updated' => true, 'release_version' => '9.9.9'],
            'uninstall_agent' => ['removed' => true, 'reboot_queued' => false],
            default => ['ok' => true],
        };
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
