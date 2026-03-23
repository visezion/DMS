<?php

namespace Tests\Feature\Web;

use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use App\Models\ComplianceResult;
use App\Models\DeviceGroup;
use App\Models\ControlPlaneSetting;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\AiPower\NaturalLanguageCommandService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiPowerLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_power_landing_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.ai-power.index'))
            ->assertOk()
            ->assertSee('AI Power Landing')
            ->assertSee('AI Power Command Console');
    }

    public function test_ai_power_can_preview_a_plan_without_queueing_a_job(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'reboot_device',
                            'target_type' => 'device',
                            'target_query' => 'LAB-01',
                            'script' => '',
                            'run_as' => 'system',
                            'timeout_seconds' => 180,
                            'priority' => 90,
                            'confidence' => 0.91,
                            'rationale' => 'Explicit reboot request.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Please reboot device LAB-01 now.',
                'execute_now' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (string) data_get($result, 'plan.intent') === 'reboot_device';
            });

        $this->assertSame(0, DmsJob::query()->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_can_execute_run_command_and_queue_job(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'run_command_device',
                            'target_type' => 'device',
                            'target_query' => 'LAB-02',
                            'script' => 'gpupdate /force',
                            'run_as' => 'system',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.92,
                            'rationale' => 'Direct command request with explicit target.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Run command "gpupdate /force" on device LAB-02',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'job.job_type') === 'run_command';
            });

        $job = DmsJob::query()->firstOrFail();
        $payload = is_array($job->payload) ? $job->payload : [];

        $this->assertSame('run_command', $job->job_type);
        $this->assertSame('device', $job->target_type);
        $this->assertSame($device->id, $job->target_id);
        $this->assertSame('gpupdate /force', (string) ($payload['script'] ?? ''));
        $this->assertSame('system', (string) ($payload['run_as'] ?? ''));
        $this->assertSame(hash('sha256', 'gpupdate /force'), (string) ($payload['script_sha256'] ?? ''));
        $this->assertSame('run_command_device', (string) data_get($payload, 'ai_power.intent', ''));
        $this->assertSame(1, JobRun::query()->count());
        Http::assertSentCount(2);
    }

    public function test_ai_power_maps_restart_print_service_to_safe_run_command_and_executes(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-PRINT-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'restart print service on device LAB-PRINT-01',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (bool) data_get($result, 'run_command_test.ok', false) === true;
            });

        $job = DmsJob::query()->firstOrFail();
        $payload = is_array($job->payload) ? $job->payload : [];

        $this->assertSame('run_command', $job->job_type);
        $this->assertStringContainsString('Restart-Service -Name Spooler', (string) data_get($payload, 'script'));
        $this->assertSame('system', (string) data_get($payload, 'run_as'));
    }

    public function test_ai_power_requires_explicit_confirmation_for_high_risk_disable_firewall_command(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-SEC-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'disable firewall on device LAB-SEC-01',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'plan.risk_level') === 'high'
                    && (bool) data_get($result, 'plan.requires_approval', false) === true
                    && trim((string) data_get($result, 'confirmation_required.confirmation_phrase', '')) !== '';
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_executes_high_risk_disable_firewall_after_confirmation_phrase(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-SEC-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'disable firewall on device LAB-SEC-02',
                'execute_now' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'confirm disable firewall on device LAB-SEC-02',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'job.job_type') === 'run_command';
            });

        $job = DmsJob::query()->latest('created_at')->firstOrFail();
        $payload = is_array($job->payload) ? $job->payload : [];
        $this->assertStringContainsString('netsh advfirewall set allprofiles state off', (string) data_get($payload, 'script'));
        $this->assertSame('high', (string) data_get($payload, 'ai_power.risk_level'));
    }

    public function test_ai_power_prefers_fallback_action_when_openai_returns_ai_query_for_operational_request(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'ai_query',
                            'target_type' => 'device',
                            'target_query' => '',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 30,
                            'priority' => 1,
                            'confidence' => 0.05,
                            'rationale' => 'Misclassified analytics request.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-PRINT-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'restart print service on device LAB-PRINT-02',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'plan.source') === 'fallback'
                    && (bool) ($result['executed'] ?? false) === true;
            });

        $this->assertSame(1, DmsJob::query()->count());
        Http::assertSentCount(2);
    }

    public function test_ai_power_prefers_fallback_ai_query_when_openai_returns_run_command_for_inventory_question(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'run_command_device',
                            'target_type' => 'device',
                            'target_query' => 'KURSU-ST110',
                            'script' => 'winget list',
                            'run_as' => 'default',
                            'timeout_seconds' => 180,
                            'priority' => 100,
                            'confidence' => 0.90,
                            'rationale' => 'Software inventory command.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'inventory' => [
                    'software' => [
                        [
                            'name' => 'Google Chrome',
                            'version' => '121.0',
                            'outdated' => false,
                            'unauthorized' => false,
                            'installed_at' => now()->toIso8601String(),
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'List installed software on KURSU-ST110',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'plan.source') === 'fallback'
                    && (string) data_get($result, 'ai_function.domain') === 'software'
                    && (string) data_get($result, 'ai_function.topic') === 'inventory'
                    && (string) data_get($result, 'ai_function.items.0.label') === 'Google Chrome';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'google chrome')
                    && ! str_contains($message, 'kursu-st110 - google chrome')
                    && ! str_contains($message, 'job queued');
            });

        $this->assertSame(0, DmsJob::query()->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_outdated_software_query_returns_device_level_answer(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'OLD-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'inventory' => [
                    'software' => [
                        ['name' => 'Legacy App A', 'version' => '1.0', 'outdated' => true, 'unauthorized' => false],
                        ['name' => 'Legacy App B', 'version' => '2.0', 'outdated' => true, 'unauthorized' => false],
                    ],
                ],
            ],
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'NEW-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'inventory' => [
                    'software' => [
                        ['name' => 'Current App', 'version' => '10.0', 'outdated' => false, 'unauthorized' => false],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices have outdated software?',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'software'
                    && (string) data_get($result, 'ai_function.topic') === 'outdated'
                    && (string) data_get($result, 'ai_function.items.0.label') === 'OLD-01';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'outdated software')
                    && str_contains($message, 'old-01')
                    && ! str_contains($message, 'old-01 -');
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_installed_software_summary_keeps_full_app_names_with_hyphen(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'DESKTOP-S3DNC2H',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'inventory' => [
                    'software' => [
                        [
                            'name' => 'Microsoft Visual C++ 2022 X64 Additional Runtime - 14.40.33810',
                            'version' => '14.40.33810',
                            'outdated' => false,
                            'unauthorized' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'List installed software on DESKTOP-S3DNC2H',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = (string) ($last['message'] ?? '');

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'Microsoft Visual C++ 2022 X64 Additional Runtime - 14.40.33810')
                    && ! str_contains($message, 'Applications on DESKTOP-S3DNC2H: 14.40.33810');
            });
    }

    public function test_ai_power_remove_software_follow_up_uses_previous_device_context(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'DESKTOP-S3DNC2H',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'inventory' => [
                    'software' => [
                        ['name' => '7-Zip 26.00 (x64)', 'version' => '26.00', 'outdated' => false, 'unauthorized' => false],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'List installed software on DESKTOP-S3DNC2H',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result): bool {
                return is_array($result)
                    && (string) data_get($result, 'ai_function.domain') === 'software'
                    && in_array((string) data_get($result, 'ai_function.context.target.scope', ''), ['device', 'fleet'], true);
            });

        $lastResult = session('ai_power_last_result');
        $this->assertIsArray($lastResult);
        $this->assertSame('DESKTOP-S3DNC2H', (string) data_get($lastResult, 'ai_function.context.target.device.hostname', ''));

        $this
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'remove 7-Zip 26.00 (x64)',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'resolution.device.id') === (string) $device->id;
            });

        $job = DmsJob::query()->latest('created_at')->firstOrFail();
        $payload = is_array($job->payload) ? $job->payload : [];
        $script = (string) ($payload['script'] ?? '');

        $this->assertSame('run_command', $job->job_type);
        $this->assertStringContainsString('winget uninstall --name', $script);
        $this->assertStringContainsString('7-Zip 26.00 (x64)', $script);
    }

    public function test_ai_power_requires_confirmation_for_clear_temp_all_devices_command(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CLEAN-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CLEAN-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'clear temp files on all devices',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'confirmation_required.scope') === 'all_devices'
                    && (int) data_get($result, 'confirmation_required.device_count', 0) === 2
                    && (bool) data_get($result, 'run_command_test.ok', false) === true;
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_blocks_run_command_when_preflight_fails(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-RISK-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'run command "format c: /q" on device LAB-RISK-01',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('ai_power')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) data_get($result, 'run_command_test.ok', true) === false
                    && count((array) data_get($result, 'run_command_test.errors', [])) > 0;
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_executes_clear_temp_all_devices_after_confirmation(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CLEAN-11',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CLEAN-12',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'confirm run command on all devices clear temp files',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (int) data_get($result, 'bulk_job.count', 0) === 2;
            });

        $this->assertSame(2, DmsJob::query()->count());
        $this->assertSame(2, JobRun::query()->count());
        DmsJob::query()->each(function (DmsJob $job): void {
            $payload = is_array($job->payload) ? $job->payload : [];
            $this->assertSame('run_command', $job->job_type);
            $this->assertStringContainsString('Remove-Item', (string) data_get($payload, 'script'));
        });
    }

    public function test_ai_power_requires_confirmation_for_group_command_install_chrome(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Labs',
            'description' => 'Lab endpoints',
        ]);
        $deviceA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CHROME-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $deviceB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CHROME-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $deviceA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $deviceB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'install chrome on all labs computers',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'resolution.target_type') === 'group'
                    && (string) data_get($result, 'confirmation_required.scope') === 'group'
                    && (int) data_get($result, 'confirmation_required.device_count', 0) === 2;
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_executes_group_command_install_chrome_after_confirmation(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Labs',
            'description' => 'Lab endpoints',
        ]);
        $deviceA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CHROME-11',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $deviceB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-CHROME-12',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $deviceA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $deviceB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'confirm run command on all devices in group Labs install chrome',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && (string) data_get($result, 'bulk_job.scope') === 'group'
                    && (int) data_get($result, 'bulk_job.count', 0) === 2;
            });

        $this->assertSame(2, DmsJob::query()->count());
        $this->assertSame(2, JobRun::query()->count());
        DmsJob::query()->each(function (DmsJob $job): void {
            $payload = is_array($job->payload) ? $job->payload : [];
            $this->assertSame('run_command', $job->job_type);
            $this->assertStringContainsString('winget install --id Google.Chrome', (string) data_get($payload, 'script'));
        });
    }

    public function test_ai_power_can_create_policy_and_apply_to_group(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'create_policy',
                            'target_type' => 'group',
                            'target_query' => 'LABS',
                            'policy_name' => 'Nightly GPUpdate',
                            'policy_query' => 'Nightly GPUpdate',
                            'policy_category' => 'operations/automation',
                            'policy_command' => 'gpupdate /force',
                            'script' => '',
                            'run_as' => 'system',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.94,
                            'rationale' => 'Create command policy and apply to a named group.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'LABS',
            'description' => 'School lab systems',
        ]);
        $deviceA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-PC-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $deviceB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-PC-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $deviceA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $deviceB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => "Create policy 'Nightly GPUpdate' command 'gpupdate /force' and apply to group LABS",
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'create_policy'
                    && (string) data_get($result, 'job.job_type') === 'apply_policy'
                    && is_array(data_get($result, 'policy'));
            });

        $policy = Policy::query()->firstOrFail();
        $version = PolicyVersion::query()->where('policy_id', $policy->id)->firstOrFail();
        $rule = PolicyRule::query()->where('policy_version_id', $version->id)->firstOrFail();
        $job = DmsJob::query()->where('job_type', 'apply_policy')->firstOrFail();

        $this->assertSame('Nightly GPUpdate', $policy->name);
        $this->assertSame('active', $policy->status);
        $this->assertSame('command', $rule->rule_type);
        $this->assertSame('gpupdate /force', (string) data_get($rule->rule_config, 'command'));
        $this->assertDatabaseHas('policy_assignments', [
            'policy_version_id' => $version->id,
            'target_type' => 'group',
            'target_id' => $group->id,
        ]);
        $this->assertSame('group', $job->target_type);
        $this->assertSame($group->id, $job->target_id);
        $this->assertSame(2, JobRun::query()->where('job_id', $job->id)->count());
        Http::assertSentCount(2);
    }

    public function test_ai_power_can_apply_existing_policy_to_device(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'apply_policy',
                            'target_type' => 'device',
                            'target_query' => 'LAB-04',
                            'policy_name' => '',
                            'policy_query' => 'nightly-gpupdate',
                            'policy_category' => '',
                            'policy_command' => '',
                            'script' => '',
                            'run_as' => 'system',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.90,
                            'rationale' => 'Apply existing policy to specific device.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-04',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Nightly GPUpdate',
            'slug' => 'nightly-gpupdate',
            'category' => 'operations/automation',
            'status' => 'active',
        ]);
        $version = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'version_number' => 1,
            'status' => 'active',
            'created_by' => $user->id,
            'published_at' => now(),
        ]);
        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $version->id,
            'order_index' => 0,
            'rule_type' => 'command',
            'rule_config' => [
                'command' => 'gpupdate /force',
                'run_as' => 'system',
                'timeout_seconds' => 300,
            ],
            'enforce' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => "Apply policy 'nightly-gpupdate' to device LAB-04",
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($version) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'apply_policy'
                    && (string) data_get($result, 'policy.version_id') === $version->id
                    && (string) data_get($result, 'job.job_type') === 'apply_policy';
            });

        $job = DmsJob::query()->where('job_type', 'apply_policy')->firstOrFail();
        $this->assertSame('device', $job->target_type);
        $this->assertSame($device->id, $job->target_id);
        $this->assertDatabaseHas('policy_assignments', [
            'policy_version_id' => $version->id,
            'target_type' => 'device',
            'target_id' => $device->id,
        ]);
        $this->assertSame(1, JobRun::query()->where('job_id', $job->id)->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_can_create_group_and_add_device_in_single_instruction(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'DESKTOP-S3DNC2H',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'create lab group and add DESKTOP-S3DNC2H to the group',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) data_get($result, 'executed', false) === true
                    && (string) data_get($result, 'plan.intent') === 'group_membership'
                    && (string) data_get($result, 'group.name') === 'lab'
                    && (bool) data_get($result, 'group.created', false) === true;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'group lab')
                    && str_contains($message, 'device was added to the group');
            });

        $group = DeviceGroup::query()->whereRaw('LOWER(name) = ?', ['lab'])->first();
        $this->assertNotNull($group);
        $membershipExists = DB::table('device_group_memberships')
            ->where('device_group_id', (string) $group?->id)
            ->where('device_id', $device->id)
            ->exists();
        $this->assertTrue($membershipExists);
    }

    public function test_ai_power_can_add_device_to_existing_group_from_plain_instruction(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'lab',
            'description' => 'Lab devices',
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'add KURSU-ST110 to lab',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($group) {
                return is_array($result)
                    && (bool) data_get($result, 'executed', false) === true
                    && (string) data_get($result, 'plan.intent') === 'group_membership'
                    && (string) data_get($result, 'group.name') === 'lab'
                    && (string) data_get($result, 'group.id') === $group->id;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'added kursu-st110 to group lab');
            });

        $this->assertDatabaseHas('device_group_memberships', [
            'device_group_id' => $group->id,
            'device_id' => $device->id,
        ]);
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_ai_power_asks_to_create_group_when_add_target_group_is_missing(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'add KURSU-ST110 to lab',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('ai_power')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) data_get($result, 'executed', true) === false
                    && (string) data_get($result, 'plan.intent') === 'group_membership'
                    && (string) data_get($result, 'plan.target_query') === 'lab';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'do you want me to create it');
            });

        $this->assertSame(0, DeviceGroup::query()->count());
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_ai_power_can_create_group_from_simple_create_group_instruction(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'create software group',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) data_get($result, 'executed', false) === true
                    && (string) data_get($result, 'plan.intent') === 'group_membership'
                    && (string) data_get($result, 'group.name') === 'software'
                    && (bool) data_get($result, 'group.created', false) === true;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'group software created');
            });

        $this->assertDatabaseHas('device_groups', ['name' => 'software']);
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_ai_power_can_create_group_and_assign_current_available_devices(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $onlineA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'AVAILABLE-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $onlineB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'AVAILABLE-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'NOT-AVAILABLE-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'create a group named class id and put the current available devices into the group',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) data_get($result, 'executed', false) === true
                    && (string) data_get($result, 'plan.intent') === 'group_membership'
                    && (string) data_get($result, 'group.name') === 'class id';
            });

        $group = DeviceGroup::query()->whereRaw('LOWER(name) = ?', ['class id'])->first();
        $this->assertNotNull($group);
        $this->assertDatabaseHas('device_group_memberships', [
            'device_group_id' => (string) $group?->id,
            'device_id' => $onlineA->id,
        ]);
        $this->assertDatabaseHas('device_group_memberships', [
            'device_group_id' => (string) $group?->id,
            'device_id' => $onlineB->id,
        ]);
    }

    public function test_ai_power_requires_confirmation_before_applying_policy_to_all_devices(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Nightly GPUpdate',
            'slug' => 'nightly-gpupdate',
            'category' => 'operations/automation',
            'status' => 'active',
        ]);
        $version = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'version_number' => 1,
            'status' => 'active',
            'created_by' => $user->id,
            'published_at' => now(),
        ]);
        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $version->id,
            'order_index' => 0,
            'rule_type' => 'command',
            'rule_config' => [
                'command' => 'gpupdate /force',
                'run_as' => 'system',
                'timeout_seconds' => 300,
            ],
            'enforce' => true,
        ]);

        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'APPLY-ALL-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'APPLY-ALL-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'apply policy "nightly-gpupdate" to all devices',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (string) data_get($result, 'plan.intent') === 'apply_policy'
                    && (string) data_get($result, 'confirmation_required.scope') === 'all_devices'
                    && (int) data_get($result, 'confirmation_required.device_count', 0) === 2;
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_can_apply_policy_to_all_devices_after_confirmation(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Nightly GPUpdate',
            'slug' => 'nightly-gpupdate',
            'category' => 'operations/automation',
            'status' => 'active',
        ]);
        $version = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'version_number' => 1,
            'status' => 'active',
            'created_by' => $user->id,
            'published_at' => now(),
        ]);
        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $version->id,
            'order_index' => 0,
            'rule_type' => 'command',
            'rule_config' => [
                'command' => 'gpupdate /force',
                'run_as' => 'system',
                'timeout_seconds' => 300,
            ],
            'enforce' => true,
        ]);

        $deviceA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'APPLY-ALL-11',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $deviceB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'APPLY-ALL-12',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'confirm apply policy "nightly-gpupdate" to all devices',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'apply_policy'
                    && (int) data_get($result, 'bulk_job.count', 0) === 2
                    && (string) data_get($result, 'bulk_job.scope') === 'all_devices';
            });

        $this->assertSame(2, DmsJob::query()->where('job_type', 'apply_policy')->count());
        $this->assertSame(2, JobRun::query()->count());
        $this->assertDatabaseHas('policy_assignments', [
            'policy_version_id' => $version->id,
            'target_type' => 'device',
            'target_id' => $deviceA->id,
        ]);
        $this->assertDatabaseHas('policy_assignments', [
            'policy_version_id' => $version->id,
            'target_type' => 'device',
            'target_id' => $deviceB->id,
        ]);
    }

    public function test_ai_power_executes_when_openai_omits_confidence_but_plan_is_complete(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'reboot_device',
                            'target_type' => 'device',
                            'target_query' => 'BIM-VICTOR',
                            'script' => '',
                            'run_as' => 'system',
                            'timeout_seconds' => 180,
                            'priority' => 100,
                            'rationale' => 'Direct reboot request for named host.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BIM-VICTOR',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'reboot BIM-VICTOR',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'reboot_device'
                    && (float) data_get($result, 'plan.confidence', 0.0) >= 0.35
                    && (string) data_get($result, 'resolution.device.id') === $device->id;
            });

        $job = DmsJob::query()->firstOrFail();
        $this->assertSame('run_command', $job->job_type);
        $this->assertSame($device->id, $job->target_id);
        Http::assertSentCount(2);
    }

    public function test_low_confidence_error_keeps_resolution_context_for_user_feedback(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'reboot_device',
                            'target_type' => 'device',
                            'target_query' => 'BIM-VICTOR',
                            'script' => '',
                            'run_as' => 'system',
                            'timeout_seconds' => 180,
                            'priority' => 100,
                            'confidence' => 0.05,
                            'rationale' => 'Ambiguous instruction confidence is low.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BIM-VICTOR',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'reboot BIM-VICTOR',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('ai_power')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (bool) data_get($result, 'resolution.ok', false) === true
                    && (string) data_get($result, 'resolution.device.hostname') === 'BIM-VICTOR';
            });

        $this->assertSame(0, DmsJob::query()->count());
        Http::assertSentCount(1);
    }

    public function test_fallback_parser_handles_plain_reboot_hostname_without_openai(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BIM-VICTOR',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'reboot BIM-VICTOR',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'reboot_device'
                    && (string) data_get($result, 'resolution.device.id') === $device->id;
            });

        $job = DmsJob::query()->firstOrFail();
        $this->assertSame('run_command', $job->job_type);
    }

    public function test_ai_power_can_lookup_device_status_without_queueing_job(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'get_device_status',
                            'target_type' => 'device',
                            'target_query' => 'KURSU-ST110',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.96,
                            'rationale' => 'User requested device status.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'what is the status of KURSU-ST110',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (string) data_get($result, 'device_status.status') === 'online';
            });

        $this->assertSame(0, DmsJob::query()->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_uses_fallback_when_openai_returns_unknown_for_status_request(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'unknown',
                            'target_type' => 'device',
                            'target_query' => '',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.0,
                            'rationale' => 'Could not classify.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'what is the status of KURSU-ST110',
                'execute_now' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'plan.source') === 'fallback'
                    && (string) data_get($result, 'resolution.device.id') === $device->id;
            });

        $this->assertSame(0, DmsJob::query()->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_device_status_treats_stale_online_as_offline(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);
        config()->set('services.openai.ai_power_online_window_minutes', 5);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'STALE-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'status of STALE-01',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (string) data_get($result, 'device_status.status') === 'offline';
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_ip_lookup_uses_real_device_ip_when_openai_returns_ai_query(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'ai_query',
                            'target_type' => 'device',
                            'target_query' => '-',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 30,
                            'priority' => 1,
                            'confidence' => 0.0,
                            'rationale' => 'Analytics interpretation.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'ip_address' => '172.16.43.110',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'whatis the ip of KURSU-ST110',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'plan.source') === 'fallback'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (string) data_get($result, 'device_status.ip_address') === '172.16.43.110';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'ip: 172.16.43.110')
                    && str_contains($message, 'kursu-st110')
                    && str_contains($message, 'online');
            });

        $this->assertSame(0, DmsJob::query()->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_can_resolve_device_by_exact_ip_lookup(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BIM-VICTOR',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'ip_address' => '172.16.43.163',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'which device has IP 172.16.43.163',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (string) data_get($result, 'device_status.ip_address') === '172.16.43.163';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, '172.16.43.163')
                    && str_contains($message, 'belongs to bim-victor');
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_can_resolve_device_by_unique_ip_prefix_lookup(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BIM-VICTOR',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'ip_address' => '172.16.43.163',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'find IP 172.16.43.16',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (string) data_get($result, 'resolution.lookup.match') === 'prefix';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, '172.16.43.16')
                    && str_contains($message, 'matches bim-victor')
                    && str_contains($message, '172.16.43.163');
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_follow_up_status_query_uses_previous_target_context(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'ip_address' => '172.16.43.110',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'what is the status of KURSU-ST110',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'resolution.device.id') === $device->id;
            });

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'what is the ip of this device',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'plan.target_query') === 'KURSU-ST110'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (string) data_get($result, 'device_status.ip_address') === '172.16.43.110';
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_network_query_counts_stale_online_as_offline(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);
        config()->set('services.openai.ai_power_online_window_minutes', 5);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'NET-STALE-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now()->subMinutes(30),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'NET-ON-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'which devices are offline right now',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                if (! is_array($result)) {
                    return false;
                }
                $metrics = (array) data_get($result, 'ai_function.metrics', []);
                $offlineMetric = collect($metrics)->first(fn ($m) => (string) ($m['label'] ?? '') === 'Offline');

                return (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'network'
                    && (string) ($offlineMetric['value'] ?? '0') === '1';
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_can_answer_not_checked_in_custom_minutes_window(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CHK-RECENT-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now()->subSeconds(20),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CHK-STALE-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices have not checked in 1 min ago',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'health'
                    && (string) data_get($result, 'ai_function.topic') === 'not_checked_in_window:1'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'within the last 1 minute')
                    && count((array) data_get($result, 'ai_function.items', [])) >= 1;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'chk-stale-01')
                    && ! str_contains($message, 'current posture');
            });
    }

    public function test_ai_power_device_status_uses_jobs_online_window_setting_for_freshness(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'jobs.online_window_minutes'],
            ['value' => ['value' => 2], 'updated_by' => $user->id]
        );

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'STALE-WINDOW-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now()->subMinutes(4),
            'tags' => [
                'runtime_diagnostics' => [
                    'ip_address' => '10.200.1.10',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'status of STALE-WINDOW-01',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'resolution.device.id') === $device->id
                    && (string) data_get($result, 'device_status.status') === 'offline';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'stale-window-01 is offline');
            });
    }

    public function test_ai_power_can_show_detailed_breakdown_when_user_requests_details(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'KURSU-ST110',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'what is the status of KURSU-ST110',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'details',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = (string) ($last['message'] ?? '');

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'Here are the full details from the last AI result.')
                    && str_contains($message, 'Plan')
                    && str_contains($message, 'Confidence:');
            });
    }

    public function test_ai_power_can_return_project_inventory_of_functions_and_values(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'INV-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show all functions and all values in this project',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (string) data_get($result, 'plan.intent') === 'project_inventory'
                    && (int) data_get($result, 'project_inventory.summary.total_admin_routes', 0) > 0
                    && (int) data_get($result, 'project_inventory.values.devices_total', 0) >= 1;
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_unknown_request_prompts_clarifying_question_in_chat(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'blabla ???',
                'execute_now' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'unknown';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains(mb_strtolower((string) ($last['message'] ?? '')), 'exact target and action');
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_greeting_returns_friendly_welcome_without_plan_block(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'hello',
                'execute_now' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'unknown';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'hello.')
                    && ! str_contains($message, 'plan');
            });
    }

    public function test_ai_power_typo_greeting_returns_friendly_welcome(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'heelo',
                'execute_now' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'hello.')
                    && ! str_contains($message, 'exact target and action');
            });
    }

    public function test_ai_power_thank_you_returns_friendly_acknowledgement_without_unknown_block(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'thank you',
                'execute_now' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'unknown';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'you are welcome')
                    && ! str_contains($message, 'i did not fully understand');
            });
    }

    public function test_ai_power_network_ip_query_for_named_host_prefers_device_status(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'ai_query',
                            'target_type' => 'device',
                            'target_query' => '',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 30,
                            'priority' => 1,
                            'confidence' => 0.15,
                            'rationale' => 'General network analysis request.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BIM-VICTOR',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'ip_address' => '172.16.43.163',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show all BIM-VICTOR network ip',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && (string) data_get($result, 'plan.source') === 'fallback'
                    && (string) data_get($result, 'device_status.ip_address') === '172.16.43.163';
            });
    }

    public function test_ai_power_status_query_can_include_interface_details_when_reported(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BIM-VICTOR',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'ip_address' => '172.16.43.163',
                    'network' => [
                        'interfaces' => [
                            ['name' => 'Ethernet0', 'ip_address' => '172.16.43.163', 'mac_address' => '00-11-22-33-44-55'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show BIM-VICTOR network interface',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                $interfaces = (array) data_get($result, 'device_status.network_interfaces', []);
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'get_device_status'
                    && count($interfaces) >= 1
                    && str_contains((string) ($interfaces[0] ?? ''), 'Ethernet0');
            });
    }

    public function test_ai_power_generates_policy_command_when_missing_and_creates_policy(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake(function (HttpRequest $request) {
            $messages = (array) data_get($request->data(), 'messages', []);
            $joined = collect($messages)->pluck('content')->filter()->implode("\n");

            if (str_contains($joined, 'Convert user instruction into strict JSON.')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'intent' => 'create_policy',
                                'target_type' => 'device',
                                'target_query' => 'LAB-05',
                                'policy_name' => 'Disable USB Policy',
                                'policy_query' => 'Disable USB Policy',
                                'policy_category' => 'security/device-control',
                                'policy_command' => '',
                                'script' => '',
                                'run_as' => 'system',
                                'timeout_seconds' => 300,
                                'priority' => 100,
                                'confidence' => 0.95,
                                'rationale' => 'Create disable-usb policy and apply to target device.',
                            ], JSON_UNESCAPED_SLASHES),
                        ],
                    ]],
                ], 200);
            }

            if (str_contains($joined, 'Generate a single endpoint policy command')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'command' => 'reg add "HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows\\RemovableStorageDevices" /v Deny_All /t REG_DWORD /d 1 /f',
                                'run_as' => 'system',
                                'timeout_seconds' => 300,
                                'confidence' => 0.92,
                                'rationale' => 'Disables removable storage access through Windows policy key.',
                            ], JSON_UNESCAPED_SLASHES),
                        ],
                    ]],
                ], 200);
            }

            if (str_contains($joined, 'Review this endpoint policy command for safe/valid use.')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'pass' => true,
                                'confidence' => 0.95,
                                'errors' => [],
                                'warnings' => [],
                            ], JSON_UNESCAPED_SLASHES),
                        ],
                    ]],
                ], 200);
            }

            return Http::response([], 500);
        });

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-05',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'create a policy that disable usb and apply to device LAB-05',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (string) data_get($result, 'plan.intent') === 'create_policy'
                    && is_array(data_get($result, 'policy_command_generated'))
                    && (bool) data_get($result, 'policy_test.ok', false) === true
                    && (string) data_get($result, 'resolution.device.id') === $device->id;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = (string) ($last['message'] ?? '');

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'Policy Command Test Passed')
                    && str_contains($message, 'Policy Result')
                    && str_contains($message, 'Reply "details" for full plan and diagnostics.');
            });

        $policy = Policy::query()->firstOrFail();
        $version = PolicyVersion::query()->where('policy_id', $policy->id)->firstOrFail();
        $rule = PolicyRule::query()->where('policy_version_id', $version->id)->firstOrFail();

        $this->assertSame('command', $rule->rule_type);
        $this->assertStringContainsString('RemovableStorageDevices', (string) data_get($rule->rule_config, 'command'));
        Http::assertSentCount(3);
    }

    public function test_ai_power_can_create_usb_policy_from_typo_natural_language_without_target(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'create a policy ti disble USB',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result');

        $policy = Policy::query()->firstOrFail();
        $version = PolicyVersion::query()->where('policy_id', $policy->id)->firstOrFail();
        $rule = PolicyRule::query()->where('policy_version_id', $version->id)->firstOrFail();

        $this->assertSame('Disable USB Policy', $policy->name);
        $this->assertStringContainsString('RemovableStorageDevices', (string) data_get($rule->rule_config, 'command'));
        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_blocks_policy_save_when_preflight_test_fails(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-06',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'create policy "Bad Format Policy" command "format c: /q" and apply to device LAB-06',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('ai_power')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) data_get($result, 'policy_test.ok', true) === false
                    && count((array) data_get($result, 'policy_test.errors', [])) > 0;
            });

        $this->assertSame(0, Policy::query()->count());
        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_requires_confirmation_before_rebooting_all_connected_devices(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'reboot_device',
                            'target_type' => 'group',
                            'target_query' => 'all',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.90,
                            'rationale' => 'Restart all connected devices.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CONN-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CONN-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'OFF-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'i want to restrt all the device connetd',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? true) === false
                    && (int) data_get($result, 'confirmation_required.device_count', 0) === 2
                    && (string) data_get($result, 'confirmation_required.scope', '') === 'all_connected';
            });

        $this->assertSame(0, DmsJob::query()->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_can_reboot_all_connected_devices_after_confirmation(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'reboot_device',
                            'target_type' => 'group',
                            'target_query' => 'all',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.90,
                            'rationale' => 'Restart all connected devices.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $onlineA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CONN-11',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $onlineB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CONN-12',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'OFF-11',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'confirm restart all connected devices',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (bool) ($result['executed'] ?? false) === true
                    && (int) data_get($result, 'bulk_job.count', 0) === 2
                    && (string) data_get($result, 'bulk_job.scope', '') === 'all_connected';
            });

        $jobs = DmsJob::query()->get();
        $targetIds = $jobs->pluck('target_id')->all();

        $this->assertCount(2, $jobs);
        $this->assertContains($onlineA->id, $targetIds);
        $this->assertContains($onlineB->id, $targetIds);
        $this->assertSame(2, JobRun::query()->count());
        Http::assertSentCount(1);
    }

    public function test_ai_power_can_answer_health_queries_with_ai_function_system(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-HEALTH-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 96,
                    'memory_usage_percent' => 91,
                    'disk_free_percent' => 8,
                    'pending_restart' => true,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'which devices are unhealthy right now',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'health'
                    && count((array) data_get($result, 'ai_function.items', [])) >= 1;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'unhealthy or degraded')
                    && str_contains($message, 'devices: lab-health-01');
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_can_answer_group_scoped_device_count_queries(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'lab',
            'description' => 'Lab systems',
        ]);
        $labA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-COUNT-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $labB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-COUNT-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(10),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'OUTSIDE-COUNT-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $labA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $labB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'how many devices is in the lab?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_count'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'group lab has 2 devices');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'group lab has 2 devices')
                    && str_contains($message, 'lab-count-01')
                    && str_contains($message, 'lab-count-02')
                    && ! str_contains($message, 'outside-count-01')
                    && ! str_contains($message, 'current posture');
            });
    }

    public function test_ai_power_follow_up_in_group_phrase_returns_group_count_not_posture(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'lab',
            'description' => 'Lab systems',
        ]);
        $labA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-FOLLOW-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $labB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-FOLLOW-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $labA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $labB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'how many devices is in the lab?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_function.topic') === 'device_count';
            });

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'in lab group?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_count'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'group lab has 2 devices');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'group lab has 2 devices')
                    && ! str_contains($message, 'current posture');
            });
    }

    public function test_ai_power_list_devices_in_group_query_is_not_project_inventory(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'project_inventory',
                            'target_type' => 'device',
                            'target_query' => '',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.90,
                            'rationale' => 'Inventory request.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'lab',
            'description' => 'Lab systems',
        ]);
        $labA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-LIST-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $labB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-LIST-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $labA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $labB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'list devices in lab group',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'plan.source') === 'fallback'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_count'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'group lab has 2 devices');
            });
    }

    public function test_ai_power_show_lab_devices_routes_to_group_query_not_project_inventory(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'lab',
            'description' => 'Lab systems',
        ]);
        $labA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-SHOW-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $labB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-SHOW-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(15),
        ]);
        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $labA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $labB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show lab devices',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_count'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'group lab has 2 devices');
            });
    }

    public function test_ai_power_available_devices_query_returns_device_list_not_project_inventory(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'project_inventory',
                            'target_type' => 'device',
                            'target_query' => '',
                            'script' => '',
                            'run_as' => 'default',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.90,
                            'rationale' => 'Inventory request.',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'AVAILABLE-LIST-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show all available devices',
                'execute_now' => '1',
            ])
            ->assertRedirect(route('admin.ai-power.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'plan.source') === 'fallback'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_list';
            });
    }

    public function test_ai_power_how_many_devices_are_online_returns_online_count_topic(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ONLINE-COUNT-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ONLINE-COUNT-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ONLINE-COUNT-03',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'how many devices are online',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'online_count'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), '2 devices are online');
            });
    }

    public function test_ai_power_follow_up_can_return_device_names_from_previous_health_result(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-HEALTH-09',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 95,
                    'memory_usage_percent' => 89,
                    'disk_free_percent' => 7,
                    'ip_address' => '172.16.43.201',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices are unhealthy right now?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'health'
                    && count((array) data_get($result, 'ai_function.items', [])) >= 1;
            });

        $this->actingAs($user)
            ->get(route('admin.ai-power.index'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'what is the name of the device',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_follow_up.kind') === 'device_names'
                    && (int) data_get($result, 'ai_follow_up.count', 0) >= 1;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'unhealthy device')
                    && str_contains($message, 'lab-health-09')
                    && ! str_contains($message, 'ai operations overview');
            });
    }

    public function test_ai_power_follow_up_show_names_returns_device_names_not_inventory(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'FOLLOW-NAME-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 97,
                    'memory_usage_percent' => 91,
                    'disk_free_percent' => 8,
                    'ip_address' => '172.16.43.211',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices are unhealthy right now?',
                'execute_now' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show names',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_follow_up.kind') === 'device_names'
                    && (bool) data_get($result, '_suppress_summary', false) === true;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'follow-name-01')
                    && ! str_contains($message, 'project inventory');
            });
    }

    public function test_ai_power_active_device_ip_query_returns_device_ip_list_not_fleet_status(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ACTIVE-IP-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => ['runtime_diagnostics' => ['ip_address' => '10.10.10.11']],
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ACTIVE-IP-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => ['runtime_diagnostics' => ['ip_address' => '10.10.10.12']],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'how all active device IP',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_ips';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'ip list')
                    && str_contains($message, 'active-ip-01')
                    && ! str_contains($message, 'fleet status');
            });
    }

    public function test_ai_power_follow_up_can_return_device_ip_from_previous_health_result(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-IP-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 92,
                    'memory_usage_percent' => 90,
                    'disk_free_percent' => 9,
                    'ip_address' => '172.16.43.210',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices are unhealthy right now?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_function.domain') === 'health'
                    && count((array) data_get($result, 'ai_function.items', [])) >= 1;
            });

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show the device IP',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'device_status.hostname') === 'LAB-IP-01'
                    && (string) data_get($result, 'device_status.ip_address') === '172.16.43.210';
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'lab-ip-01')
                    && str_contains($message, '172.16.43.210')
                    && ! str_contains($message, 'i could not resolve the target device');
            });
    }

    public function test_ai_power_follow_up_show_there_ip_returns_bulk_ip_list_from_previous_result(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BULK-IP-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => ['runtime_diagnostics' => ['cpu_usage_percent' => 95, 'ip_address' => '172.16.43.31']],
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BULK-IP-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => ['runtime_diagnostics' => ['cpu_usage_percent' => 93, 'ip_address' => '172.16.43.32']],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices are unhealthy right now?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_function.domain') === 'health'
                    && count((array) data_get($result, 'ai_function.items', [])) >= 2;
            });

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show there ip',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_follow_up.kind') === 'device_ips'
                    && (int) data_get($result, 'ai_follow_up.count', 0) >= 2;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'device ips:')
                    && str_contains($message, 'bulk-ip-01')
                    && str_contains($message, 'bulk-ip-02')
                    && ! str_contains($message, 'please tell me which one to show ip for')
                    && ! str_contains($message, 'current posture');
            });
    }

    public function test_ai_power_group_devices_ip_query_uses_group_scope_not_previous_follow_up_disambiguation(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $group = DeviceGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'lab',
            'description' => 'Lab systems',
        ]);

        $labA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-IP-LIST-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => ['runtime_diagnostics' => ['ip_address' => '172.16.43.41']],
        ]);
        $labB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-IP-LIST-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(15),
            'tags' => ['runtime_diagnostics' => ['ip_address' => '172.16.43.42']],
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'OUTSIDE-IP-LIST-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => ['runtime_diagnostics' => ['ip_address' => '172.16.43.99']],
        ]);

        DB::table('device_group_memberships')->insert([
            ['device_group_id' => $group->id, 'device_id' => $labA->id, 'created_at' => now()],
            ['device_group_id' => $group->id, 'device_id' => $labB->id, 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'how all device name',
                'execute_now' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show all lab group devices IP',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'plan.target_type') === 'group'
                    && (string) data_get($result, 'plan.target_query') === 'lab'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_ips'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'ip list for group lab');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'lab-ip-list-01')
                    && str_contains($message, 'lab-ip-list-02')
                    && ! str_contains($message, 'outside-ip-list-01')
                    && ! str_contains($message, 'i found multiple devices from the last result');
            });
    }

    public function test_ai_power_health_topic_answers_are_direct_for_high_cpu_and_memory(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CPU-HOT-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 94,
                    'memory_usage_percent' => 90,
                    'disk_free_percent' => 20,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Show me all devices with high CPU usage',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_function.domain') === 'health'
                    && (string) data_get($result, 'ai_function.topic') === 'high_cpu'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'high cpu');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return str_contains($message, 'high cpu')
                    && str_contains($message, 'devices: cpu-hot-01');
            });

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which machines are running out of memory?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'ai_function.domain') === 'health'
                    && (string) data_get($result, 'ai_function.topic') === 'high_memory'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'memory');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return str_contains($message, 'memory')
                    && str_contains($message, 'devices: cpu-hot-01');
            });
    }

    public function test_ai_power_can_list_device_names_from_typo_natural_language_query(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ALPHA-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => ['runtime_diagnostics' => ['ip_address' => '172.16.43.11']],
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BETA-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'offline',
            'last_seen_at' => now()->subHour(),
            'tags' => ['runtime_diagnostics' => ['ip_address' => '172.16.43.12']],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'how all device name',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'reporting'
                    && (string) data_get($result, 'ai_function.topic') === 'device_list'
                    && count((array) data_get($result, 'ai_function.items', [])) >= 2;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'devices found')
                    && str_contains($message, 'devices: alpha-01')
                    && str_contains($message, 'beta-01');
            });
    }

    public function test_ai_power_affirmative_yes_uses_last_result_details_not_unknown(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show all functions and all values in this project',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'project_inventory';
            });

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'yes',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }

                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'here are the full details from the last ai result')
                    && ! str_contains($message, 'i did not fully understand');
            });
    }

    public function test_ai_power_can_answer_security_queries_with_ai_function_system(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'SEC-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'user_logon',
            'occurred_at' => now()->subHour(),
            'user_name' => 'student01',
            'process_name' => 'winlogon.exe',
            'file_path' => null,
            'metadata' => [
                'status' => 'failed',
                'message' => 'invalid password',
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'show me devices with suspicious activity',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'security'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'failed');
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_usb_storage_query_returns_direct_usb_activity_devices(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'USB-ACT-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'file_access',
            'occurred_at' => now()->subMinutes(10),
            'user_name' => 'student01',
            'process_name' => 'explorer.exe',
            'file_path' => 'E:\\Removable\\notes.txt',
            'metadata' => [
                'message' => 'usb removable storage mounted',
                'status' => 'success',
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices are using USB storage',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'security'
                    && (string) data_get($result, 'ai_function.topic') === 'usb_activity'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'usb');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'usb')
                    && str_contains($message, 'usb-act-01')
                    && ! str_contains($message, 'security scan complete');
            });
    }

    public function test_ai_power_unusual_login_times_query_returns_off_hours_devices(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'OFFHOUR-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'user_logon',
            'occurred_at' => now()->subDay()->setTime(2, 15),
            'user_name' => 'night.user',
            'process_name' => 'winlogon.exe',
            'file_path' => null,
            'metadata' => ['status' => 'success', 'message' => 'logon success'],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Show unusual login times',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'user'
                    && (string) data_get($result, 'ai_function.topic') === 'off_hours'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'unusual');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'off-hours')
                    && str_contains($message, 'offhour-01')
                    && ! str_contains($message, 'current posture');
            });
    }

    public function test_ai_power_multiple_failed_logins_query_reports_devices_with_repeated_failures(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $deviceA = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LOGIN-FAIL-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $deviceB = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LOGIN-FAIL-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $deviceA->id,
            'event_type' => 'user_logon',
            'occurred_at' => now()->subMinutes(5),
            'user_name' => 'student01',
            'process_name' => 'winlogon.exe',
            'file_path' => null,
            'metadata' => ['status' => 'failed', 'message' => 'invalid password'],
        ]);
        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $deviceA->id,
            'event_type' => 'user_logon',
            'occurred_at' => now()->subMinutes(3),
            'user_name' => 'student01',
            'process_name' => 'winlogon.exe',
            'file_path' => null,
            'metadata' => ['outcome' => 'failure', 'message' => 'account lockout'],
        ]);
        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $deviceB->id,
            'event_type' => 'user_logon',
            'occurred_at' => now()->subMinutes(1),
            'user_name' => 'student02',
            'process_name' => 'winlogon.exe',
            'file_path' => null,
            'metadata' => ['status' => 'failed', 'message' => 'invalid password'],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Which devices had multiple failed logins?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'security'
                    && (string) data_get($result, 'ai_function.topic') === 'failed_logins'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'multiple failed logins');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'multiple failed logins')
                    && str_contains($message, 'login-fail-01')
                    && ! str_contains($message, 'current posture');
            });
    }

    public function test_ai_power_admin_account_created_query_returns_direct_security_answer(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ADMIN-CREATE-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'user_account_created',
            'occurred_at' => now()->subMinutes(7),
            'user_name' => 'helpdesk01',
            'process_name' => 'net.exe',
            'file_path' => null,
            'metadata' => [
                'action' => 'create',
                'group' => 'Administrators',
                'account_type' => 'admin',
                'created_user' => 'local.admin',
                'message' => 'New admin account created',
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Has any new admin account been created?',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'security'
                    && (string) data_get($result, 'ai_function.topic') === 'admin_account_created'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'admin account');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'admin account')
                    && str_contains($message, 'admin-create-01')
                    && ! str_contains($message, 'security scan complete');
            });
    }

    public function test_ai_power_antivirus_disabled_query_returns_disabled_devices_not_generic_summary(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'AV-ON-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'antivirus_enabled' => true,
                    'firewall_enabled' => true,
                    'bitlocker_enabled' => true,
                ],
            ],
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'AV-OFF-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [
                'runtime_diagnostics' => [
                    'antivirus_enabled' => false,
                    'firewall_enabled' => true,
                    'bitlocker_enabled' => true,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Show devices with antivirus disabled',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'security'
                    && (string) data_get($result, 'ai_function.topic') === 'antivirus_disabled'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'antivirus');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'antivirus disabled')
                    && str_contains($message, 'av-off-01')
                    && ! str_contains($message, 'security scan complete');
            });
    }

    public function test_ai_power_non_compliant_follow_up_phrase_routes_to_compliance_and_lists_devices(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $bad = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'NONCOMP-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $good = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'NONCOMP-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        ComplianceResult::query()->create([
            'id' => (string) Str::uuid(),
            'compliance_check_id' => (string) Str::uuid(),
            'device_id' => $bad->id,
            'status' => 'non_compliant',
            'details' => ['source' => 'test'],
            'checked_at' => now(),
        ]);
        ComplianceResult::query()->create([
            'id' => (string) Str::uuid(),
            'compliance_check_id' => (string) Str::uuid(),
            'device_id' => $good->id,
            'status' => 'compliant',
            'details' => ['source' => 'test'],
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'how 1 non-compliant devices',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'compliance'
                    && (string) data_get($result, 'ai_function.topic') === 'status'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'non-compliant');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'non-compliant')
                    && str_contains($message, 'noncomp-01')
                    && ! str_contains($message, 'current posture');
            });
    }

    public function test_ai_power_anomaly_phrase_behaving_differently_routes_to_anomaly_not_overview(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ANOM-QUERY-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Show me devices behaving differently from others',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'anomaly'
                    && (string) data_get($result, 'ai_function.topic') === 'risk'
                    && str_contains(mb_strtolower((string) data_get($result, 'ai_function.summary', '')), 'anomal');
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && str_contains($message, 'anomal')
                    && ! str_contains($message, 'current posture');
            });

        $this->assertSame(0, DmsJob::query()->count());
    }

    public function test_ai_power_incident_query_with_device_suffix_resolves_target_and_returns_root_cause(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        $user = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'DESKTOP-S3DNC2H',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'What caused this anomaly DESKTOP-S3DNC2H',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('ai_power_result', function ($result) use ($device) {
                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'ai_query'
                    && (string) data_get($result, 'ai_function.domain') === 'incident'
                    && (string) data_get($result, 'ai_function.topic') === 'root_cause'
                    && (string) data_get($result, 'ai_function.context.target.scope') === 'device'
                    && (string) data_get($result, 'ai_function.context.target.device.id') === $device->id
                    && (bool) data_get($result, 'ai_function.needs_clarification', true) === false;
            })
            ->assertSessionHas('ai_power_chat', function ($chat) {
                if (! is_array($chat) || count($chat) < 2) {
                    return false;
                }
                $last = $chat[count($chat) - 1] ?? [];
                $message = mb_strtolower((string) ($last['message'] ?? ''));

                return (string) ($last['role'] ?? '') === 'assistant'
                    && ! str_contains($message, 'please provide exact device hostname or id')
                    && str_contains($message, 'root cause');
            });
    }

    public function test_fallback_interpreter_handles_extended_query_and_action_catalog(): void
    {
        config()->set('services.openai.api_key', '');
        config()->set('services.openai.ai_power_enabled', false);

        /** @var NaturalLanguageCommandService $interpreter */
        $interpreter = app(NaturalLanguageCommandService::class);

        $cases = [
            ['Which devices are unhealthy right now?', 'ai_query'],
            ['Show me all devices with high CPU usage', 'ai_query'],
            ['Which machines are running out of memory?', 'ai_query'],
            ['Show devices with low disk space', 'ai_query'],
            ['Which devices have not checked in today?', 'ai_query'],
            ['Which devices have not checked in 1 min ago?', 'ai_query'],
            ['how many devices is in the lab?', 'ai_query'],
            ['in lab group?', 'ai_query'],
            ['Which systems need a restart?', 'ai_query'],
            ['Show me devices with frequent crashes', 'ai_query'],
            ['Which devices are overheating?', 'ai_query'],
            ['Are there any abnormal devices right now?', 'ai_query'],
            ['Show me devices behaving differently from others', 'ai_query'],
            ['Show me anomalies in the last 24 hours', 'ai_query'],
            ['What caused this anomaly?', 'ai_query'],
            ['What caused this anomaly DESKTOP-S3DNC2H', 'ai_query'],
            ['Show me devices with suspicious activity', 'ai_query'],
            ['Which devices had multiple failed logins?', 'ai_query'],
            ['Has any new admin account been created?', 'ai_query'],
            ['Show devices with antivirus disabled', 'ai_query'],
            ['Detect possible malware behavior', 'ai_query'],
            ['Which devices are using USB storage?', 'ai_query'],
            ['Which devices are not compliant with security policies?', 'ai_query'],
            ['how 1 non-compliant devices', 'ai_query'],
            ['List installed software on this device', 'ai_query'],
            ['Which devices have outdated software?', 'ai_query'],
            ['Show recently installed software', 'ai_query'],
            ['Which devices are missing updates?', 'ai_query'],
            ['Show failed Windows updates', 'ai_query'],
            ['Which systems need critical patches?', 'ai_query'],
            ['Show patch compliance status', 'ai_query'],
            ['Which devices are offline?', 'ai_query'],
            ['Show devices with DNS problems', 'ai_query'],
            ['Which devices changed IP address?', 'ai_query'],
            ['Show devices with high network usage', 'ai_query'],
            ['Show login history for this device', 'ai_query'],
            ['Which users logged in outside working hours?', 'ai_query'],
            ['Show inactive users', 'ai_query'],
            ['Which devices are shared by multiple users?', 'ai_query'],
            ['Show policy violations', 'ai_query'],
            ['Check BitLocker status across devices', 'ai_query'],
            ['Compare policy across departments', 'ai_query'],
            ['What is the root cause of this failure?', 'ai_query'],
            ['Show timeline of events for this device', 'ai_query'],
            ['Recommend next steps', 'ai_query'],
            ['Which issues are critical?', 'ai_query'],
            ['Generate a daily system report', 'ai_query'],
            ['Create executive summary', 'ai_query'],
            ['Predict which devices will fail soon', 'ai_query'],
            ['Detect hidden problems before they happen', 'ai_query'],
            ['Are we safe right now?', 'ai_query'],
            ['Restart this device LAB-01', 'reboot_device'],
            ['Restart all devices in this group Labs', 'reboot_device'],
            ['Shut down inactive machines on all devices', 'run_command_device'],
            ['Disable firewall on device LAB-01', 'run_command_device'],
            ['Run a diagnostic on device LAB-01', 'run_command_device'],
            ['Restart the print service on device LAB-01', 'run_command_device'],
            ['Clear temp files on all devices', 'run_command_device'],
            ['Install Chrome on all lab computers', 'run_command_device'],
            ['Update all outdated applications on all devices', 'run_command_device'],
            ['Uninstall agent on device LAB-01', 'uninstall_agent_device'],
            ['Remove 7-Zip 24.09 (x64) software from all devices', 'run_command_device'],
            ['Apply policy "Nightly GPUpdate" to device LAB-01', 'apply_policy'],
            ['Create policy "Nightly GPUpdate" command "gpupdate /force" and apply to group Labs', 'create_policy'],
            ['What is the status of LAB-01', 'get_device_status'],
            ['What is the ip of LAB-01', 'get_device_status'],
            ['how all device name', 'ai_query'],
            ['how all active device IP', 'ai_query'],
            ['show all lab group devices IP', 'ai_query'],
            ['which device has IP 172.16.43.163', 'get_device_status'],
            ['find IP 172.16.43.16', 'get_device_status'],
            ['show all BIM-VICTOR network ip', 'get_device_status'],
            ['show BIM-VICTOR network interface', 'get_device_status'],
        ];

        foreach ($cases as [$prompt, $expectedIntent]) {
            $plan = $interpreter->interpret($prompt);
            $this->assertSame($expectedIntent, (string) ($plan['intent'] ?? 'unknown'), $prompt);
        }
    }

    public function test_interpreter_prefers_fallback_for_software_uninstall_when_openai_returns_uninstall_agent(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'uninstall_agent_device',
                            'target_type' => 'group',
                            'target_query' => 'all',
                            'policy_name' => '',
                            'policy_query' => '',
                            'policy_category' => 'operations/ai-power',
                            'policy_command' => '',
                            'script' => '',
                            'run_as' => 'system',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.87,
                            'rationale' => 'Uninstall request across all devices.',
                            'command_slug' => 'uninstall_agent',
                            'risk_level' => 'high',
                            'requires_approval' => true,
                            'rollback_command' => '',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        /** @var NaturalLanguageCommandService $interpreter */
        $interpreter = app(NaturalLanguageCommandService::class);

        $plan = $interpreter->interpret('Remove 7-Zip 24.09 (x64) software from all devices');

        $this->assertSame('run_command_device', (string) ($plan['intent'] ?? 'unknown'));
        $this->assertSame('group', (string) ($plan['target_type'] ?? ''));
        $this->assertSame('all', (string) ($plan['target_query'] ?? ''));
        $this->assertSame('uninstall_software', (string) ($plan['command_slug'] ?? ''));
        $this->assertStringContainsString('winget uninstall --name', (string) ($plan['script'] ?? ''));
        $this->assertSame('fallback', (string) ($plan['source'] ?? ''));
    }

    public function test_ai_power_software_uninstall_confirmation_does_not_request_agent_uninstall_phrase(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.ai_power_enabled', true);
        config()->set('services.openai.ai_power_model', 'test-model');
        config()->set('services.openai.ai_power_timeout_seconds', 10);
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'uninstall_agent_device',
                            'target_type' => 'group',
                            'target_query' => 'all',
                            'policy_name' => '',
                            'policy_query' => '',
                            'policy_category' => 'operations/ai-power',
                            'policy_command' => '',
                            'script' => '',
                            'run_as' => 'system',
                            'timeout_seconds' => 300,
                            'priority' => 100,
                            'confidence' => 0.90,
                            'rationale' => 'Uninstall request on all devices.',
                            'command_slug' => 'uninstall_agent',
                            'risk_level' => 'high',
                            'requires_approval' => false,
                            'rollback_command' => '',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-UNINS-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-UNINS-02',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'LAB-UNINS-03',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.ai-power.execute'), [
                'instruction' => 'Remove 7-Zip 24.09 (x64) software from all devices',
                'execute_now' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('ai_power_result', function ($result) {
                $confirmationPhrase = mb_strtolower((string) data_get($result, 'confirmation_required.confirmation_phrase', ''));

                return is_array($result)
                    && (string) data_get($result, 'plan.intent') === 'run_command_device'
                    && $confirmationPhrase !== ''
                    && ! str_contains($confirmationPhrase, 'uninstall agent')
                    && str_contains($confirmationPhrase, 'confirm run command on all devices');
            });
    }
}
