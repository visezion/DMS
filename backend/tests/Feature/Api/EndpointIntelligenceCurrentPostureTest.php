<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\DeviceHealthScore;
use App\Models\DeviceHealthSnapshot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EndpointIntelligenceCurrentPostureTest extends TestCase
{
    use RefreshDatabase;

    public function test_unhealthy_uses_latest_score_per_device_not_historical_rows(): void
    {
        Sanctum::actingAs($this->createUserWithPermissions(['health.read']));

        $deviceRecovered = $this->createDevice('RECOVERED-PC', 'Windows 11 Pro');
        $deviceDegraded = $this->createDevice('DEGRADED-PC', 'Windows 11 Pro');

        $this->createHealthScore($deviceRecovered, 12.0, 'critical', now()->subHours(2));
        $this->createHealthScore($deviceRecovered, 91.0, 'healthy', now()->subMinutes(5));
        $this->createHealthScore($deviceDegraded, 35.0, 'degraded', now()->subMinutes(4));

        $this->getJson('/api/v1/admin/health/unhealthy?bands=critical,degraded')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.device_id', $deviceDegraded->id)
            ->assertJsonMissing(['device_id' => $deviceRecovered->id]);
    }

    public function test_compare_uses_latest_fleet_and_peer_baselines(): void
    {
        Sanctum::actingAs($this->createUserWithPermissions(['health.read']));

        $deviceA = $this->createDevice('COMPARE-A', 'Windows 11 Enterprise');
        $deviceB = $this->createDevice('COMPARE-B', 'Windows 11 Enterprise');
        $deviceC = $this->createDevice('COMPARE-C', 'Windows 10 Pro');

        $this->createHealthScore($deviceA, 20.0, 'critical', now()->subDays(2));
        $this->createHealthScore($deviceA, 80.0, 'healthy', now()->subMinutes(3));
        $this->createHealthScore($deviceB, 10.0, 'critical', now()->subDays(2));
        $this->createHealthScore($deviceB, 60.0, 'warning', now()->subMinutes(2));
        $this->createHealthScore($deviceC, 99.0, 'healthy', now()->subDays(2));
        $this->createHealthScore($deviceC, 40.0, 'degraded', now()->subMinutes(1));

        $this->getJson('/api/v1/admin/health/devices/'.$deviceA->id.'/compare')
            ->assertOk()
            ->assertJsonPath('current.score', 80)
            ->assertJsonPath('fleet_average', 60)
            ->assertJsonPath('peer_average', 70);
    }

    private function createDevice(string $hostname, string $osName): Device
    {
        return Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => $hostname,
            'os_name' => $osName,
            'os_version' => '24H2',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'tags' => [],
        ]);
    }

    private function createHealthScore(Device $device, float $score, string $band, \DateTimeInterface $scoredAt): void
    {
        $snapshot = DeviceHealthSnapshot::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'snapshot_at' => $scoredAt,
            'metrics' => [],
            'raw_payload' => [],
        ]);

        DeviceHealthScore::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'snapshot_id' => $snapshot->id,
            'score' => $score,
            'band' => $band,
            'predicted_failure_risk' => max(0, min(100, round(100 - $score, 2))),
            'component_scores' => [],
            'contributors' => [],
            'scored_at' => $scoredAt,
            'created_at' => $scoredAt,
            'updated_at' => $scoredAt,
        ]);
    }

    /**
     * @param  array<int,string>  $permissions
     */
    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'API Operator',
            'email' => 'operator-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Intelligence Operator',
            'slug' => 'intelligence-operator-'.Str::random(6),
        ]);

        foreach ($permissions as $permissionSlug) {
            $permission = Permission::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $permissionSlug,
                'slug' => $permissionSlug,
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }
}
