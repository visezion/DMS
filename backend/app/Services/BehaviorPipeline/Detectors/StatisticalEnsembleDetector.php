<?php

namespace App\Services\BehaviorPipeline\Detectors;

use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\BehaviorPipelineSettings;
use App\Services\BehaviorPipeline\Contracts\AnomalyDetector;
use Illuminate\Support\Facades\Storage;

class StatisticalEnsembleDetector implements AnomalyDetector
{
    public function __construct(private readonly BehaviorPipelineSettings $settings)
    {
    }

    public function key(): string
    {
        return 'statistical_ensemble';
    }

    public function detect(DeviceBehaviorLog $event, array $features): array
    {
        $modelPath = $this->settings->settingString('behavior.ai_model_path', 'behavior_models/current-model.json');
        $model = [];
        if (Storage::disk('local')->exists($modelPath)) {
            $decoded = json_decode((string) Storage::disk('local')->get($modelPath), true);
            if (is_array($decoded)) {
                $model = $decoded;
            }
        }

        $type = (string) ($features['event_type'] ?? 'unknown');
        $hour = (int) ($features['hour'] ?? 0);
        $user = (string) ($features['user_name'] ?? 'unknown');
        $process = (string) ($features['process_name'] ?? 'unknown');
        $ext = (string) ($features['file_extension'] ?? 'none');

        $total = (int) ($model['total_events'] ?? 1);
        $typeCount = (int) (($model['event_type_counts'][$type] ?? 0));
        $hourCount = (int) (($model['hour_by_type'][$type][$hour] ?? 0));
        $userHourCount = (int) (($model['user_hour_counts'][$user][$hour] ?? 0));
        $procCount = (int) (($model['process_counts'][$type][$process] ?? 0));
        $extCount = (int) (($model['file_ext_counts'][$ext] ?? 0));

        $pType = ($typeCount + 1) / max(1, $total + 10);
        $pHour = ($hourCount + 1) / max(1, $typeCount + 24);
        $pUserHour = ($userHourCount + 1) / max(1, array_sum($model['user_hour_counts'][$user] ?? []) + 24);
        $pProcess = ($procCount + 1) / max(1, array_sum($model['process_counts'][$type] ?? []) + 100);
        $pExt = ($extCount + 1) / max(1, $total + 200);

        $surprise = (-log($pType) * 0.10)
            + (-log($pHour) * 0.35)
            + (-log($pUserHour) * 0.35)
            + (-log($pProcess) * 0.10)
            + (-log($pExt) * 0.10);

        $score = 1.0 - exp(-$surprise / 7.5);
        $score = max(0.0, min(1.0, $score));
        $confidence = max(0.35, min(0.99, 0.35 + (min($total, 5000) / 5000) * 0.64));

        return [
            'score' => $score,
            'confidence' => $confidence,
            'details' => [
                'algorithm' => (string) ($model['algorithm'] ?? 'unknown'),
                'trained_at' => (string) ($model['trained_at'] ?? ''),
                'total_events' => $total,
                'p_type' => round($pType, 6),
                'p_hour' => round($pHour, 6),
                'p_user_hour' => round($pUserHour, 6),
                'p_process' => round($pProcess, 6),
                'p_ext' => round($pExt, 6),
            ],
        ];
    }
}
