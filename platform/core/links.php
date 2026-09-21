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

    public static function storefront(Tenant $tenant, string $path = ''): string
    {
        return self::forTenant($tenant, $path === '' ? '/' : '/' . ltrim($path, '/'));
    }

    public static function admin(Tenant $tenant, string $path = 'admin/login.php'): string
    {
        return self::forTenant($tenant, '/' . ltrim($path, '/'));
    }

    public static function billing(Tenant $tenant): string
    {
        return self::forTenant($tenant, '/admin/billing.php');
    }

    private static function forTenant(Tenant $tenant, string $path): string
    {
        if (self::usesHostRouting()) {
            return rtrim($tenant->baseUrl(), '/') . $path;
        }

        $separator = str_contains($path, '?') ? '&' : '?';
        return self::origin() . $path . $separator . '__tenant=' . urlencode($tenant->slug());
    }

    /** The console URL (superadmin SPA). */
    public static function console(string $path = ''): string
    {
        return self::origin() . '/superadmin' . ($path === '' ? '/' : '/' . ltrim($path, '/'));
    }
}
