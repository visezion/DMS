<?php

namespace Tests\Feature\Behavior;

use App\Models\AiEventStream;
use App\Models\BehaviorAnomalySignal;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceBehaviorBaseline;
use App\Models\DeviceBehaviorDriftEvent;
use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\AnomalyDetectionEngine;
use App\Services\BehaviorPipeline\BehaviorFeatureBuilder;
use App\Services\BehaviorPipeline\BehavioralBaselineModelingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BehavioralBaselineModelingTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_detector_integrates_with_pipeline_and_persists_drift_event(): void
    {
        Carbon::setTestNow('2026-03-12 11:00:00');

        try {
            $device = $this->createDevice('lab-baseline-01');
            $this->setControlPlaneSetting('behavior.baseline.enabled', true);
            $this->setControlPlaneSetting('behavior.baseline.min_samples', 5);
            $this->setControlPlaneSetting('behavior.baseline.min_numeric_samples', 5);
            $this->setControlPlaneSetting('behavior.baseline.drift_event_threshold', 0.5);
            $this->setControlPlaneSetting('behavior.pipeline.min_risk', 0.0);

            $baselineService = app(BehavioralBaselineModelingService::class);
            for ($i = 0; $i < 8; $i++) {
                $warmEvent = DeviceBehaviorLog::query()->create([
                    'device_id' => $device->id,
                    'event_type' => 'app_launch',
                    'occurred_at' => now()->subHours(12)->addMinutes($i * 10),
                    'user_name' => 'student',
                    'process_name' => 'chrome.exe',
                    'file_path' => 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                    'metadata' => [
                        'cpu_percent' => 14 + ($i % 2),
                        'memory_mb' => 410 + ($i * 2),
                        'network_bytes_sent' => 45000 + ($i * 400),
                        'network_bytes_received' => 62000 + ($i * 500),
                    ],
                ]);
                $baselineService->ingestOutcome($warmEvent, null);
            }

            $event = DeviceBehaviorLog::query()->create([
                'device_id' => $device->id,
                'event_type' => 'app_launch',
                'occurred_at' => now()->subMinutes(1),
                'user_name' => 'student',
                'process_name' => 'mimikatz.exe',
                'file_path' => 'C:\\Temp\\mimikatz.exe',
                'metadata' => [
                    'cpu_percent' => 92,
                    'memory_mb' => 1480,
                    'network_bytes_sent' => 9500000,
                    'network_bytes_received' => 7800000,
                    'network_connection_count' => 42,
                ],
            ]);

            $stream = AiEventStream::query()->create([
                'device_id' => $device->id,
                'behavior_log_id' => $event->id,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at,
                'payload' => [
                    'event_type' => $event->event_type,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'process_name' => $event->process_name,
                    'metadata' => $event->metadata,
                ],
                'status' => 'queued',
                'attempts' => 0,
            ]);

            $engine = app(AnomalyDetectionEngine::class);
            $case = $engine->detectAndPersist($stream, $event);
            $this->assertNotNull($case);
            $baselineService->ingestOutcome($event, $case);

            $case?->refresh();
            $signal = data_get($case?->context, 'detector_signals.behavioral_baseline_drift');
            $this->assertIsArray($signal);
            $this->assertTrue((bool) ($signal['active'] ?? false));
            $this->assertGreaterThan(0.0, (float) ($signal['score'] ?? 0.0));

            $this->assertDatabaseHas('behavior_anomaly_signals', [
                'anomaly_case_id' => $case?->id,
                'detector_key' => 'behavioral_baseline_drift',
            ]);
            $this->assertDatabaseHas('device_behavior_drift_events', [
                'behavior_log_id' => $event->id,
                'device_id' => $device->id,
            ]);
            $this->assertGreaterThanOrEqual(9, (int) DeviceBehaviorBaseline::query()->where('device_id', $device->id)->value('sample_count'));
            $this->assertGreaterThan(0, DeviceBehaviorDriftEvent::query()->count());
            $this->assertGreaterThan(0, BehaviorAnomalySignal::query()->where('detector_key', 'behavioral_baseline_drift')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_baseline_modeling_is_optional_when_feature_flag_is_disabled(): void
    {
        $device = $this->createDevice('lab-baseline-optional');
        $event = DeviceBehaviorLog::query()->create([
            'device_id' => $device->id,
            'event_type' => 'app_launch',
            'occurred_at' => now(),
            'user_name' => 'student',
            'process_name' => 'chrome.exe',
            'file_path' => 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'metadata' => [
                'cpu_percent' => 17,
                'memory_mb' => 450,
                'network_bytes_sent' => 54000,
                'network_bytes_received' => 76000,
            ],
        ]);

        $baselineService = app(BehavioralBaselineModelingService::class);
        $baselineService->ingestOutcome($event, null);

        $features = app(BehaviorFeatureBuilder::class)->build($event);
        $signal = $baselineService->detectorSignal($event, $features);

        $this->assertFalse((bool) ($signal['active'] ?? true));
        $this->assertSame('feature_disabled', (string) data_get($signal, 'details.reason', ''));
        $this->assertDatabaseMissing('device_behavior_baselines', ['device_id' => $device->id]);
        $this->assertDatabaseMissing('device_behavior_drift_events', ['behavior_log_id' => $event->id]);
    }

    private function createDevice(string $hostname): Device
    {
        return Device::query()->create([
            'hostname' => $hostname,
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '1.0.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param mixed $value
     */
    private function setControlPlaneSetting(string $key, $value): void
    {
        ControlPlaneSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]]
        );
    }
}

