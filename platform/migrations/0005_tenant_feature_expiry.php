<?php
/**
 * Give feature overrides an expiry date.
 *
 * Support comps ("API access until the end of the month") need to lapse on
 * their own; 0001 created tenant_features without expires_at.
 */

use Resto\Database\Schema;

return [
    'up' => static function (PDO $conn, string $driver): void {
        if (!Schema::hasTable($conn, 'tenant_features')) {
            return;
        }
        if (!Schema::hasColumn($conn, 'tenant_features', 'expires_at')) {
            Schema::addColumn($conn, $driver, 'tenant_features', 'expires_at', ['datetime', 'null']);
        }
    },

    'down' => static function (PDO $conn, string $driver): void {
        // Column drops are driver-specific and harmless to keep — no-op.
    },
];
