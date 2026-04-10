<?php

namespace Tests\Feature\Web;

use App\Models\CorrelatedIncident;
use App\Models\Device;
use App\Models\DeviceHealthScore;
use App\Models\DeviceHealthSnapshot;
use App\Models\DeviceRiskScore;
use App\Models\DmsJob;
use App\Models\Permission;
use App\Models\RemediationAction;
use App\Models\RemediationActionResult;
use App\Models\RemediationPlan;
use App\Models\ApprovalRequest;
use App\Models\AssistantMessage;
use App\Models\AssistantSession;
use App\Models\OperatorConversation;
use App\Models\Role;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndpointIntelligencePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_intelligence_index_pages_render_on_empty_state(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/intelligence/health')
            ->assertOk()
            ->assertSee('Fleet Health');

        $this->actingAs($user)
            ->get('/admin/intelligence/risk')
            ->assertOk()
            ->assertSee('Risk');

        $this->actingAs($user)
            ->get('/admin/intelligence/incidents')
            ->assertOk()
            ->assertSee('Incidents');

        $this->actingAs($user)
            ->get('/admin/intelligence/assistant')
            ->assertOk()
            ->assertSee('Assistant');

        $this->actingAs($user)
            ->get('/admin/intelligence/remediation')
            ->assertOk()
            ->assertSee('Remediation');

        $this->actingAs($user)
            ->get('/admin/intelligence/approvals')
            ->assertOk()
            ->assertSee('Approval');

        $this->actingAs($user)
            ->get('/admin/intelligence/actions')
            ->assertOk()
            ->assertSee('Action');

        $this->actingAs($user)
            ->get('/admin/intelligence/autonomy')
            ->assertOk()
            ->assertSee('Autonomy');

        $this->actingAs($user)
            ->get('/admin/intelligence/tuning')
            ->assertOk()
            ->assertSee('Tuning');
    }

    public function test_endpoint_intelligence_detail_pages_render_with_seeded_records(): void
    {
        $user = User::factory()->create();

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'EI-PC-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);

        $snapshot = DeviceHealthSnapshot::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'snapshot_at' => now(),
            'metrics' => ['cpu' => 42],
        ]);

        DeviceHealthScore::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'snapshot_id' => $snapshot->id,
            'score' => 78.50,
            'band' => 'warning',
            'predicted_failure_risk' => 24.00,
            'component_scores' => [],
            'contributors' => [],
            'scored_at' => now(),
        ]);

        DeviceRiskScore::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'score' => 61.00,
            'severity' => 'high',
            'confidence' => 88.00,
            'factor_breakdown' => [],
            'scored_at' => now(),
        ]);

        $incident = CorrelatedIncident::query()->create([
            'id' => (string) Str::uuid(),
            'primary_device_id' => $device->id,
            'title' => 'Suspicious login burst',
            'summary' => 'Repeated failed logins followed by recovery.',
            'severity' => 'high',
            'confidence' => 82.00,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        TimelineEvent::query()->create([
            'id' => (string) Str::uuid(),
            'incident_id' => $incident->id,
            'device_id' => $device->id,
            'source_type' => 'behavior_log',
            'source_ref_id' => 'evt-1',
            'event_type' => 'failed_login',
            'occurred_at' => now(),
            'risk_delta' => 12.00,
            'evidence' => [],
        ]);

        $this->actingAs($user)
            ->get('/admin/intelligence/health/devices/'.$device->id)
            ->assertOk()
            ->assertSee('EI-PC-01');

        $this->actingAs($user)
            ->get('/admin/intelligence/incidents/'.$incident->id.'/timeline')
            ->assertOk()
            ->assertSee('Suspicious login burst');

        $this->actingAs($user)
            ->get('/admin/intelligence/executive/'.$device->id)
            ->assertOk()
            ->assertSee('EI-PC-01');

        $this->actingAs($user)
            ->get('/admin/intelligence/telemetry/devices/'.$device->id)
            ->assertOk()
            ->assertSee('Telemetry')
            ->assertSee('EI-PC-01');

        $this->actingAs($user)
            ->get('/admin/devices/'.$device->id)
            ->assertOk()
            ->assertSee('Telemetry Detail')
            ->assertSee(route('admin.intelligence.telemetry.device', $device->id), false);
    }

    public function test_assistant_new_chat_query_starts_a_blank_conversation(): void
    {
        $user = User::factory()->create();

        $conversation = OperatorConversation::query()->create([
            'id' => (string) Str::uuid(),
            'operator_user_id' => $user->id,
            'title' => 'Seeded conversation',
            'scope' => [],
            'status' => 'active',
            'last_message_at' => now(),
        ]);

        $session = AssistantSession::query()->create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'mode' => 'investigate',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        $messageToken = 'assistant-seeded-message-123';
        AssistantMessage::query()->create([
            'id' => (string) Str::uuid(),
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $messageToken,
            'citations' => [],
            'token_usage' => [],
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/intelligence/assistant')
            ->assertOk()
            ->assertSee($messageToken);

        $this->actingAs($user)
            ->get('/admin/intelligence/assistant?new=1')
            ->assertOk()
            ->assertDontSee($messageToken)
            ->assertSee('Ask anything about devices, groups, packages, health, risk, incidents, or remediation.');
    }

    public function test_telemetry_detail_page_shows_collector_error_banner_and_partial_coverage(): void
    {
        $user = User::factory()->create();

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'EI-PC-TIMEOUT',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.9.1',
            'status' => 'online',
        ]);

        DeviceHealthSnapshot::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'snapshot_at' => now(),
            'metrics' => [
                'cpu_usage_percent' => 10,
                'memory_usage_percent' => 20,
            ],
            'raw_payload' => [
                'identity' => [
                    'hostname' => 'EI-PC-TIMEOUT',
                    'windows_edition' => '',
                ],
                'inventory' => [
                    'network' => [
                        'ip_addresses' => ['10.0.0.5'],
                    ],
                    'running_processes' => [
                        ['name' => 'explorer.exe'],
                    ],
                    'installed_software' => [
                        ['name' => 'Office'],
                    ],
                    'logged_in_sessions' => [
                        ['username' => 'administrator'],
                    ],
                    'disks' => [
                        ['name' => 'C:\\', 'free_bytes' => 100, 'total_bytes' => 1000],
                    ],
                ],
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 10,
                    'memory_usage_percent' => 20,
                ],
                'windows_telemetry_meta' => [
                    'collection_error' => 'telemetry_timeout_or_cancelled',
                ],
                'windows_telemetry' => [
                    'system_health_and_performance' => [],
                    'windows_event_logs' => [],
                    'process_and_application_activity' => [],
                    'security_posture' => [],
                    'authentication_and_user_activity' => [],
                    'file_and_storage_activity' => [],
                    'network_telemetry' => [],
                    'configuration_and_policy_state' => [],
                    'smart_operational_data' => [],
                ],
                'behavior_summary' => [
                    'recent_event_count' => 0,
                ],
                'telemetry_coverage' => [
                    'windows_telemetry_present' => false,
                    'network_telemetry_present' => true,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get('/admin/intelligence/telemetry/devices/'.$device->id)
            ->assertOk()
            ->assertSee('Collector Metadata')
            ->assertSee('telemetry_timeout_or_cancelled')
            ->assertSee('Raw Snapshot');
    }

    public function test_telemetry_detail_page_builds_live_preview_when_snapshot_is_missing(): void
    {
        $user = User::factory()->create();

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'EI-LIVE-PREVIEW-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '2.0.0',
            'status' => 'online',
            'tags' => [
                'inventory' => [
                    'windows_telemetry' => [
                        'basic_device_identity' => [
                            'hostname' => 'EI-LIVE-PREVIEW-01',
                            'manufacturer' => 'Dell',
                            'model' => 'Latitude',
                        ],
                        'system_health_and_performance' => [
                            'cpu_usage_percent' => 18,
                            'memory_usage_percent' => 39,
                        ],
                    ],
                ],
                'runtime_diagnostics' => [
                    'cpu_usage_percent' => 18,
                    'memory_usage_percent' => 39,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get('/admin/intelligence/telemetry/devices/'.$device->id)
            ->assertOk()
            ->assertSee('live telemetry preview', false)
            ->assertSee('EI-LIVE-PREVIEW-01')
            ->assertSee('Raw Snapshot');
    }

    public function test_autonomy_policy_can_be_saved_from_web_route(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'autonomy.manage', 'Autonomy Manager', 'autonomy-manager');

        $this->actingAs($user)
            ->postJson('/admin/intelligence/autonomy/policies', [
                'scope_type' => 'global',
                'autonomy_level' => 'advisory',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Autonomy policy saved.');

        $this->assertDatabaseHas('autonomy_policies', [
            'scope_type' => 'global',
            'scope_id' => 'global',
            'autonomy_level' => 'advisory',
            'active' => true,
        ]);
    }

    public function test_endpoint_intelligence_web_actions_work_for_assistant_approvals_and_remediation(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'assistant.use', 'Assistant Operator', 'assistant-operator');
        $this->grantPermission($user, 'remediation.approve', 'Remediation Approver', 'remediation-approver');
        $this->grantPermission($user, 'remediation.execute', 'Remediation Executor', 'remediation-executor');
        $this->grantPermission($user, 'remediation.plan', 'Remediation Planner', 'remediation-planner');

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'EI-WEB-01',
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);

        $plan = RemediationPlan::query()->create([
            'id' => (string) Str::uuid(),
            'source_type' => 'manual',
            'source_id' => 'web-test',
            'risk_level' => 'low',
            'status' => 'pending_approval',
        ]);

        $action = RemediationAction::query()->create([
            'id' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'action_order' => 1,
            'action_type' => 're_run_inventory',
            'target_device_id' => $device->id,
            'status' => 'pending',
        ]);

        RemediationActionResult::query()->create([
            'id' => (string) Str::uuid(),
            'action_id' => $action->id,
            'status' => 'success',
            'evidence' => [
                'rollback_hint' => [
                    'possible' => true,
                    'job_type' => 'run_command',
                    'payload' => ['command' => 'echo rollback'],
                ],
            ],
        ]);

        $approval = ApprovalRequest::query()->create([
            'id' => (string) Str::uuid(),
            'request_type' => 'remediation_plan',
            'request_ref_id' => $plan->id,
            'risk_level' => 'high',
            'status' => 'pending',
            'required_role' => 'remediation.approve',
        ]);

        $this->actingAs($user)
            ->postJson('/admin/intelligence/assistant/ask', [
                'question' => 'What is happening?',
                'device_id' => $device->id,
                'mode' => 'investigate',
            ])
            ->assertOk()
            ->assertJsonStructure(['answer' => ['reasoning_summary']]);

        $this->actingAs($user)
            ->postJson('/admin/intelligence/approvals/'.$approval->id.'/approve')
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->actingAs($user)
            ->postJson('/admin/intelligence/remediation/plans/'.$plan->id.'/validate')
            ->assertOk()
            ->assertJsonPath('ready', true);

        $this->actingAs($user)
            ->postJson('/admin/intelligence/remediation/actions/'.$action->id.'/rollback')
            ->assertCreated();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $approval->id,
            'status' => 'approved',
        ]);
    }

    private function grantPermission(User $user, string $permissionSlug, string $roleName, string $roleSlug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $permissionSlug],
            ['name' => $permissionSlug]
        );

        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['id' => (string) Str::uuid(), 'name' => $roleName]
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}
