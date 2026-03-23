<?php

namespace Tests\Feature\Behavior;

use App\Models\AiEventStream;
use App\Models\ControlPlaneSetting;
use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\AnomalyDetectionEngine;
use App\Services\BehaviorPipeline\OpenAiBehaviorAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnomalyDetectionOpenAiCalibrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_confidence_openai_adjustment_is_not_applied(): void
    {
        config()->set('services.openai.behavior_min_confidence', 0.70);
        $this->setControlPlaneSetting('behavior.pipeline.min_risk', 0.0);

        $device = $this->createDevice('openai-calibration-low-confidence');
        [$event, $stream] = $this->createEventAndStream($device->id, 'process_start', 'wscript.exe');

        $this->bindOpenAiResponse([
            'classification' => 'suspicious',
            'confidence' => 0.42,
            'recommended_action' => 'notify',
            'risk_adjustment' => 0.35,
            'summary' => 'Low confidence model narrative should not override summary.',
            'behavior_markers' => ['rare_process'],
            'model' => 'test-model',
            'generated_at' => now()->toIso8601String(),
        ]);

        $case = app(AnomalyDetectionEngine::class)->detectAndPersist($stream, $event);
        $this->assertNotNull($case);
        $case?->refresh();

        $this->assertSame(0.0, (float) data_get($case?->context, 'risk.openai_adjustment', -1));
        $this->assertFalse((bool) data_get($case?->context, 'openai_calibration.confidence_gate_passed', true));
        $this->assertStringContainsString('AI pipeline detected', (string) $case?->summary);
    }

    public function test_normal_classification_cannot_raise_risk_and_can_auto_approve(): void
    {
        config()->set('services.openai.behavior_min_confidence', 0.60);
        $this->setControlPlaneSetting('behavior.pipeline.min_risk', 0.0);

        $device = $this->createDevice('openai-calibration-normal');
        [$event, $stream] = $this->createEventAndStream($device->id, 'process_start', 'powershell.exe');

        $this->bindOpenAiResponse([
            'classification' => 'normal',
            'confidence' => 0.98,
            'recommended_action' => 'observe',
            'risk_adjustment' => 0.30,
            'summary' => 'Model thinks this device behavior is normal.',
            'behavior_markers' => ['expected_admin_chain'],
            'model' => 'test-model',
            'generated_at' => now()->toIso8601String(),
        ]);

        $case = app(AnomalyDetectionEngine::class)->detectAndPersist($stream, $event);
        $this->assertNotNull($case);
        $case?->refresh();

        $this->assertSame('approved', (string) $case?->status);
        $this->assertSame(0.0, (float) data_get($case?->context, 'risk.openai_adjustment', -1));
        $this->assertTrue((bool) data_get($case?->context, 'openai_calibration.confidence_gate_passed', false));
    }

    public function test_malicious_adjustment_is_scaled_by_confidence(): void
    {
        config()->set('services.openai.behavior_min_confidence', 0.60);
        $this->setControlPlaneSetting('behavior.pipeline.min_risk', 0.0);

        $device = $this->createDevice('openai-calibration-malicious');
        [$event, $stream] = $this->createEventAndStream($device->id, 'process_start', 'cmd.exe');

        $this->bindOpenAiResponse([
            'classification' => 'malicious',
            'confidence' => 0.80,
            'recommended_action' => 'apply_policy',
            'risk_adjustment' => 0.25,
            'summary' => 'Likely malicious process activity.',
            'behavior_markers' => ['credential_dump_pattern'],
            'model' => 'test-model',
            'generated_at' => now()->toIso8601String(),
        ]);

        $case = app(AnomalyDetectionEngine::class)->detectAndPersist($stream, $event);
        $this->assertNotNull($case);
        $case?->refresh();

        $this->assertEqualsWithDelta(0.20, (float) data_get($case?->context, 'risk.openai_adjustment', 0.0), 0.0001);
        $this->assertEqualsWithDelta(0.25, (float) data_get($case?->context, 'risk.openai_raw_adjustment', 0.0), 0.0001);
        $this->assertTrue((bool) data_get($case?->context, 'openai_calibration.confidence_gate_passed', false));
    }

    private function bindOpenAiResponse(array $response): void
    {
        app()->instance(OpenAiBehaviorAnalyst::class, new class($response) extends OpenAiBehaviorAnalyst
        {
            /**
             * @param array<string,mixed> $features
             * @param array<string,mixed> $detectorSignals
             */
            public function analyze(
                DeviceBehaviorLog $event,
                array $features,
                array $detectorSignals,
                float $riskScore,
                float $threshold,
            ): ?array {
                return $this->response;
            }

            /**
             * @param array<string,mixed> $response
             */
            public function __construct(private readonly array $response)
            {
            }
        });
    }

    private function createDevice(string $hostname): Device
    {
        return Device::query()->create([
            'id' => (string) Str::uuid(),
            'hostname' => $hostname,
            'os_name' => 'Windows',
            'os_version' => '11',
            'agent_version' => '2.1.0',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @return array{0:DeviceBehaviorLog,1:AiEventStream}
     */
    private function createEventAndStream(string $deviceId, string $eventType, string $processName): array
    {
        $event = DeviceBehaviorLog::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'occurred_at' => now()->subMinute(),
            'user_name' => 'LAB\\operator',
            'process_name' => $processName,
            'file_path' => 'C:\\Temp\\'.$processName,
            'metadata' => ['source' => 'tests'],
        ]);

        $stream = AiEventStream::query()->create([
            'id' => (string) Str::uuid(),
            'device_id' => $deviceId,
            'behavior_log_id' => $event->id,
            'event_type' => $event->event_type,
            'occurred_at' => $event->occurred_at,
            'payload' => [
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'process_name' => $event->process_name,
            ],
            'status' => 'queued',
            'attempts' => 0,
        ]);

        return [$event, $stream];
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

