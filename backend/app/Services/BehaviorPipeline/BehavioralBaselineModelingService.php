<?php

namespace App\Services\BehaviorPipeline;

use App\Models\BehaviorAnomalyCase;
use App\Models\DeviceBehaviorBaseline;
use App\Models\DeviceBehaviorDriftEvent;
use App\Models\DeviceBehaviorLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BehavioralBaselineModelingService
{
    private ?bool $baselineTableReady = null;
    private ?bool $driftTableReady = null;

    public function __construct(
        private readonly BehaviorPipelineSettings $settings,
        private readonly BehaviorFeatureBuilder $featureBuilder,
    ) {
    }

    /**
     * @param array<string,mixed> $features
     * @return array{score: float, confidence: float, details: array<string,mixed>, active: bool}
     */
    public function detectorSignal(DeviceBehaviorLog $event, array $features): array
    {
        if (! $this->isEnabled()) {
            return $this->inactiveSignal('feature_disabled');
        }
        if (! $this->hasBaselineTable()) {
            return $this->inactiveSignal('baseline_table_missing');
        }

        $baseline = DeviceBehaviorBaseline::query()->firstOrNew([
            'device_id' => (string) $event->device_id,
        ]);

        $profile = is_array($baseline->profile) ? $baseline->profile : [];
        $sampleCount = (int) ($baseline->sample_count ?? 0);
        $eventType = mb_strtolower(trim((string) ($features['event_type'] ?? 'unknown')));
        $minSamples = $this->settingInt('behavior.baseline.min_samples', 30, 5, 500);
        if ($sampleCount < $minSamples) {
            return [
                'score' => 0.0,
                'confidence' => round($this->clamp($sampleCount / max(1, $minSamples) * 0.35), 4),
                'active' => false,
                'details' => [
                    'reason' => 'baseline_warmup',
                    'event_type' => $eventType,
                    'baseline_sample_count' => $sampleCount,
                    'min_samples' => $minSamples,
                ],
            ];
        }

        $components = [];

        $processName = $this->normalizeBinaryName(
            (string) ($features['process_name_raw'] ?? $features['process_name'] ?? '')
        );
        if ($processName !== '') {
            $processCounts = is_array($profile['process_counts'] ?? null) ? $profile['process_counts'] : [];
            $processTotal = max(1, (int) ($profile['process_total'] ?? array_sum($processCounts)));
            $processCount = (int) ($processCounts[$processName] ?? 0);
            $probability = ($processCount + 1) / max(1, $processTotal + max(25, count($processCounts)));
            $score = 1.0 - exp(-(-log(max($probability, 0.000001))) / 5.0);
            if ($processCount === 0) {
                $score = max($score, 0.88);
            }
            if (! in_array($eventType, ['app_launch', 'file_access'], true)) {
                $score *= 0.45;
            }
            $components['rare_process'] = [
                'weight' => 0.24,
                'active' => true,
                'score' => round($this->clamp($score), 4),
                'details' => [
                    'process_name' => $processName,
                    'process_count' => $processCount,
                    'process_total' => $processTotal,
                    'probability' => round($probability, 6),
                ],
            ];
        }

        if ($eventType === 'user_logon') {
            $loginHistogram = is_array($profile['login_hour_histogram'] ?? null) ? $profile['login_hour_histogram'] : [];
            $loginTotal = max(0, (int) ($profile['login_total'] ?? array_sum($loginHistogram)));
            $minLoginSamples = $this->settingInt('behavior.baseline.min_login_samples', 12, 3, 300);
            if ($loginTotal >= $minLoginSamples) {
                $hour = max(0, min(23, (int) ($features['hour'] ?? 0)));
                $hourCount = (int) ($loginHistogram[(string) $hour] ?? 0);
                $probability = ($hourCount + 1) / max(1, $loginTotal + 24);
                $score = min(1.0, (-log(max($probability, 0.000001))) / 4.6);
                if ($hourCount === 0) {
                    $score = max($score, 0.86);
                }

                $components['abnormal_login_time'] = [
                    'weight' => 0.20,
                    'active' => true,
                    'score' => round($this->clamp($score), 4),
                    'details' => [
                        'observed_hour' => $hour,
                        'hour_count' => $hourCount,
                        'login_total' => $loginTotal,
                        'probability' => round($probability, 6),
                    ],
                ];
            }
        }

        if ($eventType === 'app_launch') {
            $appName = $this->normalizeBinaryName((string) ($features['app_name'] ?? $processName));
            if ($appName !== '') {
                $appCounts = is_array($profile['app_counts'] ?? null) ? $profile['app_counts'] : [];
                $appTotal = max(1, (int) ($profile['app_total'] ?? array_sum($appCounts)));
                $appCount = (int) ($appCounts[$appName] ?? 0);
                $probability = ($appCount + 1) / max(1, $appTotal + max(20, count($appCounts)));
                $score = $appCount === 0
                    ? 0.95
                    : $this->clamp((0.10 - $probability) / 0.10);

                $components['new_application'] = [
                    'weight' => 0.20,
                    'active' => true,
                    'score' => round($this->clamp($score), 4),
                    'details' => [
                        'application' => $appName,
                        'app_count' => $appCount,
                        'app_total' => $appTotal,
                        'probability' => round($probability, 6),
                    ],
                ];
            }
        }

        $numericStats = is_array($profile['numeric_stats'] ?? null) ? $profile['numeric_stats'] : [];
        $networkSignals = $this->extractNetworkSignals((array) ($features['metadata'] ?? []));
        $networkComponent = $this->numericDriftComponent(
            $numericStats,
            $networkSignals,
            'network',
            $this->settingInt('behavior.baseline.min_numeric_samples', 20, 5, 600)
        );
        if ((bool) ($networkComponent['active'] ?? false)) {
            $components['unusual_network_usage'] = [
                'weight' => 0.18,
                'active' => true,
                'score' => (float) ($networkComponent['score'] ?? 0.0),
                'details' => (array) ($networkComponent['details'] ?? []),
            ];
        }

        $resourceSignals = $this->extractResourceSignals((array) ($features['metadata'] ?? []));
        $resourceComponent = $this->numericDriftComponent(
            $numericStats,
            $resourceSignals,
            'resource',
            $this->settingInt('behavior.baseline.min_numeric_samples', 20, 5, 600)
        );
        if ((bool) ($resourceComponent['active'] ?? false)) {
            $components['abnormal_cpu_memory'] = [
                'weight' => 0.18,
                'active' => true,
                'score' => (float) ($resourceComponent['score'] ?? 0.0),
                'details' => (array) ($resourceComponent['details'] ?? []),
            ];
        }

        $weightedSum = 0.0;
        $weightTotal = 0.0;
        $activeComponents = 0;
        foreach ($components as $component) {
            $active = (bool) ($component['active'] ?? false);
            if (! $active) {
                continue;
            }

            $weight = max(0.0, (float) ($component['weight'] ?? 0.0));
            $score = $this->clamp((float) ($component['score'] ?? 0.0));
            $weightedSum += $score * $weight;
            $weightTotal += $weight;
            $activeComponents++;
        }

        if ($activeComponents === 0 || $weightTotal <= 0.0) {
            return [
                'score' => 0.0,
                'confidence' => round($this->clamp(($sampleCount / max(1, $minSamples)) * 0.5), 4),
                'active' => false,
                'details' => [
                    'reason' => 'no_comparable_signal_for_event',
                    'event_type' => $eventType,
                    'baseline_sample_count' => $sampleCount,
                    'components' => $components,
                ],
            ];
        }

        $score = $this->clamp($weightedSum / $weightTotal);
        $categoryThreshold = $this->settings->settingFloat('behavior.baseline.category_drift_threshold', 0.70);
        $driftCategories = [];
        foreach ($components as $key => $component) {
            if ((bool) ($component['active'] ?? false) && (float) ($component['score'] ?? 0.0) >= $categoryThreshold) {
                $driftCategories[] = $key;
            }
        }

        $maturity = min(1.0, $sampleCount / 150.0);
        $coverage = min(1.0, $activeComponents / 5.0);
        $confidence = $this->clamp(0.25 + ($maturity * 0.45) + ($coverage * 0.30));

        return [
            'score' => round($score, 4),
            'confidence' => round($confidence, 4),
            'active' => true,
            'details' => [
                'event_type' => $eventType,
                'baseline_sample_count' => $sampleCount,
                'active_components' => $activeComponents,
                'drift_categories' => $driftCategories,
                'components' => $components,
            ],
        ];
    }

    public function ingestOutcome(DeviceBehaviorLog $event, ?BehaviorAnomalyCase $case): void
    {
        if (! $this->isEnabled() || ! $this->hasBaselineTable()) {
            return;
        }

        $features = $this->featureBuilder->build($event);
        $signal = $this->signalFromCase($case) ?? $this->detectorSignal($event, $features);
        $this->persistDriftEvent($event, $case, $signal);
        $this->learnBaseline($event, $features);
    }

    private function isEnabled(): bool
    {
        return $this->settings->settingBool('behavior.baseline.enabled', false);
    }

    /**
     * @param array<string,mixed> $signal
     */
    private function persistDriftEvent(DeviceBehaviorLog $event, ?BehaviorAnomalyCase $case, array $signal): void
    {
        if (! $this->hasDriftTable()) {
            return;
        }

        $isActive = (bool) ($signal['active'] ?? false);
        if (! $isActive) {
            return;
        }

        $score = $this->clamp((float) ($signal['score'] ?? 0.0));
        $threshold = $this->settings->settingFloat('behavior.baseline.drift_event_threshold', 0.68);
        if ($score < $threshold) {
            return;
        }

        $details = is_array($signal['details'] ?? null) ? $signal['details'] : [];
        $categories = is_array($details['drift_categories'] ?? null) ? $details['drift_categories'] : [];

        $severity = 'low';
        if ($score >= 0.86) {
            $severity = 'high';
        } elseif ($score >= 0.72) {
            $severity = 'medium';
        }

        DeviceBehaviorDriftEvent::query()->updateOrCreate(
            ['behavior_log_id' => (string) $event->id],
            [
                'device_id' => (string) $event->device_id,
                'anomaly_case_id' => $case?->id,
                'drift_score' => round($score, 4),
                'severity' => $severity,
                'drift_categories' => $categories,
                'details' => [
                    'confidence' => round($this->clamp((float) ($signal['confidence'] ?? 0.0)), 4),
                    'signal_details' => $details,
                ],
                'detected_at' => $event->occurred_at ?? now(),
            ]
        );
    }

    /**
     * @param array<string,mixed> $features
     */
    private function learnBaseline(DeviceBehaviorLog $event, array $features): void
    {
        $baseline = DeviceBehaviorBaseline::query()->firstOrNew([
            'device_id' => (string) $event->device_id,
        ]);

        $profile = is_array($baseline->profile) ? $baseline->profile : [];
        $eventType = mb_strtolower(trim((string) ($features['event_type'] ?? 'unknown')));
        $profile['event_total'] = max(0, (int) ($profile['event_total'] ?? 0)) + 1;
        $eventTypeCounts = is_array($profile['event_type_counts'] ?? null) ? $profile['event_type_counts'] : [];
        $eventTypeCounts[$eventType] = max(0, (int) ($eventTypeCounts[$eventType] ?? 0)) + 1;
        $profile['event_type_counts'] = $eventTypeCounts;

        $maxBins = $this->settingInt('behavior.baseline.max_category_bins', 240, 30, 1000);
        $processName = $this->normalizeBinaryName(
            (string) ($features['process_name_raw'] ?? $features['process_name'] ?? '')
        );
        if ($processName !== '') {
            $processCounts = is_array($profile['process_counts'] ?? null) ? $profile['process_counts'] : [];
            $processCounts[$processName] = max(0, (int) ($processCounts[$processName] ?? 0)) + 1;
            $profile['process_counts'] = $this->pruneCounters($processCounts, $maxBins);
            $profile['process_total'] = max(0, (int) ($profile['process_total'] ?? 0)) + 1;
        }

        if ($eventType === 'app_launch') {
            $appName = $this->normalizeBinaryName((string) ($features['app_name'] ?? $processName));
            if ($appName !== '') {
                $appCounts = is_array($profile['app_counts'] ?? null) ? $profile['app_counts'] : [];
                $appCounts[$appName] = max(0, (int) ($appCounts[$appName] ?? 0)) + 1;
                $profile['app_counts'] = $this->pruneCounters($appCounts, $maxBins);
                $profile['app_total'] = max(0, (int) ($profile['app_total'] ?? 0)) + 1;
            }
        }

        if ($eventType === 'user_logon') {
            $hour = max(0, min(23, (int) ($features['hour'] ?? 0)));
            $loginHistogram = is_array($profile['login_hour_histogram'] ?? null) ? $profile['login_hour_histogram'] : [];
            $hourKey = (string) $hour;
            $loginHistogram[$hourKey] = max(0, (int) ($loginHistogram[$hourKey] ?? 0)) + 1;
            $profile['login_hour_histogram'] = $loginHistogram;
            $profile['login_total'] = max(0, (int) ($profile['login_total'] ?? 0)) + 1;
        }

        $numericStats = is_array($profile['numeric_stats'] ?? null) ? $profile['numeric_stats'] : [];
        $metadata = is_array($features['metadata'] ?? null) ? $features['metadata'] : [];

        foreach ($this->extractNetworkSignals($metadata) as $metric => $value) {
            $numericStats[$metric] = $this->updateRunningStat(
                is_array($numericStats[$metric] ?? null) ? $numericStats[$metric] : [],
                (float) $value
            );
        }
        foreach ($this->extractResourceSignals($metadata) as $metric => $value) {
            $numericStats[$metric] = $this->updateRunningStat(
                is_array($numericStats[$metric] ?? null) ? $numericStats[$metric] : [],
                (float) $value
            );
        }

        $profile['numeric_stats'] = $numericStats;
        $baseline->profile = $profile;
        $baseline->sample_count = max(0, (int) ($baseline->sample_count ?? 0)) + 1;
        $baseline->last_event_at = $event->occurred_at ?? now();
        $baseline->last_model_update_at = now();
        $baseline->save();
    }

    /**
     * @return array{score: float, confidence: float, details: array<string,mixed>, active: bool}|null
     */
    private function signalFromCase(?BehaviorAnomalyCase $case): ?array
    {
        if (! $case) {
            return null;
        }

        $context = is_array($case->context) ? $case->context : [];
        $signals = is_array($context['detector_signals'] ?? null) ? $context['detector_signals'] : [];
        $signal = $signals['behavioral_baseline_drift'] ?? null;
        if (! is_array($signal)) {
            return null;
        }

        return [
            'score' => round($this->clamp((float) ($signal['score'] ?? 0.0)), 4),
            'confidence' => round($this->clamp((float) ($signal['confidence'] ?? 0.0)), 4),
            'details' => is_array($signal['details'] ?? null) ? $signal['details'] : [],
            'active' => (bool) ($signal['active'] ?? true),
        ];
    }

    /**
     * @param array<string,mixed> $numericStats
     * @param array<string,float> $metricValues
     * @return array{score: float, active: bool, details: array<string,mixed>}
     */
    private function numericDriftComponent(array $numericStats, array $metricValues, string $prefix, int $minSamples): array
    {
        $metricScores = [];
        $metricDetails = [];

        foreach ($metricValues as $metric => $value) {
            $stat = is_array($numericStats[$metric] ?? null) ? $numericStats[$metric] : null;
            $anomaly = $this->runningStatAnomalyScore($stat, (float) $value, $minSamples);
            if (! (bool) ($anomaly['active'] ?? false)) {
                continue;
            }

            $metricScores[] = (float) ($anomaly['score'] ?? 0.0);
            $metricDetails[$metric] = [
                'value' => round((float) $value, 4),
                'score' => round((float) ($anomaly['score'] ?? 0.0), 4),
                'z_score' => isset($anomaly['z_score']) ? round((float) $anomaly['z_score'], 4) : null,
                'mean' => isset($anomaly['mean']) ? round((float) $anomaly['mean'], 4) : null,
                'std_dev' => isset($anomaly['std_dev']) ? round((float) $anomaly['std_dev'], 4) : null,
                'sample_count' => (int) ($anomaly['sample_count'] ?? 0),
            ];
        }

        if ($metricScores === []) {
            return [
                'score' => 0.0,
                'active' => false,
                'details' => [
                    'reason' => 'no_numeric_baseline',
                    'prefix' => $prefix,
                ],
            ];
        }

        return [
            'score' => round(array_sum($metricScores) / count($metricScores), 4),
            'active' => true,
            'details' => [
                'prefix' => $prefix,
                'metrics' => $metricDetails,
            ],
        ];
    }

    /**
     * @param array<string,mixed>|null $stat
     * @return array{active: bool, score: float, z_score?: float, mean?: float, std_dev?: float, sample_count?: int}
     */
    private function runningStatAnomalyScore(?array $stat, float $value, int $minSamples): array
    {
        if (! is_array($stat)) {
            return ['active' => false, 'score' => 0.0];
        }

        $count = max(0, (int) ($stat['count'] ?? 0));
        if ($count < $minSamples) {
            return ['active' => false, 'score' => 0.0, 'sample_count' => $count];
        }

        $mean = (float) ($stat['mean'] ?? 0.0);
        $m2 = max(0.0, (float) ($stat['m2'] ?? 0.0));
        $variance = $count > 1 ? ($m2 / max(1, $count - 1)) : 0.0;
        $stdDev = sqrt(max(0.0, $variance));

        if ($stdDev < 0.0001) {
            if (abs($mean) < 0.0001) {
                $score = min(1.0, $value / 10.0);
                return [
                    'active' => true,
                    'score' => $this->clamp($score),
                    'mean' => $mean,
                    'std_dev' => $stdDev,
                    'sample_count' => $count,
                ];
            }

            $ratio = abs($value - $mean) / max(0.0001, abs($mean));
            $score = min(1.0, $ratio / 2.25);
            return [
                'active' => true,
                'score' => $this->clamp($score),
                'mean' => $mean,
                'std_dev' => $stdDev,
                'sample_count' => $count,
            ];
        }

        $zScore = abs($value - $mean) / $stdDev;
        $score = min(1.0, $zScore / 3.5);

        return [
            'active' => true,
            'score' => $this->clamp($score),
            'z_score' => $zScore,
            'mean' => $mean,
            'std_dev' => $stdDev,
            'sample_count' => $count,
        ];
    }

    /**
     * @param array<string,mixed> $stat
     * @return array<string,mixed>
     */
    private function updateRunningStat(array $stat, float $value): array
    {
        $count = max(0, (int) ($stat['count'] ?? 0));
        $mean = (float) ($stat['mean'] ?? 0.0);
        $m2 = max(0.0, (float) ($stat['m2'] ?? 0.0));

        $count++;
        $delta = $value - $mean;
        $mean += $delta / $count;
        $delta2 = $value - $mean;
        $m2 += $delta * $delta2;

        return [
            'count' => $count,
            'mean' => round($mean, 8),
            'm2' => round($m2, 8),
            'min' => $count === 1 ? $value : min((float) ($stat['min'] ?? $value), $value),
            'max' => $count === 1 ? $value : max((float) ($stat['max'] ?? $value), $value),
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,float>
     */
    private function extractNetworkSignals(array $metadata): array
    {
        $metrics = [
            'network.bytes_sent' => [
                'network_bytes_sent',
                'bytes_sent',
                'tx_bytes',
                'network.bytes_sent',
                'network.bytes.tx',
            ],
            'network.bytes_received' => [
                'network_bytes_received',
                'bytes_received',
                'rx_bytes',
                'network.bytes_received',
                'network.bytes.rx',
            ],
            'network.connection_count' => [
                'network_connection_count',
                'connection_count',
                'network_connections',
                'network.connection_count',
            ],
        ];

        return $this->extractMetricMap($metadata, $metrics);
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,float>
     */
    private function extractResourceSignals(array $metadata): array
    {
        $metrics = [
            'resource.cpu_percent' => [
                'cpu_percent',
                'cpu_usage',
                'cpu',
                'resource.cpu_percent',
                'system.cpu.percent',
            ],
            'resource.memory_mb' => [
                'memory_mb',
                'memory_usage_mb',
                'memory.used_mb',
                'resource.memory_mb',
                'system.memory.mb',
            ],
            'resource.memory_percent' => [
                'memory_percent',
                'memory_usage_percent',
                'resource.memory_percent',
                'system.memory.percent',
            ],
        ];

        return $this->extractMetricMap($metadata, $metrics);
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,array<int,string>> $definitions
     * @return array<string,float>
     */
    private function extractMetricMap(array $metadata, array $definitions): array
    {
        $collected = [];
        foreach ($definitions as $target => $paths) {
            $value = $this->extractNumericFromMetadata($metadata, $paths);
            if ($value === null) {
                continue;
            }
            $collected[$target] = $value;
        }

        return $collected;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<int,string> $paths
     */
    private function extractNumericFromMetadata(array $metadata, array $paths): ?float
    {
        foreach ($paths as $path) {
            if (array_key_exists($path, $metadata) && is_numeric($metadata[$path])) {
                $directValue = (float) $metadata[$path];
                if ($directValue >= 0) {
                    return $directValue;
                }
            }

            $node = $metadata;
            $ok = true;
            foreach (explode('.', $path) as $segment) {
                if (is_array($node) && array_key_exists($segment, $node)) {
                    $node = $node[$segment];
                } else {
                    $ok = false;
                    break;
                }
            }

            if (! $ok || ! is_numeric($node)) {
                continue;
            }

            $value = (float) $node;
            if ($value < 0) {
                continue;
            }
            return $value;
        }

        return null;
    }

    /**
     * @param array<string,int> $counters
     * @return array<string,int>
     */
    private function pruneCounters(array $counters, int $maxBins): array
    {
        if (count($counters) <= $maxBins) {
            return $counters;
        }

        arsort($counters);
        return array_slice($counters, 0, $maxBins, true);
    }

    private function normalizeBinaryName(string $value): string
    {
        $clean = trim($value, " \t\n\r\0\x0B\"'");
        if ($clean === '' || $clean === 'unknown') {
            return '';
        }

        $base = basename(str_replace('\\', '/', mb_strtolower($clean)));
        return trim($base) !== '' ? trim($base) : '';
    }

    /**
     * @return array{score: float, confidence: float, details: array<string,mixed>, active: bool}
     */
    private function inactiveSignal(string $reason): array
    {
        return [
            'score' => 0.0,
            'confidence' => 0.0,
            'active' => false,
            'details' => ['reason' => $reason],
        ];
    }

    private function settingInt(string $key, int $default, int $min, int $max): int
    {
        $value = (int) round($this->settings->settingFloat($key, (float) $default));
        return max($min, min($max, $value));
    }

    private function hasBaselineTable(): bool
    {
        if ($this->baselineTableReady !== null) {
            return $this->baselineTableReady;
        }

        try {
            $this->baselineTableReady = Schema::hasTable('device_behavior_baselines');
        } catch (\Throwable $e) {
            Log::warning('Baseline table check failed.', ['error' => $e->getMessage()]);
            $this->baselineTableReady = false;
        }

        return $this->baselineTableReady;
    }

    private function hasDriftTable(): bool
    {
        if ($this->driftTableReady !== null) {
            return $this->driftTableReady;
        }

        try {
            $this->driftTableReady = Schema::hasTable('device_behavior_drift_events');
        } catch (\Throwable $e) {
            Log::warning('Baseline drift table check failed.', ['error' => $e->getMessage()]);
            $this->driftTableReady = false;
        }

        return $this->driftTableReady;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
