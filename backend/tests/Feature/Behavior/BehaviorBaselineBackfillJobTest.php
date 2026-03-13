<?php

namespace Tests\Feature\Behavior;

use App\Jobs\BackfillBehaviorBaselineProfilesJob;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceBehaviorBaseline;
use App\Models\DeviceBehaviorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BehaviorBaselineBackfillJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_job_builds_device_baseline_profiles_from_existing_logs(): void
    {
        $device = Device::query()->create([
            'hostname' => 'lab-baseline-backfill-01',
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.baseline.enabled'],
            ['value' => ['value' => true]]
        );
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => 'behavior.baseline.min_samples'],
            ['value' => ['value' => 5]]
        );

        for ($i = 0; $i < 12; $i++) {
            DeviceBehaviorLog::query()->create([
                'device_id' => $device->id,
                'event_type' => 'app_launch',
                'occurred_at' => now()->subHours(3)->addMinutes($i * 5),
                'user_name' => 'student',
                'process_name' => 'chrome.exe',
                'file_path' => 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'metadata' => [
                    'cpu_percent' => 18 + ($i % 3),
                    'memory_mb' => 520 + ($i * 2),
                    'network_bytes_sent' => 60000 + ($i * 400),
                    'network_bytes_received' => 90000 + ($i * 700),
                ],
            ]);
        }

        BackfillBehaviorBaselineProfilesJob::dispatchSync(7, 5000);

        $baseline = DeviceBehaviorBaseline::query()->where('device_id', $device->id)->first();
        $this->assertNotNull($baseline);
        $this->assertGreaterThanOrEqual(12, (int) $baseline?->sample_count);

        $resultSetting = ControlPlaneSetting::query()->find('behavior.baseline.last_backfill_result');
        $this->assertNotNull($resultSetting);
        $result = is_array($resultSetting?->value ?? null) ? ($resultSetting->value['value'] ?? []) : [];
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, (int) ($result['processed'] ?? 0));
    }
}

