<?php
/**
 * RestaurantOS — application bootstrap.
 *
 * Every entry point loads this file first: the legacy storefront, the tenant
 * admin panel, the JSON API and the CLI tools. It wires together
 *
 *   1. runtime tuning + autoloading
 *   2. the tenant context (which restaurant is this request for?)
 *   3. $conn — the tenant database connection the legacy app expects
 *   4. the platform/control-plane connection for SaaS concerns
 *
 * @package Resto
 */

declare(strict_types=1);

use Resto\Database\Manager;
use Resto\Tenancy\Tenant;
use Resto\Support\Config;
use Resto\Support\Env;
use Resto\Support\Str;

foreach (glob(__DIR__ . '/core/*.php') ?: [] as $coreFile) {
    require_once $coreFile;
}

Env::load();
Config::load();

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

$GLOBALS['resto'] = [
    'booted_at'  => microtime(true),
    'cli'        => PHP_SAPI === 'cli',
    'tenant'     => null,
    'impersonating' => false,
];

/* -------------------------------------------------------------------------
 * Runtime tuning
 * ---------------------------------------------------------------------- */
(function (): void {
    $isWeb = PHP_SAPI !== 'cli';

    // The dev server (tools/php-runtime.mjs) boots PHP from wasm, where a few
    // php.ini values must be applied at runtime. Harmless in production.
    if (!Config::isLocal()) {
        return;
    }

    $storage = Config::path((string) Config::get('storage.path', 'storage'));
    foreach (['sessions', 'logs', 'tenants', 'uploads'] as $dir) {
        if (!is_dir("$storage/$dir")) {
            @mkdir("$storage/$dir", 0775, true);
        }
    }

    @ini_set('display_errors', '1');
    @ini_set('error_reporting', 'E_ALL');
    @ini_set('log_errors', '1');
    @ini_set('error_log', "$storage/logs/php-error.log");
    if ($isWeb && session_status() === PHP_SESSION_NONE) {
        @ini_set('session.save_path', "$storage/sessions");
    }
})();

/* -------------------------------------------------------------------------
 * Sessions (hardened, same policy as the original application)
 * ---------------------------------------------------------------------- */
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Lax', // Strict would break redirects back from billing/checkout
    ]);
    session_start();
}

/* -------------------------------------------------------------------------
 * Platform database + schema (auto-migrate in local/demo environments)
 * ---------------------------------------------------------------------- */
function platform_db(): \Resto\Database\Connection
{
    return Manager::platform();
}

(function (): void {
    $pdo = Manager::platform();
    \Resto\Database\Migrator::run($pdo, false);
})();

/* -------------------------------------------------------------------------
 * Tenant context
 * ---------------------------------------------------------------------- */
$tenant = null;
if (PHP_SAPI !== 'cli') {
    $tenant = \Resto\Tenancy\Resolver::resolve();
}

if ($tenant instanceof Tenant) {
    $GLOBALS['resto']['tenant'] = $tenant;
    \Resto\Tenancy\Context::set($tenant);

    // The legacy application talks to $conn — hand it the tenant's database.
    /** @var \Resto\Database\Connection $conn */
    $conn = Manager::tenant($tenant->toArray());

    // Keep the historical constants available for legacy code.
    if (!defined('DB_HOST')) {
        define('DB_HOST', (string) Config::get('database.tenant.host'));
    }
    if (!defined('DB_NAME')) {
        define('DB_NAME', $tenant->dbName);
    }
    if (!defined('DB_USER')) {
        define('DB_USER', (string) Config::get('database.tenant.user'));
    }
    if (!defined('DB_PASS')) {
        define('DB_PASS', (string) Config::get('database.tenant.pass'));
    }

    // Suspended / cancelled tenants still get a readable "we're closed" page.
    \Resto\Tenancy\Gatekeeper::enforce($tenant);
}

/* -------------------------------------------------------------------------
 * URL helpers used throughout the legacy templates
 * ---------------------------------------------------------------------- */
