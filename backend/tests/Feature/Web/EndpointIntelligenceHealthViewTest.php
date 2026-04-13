<?php

namespace Tests\Feature\Web;

use App\Models\Device;
use App\Models\ThreatFinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndpointIntelligenceHealthViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_health_view_shows_only_active_findings(): void
    {
        $user = User::factory()->create();
        $device = Device::query()->create([
            'hostname' => 'HEALTH-VIEW-PC',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);

        ThreatFinding::query()->create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'finding_type' => 'bitlocker_disabled',
            'severity' => 'medium',
            'status' => 'open',
            'confidence' => 0.72,
            'fingerprint' => 'open-finding',
            'evidence' => ['summary' => 'Disk encryption is disabled.'],
            'first_seen_at' => now()->subHours(2),
            'last_seen_at' => now()->subMinutes(5),
        ]);

        ThreatFinding::query()->create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'finding_type' => 'unusual_external_connections',
            'severity' => 'medium',
            'status' => 'resolved',
            'confidence' => 0.70,
            'fingerprint' => 'resolved-finding',
            'evidence' => ['summary' => 'High volume of external connection activity detected.'],
            'first_seen_at' => now()->subDays(14),
            'last_seen_at' => now()->subDays(14),
        ]);

        $this->actingAs($user)
            ->get(route('admin.intelligence.health.device', $device->id))
            ->assertOk()
            ->assertSee('Disk encryption is disabled.')
            ->assertDontSee('High volume of external connection activity detected.');
    }
}

