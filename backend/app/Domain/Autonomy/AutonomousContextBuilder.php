<?php

namespace App\Domain\Autonomy;

use App\Models\CorrelatedIncident;
use App\Models\Device;
use App\Models\DeviceHealthScore;
use App\Models\DeviceRiskScore;
use App\Models\ThreatFinding;
use Illuminate\Support\Facades\DB;

class AutonomousContextBuilder
{
    /**
     * @param  array<string,mixed>  $trigger
     * @return array<string,mixed>
     */
    public function build(array $trigger): array
    {
        $finding = $this->resolveFinding($trigger);
        $incident = $this->resolveIncident($trigger);
        $device = $this->resolveDevice($trigger, $finding, $incident);
        $latestRisk = $device?->id
            ? DeviceRiskScore::query()->where('device_id', $device->id)->latest('scored_at')->first()
            : null;
        $latestHealth = $device?->id
            ? DeviceHealthScore::query()->where('device_id', $device->id)->latest('scored_at')->first()
            : null;

        $deviceGroupIds = $device?->id
            ? DB::table('device_group_memberships')->where('device_id', $device->id)->pluck('device_group_id')->values()->all()
            : [];

        $tenantId = (string) (
            $trigger['tenant_id']
            ?? $finding?->tenant_id
            ?? $incident?->tenant_id
            ?? $device?->tenant_id
            ?? ''
        ) ?: null;
        $severity = (string) (
            $trigger['severity']
            ?? $finding?->severity
            ?? $incident?->severity
            ?? $latestRisk?->severity
            ?? 'medium'
        );
        $riskScore = (float) (
            $trigger['risk_score']
            ?? $latestRisk?->score
            ?? 0
        );
        $triggerType = trim((string) (
            $trigger['trigger_type']
            ?? $finding?->finding_type
            ?? $this->inferIncidentTrigger($incident)
            ?? $trigger['trigger_source']
            ?? 'manual_evaluation'
        ));
        $deviceTags = is_array($device?->tags) ? $device->tags : [];
        $telemetryEvidence = [
            'finding_evidence' => is_array($finding?->evidence) ? $finding->evidence : [],
            'incident_root_cause' => is_array($incident?->root_cause) ? $incident->root_cause : [],
            'health_contributors' => is_array($latestHealth?->contributors) ? $latestHealth->contributors : [],
            'risk_factor_breakdown' => is_array($latestRisk?->factor_breakdown) ? $latestRisk->factor_breakdown : [],
        ];

        $successCount = 0;
        $failureCount = 0;
        if ($device?->id) {
            $successCount = DB::table('autonomous_decisions')
                ->where('device_id', $device->id)
                ->where('status', 'executed')
                ->count();
            $failureCount = DB::table('autonomous_decisions')
                ->where('device_id', $device->id)
                ->whereIn('status', ['failed', 'rolled_back'])
                ->count();
        }

        return [
            'trigger_source' => (string) ($trigger['trigger_source'] ?? $triggerType),
            'trigger_type' => $triggerType !== '' ? $triggerType : 'manual_evaluation',
            'tenant_id' => $tenantId,
            'device_id' => $device?->id,
            'device' => $device?->toArray(),
            'device_group_ids' => $deviceGroupIds,
            'finding_id' => $finding?->id,
            'finding_type' => $finding?->finding_type,
            'incident_id' => $incident?->id,
            'incident_type' => $this->inferIncidentTrigger($incident),
            'severity' => $severity,
            'risk_score' => $riskScore,
            'health_band' => (string) ($latestHealth?->band ?? 'unknown'),
            'health_score' => (float) ($latestHealth?->score ?? 0),
            'device_online' => in_array((string) ($device?->status ?? ''), ['online', 'healthy'], true),
            'device_status' => (string) ($device?->status ?? 'unknown'),
            'device_criticality' => strtolower((string) (
                data_get($deviceTags, 'criticality')
                ?? data_get($deviceTags, 'asset_criticality')
                ?? 'medium'
            )),
            'telemetry_corroboration' => $this->estimateTelemetryCorroboration($telemetryEvidence),
            'telemetry_evidence' => $telemetryEvidence,
            'recent_incident_history' => $device?->id
                ? CorrelatedIncident::query()->where('primary_device_id', $device->id)->where('opened_at', '>=', now()->subDays(14))->count()
                : 0,
            'previous_remediation_success' => $successCount,
            'previous_remediation_failure' => $failureCount,
            'policy_context' => [
                'requested_mode' => (string) ($trigger['requested_mode'] ?? ''),
                'simulation' => (bool) ($trigger['simulation'] ?? false),
                'dry_run' => (bool) ($trigger['dry_run'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $trigger
     */
    private function resolveFinding(array $trigger): ?ThreatFinding
    {
        $findingId = trim((string) ($trigger['finding_id'] ?? ''));

        return $findingId !== '' ? ThreatFinding::query()->find($findingId) : null;
    }

    /**
     * @param  array<string,mixed>  $trigger
     */
    private function resolveIncident(array $trigger): ?CorrelatedIncident
    {
        $incidentId = trim((string) ($trigger['incident_id'] ?? ''));

        return $incidentId !== '' ? CorrelatedIncident::query()->find($incidentId) : null;
    }

    /**
     * @param  array<string,mixed>  $trigger
     */
    private function resolveDevice(array $trigger, ?ThreatFinding $finding, ?CorrelatedIncident $incident): ?Device
    {
        $deviceId = trim((string) (
            $trigger['device_id']
            ?? $finding?->device_id
            ?? $incident?->primary_device_id
            ?? ''
        ));

        return $deviceId !== '' ? Device::query()->find($deviceId) : null;
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function estimateTelemetryCorroboration(array $evidence): float
    {
        $score = 0;
        foreach ($evidence as $bucket) {
            if (is_array($bucket) && $bucket !== []) {
                $score += 25;
            }
        }

        return max(0.0, min(100.0, (float) $score));
    }

    private function inferIncidentTrigger(?CorrelatedIncident $incident): ?string
    {
        if (! $incident) {
            return null;
        }

        return trim((string) (
            data_get($incident->root_cause, 'trigger_type')
            ?: data_get($incident->root_cause, 'finding_type')
            ?: 'incident_detected'
        ));
    }
}
