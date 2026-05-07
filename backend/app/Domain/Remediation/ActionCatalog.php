<?php

namespace App\Domain\Remediation;

use App\Models\AutonomousActionDefinition;
use Illuminate\Support\Facades\Schema;

class ActionCatalog
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $definitions = $this->defaults();

        if (! Schema::hasTable('autonomous_action_definitions')) {
            return $definitions;
        }

        $rows = AutonomousActionDefinition::query()->where('enabled', true)->get();
        foreach ($rows as $row) {
            $definitions[(string) $row->action_key] = array_merge(
                $definitions[(string) $row->action_key] ?? [],
                [
                    'display_name' => $row->display_name,
                    'description' => $row->description,
                    'supported_target_types' => is_array($row->supported_target_types) ? $row->supported_target_types : [],
                    'required_parameters' => is_array($row->required_parameters) ? $row->required_parameters : [],
                    'safety_class' => (string) $row->safety_class,
                    'reversible' => (bool) $row->reversible,
                    'rollback_handler' => $row->rollback_handler,
                    'recommended_approval_mode' => (string) $row->recommended_approval_mode,
                    'cooldown_minutes' => (int) $row->cooldown_minutes,
                    'requires_online' => (bool) $row->requires_online,
                    'supports_offline' => (bool) $row->supports_offline,
                    'tenant_compatible' => (bool) $row->tenant_compatible,
                    'execution_strategy' => (string) $row->execution_strategy,
                    'default_payload' => is_array($row->default_payload) ? $row->default_payload : [],
                    'enabled' => (bool) $row->enabled,
                    'approval_required' => (string) $row->recommended_approval_mode !== 'auto_execute',
                    'risk' => (string) $row->safety_class,
                ]
            );
        }

        return $definitions;
    }

    public function has(string $actionType): bool
    {
        return array_key_exists($actionType, $this->all());
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $actionType): array
    {
        return $this->all()[$actionType] ?? [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function defaults(): array
    {
        return [
            'restart_service' => $this->definition('Restart Service', 'Restart a Windows service on the target endpoint.', 'moderate', ['device'], ['service_name'], true, 'approval_required', true, false, 'job', []),
            'kill_process' => $this->definition('Kill Process', 'Terminate a process by name or PID.', 'high', ['device'], ['process_name'], false, 'approval_required', true, false, 'job', []),
            'run_approved_command' => $this->definition('Run Approved Command', 'Execute a pre-approved command payload.', 'high', ['device'], ['script'], true, 'approval_required', true, false, 'job', []),
            'apply_policy' => $this->definition('Apply Policy', 'Re-apply a known DMS policy version to the endpoint.', 'moderate', ['device', 'group'], ['policy_version_id'], true, 'approval_required', true, false, 'job', []),
            'isolate_device' => $this->definition('Isolate Device', 'Restrict inbound and outbound network access for containment.', 'high', ['device'], [], true, 'approval_required', true, false, 'job', [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "New-NetFirewallRule -DisplayName \'DMS Auto Isolation In\' -Direction Inbound -Action Block; New-NetFirewallRule -DisplayName \'DMS Auto Isolation Out\' -Direction Outbound -Action Block"',
                'rollback_job_type' => 'run_command',
                'rollback_payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-NetFirewallRule -DisplayName \'DMS Auto Isolation In\',\'DMS Auto Isolation Out\' -ErrorAction SilentlyContinue | Remove-NetFirewallRule"',
                ],
            ]),
            'uninstall_software' => $this->definition('Uninstall Software', 'Remove a software package from the endpoint.', 'high', ['device'], ['winget_id'], true, 'approval_required', true, false, 'job', []),
            'cleanup_temp_files' => $this->definition('Cleanup Temp Files', 'Delete temporary files to relieve disk pressure.', 'safe', ['device'], [], true, 'recommend_only', true, false, 'job', []),
            'trigger_windows_update' => $this->definition('Trigger Windows Update', 'Start a Windows Update scan and install cycle.', 'moderate', ['device'], [], true, 'approval_required', true, false, 'job', []),
            're_run_inventory' => $this->definition('Re-run Inventory', 'Reconcile software and system inventory from the endpoint.', 'safe', ['device'], [], true, 'recommend_only', true, false, 'job', []),
            're_enable_security_control' => $this->definition('Re-enable Security Control', 'Restore a disabled Defender or firewall control.', 'moderate', ['device'], ['control'], true, 'approval_required', true, false, 'job', []),
            'agent_self_heal' => $this->definition('Agent Self Heal', 'Restart the DMS agent service and recover basic functionality.', 'moderate', ['device'], [], true, 'approval_required', true, false, 'job', []),
            'schedule_reboot' => $this->definition('Schedule Reboot', 'Queue a controlled reboot on the endpoint.', 'moderate', ['device'], [], true, 'approval_required', true, false, 'job', []),
            'open_approval_request' => $this->definition('Open Approval Request', 'Escalate the action for a human operator review.', 'safe', ['device', 'group', 'fleet'], [], false, 'recommend_only', false, true, 'manual', []),
            'run_diagnostic_script' => $this->definition('Run Diagnostic Script', 'Run a bounded diagnostic script to collect troubleshooting context.', 'safe', ['device'], [], true, 'recommend_only', true, false, 'job', [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Process | Sort-Object CPU -Descending | Select-Object -First 20; Get-Service | Where-Object {$_.Status -ne \'Running\'}"',
            ]),
            'collect_forensic_snapshot' => $this->definition('Collect Forensic Snapshot', 'Gather process, service, network, and system state for investigation.', 'moderate', ['device'], [], true, 'approval_required', true, false, 'job', [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Process; Get-Service; Get-NetTCPConnection; Get-ComputerInfo | Out-String"',
            ]),
            'force_password_reset' => $this->definition('Force Password Reset', 'Mark the account for password reset or open a secure reset workflow.', 'high', ['device', 'user'], [], false, 'approval_required', false, true, 'manual', []),
            'disable_user_session' => $this->definition('Disable User Session', 'Log off active user sessions on the endpoint.', 'high', ['device'], [], true, 'approval_required', true, false, 'job', [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Process explorer -IncludeUserName -ErrorAction SilentlyContinue | Stop-Process -Force"',
            ]),
            'restart_agent' => $this->definition('Restart Agent', 'Restart the DMS agent service.', 'moderate', ['device'], [], true, 'approval_required', true, false, 'job', []),
            'reapply_policy' => $this->definition('Reapply Policy', 'Re-apply the latest effective policy to the endpoint.', 'moderate', ['device'], ['policy_version_id'], true, 'approval_required', true, false, 'job', []),
            'uninstall_package' => $this->definition('Uninstall Package', 'Uninstall a deployed package using its known identifier.', 'high', ['device'], ['winget_id'], true, 'approval_required', true, false, 'job', []),
            'block_hash' => $this->definition('Block Hash', 'Block a known-bad file hash and contain the payload.', 'destructive', ['device'], ['hash'], true, 'approval_required', true, false, 'job', [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Write-Output \'Hash block requested by autonomous response\'"',
            ]),
            'quarantine_file' => $this->definition('Quarantine File', 'Move a suspicious file into a quarantine folder.', 'high', ['device'], ['file_path'], true, 'approval_required', true, false, 'job', []),
            'restrict_network' => $this->definition('Restrict Network', 'Apply a restrictive network profile without full isolation.', 'high', ['device'], [], true, 'approval_required', true, false, 'job', [
                'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Set-NetFirewallProfile -Profile Domain,Public,Private -DefaultOutboundAction Block -DefaultInboundAction Block"',
                'rollback_job_type' => 'run_command',
                'rollback_payload' => [
                    'script' => 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Set-NetFirewallProfile -Profile Domain,Public,Private -DefaultOutboundAction Allow -DefaultInboundAction Allow"',
                ],
            ]),
            'reboot_device' => $this->definition('Reboot Device', 'Restart the endpoint to recover from a degraded state.', 'moderate', ['device'], [], true, 'approval_required', true, false, 'job', []),
            'notify_admin' => $this->definition('Notify Admin', 'Create an operator-visible notification for manual follow-up.', 'safe', ['device', 'fleet'], [], false, 'recommend_only', false, true, 'manual', []),
            'create_ticket' => $this->definition('Create Ticket', 'Escalate the issue into the ticketing workflow.', 'safe', ['device', 'fleet'], [], false, 'recommend_only', false, true, 'manual', []),
            'require_manual_investigation' => $this->definition('Require Manual Investigation', 'Stop automation and require an analyst review.', 'safe', ['device', 'fleet'], [], false, 'recommend_only', false, true, 'manual', []),
        ];
    }

    /**
     * @param  array<int,string>  $targets
     * @param  array<int,string>  $requiredParameters
     * @param  array<string,mixed>  $defaultPayload
     * @return array<string,mixed>
     */
    private function definition(
        string $displayName,
        string $description,
        string $safetyClass,
        array $targets,
        array $requiredParameters,
        bool $reversible,
        string $recommendedApprovalMode,
        bool $requiresOnline,
        bool $supportsOffline,
        string $executionStrategy,
        array $defaultPayload
    ): array {
        return [
            'display_name' => $displayName,
            'description' => $description,
            'supported_target_types' => $targets,
            'required_parameters' => $requiredParameters,
            'safety_class' => $safetyClass,
            'reversible' => $reversible,
            'rollback_handler' => $reversible ? 'job' : null,
            'recommended_approval_mode' => $recommendedApprovalMode,
            'cooldown_minutes' => 15,
            'requires_online' => $requiresOnline,
            'supports_offline' => $supportsOffline,
            'tenant_compatible' => true,
            'execution_strategy' => $executionStrategy,
            'default_payload' => $defaultPayload,
            'enabled' => true,
            'risk' => $safetyClass,
            'approval_required' => $recommendedApprovalMode !== 'auto_execute',
        ];
    }
}
