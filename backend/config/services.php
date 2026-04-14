<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'assistant_model' => env('OPENAI_ASSISTANT_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'assistant_fallback_model' => env('OPENAI_ASSISTANT_FALLBACK_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'assistant_temperature' => (float) env('OPENAI_ASSISTANT_TEMPERATURE', 0.15),
        'assistant_max_completion_tokens' => (int) env('OPENAI_ASSISTANT_MAX_COMPLETION_TOKENS', 700),
        'assistant_timeout' => (int) env('OPENAI_ASSISTANT_TIMEOUT_SECONDS', env('OPENAI_TIMEOUT_SECONDS', 12)),
        'assistant_connect_timeout' => (int) env('OPENAI_ASSISTANT_CONNECT_TIMEOUT_SECONDS', 2),
        'assistant_retry_times' => (int) env('OPENAI_ASSISTANT_RETRY_TIMES', 0),
        'assistant_retry_sleep_ms' => (int) env('OPENAI_ASSISTANT_RETRY_SLEEP_MS', 120),
        'assistant_history_messages' => (int) env('OPENAI_ASSISTANT_HISTORY_MESSAGES', 8),
        'assistant_expose_provider_errors' => (bool) env('OPENAI_ASSISTANT_EXPOSE_PROVIDER_ERRORS', false),
        'assistant_redact_behavior_details' => (bool) env('OPENAI_ASSISTANT_REDACT_BEHAVIOR_DETAILS', true),
        'timeout' => (int) env('OPENAI_TIMEOUT_SECONDS', 12),
    ],

    'endpoint_intelligence' => [
        'queue' => env('DMS_INTELLIGENCE_QUEUE', 'default'),
        'dispatch_debounce_seconds' => (int) env('DMS_INTELLIGENCE_DISPATCH_DEBOUNCE_SECONDS', 45),
        'finding_stale_minutes' => (int) env('DMS_FINDING_STALE_MINUTES', 30),
        'freshness_stale_minutes' => (int) env('DMS_INTELLIGENCE_FRESHNESS_STALE_MINUTES', 120),
        'allow_behavior_checkin_fallback' => (bool) env('DMS_BEHAVIOR_LOG_ALLOW_CHECKIN_FALLBACK', true),
        'behavior_checkin_fallback_window_minutes' => (int) env('DMS_BEHAVIOR_LOG_CHECKIN_FALLBACK_WINDOW_MINUTES', 15),
        'checkin_interval_seconds' => (int) env('DMS_CHECKIN_INTERVAL_SECONDS', 60),
        'nonce_window_seconds' => (int) env('DMS_NONCE_WINDOW_SECONDS', 300),
    ],

];
