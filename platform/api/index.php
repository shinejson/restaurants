<?php
/**
 * RestaurantOS — JSON API front controller.
 *
 * Both the superadmin console and (optionally) tenant integrations talk to the
 * platform through /api/v1/*. In Apache this is reached through the rewrite in
 * .htaccess; the dev server applies the same rule in tools/php-runtime.mjs.
 *
 * @package Resto\Api
 */

use Resto\Http\Request;
use Resto\Http\Router;

require_once __DIR__ . '/../bootstrap.php';

// Platform traffic: never resolve a tenant for these requests.
\Resto\Tenancy\Resolver::markPlatformRequest();

header('x-api-version: ' . \Resto\Support\Config::get('api.version', 'v1'));

$router = new Router();

foreach (glob(__DIR__ . '/routes/*.php') ?: [] as $routeFile) {
    $register = require $routeFile;
    if (is_callable($register)) {
        $register($router);
    }
}

// ApiException / PDOException / Throwable are rendered as JSON envelopes.
$router->handle(Request::capture());
