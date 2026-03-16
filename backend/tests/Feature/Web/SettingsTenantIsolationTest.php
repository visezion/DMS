<?php

namespace Tests\Feature\Web;

use App\Models\ControlPlaneSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettingsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_settings_are_written_with_tenant_namespace(): void
    {
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);
        $user = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-a-settings@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($user)
            ->withSession(['_token' => 'csrf-token'])
            ->post(route('admin.ops.update'), [
                '_token' => 'csrf-token',
                'kill_switch' => '1',
                'max_retries' => 7,
                'base_backoff_seconds' => 45,
                'allowed_script_hashes' => '',
                'auto_allow_run_command_hashes' => '0',
                'delete_cleanup_before_uninstall' => '0',
                'package_download_url_mode' => 'public',
                'behavior_ai_threshold' => '0.82',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_plane_settings', [
            'key' => 'tenant:'.$tenant->id.':jobs.max_retries',
            'tenant_id' => $tenant->id,
        ]);
        $this->assertDatabaseMissing('control_plane_settings', [
            'key' => 'jobs.max_retries',
        ]);
    }

    public function test_tenant_reads_use_override_then_fallback_to_global_default(): void
    {
        $tenantA = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);
        $tenantB = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'status' => 'active',
        ]);

        $userA = User::query()->create([
            'name' => 'Tenant A Admin',
            'email' => 'tenant-a-reader@example.com',
            'password' => 'password',
            'tenant_id' => $tenantA->id,
        ]);
        $userB = User::query()->create([
            'name' => 'Tenant B Admin',
            'email' => 'tenant-b-reader@example.com',
            'password' => 'password',
            'tenant_id' => $tenantB->id,
        ]);

        ControlPlaneSetting::query()
            ->withoutGlobalScopes()
            ->create([
                'key' => 'jobs.max_retries',
                'tenant_id' => null,
                'value' => ['value' => 3],
                'updated_by' => null,
            ]);
        ControlPlaneSetting::query()
            ->withoutGlobalScopes()
            ->create([
                'key' => 'tenant:'.$tenantA->id.':jobs.max_retries',
                'tenant_id' => $tenantA->id,
                'value' => ['value' => 9],
                'updated_by' => null,
            ]);

        $this->actingAs($userA)
            ->get(route('admin.jobs'))
            ->assertOk()
            ->assertSee('max 9 attempts');

        $this->actingAs($userB)
            ->get(route('admin.jobs'))
            ->assertOk()
            ->assertSee('max 3 attempts');
    }
}
