<?php
/**
 * RestaurantOS — platform configuration.
 *
 * Values are read from the environment (config/.env) with sane defaults so a
 * fresh checkout boots with SQLite and no external services.
 */

return [
    'app' => [
        'name'      => getenv('APP_NAME') ?: 'RestaurantOS',
        'env'       => getenv('APP_ENV') ?: 'production',
        'debug'     => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
        'timezone'  => getenv('APP_TIMEZONE') ?: 'UTC',
        'root_domain' => getenv('APP_ROOT_DOMAIN') ?: 'localhost',
        'scheme'    => getenv('APP_SCHEME') ?: 'http',
        'version'   => '1.0.0',
    ],

    'database' => [
        // mysql | sqlite
        'driver'    => strtolower(getenv('DB_DRIVER') ?: 'mysql'),
        'sqlite_path' => getenv('DB_SQLITE_PATH') ?: 'storage',

        'platform' => [
            'host' => getenv('PLATFORM_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PLATFORM_DB_PORT') ?: 3306),
            'name' => getenv('PLATFORM_DB_NAME') ?: 'restaurantos_platform',
            'user' => getenv('PLATFORM_DB_USER') ?: 'root',
            'pass' => getenv('PLATFORM_DB_PASS') ?: '',
        ],

        // One database per tenant — the isolation strategy that lets the
        // existing (single-tenant) restaurant application run unchanged.
        'tenant' => [
            'prefix' => getenv('TENANT_DB_PREFIX') ?: 'restaurantos_t_',
            'host'   => getenv('TENANT_DB_HOST') ?: (getenv('PLATFORM_DB_HOST') ?: '127.0.0.1'),
            'port'   => (int) (getenv('TENANT_DB_PORT') ?: (getenv('PLATFORM_DB_PORT') ?: 3306)),
            'user'   => getenv('TENANT_DB_USER') ?: (getenv('PLATFORM_DB_USER') ?: 'root'),
            'pass'   => getenv('TENANT_DB_PASS') ?: (getenv('PLATFORM_DB_PASS') ?: ''),
        ],
    ],

    'tenancy' => [
        'trial_days'      => (int) (getenv('DEFAULT_TRIAL_DAYS') ?: 14),
        'auto_provision'  => filter_var(getenv('TENANT_AUTO_PROVISION') ?: true, FILTER_VALIDATE_BOOLEAN),
        'demo_tenant'     => getenv('DEMO_TENANT_SLUG') ?: 'demo',
        // Single-host environments (sandbox previews, plain localhost) have no
        // wildcard DNS: unknown hosts fall back to the demo restaurant and
        // tenant links carry ?__tenant=<slug> instead of a sub-domain.
        'fallback_to_demo' => filter_var(getenv('TENANCY_FALLBACK_TO_DEMO') ?: true, FILTER_VALIDATE_BOOLEAN),
        // Sub-domains that belong to the platform itself, never to a tenant.
        'reserved_hosts'  => ['www', 'app', 'admin', 'api', 'superadmin', 'static', 'cdn', 'status', 'docs'],
        'session_timeout' => 1800,
        // Characters in the per-tenant sign-in code (4-12).
        'access_code_length' => (int) (getenv('ACCESS_CODE_LENGTH') ?: 5),
    ],

    'api' => [
        'rate_limit' => (int) (getenv('API_RATE_LIMIT') ?: 120),
        'version'    => 'v1',
    ],

    'storage' => [
        'path'     => 'storage',
        'sessions' => 'storage/sessions',
        'logs'     => 'storage/logs',
        'uploads'  => 'storage/uploads',
        'backups'  => 'storage/backups',
    ],
];
