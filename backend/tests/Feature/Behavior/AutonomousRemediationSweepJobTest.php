<?php

namespace Tests\Feature\Behavior;

use App\Jobs\SweepAutonomousRemediationCasesJob;
use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorRemediationExecution;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutonomousRemediationSweepJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sweep_job_processes_existing_pending_cases_and_records_result(): void
    {
        $device = Device::query()->create([
            'hostname' => 'lab-remediation-sweep-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->setSetting('behavior.remediation.enabled', true);
        $this->setSetting('behavior.remediation.min_risk', 0.70);
        $this->setSetting('behavior.remediation.max_actions_per_case', 1);
        $this->setSetting('behavior.remediation.allow_force_scan', true);
        $this->setSetting('behavior.remediation.allow_kill_process', false);
        $this->setSetting('behavior.remediation.allow_emergency_profile', false);
        $this->setSetting('behavior.remediation.allow_isolate_network', false);
        $this->setSetting('behavior.remediation.allow_uninstall_software', false);
        $this->setSetting('behavior.remediation.allow_rollback_policy', false);
        $this->setSetting('behavior.remediation.scan_command', 'echo remediation_sweep_scan');

        BehaviorAnomalyCase::query()->create([
            'id' => (string) Str::uuid(),
            'stream_event_id' => null,
            'behavior_log_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'risk_score' => 0.9400,
            'severity' => 'high',
            'status' => 'pending_review',
            'summary' => 'Pending case for remediation sweep.',
            'context' => [
                'features' => [
                    'event_type' => 'process_start',
                    'process_name' => 'weird.exe',
                    'process_name_raw' => 'weird.exe',
                    'metadata' => [
                        'cpu_percent' => 90,
                        'memory_mb' => 1330,
                    ],
                ],
                'detector_signals' => [
                    'rare_process_on_device' => ['score' => 0.91, 'active' => true],
                    'behavioral_baseline_drift' => ['score' => 0.80, 'active' => true],
                ],
            ],
            'detector_weights' => [],
            'detected_at' => now()->subDays(2),
        ]);

        BehaviorAnomalyCase::query()->create([
            'id' => (string) Str::uuid(),
            'stream_event_id' => null,
            'behavior_log_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'risk_score' => 0.9800,
            'severity' => 'high',
            'status' => 'approved',
            'summary' => 'Approved case should be skipped in pending-only sweep.',
            'context' => [
                'features' => [
                    'event_type' => 'process_start',
                    'process_name' => 'approved-case.exe',
                    'process_name_raw' => 'approved-case.exe',
                ],
                'detector_signals' => [
                    'rare_process_on_device' => ['score' => 0.95, 'active' => true],
                ],
            ],
            'detector_weights' => [],
            'detected_at' => now()->subDays(1),
        ]);

        SweepAutonomousRemediationCasesJob::dispatchSync(14, 2000, true);

        $this->assertSame(1, BehaviorRemediationExecution::query()->count());
        $resultSetting = ControlPlaneSetting::query()->find('behavior.remediation.last_sweep_result');
        $this->assertNotNull($resultSetting);
        $result = is_array($resultSetting?->value ?? null) ? ($resultSetting->value['value'] ?? []) : [];
        $this->assertIsArray($result);
        $this->assertSame(1, (int) ($result['scanned'] ?? 0));
        $this->assertSame(1, (int) ($result['executions_created'] ?? 0));
        $this->assertSame(0, (int) ($result['failed_cases'] ?? -1));
    }

    /**
     * @param mixed $value
     */
    private function setSetting(string $key, $value): void
    {
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]]
        );
    }
}

