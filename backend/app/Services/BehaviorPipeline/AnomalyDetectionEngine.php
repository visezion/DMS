<?php

namespace App\Services\BehaviorPipeline;

use App\Models\AiEventStream;
use App\Models\BehaviorAnomalyCase;
use App\Models\BehaviorAnomalySignal;
use App\Models\DeviceBehaviorLog;
use App\Services\BehaviorPipeline\Contracts\AnomalyDetector;
use App\Services\BehaviorPipeline\Detectors\BurstFileAccessDetector;
use App\Services\BehaviorPipeline\Detectors\MultiSignalCorrelationDetector;
use App\Services\BehaviorPipeline\Detectors\OffHoursDetector;
use App\Services\BehaviorPipeline\Detectors\RareProcessDetector;
use App\Services\BehaviorPipeline\Detectors\StatisticalEnsembleDetector;

class AnomalyDetectionEngine
{
    /** @var array<int,AnomalyDetector> */
    private array $detectors;

    public function __construct(
        private readonly BehaviorPipelineSettings $settings,
        private readonly BehaviorFeatureBuilder $featureBuilder,
        StatisticalEnsembleDetector $statisticalEnsembleDetector,
        OffHoursDetector $offHoursDetector,
        RareProcessDetector $rareProcessDetector,
        MultiSignalCorrelationDetector $multiSignalCorrelationDetector,
        BurstFileAccessDetector $burstFileAccessDetector,
    ) {
        $this->detectors = [
            $statisticalEnsembleDetector,
            $offHoursDetector,
            $rareProcessDetector,
            $multiSignalCorrelationDetector,
            $burstFileAccessDetector,
        ];
    }

    public function detectAndPersist(AiEventStream $stream, DeviceBehaviorLog $event): ?BehaviorAnomalyCase
    {
        $features = $this->featureBuilder->build($event);
        $weights = $this->resolvedDetectorWeights();

        $signals = [];
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($this->detectors as $detector) {
            $result = $detector->detect($event, $features);
            $key = $detector->key();
            $weight = (float) ($weights[$key] ?? 0.0);
            $score = $this->clamp((float) ($result['score'] ?? 0.0));
            $confidence = $this->clamp((float) ($result['confidence'] ?? 0.0));
            $details = (array) ($result['details'] ?? []);

            $signals[$key] = [
                'score' => $score,
                'confidence' => $confidence,
                'weight' => $weight,
                'details' => $details,
            ];

            $weightedSum += $score * $weight;
            $weightTotal += $weight;
        }

        $rawRiskScore = $weightTotal > 0 ? $this->clamp($weightedSum / $weightTotal) : 0.0;
        $riskAdjustments = $this->trustedActivityAdjustments($features);
        $scoreDelta = (float) ($riskAdjustments['score_delta'] ?? 0.0);
        $riskScore = $this->clamp($rawRiskScore - $scoreDelta);
        $threshold = $this->clamp(
            $this->settings->settingFloat(
                'behavior.pipeline.min_risk',
                $this->settings->settingFloat('behavior.ai_threshold', 0.82)
            )
        );

        if ($riskScore < $threshold) {
            return null;
        }

        $severity = $riskScore >= 0.92 ? 'high' : ($riskScore >= 0.80 ? 'medium' : 'low');
        if (($riskAdjustments['severity_override'] ?? null) === 'low') {
            $severity = 'low';
        }
        $summary = sprintf(
            'AI pipeline detected %s anomaly for %s on device %s',
            $severity,
            (string) ($features['event_type'] ?? 'unknown'),
            (string) $event->device_id
        );
        $trustedLabels = is_array($riskAdjustments['labels'] ?? null) ? $riskAdjustments['labels'] : [];
        if ($trustedLabels !== []) {
            $summary .= ' (trusted automation pattern)';
        }

        $case = BehaviorAnomalyCase::query()->firstOrNew(['behavior_log_id' => (string) $event->id]);
        $autoApproveTrusted = $this->settings->settingBool('behavior.pipeline.auto_approve_trusted_activity', true);
        $trustedStatus = ($trustedLabels !== [] && $autoApproveTrusted) ? 'approved' : 'pending_review';
        $case->fill([
            'stream_event_id' => $stream->id,
            'device_id' => $event->device_id,
            'risk_score' => round($riskScore, 4),
            'severity' => $severity,
            'status' => $case->exists ? $case->status : $trustedStatus,
            'summary' => $summary,
            'context' => [
                'features' => $features,
                'threshold' => $threshold,
                'risk' => [
                    'raw_score' => round($rawRiskScore, 4),
                    'score_delta' => round($scoreDelta, 4),
                    'adjusted_score' => round($riskScore, 4),
                ],
                'activity_labels' => $trustedLabels,
                'trusted_activity' => [
                    'matched' => $trustedLabels !== [],
                    'auto_approved' => ! $case->exists && $trustedStatus === 'approved',
                    'explanation' => (string) ($riskAdjustments['explanation'] ?? ''),
                ],
                'detector_signals' => $signals,
            ],
            'detector_weights' => $weights,
            'detected_at' => $event->occurred_at ?? now(),
        ]);
        $case->save();

        foreach ($signals as $detectorKey => $signal) {
            BehaviorAnomalySignal::query()->updateOrCreate(
                [
                    'anomaly_case_id' => $case->id,
                    'detector_key' => $detectorKey,
                ],
                [
                    'score' => round((float) $signal['score'], 4),
                    'confidence' => round((float) $signal['confidence'], 4),
                    'weight' => round((float) $signal['weight'], 4),
                    'details' => $signal['details'],
                ]
            );
        }

        return $case;
    }

