<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckinTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_marks_device_online(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'PC-001',
            'os_name' => 'Windows 11',
            'agent_version' => '1.0.0',
            'status' => 'offline',
        ]);

        $response = $this->postJson('/api/v1/device/heartbeat', [
            'device_id' => $device->id,
            'agent_version' => '1.1.0',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'status' => 'online',
            'agent_version' => '1.1.0',
        ]);
    }
}
