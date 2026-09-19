<?php
/**
 * RestaurantOS — support layer.
 *
 * Small, dependency-free helpers shared by the whole platform: environment
 * loading, configuration access, string/id utilities, money and time.
 *
 * @package Resto\Support
 */

namespace Resto\Support;

/* -------------------------------------------------------------------------
 * Env — .env loading
 * ---------------------------------------------------------------------- */

final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    /** Load config/.env (once) without clobbering real environment variables. */
    public static function load(?string $file = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $file = $file ?: dirname(__DIR__, 2) . '/config/.env';
        if (!is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip matching quotes.
            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;

            // Real env vars win (docker/k8s deployments set them directly).
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'true' : 'false');
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

/* -------------------------------------------------------------------------
 * Config — dot-notation configuration access
 * ---------------------------------------------------------------------- */

final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(?string $dir = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $dir = $dir ?: dirname(__DIR__, 2) . '/config';

        foreach (glob($dir . '/*.php') as $file) {
            $key = basename($file, '.php');

            // db.php is the legacy entry point (it opens a database connection
            // as a side effect) — never treat it as a config file.
            if ($key === 'db') {
                continue;
            }

            /** @var array<string,mixed> $data */
            $data = require $file;
            if (!is_array($data)) {
                continue;
            }
            // Each file contributes top-level namespaces (app.*, database.*, …)
            // and also stays addressable by its file name (app.php => "app").
            self::$items = array_replace_recursive(self::$items, $data);
            if (!isset(self::$items[$key])) {
                self::$items[$key] = $data;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $segments = explode('.', $key);
        $value    = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        self::load();
        $segments = explode('.', $key);
        $ref      = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public static function isLocal(): bool
    {
        return in_array(self::get('app.env'), ['local', 'development', 'dev', 'testing'], true);
    }

    /** Absolute path helper (relative paths in config are repo-relative). */
    public static function path(string $relative): string
    {
        $root = dirname(__DIR__, 2);
        return str_starts_with($relative, '/') ? $relative : $root . '/' . $relative;
    }
}

/* -------------------------------------------------------------------------
 * Str — identifiers & text helpers
 * ---------------------------------------------------------------------- */

final class Str
{
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** A URL/database safe slug. */
    public static function slug(string $value, int $max = 40): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            $value = 'tenant';
        }
        return substr($value, 0, $max);
    }

    /** Make a slug unique against a callback: fn(string $candidate): bool */
    public static function uniqueSlug(string $value, callable $exists, int $max = 40): string
    {
        $base = self::slug($value, $max);
        $slug = $base;
        $i    = 2;
        while ($exists($slug)) {
            $suffix = '-' . $i++;
            $slug   = substr($base, 0, $max - strlen($suffix)) . $suffix;
            if ($i > 500) {
                $slug = $base . '-' . substr(self::token(3), 0, 6);
                break;
            }
        }
        return $slug;
    }

    /** Database identifier for a tenant (never user-controlled verbatim). */
    public static function dbIdentifier(string $prefix, string $slug): string
    {
        return $prefix . preg_replace('/[^a-z0-9_]/', '_', str_replace('-', '_', strtolower($slug)));
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out   = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $out .= strtoupper(substr($part, 0, 1));
        }
        return $out !== '' ? $out : '?';
    }

    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return $email;
        }
        $visible = substr($user, 0, 2);
        return $visible . str_repeat('•', max(1, strlen($user) - 2)) . '@' . $domain;
    }

    public static function truncate(string $value, int $length = 80): string
    {
        return strlen($value) <= $length ? $value : substr($value, 0, $length - 1) . '…';
    }
}

/* -------------------------------------------------------------------------
 * Clock — time helpers, all UTC
 * ---------------------------------------------------------------------- */

final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    /** Start of the current billing month, e.g. 2026-09-01 00:00:00 */
    public static function periodStart(?string $date = null): string
    {
        return gmdate('Y-m-01 00:00:00', $date ? strtotime($date) : time());
    }

    public static function addDays(string $from, int $days): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + ($days * 86400));
    }

    public static function addMonths(string $from, int $months): string
    {
        $ts = strtotime($from . ' UTC');
        return gmdate('Y-m-d H:i:s', strtotime('+' . $months . ' month', $ts));
    }

    public static function daysBetween(string $from, ?string $to = null): int
    {
        $to = $to ?: self::now();
        return (int) floor((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400);
    }

    public static function daysLeft(?string $deadline): ?int
    {
        if (!$deadline) {
            return null;
        }
        return (int) ceil((strtotime($deadline . ' UTC') - time()) / 86400);
    }

    public static function human(?string $timestamp): string
    {
        if (!$timestamp) {
            return '—';
        }
        $diff = time() - strtotime($timestamp . ' UTC');
        if ($diff < 60) {
            return 'just now';
        }
        foreach ([[31536000, 'y'], [2592000, 'mo'], [604800, 'w'], [86400, 'd'], [3600, 'h'], [60, 'm']] as [$unit, $label]) {
            if ($diff >= $unit) {
                return floor($diff / $unit) . $label . ' ago';
            }
        }
        return 'just now';
    }

    /** Month buckets for the last N months: ['2026-09' => 'Sep 2026', …] */
    /** Days elapsed since a timestamp (0 when unknown). */
    public static function daysSince(?string $timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }
        return max(0, (int) floor((strtotime(self::now()) - strtotime($timestamp)) / 86400));
    }

    public static function monthBuckets(int $months = 12): array
    {
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i month", strtotime(gmdate('Y-m-01')));
            $buckets[gmdate('Y-m', $ts)] = gmdate('M Y', $ts);
        }
        return $buckets;
    }
}

/* -------------------------------------------------------------------------
 * Money — minor-unit safe formatting
 * ---------------------------------------------------------------------- */

final class Money
{
    public static function format(float|int|string $amount, string $currency = 'USD'): string
    {
        $symbol = self::symbol($currency);
        return $symbol . number_format((float) $amount, 2);
    }

    public static function compact(float|int|string $amount, string $currency = 'USD'): string
    {
        $amount = (float) $amount;
        $symbol = self::symbol($currency);
        if (abs($amount) >= 1000000) {
            return $symbol . rtrim(rtrim(number_format($amount / 1000000, 1), '0'), '.') . 'M';
        }
        if (abs($amount) >= 1000) {
            return $symbol . rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'k';
        }
        return $symbol . number_format($amount, 0);
    }

    public static function symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'KES' => 'KSh ',
            'NGN' => '₦',
            'INR' => '₹',
            'ZAR' => 'R',
            'AED' => 'AED ',
            default => strtoupper($currency) . ' ',
        };
    }

    public static function percentChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : null;
        }
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
