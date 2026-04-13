<?php

namespace App\Domain\EndpointIntelligence;

use App\Models\Device;
use App\Models\DeviceHealthScore;
use App\Models\DeviceRiskScore;
use App\Models\ThreatFinding;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CurrentPostureService
{
    /**
     * @var array<int,string>
     */
    public const ACTIVE_FINDING_STATUSES = ['open', 'investigating'];

    public function latestHealthScoresQuery(): Builder
    {
        $latestScoreTimestampByDevice = DeviceHealthScore::query()
            ->selectRaw('device_id, MAX(scored_at) as max_scored_at')
            ->groupBy('device_id');

        return DeviceHealthScore::query()
            ->joinSub($latestScoreTimestampByDevice, 'latest_score_times', function ($join): void {
                $join->on('device_health_scores.device_id', '=', 'latest_score_times.device_id')
                    ->on('device_health_scores.scored_at', '=', 'latest_score_times.max_scored_at');
            })
            ->orderByDesc('device_health_scores.scored_at')
            ->orderByDesc('device_health_scores.created_at')
            ->select([
                'device_health_scores.id',
                'device_health_scores.device_id',
                'device_health_scores.snapshot_id',
                'device_health_scores.score',
                'device_health_scores.band',
                'device_health_scores.predicted_failure_risk',
                'device_health_scores.component_scores',
                'device_health_scores.contributors',
                'device_health_scores.scored_at',
                'device_health_scores.created_at',
            ]);
    }

    /**
     * @return Collection<int,DeviceHealthScore>
     */
    public function latestHealthScores(): Collection
    {
        return $this->latestHealthScoresQuery()
            ->get()
            ->unique('device_id')
            ->values();
    }

    public function latestHealthScoreForDevice(string $deviceId): ?DeviceHealthScore
    {
        return DeviceHealthScore::query()
            ->where('device_id', $deviceId)
            ->orderByDesc('scored_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function latestRiskScoresQuery(): Builder
    {
        $latestScoreTimestampByDevice = DeviceRiskScore::query()
            ->selectRaw('device_id, MAX(scored_at) as max_scored_at')
            ->groupBy('device_id');

        return DeviceRiskScore::query()
            ->joinSub($latestScoreTimestampByDevice, 'latest_score_times', function ($join): void {
                $join->on('device_risk_scores.device_id', '=', 'latest_score_times.device_id')
                    ->on('device_risk_scores.scored_at', '=', 'latest_score_times.max_scored_at');
            })
            ->orderByDesc('device_risk_scores.scored_at')
            ->orderByDesc('device_risk_scores.created_at')
            ->select([
                'device_risk_scores.id',
                'device_risk_scores.device_id',
                'device_risk_scores.score',
                'device_risk_scores.severity',
                'device_risk_scores.confidence',
                'device_risk_scores.factor_breakdown',
                'device_risk_scores.scored_at',
                'device_risk_scores.created_at',
            ]);
    }

    /**
     * @return Collection<int,DeviceRiskScore>
     */
    public function latestRiskScores(): Collection
    {
        return $this->latestRiskScoresQuery()
            ->get()
            ->unique('device_id')
            ->values();
    }

    public function latestRiskScoreForDevice(string $deviceId): ?DeviceRiskScore
    {
        return DeviceRiskScore::query()
            ->where('device_id', $deviceId)
            ->orderByDesc('scored_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function activeFindingsQuery(): Builder
    {
        return ThreatFinding::query()
            ->whereIn('status', self::ACTIVE_FINDING_STATUSES)
            ->latest('last_seen_at');
    }

    public function latestActiveFindingForDevice(string $deviceId): ?ThreatFinding
    {
        return ThreatFinding::query()
            ->where('device_id', $deviceId)
            ->whereIn('status', self::ACTIVE_FINDING_STATUSES)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function fleetFreshness(?int $staleAfterMinutes = null): array
    {
        $staleAfterMinutes = $this->normalizeStaleAfterMinutes($staleAfterMinutes);
        $staleThreshold = now()->subMinutes($staleAfterMinutes);

        $latestHealthScores = $this->latestHealthScores();
        $latestRiskScores = $this->latestRiskScores();
        $fleetDeviceCount = (int) Device::query()->count();

        $latestHealthAt = $this->latestTimestamp($latestHealthScores, 'scored_at');
        $latestRiskAt = $this->latestTimestamp($latestRiskScores, 'scored_at');
        $latestFindingAtRaw = $this->activeFindingsQuery()->max('last_seen_at');
        $latestFindingAt = $this->toCarbon($latestFindingAtRaw);

        return [
            'stale_after_minutes' => $staleAfterMinutes,
            'fleet_device_count' => $fleetDeviceCount,
            'health_scored_devices' => $latestHealthScores->pluck('device_id')->unique()->count(),
            'risk_scored_devices' => $latestRiskScores->pluck('device_id')->unique()->count(),
            'health_missing_devices' => max(0, $fleetDeviceCount - $latestHealthScores->pluck('device_id')->unique()->count()),
            'risk_missing_devices' => max(0, $fleetDeviceCount - $latestRiskScores->pluck('device_id')->unique()->count()),
            'stale_health_devices' => $latestHealthScores
                ->filter(fn (DeviceHealthScore $score): bool => $score->scored_at !== null && $score->scored_at->lte($staleThreshold))
                ->count(),
            'stale_risk_devices' => $latestRiskScores
                ->filter(fn (DeviceRiskScore $score): bool => $score->scored_at !== null && $score->scored_at->lte($staleThreshold))
                ->count(),
            'health_latest' => $this->buildFreshnessEntry($latestHealthAt, $staleAfterMinutes),
            'risk_latest' => $this->buildFreshnessEntry($latestRiskAt, $staleAfterMinutes),
            'finding_latest' => $this->buildFreshnessEntry($latestFindingAt, $staleAfterMinutes),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function deviceFreshness(string $deviceId, ?int $staleAfterMinutes = null): array
    {
        $staleAfterMinutes = $this->normalizeStaleAfterMinutes($staleAfterMinutes);
        $health = $this->latestHealthScoreForDevice($deviceId);
        $risk = $this->latestRiskScoreForDevice($deviceId);
        $finding = $this->latestActiveFindingForDevice($deviceId);

        return [
            'stale_after_minutes' => $staleAfterMinutes,
            'health' => $this->buildFreshnessEntry($health?->scored_at, $staleAfterMinutes),
            'risk' => $this->buildFreshnessEntry($risk?->scored_at, $staleAfterMinutes),
            'finding' => $this->buildFreshnessEntry($finding?->last_seen_at, $staleAfterMinutes),
        ];
    }

    private function normalizeStaleAfterMinutes(?int $staleAfterMinutes): int
    {
        return max(1, (int) ($staleAfterMinutes ?? config('services.endpoint_intelligence.freshness_stale_minutes', 120)));
    }

    /**
     * @param  Collection<int,object>  $rows
     */
    private function latestTimestamp(Collection $rows, string $column): ?Carbon
    {
        $timestamp = $rows->max(function (object $row) use ($column): ?int {
            $value = data_get($row, $column);
            $parsed = $this->toCarbon($value);

            return $parsed?->getTimestamp();
        });

        return is_numeric($timestamp)
            ? Carbon::createFromTimestamp((int) $timestamp)
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildFreshnessEntry(mixed $value, int $staleAfterMinutes): array
    {
        $timestamp = $this->toCarbon($value);
        $ageMinutes = $timestamp ? now()->diffInMinutes($timestamp) : null;

        return [
            'updated_at' => $timestamp,
            'age_minutes' => $ageMinutes,
            'age_human' => $timestamp?->diffForHumans(),
            'is_stale' => $ageMinutes !== null && $ageMinutes >= $staleAfterMinutes,
            'has_data' => $timestamp !== null,
        ];
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value);
        }

        return null;
    }
}

