<?php
/**
 * Per-tenant sign-in codes.
 *
 * Every restaurant gets a short unique code ("K7M2Q") that staff type on the
 * sign-in form. On single-host installations — localhost, sandboxes, a shared
 * landing page — the code is what decides which tenant database a visitor is
 * authenticating against, so it has to exist for accounts created before this
 * release too.
 */

use Resto\Database\Schema;
use Resto\Tenancy\TenantRepository;

return [
    'up' => static function (PDO $conn, string $driver): void {
        if (!Schema::hasTable($conn, 'tenants')) {
            return;
        }

        if (!Schema::hasColumn($conn, 'tenants', 'access_code')) {
            Schema::addColumn($conn, $driver, 'tenants', 'access_code', ['string', 12, 'null']);
        }

        // One code per restaurant, no duplicates.
        Schema::createIndex($conn, $driver, 'tenants', [
            'name'    => 'tenants_access_code_unique',
            'columns' => ['access_code'],
            'unique'  => true,
        ]);

        // Backfill accounts created before codes existed.
        $repo = new TenantRepository();
        foreach ($conn->query("SELECT id FROM tenants WHERE access_code IS NULL OR access_code = ''")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $conn->prepare('UPDATE tenants SET access_code = ?, updated_at = ? WHERE id = ?')
                ->execute([$repo->uniqueAccessCode(), gmdate('Y-m-d H:i:s'), (int) $id]);
        }
    },

    'down' => static function (PDO $conn, string $driver): void {
        // Column drops are driver-specific and harmless to keep — no-op.
    },
];
