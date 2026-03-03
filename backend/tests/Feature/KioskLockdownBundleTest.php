<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DmsJob;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KioskLockdownBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_group_kiosk_lockdown_bundle_creates_assignments_and_queues_jobs(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester+kiosk@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Kiosk Lab',
            'description' => 'Kiosk lockdown test group',
        ]);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KIOSK-DEVICE-01',
            'os_name' => 'Windows',
            'os_version' => '10.0.19045',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $this->post(route('admin.groups.kiosk-lockdown', $group->id), [
            'queue_now' => 1,
            'include_app_controls' => 0,
            'include_usb_lock' => 1,
            'include_local_admin_restriction' => 0,
            'include_shell_lock' => 0,
            'include_taskmgr_lock' => 1,
            'include_control_panel_lock' => 1,
        ])->assertRedirect();

        $expectedSlugs = [
            'security-usb-storage-block',
            'lab-exam-disable-taskmgr',
            'security-disable-control-panel',
        ];

        $policyIds = Policy::query()
            ->whereIn('slug', $expectedSlugs)
            ->pluck('id')
            ->values();
        $this->assertCount(3, $policyIds);

        $assignmentCount = DB::table('policy_assignments as a')
            ->join('policy_versions as pv', 'pv.id', '=', 'a.policy_version_id')
            ->where('a.target_type', 'group')
            ->where('a.target_id', $group->id)
            ->whereIn('pv.policy_id', $policyIds)
            ->count();
        $this->assertSame(3, $assignmentCount);

        $jobs = DmsJob::query()
            ->where('job_type', 'apply_policy')
            ->where('target_type', 'group')
            ->where('target_id', $group->id)
            ->get();
        $this->assertCount(3, $jobs);

        $runCount = DB::table('job_runs')
            ->whereIn('job_id', $jobs->pluck('id')->values())
            ->where('device_id', $device->id)
            ->count();
        $this->assertSame(3, $runCount);
    }

    public function test_kiosk_lockdown_policy_removal_queues_effective_cleanup_rules(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester+kiosk-removal@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Kiosk Lab Removal',
            'description' => 'Kiosk lockdown removal test group',
        ]);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KIOSK-DEVICE-REMOVE-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $this->post(route('admin.groups.kiosk-lockdown', $group->id), [
            'queue_now' => 1,
            'include_app_controls' => 1,
            'include_usb_lock' => 1,
            'include_local_admin_restriction' => 1,
            'include_shell_lock' => 1,
            'include_taskmgr_lock' => 1,
            'include_control_panel_lock' => 1,
        ])->assertRedirect();

        $assignments = DB::table('policy_assignments as a')
            ->join('policy_versions as pv', 'pv.id', '=', 'a.policy_version_id')
            ->join('policies as p', 'p.id', '=', 'pv.policy_id')
            ->where('a.target_type', 'group')
            ->where('a.target_id', $group->id)
            ->whereIn('p.slug', [
                'kiosk-enforce-applocker-service',
                'lab-disable-microsoft-store',
                'lab-disable-consumer-features',
                'security-usb-storage-block',
                'lab-restrict-local-admins',
                'kiosk-shell-lock-explorer',
                'lab-exam-disable-taskmgr',
                'security-disable-control-panel',
            ])
            ->get(['a.id as assignment_id']);

        $this->assertSame(8, $assignments->count());

        foreach ($assignments as $assignment) {
            $this->delete(route('admin.groups.policies.remove', [$group->id, $assignment->assignment_id]))
                ->assertRedirect();
        }

        $cleanupJobs = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('job_type', 'apply_policy')
            ->where('payload->cleanup', true)
            ->get();

        $this->assertTrue($cleanupJobs->isNotEmpty(), 'Expected cleanup apply_policy jobs after group assignment removals.');

        $flatCleanupRules = $cleanupJobs
            ->flatMap(function (DmsJob $job) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return collect((array) ($payload['rules'] ?? []));
            })
            ->filter(fn ($rule) => is_array($rule))
            ->values();

        $hasLocalGroupCleanup = $flatCleanupRules->contains(function ($rule) {
            return strtolower((string) ($rule['type'] ?? '')) === 'local_group'
                && strtolower((string) (($rule['config']['group'] ?? ''))) === 'administrators'
                && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent'
                && (bool) (($rule['config']['restore_previous'] ?? false)) === true;
        });
        $this->assertTrue($hasLocalGroupCleanup, 'Expected local_group cleanup rule for Administrators.');

        $hasShellRegistryCleanup = $flatCleanupRules->contains(function ($rule) {
            return strtolower((string) ($rule['type'] ?? '')) === 'registry'
                && strtoupper((string) (($rule['config']['path'] ?? ''))) === 'HKLM\\SOFTWARE\\MICROSOFT\\WINDOWS NT\\CURRENTVERSION\\WINLOGON'
                && strtoupper((string) (($rule['config']['name'] ?? ''))) === 'SHELL'
                && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent';
        });
        $this->assertTrue($hasShellRegistryCleanup, 'Expected Shell registry cleanup rule.');

        $hasAppLockerCommandCleanup = $flatCleanupRules->contains(function ($rule) {
            if (strtolower((string) ($rule['type'] ?? '')) !== 'command') {
                return false;
            }

            $command = strtolower((string) (($rule['config']['command'] ?? '')));
            return str_contains($command, 'set-service -name appidsvc -startuptype manual');
        });
        $this->assertTrue($hasAppLockerCommandCleanup, 'Expected AppLocker command cleanup rule.');

        $containsNoOpRemovalCommands = $flatCleanupRules->contains(function ($rule) {
            if (strtolower((string) ($rule['type'] ?? '')) !== 'command') {
                return false;
            }
            $command = strtolower((string) (($rule['config']['command'] ?? '')));
            return str_contains($command, 'kiosk shell lock removal requires manual shell policy decision')
                || str_contains($command, 'lab local admin restriction removed from dms policy profile');
        });
        $this->assertFalse($containsNoOpRemovalCommands, 'Cleanup should not use no-op placeholder commands.');
    }

    public function test_policy_detail_group_assignment_delete_queues_cleanup_rules_for_kiosk_bundle(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester+kiosk-policy-detail@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Kiosk Policy Detail Removal',
            'description' => 'Policy detail assignment delete cleanup test',
        ]);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KIOSK-DEVICE-POLICY-DETAIL-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.2',
            'status' => 'online',
        ]);

        DB::table('device_group_memberships')->insert([
            'device_group_id' => $group->id,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $this->post(route('admin.groups.kiosk-lockdown', $group->id), [
            'queue_now' => 0,
            'include_app_controls' => 1,
            'include_usb_lock' => 0,
            'include_local_admin_restriction' => 0,
            'include_shell_lock' => 1,
            'include_taskmgr_lock' => 0,
            'include_control_panel_lock' => 0,
        ])->assertRedirect();

        $assignment = DB::table('policy_assignments as a')
            ->join('policy_versions as pv', 'pv.id', '=', 'a.policy_version_id')
            ->join('policies as p', 'p.id', '=', 'pv.policy_id')
            ->where('a.target_type', 'group')
            ->where('a.target_id', $group->id)
            ->where('p.slug', 'kiosk-enforce-applocker-service')
            ->select(['a.id as assignment_id', 'pv.id as version_id', 'p.id as policy_id'])
            ->first();

        $this->assertNotNull($assignment, 'Expected kiosk app controls assignment to exist.');

        $this->delete(route('admin.policies.versions.assignments.delete', [
            $assignment->policy_id,
            $assignment->version_id,
            $assignment->assignment_id,
        ]))->assertRedirect();

        $cleanupJobs = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('job_type', 'apply_policy')
            ->where('payload->cleanup', true)
            ->get();

        $this->assertTrue($cleanupJobs->isNotEmpty(), 'Expected cleanup apply_policy jobs after policy detail assignment removal.');

        $flatCleanupRules = $cleanupJobs
            ->flatMap(function (DmsJob $job) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return collect((array) ($payload['rules'] ?? []));
            })
            ->filter(fn ($rule) => is_array($rule))
            ->values();

        $hasAppLockerCommandCleanup = $flatCleanupRules->contains(function ($rule) {
            if (strtolower((string) ($rule['type'] ?? '')) !== 'command') {
                return false;
            }
            $command = strtolower((string) (($rule['config']['command'] ?? '')));
            return str_contains($command, 'set-service -name appidsvc -startuptype manual');
        });
        $this->assertTrue($hasAppLockerCommandCleanup, 'Expected AppLocker command cleanup rule from policy detail delete path.');
    }

    public function test_each_kiosk_control_removal_queues_expected_cleanup_rules(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester+kiosk-each-control@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $cases = [
            [
                'name' => 'app_controls',
                'toggles' => [
                    'include_app_controls' => 1,
                    'include_usb_lock' => 0,
                    'include_local_admin_restriction' => 0,
                    'include_shell_lock' => 0,
                    'include_taskmgr_lock' => 0,
                    'include_control_panel_lock' => 0,
                ],
                'slugs' => [
                    'kiosk-enforce-applocker-service',
                    'lab-disable-microsoft-store',
                    'lab-disable-consumer-features',
                ],
                'assert' => function ($rules): void {
                    $hasAppLockerCommandCleanup = $rules->contains(function ($rule) {
                        if (strtolower((string) ($rule['type'] ?? '')) !== 'command') {
                            return false;
                        }
                        $command = strtolower((string) (($rule['config']['command'] ?? '')));
                        return str_contains($command, 'set-service -name appidsvc -startuptype manual');
                    });
                    $this->assertTrue($hasAppLockerCommandCleanup, 'Expected AppLocker remove command for app_controls.');

                    $hasStoreCleanup = $rules->contains(function ($rule) {
                        return strtolower((string) ($rule['type'] ?? '')) === 'registry'
                            && strtoupper((string) (($rule['config']['path'] ?? ''))) === 'HKLM\\SOFTWARE\\POLICIES\\MICROSOFT\\WINDOWSSTORE'
                            && strtoupper((string) (($rule['config']['name'] ?? ''))) === 'REMOVEWINDOWSSTORE'
                            && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent';
                    });
                    $this->assertTrue($hasStoreCleanup, 'Expected Store registry cleanup for app_controls.');

                    $hasConsumerCleanup = $rules->contains(function ($rule) {
                        return strtolower((string) ($rule['type'] ?? '')) === 'registry'
                            && strtoupper((string) (($rule['config']['path'] ?? ''))) === 'HKLM\\SOFTWARE\\POLICIES\\MICROSOFT\\WINDOWS\\CLOUDCONTENT'
                            && strtoupper((string) (($rule['config']['name'] ?? ''))) === 'DISABLEWINDOWSCONSUMERFEATURES'
                            && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent';
                    });
                    $this->assertTrue($hasConsumerCleanup, 'Expected consumer-features registry cleanup for app_controls.');
                },
            ],
            [
                'name' => 'usb_lock',
                'toggles' => [
                    'include_app_controls' => 0,
                    'include_usb_lock' => 1,
                    'include_local_admin_restriction' => 0,
                    'include_shell_lock' => 0,
                    'include_taskmgr_lock' => 0,
                    'include_control_panel_lock' => 0,
                ],
                'slugs' => ['security-usb-storage-block'],
                'assert' => function ($rules): void {
                    $hasUsbCleanup = $rules->contains(function ($rule) {
                        return strtolower((string) ($rule['type'] ?? '')) === 'registry'
                            && strtoupper((string) (($rule['config']['path'] ?? ''))) === 'HKLM\\SYSTEM\\CURRENTCONTROLSET\\SERVICES\\USBSTOR'
                            && strtoupper((string) (($rule['config']['name'] ?? ''))) === 'START'
                            && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent';
                    });
                    $this->assertTrue($hasUsbCleanup, 'Expected USB registry cleanup for usb_lock.');
                },
            ],
            [
                'name' => 'local_admin',
                'toggles' => [
                    'include_app_controls' => 0,
                    'include_usb_lock' => 0,
                    'include_local_admin_restriction' => 1,
                    'include_shell_lock' => 0,
                    'include_taskmgr_lock' => 0,
                    'include_control_panel_lock' => 0,
                ],
                'slugs' => ['lab-restrict-local-admins'],
                'assert' => function ($rules): void {
                    $hasLocalGroupCleanup = $rules->contains(function ($rule) {
                        return strtolower((string) ($rule['type'] ?? '')) === 'local_group'
                            && strtolower((string) (($rule['config']['group'] ?? ''))) === 'administrators'
                            && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent'
                            && (bool) (($rule['config']['restore_previous'] ?? false)) === true;
                    });
                    $this->assertTrue($hasLocalGroupCleanup, 'Expected local group cleanup for local_admin.');
                },
            ],
            [
                'name' => 'shell_lock',
                'toggles' => [
                    'include_app_controls' => 0,
                    'include_usb_lock' => 0,
                    'include_local_admin_restriction' => 0,
                    'include_shell_lock' => 1,
                    'include_taskmgr_lock' => 0,
                    'include_control_panel_lock' => 0,
                ],
                'slugs' => ['kiosk-shell-lock-explorer'],
                'assert' => function ($rules): void {
                    $hasShellCleanup = $rules->contains(function ($rule) {
                        return strtolower((string) ($rule['type'] ?? '')) === 'registry'
                            && strtoupper((string) (($rule['config']['path'] ?? ''))) === 'HKLM\\SOFTWARE\\MICROSOFT\\WINDOWS NT\\CURRENTVERSION\\WINLOGON'
                            && strtoupper((string) (($rule['config']['name'] ?? ''))) === 'SHELL'
                            && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent';
                    });
                    $this->assertTrue($hasShellCleanup, 'Expected shell registry cleanup for shell_lock.');
                },
            ],
            [
                'name' => 'taskmgr_lock',
                'toggles' => [
                    'include_app_controls' => 0,
                    'include_usb_lock' => 0,
                    'include_local_admin_restriction' => 0,
                    'include_shell_lock' => 0,
                    'include_taskmgr_lock' => 1,
                    'include_control_panel_lock' => 0,
                ],
                'slugs' => ['lab-exam-disable-taskmgr'],
                'assert' => function ($rules): void {
                    $hasTaskMgrCleanup = $rules->contains(function ($rule) {
                        return strtolower((string) ($rule['type'] ?? '')) === 'registry'
                            && strtoupper((string) (($rule['config']['path'] ?? ''))) === 'HKLM\\SOFTWARE\\MICROSOFT\\WINDOWS\\CURRENTVERSION\\POLICIES\\SYSTEM'
                            && strtoupper((string) (($rule['config']['name'] ?? ''))) === 'DISABLETASKMGR'
                            && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent';
                    });
                    $this->assertTrue($hasTaskMgrCleanup, 'Expected task manager registry cleanup for taskmgr_lock.');
                },
            ],
            [
                'name' => 'control_panel_lock',
                'toggles' => [
                    'include_app_controls' => 0,
                    'include_usb_lock' => 0,
                    'include_local_admin_restriction' => 0,
                    'include_shell_lock' => 0,
                    'include_taskmgr_lock' => 0,
                    'include_control_panel_lock' => 1,
                ],
                'slugs' => ['security-disable-control-panel'],
                'assert' => function ($rules): void {
                    $hasControlPanelCleanup = $rules->contains(function ($rule) {
                        return strtolower((string) ($rule['type'] ?? '')) === 'registry'
                            && strtoupper((string) (($rule['config']['path'] ?? ''))) === 'HKLM\\SOFTWARE\\MICROSOFT\\WINDOWS\\CURRENTVERSION\\POLICIES\\EXPLORER'
                            && strtoupper((string) (($rule['config']['name'] ?? ''))) === 'NOCONTROLPANEL'
                            && strtolower((string) (($rule['config']['ensure'] ?? ''))) === 'absent';
                    });
                    $this->assertTrue($hasControlPanelCleanup, 'Expected control panel registry cleanup for control_panel_lock.');
                },
            ],
        ];

        foreach ($cases as $index => $case) {
            $group = DeviceGroup::query()->create([
                'id' => (string) Str::uuid(),
                'name' => 'Kiosk Each '.$case['name'].' '.$index,
                'description' => 'Per-control removal verification',
            ]);

            $device = Device::query()->create([
                'id' => (string) Str::uuid(),
                'hostname' => 'KIOSK-EACH-'.$index,
                'os_name' => 'Windows',
                'os_version' => '11',
                'agent_version' => '2.0.2',
                'status' => 'online',
            ]);

            DB::table('device_group_memberships')->insert([
                'device_group_id' => $group->id,
                'device_id' => $device->id,
                'created_at' => now(),
            ]);

            $this->post(route('admin.groups.kiosk-lockdown', $group->id), [
                'queue_now' => 0,
                ...$case['toggles'],
            ])->assertRedirect();

            $assignments = DB::table('policy_assignments as a')
                ->join('policy_versions as pv', 'pv.id', '=', 'a.policy_version_id')
                ->join('policies as p', 'p.id', '=', 'pv.policy_id')
                ->where('a.target_type', 'group')
                ->where('a.target_id', $group->id)
                ->whereIn('p.slug', $case['slugs'])
                ->get(['a.id as assignment_id']);

            $this->assertTrue($assignments->count() > 0, 'Expected assignment(s) for kiosk control case: '.$case['name']);

            foreach ($assignments as $assignment) {
                $this->delete(route('admin.groups.policies.remove', [$group->id, $assignment->assignment_id]))
                    ->assertRedirect();
            }

            $cleanupJobs = DmsJob::query()
                ->where('target_type', 'device')
                ->where('target_id', $device->id)
                ->where('job_type', 'apply_policy')
                ->where('payload->cleanup', true)
                ->get();

            $this->assertTrue($cleanupJobs->isNotEmpty(), 'Expected cleanup apply_policy jobs for case: '.$case['name']);

            $flatCleanupRules = $cleanupJobs
                ->flatMap(function (DmsJob $job) {
                    $payload = is_array($job->payload) ? $job->payload : [];
                    return collect((array) ($payload['rules'] ?? []));
                })
                ->filter(fn ($rule) => is_array($rule))
                ->values();

            $assertFn = $case['assert'];
            $assertFn($flatCleanupRules);
        }
    }
}
