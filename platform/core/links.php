<?php
/**
 * RestaurantOS — URL helpers for multi-tenant routing.
 *
 * In production each restaurant is reached on its own host
 * (aurora.restaurantos.com or a custom domain). Sandboxes and local
 * environments do not have wildcard DNS, so the same links fall back to a
 * `?__tenant=<slug>` parameter which the resolver understands.
 *
 * @package Resto\Tenancy
 */

namespace Resto\Tenancy;

use Resto\Support\Config;

final class Links
{
    /**
     * Can this environment route by host name?
     *
     * Wildcard sub-domains only exist when the root domain is a real, DNS
     * resolvable domain. Sandboxes and plain localhost serve every tenant from
     * one host, so links fall back to ?__tenant=<slug>.
     */
    public static function usesHostRouting(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $root = (string) Config::get('app.root_domain');

        if ($host === '' || $root === '') {
            return false;
        }
        if (self::isDevDomain($root)) {
            return false;
        }

        return $host === $root || str_ends_with($host, '.' . $root);
    }

    /** localhost, IPs and *.local / *.test never have wildcard DNS in practice. */
    private static function isDevDomain(string $domain): bool
    {
        return $domain === 'localhost'
            || str_ends_with($domain, '.localhost')
            || str_ends_with($domain, '.local')
            || str_ends_with($domain, '.test')
            || filter_var($domain, FILTER_VALIDATE_IP) !== false;
    }

    /** Absolute origin for the current request. */
    public static function origin(): string
    {
        // Some SAPIs report HTTPS=off rather than leaving it unset.
        $https  = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $scheme = in_array($https, ['on', '1', 'true'], true)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                ? 'https'
                : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? Config::get('app.root_domain'));
        return $scheme . '://' . $host;
    }

    /**
     * Platform base URL including subfolder if installed in one
     * (e.g. http://localhost:8080/restaurants).
     */
    public static function baseUrl(): string
    {
        if (defined('BASE_URL') && BASE_URL !== '') {
            return rtrim(BASE_URL, '/');
        }

        $origin = self::origin();
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $file   = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $appDir = str_replace('\\', '/', dirname(__DIR__, 2));
        $root   = '';
        if ($script !== '' && $file !== '' && str_starts_with($file, $appDir . '/')) {
            $relative = substr($file, strlen($appDir));
            if (str_ends_with($script, $relative)) {
                $root = substr($script, 0, strlen($script) - strlen($relative));
            }
        }

        return rtrim($origin . $root, '/');
    }

    /**
     * Unique storefront link for the tenant.
     * Host routing: https://{slug}.rootdomain.com/ or https://customdomain.com/
     * Single-host:  http://localhost:8080/restaurants/t/{slug}/
     */
    public static function storefront(Tenant $tenant, string $path = ''): string
    {
        return self::forTenant($tenant, $path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * Unique admin login / portal link for the tenant.
     * Host routing: https://{slug}.rootdomain.com/admin
     * Single-host:  http://localhost:8080/restaurants/t/{slug}/admin
     */
    public static function admin(Tenant $tenant, string $path = 'admin'): string
    {
        $cleanPath = ltrim($path, '/');
        return self::forTenant($tenant, '/' . $cleanPath);
    }

    public static function menu(Tenant $tenant): string
    {
        return self::forTenant($tenant, '/menu');
    }

    public static function orders(Tenant $tenant): string
    {
        return self::forTenant($tenant, '/orders');
    }

    public static function billing(Tenant $tenant): string
    {
        return self::forTenant($tenant, '/admin/billing.php');
    }

    /**
     * Direct unique URL using the restaurant's short access code.
     * e.g. http://localhost:8080/restaurants/t/{access_code}/
     */
    public static function byCode(Tenant $tenant, string $path = ''): string
    {
        $code = $tenant->accessCode();
        if (self::usesHostRouting()) {
            return self::storefront($tenant, $path);
        }
        $sub = $path === '' ? '/' : '/' . ltrim($path, '/');
        return self::baseUrl() . '/t/' . urlencode($code) . $sub;
    }

    private static function forTenant(Tenant $tenant, string $path): string
    {
        if (self::usesHostRouting()) {
            return rtrim($tenant->baseUrl(), '/') . ($path === '' ? '/' : $path);
        }

        $base = self::baseUrl();
        $slug = urlencode($tenant->slug());

        // Clean unique path-based routing: /t/<slug>/...
        if ($path === '' || $path === '/') {
            return $base . '/t/' . $slug . '/';
        }

        return $base . '/t/' . $slug . $path;
    }

    /** The console URL (superadmin SPA). */
    public static function console(string $path = ''): string
    {
        return self::baseUrl() . '/superadmin' . ($path === '' ? '/' : '/' . ltrim($path, '/'));
    }
}
