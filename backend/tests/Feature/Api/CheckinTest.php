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

    public function test_heartbeat_stores_request_ip_when_runtime_ip_missing(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'PC-002',
            'os_name' => 'Windows 11',
            'agent_version' => '1.0.0',
            'status' => 'offline',
            'tags' => [],
        ]);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '172.16.43.110'])
            ->postJson('/api/v1/device/heartbeat', [
                'device_id' => $device->id,
                'agent_version' => '1.1.0',
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 19,
                ],
            ]);

        $response->assertStatus(200);

        $device->refresh();
        $tags = is_array($device->tags) ? $device->tags : [];
        $runtime = is_array($tags['runtime_diagnostics'] ?? null) ? $tags['runtime_diagnostics'] : [];

        $this->assertSame('172.16.43.110', (string) ($runtime['ip_address'] ?? ''));
        $this->assertSame('172.16.43.110', (string) ($runtime['request_ip_address'] ?? ''));
        $this->assertSame('172.16.43.110', (string) data_get($runtime, 'network.request_ip', ''));
    }
}
