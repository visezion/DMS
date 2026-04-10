<?php

namespace App\Jobs;

use App\Domain\Common\DeviceTelemetryDataBuilder;
use App\Domain\Correlation\IncidentCorrelationService;
use App\Domain\Health\HealthScoringService;
use App\Domain\Risk\RiskScoringService;
use App\Models\Device;
use App\Models\DeviceHealthScore;
use App\Models\DeviceHealthSnapshot;
use App\Models\DeviceRiskScore;
use App\Models\FeatureSnapshot;
use App\Models\ThreatFinding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BuildDeviceIntelligenceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $deviceId
    ) {
        $this->onQueue('health_compute');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('build-device-intelligence:'.$this->deviceId))
                ->expireAfter(300)
                ->releaseAfter(10),
        ];
    }

    public function handle(
        DeviceTelemetryDataBuilder $builder,
        HealthScoringService $healthScoring,
        RiskScoringService $riskScoring,
        IncidentCorrelationService $correlation
    ): void {
        $device = Device::query()->find($this->deviceId);
        if (! $device) {
            return;
        }

        $telemetry = $builder->build($device);
        $snapshot = DeviceHealthSnapshot::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'snapshot_at' => $telemetry['snapshot_at'],
            'source' => 'derived',
            'metrics' => $telemetry['metrics'],
            'raw_payload' => $telemetry['raw_payload'],
            'ingest_version' => 'v1',
        ]);

        $health = $healthScoring->score($telemetry['metrics']);
        DeviceHealthScore::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'snapshot_id' => $snapshot->id,
            'score' => $health['score'],
            'band' => $health['band'],
            'component_scores' => $health['component_scores'],
            'contributors' => $health['contributors'],
            'predicted_failure_risk' => $health['predicted_failure_risk'],
            'scored_at' => now(),
        ]);

        $risk = $riskScoring->score($telemetry['metrics']);
        DeviceRiskScore::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'score' => $risk['score'],
            'severity' => $risk['severity'],
            'factor_breakdown' => $risk['factor_breakdown'],
            'confidence' => $risk['confidence'],
            'scored_at' => now(),
        ]);

        $observedFingerprints = collect($risk['findings'])
            ->map(fn (array $finding): string => (string) ($finding['fingerprint'] ?? ''))
            ->filter(fn (string $fingerprint): bool => $fingerprint !== '')
            ->unique()
            ->values();

        $this->upsertCurrentFindings($device, $risk['findings']);
        $this->resolveStaleFindings($device, $observedFingerprints);

        FeatureSnapshot::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'window_start' => now()->subDay(),
            'window_end' => now(),
            'features' => $telemetry['features'],
            'labels' => [
                'health_band' => $health['band'],
                'risk_severity' => $risk['severity'],
                'telemetry_sections' => array_keys(array_filter(
                    is_array(data_get($telemetry, 'raw_payload.telemetry_coverage')) ? data_get($telemetry, 'raw_payload.telemetry_coverage') : [],
                    fn ($present) => $present === true
                )),
            ],
        ]);

        $correlation->syncTimeline($device, $telemetry['recent_logs']);
        $correlation->correlate($device);
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function upsertCurrentFindings(Device $device, array $findings): void
    {
        $seenAt = now();

        foreach ($findings as $finding) {
            $fingerprint = (string) ($finding['fingerprint'] ?? '');
            if ($fingerprint === '') {
                continue;
            }

            $existing = ThreatFinding::query()
                ->where('tenant_id', $device->tenant_id)
                ->where('device_id', $device->id)
                ->where('fingerprint', $fingerprint)
                ->whereIn('status', ['open', 'investigating'])
                ->first();

            $payload = [
                'session_id' => null,
                'finding_type' => (string) ($finding['finding_type'] ?? 'unknown'),
                'severity' => (string) ($finding['severity'] ?? 'low'),
                'confidence' => (float) ($finding['confidence'] ?? 0),
                'mitre_tactic' => null,
                'mitre_technique' => null,
                'evidence' => array_merge(
                    is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [],
                    ['summary' => (string) ($finding['summary'] ?? '')]
                ),
                'last_seen_at' => $seenAt,
                'status' => $existing?->status === 'investigating' ? 'investigating' : 'open',
            ];

            if ($existing) {
                $existing->update($payload);

                continue;
            }

            ThreatFinding::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'fingerprint' => $fingerprint,
                'first_seen_at' => $seenAt,
                ...$payload,
            ]);
        }
    }

    /**
     * @param  Collection<int,string>  $observedFingerprints
     */
    private function resolveStaleFindings(Device $device, Collection $observedFingerprints): void
    {
        $staleMinutes = max(1, (int) config('services.endpoint_intelligence.finding_stale_minutes', 30));
        $cutoff = now()->subMinutes($staleMinutes);

        $staleQuery = ThreatFinding::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->whereIn('status', ['open', 'investigating'])
            ->where('last_seen_at', '<=', $cutoff);

        if ($observedFingerprints->isNotEmpty()) {
            $staleQuery->whereNotIn('fingerprint', $observedFingerprints->all());
        }

        $staleFindings = $staleQuery->get();
        foreach ($staleFindings as $finding) {
            $evidence = is_array($finding->evidence) ? $finding->evidence : [];
            $evidence['resolution'] = 'Resolved automatically after finding signal was not observed in current scoring window.';

            $finding->update([
                'status' => 'resolved',
                'last_seen_at' => now(),
                'reviewed_at' => now(),
                'evidence' => $evidence,
            ]);
        }
    }
}
