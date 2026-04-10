<?php

namespace App\Domain\Correlation;

use App\Models\CorrelatedIncident;
use App\Models\Device;
use App\Models\DeviceBehaviorLog;
use App\Models\IncidentTimeline;
use App\Models\ThreatFinding;
use App\Models\TimelineEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IncidentCorrelationService
{
    /**
     * @param  Collection<int,DeviceBehaviorLog>  $logs
     */
    public function syncTimeline(Device $device, Collection $logs): void
    {
        foreach ($logs as $log) {
            TimelineEvent::query()->updateOrCreate(
                [
                    'tenant_id' => $device->tenant_id,
                    'source_type' => 'behavior_log',
                    'source_ref_id' => $log->id,
                ],
                [
                    'id' => TimelineEvent::query()
                        ->where('tenant_id', $device->tenant_id)
                        ->where('source_type', 'behavior_log')
                        ->where('source_ref_id', $log->id)
                        ->value('id') ?? (string) Str::uuid(),
                    'device_id' => $device->id,
                    'event_type' => (string) $log->event_type,
                    'occurred_at' => $log->occurred_at,
                    'actor_user' => $log->user_name,
                    'session_id' => $log->session_uid ?? data_get($log->metadata ?? [], 'session_uid'),
                    'process_ref' => $log->process_uid
                        ?? data_get($log->metadata ?? [], 'process_uid')
                        ?? $log->process_name,
                    'parent_ref' => $log->parent_process_uid ?? data_get($log->metadata ?? [], 'parent_process_uid'),
                    'evidence' => [
                        'event_uid' => $log->event_uid,
                        'checkin_id' => $log->checkin_id,
                        'file_path' => $log->file_path,
                        'metadata' => $log->metadata,
                    ],
                    'risk_delta' => $this->riskDeltaForEvent((string) $log->event_type),
                ]
            );
        }
    }

    public function correlate(Device $device): ?CorrelatedIncident
    {
        $openFindings = ThreatFinding::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['open', 'investigating'])
            ->orderByDesc('severity')
            ->orderByDesc('last_seen_at')
            ->get();

        if ($openFindings->isEmpty()) {
            CorrelatedIncident::query()
                ->where('primary_device_id', $device->id)
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'closed_at' => now()]);

            return null;
        }

        $incident = CorrelatedIncident::query()
            ->where('primary_device_id', $device->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        $topFindings = $openFindings->take(3)->pluck('finding_type')->all();
        $severity = $this->incidentSeverity($openFindings);
        $confidence = round((float) $openFindings->avg('confidence'), 2);
        $summary = 'Open findings: '.implode(', ', $topFindings);

        if (! $incident) {
            $incident = CorrelatedIncident::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $device->tenant_id,
                'primary_device_id' => $device->id,
                'title' => 'Endpoint intelligence incident for '.$device->hostname,
                'summary' => $summary,
                'severity' => $severity,
                'confidence' => $confidence,
                'status' => 'open',
                'opened_at' => now(),
                'root_cause' => [
                    'top_findings' => $topFindings,
                ],
            ]);
        } else {
            $incident->update([
                'summary' => $summary,
                'severity' => $severity,
                'confidence' => $confidence,
                'root_cause' => [
                    'top_findings' => $topFindings,
                ],
            ]);
        }

        TimelineEvent::query()
            ->where('device_id', $device->id)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->update(['incident_id' => $incident->id]);

        $version = ((int) IncidentTimeline::query()->where('incident_id', $incident->id)->max('version')) + 1;
        IncidentTimeline::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $device->tenant_id,
            'incident_id' => $incident->id,
            'version' => $version,
            'summary' => $summary,
            'narrative' => $this->buildNarrative($device, $openFindings),
            'generated_by' => 'correlation',
            'generated_at' => now(),
        ]);

        return $incident;
    }

    /**
     * @param  Collection<int,ThreatFinding>  $findings
     */
    private function buildNarrative(Device $device, Collection $findings): string
    {
        $lines = [
            'Device '.$device->hostname.' has '.count($findings).' active finding(s).',
        ];

        foreach ($findings->take(5) as $finding) {
            $lines[] = sprintf(
                '[%s] %s (confidence %.2f)',
                strtoupper((string) $finding->severity),
                (string) data_get($finding->evidence ?? [], 'summary', $finding->finding_type),
                (float) $finding->confidence
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int,ThreatFinding>  $findings
     */
    private function incidentSeverity(Collection $findings): string
    {
        if ($findings->contains(fn (ThreatFinding $finding) => $finding->severity === 'critical')) {
            return 'critical';
        }
        if ($findings->contains(fn (ThreatFinding $finding) => $finding->severity === 'high')) {
            return 'high';
        }
        if ($findings->contains(fn (ThreatFinding $finding) => $finding->severity === 'medium')) {
            return 'medium';
        }

        return 'low';
    }

    private function riskDeltaForEvent(string $eventType): float
    {
        return match ($eventType) {
            'failed_login', 'login_failed' => 4.0,
            'usb_inserted', 'usb_storage_connected' => 2.0,
            'app_crash', 'blue_screen', 'bsod' => 1.5,
            'service_failure' => 1.0,
            default => 0.2,
        };
    }
}
