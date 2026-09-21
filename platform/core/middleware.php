<?php
/**
 * RestaurantOS — HTTP middleware.
 *
 * Small guards that wrap API routes: authentication, permissions, CSRF and
 * throttling. They live in the HTTP namespace because they know nothing about
 * billing or tenancy — they just decide whether a request may continue.
 *
 * @package Resto\Http
 */

namespace Resto\Http;

use Resto\Platform\Audit;
use Resto\Platform\Auth;
use Resto\Platform\Csrf;
use Resto\Platform\RateLimiter;
use Resto\Support\Config;

final class Middleware
{
    /** Require a signed-in platform user; optionally require a permission. */
    public static function auth(?string $permission = null): callable
    {
        return static function (Request $request, callable $next) use ($permission): Response {
            if (!Auth::check()) {
                throw ApiException::unauthorized('Your session has expired. Please sign in again.');
            }
            if ($permission !== null) {
                Auth::requirePermission($permission);
            }
            return $next();
        };
    }

    /** CSRF protection for state-changing requests. */
    public static function csrf(): callable
    {
        return static function (Request $request, callable $next): Response {
            if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                return $next();
            }
            if (!Csrf::verify($request->header(Csrf::HEADER))) {
                throw new ApiException('Your session token expired. Refresh the page and try again.', 419, [], 'csrf_mismatch');
            }
            return $next();
        };
    }

    /** Throttle per IP (used for login and other sensitive endpoints). */
    public static function throttle(string $name, ?int $perMinute = null): callable
    {
        return static function (Request $request, callable $next) use ($name, $perMinute): Response {
            $max = $perMinute ?? (int) Config::get('api.rate_limit', 120);
            $key = $name . ':' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, $max, 60)) {
                throw new ApiException('Too many requests — slow down a little.', 429, [], 'rate_limited');
            }
            RateLimiter::hit($key, 60);

            return $next();
        };
    }

    /** Mark a request as platform traffic so tenancy resolution stays out of the way. */
    public static function platformContext(): callable
    {
        return static function (Request $request, callable $next): Response {
            \Resto\Tenancy\Resolver::markPlatformRequest();
            return $next();
        };
    }

    /** Write an audit entry for every mutating request (defence in depth). */
    public static function audit(string $action): callable
    {
        return static function (Request $request, callable $next) use ($action): Response {
            $response = $next();
            if ($response->status < 400) {
                Audit::record([
                    'action'      => $action,
                    'description' => $request->method . ' ' . $request->path,
                    'severity'    => 'info',
                ]);
            }
            return $response;
        };
    }
}
