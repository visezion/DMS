<?php

namespace Database\Seeders;

use App\Models\ActionGuardrail;
use App\Models\AutonomyPolicy;
use App\Models\ConfidenceThreshold;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EndpointIntelligenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedConfidenceThresholds();
        $this->seedActionGuardrails();
        $this->seedAutonomyPolicy();
    }

    private function seedConfidenceThresholds(): void
    {
        $defaults = [
            ['engine' => 'assistant', 'context_key' => 'default', 'min_confidence' => 0.55, 'approval_below' => 0.75, 'auto_execute_above' => 0.92],
            ['engine' => 'risk', 'context_key' => 'default', 'min_confidence' => 0.60, 'approval_below' => 0.80, 'auto_execute_above' => 0.95],
            ['engine' => 'remediation', 'context_key' => 'default', 'min_confidence' => 0.65, 'approval_below' => 0.85, 'auto_execute_above' => 0.96],
        ];

        foreach ($defaults as $row) {
            ConfidenceThreshold::query()->updateOrCreate(
                [
                    'tenant_id' => null,
                    'engine' => $row['engine'],
                    'context_key' => $row['context_key'],
                    'active' => true,
                ],
                [
                    'id' => ConfidenceThreshold::query()
                        ->where('tenant_id', null)
                        ->where('engine', $row['engine'])
                        ->where('context_key', $row['context_key'])
                        ->where('active', true)
                        ->value('id') ?? (string) Str::uuid(),
                    'min_confidence' => $row['min_confidence'],
                    'approval_below' => $row['approval_below'],
                    'auto_execute_above' => $row['auto_execute_above'],
                ]
            );
        }
    }

    private function seedActionGuardrails(): void
    {
        $defaults = [
            're_run_inventory' => ['max_targets' => 100, 'requires_rollback_plan' => false],
            'restart_service' => ['max_targets' => 25, 'requires_rollback_plan' => false],
            'trigger_windows_update' => ['max_targets' => 10, 'requires_rollback_plan' => false],
            're_enable_security_control' => ['max_targets' => 10, 'requires_rollback_plan' => false],
            'run_approved_command' => ['max_targets' => 1, 'requires_rollback_plan' => true],
        ];

        foreach ($defaults as $actionType => $config) {
            ActionGuardrail::query()->updateOrCreate(
                [
                    'tenant_id' => null,
                    'action_type' => $actionType,
                    'active' => true,
                ],
                [
                    'id' => ActionGuardrail::query()
                        ->where('tenant_id', null)
                        ->where('action_type', $actionType)
                        ->where('active', true)
                        ->value('id') ?? (string) Str::uuid(),
                    'arg_schema' => ['type' => 'object'],
                    'forbidden_patterns' => ['Remove-Item', 'format', 'cipher /w'],
                    'allow_conditions' => ['device_online' => true],
                    'deny_conditions' => ['kill_switch' => true],
                    'max_targets' => $config['max_targets'],
                    'cooldown_seconds' => 300,
                    'requires_rollback_plan' => $config['requires_rollback_plan'],
                    'version' => 1,
                ]
            );
        }
    }

    private function seedAutonomyPolicy(): void
    {
        $scopeId = 'global';

        AutonomyPolicy::query()->updateOrCreate(
            [
                'tenant_id' => null,
                'scope_type' => 'global',
                'scope_id' => $scopeId,
                'active' => true,
            ],
            [
                'id' => AutonomyPolicy::query()
                    ->where('tenant_id', null)
                    ->where('scope_type', 'global')
                    ->where('scope_id', $scopeId)
                    ->where('active', true)
                    ->value('id') ?? (string) Str::uuid(),
                'autonomy_level' => 'advisory',
                'allowed_actions' => ['re_run_inventory', 'restart_service', 'cleanup_temp_files'],
                'blocked_conditions' => ['kill_switch' => true],
                'maintenance_windows' => [],
                'max_parallel_actions' => 5,
                'version' => 1,
            ]
        );
    }
}