    /**
     * @return array<string,float>
     */
    private function resolvedDetectorWeights(): array
    {
        $defaultWeights = [
            'statistical_ensemble' => 0.28,
            'off_hours_profile' => 0.12,
            'rare_process_on_device' => 0.16,
            'multi_signal_correlation_window' => 0.20,
            'burst_file_access' => 0.24,
        ];

        $adaptiveModel = $this->settings->adaptiveModel();
        $adaptiveWeights = is_array($adaptiveModel['detector_weights'] ?? null)
            ? $adaptiveModel['detector_weights']
            : [];

        $weights = [];
        foreach ($defaultWeights as $key => $defaultWeight) {
            $candidate = $adaptiveWeights[$key] ?? $defaultWeight;
            $weights[$key] = max(0.01, (float) $candidate);
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            return $defaultWeights;
        }

        foreach ($weights as $key => $value) {
            $weights[$key] = $value / $sum;
        }

        return $weights;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * @param array<string,mixed> $features
     * @return array{labels:array<int,string>,score_delta:float,severity_override:?string,explanation:string}
     */
    private function trustedActivityAdjustments(array $features): array
    {
        $metadata = is_array($features['metadata'] ?? null) ? $features['metadata'] : [];
        $tags = is_array($features['tags'] ?? null) ? $features['tags'] : [];
        $actor = mb_strtolower(trim((string) ($features['actor'] ?? ($metadata['actor'] ?? ''))));
        $source = mb_strtolower(trim((string) ($metadata['source'] ?? '')));
        $userRaw = trim((string) ($features['user_name_raw'] ?? $features['user_name'] ?? ''));
        $processRaw = mb_strtolower(trim((string) ($features['process_name_raw'] ?? $features['process_name'] ?? '')));
        $processBase = basename(str_replace('\\', '/', $processRaw));
        $parentRaw = mb_strtolower(trim((string) ($features['parent_process_raw'] ?? $features['parent_process'] ?? '')));
        $parentBase = basename(str_replace('\\', '/', $parentRaw));
        $signer = mb_strtolower(trim((string) ($features['signer_raw'] ?? $features['signer'] ?? '')));
        $filePath = mb_strtolower(trim((string) ($features['file_path'] ?? '')));
        $commandLine = mb_strtolower(trim((string) ($features['command_line'] ?? '')));
        $commandSequence = is_array($features['command_sequence'] ?? null) ? $features['command_sequence'] : [];
        $sequenceText = mb_strtolower(implode(' ', array_map(fn ($s) => (string) $s, $commandSequence)));

        $trustedActorMarkers = $this->settingsList('behavior.allowlist.trusted_actor_markers', ['trusted_agent', 'dms_agent', 'dms-agent', 'managed_device_telemetry']);
        $parentAllowlist = $this->settingsList('behavior.allowlist.parent_processes', ['dmsagentservice.exe', 'dms-agent.exe', 'dms-agent-runtime.exe']);
        $pathAllowlist = $this->settingsList('behavior.allowlist.paths', ['c:\\program files\\dms agent\\', 'c:\\programdata\\dms\\']);
        $signerAllowlist = $this->settingsList('behavior.allowlist.signers', ['microsoft', 'endivex', 'ciu']);
        $sequenceAllowlist = $this->settingsList('behavior.allowlist.command_sequences', ['powershell.exe sc.exe query.exe', 'cmd.exe sc.exe quser.exe']);
        $adminChainProcesses = $this->settingsList('behavior.allowlist.admin_chain_processes', ['powershell.exe', 'cmd.exe', 'sc.exe', 'quser.exe', 'query.exe']);

        $labels = [];
        $scoreDelta = 0.0;

        $isTrustedActor = $this->containsAny($actor.' '.$source.' '.implode(' ', $tags), $trustedActorMarkers);
        if ($isTrustedActor) {
            $labels[] = 'trusted_agent_activity';
            $labels[] = 'managed_device_telemetry';
            $scoreDelta += 0.18;
        }

        $machineAccount = $userRaw !== '' && str_ends_with($userRaw, '$');
        $isAdminChainProcess = in_array($processBase, $adminChainProcesses, true) || in_array($processRaw, $adminChainProcesses, true);
        if ($isTrustedActor && $machineAccount && $isAdminChainProcess) {
            $labels[] = 'expected_admin_automation';
            $scoreDelta += 0.32;
        }

        if ($parentBase !== '' && (in_array($parentBase, $parentAllowlist, true) || $this->containsAny($parentRaw, $parentAllowlist))) {
            $labels[] = 'allowlisted_parent_process';
            $scoreDelta += 0.14;
        }

        if ($signer !== '' && $this->containsAny($signer, $signerAllowlist)) {
            $labels[] = 'allowlisted_signer';
            $scoreDelta += 0.10;
        }

        if ($filePath !== '' && $this->containsAny($filePath, $pathAllowlist)) {
            $labels[] = 'allowlisted_path';
            $scoreDelta += 0.12;
        }

        $sequenceMatch = false;
        foreach ($sequenceAllowlist as $sequence) {
            $normalized = mb_strtolower(trim((string) $sequence));
            if ($normalized === '') {
                continue;
            }
            if (($commandLine !== '' && str_contains($commandLine, $normalized))
                || ($sequenceText !== '' && str_contains($sequenceText, $normalized))) {
                $sequenceMatch = true;
                break;
            }
        }
        if ($sequenceMatch) {
            $labels[] = 'allowlisted_command_sequence';
            $scoreDelta += 0.16;
        }

        $labels = array_values(array_unique($labels));
        $scoreDelta = min(0.78, max(0.0, $scoreDelta));
        $severityOverride = in_array('expected_admin_automation', $labels, true) ? 'low' : null;

        return [
            'labels' => $labels,
            'score_delta' => $scoreDelta,
            'severity_override' => $severityOverride,
            'explanation' => $labels !== []
                ? 'Risk reduced by trusted automation/allowlist logic.'
                : '',
        ];
    }

    /**
     * @param array<int,string> $default
     * @return array<int,string>
     */
    private function settingsList(string $key, array $default): array
    {
        $raw = trim($this->settings->settingString($key, ''));
        if ($raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $items = [];
            foreach ($decoded as $item) {
                if (! is_scalar($item)) {
                    continue;
                }
                $value = mb_strtolower(trim((string) $item));
                if ($value !== '') {
                    $items[] = $value;
                }
            }
            return $items !== [] ? array_values(array_unique($items)) : $default;
        }

        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $value = mb_strtolower(trim((string) $part));
            if ($value !== '') {
                $items[] = $value;
            }
        }

        return $items !== [] ? array_values(array_unique($items)) : $default;
    }

    /**
     * @param array<int,string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack);
        foreach ($needles as $needle) {
            $needle = mb_strtolower(trim((string) $needle));
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
