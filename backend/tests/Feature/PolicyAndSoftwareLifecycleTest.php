<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\PackageFile;
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

class PolicyAndSoftwareLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_policy_add_and_remove_lifecycle_for_group_and_device_targets(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'TEST-DEVICE-01',
            'os_name' => 'Windows',
            'os_version' => '10.0.19045',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'QA Group',
            'description' => 'Lifecycle test group',
        ]);

        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Hide Drives',
            'slug' => 'hide-drives-test',
            'category' => 'education/lab_lockdown',
            'status' => 'active',
        ]);

        $version = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'version_number' => 1,
            'status' => 'active',
            'created_by' => $user->id,
            'published_at' => now(),
        ]);

        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $version->id,
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
            'policy_version_id' => $version->id,
            'queue_now' => 1,
        ])->assertRedirect();

        $groupAssignmentId = (string) DB::table('policy_assignments')
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->where('policy_version_id', $version->id)
            ->value('id');
        $this->assertNotSame('', $groupAssignmentId);

        $this->delete(route('admin.groups.policies.remove', [$group->id, $groupAssignmentId]))
            ->assertRedirect();

        $cleanupJob = DmsJob::query()
            ->where('job_type', 'apply_policy')
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('payload->cleanup', true)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($cleanupJob, 'Expected cleanup apply_policy job after group policy removal.');
        $cleanupPayload = is_array($cleanupJob->payload) ? $cleanupJob->payload : [];
        $cleanupRules = is_array($cleanupPayload['rules'] ?? null) ? $cleanupPayload['rules'] : [];
        $this->assertNotEmpty($cleanupRules);
        $this->assertSame('registry', strtolower((string) ($cleanupRules[0]['type'] ?? '')));
        $this->assertSame('absent', strtolower((string) (($cleanupRules[0]['config']['ensure'] ?? ''))));

        DB::table('policy_assignments')->insert([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $version->id,
            'target_type' => 'device',
            'target_id' => $device->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deviceAssignmentId = (string) DB::table('policy_assignments')
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('policy_version_id', $version->id)
            ->value('id');
        $this->assertNotSame('', $deviceAssignmentId);

        $this->delete(route('admin.devices.policies.remove', [$device->id, $deviceAssignmentId]))
            ->assertRedirect();

        $deviceCleanupJob = DmsJob::query()
            ->where('job_type', 'apply_policy')
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('payload->cleanup', true)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($deviceCleanupJob, 'Expected cleanup apply_policy job after device policy removal.');
    }

    public function test_software_add_and_remove_lifecycle_for_group_target(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester-software@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'TEST-DEVICE-02',
            'os_name' => 'Windows',
            'os_version' => '10.0.19045',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'QA Software Group',
            'description' => 'Package lifecycle test',
        ]);

        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $package = PackageModel::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Notepad++',
            'slug' => 'notepadplusplus.notepadplusplus',
            'publisher' => 'Notepad++',
            'package_type' => 'winget',
            'is_active' => true,
        ]);

        $version = PackageVersion::query()->create([
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
            'package_version_id' => $version->id,
            'priority' => 100,
            'stagger_seconds' => 0,
            'expires_hours' => 24,
            'public_base_url' => 'http://10.10.10.10',
        ])->assertRedirect();

        $installJob = DmsJob::query()
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->where('job_type', 'install_package')
            ->where('payload->package_version_id', $version->id)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($installJob, 'Expected install_package job when assigning package to group.');

        $this->assertDatabaseHas('job_runs', [
            'job_id' => $installJob->id,
            'device_id' => $device->id,
            'status' => 'pending',
        ]);

        $this->delete(route('admin.groups.packages.remove', [$group->id, $installJob->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('jobs', [
            'id' => $installJob->id,
        ]);

        $uninstallJob = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('job_type', 'uninstall_package')
            ->where('payload->package_version_id', $version->id)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($uninstallJob, 'Expected uninstall job after removing package assignment from group.');

        $this->assertDatabaseHas('job_runs', [
            'job_id' => $uninstallJob->id,
            'device_id' => $device->id,
            'status' => 'pending',
        ]);
    }

    public function test_custom_package_uninstall_uses_backward_compatible_command_fallbacks(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester-custom-uninstall@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'TEST-DEVICE-03',
            'os_name' => 'Windows',
            'os_version' => '10.0.19045',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'QA Custom Group',
            'description' => 'Custom uninstall fallback test',
        ]);

        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $package = PackageModel::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Legacy App',
            'slug' => 'legacy-app',
            'publisher' => 'Legacy Corp',
            'package_type' => 'exe',
            'is_active' => true,
        ]);

        $version = PackageVersion::query()->create([
            'id' => (string) Str::uuid(),
            'package_id' => $package->id,
            'version' => '1.0.0',
            'channel' => 'stable',
            'install_args' => [
                'silent_args' => '/S',
                'uninstall_command' => '"C:\\Program Files\\Legacy App\\uninstall.exe" /S',
            ],
            'uninstall_args' => [],
            'detection_rules' => ['type' => 'file', 'value' => 'C:\\Program Files\\Legacy App\\app.exe'],
            'is_deprecated' => false,
        ]);
        PackageFile::query()->create([
            'id' => (string) Str::uuid(),
            'package_version_id' => $version->id,
            'file_name' => 'legacy-app-1.0.0.exe',
            'source_uri' => 'https://example.invalid/legacy-app-1.0.0.exe',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'signature_metadata' => [],
        ]);

        $installJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'install_exe',
            'status' => 'success',
            'priority' => 100,
            'payload' => [
                'package_id' => $package->id,
                'package_version_id' => $version->id,
                'download_url' => 'https://example.invalid/legacy-app-1.0.0.exe',
                'file_name' => 'legacy-app-1.0.0.exe',
                'silent_args' => '/S',
            ],
            'target_type' => 'group',
            'target_id' => $group->id,
            'created_by' => $user->id,
        ]);
        DB::table('job_runs')->insert([
            'id' => (string) Str::uuid(),
            'job_id' => $installJob->id,
            'device_id' => $device->id,
            'status' => 'success',
            'attempt_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'finished_at' => now(),
        ]);

        $this->delete(route('admin.groups.packages.remove', [$group->id, $installJob->id]))
            ->assertRedirect();

        $uninstallJob = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('job_type', 'uninstall_exe')
            ->where('payload->package_version_id', $version->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($uninstallJob, 'Expected uninstall_exe job from install_args.uninstall_command fallback.');
        $payload = is_array($uninstallJob->payload) ? $uninstallJob->payload : [];
        $this->assertSame(
            '"C:\\Program Files\\Legacy App\\uninstall.exe" /S',
            (string) ($payload['command'] ?? '')
        );
    }

    public function test_installed_device_ids_excludes_device_after_successful_uninstall(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester-installed-state@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'TEST-DEVICE-04',
            'os_name' => 'Windows',
            'os_version' => '10.0.19045',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        $package = PackageModel::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Lifecycle App',
            'slug' => 'lifecycle-app',
            'publisher' => 'Lifecycle Corp',
            'package_type' => 'exe',
            'is_active' => true,
        ]);

        $version = PackageVersion::query()->create([
            'id' => (string) Str::uuid(),
            'package_id' => $package->id,
            'version' => '1.0.0',
            'channel' => 'stable',
            'install_args' => ['silent_args' => '/S'],
            'uninstall_args' => ['command' => '"C:\\Program Files\\Lifecycle App\\uninstall.exe" /S'],
            'detection_rules' => ['type' => 'file', 'value' => 'C:\\Program Files\\Lifecycle App\\app.exe'],
            'is_deprecated' => false,
        ]);

        $installJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'install_exe',
            'status' => 'success',
            'priority' => 100,
            'payload' => [
                'package_id' => $package->id,
                'package_version_id' => $version->id,
            ],
            'target_type' => 'device',
            'target_id' => $device->id,
            'created_by' => $user->id,
        ]);
        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $installJob->id,
            'device_id' => $device->id,
            'status' => 'success',
            'attempt_count' => 1,
            'finished_at' => now()->subMinute(),
        ]);

        $controller = app(\App\Http\Controllers\Web\AdminConsoleController::class);
        $method = new \ReflectionMethod($controller, 'installedDeviceIdsForPackageVersion');
        $method->setAccessible(true);

        $uninstallJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'uninstall_exe',
            'status' => 'success',
            'priority' => 100,
            'payload' => [
                'package_id' => $package->id,
                'package_version_id' => $version->id,
                'command' => '"C:\\Program Files\\Lifecycle App\\uninstall.exe" /S',
            ],
            'target_type' => 'device',
            'target_id' => $device->id,
            'created_by' => $user->id,
        ]);
        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $uninstallJob->id,
            'device_id' => $device->id,
            'status' => 'success',
            'attempt_count' => 1,
            'finished_at' => now(),
        ]);

        $installedAfter = $method->invoke($controller, $version->id);
        $this->assertNotContains($device->id, $installedAfter);
    }

    public function test_custom_uninstall_can_be_inferred_from_package_name_when_command_missing(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester-infer-uninstall@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'TEST-DEVICE-05',
            'os_name' => 'Windows',
            'os_version' => '10.0.19045',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'QA Infer Group',
            'description' => 'Infer uninstall command test',
        ]);
        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $package = PackageModel::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Infer App',
            'slug' => 'infer-app',
            'publisher' => 'Infer Corp',
            'package_type' => 'custom',
            'is_active' => true,
        ]);
        $version = PackageVersion::query()->create([
            'id' => (string) Str::uuid(),
            'package_id' => $package->id,
            'version' => '1.0.0',
            'channel' => 'stable',
            'install_args' => ['silent_args' => '/S'],
            'uninstall_args' => [],
            'detection_rules' => ['type' => 'file', 'value' => 'C:\\Program Files\\Infer App\\app.exe'],
            'is_deprecated' => false,
        ]);

        $installJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'install_exe',
            'status' => 'success',
            'priority' => 100,
            'payload' => [
                'package_id' => $package->id,
                'package_version_id' => $version->id,
            ],
            'target_type' => 'group',
            'target_id' => $group->id,
            'created_by' => $user->id,
        ]);
        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $installJob->id,
            'device_id' => $device->id,
            'status' => 'success',
            'attempt_count' => 1,
            'finished_at' => now(),
        ]);

        $this->delete(route('admin.groups.packages.remove', [$group->id, $installJob->id]))
            ->assertRedirect();

        $uninstallJob = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('job_type', 'uninstall_exe')
            ->where('payload->package_version_id', $version->id)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($uninstallJob, 'Expected uninstall_exe job to be queued with inferred command.');
        $payload = is_array($uninstallJob->payload) ? $uninstallJob->payload : [];
        $command = (string) ($payload['command'] ?? '');
        $this->assertNotSame('', $command);
        $this->assertStringContainsStringIgnoringCase('powershell.exe', $command);
        $this->assertStringContainsStringIgnoringCase('Infer App', $command);
    }
}
