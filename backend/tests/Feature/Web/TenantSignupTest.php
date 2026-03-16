<?php

namespace Tests\Feature\Web;

use App\Models\Device;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_sign_up_tenant_and_only_see_own_org_items(): void
    {
        putenv('DMS_SELF_SIGNUP_ENABLED=true');

        try {
            $otherTenant = Tenant::query()->create([
                'id' => (string) Str::uuid(),
                'name' => 'Other Org',
                'slug' => 'other-org',
                'status' => 'active',
            ]);
            Device::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $otherTenant->id,
                'hostname' => 'other-org-device',
                'os_name' => 'Windows',
                'agent_version' => '1.0.0',
                'status' => 'online',
            ]);

            $response = $this->post(route('admin.signup.submit'), [
                'organization_name' => 'Acme Corp',
                'organization_slug' => 'acme-corp',
                'name' => 'Acme Owner',
                'email' => 'owner@acme.example',
                'password' => 'securePass123',
                'password_confirmation' => 'securePass123',
            ]);

            $response->assertRedirect(route('admin.dashboard'));
            $this->assertAuthenticated();

            $user = User::query()->where('email', 'owner@acme.example')->first();
            $this->assertNotNull($user);
            $this->assertNotNull($user->tenant_id);

            $tenant = Tenant::query()->where('slug', 'acme-corp')->first();
            $this->assertNotNull($tenant);
            $this->assertSame($tenant->id, $user->tenant_id);

            $role = Role::query()
                ->withoutGlobalScope('tenant')
                ->where('slug', 'super-admin')
                ->where('tenant_id', $tenant->id)
                ->first();
            $this->assertNotNull($role);
            $this->assertTrue(
                DB::table('role_user')
                    ->where('user_id', $user->id)
                    ->where('role_id', $role->id)
                    ->exists()
            );

            $ownDevice = Device::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'hostname' => 'acme-device',
                'os_name' => 'Windows',
                'agent_version' => '1.0.0',
                'status' => 'online',
            ]);

            $token = $user->createToken('signup')->plainTextToken;
            $apiResponse = $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/v1/admin/devices');

            $apiResponse->assertOk();
            $apiResponse->assertJsonCount(1, 'data');
            $apiResponse->assertJsonPath('data.0.id', $ownDevice->id);
        } finally {
            putenv('DMS_SELF_SIGNUP_ENABLED');
        }
    }

    public function test_signup_routes_are_blocked_when_disabled(): void
    {
        putenv('DMS_SELF_SIGNUP_ENABLED=false');

        try {
            $this->get(route('admin.signup'))->assertNotFound();

            $this->post(route('admin.signup.submit'), [
                'organization_name' => 'Nope Org',
                'name' => 'Nope Admin',
                'email' => 'nope@example.com',
                'password' => 'securePass123',
                'password_confirmation' => 'securePass123',
            ])->assertNotFound();
        } finally {
            putenv('DMS_SELF_SIGNUP_ENABLED');
        }
    }
}

