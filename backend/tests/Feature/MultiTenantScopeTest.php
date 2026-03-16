<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_user_only_sees_own_tenant_devices(): void
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

        $user = User::query()->create([
            'name' => 'Tenant A Admin',
            'email' => 'tenant-a-admin@example.com',
            'password' => 'password',
            'tenant_id' => $tenantA->id,
        ]);

        $permission = Permission::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'devices.read',
            'slug' => 'devices.read',
        ]);
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant A Device Reader',
            'slug' => 'tenant-a-device-reader',
            'tenant_id' => $tenantA->id,
        ]);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->sync([$role->id]);

        $tenantADevice = Device::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'hostname' => 'tenant-a-device',
            'os_name' => 'Windows',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'hostname' => 'tenant-b-device',
            'os_name' => 'Windows',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);

        $token = $user->createToken('tenant-a')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/devices');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $tenantADevice->id);
    }

    public function test_super_admin_can_switch_tenant_context_via_header(): void
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

        $superAdmin = User::query()->create([
            'name' => 'Global Operator',
            'email' => 'global-operator@example.com',
            'password' => 'password',
            'tenant_id' => null,
        ]);

        $permission = Permission::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'devices.read',
            'slug' => 'devices.read',
        ]);
        $tenantRole = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant A Device Reader',
            'slug' => 'tenant-a-device-reader',
            'tenant_id' => $tenantA->id,
        ]);
        $tenantRole->permissions()->sync([$permission->id]);
        $superAdmin->roles()->sync([$tenantRole->id]);

        $tenantADevice = Device::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'hostname' => 'tenant-a-device',
            'os_name' => 'Windows',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'hostname' => 'tenant-b-device',
            'os_name' => 'Windows',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);

        $token = $superAdmin->createToken('global-operator')->plainTextToken;

        $tenantScopedResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant-Id', $tenantA->id)
            ->getJson('/api/v1/admin/devices');

        $tenantScopedResponse->assertOk();
        $tenantScopedResponse->assertJsonCount(1, 'data');
        $tenantScopedResponse->assertJsonPath('data.0.id', $tenantADevice->id);

        $noRoleInTenantResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant-Id', $tenantB->id)
            ->getJson('/api/v1/admin/devices');

        $noRoleInTenantResponse->assertForbidden();
    }

    public function test_role_permission_seeder_creates_platform_super_admin_and_default_tenant(): void
    {
        putenv('DMS_SEED_TENANT_NAME=Acme Corp');
        putenv('DMS_SEED_TENANT_SLUG=acme-corp');
        putenv('DMS_SEED_ADMIN_NAME=Acme Admin');
        putenv('DMS_SEED_ADMIN_EMAIL=acme-admin@example.com');
        putenv('DMS_SEED_ADMIN_PASSWORD=admin123');

        try {
            $this->seed(RolePermissionSeeder::class);

            $tenant = Tenant::query()->where('slug', 'acme-corp')->first();
            $this->assertNotNull($tenant);

            $role = Role::query()
                ->whereNull('tenant_id')
                ->where('slug', 'super-admin')
                ->first();
            $this->assertNotNull($role);

            $admin = User::query()->where('email', 'acme-admin@example.com')->first();
            $this->assertNotNull($admin);
            $this->assertNull($admin->tenant_id);
            $this->assertTrue($admin->roles()->where('roles.id', $role->id)->exists());
        } finally {
            putenv('DMS_SEED_TENANT_NAME');
            putenv('DMS_SEED_TENANT_SLUG');
            putenv('DMS_SEED_ADMIN_NAME');
            putenv('DMS_SEED_ADMIN_EMAIL');
            putenv('DMS_SEED_ADMIN_PASSWORD');
        }
    }
}
