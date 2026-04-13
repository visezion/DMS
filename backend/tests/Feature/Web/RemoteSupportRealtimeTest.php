<?php

namespace Tests\Feature\Web;

use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\RemoteSupportSession;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemoteSupportRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_update_ops_persists_remote_support_runtime_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.ops.update'), [
            'max_retries' => 3,
            'base_backoff_seconds' => 30,
            'package_download_url_mode' => 'public',
            'remote_support_capture_max_dimension' => 1600,
            'remote_support_capture_jpeg_quality' => 70,
            'remote_support_live_fps' => 10,
            'remote_support_capture_interval_seconds' => 2,
            'remote_support_live_duration_seconds' => 240,
            'remote_support_webrtc_signal_poll_interval_ms' => 250,
            'remote_support_webrtc_input_flush_interval_ms' => 40,
            'remote_support_webrtc_input_batch_max' => 20,
            'remote_support_webrtc_admin_token_ttl_minutes' => 45,
            'remote_support_webrtc_stun_urls' => 'stun:stun1.example.test:3478, stun:stun2.example.test:3478',
            'remote_support_webrtc_turn_urls' => 'turn:turn.example.test:3478?transport=udp',
            'remote_support_webrtc_turn_username' => 'turn-user',
            'remote_support_webrtc_turn_credential' => 'turn-pass',
        ])->assertRedirect();

        $this->assertSame(1600, $this->settingValue('remote_support.capture_max_dimension'));
        $this->assertSame(70, $this->settingValue('remote_support.capture_jpeg_quality'));
        $this->assertSame(10, $this->settingValue('remote_support.live_fps'));
        $this->assertSame(2, $this->settingValue('remote_support.capture_interval_seconds'));
        $this->assertSame(240, $this->settingValue('remote_support.live_duration_seconds'));
        $this->assertSame(250, $this->settingValue('remote_support.webrtc_signal_poll_interval_ms'));
        $this->assertSame(40, $this->settingValue('remote_support.webrtc_input_flush_interval_ms'));
        $this->assertSame(20, $this->settingValue('remote_support.webrtc_input_batch_max'));
        $this->assertSame(45, $this->settingValue('remote_support.webrtc_admin_token_ttl_minutes'));
        $this->assertSame('stun:stun1.example.test:3478, stun:stun2.example.test:3478', $this->settingValue('remote_support.webrtc_stun_urls'));
        $this->assertSame('turn:turn.example.test:3478?transport=udp', $this->settingValue('remote_support.webrtc_turn_urls'));
        $this->assertSame('turn-user', $this->settingValue('remote_support.webrtc_turn_username'));
        $this->assertSame('turn-pass', $this->settingValue('remote_support.webrtc_turn_credential'));
    }

    public function test_realtime_bootstrap_queues_webrtc_job_with_configured_ice_servers(): void
    {
        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'REMOTE-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'webrtc_media_pipeline' => true,
                ],
            ],
        ]);
        $session = RemoteSupportSession::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'requested_by' => $user->id,
            'status' => 'active',
            'meta' => [],
        ]);

        $this->storeSetting('remote_support.live_duration_seconds', 180);
        $this->storeSetting('remote_support.capture_max_dimension', 1440);
        $this->storeSetting('remote_support.webrtc_signal_poll_interval_ms', 300);
        $this->storeSetting('remote_support.webrtc_input_flush_interval_ms', 48);
        $this->storeSetting('remote_support.webrtc_input_batch_max', 16);
        $this->storeSetting('remote_support.webrtc_stun_urls', 'stun:stun1.example.test:3478, stun:stun2.example.test:3478');
        $this->storeSetting('remote_support.webrtc_turn_urls', 'turn:turn.example.test:3478?transport=udp');
        $this->storeSetting('remote_support.webrtc_turn_username', 'turn-user');
        $this->storeSetting('remote_support.webrtc_turn_credential', 'turn-pass');

        $response = $this->actingAs($user)
            ->postJson(route('admin.remote-support.realtime.bootstrap', $session->id));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'mode' => 'webrtc',
                'session_id' => $session->id,
                'signal_poll_interval_ms' => 300,
                'input_flush_interval_ms' => 48,
                'input_batch_max' => 16,
            ]);

        $job = DmsJob::query()->where('job_type', 'start_webrtc_session')->latest('created_at')->first();
        $this->assertNotNull($job);
        $this->assertSame($device->id, $job->target_id);
        $this->assertSame($session->id, data_get($job->payload, 'session_id'));
        $this->assertSame(180, data_get($job->payload, 'duration_seconds'));
        $this->assertSame(1440, data_get($job->payload, 'max_dimension'));
        $this->assertSame('webrtc_v1', data_get($job->payload, 'transport'));
        $this->assertSame(
            [
                [
                    'urls' => ['stun:stun1.example.test:3478', 'stun:stun2.example.test:3478'],
                    'username' => null,
                    'credential' => null,
                ],
                [
                    'urls' => ['turn:turn.example.test:3478?transport=udp'],
                    'username' => 'turn-user',
                    'credential' => 'turn-pass',
                ],
            ],
            data_get($job->payload, 'ice_servers')
        );

        $run = JobRun::query()->where('job_id', $job->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('pending', $run->status);
    }

    public function test_realtime_signal_pull_and_input_endpoints_exchange_events(): void
    {
        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'REMOTE-02',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'webrtc_media_pipeline' => true,
                ],
            ],
        ]);
        $session = RemoteSupportSession::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'requested_by' => $user->id,
            'status' => 'active',
            'meta' => [],
        ]);

        $this->actingAs($user)->postJson(route('admin.remote-support.realtime.signal.push', $session->id), [
            'type' => 'answer',
            'payload' => ['sdp' => 'v=0'],
        ])->assertOk()->assertJson(['ok' => true]);

        $adminSignalEvents = Cache::get('remote_support:realtime:signals_admin_to_agent:'.$session->id, []);
        $this->assertCount(1, $adminSignalEvents);
        $this->assertSame('answer', $adminSignalEvents[0]['type']);

        Cache::put('remote_support:realtime:signals_agent_to_admin:'.$session->id, [[
            'seq' => 1,
            'type' => 'offer',
            'payload' => ['sdp' => 'offer-sdp'],
            'source' => 'agent',
            'created_at_iso' => now()->toIso8601String(),
        ]], now()->addMinutes(30));

        $this->actingAs($user)
            ->getJson(route('admin.remote-support.realtime.signal.pull', $session->id, false).'?since=0')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'latest_seq' => 1,
                'events' => [
                    ['type' => 'offer'],
                ],
            ]);

        $this->actingAs($user)->postJson(route('admin.remote-support.realtime.input.push', $session->id), [
            'events' => [
                ['type' => 'mouse_move', 'payload' => ['x' => 0.25, 'y' => 0.75]],
                ['type' => 'key_down', 'payload' => ['code' => 'KeyA', 'key' => 'a']],
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $inputEvents = Cache::get('remote_support:realtime:input_admin_to_agent:'.$session->id, []);
        $this->assertCount(2, $inputEvents);
        $this->assertSame('mouse_move', $inputEvents[0]['type']);
        $this->assertSame('key_down', $inputEvents[1]['type']);
    }

    private function storeSetting(string $key, mixed $value): void
    {
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]]
        );
    }

    private function settingValue(string $key): mixed
    {
        return data_get(ControlPlaneSetting::query()->where('key', $key)->first()?->value, 'value');
    }
}
