<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasTenantAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_superadmin_can_manage_saas_tenants_page(): void
    {
        $platformSuperAdmin = $this->createSuperAdminUser();

        $this->actingAs($platformSuperAdmin)
            ->get(route('admin.saas.dashboard'))
            ->assertOk()
            ->assertSee('SaaS SuperAdmin Dashboard');

        $this->actingAs($platformSuperAdmin)
            ->get(route('admin.saas.tenants'))
            ->assertOk()
            ->assertSee('SaaS Tenant Administration');

        $createResponse = $this->actingAs($platformSuperAdmin)->post(route('admin.saas.tenants.create'), [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'status' => 'active',
        ]);
        $createResponse->assertRedirect();

        $tenant = Tenant::query()->where('slug', 'acme-corp')->first();
        $this->assertNotNull($tenant);

        $this->actingAs($platformSuperAdmin)
            ->get(route('admin.saas.dashboard'))
            ->assertOk()
            ->assertSee('Acme Corp');

        $switchResponse = $this->actingAs($platformSuperAdmin)->post(route('admin.saas.tenants.switch', $tenant->id));
        $switchResponse->assertRedirect();
        $switchResponse->assertSessionHas('active_tenant_id', $tenant->id);

        $clearResponse = $this->actingAs($platformSuperAdmin)->post(route('admin.saas.tenants.switch.platform'));
        $clearResponse->assertRedirect();
        $clearResponse->assertSessionMissing('active_tenant_id');
    }

    public function test_tenant_superadmin_cannot_access_platform_saas_tenant_page(): void
    {
        $this->createSuperAdminUser();

        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant Scoped',
            'slug' => 'tenant-scoped',
            'status' => 'active',
        ]);

        $tenantRole = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant Super Admin',
            'slug' => 'super-admin',
            'tenant_id' => $tenant->id,
        ]);

        $tenantSuperAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);
        $tenantSuperAdmin->roles()->sync([$tenantRole->id]);

        $this->actingAs($tenantSuperAdmin)
            ->get(route('admin.saas.tenants'))
            ->assertForbidden();

        $this->actingAs($tenantSuperAdmin)
            ->get(route('admin.saas.dashboard'))
            ->assertForbidden();
    }

    public function test_tenant_superadmin_can_access_when_no_platform_superadmin_exists(): void
    {
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant Scoped',
            'slug' => 'tenant-scoped',
            'status' => 'active',
        ]);

        $tenantRole = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant Super Admin',
            'slug' => 'super-admin',
            'tenant_id' => $tenant->id,
        ]);

        $tenantSuperAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin-2@example.com',
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);
        $tenantSuperAdmin->roles()->sync([$tenantRole->id]);

        $this->actingAs($tenantSuperAdmin)
            ->get(route('admin.saas.tenants'))
            ->assertOk();

        $this->actingAs($tenantSuperAdmin)
            ->get(route('admin.saas.dashboard'))
            ->assertOk();
    }

    public function test_assigning_user_to_tenant_removes_out_of_scope_roles(): void
    {
        $platformSuperAdmin = $this->createSuperAdminUser();

        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'status' => 'active',
        ]);

        $tenantRole = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant Operator',
            'slug' => 'tenant-operator',
            'tenant_id' => $tenant->id,
        ]);
        $globalRole = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Global Operator',
            'slug' => 'global-operator',
            'tenant_id' => null,
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => 'password',
            'tenant_id' => null,
        ]);
        $staff->roles()->sync([$tenantRole->id, $globalRole->id]);

        $this->actingAs($platformSuperAdmin)
            ->post(route('admin.saas.users.tenant.assign'), [
                'user_id' => $staff->id,
                'tenant_id' => $tenant->id,
            ])
            ->assertRedirect();

        $staff->refresh();
        $this->assertSame($tenant->id, $staff->tenant_id);

        $remainingRoleIds = DB::table('role_user')
            ->where('user_id', $staff->id)
            ->pluck('role_id')
            ->all();
        $this->assertSame([$tenantRole->id], $remainingRoleIds);
    }

    private function createSuperAdminUser(): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'tenant_id' => null,
        ]);

        $user = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin@example.com',
            'password' => 'password',
            'tenant_id' => null,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
