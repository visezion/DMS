<?php

namespace App\Domain\Remediation;

class ActionCatalog
{
    public function all(): array
    {
        return [
            'restart_service' => ['risk' => 'low', 'approval_required' => false],
            'kill_process' => ['risk' => 'medium', 'approval_required' => true],
            'run_approved_command' => ['risk' => 'high', 'approval_required' => true],
            'apply_policy' => ['risk' => 'medium', 'approval_required' => true],
            'isolate_device' => ['risk' => 'high', 'approval_required' => true],
            'uninstall_software' => ['risk' => 'medium', 'approval_required' => true],
            'cleanup_temp_files' => ['risk' => 'low', 'approval_required' => false],
            'trigger_windows_update' => ['risk' => 'medium', 'approval_required' => true],
            're_run_inventory' => ['risk' => 'low', 'approval_required' => false],
            're_enable_security_control' => ['risk' => 'medium', 'approval_required' => true],
            'agent_self_heal' => ['risk' => 'medium', 'approval_required' => true],
            'schedule_reboot' => ['risk' => 'medium', 'approval_required' => true],
            'open_approval_request' => ['risk' => 'low', 'approval_required' => false],
        ];
    }

    public function has(string $actionType): bool
    {
        return array_key_exists($actionType, $this->all());
    }

    public function get(string $actionType): array
    {
        return $this->all()[$actionType] ?? [];
    }
}