if (!defined('BASE_URL')) {
    if (PHP_SAPI === 'cli') {
        define('BASE_URL', (string) getenv('APP_BASE_URL'));
    } else {
        // Some SAPIs report HTTPS=off rather than leaving it unset.
        $https  = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $scheme = in_array($https, ['on', '1', 'true'], true)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                ? 'https'
                : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? Config::get('app.root_domain');

        // The app may live in a sub-directory (e.g. /restaurants/admin/…).
        // Derive the prefix by comparing the URL path of the running script
        // with its path on disk — guessing from the first path segment would
        // mistake the application's own /admin/ folder for a sub-directory.
        $script   = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $file     = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $appDir   = str_replace('\\', '/', dirname(__DIR__));
        $root     = '';
        if ($tenant instanceof Tenant && $tenant->pathPrefix !== '') {
            $root = $tenant->pathPrefix;
        } elseif ($script !== '' && $file !== '' && str_starts_with($file, $appDir . '/')) {
            $relative = substr($file, strlen($appDir));            // /admin/login.php
            if (str_ends_with($script, $relative)) {
                $root = substr($script, 0, strlen($script) - strlen($relative)); // '' or '/sub'
            }
        }
        define('BASE_URL', $scheme . '://' . $host . rtrim($root, '/'));
    }
}

/** Absolute URL of the running tenant site. */
function tenant_url(string $path = ''): string
{
    $tenant = \Resto\Tenancy\Context::get();
    if ($tenant instanceof Tenant) {
        // Handles both host routing and the ?__tenant=<slug> fallback used in
        // single-host environments (sandbox previews, plain localhost).
        return \Resto\Tenancy\Links::storefront($tenant, $path);
    }
    return rtrim(BASE_URL, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** Absolute URL of the platform (superadmin console). */
function platform_url(string $path = ''): string
{
    return \Resto\Tenancy\Links::console($path);
}

/** Absolute URL of the JSON API. */
function api_url(string $path = ''): string
{
    return \Resto\Tenancy\Links::baseUrl() . '/api/v1' . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/* -------------------------------------------------------------------------
 * Tenancy shortcuts for the procedural application
 *
 * The legacy pages live in the global namespace, the modern services are
 * namespaced — these shims let both styles coexist in one file tree.
 * ---------------------------------------------------------------------- */
if (!function_exists('current_tenant')) {
    function current_tenant(): ?\Resto\Tenancy\Tenant
    {
        return \Resto\Tenancy\Context::get();
    }
}

/** The restaurant a visitor identified by code, or null. */
if (!function_exists('tenant_by_code')) {
    function tenant_by_code(string $code): ?\Resto\Tenancy\Tenant
    {
        return \Resto\Tenancy\tenant_by_code($code);
    }
}

/** Point this request (and the session) at another restaurant. */
if (!function_exists('use_tenant')) {
    function use_tenant(\Resto\Tenancy\Tenant $tenant): \Resto\Database\Connection
    {
        return \Resto\Tenancy\use_tenant($tenant);
    }
}

if (!function_exists('feature_enabled')) {
    function feature_enabled(string $feature): bool
    {
        return \Resto\Tenancy\feature_enabled($feature);
    }
}

if (!function_exists('require_feature')) {
    function require_feature(string $feature): void
    {
        \Resto\Tenancy\require_feature($feature);
    }
}

if (!function_exists('quota_check')) {
    function quota_check(string $metric, int $adding = 1): void
    {
        \Resto\Tenancy\quota_check($metric, $adding);
    }
}

if (!function_exists('record_usage')) {
    function record_usage(string $metric, int $quantity = 1, array $meta = []): void
    {
        \Resto\Tenancy\record_usage($metric, $quantity, $meta);
    }
}

/* -------------------------------------------------------------------------
 * Graceful failure for the web UI
 * ---------------------------------------------------------------------- */
if (PHP_SAPI !== 'cli' && !$tenant instanceof Tenant && !\Resto\Tenancy\Resolver::isPlatformRequest()) {
    http_response_code(421);
    echo '<!doctype html><meta charset="utf-8"><title>No restaurant here</title>'
        . '<div style="font:16px/1.6 system-ui;max-width:44rem;margin:12vh auto;padding:0 1.5rem">'
        . '<h1 style="font-size:1.6rem">No restaurant is hosted at this address</h1>'
        . '<p style="color:#555">This domain is not connected to a restaurant account yet. '
        . 'If you are the owner, sign in to the platform to finish setting up your site.</p>'
        . '<p><a href="' . htmlspecialchars(platform_url('/'), ENT_QUOTES) . '">Open the platform console →</a></p></div>';
    exit;
}
