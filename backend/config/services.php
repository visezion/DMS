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
        'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 12),
        'behavior_analyst_enabled' => filter_var((string) env('OPENAI_BEHAVIOR_ANALYST_ENABLED', 'true'), FILTER_VALIDATE_BOOL),
        'behavior_history_events' => (int) env('OPENAI_BEHAVIOR_HISTORY_EVENTS', 150),
        'behavior_min_confidence' => (float) env('OPENAI_BEHAVIOR_MIN_CONFIDENCE', 0.60),
        'ai_power_enabled' => filter_var((string) env('OPENAI_AI_POWER_ENABLED', 'true'), FILTER_VALIDATE_BOOL),
        'ai_power_model' => env('OPENAI_AI_POWER_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'ai_power_timeout_seconds' => (int) env('OPENAI_AI_POWER_TIMEOUT_SECONDS', env('OPENAI_TIMEOUT_SECONDS', 12)),
        'ai_power_prompt_version' => env('OPENAI_AI_POWER_PROMPT_VERSION', 'v2'),
        'ai_power_prompt_extra' => env('OPENAI_AI_POWER_PROMPT_EXTRA', ''),
        'ai_power_policy_prompt_version' => env('OPENAI_AI_POWER_POLICY_PROMPT_VERSION', 'v2'),
        'ai_power_policy_prompt_extra' => env('OPENAI_AI_POWER_POLICY_PROMPT_EXTRA', ''),
        'ai_power_review_prompt_version' => env('OPENAI_AI_POWER_REVIEW_PROMPT_VERSION', 'v2'),
        'ai_power_review_prompt_extra' => env('OPENAI_AI_POWER_REVIEW_PROMPT_EXTRA', ''),
        'ai_power_online_window_minutes' => (int) env('OPENAI_AI_POWER_ONLINE_WINDOW_MINUTES', 5),
    ],

];
