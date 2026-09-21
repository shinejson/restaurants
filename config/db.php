<?php
/**
 * RestaurantOS — legacy database entry point.
 *
 * Historically every page in this application started here: it opened the
 * MySQL connection, started the session and worked out BASE_URL. The SaaS
 * platform still needs all three, but the connection now depends on *which
 * restaurant* is being served — so this file is a thin shim over the platform
 * bootstrap, which resolves the tenant first.
 *
 * Existing pages do not need to change: they still get `$conn`, `BASE_URL` and
 * a started session.
 *
 * @see platform/bootstrap.php
 */

require_once __DIR__ . '/../platform/bootstrap.php';
