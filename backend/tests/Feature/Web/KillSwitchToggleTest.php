<?php

namespace Tests\Feature\Web;

use App\Models\ControlPlaneSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KillSwitchToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enable_kill_switch_with_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ops.kill-switch'), [
                'enabled' => '1',
                'admin_password' => 'password',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $setting = ControlPlaneSetting::query()->find('jobs.kill_switch');

        $this->assertNotNull($setting);
        $this->assertSame(['value' => true], $setting->value);
    }

    public function test_kill_switch_toggle_rejects_incorrect_admin_password(): void
    {
        $user = User::factory()->create();

        ControlPlaneSetting::query()->create([
            'key' => 'jobs.kill_switch',
            'value' => ['value' => false],
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->from(route('admin.dashboard'))
            ->post(route('admin.ops.kill-switch'), [
                'enabled' => '1',
                'admin_password' => 'wrong-password',
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('kill_switch');

        $this->assertSame(
            ['value' => false],
            ControlPlaneSetting::query()->findOrFail('jobs.kill_switch')->value
        );
    }
}
