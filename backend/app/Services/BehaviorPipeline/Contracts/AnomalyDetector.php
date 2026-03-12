<?php

namespace App\Services\BehaviorPipeline\Contracts;

use App\Models\DeviceBehaviorLog;

interface AnomalyDetector
{
    public function key(): string;

    /**
     * @param array<string,mixed> $features
     * @return array{score: float, confidence: float, details: array<string,mixed>}
     */
    public function detect(DeviceBehaviorLog $event, array $features): array;
}
