<?php

namespace Tests\Feature\Web;

use App\Models\BehaviorAnomalyCase;
use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceBehaviorIntelligencePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_behavior_intelligence_page_renders_behavior_history_and_openai_timeline(): void
    {
        $user = User::factory()->create();
        $device = Device::query()->create([
            'hostname' => 'lab-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $log = DeviceBehaviorLog::query()->create([
            'device_id' => $device->id,
            'event_type' => 'process_start',
            'occurred_at' => now()->subMinutes(8),
            'user_name' => 'helpdesk',
            'process_name' => 'powershell.exe',
            'file_path' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
            'metadata' => [
                'tags' => ['remote_admin', 'scripted_task'],
            ],
        ]);

        BehaviorAnomalyCase::query()->create([
            'behavior_log_id' => $log->id,
            'device_id' => $device->id,
            'risk_score' => 0.9012,
            'severity' => 'high',
            'status' => 'pending_review',
            'summary' => 'Fallback summary',
            'detected_at' => now()->subMinutes(6),
            'context' => [
                'openai' => [
                    'classification' => 'suspicious',
                    'confidence' => 0.93,
                    'recommended_action' => 'notify',
                    'risk_adjustment' => 0.17,
                    'summary' => 'Automation outside normal hours with uncommon process invocation.',
                    'behavior_markers' => ['off_hours_execution', 'rare_process_for_device'],
                    'model' => 'gpt-4o-mini',
                    'generated_at' => now()->subMinutes(6)->toIso8601String(),
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.devices.behavior-intelligence', $device->id))
            ->assertOk()
            ->assertSee('Device Behavior Intelligence')
            ->assertSee('Behavior History')
            ->assertSee('OpenAI Verdict Timeline')
            ->assertSee('powershell.exe')
            ->assertSee('Suspicious')
            ->assertSee('Notify')
            ->assertSee('off_hours_execution')
            ->assertSee('Automation outside normal hours with uncommon process invocation.');
    }

    public function test_device_behavior_intelligence_page_handles_empty_device_data(): void
    {
        $user = User::factory()->create();
        $device = Device::query()->create([
            'hostname' => 'lab-empty',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.devices.behavior-intelligence', $device->id))
            ->assertOk()
            ->assertSee('No behavior events available for this device yet.')
            ->assertSee('No OpenAI verdicts available for this device yet.');
    }
}

