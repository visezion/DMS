<?php

namespace App\Services\BehaviorPipeline\Detectors;

use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\BehavioralBaselineModelingService;
use App\Services\BehaviorPipeline\Contracts\AnomalyDetector;
use Illuminate\Support\Facades\Log;

class BehavioralBaselineDriftDetector implements AnomalyDetector
{
    public function __construct(private readonly BehavioralBaselineModelingService $baselineModelingService)
    {
    }

    public function key(): string
    {
        return 'behavioral_baseline_drift';
    }

    public function detect(DeviceBehaviorLog $event, array $features): array
    {
        try {
            return $this->baselineModelingService->detectorSignal($event, $features);
        } catch (\Throwable $e) {
            Log::warning('Behavioral baseline detector failed.', [
                'device_id' => (string) $event->device_id,
                'behavior_log_id' => (string) $event->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'score' => 0.0,
                'confidence' => 0.0,
                'active' => false,
                'details' => [
                    'reason' => 'baseline_detector_error',
                ],
            ];
        }
    }
}

