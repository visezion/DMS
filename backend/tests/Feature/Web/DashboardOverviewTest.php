<?php

namespace Tests\Feature\Web;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_renders_the_new_overview(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Fleet Risk')
            ->assertSee('Job run activity')
            ->assertSee('Recent anomaly review feed');
    }

    public function test_dashboard_uses_effective_online_device_counts_for_reachability(): void
    {
        Carbon::setTestNow('2026-03-12 10:00:00');

        try {
            $user = User::factory()->create();

            Device::query()->create([
                'hostname' => 'lab-online',
                'os_name' => 'Windows',
                'os_version' => '11',
                'agent_version' => '1.0.0',
                'status' => 'online',
                'last_seen_at' => now()->subMinute(),
            ]);

            Device::query()->create([
                'hostname' => 'lab-stale',
                'os_name' => 'Windows',
                'os_version' => '11',
                'agent_version' => '1.0.0',
                'status' => 'online',
                'last_seen_at' => now()->subMinutes(10),
            ]);

            Device::query()->create([
                'hostname' => 'lab-pending',
                'os_name' => 'Windows',
                'os_version' => '11',
                'agent_version' => '1.0.0',
                'status' => 'pending',
                'last_seen_at' => now()->subMinute(),
            ]);

            Device::query()->create([
                'hostname' => 'lab-fresh-offline-status',
                'os_name' => 'Windows',
                'os_version' => '11',
                'agent_version' => '1.0.0',
                'status' => 'offline',
                'last_seen_at' => now()->subMinute(),
            ]);

            $this->actingAs($user)
                ->get('/admin')
                ->assertOk()
                ->assertSee('Online 2')
                ->assertSeeInOrder([
                    'Device Reachability',
                    '50%',
                    'Online 2 | offline 1 | pending 1',
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
