<?php

return [
    'service' => env('PIXFLIX_SERVICE_NAME', 'pixflix-api'),
    'api_version' => 'v1',
    'auth_token_name' => env('PIXFLIX_AUTH_TOKEN_NAME', 'pwa'),
    'auth_token_lifetime_minutes' => max(
        1,
        (int) env('PIXFLIX_AUTH_TOKEN_LIFETIME_MINUTES', env('SESSION_LIFETIME', 43200)),
    ),
    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@pixflix.test'),
        'username' => env('ADMIN_USERNAME', 'admin'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'request_id_header' => 'X-Request-Id',
    'pwa' => [
        'force_update' => filter_var(env('PIXFLIX_PWA_FORCE_UPDATE', false), FILTER_VALIDATE_BOOLEAN),
    ],
    'rate_limit' => [
        'per_minute' => (int) env('PIXFLIX_RATE_LIMIT_PER_MINUTE', 60),
        'auth_per_minute' => (int) env('PIXFLIX_RATE_LIMIT_AUTH_PER_MINUTE', 240),
    ],
    'catalog' => [
        'primary_url' => env('PIXFLIX_CATALOG_PRIMARY_URL', 'https://zonaapis.arcando.cloud'),
        'fallback_url' => env('PIXFLIX_CATALOG_FALLBACK_URL', 'https://apiprorescue.testaacc.workers.dev'),
        'timeout_ms' => (int) env('PIXFLIX_CATALOG_TIMEOUT_MS', 8000),
        'verify_ssl' => filter_var(env('PIXFLIX_CATALOG_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        // Keep local seeded content playable without requiring an external
        // catalog provider. Production remains disabled unless explicitly set.
        'use_fixtures' => filter_var(
            env('PIXFLIX_CATALOG_USE_FIXTURES', env('APP_ENV', 'production') === 'local'),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'fixture_hls_url' => env(
            'PIXFLIX_CATALOG_FIXTURE_HLS_URL',
            'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
        ),
        'retry_attempts' => (int) env('PIXFLIX_CATALOG_RETRY_ATTEMPTS', 3),
        'retry_delays_ms' => array_values(array_map('intval', explode(',', env('PIXFLIX_CATALOG_RETRY_DELAYS_MS', '2000,8000,30000')))),
        'circuit_threshold' => (int) env('PIXFLIX_CATALOG_CIRCUIT_THRESHOLD', 3),
        'circuit_cooldown_seconds' => (int) env('PIXFLIX_CATALOG_CIRCUIT_COOLDOWN', 300),
        'page_size' => (int) env('PIXFLIX_CATALOG_PAGE_SIZE', 50),
        'max_pages' => (int) env('PIXFLIX_CATALOG_MAX_PAGES', 1000),
    ],
    'tmdb' => [
        'api_key' => env('PIXFLIX_TMDB_API_KEY', ''),
        'access_token' => env('PIXFLIX_TMDB_ACCESS_TOKEN', ''),
        'base_url' => env('PIXFLIX_TMDB_BASE_URL', 'https://api.themoviedb.org/3'),
        'language' => env('PIXFLIX_TMDB_LANGUAGE', 'es-MX'),
        'timeout_seconds' => (int) env('PIXFLIX_TMDB_TIMEOUT_SECONDS', 8),
        'verify_ssl' => filter_var(env('PIXFLIX_TMDB_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],
    'sync' => [
        'cron_hour' => env('PIXFLIX_SYNC_CRON_HOUR', '04:00'),
        'timezone' => env('PIXFLIX_SYNC_TIMEZONE', 'UTC'),
        'allow_on_demand' => filter_var(env('PIXFLIX_SYNC_ALLOW_ON_DEMAND', false), FILTER_VALIDATE_BOOLEAN),
        // Queue the admin-triggered IPTV/VOD syncs instead of running them
        // inside the HTTP request. Needs Dragonfly-backed QUEUE_CONNECTION=redis
        // plus a deployed worker.
        'async' => filter_var(env('PIXFLIX_SYNC_ASYNC', false), FILTER_VALIDATE_BOOLEAN),
        'lock_seconds' => (int) env('PIXFLIX_SYNC_LOCK_SECONDS', 3600),
        'stale_after_minutes' => (int) env('PIXFLIX_SYNC_STALE_AFTER_MINUTES', 180),
    ],
    'trial' => [
        'plan_id' => env('PIXFLIX_TRIAL_PLAN_ID') !== null ? (int) env('PIXFLIX_TRIAL_PLAN_ID') : null,
    ],
    'stremio' => [
        'enabled' => filter_var(env('PIXFLIX_STREMIO_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'primary' => filter_var(env('PIXFLIX_STREMIO_PRIMARY', false), FILTER_VALIDATE_BOOLEAN),
        'catalog_enabled' => env('PIXFLIX_STREMIO_CATALOG_ENABLED') === null
            ? null
            : filter_var(env('PIXFLIX_STREMIO_CATALOG_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'streams_enabled' => env('PIXFLIX_STREMIO_STREAMS_ENABLED') === null
            ? null
            : filter_var(env('PIXFLIX_STREMIO_STREAMS_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'timeout_seconds' => (int) env('PIXFLIX_STREMIO_TIMEOUT_SECONDS', 10),
        'cache_ttl_seconds' => (int) env('PIXFLIX_STREAM_CACHE_TTL_SECONDS', 1800),
        'catalog_max_pages' => (int) env('PIXFLIX_STREMIO_CATALOG_MAX_PAGES', 10),
        'catalog_max_items' => (int) env('PIXFLIX_STREMIO_CATALOG_MAX_ITEMS', 500),
        'catalog_sync_ttl_seconds' => (int) env('PIXFLIX_STREMIO_CATALOG_SYNC_TTL_SECONDS', 900),
        'catalog_sync_wait_seconds' => (int) env('PIXFLIX_STREMIO_CATALOG_SYNC_WAIT_SECONDS', 120),
        'languages' => array_values(array_filter(array_map('trim', explode(',', env('PIXFLIX_STREMIO_LANGUAGES', ''))))),
        'catalog_addons' => [],
        'stream_addons' => [],
        'addons' => [],
    ],
    'cache' => [
        'catalog_ttl' => (int) env('PIXFLIX_CACHE_CATALOG_TTL', 43200),
        'channels_ttl' => (int) env('PIXFLIX_CACHE_CHANNELS_TTL', 43200),
        'warmup_interval_hours' => (int) env('PIXFLIX_CACHE_WARMUP_INTERVAL_HOURS', 12),
    ],
    'iptv' => [
        'playlist_url' => env('PIXFLIX_IPTV_M3U_URL', 'https://iptv-org.github.io/iptv/index.m3u'),
        'proxies' => [],
        'country' => env('PIXFLIX_IPTV_COUNTRY'),
        'max_channels' => env('PIXFLIX_IPTV_MAX_CHANNELS') !== null ? (int) env('PIXFLIX_IPTV_MAX_CHANNELS') : null,
        'timeout_seconds' => (int) env('PIXFLIX_IPTV_TIMEOUT_SECONDS', 30),
        'verify_ssl' => filter_var(env('PIXFLIX_IPTV_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
