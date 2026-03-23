<?php

namespace Tests\Feature\Behavior;

use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\OpenAiBehaviorAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpenAiBehaviorAnalystTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_null_when_openai_key_is_missing(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.behavior_analyst_enabled', true);

        $device = $this->createDevice('openai-disabled-device');
        $event = $this->createBehaviorEvent($device->id, 'app_launch', 'powershell.exe');

        $analysis = app(OpenAiBehaviorAnalyst::class)->analyze(
            $event,
            ['event_type' => 'app_launch', 'hour' => 10, 'day_of_week' => 1],
            [],
            0.72,
            0.82,
        );

        $this->assertNull($analysis);
        Http::assertNothingSent();
    }

    public function test_it_normalizes_openai_behavior_analysis_response(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        config()->set('services.openai.model', 'test-model');
        config()->set('services.openai.timeout_seconds', 10);
        config()->set('services.openai.behavior_analyst_enabled', true);
        config()->set('services.openai.behavior_history_events', 30);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'classification' => 'suspicious',
                            'confidence' => 0.84,
                            'recommended_action' => 'notify',
                            'risk_adjustment' => 0.12,
                            'summary' => 'Process chain is unusual for this host baseline.',
                            'behavior_markers' => ['rare_process', 'off_hours_activity'],
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $device = $this->createDevice('openai-enabled-device');
        $event = $this->createBehaviorEvent($device->id, 'app_launch', 'whoami.exe');
        $this->createBehaviorEvent($device->id, 'app_launch', 'explorer.exe');
        $this->createBehaviorEvent($device->id, 'file_access', 'notepad.exe');

        $analysis = app(OpenAiBehaviorAnalyst::class)->analyze(
            $event,
            [
                'event_type' => 'app_launch',
                'hour' => 2,
                'day_of_week' => 0,
                'user_name_raw' => 'LAB\\admin',
                'process_name_raw' => 'whoami.exe',
                'file_path' => '',
                'tags' => ['manual'],
            ],
            [
                'rare_process_on_device' => ['score' => 0.89, 'active' => true],
                'off_hours_profile' => ['score' => 0.72, 'active' => true],
            ],
            0.79,
            0.82,
        );

        $this->assertIsArray($analysis);
        $this->assertSame('suspicious', $analysis['classification']);
        $this->assertSame('notify', $analysis['recommended_action']);
        $this->assertSame(0.84, $analysis['confidence']);
        $this->assertSame(0.12, $analysis['risk_adjustment']);
        $this->assertSame('test-model', $analysis['model']);
        $this->assertNotEmpty($analysis['generated_at']);
        $this->assertContains('rare_process', $analysis['behavior_markers']);

        Http::assertSentCount(1);
    }

    private function createDevice(string $hostname): Device
    {
        return Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => $hostname,
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    private function createBehaviorEvent(string $deviceId, string $eventType, string $processName): DeviceBehaviorLog
    {
        return DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'occurred_at' => now()->subMinutes(random_int(1, 120)),
            'user_name' => 'LAB\\admin',
            'process_name' => $processName,
            'file_path' => null,
            'metadata' => ['source' => 'test'],
        ]);
    }
}

