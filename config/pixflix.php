<?php

return [
    'service' => env('PIXFLIX_SERVICE_NAME', 'pixflix-api'),
    'api_version' => 'v1',
    'auth_token_name' => env('PIXFLIX_AUTH_TOKEN_NAME', 'pwa'),
    'request_id_header' => 'X-Request-Id',
    'pwa' => [
        'force_update' => filter_var(env('PIXFLIX_PWA_FORCE_UPDATE', false), FILTER_VALIDATE_BOOLEAN),
    ],
    'rate_limit' => [
        'per_minute' => (int) env('PIXFLIX_RATE_LIMIT_PER_MINUTE', 60),
    ],
    'catalog' => [
        'primary_url' => env('PIXFLIX_CATALOG_PRIMARY_URL', 'https://zonaapis.arcando.cloud'),
        'fallback_url' => env('PIXFLIX_CATALOG_FALLBACK_URL', 'https://apiprorescue.testaacc.workers.dev'),
        'timeout_ms' => (int) env('PIXFLIX_CATALOG_TIMEOUT_MS', 8000),
        'verify_ssl' => filter_var(env('PIXFLIX_CATALOG_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        'retry_attempts' => (int) env('PIXFLIX_CATALOG_RETRY_ATTEMPTS', 3),
        'retry_delays_ms' => array_values(array_map('intval', explode(',', env('PIXFLIX_CATALOG_RETRY_DELAYS_MS', '2000,8000,30000')))),
        'circuit_threshold' => (int) env('PIXFLIX_CATALOG_CIRCUIT_THRESHOLD', 3),
        'circuit_cooldown_seconds' => (int) env('PIXFLIX_CATALOG_CIRCUIT_COOLDOWN', 300),
        'page_size' => (int) env('PIXFLIX_CATALOG_PAGE_SIZE', 50),
        'max_pages' => (int) env('PIXFLIX_CATALOG_MAX_PAGES', 1000),
    ],
    'sync' => [
        'cron_hour' => env('PIXFLIX_SYNC_CRON_HOUR', '04:00'),
        'timezone' => env('PIXFLIX_SYNC_TIMEZONE', 'UTC'),
        'allow_on_demand' => filter_var(env('PIXFLIX_SYNC_ALLOW_ON_DEMAND', false), FILTER_VALIDATE_BOOLEAN),
        'lock_seconds' => (int) env('PIXFLIX_SYNC_LOCK_SECONDS', 3600),
        'stale_after_minutes' => (int) env('PIXFLIX_SYNC_STALE_AFTER_MINUTES', 180),
    ],
    'trial' => [
        'plan_id' => env('PIXFLIX_TRIAL_PLAN_ID') !== null ? (int) env('PIXFLIX_TRIAL_PLAN_ID') : null,
    ],
    'stremio' => [
        'enabled' => filter_var(env('PIXFLIX_STREMIO_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'timeout_seconds' => (int) env('PIXFLIX_STREMIO_TIMEOUT_SECONDS', 10),
        'cache_ttl_seconds' => (int) env('PIXFLIX_STREAM_CACHE_TTL_SECONDS', 1800),
        'languages' => array_values(array_filter(array_map('trim', explode(',', env('PIXFLIX_STREMIO_LANGUAGES', ''))))),
        'addons' => [],
    ],
    'iptv' => [
        'playlist_url' => env('PIXFLIX_IPTV_M3U_URL', 'https://iptv-org.github.io/iptv/index.m3u'),
        'proxies' => [],
        'country' => env('PIXFLIX_IPTV_COUNTRY'),
        'max_channels' => env('PIXFLIX_IPTV_MAX_CHANNELS') !== null ? (int) env('PIXFLIX_IPTV_MAX_CHANNELS') : null,
        'timeout_seconds' => (int) env('PIXFLIX_IPTV_TIMEOUT_SECONDS', 30),
        'verify_ssl' => filter_var(env('PIXFLIX_IPTV_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        'sync_cron' => env('PIXFLIX_IPTV_SYNC_CRON', '03:30'),
    ],
];
