<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_user_is_blocked_by_permission_middleware(): void
    {
        $user = User::query()->create([
            'name' => 'NoPerm',
            'email' => 'noperm@example.com',
            'password' => 'password',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/devices');

        $response->assertStatus(403);
    }

    public function test_authorized_user_can_access_devices_endpoint(): void
    {
        $user = User::query()->create([
            'name' => 'Perm',
            'email' => 'perm@example.com',
            'password' => 'password',
        ]);

        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Device Reader',
            'slug' => 'device-reader',
        ]);

        $permission = \App\Models\Permission::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'devices.read',
            'slug' => 'devices.read',
        ]);

        $role->permissions()->sync([$permission->id]);
        $user->roles()->sync([$role->id]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/devices');

        $response->assertStatus(200);
    }
}
