<?php

namespace Database\Seeders;

use App\Domain\Remediation\ActionCatalog;
use App\Models\AutonomousActionDefinition;
use App\Models\AutonomousResponsePolicy;
use App\Models\ControlPlaneSetting;
use App\Models\RiskActionMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AutonomousResponseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedActionCatalog();
        $this->seedMappings();
        $this->seedPolicies();
        $this->seedControlSettings();
    }

    private function seedActionCatalog(): void
    {
        $catalog = app(ActionCatalog::class)->all();
        foreach ($catalog as $key => $definition) {
            AutonomousActionDefinition::query()->updateOrCreate(
                [
                    'tenant_id' => null,
                    'action_key' => $key,
                ],
                [
                    'id' => AutonomousActionDefinition::query()->whereNull('tenant_id')->where('action_key', $key)->value('id') ?? (string) Str::uuid(),
                    'display_name' => (string) ($definition['display_name'] ?? Str::headline(str_replace('_', ' ', $key))),
                    'description' => (string) ($definition['description'] ?? ''),
                    'supported_target_types' => $definition['supported_target_types'] ?? [],
                    'required_parameters' => $definition['required_parameters'] ?? [],
                    'safety_class' => (string) ($definition['safety_class'] ?? 'moderate'),
                    'reversible' => (bool) ($definition['reversible'] ?? false),
                    'rollback_handler' => $definition['rollback_handler'] ?? null,
                    'recommended_approval_mode' => (string) ($definition['recommended_approval_mode'] ?? 'approval_required'),
                    'cooldown_minutes' => (int) ($definition['cooldown_minutes'] ?? 15),
                    'requires_online' => (bool) ($definition['requires_online'] ?? true),
                    'supports_offline' => (bool) ($definition['supports_offline'] ?? false),
                    'tenant_compatible' => (bool) ($definition['tenant_compatible'] ?? true),
                    'execution_strategy' => (string) ($definition['execution_strategy'] ?? 'job'),
                    'default_payload' => $definition['default_payload'] ?? [],
                    'enabled' => (bool) ($definition['enabled'] ?? true),
                ]
            );
        }
    }

    private function seedMappings(): void
    {
        $rows = [
            [
                'name' => 'Malware Containment',
                'trigger_type' => 'malware_detected',
                'minimum_severity' => 'high',
                'minimum_risk_score' => 70,
                'candidate_actions' => [
                    ['action_key' => 'isolate_device', 'priority' => 1],
                    ['action_key' => 'collect_forensic_snapshot', 'priority' => 2],
                    ['action_key' => 'notify_admin', 'priority' => 3],
                ],
            ],
            [
                'name' => 'Suspicious Login Containment',
                'trigger_type' => 'suspicious_login',
                'minimum_severity' => 'medium',
                'minimum_risk_score' => 55,
                'candidate_actions' => [
                    ['action_key' => 'force_password_reset', 'priority' => 1],
                    ['action_key' => 'disable_user_session', 'priority' => 2],
                    ['action_key' => 'notify_admin', 'priority' => 3],
                ],
            ],
            [
                'name' => 'CPU Diagnostic Escalation',
                'trigger_type' => 'cpu_anomaly_high',
                'minimum_severity' => 'medium',
                'minimum_risk_score' => 45,
                'candidate_actions' => [
                    ['action_key' => 'run_diagnostic_script', 'priority' => 1],
                    ['action_key' => 'collect_forensic_snapshot', 'priority' => 2],
                ],
            ],
            [
                'name' => 'Repeated Non Compliance Recovery',
                'trigger_type' => 'repeated_non_compliance',
                'minimum_severity' => 'medium',
                'minimum_risk_score' => 50,
                'candidate_actions' => [
                    ['action_key' => 'reapply_policy', 'priority' => 1],
                    ['action_key' => 'restart_agent', 'priority' => 2],
                    ['action_key' => 'notify_admin', 'priority' => 3],
                ],
            ],
            [
                'name' => 'Suspicious Network Burst',
                'trigger_type' => 'suspicious_network_burst',
                'minimum_severity' => 'high',
                'minimum_risk_score' => 60,
                'candidate_actions' => [
                    ['action_key' => 'restrict_network', 'priority' => 1],
                    ['action_key' => 'collect_forensic_snapshot', 'priority' => 2],
                ],
            ],
            [
                'name' => 'Repeated Agent Failure Recovery',
                'trigger_type' => 'repeated_agent_failure',
                'minimum_severity' => 'medium',
                'minimum_risk_score' => 40,
                'candidate_actions' => [
                    ['action_key' => 'restart_agent', 'priority' => 1],
                    ['action_key' => 'run_diagnostic_script', 'priority' => 2],
                ],
            ],
            [
                'name' => 'Policy Drift Recovery',
                'trigger_type' => 'policy_drift_detected',
                'minimum_severity' => 'medium',
                'minimum_risk_score' => 40,
                'candidate_actions' => [
                    ['action_key' => 'reapply_policy', 'priority' => 1],
                    ['action_key' => 'notify_admin', 'priority' => 2],
                ],
            ],
            [
                'name' => 'Health Degradation',
                'trigger_type' => 'agent_health_degradation',
                'minimum_severity' => 'high',
                'minimum_risk_score' => 50,
                'candidate_actions' => [
                    ['action_key' => 'restart_agent', 'priority' => 1],
                    ['action_key' => 'run_diagnostic_script', 'priority' => 2],
                    ['action_key' => 'reboot_device', 'priority' => 3],
                ],
            ],
        ];

        foreach ($rows as $index => $row) {
            RiskActionMapping::query()->updateOrCreate(
                [
                    'tenant_id' => null,
                    'name' => $row['name'],
                ],
                [
                    'id' => RiskActionMapping::query()->whereNull('tenant_id')->where('name', $row['name'])->value('id') ?? (string) Str::uuid(),
                    'trigger_type' => $row['trigger_type'],
                    'minimum_severity' => $row['minimum_severity'] ?? null,
                    'maximum_severity' => $row['maximum_severity'] ?? null,
                    'minimum_risk_score' => (float) ($row['minimum_risk_score'] ?? 0),
                    'maximum_risk_score' => $row['maximum_risk_score'] ?? null,
                    'candidate_actions' => $row['candidate_actions'],
                    'preconditions' => [],
                    'rollback_metadata' => [],
                    'enabled' => true,
                    'priority' => 100 + $index,
                ]
            );
        }
    }

    private function seedPolicies(): void
    {
        AutonomousResponsePolicy::query()->updateOrCreate(
            [
                'tenant_id' => null,
                'scope_type' => 'global',
                'scope_id' => null,
                'trigger_type' => 'any',
            ],
            [
                'id' => AutonomousResponsePolicy::query()
                    ->whereNull('tenant_id')
                    ->where('scope_type', 'global')
                    ->whereNull('scope_id')
                    ->where('trigger_type', 'any')
                    ->value('id') ?? (string) Str::uuid(),
                'name' => 'Global Autonomous Baseline',
                'minimum_risk_score' => 40,
                'allowed_actions' => ['run_diagnostic_script', 'collect_forensic_snapshot', 'restart_agent', 'reapply_policy', 'restrict_network', 'isolate_device', 'notify_admin'],
                'blocked_actions' => ['uninstall_package', 'block_hash'],
                'autonomy_mode' => 'approval_required',
                'minimum_confidence' => 78,
                'requires_rollback_plan' => false,
                'max_actions_per_hour' => 4,
                'cooldown_minutes' => 30,
                'enabled' => true,
            ]
        );
    }

    private function seedControlSettings(): void
    {
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'autonomous_response.pause'],
            [
                'tenant_id' => null,
                'value' => ['value' => false],
            ]
        );
    }
}
