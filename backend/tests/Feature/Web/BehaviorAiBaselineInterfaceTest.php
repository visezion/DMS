<?php

namespace Tests\Feature\Web;

use App\Models\ControlPlaneSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BehaviorAiBaselineInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_behavior_baseline_page_renders_dedicated_interface(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.behavior-baseline.index'))
            ->assertOk()
            ->assertSee('Behavioral Baseline')
            ->assertSee('Behavioral Drift Feed')
            ->assertSee('Device Baseline Profiles');
    }

    public function test_behavior_ai_page_no_longer_contains_baseline_sections(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.behavior-ai.index'))
            ->assertOk()
            ->assertDontSee('Behavioral Drift Feed')
            ->assertDontSee('Device Baseline Profiles');
    }

    public function test_admin_can_update_baseline_settings_from_dedicated_baseline_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.behavior-baseline.settings'), [
                'baseline_enabled' => '1',
                'min_samples' => 42,
                'min_login_samples' => 14,
                'min_numeric_samples' => 30,
                'drift_event_threshold' => '0.77',
                'category_drift_threshold' => '0.74',
                'max_category_bins' => 320,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(['value' => true], ControlPlaneSetting::query()->findOrFail('behavior.baseline.enabled')->value);
        $this->assertSame(['value' => 42], ControlPlaneSetting::query()->findOrFail('behavior.baseline.min_samples')->value);
        $this->assertSame(['value' => 14], ControlPlaneSetting::query()->findOrFail('behavior.baseline.min_login_samples')->value);
        $this->assertSame(['value' => 30], ControlPlaneSetting::query()->findOrFail('behavior.baseline.min_numeric_samples')->value);
        $this->assertSame(['value' => 0.77], ControlPlaneSetting::query()->findOrFail('behavior.baseline.drift_event_threshold')->value);
        $this->assertSame(['value' => 0.74], ControlPlaneSetting::query()->findOrFail('behavior.baseline.category_drift_threshold')->value);
        $this->assertSame(['value' => 320], ControlPlaneSetting::query()->findOrFail('behavior.baseline.max_category_bins')->value);
    }
}
