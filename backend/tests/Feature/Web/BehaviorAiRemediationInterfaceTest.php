<?php

namespace Tests\Feature\Web;

use App\Jobs\SweepAutonomousRemediationCasesJob;
use App\Models\ControlPlaneSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BehaviorAiRemediationInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_autonomous_remediation_page_renders_dedicated_interface(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.behavior-remediation.index'))
            ->assertOk()
            ->assertSee('Autonomous Remediation Engine')
            ->assertSee('Remediation Settings')
            ->assertSee('Recent Remediation Actions');
    }

    public function test_behavior_ai_page_does_not_embed_autonomous_remediation_page_sections(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.behavior-ai.index'))
            ->assertOk()
            ->assertDontSee('Autonomous Remediation Engine')
            ->assertDontSee('Recent Remediation Actions');
    }

    public function test_admin_can_update_autonomous_remediation_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.behavior-remediation.settings'), [
                'remediation_enabled' => '1',
                'min_risk' => '0.84',
                'max_actions_per_case' => 3,
                'allow_isolate_network' => '1',
                'allow_kill_process' => '1',
                'allow_uninstall_software' => '0',
                'allow_rollback_policy' => '1',
                'allow_emergency_profile' => '1',
                'allow_force_scan' => '1',
                'scan_command' => 'echo scan',
                'isolate_command' => 'echo isolate',
                'rollback_restore_point_description' => 'DMS Safe Point',
                'rollback_reboot_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.enabled')->value);
        $this->assertSame(['value' => 0.84], ControlPlaneSetting::query()->findOrFail('behavior.remediation.min_risk')->value);
        $this->assertSame(['value' => 3], ControlPlaneSetting::query()->findOrFail('behavior.remediation.max_actions_per_case')->value);
        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.allow_isolate_network')->value);
        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.allow_kill_process')->value);
        $this->assertSame(['value' => false], ControlPlaneSetting::query()->findOrFail('behavior.remediation.allow_uninstall_software')->value);
        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.allow_rollback_policy')->value);
        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.allow_emergency_profile')->value);
        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.allow_force_scan')->value);
        $this->assertSame(['value' => 'echo scan'], ControlPlaneSetting::query()->findOrFail('behavior.remediation.scan_command')->value);
        $this->assertSame(['value' => 'echo isolate'], ControlPlaneSetting::query()->findOrFail('behavior.remediation.isolate_command')->value);
        $this->assertSame(['value' => 'DMS Safe Point'], ControlPlaneSetting::query()->findOrFail('behavior.remediation.rollback_restore_point_description')->value);
        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.rollback_reboot_now')->value);
    }

    public function test_admin_can_queue_remediation_sweep_and_auto_enable_engine(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.behavior-remediation.sweep'), [
                'days' => 14,
                'limit' => 2000,
                'pending_only' => '1',
                'auto_enable' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.remediation.enabled')->value);
        $this->assertNotNull(ControlPlaneSetting::query()->find('behavior.remediation.last_sweep_requested_at'));
        Queue::assertPushed(SweepAutonomousRemediationCasesJob::class);
    }
}
