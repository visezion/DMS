<?php

return [
    'risk_spike_threshold' => (float) env('DMS_AUTONOMOUS_RISK_SPIKE_THRESHOLD', 75),
    'health_degradation_band' => env('DMS_AUTONOMOUS_HEALTH_DEGRADATION_BAND', 'critical'),
    'failed_jobs_threshold' => (int) env('DMS_AUTONOMOUS_FAILED_JOBS_THRESHOLD', 3),
    'max_actions_per_device_per_hour' => (int) env('DMS_AUTONOMOUS_MAX_ACTIONS_PER_DEVICE_PER_HOUR', 3),
    'default_mode' => env('DMS_AUTONOMOUS_DEFAULT_MODE', 'recommend_only'),
    'default_confidence' => (float) env('DMS_AUTONOMOUS_DEFAULT_CONFIDENCE', 70),
    'ai' => [
        'driver' => env('DMS_AUTONOMOUS_AI_DRIVER', 'local'),
        'endpoint' => env('DMS_AUTONOMOUS_AI_ENDPOINT'),
        'timeout_seconds' => (int) env('DMS_AUTONOMOUS_AI_TIMEOUT_SECONDS', 4),
        'retry_times' => (int) env('DMS_AUTONOMOUS_AI_RETRY_TIMES', 1),
    ],
];
