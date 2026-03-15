<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommandEnvelopeSigner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(CommandEnvelopeSigner::class)->ensureActiveKey();

        $adminName = trim((string) env('DMS_SEED_ADMIN_NAME', 'DMS Admin'));
        if ($adminName === '') {
            $adminName = 'DMS Admin';
        }

        $adminEmail = strtolower(trim((string) env('DMS_SEED_ADMIN_EMAIL', 'admin@example.com')));
        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $adminEmail = 'admin@example.com';
        }

        $adminPassword = trim((string) env('DMS_SEED_ADMIN_PASSWORD', 'admin123'));
        if ($adminPassword === '') {
            $adminPassword = 'admin123';
        }

        $permissionSlugs = [
            'devices.read', 'devices.write',
            'groups.read', 'groups.write',
            'packages.read', 'packages.write',
            'policies.read', 'policies.write',
            'jobs.read', 'jobs.write',
            'audit.read',
            'access.read', 'access.write',
        ];

        foreach ($permissionSlugs as $slug) {
            Permission::query()->firstOrCreate([
                'slug' => $slug,
            ], [
                'id' => (string) Str::uuid(),
                'name' => $slug,
            ]);
        }

        $adminRole = Role::query()->firstOrCreate([
            'slug' => 'super-admin',
            'tenant_id' => null,
        ], [
            'id' => (string) Str::uuid(),
            'name' => 'Super Admin',
        ]);

        $adminRole->permissions()->sync(Permission::query()->pluck('id'));

        $admin = User::query()->firstOrCreate([
            'email' => $adminEmail,
        ], [
            'name' => $adminName,
            'password' => Hash::make($adminPassword),
            'is_active' => true,
        ]);

        $admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }
}
