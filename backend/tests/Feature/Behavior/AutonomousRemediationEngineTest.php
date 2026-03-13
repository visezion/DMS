<?php

namespace Tests\Feature\Behavior;

use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorRemediationExecution;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DmsJob;
use App\Services\BehaviorPipeline\AutonomousRemediationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutonomousRemediationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_risk_case_queues_autonomous_remediation_actions_and_deduplicates(): void
    {
        $device = Device::query()->create([
            'hostname' => 'lab-remediation-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->setSetting('behavior.remediation.enabled', true);
        $this->setSetting('behavior.remediation.min_risk', 0.70);
        $this->setSetting('behavior.remediation.max_actions_per_case', 2);
        $this->setSetting('behavior.remediation.allow_force_scan', true);
        $this->setSetting('behavior.remediation.allow_kill_process', true);
        $this->setSetting('behavior.remediation.allow_emergency_profile', false);
        $this->setSetting('behavior.remediation.allow_isolate_network', false);
        $this->setSetting('behavior.remediation.allow_uninstall_software', false);
        $this->setSetting('behavior.remediation.allow_rollback_policy', false);
        $this->setSetting('behavior.remediation.scan_command', 'echo remediation_scan');

        $case = BehaviorAnomalyCase::query()->create([
            'id' => (string) Str::uuid(),
            'stream_event_id' => null,
            'behavior_log_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'risk_score' => 0.9600,
            'severity' => 'high',
            'status' => 'pending_review',
            'summary' => 'Synthetic high-risk anomaly for remediation test.',
            'context' => [
                'features' => [
                    'event_type' => 'process_start',
                    'process_name' => 'mimikatz.exe',
                    'process_name_raw' => 'mimikatz.exe',
                    'metadata' => [
                        'cpu_percent' => 93,
                        'memory_mb' => 1640,
                        'network_bytes_sent' => 4800000,
                        'network_bytes_received' => 3900000,
                        'network_connection_count' => 33,
                    ],
                ],
                'detector_signals' => [
                    'rare_process_on_device' => ['score' => 0.93, 'active' => true],
                    'multi_signal_correlation_window' => ['score' => 0.81, 'active' => true],
                    'behavioral_baseline_drift' => ['score' => 0.79, 'active' => true],
                ],
            ],
            'detector_weights' => [],
            'detected_at' => now(),
        ]);

        $service = app(AutonomousRemediationEngine::class);
        $created = $service->executeForCase($case);
        $createdAgain = $service->executeForCase($case);

        $this->assertCount(2, $created);
        $this->assertCount(0, $createdAgain);
        $this->assertSame(2, BehaviorRemediationExecution::query()->where('anomaly_case_id', $case->id)->count());

        $remediationJobs = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->get()
            ->filter(function (DmsJob $job) {
                $payload = is_array($job->payload) ? $job->payload : [];
                return (bool) ($payload['autonomous_remediation'] ?? false);
            })
            ->values();

        $this->assertCount(2, $remediationJobs);
        $this->assertTrue($remediationJobs->every(fn (DmsJob $job) => $job->job_type === 'apply_policy'));
    }

    public function test_engine_is_optional_when_feature_flag_is_disabled(): void
    {
        $device = Device::query()->create([
            'hostname' => 'lab-remediation-disabled',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->setSetting('behavior.remediation.enabled', false);

        $case = BehaviorAnomalyCase::query()->create([
            'id' => (string) Str::uuid(),
            'stream_event_id' => null,
            'behavior_log_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'risk_score' => 0.9900,
            'severity' => 'high',
            'status' => 'pending_review',
            'summary' => 'Remediation disabled test anomaly.',
            'context' => [
                'features' => [
                    'event_type' => 'process_start',
                    'process_name' => 'unknown.exe',
                ],
                'detector_signals' => [
                    'rare_process_on_device' => ['score' => 0.97, 'active' => true],
                ],
            ],
            'detector_weights' => [],
            'detected_at' => now(),
        ]);

        $created = app(AutonomousRemediationEngine::class)->executeForCase($case);

        $this->assertCount(0, $created);
        $this->assertSame(0, BehaviorRemediationExecution::query()->count());
        $this->assertSame(0, DmsJob::query()->count());
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

