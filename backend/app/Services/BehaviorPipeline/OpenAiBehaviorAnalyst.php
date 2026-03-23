<?php

namespace App\Services\BehaviorPipeline;

use App\Models\DeviceBehaviorLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiBehaviorAnalyst
{
    /**
     * @param array<string,mixed> $features
     * @param array<string,mixed> $detectorSignals
     * @return array{
     *   classification:string,
     *   confidence:float,
     *   recommended_action:string,
     *   risk_adjustment:float,
     *   summary:string,
     *   behavior_markers:array<int,string>,
     *   model:string,
     *   generated_at:string
     * }|null
     */
    public function analyze(
        DeviceBehaviorLog $event,
        array $features,
        array $detectorSignals,
        float $riskScore,
        float $threshold,
    ): ?array {
        if (! $this->enabled()) {
            return null;
        }

        $apiKey = trim((string) config('services.openai.api_key', ''));
        $baseUrl = trim((string) config('services.openai.base_url', 'https://api.openai.com/v1'));
        $model = trim((string) config('services.openai.model', 'gpt-4o-mini'));
        $timeout = max(5, min(30, (int) config('services.openai.timeout_seconds', 12)));

        $payload = [
            'current_event' => [
                'device_id' => (string) $event->device_id,
                'event_type' => (string) ($features['event_type'] ?? $event->event_type ?? 'unknown'),
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                'user_name' => (string) ($features['user_name_raw'] ?? $event->user_name ?? ''),
                'process_name' => (string) ($features['process_name_raw'] ?? $event->process_name ?? ''),
                'file_path' => (string) ($features['file_path'] ?? $event->file_path ?? ''),
                'metadata' => is_array($event->metadata) ? $event->metadata : [],
            ],
            'feature_snapshot' => [
                'hour' => (int) ($features['hour'] ?? 0),
                'day_of_week' => (int) ($features['day_of_week'] ?? 0),
                'is_machine_account' => (bool) ($features['is_machine_account'] ?? false),
                'tags' => array_values(array_filter((array) ($features['tags'] ?? []), fn ($v) => is_scalar($v))),
            ],
            'detector_signals' => $this->compactDetectorSignals($detectorSignals),
            'current_risk_score' => round($this->clamp($riskScore), 4),
            'threshold' => round($this->clamp($threshold), 4),
            'device_history_summary' => $this->buildHistorySummary((string) $event->device_id, (string) $event->id),
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a SOC behavior analyst for Windows device telemetry. Respond with a single JSON object only.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->analysisPrompt($payload),
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAI behavior analyst request failed.', [
                    'status' => $response->status(),
                    'model' => $model,
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                return null;
            }

            $raw = data_get($response->json(), 'choices.0.message.content');
            if (is_array($raw)) {
                $raw = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (! is_string($raw) || trim($raw) === '') {
                return null;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return null;
            }

            return $this->normalizeAnalysis($decoded, $model);
        } catch (\Throwable $e) {
            Log::warning('OpenAI behavior analyst exception.', [
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }
    }

    private function enabled(): bool
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));
        $flag = (bool) config('services.openai.behavior_analyst_enabled', true);

        return $flag && $apiKey !== '';
    }

    /**
     * @param array<string,mixed> $detectorSignals
     * @return array<string,array{score:float,active:bool}>
     */
    private function compactDetectorSignals(array $detectorSignals): array
    {
        $compact = [];
        foreach ($detectorSignals as $key => $signal) {
            if (! is_array($signal)) {
                continue;
            }
            $compact[(string) $key] = [
                'score' => round($this->clamp((float) ($signal['score'] ?? 0.0)), 4),
                'active' => ! array_key_exists('active', $signal) || (bool) $signal['active'],
            ];
        }

        return $compact;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildHistorySummary(string $deviceId, string $currentEventId): array
    {
        $limit = max(20, min(300, (int) config('services.openai.behavior_history_events', 150)));
        $history = DeviceBehaviorLog::query()
            ->where('device_id', $deviceId)
            ->where('id', '!=', $currentEventId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['event_type', 'occurred_at', 'user_name', 'process_name', 'file_path', 'metadata']);

        if ($history->isEmpty()) {
            return [
                'events_analyzed' => 0,
                'event_type_distribution' => [],
                'common_processes' => [],
                'active_hours_utc' => [],
                'recent_samples' => [],
            ];
        }

        $eventTypeDistribution = $history
            ->groupBy(fn (DeviceBehaviorLog $log) => (string) ($log->event_type ?? 'unknown'))
            ->map(fn (Collection $items) => $items->count())
            ->sortDesc()
            ->take(6)
            ->toArray();

        $commonProcesses = $history
            ->map(fn (DeviceBehaviorLog $log) => mb_strtolower(trim((string) ($log->process_name ?? ''))))
            ->filter(fn (string $process) => $process !== '')
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->toArray();

        $activeHours = $history
            ->map(function (DeviceBehaviorLog $log): ?int {
                if (! $log->occurred_at) {
                    return null;
                }

                return (int) $log->occurred_at->hour;
            })
            ->filter(fn ($hour) => is_int($hour))
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->toArray();

        $recentSamples = $history
            ->take(15)
            ->map(function (DeviceBehaviorLog $log): array {
                return [
                    'event_type' => (string) ($log->event_type ?? 'unknown'),
                    'occurred_at' => optional($log->occurred_at)->toIso8601String(),
                    'user_name' => trim((string) ($log->user_name ?? '')),
                    'process_name' => trim((string) ($log->process_name ?? '')),
                    'file_path' => trim((string) ($log->file_path ?? '')),
                    'tags' => $this->extractTags($log->metadata),
                ];
            })
            ->values()
            ->all();

        return [
            'events_analyzed' => $history->count(),
            'event_type_distribution' => $eventTypeDistribution,
            'common_processes' => $commonProcesses,
            'active_hours_utc' => $activeHours,
            'recent_samples' => $recentSamples,
        ];
    }

    /**
     * @param mixed $metadata
     * @return array<int,string>
     */
    private function extractTags(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $tags = $metadata['tags'] ?? [];
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function ($tag): string {
            if (! is_scalar($tag)) {
                return '';
            }

            return trim((string) $tag);
        }, $tags), fn (string $tag) => $tag !== '')));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function analysisPrompt(array $payload): string
    {
        return implode("\n", [
            'Analyze whether the current event behavior is normal or anomalous for this specific device.',
            'Use device_history_summary and detector_signals as primary evidence.',
            'Output strict JSON with keys:',
            'classification (normal|suspicious|malicious|inconclusive),',
            'confidence (0..1),',
            'recommended_action (observe|notify|apply_policy),',
            'risk_adjustment (-0.35..0.35, positive increases risk, negative decreases risk),',
            'summary (max 220 chars),',
            'behavior_markers (array of concise strings).',
            'No markdown, no additional keys, no prose.',
            'Input JSON:',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{
     *   classification:string,
     *   confidence:float,
     *   recommended_action:string,
     *   risk_adjustment:float,
     *   summary:string,
     *   behavior_markers:array<int,string>,
     *   model:string,
     *   generated_at:string
     * }
     */
    private function normalizeAnalysis(array $decoded, string $model): array
    {
        $classification = mb_strtolower(trim((string) ($decoded['classification'] ?? 'inconclusive')));
        if (! in_array($classification, ['normal', 'suspicious', 'malicious', 'inconclusive'], true)) {
            $classification = 'inconclusive';
        }

        $recommendedAction = mb_strtolower(trim((string) ($decoded['recommended_action'] ?? 'observe')));
        if (! in_array($recommendedAction, ['observe', 'notify', 'apply_policy'], true)) {
            $recommendedAction = 'observe';
        }

        $riskAdjustment = (float) ($decoded['risk_adjustment'] ?? 0.0);
        $riskAdjustment = max(-0.35, min(0.35, $riskAdjustment));

        $confidence = $this->clamp((float) ($decoded['confidence'] ?? 0.0));
        $summary = trim((string) ($decoded['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'No OpenAI narrative provided.';
        }
        $summary = mb_substr($summary, 0, 220);

        $markers = array_values(array_filter(array_map(function ($item): string {
            if (! is_scalar($item)) {
                return '';
            }
            return mb_substr(trim((string) $item), 0, 120);
        }, Arr::wrap($decoded['behavior_markers'] ?? [])), fn (string $item) => $item !== ''));

        return [
            'classification' => $classification,
            'confidence' => round($confidence, 4),
            'recommended_action' => $recommendedAction,
            'risk_adjustment' => round($riskAdjustment, 4),
            'summary' => $summary,
            'behavior_markers' => array_slice($markers, 0, 8),
            'model' => $model,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}

