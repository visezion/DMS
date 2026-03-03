<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\Policy;
use App\Models\PolicyRule;
use App\Models\PolicyVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BaselineDriftRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_drift_queues_single_remediation_job_and_stores_report(): void
    {
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => 'BASELINE-TEST-01',
            'os_name' => 'Windows',
            'os_version' => '10.0.19045',
            'status' => 'online',
            'last_seen_at' => now(),
            'agent_version' => '2.0.2',
        ]);

        $policy = Policy::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Baseline Policy',
            'slug' => 'baseline-policy',
            'category' => 'operations/baseline',
            'status' => 'active',
        ]);
        $version = PolicyVersion::query()->create([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'version_number' => 1,
            'status' => 'active',
            'published_at' => now(),
        ]);
        PolicyRule::query()->create([
            'id' => (string) Str::uuid(),
            'policy_version_id' => $version->id,
            'order_index' => 0,
            'rule_type' => 'baseline_profile',
            'rule_config' => [
                'registry_values' => [
                    [
                        'path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR',
                        'name' => 'Start',
                        'type' => 'DWORD',
                        'value' => 4,
                    ],
                ],
                'remediation_rules' => [
                    [
                        'type' => 'registry',
                        'config' => [
                            'path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR',
                            'name' => 'Start',
                            'type' => 'DWORD',
                            'value' => 4,
                        ],
                        'enforce' => true,
                    ],
                ],
            ],
            'enforce' => true,
        ]);

        $sourceJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'job_type' => 'apply_policy',
            'status' => 'queued',
            'priority' => 100,
            'payload' => [
                'policy_version_id' => $version->id,
                'rules' => [
                    [
                        'type' => 'baseline_profile',
                        'config' => [
                            'registry_values' => [
                                [
                                    'path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR',
                                    'name' => 'Start',
                                    'type' => 'DWORD',
                                    'value' => 4,
                                ],
                            ],
                            'remediation_rules' => [
                                [
                                    'type' => 'registry',
                                    'config' => [
                                        'path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR',
                                        'name' => 'Start',
                                        'type' => 'DWORD',
                                        'value' => 4,
                                    ],
                                    'enforce' => true,
                                ],
                            ],
                        ],
                        'enforce' => true,
                    ],
                ],
            ],
            'target_type' => 'device',
            'target_id' => $device->id,
        ]);
        $run = JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $sourceJob->id,
            'device_id' => $device->id,
            'status' => 'pending',
        ]);

        $resultPayload = [
            'compliance_status' => 'non_compliant',
            'rules' => [
                [
                    'type' => 'baseline_profile',
                    'compliant' => false,
                    'message' => 'baseline drift detected (1)',
                    'baseline_report' => [
                        'collected_at' => now()->toIso8601String(),
                        'observed' => [
                            'critical_files' => [],
                            'registry_values' => [
                                [
                                    'path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR',
                                    'name' => 'Start',
                                    'exists' => true,
                                    'value' => '3',
                                ],
                            ],
                            'services' => [],
                            'installed_packages' => [],
                        ],
                        'drifts' => [
                            [
                                'kind' => 'registry_value',
                                'path' => 'HKLM\\SYSTEM\\CurrentControlSet\\Services\\USBSTOR',
                                'name' => 'Start',
                                'expected' => '4',
                                'actual' => '3',
                            ],
                        ],
                    ],
                    'drift_count' => 1,
                ],
            ],
        ];

        $this->postJson('/api/v1/device/job-result', [
            'job_run_id' => $run->id,
            'device_id' => $device->id,
            'status' => 'non_compliant',
            'exit_code' => 2,
            'result_payload' => $resultPayload,
        ])->assertStatus(200);

        $this->postJson('/api/v1/device/job-result', [
            'job_run_id' => $run->id,
            'device_id' => $device->id,
            'status' => 'non_compliant',
            'exit_code' => 2,
            'result_payload' => $resultPayload,
        ])->assertStatus(200);

        $remediationJobs = DmsJob::query()
            ->where('target_type', 'device')
            ->where('target_id', $device->id)
            ->where('job_type', 'apply_policy')
            ->where('payload->baseline_remediation', true)
            ->where('payload->baseline_source_job_id', $sourceJob->id)
            ->get();

        $this->assertCount(1, $remediationJobs, 'Expected exactly one queued remediation job for this baseline source job.');
        $this->assertSame('queued', (string) $remediationJobs->first()->status);
        $this->assertSame(
            'registry',
            strtolower((string) (($remediationJobs->first()->payload['rules'][0]['type'] ?? '')))
        );

        $device->refresh();
        $tags = is_array($device->tags) ? $device->tags : [];
        $reports = is_array($tags['baseline_drift_reports'] ?? null) ? $tags['baseline_drift_reports'] : [];
        $this->assertNotEmpty($reports);
        $this->assertSame(1, (int) ($reports[0]['drift_count'] ?? -1));
    }
}
