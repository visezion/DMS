<?php

return [
    'standalone_mode' => env('DMS_STANDALONE_MODE', true),
    'enforce_mtls' => env('DMS_ENFORCE_MTLS', false),
    'command_signing_required' => env('DMS_COMMAND_SIGNING_REQUIRED', true),
    'checkin_interval_seconds' => (int) env('DMS_CHECKIN_INTERVAL_SECONDS', 60),
    'replay_window_seconds' => (int) env('DMS_REPLAY_WINDOW_SECONDS', 300),
    'keyset_cache_seconds' => (int) env('DMS_KEYSET_CACHE_SECONDS', 300),
    'package_hash_allow_private_hosts' => env('DMS_PACKAGE_HASH_ALLOW_PRIVATE_HOSTS', false),
    'supported_locales' => [
        'en' => 'English',
        'tr' => 'Turkce',
    ],
];
