<?php

namespace Tests\Feature\Api;

use App\Jobs\BuildDeviceIntelligenceJob;
use App\Models\RemediationAction;
use App\Models\RemediationActionResult;
use App\Models\RemediationPlan;
use App\Models\ThreatFinding;
use App\Models\Device;
use App\Models\DeviceHealthSnapshot;
use App\Models\DeviceRiskScore;
use App\Models\DeviceBehaviorLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\OperatorConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EndpointIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_device_intelligence_creates_scores_findings_and_incident(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'RISKY-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.2.3',
            'status' => 'online',
            'tags' => [
                'inventory' => [
                    'security_posture' => [
                        'defender_enabled' => false,
                        'firewall_enabled' => true,
                        'bitlocker_enabled' => false,
                    ],
                    'windows_update' => [
                        'missing_patches' => 5,
                    ],
                ],
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 92,
                    'memory_usage_percent' => 89,
                    'disk_free_percent' => 8,
                ],
            ],
        ]);

        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'failed_login',
            'occurred_at' => now()->subHours(2),
            'metadata' => [],
        ]);
        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'failed_login',
            'occurred_at' => now()->subHours(1),
            'metadata' => [],
        ]);
        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'failed_login',
            'occurred_at' => now()->subMinutes(45),
            'metadata' => [],
        ]);
        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'failed_login',
            'occurred_at' => now()->subMinutes(30),
            'metadata' => [],
        ]);
        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'failed_login',
            'occurred_at' => now()->subMinutes(10),
            'metadata' => [],
        ]);
        DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'service_failure',
            'occurred_at' => now()->subMinutes(20),
            'metadata' => [],
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $this->assertDatabaseHas('device_health_scores', [
            'device_id' => $device->id,
        ]);
        $this->assertDatabaseHas('device_risk_scores', [
            'device_id' => $device->id,
            'severity' => 'high',
        ]);
        $this->assertDatabaseHas('threat_findings', [
            'device_id' => $device->id,
            'finding_type' => 'defender_disabled',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('correlated_incidents', [
            'primary_device_id' => $device->id,
            'status' => 'open',
        ]);
    }

    public function test_assistant_and_remediation_endpoints_work_with_fallback_mode(): void
    {
        $user = $this->createUserWithPermissions([
            'assistant.use',
            'assistant.convert',
            'remediation.plan',
        ]);
        Sanctum::actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'ASSIST-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => [],
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $assistantResponse = $this->postJson('/api/v1/admin/assistant/ask', [
            'question' => 'Why is this device risky?',
            'device_id' => $device->id,
            'mode' => 'investigate',
        ]);

        $assistantResponse
            ->assertOk()
            ->assertJsonStructure([
                'conversation_id',
                'session_id',
                'investigation_id',
                'recommendation_id',
                'answer' => ['reasoning_summary'],
            ]);

        $recommendationId = (string) $assistantResponse->json('recommendation_id');

        $planResponse = $this->postJson('/api/v1/admin/assistant/recommendations/'.$recommendationId.'/convert');
        $planResponse->assertOk();

        $this->assertDatabaseHas('remediation_plans', [
            'source_id' => $recommendationId,
        ]);
    }

    public function test_assistant_chat_supports_group_and_package_scope_with_conversation_continuity(): void
    {
        $user = $this->createUserWithPermissions([
            'assistant.use',
        ]);
        Sanctum::actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'CHAT-SCOPE-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => [],
        ]);

        $groupId = (string) Str::uuid();
        DB::table('device_groups')->insert([
            'id' => $groupId,
            'name' => 'Sales Laptops',
            'description' => 'Sales team devices',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_group_memberships')->insert([
            'device_group_id' => $groupId,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $packageId = (string) Str::uuid();
        DB::table('packages')->insert([
            'id' => $packageId,
            'name' => 'Contoso VPN',
            'slug' => 'contoso-vpn',
            'publisher' => 'Contoso',
            'package_type' => 'msi',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('package_versions')->insert([
            'id' => (string) Str::uuid(),
            'package_id' => $packageId,
            'version' => '1.2.3',
            'channel' => 'stable',
            'install_args' => json_encode([]),
            'uninstall_args' => json_encode([]),
            'detection_rules' => json_encode([]),
            'is_deprecated' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $first = $this->postJson('/api/v1/admin/assistant/ask', [
            'question' => 'Check Sales Laptops and Contoso VPN health posture.',
            'group_id' => $groupId,
            'package_id' => $packageId,
            'mode' => 'investigate',
        ])->assertOk();

        $conversationId = (string) $first->json('conversation_id');
        $firstSessionId = (string) $first->json('session_id');

        $second = $this->postJson('/api/v1/admin/assistant/ask', [
            'question' => 'What should we do next?',
            'conversation_id' => $conversationId,
            'mode' => 'recommend',
        ])->assertOk();

        $second->assertJsonPath('conversation_id', $conversationId);
        $this->assertNotSame($firstSessionId, (string) $second->json('session_id'));

        $this->assertDatabaseCount('assistant_messages', 4);
    }

    public function test_assistant_can_infer_scope_from_plain_text_question_without_manual_filters(): void
    {
        $user = $this->createUserWithPermissions([
            'assistant.use',
        ]);
        Sanctum::actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'AUTO-SCOPE-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => [],
        ]);

        $groupId = (string) Str::uuid();
        DB::table('device_groups')->insert([
            'id' => $groupId,
            'name' => 'Auto Scope Group',
            'description' => 'Inference test group',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_group_memberships')->insert([
            'device_group_id' => $groupId,
            'device_id' => $device->id,
            'created_at' => now(),
        ]);

        $packageId = (string) Str::uuid();
        DB::table('packages')->insert([
            'id' => $packageId,
            'name' => 'Auto Scope VPN',
            'slug' => 'auto-scope-vpn',
            'publisher' => 'Contoso',
            'package_type' => 'msi',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $response = $this->postJson('/api/v1/admin/assistant/ask', [
            'question' => 'Investigate AUTO-SCOPE-PC-01 in Auto Scope Group and Auto Scope VPN.',
        ])->assertOk();

        $conversationId = (string) $response->json('conversation_id');
        $conversation = OperatorConversation::query()->findOrFail($conversationId);

        $this->assertSame($device->id, (string) data_get($conversation->scope, 'device_id'));
        $this->assertSame($groupId, (string) data_get($conversation->scope, 'group_id'));
        $this->assertSame($packageId, (string) data_get($conversation->scope, 'package_id'));
    }

    public function test_assistant_fallback_returns_grounded_fleet_snapshot_for_today_and_yes_follow_up(): void
    {
        config()->set('services.openai.api_key', '');

        $user = $this->createUserWithPermissions([
            'assistant.use',
        ]);
        Sanctum::actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'RISKY-TODAY-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => [],
        ]);

        DeviceRiskScore::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'score' => 92.5,
            'severity' => 'critical',
            'confidence' => 88,
            'factor_breakdown' => ['defender' => 30],
            'scored_at' => now(),
        ]);

        ThreatFinding::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'finding_type' => 'defender_disabled',
            'severity' => 'high',
            'confidence' => 0.93,
            'status' => 'open',
            'fingerprint' => 'today-risk-'.Str::random(8),
            'evidence' => ['summary' => 'Defender disabled'],
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
        ]);

        $first = $this->postJson('/api/v1/admin/assistant/ask', [
            'question' => 'what is bad today',
        ])->assertOk();

        $firstSummary = strtolower((string) $first->json('answer.reasoning_summary'));
        $this->assertStringContainsString('open findings', $firstSummary);
        $this->assertStringContainsString('risky-today-01', $firstSummary);

        $second = $this->postJson('/api/v1/admin/assistant/ask', [
            'question' => 'yes',
            'conversation_id' => (string) $first->json('conversation_id'),
        ])->assertOk();

        $secondSummary = strtolower((string) $second->json('answer.reasoning_summary'));
        $this->assertStringContainsString('open findings', $secondSummary);
    }

    public function test_health_telemetry_endpoint_returns_latest_snapshot_payload(): void
    {
        $user = $this->createUserWithPermissions([
            'health.read',
        ]);
        Sanctum::actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'TEL-API-01',
            'os_name' => 'Windows 11 Enterprise',
            'os_version' => '26100',
            'agent_version' => '1.2.0',
            'status' => 'online',
        ]);

        DeviceHealthSnapshot::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'snapshot_at' => now(),
            'metrics' => ['cpu_usage_percent' => 33],
            'raw_payload' => [
                'identity' => ['manufacturer' => 'Lenovo'],
                'telemetry_coverage' => ['windows_telemetry_present' => true],
                'windows_telemetry' => ['security_posture' => ['firewall_status' => [['Enabled' => true]]]],
            ],
        ]);

        $this->getJson('/api/v1/admin/health/devices/'.$device->id.'/telemetry')
            ->assertOk()
            ->assertJsonPath('device.id', $device->id)
            ->assertJsonPath('telemetry_coverage.windows_telemetry_present', true)
            ->assertJsonPath('snapshot.raw_payload.identity.manufacturer', 'Lenovo');
    }

    public function test_first_checkin_with_telemetry_creates_immediate_intelligence_snapshot(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'NEW-CHECKIN-PC',
            'os_name' => 'Windows 11 Enterprise',
            'os_version' => '26100',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => [],
        ]);

        $this->postJson('/api/v1/device/checkin', [
            'device_id' => $device->id,
            'agent_version' => '1.0.1',
            'checkin_id' => 'new-checkin-1',
            'hostname' => 'NEW-CHECKIN-PC',
            'os_name' => 'Windows 11 Enterprise',
            'os_version' => '26100',
            'inventory' => [
                'windows_telemetry' => [
                    'basic_device_identity' => [
                        'hostname' => 'NEW-CHECKIN-PC',
                        'manufacturer' => 'Lenovo',
                        'model' => 'T14',
                    ],
                    'system_health_and_performance' => [
                        'cpu_usage_percent' => 22,
                        'memory_usage_percent' => 41,
                    ],
                ],
            ],
            'runtime_diagnostics' => [
                'cpu_usage_percent' => 22,
                'memory_usage_percent' => 41,
            ],
        ])->assertOk();

        $snapshot = DeviceHealthSnapshot::query()
            ->where('device_id', $device->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('NEW-CHECKIN-PC', data_get($snapshot?->raw_payload, 'identity.hostname'));
        $this->assertTrue((bool) data_get($snapshot?->raw_payload, 'telemetry_coverage.windows_telemetry_present'));
    }

    public function test_replay_keeps_findings_deduplicated_for_same_device_signal(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'REPLAY-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => [
                'inventory' => [
                    'security_posture' => ['defender_enabled' => false],
                ],
            ],
        ]);

        for ($i = 0; $i < 6; $i++) {
            DeviceBehaviorLog::query()->create([
                'id' => (string) Str::uuid(),
                'device_id' => $device->id,
                'event_type' => 'failed_login',
                'occurred_at' => now()->subMinutes(10 + $i),
                'metadata' => [],
            ]);
        }

        BuildDeviceIntelligenceJob::dispatchSync($device->id);
        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $this->assertSame(1, ThreatFinding::query()->where('device_id', $device->id)->where('fingerprint', 'failed_login_burst')->where('status', 'open')->count());
    }

    public function test_checkin_archives_full_telemetry_and_behavior_upload_deduplicates_by_event_uid(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'FULL-TELEMETRY-PC',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.9.0',
            'status' => 'online',
            'tags' => [],
        ]);

        $inventory = [
            'windows_telemetry' => [
                'basic_device_identity' => [
                    'hostname' => 'FULL-TELEMETRY-PC',
                    'serial_number' => 'SN-100',
                    'manufacturer' => 'Dell',
                    'model' => 'Latitude 5550',
                    'windows_edition' => 'Windows 11 Enterprise',
                    'windows_build_number' => '26100',
                    'bios_uefi_version' => '1.2.3',
                    'domain_joined' => true,
                    'azure_ad_joined' => true,
                    'physical_location' => 'HQ-1',
                ],
                'system_health_and_performance' => [
                    'cpu_usage_percent' => 44,
                    'memory_usage_percent' => 61,
                    'uptime_seconds' => 7200,
                    'disk_space_per_drive' => [
                        ['drive' => 'C:', 'total_bytes' => 1000, 'free_bytes' => 250, 'used_percent' => 75],
                    ],
                    'frequent_crashes_24h' => 0,
                    'service_failures_24h' => 1,
                ],
                'security_posture' => [
                    'microsoft_defender_status' => [
                        'AntivirusEnabled' => true,
                        'RealTimeProtectionEnabled' => true,
                    ],
                    'firewall_status' => [
                        ['Name' => 'Domain', 'Enabled' => true],
                    ],
                    'bitlocker_encryption_status' => [
                        ['MountPoint' => 'C:', 'ProtectionStatus' => 1],
                    ],
                    'windows_update_status' => [
                        'missing_patches' => ['count' => 2],
                    ],
                    'local_admin_accounts' => [
                        ['Name' => 'Administrator'],
                    ],
                ],
                'authentication_and_user_activity' => [
                    'login_events' => [
                        'successful_logins_24h' => 3,
                        'failed_logins_24h' => 1,
                    ],
                ],
                'network_telemetry' => [
                    'bytes_sent_received' => [
                        ['SentBytes' => 100, 'ReceivedBytes' => 300],
                    ],
                    'frequent_outbound_destinations' => [
                        ['remote_ip' => '8.8.8.8', 'connection_count' => 2],
                    ],
                ],
                'configuration_and_policy_state' => [
                    'dns_configuration' => [
                        ['ServerAddresses' => ['1.1.1.1', '8.8.8.8']],
                    ],
                ],
                'smart_operational_data' => [
                    'app_crash_frequency_7d' => 0,
                    'repeated_reboot_issues_7d' => 0,
                ],
            ],
            'installed_software' => [
                ['name' => 'Office'],
                ['name' => 'Chrome'],
            ],
        ];

        $this->postJson('/api/v1/device/checkin', [
            'device_id' => $device->id,
            'agent_version' => '1.9.0',
            'agent_build' => 'test-build',
            'checkin_id' => 'checkin-1',
            'hostname' => 'FULL-TELEMETRY-PC',
            'os_name' => 'Windows 11 Enterprise',
            'os_version' => '26100',
            'serial_number' => 'SN-100',
            'inventory' => $inventory,
            'runtime_diagnostics' => [
                'cpu_usage_percent' => 44,
                'memory_usage_percent' => 61,
                'uptime_seconds' => 7200,
            ],
            'uwf_status' => [
                'enabled' => false,
            ],
        ])->assertOk();

        $this->postJson('/api/v1/device/behavior-log', [
            'device_id' => $device->id,
            'events' => [
                [
                    'event_type' => 'app_launch',
                    'occurred_at' => now()->toIso8601String(),
                    'user_name' => 'alice',
                    'process_name' => 'powershell.exe',
                    'event_uid' => 'event-1',
                    'session_uid' => 'session-1',
                    'process_uid' => 'pid:101',
                    'parent_process_uid' => 'pid:1',
                    'checkin_id' => 'checkin-1',
                    'metadata' => ['command_line' => 'powershell -nop'],
                ],
                [
                    'event_type' => 'app_launch',
                    'occurred_at' => now()->toIso8601String(),
                    'user_name' => 'alice',
                    'process_name' => 'powershell.exe',
                    'event_uid' => 'event-1',
                    'session_uid' => 'session-1',
                    'process_uid' => 'pid:101',
                    'parent_process_uid' => 'pid:1',
                    'checkin_id' => 'checkin-1',
                    'metadata' => ['command_line' => 'powershell -nop'],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, DeviceBehaviorLog::query()->where('device_id', $device->id)->where('event_uid', 'event-1')->count());

        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $snapshots = DeviceHealthSnapshot::query()
            ->where('device_id', $device->id)
            ->get();

        $snapshot = $snapshots->first(fn (DeviceHealthSnapshot $row) => (int) data_get($row->raw_payload, 'behavior_summary.recent_event_count', 0) === 1)
            ?? $snapshots->sortByDesc('created_at')->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('Dell', data_get($snapshot?->raw_payload, 'identity.manufacturer'));
        $this->assertSame('Latitude 5550', data_get($snapshot?->raw_payload, 'identity.model'));
        $this->assertSame('HQ-1', data_get($snapshot?->raw_payload, 'identity.physical_location'));
        $this->assertSame(44.0, (float) data_get($snapshot?->metrics, 'cpu_usage_percent'));
        $this->assertSame(2, (int) data_get($snapshot?->metrics, 'patch_gap_count'));
        $this->assertSame(1, (int) data_get($snapshot?->raw_payload, 'behavior_summary.recent_event_count'));
        $this->assertTrue((bool) data_get($snapshot?->raw_payload, 'telemetry_coverage.windows_telemetry_present'));
    }

    public function test_behavior_log_requires_authentication_and_accepts_recent_checkin_fallback(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BEHAVIOR-AUTH-PC',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '2.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'tags' => [],
        ]);

        $this->postJson('/api/v1/device/behavior-log', [
            'device_id' => $device->id,
            'events' => [
                [
                    'event_type' => 'app_launch',
                    'occurred_at' => now()->toIso8601String(),
                    'process_name' => 'cmd.exe',
                ],
            ],
        ])->assertStatus(401);

        $device->update([
            'tags' => ['last_checkin_id' => 'checkin-auth-1'],
            'last_seen_at' => now(),
        ]);

        $this->postJson('/api/v1/device/behavior-log', [
            'device_id' => $device->id,
            'events' => [
                [
                    'event_type' => 'app_launch',
                    'occurred_at' => now()->toIso8601String(),
                    'process_name' => 'cmd.exe',
                    'checkin_id' => 'checkin-auth-1',
                ],
            ],
        ])->assertOk()->assertJsonPath('auth_mode', 'checkin_fallback');
    }

    public function test_findings_keep_original_first_seen_and_stale_findings_are_resolved(): void
    {
        config()->set('services.endpoint_intelligence.finding_stale_minutes', 1);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'FINDING-LIFECYCLE-PC',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '2.1.0',
            'status' => 'online',
            'tags' => [
                'inventory' => [
                    'security_posture' => [
                        'defender_enabled' => false,
                    ],
                ],
            ],
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);
        $initial = ThreatFinding::query()
            ->where('device_id', $device->id)
            ->where('fingerprint', 'defender_disabled')
            ->whereIn('status', ['open', 'investigating'])
            ->first();

        $this->assertNotNull($initial);
        $firstSeenAt = optional($initial->first_seen_at)?->toIso8601String();

        BuildDeviceIntelligenceJob::dispatchSync($device->id);
        $replayed = ThreatFinding::query()
            ->where('device_id', $device->id)
            ->where('fingerprint', 'defender_disabled')
            ->whereIn('status', ['open', 'investigating'])
            ->first();

        $this->assertNotNull($replayed);
        $this->assertSame($firstSeenAt, optional($replayed->first_seen_at)?->toIso8601String());

        $replayed->update(['last_seen_at' => now()->subMinutes(5)]);
        $device->update([
            'tags' => [
                'inventory' => [
                    'security_posture' => [
                        'microsoft_defender_status' => [
                            'AntivirusEnabled' => true,
                            'RealTimeProtectionEnabled' => true,
                        ],
                        'firewall_status' => [
                            ['Enabled' => true],
                        ],
                        'bitlocker_encryption_status' => [
                            ['ProtectionStatus' => 1],
                        ],
                    ],
                    'windows_update' => [
                        'missing_patches' => 0,
                    ],
                ],
            ],
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);
        $this->assertDatabaseHas('threat_findings', [
            'id' => $replayed->id,
            'status' => 'resolved',
        ]);
    }

    public function test_build_device_intelligence_marks_extended_windows_telemetry_failed_when_collector_reports_error(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'TIMEOUT-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.9.1',
            'status' => 'online',
            'tags' => [
                'inventory' => [
                    'network' => [
                        'ip_addresses' => ['192.168.1.10'],
                    ],
                    'running_processes' => [
                        ['name' => 'explorer.exe', 'pid' => 100],
                    ],
                    'installed_software' => [
                        ['name' => 'Office'],
                    ],
                    'logged_in_sessions' => [
                        ['username' => 'administrator', 'state' => 'Active'],
                    ],
                    'windows_telemetry' => [
                        'supported' => true,
                        'collection_error' => 'telemetry_timeout_or_cancelled',
                        'collector' => 'windows_extended_telemetry',
                        'collector_version' => '2026-03-23.1',
                    ],
                ],
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 12,
                    'memory_usage_percent' => 34,
                ],
            ],
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $snapshot = DeviceHealthSnapshot::query()
            ->where('device_id', $device->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertFalse((bool) data_get($snapshot?->raw_payload, 'telemetry_coverage.windows_telemetry_present'));
        $this->assertTrue((bool) data_get($snapshot?->raw_payload, 'telemetry_coverage.network_telemetry_present'));
        $this->assertSame('telemetry_timeout_or_cancelled', data_get($snapshot?->raw_payload, 'windows_telemetry_meta.collection_error'));
    }

    public function test_inventory_identity_and_process_fallbacks_are_preserved_when_extended_telemetry_times_out(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'FALLBACK-ID-PC',
            'os_name' => 'Windows',
            'os_version' => 'unknown',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'tags' => [
                'device_identity' => [
                    'serial_number' => 'SN-FALLBACK-1',
                    'manufacturer' => 'HP',
                    'model' => 'ProBook 450 G8',
                    'windows_edition' => 'Windows 11 Education',
                    'windows_build_number' => '22000',
                    'bios_uefi_version' => 'T70 01.22.00',
                    'domain_joined' => false,
                    'azure_ad_joined' => false,
                ],
                'inventory' => [
                    'device_identity' => [
                        'serial_number' => 'SN-FALLBACK-1',
                        'manufacturer' => 'HP',
                        'model' => 'ProBook 450 G8',
                        'windows_edition' => 'Windows 11 Education',
                        'windows_build_number' => '22000',
                        'bios_uefi_version' => 'T70 01.22.00',
                    ],
                    'memory' => [
                        'total_bytes' => 1000,
                        'available_bytes' => 250,
                    ],
                    'running_processes' => [
                        ['name' => 'explorer.exe', 'pid' => 100],
                        ['name' => 'powershell.exe', 'pid' => 101],
                    ],
                    'installed_software' => [
                        ['name' => 'Office'],
                        ['name' => 'Chrome'],
                    ],
                    'services' => [
                        ['name' => 'WinDefend', 'state' => 'RUNNING'],
                        ['name' => 'mpssvc', 'state' => 'RUNNING'],
                    ],
                    'windows_telemetry' => [
                        'supported' => true,
                        'collection_error' => 'telemetry_timeout_or_cancelled',
                        'collector' => 'windows_extended_telemetry',
                        'collector_version' => '2026-03-23.2',
                    ],
                ],
                'runtime_diagnostics' => [
                    'uptime_seconds' => 3600,
                ],
            ],
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);

        $snapshot = DeviceHealthSnapshot::query()
            ->where('device_id', $device->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('HP', data_get($snapshot?->raw_payload, 'identity.manufacturer'));
        $this->assertSame('ProBook 450 G8', data_get($snapshot?->raw_payload, 'identity.model'));
        $this->assertSame('Windows 11 Education', data_get($snapshot?->raw_payload, 'identity.windows_edition'));
        $this->assertSame(2, (int) data_get($snapshot?->metrics, 'running_process_count'));
        $this->assertSame(2, (int) data_get($snapshot?->metrics, 'installed_software_count'));
        $this->assertSame(75.0, (float) data_get($snapshot?->metrics, 'memory_usage_percent'));
        $this->assertFalse((bool) data_get($snapshot?->raw_payload, 'telemetry_coverage.windows_telemetry_present'));
    }

    public function test_validate_plan_supports_simulation_style_readiness_check_without_dispatch(): void
    {
        $user = $this->createUserWithPermissions([
            'assistant.use',
            'assistant.convert',
            'remediation.plan',
        ]);
        Sanctum::actingAs($user);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'SIM-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => [],
        ]);

        BuildDeviceIntelligenceJob::dispatchSync($device->id);
        $assistantResponse = $this->postJson('/api/v1/admin/assistant/ask', [
            'question' => 'Recommend a safe next action.',
            'device_id' => $device->id,
            'mode' => 'recommend',
        ])->assertOk();

        $recommendationId = (string) $assistantResponse->json('recommendation_id');
        $plan = $this->postJson('/api/v1/admin/assistant/recommendations/'.$recommendationId.'/convert')
            ->assertOk()
            ->json('id');

        $this->postJson('/api/v1/admin/remediation/plans/'.$plan.'/validate')
            ->assertOk()
            ->assertJsonPath('ready', true);

        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_approval_sla_sweep_expires_old_pending_requests(): void
    {
        \App\Models\ApprovalRequest::query()->create([
            'id' => (string) Str::uuid(),
            'request_type' => 'remediation_plan',
            'request_ref_id' => (string) Str::uuid(),
            'risk_level' => 'high',
            'reason' => 'Expired approval check',
            'required_role' => 'remediation.approve',
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('dms:approvals:sweep')->assertExitCode(0);

        $this->assertDatabaseHas('approval_requests', [
            'status' => 'expired',
        ]);
    }

    public function test_rollback_endpoint_dispatches_rollback_job_when_hint_is_available(): void
    {
        $user = $this->createUserWithPermissions([
            'remediation.execute',
        ]);
        Sanctum::actingAs($user);

        $plan = RemediationPlan::query()->create([
            'id' => (string) Str::uuid(),
            'source_type' => 'manual',
            'source_id' => (string) Str::uuid(),
            'risk_level' => 'medium',
            'status' => 'executing',
            'summary' => [],
        ]);

        $action = RemediationAction::query()->create([
            'id' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'action_order' => 1,
            'action_type' => 'run_approved_command',
            'target_device_id' => (string) Str::uuid(),
            'args' => [],
            'guardrail_snapshot' => [],
            'approval_required' => true,
            'status' => 'failed',
        ]);

        RemediationActionResult::query()->create([
            'id' => (string) Str::uuid(),
            'action_id' => $action->id,
            'status' => 'failed',
            'evidence' => [
                'rollback_hint' => [
                    'possible' => true,
                    'job_type' => 'run_command',
                    'payload' => ['command' => 'Write-Host rollback'],
                ],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->postJson('/api/v1/admin/remediation/actions/'.$action->id.'/rollback')
            ->assertCreated();

        $this->assertDatabaseHas('action_rollbacks', [
            'rollback_action_type' => 'run_command',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('jobs', [
            'job_type' => 'run_command',
            'target_id' => $action->target_device_id,
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
