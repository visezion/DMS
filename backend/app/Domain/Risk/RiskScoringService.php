<?php

namespace App\Domain\Risk;

class RiskScoringService
{
    public function score(array $metrics): array
    {
        $score = 0.0;
        $factorBreakdown = [];
        $findings = [];

        $addFactor = function (string $key, float $contribution, array $finding = []) use (&$score, &$factorBreakdown, &$findings): void {
            $score += $contribution;
            $factorBreakdown[$key] = round($contribution, 2);
            if ($finding !== []) {
                $findings[] = $finding;
            }
        };

        $failedLogins = (int) ($metrics['failed_logins_24h'] ?? 0);
        if ($failedLogins >= 5) {
            $severity = $failedLogins >= 10 ? 'high' : 'medium';
            $addFactor('failed_logins', min(22, $failedLogins * 2.2), [
                'finding_type' => 'failed_login_burst',
                'severity' => $severity,
                'confidence' => 0.82,
                'summary' => 'Repeated failed logins detected.',
                'evidence' => ['failed_logins_24h' => $failedLogins],
                'fingerprint' => 'failed_login_burst',
            ]);
        }

        $suspiciousPowerShell = (int) ($metrics['suspicious_powershell_24h'] ?? 0);
        if ($suspiciousPowerShell > 0) {
            $addFactor('suspicious_powershell', min(26, 12 + ($suspiciousPowerShell * 6)), [
                'finding_type' => 'suspicious_powershell',
                'severity' => 'high',
                'confidence' => 0.9,
                'summary' => 'Suspicious PowerShell execution chain detected.',
                'evidence' => ['suspicious_powershell_24h' => $suspiciousPowerShell],
                'fingerprint' => 'suspicious_powershell',
            ]);
        }

        if (! (bool) ($metrics['defender_enabled'] ?? true)) {
            $addFactor('defender_disabled', 20, [
                'finding_type' => 'defender_disabled',
                'severity' => 'high',
                'confidence' => 0.95,
                'summary' => 'Microsoft Defender is disabled.',
                'evidence' => ['defender_enabled' => false],
                'fingerprint' => 'defender_disabled',
            ]);
        }

        if (! (bool) ($metrics['firewall_enabled'] ?? true)) {
            $addFactor('firewall_disabled', 14, [
                'finding_type' => 'firewall_disabled',
                'severity' => 'medium',
                'confidence' => 0.9,
                'summary' => 'Firewall protection is disabled.',
                'evidence' => ['firewall_enabled' => false],
                'fingerprint' => 'firewall_disabled',
            ]);
        }

        if (! (bool) ($metrics['bitlocker_enabled'] ?? true)) {
            $addFactor('bitlocker_disabled', 12, [
                'finding_type' => 'bitlocker_disabled',
                'severity' => 'medium',
                'confidence' => 0.86,
                'summary' => 'Disk encryption is disabled.',
                'evidence' => ['bitlocker_enabled' => false],
                'fingerprint' => 'bitlocker_disabled',
            ]);
        }

        $patchGapCount = (int) ($metrics['patch_gap_count'] ?? 0);
        if ($patchGapCount >= 3) {
            $addFactor('patch_gaps', min(18, $patchGapCount * 3), [
                'finding_type' => 'patch_gap',
                'severity' => $patchGapCount >= 6 ? 'high' : 'medium',
                'confidence' => 0.8,
                'summary' => 'Patch gaps exceed baseline.',
                'evidence' => ['patch_gap_count' => $patchGapCount],
                'fingerprint' => 'patch_gap',
            ]);
        }

        $usbEvents = (int) ($metrics['usb_events_24h'] ?? 0);
        if ($usbEvents > 0) {
            $addFactor('usb_activity', min(10, 4 + ($usbEvents * 2)), [
                'finding_type' => 'usb_activity',
                'severity' => 'low',
                'confidence' => 0.68,
                'summary' => 'USB storage activity detected.',
                'evidence' => ['usb_events_24h' => $usbEvents],
                'fingerprint' => 'usb_activity',
            ]);
        }

        $externalConnections = (int) ($metrics['external_connections_24h'] ?? 0);
        if ($externalConnections >= 10) {
            $addFactor('external_connections', min(14, 6 + ($externalConnections * 0.5)), [
                'finding_type' => 'unusual_external_connections',
                'severity' => 'medium',
                'confidence' => 0.7,
                'summary' => 'High volume of external connection activity detected.',
                'evidence' => ['external_connections_24h' => $externalConnections],
                'fingerprint' => 'unusual_external_connections',
            ]);
        }

        $score = max(0, min(100, round($score, 2)));
        $severity = $score >= 80 ? 'critical' : ($score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low'));

        return [
            'score' => $score,
            'severity' => $severity,
            'confidence' => $findings === [] ? 0.55 : round(collect($findings)->avg('confidence'), 2),
            'factor_breakdown' => $factorBreakdown,
            'findings' => $findings,
        ];
    }
}
