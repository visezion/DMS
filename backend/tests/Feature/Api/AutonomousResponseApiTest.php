<?php

namespace Tests\Feature\Api;

use App\Domain\Autonomy\AutonomousResponseEngine;
use App\Models\AutonomousResponsePolicy;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\Permission;
use App\Models\RemediationPlan;
use App\Models\RiskActionMapping;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutonomousResponseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_downgrades_blocked_action_to_recommend_only(): void
    {
        $device = $this->createDevice();
        $this->seedMapping('malware_detected', 'isolate_device');
        AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => 'Block Isolation',
            'scope_type' => 'global',
            'scope_id' => null,
            'trigger_type' => 'malware_detected',
            'allowed_actions' => ['isolate_device'],
            'blocked_actions' => ['isolate_device'],
            'autonomy_mode' => 'auto_execute',
            'minimum_confidence' => 10,
            'enabled' => true,
        ]);

        $decision = app(AutonomousResponseEngine::class)->evaluate([
            'trigger_source' => 'manual',
            'trigger_type' => 'malware_detected',
            'device_id' => $device->id,
            'severity' => 'high',
            'risk_score' => 90,
        ]);

        $this->assertSame('recommend_only', $decision->decision_mode);
        $this->assertSame('generated', $decision->status);
        $this->assertStringContainsString('blocked', strtolower((string) $decision->failure_reason));
    }

    public function test_engine_uses_auto_execute_when_safe_action_is_allowed(): void
    {
        $device = $this->createDevice();
        $this->seedMapping('cpu_anomaly_high', 'run_diagnostic_script');
        AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => 'Auto Diagnostics',
            'scope_type' => 'global',
            'scope_id' => null,
            'trigger_type' => 'cpu_anomaly_high',
            'allowed_actions' => ['run_diagnostic_script'],
            'blocked_actions' => [],
            'autonomy_mode' => 'auto_execute',
            'minimum_confidence' => 10,
            'enabled' => true,
        ]);

        $decision = app(AutonomousResponseEngine::class)->evaluate([
            'trigger_source' => 'manual',
            'trigger_type' => 'cpu_anomaly_high',
            'device_id' => $device->id,
            'severity' => 'medium',
            'risk_score' => 70,
        ]);

        $this->assertSame('auto_execute', $decision->decision_mode);
        $this->assertSame('executing', $decision->status);
        $this->assertSame('run_diagnostic_script', $decision->recommended_action);
    }

    public function test_kill_switch_prevents_auto_execution(): void
    {
        $device = $this->createDevice();
        $this->seedMapping('policy_drift_detected', 'reapply_policy');
        AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => 'Auto Policy Recovery',
            'scope_type' => 'global',
            'scope_id' => null,
            'trigger_type' => 'policy_drift_detected',
            'allowed_actions' => ['reapply_policy'],
            'blocked_actions' => [],
            'autonomy_mode' => 'auto_execute',
            'minimum_confidence' => 10,
            'enabled' => true,
        ]);
        ControlPlaneSetting::query()->create([
            'key' => 'jobs.kill_switch',
            'value' => ['value' => true],
        ]);

        $decision = app(AutonomousResponseEngine::class)->evaluate([
            'trigger_source' => 'manual',
            'trigger_type' => 'policy_drift_detected',
            'device_id' => $device->id,
            'severity' => 'high',
            'risk_score' => 85,
        ]);

        $this->assertSame('recommend_only', $decision->decision_mode);
        $this->assertStringContainsString('kill switch', strtolower((string) $decision->failure_reason));
    }

    public function test_simulation_endpoint_returns_preview_without_persisting_decision(): void
    {
        $user = $this->createUserWithPermissions(['autonomous.manage']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/admin/autonomous-response/simulate', [
            'trigger_source' => 'manual_simulation',
            'trigger_type' => 'suspicious_login',
            'severity' => 'high',
            'risk_score' => 82,
        ]);

        $response->assertOk()->assertJsonStructure([
            'trigger_source',
            'recommended_action',
            'decision_mode',
            'status',
        ]);

        $this->assertDatabaseCount('autonomous_decisions', 0);
    }

    public function test_authorization_is_required_for_evaluation_endpoint(): void
    {
        $user = $this->createUserWithPermissions(['autonomous.read']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/admin/autonomous-response/evaluate', [
            'trigger_source' => 'manual',
            'trigger_type' => 'suspicious_login',
        ])->assertForbidden();
    }

    public function test_execute_endpoint_converts_decision_into_remediation_plan(): void
    {
        $device = $this->createDevice();
        $this->seedMapping('cpu_anomaly_high', 'run_diagnostic_script');
        AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => 'Approval Diagnostics',
            'scope_type' => 'global',
            'scope_id' => null,
            'trigger_type' => 'cpu_anomaly_high',
            'allowed_actions' => ['run_diagnostic_script'],
            'blocked_actions' => [],
            'autonomy_mode' => 'approval_required',
            'minimum_confidence' => 10,
            'enabled' => true,
        ]);

        $decision = app(AutonomousResponseEngine::class)->evaluate([
            'trigger_source' => 'manual',
            'trigger_type' => 'cpu_anomaly_high',
            'device_id' => $device->id,
            'severity' => 'high',
            'risk_score' => 80,
        ]);

        $user = $this->createUserWithPermissions(['autonomous.execute']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/admin/autonomous-response/decisions/'.$decision->id.'/execute', [
            'queued' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('remediation_plans', [
            'source_type' => 'autonomous_decision',
            'source_id' => $decision->id,
        ]);
        $this->assertGreaterThan(0, RemediationPlan::query()->count());
    }

    private function createDevice(): Device
    {
        return Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'AUTO-PC-'.Str::random(4),
            'os_name' => 'Windows 11 Pro',
            'os_version' => '24H2',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'tags' => ['criticality' => 'medium'],
        ]);
    }

    private function seedMapping(string $triggerType, string $actionKey): void
    {
        RiskActionMapping::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Map '.$triggerType,
            'trigger_type' => $triggerType,
            'minimum_risk_score' => 0,
            'candidate_actions' => [
                ['action_key' => $actionKey, 'priority' => 1],
            ],
            'enabled' => true,
            'priority' => 1,
        ]);
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Autonomous Operator',
            'email' => 'auto-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Autonomous Role',
            'slug' => 'autonomous-role-'.Str::random(6),
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
