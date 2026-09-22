<?php
/**
 * RestaurantOS — migrations.
 *
 * The control-plane schema is versioned: every file in platform/migrations is
 * applied once and recorded in `platform_migrations`. Fresh installs and
 * upgrades follow exactly the same path — something the original application
 * never had (it shipped ad-hoc "run this page once" upgrade scripts).
 *
 * @package Resto\Database
 */

namespace Resto\Database;

use PDO;
use Resto\Support\Clock;
use Resto\Support\Config;
use Resto\Support\Str;

final class Migrator
{
    private static bool $ran = false;

    public static function path(): string
    {
        return dirname(__DIR__) . '/migrations';
    }

    /**
     * Apply every pending migration.
     *
     * @param bool $force re-run even when the recorded batch matches
     */
    public static function run(PDO $conn, bool $force = false): array
    {
        if (self::$ran && !$force) {
            return [];
        }
        self::$ran = true;

        $driver = $conn instanceof Connection ? $conn->driverName : 'mysql';

        // Bookkeeping table first.
        Schema::createTable($conn, $driver, 'platform_migrations', [
            'id'         => ['id'],
            'migration'  => ['string', 191, 'unique'],
            'batch'      => ['int', 'default' => 1],
            'applied_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ]);

        $applied = [];
        foreach ($conn->query('SELECT migration FROM platform_migrations')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $applied[(string) $name] = true;
        }

        $batch = (int) $conn->query('SELECT COALESCE(MAX(batch), 0) FROM platform_migrations')->fetchColumn() + 1;
        $files = glob(self::path() . '/*.php') ?: [];
        sort($files);

        $executed = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (isset($applied[$name]) && !$force) {
                continue;
            }

            $migration = require $file;
            if (!is_array($migration) || !isset($migration['up'])) {
                continue;
            }

            $migration['up']($conn, $driver);

            $stmt = $conn->prepare('INSERT INTO platform_migrations (migration, batch, applied_at) VALUES (?, ?, ?)');
            $stmt->execute([$name, $batch, Clock::now()]);

            $executed[] = $name;
        }

        return $executed;
    }

    /** Applied migration names (for the console's system page). */
    public static function applied(PDO $conn): array
    {
        try {
            return $conn->query('SELECT migration, batch, applied_at FROM platform_migrations ORDER BY id ASC')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }
}

/**
 * Control-plane schema. Rendered by Schema::createTable() for MySQL or SQLite.
 */
final class ControlPlaneSchema
{
    public static function migrate(PDO $conn, string $driver): void
    {
        /* ---------------- people & access ---------------- */

        Schema::createTable($conn, $driver, 'platform_users', [
            'id'                 => ['id'],
            'name'               => ['string', 120],
            'email'              => ['string', 191, 'unique'],
            'password_hash'      => ['string', 255],
            'role'               => ['string', 24, 'default' => 'admin'],   // owner|admin|support|billing|viewer
            'status'             => ['string', 24, 'default' => 'active'],  // active|suspended|invited
            'avatar_color'       => ['string', 16, 'default' => '#6366f1'],
            'phone'              => ['string', 40, 'null'],
            'job_title'          => ['string', 120, 'null'],
            'two_factor_enabled' => ['bool', 'default' => 0],
            'last_login_at'      => ['datetime', 'null'],
            'last_login_ip'      => ['string', 64, 'null'],
            'failed_attempts'    => ['int', 'default' => 0],
            'locked_until'       => ['datetime', 'null'],
            'created_at'         => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at'         => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['index' => [['columns' => ['role']], ['columns' => ['status']]]]);

        Schema::createTable($conn, $driver, 'platform_sessions', [
            'id'           => ['id'],
            'user_id'      => ['int', 'index' => true],
            'token_hash'   => ['string', 128, 'unique'],
            'ip'           => ['string', 64, 'null'],
            'user_agent'   => ['string', 255, 'null'],
            'device'       => ['string', 120, 'null'],
            'last_seen_at' => ['datetime', 'null'],
            'expires_at'   => ['datetime', 'null'],
            'revoked_at'   => ['datetime', 'null'],
            'created_at'   => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ]);

        Schema::createTable($conn, $driver, 'platform_settings', [
            'id'            => ['id'],
            'setting_key'   => ['string', 191, 'unique'],
            'setting_value' => ['text', 'null'],
            'setting_group' => ['string', 64, 'default' => 'general'],
            'updated_at'    => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ]);

        Schema::createTable($conn, $driver, 'platform_audit_logs', [
            'id'          => ['id'],
            'actor_type'  => ['string', 24, 'default' => 'system'], // platform|tenant|system|api
            'actor_id'    => ['int', 'null'],
            'actor_name'  => ['string', 120, 'null'],
            'tenant_id'   => ['int', 'null'],
            'action'      => ['string', 96],
            'target_type' => ['string', 64, 'null'],
            'target_id'   => ['string', 64, 'null'],
            'description' => ['string', 255, 'null'],
            'severity'    => ['string', 16, 'default' => 'info'], // info|notice|warning|critical
            'ip'          => ['string', 64, 'null'],
            'user_agent'  => ['string', 255, 'null'],
            'meta'        => ['json', 'null'],
            'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['index' => [
            ['columns' => ['created_at']],
            ['columns' => ['tenant_id']],
            ['columns' => ['action']],
        ]]);

        Schema::createTable($conn, $driver, 'platform_notifications', [
            'id'         => ['id'],
            'tenant_id'  => ['int', 'null'],
            'type'       => ['string', 48, 'default' => 'system'],
            'level'      => ['string', 16, 'default' => 'info'],
            'title'      => ['string', 191],
            'body'       => ['text', 'null'],
            'action_url' => ['string', 255, 'null'],
            'read_at'    => ['datetime', 'null'],
            'created_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['index' => [['columns' => ['created_at']]]]);

        /* ---------------- plans & tenants ---------------- */

        Schema::createTable($conn, $driver, 'plans', [
            'id'            => ['id'],
            'code'          => ['string', 64, 'unique'],
            'name'          => ['string', 120],
            'tagline'       => ['string', 191, 'null'],
            'description'   => ['text', 'null'],
            'price_monthly' => ['decimal', 'default' => 0],
            'price_yearly'  => ['decimal', 'default' => 0],
            'currency'      => ['string', 8, 'default' => 'USD'],
            'trial_days'    => ['int', 'default' => 14],
            'is_public'     => ['bool', 'default' => 1],
            'is_archived'   => ['bool', 'default' => 0],
            'sort_order'    => ['int', 'default' => 0],
            'badge'         => ['string', 32, 'null'],
            'accent_color'  => ['string', 16, 'default' => '#6366f1'],
            'limits'        => ['json', 'null'],
            'features'      => ['json', 'null'],
            'created_at'    => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at'    => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ]);

        Schema::createTable($conn, $driver, 'tenants', [
            'id'               => ['id'],
            'uuid'             => ['string', 36, 'unique'],
            'slug'             => ['string', 64, 'unique'],
            // Short code staff type at sign-in to identify the restaurant.
            'access_code'      => ['string', 12, 'null' => true],
            'name'             => ['string', 160],
            'legal_name'       => ['string', 160, 'null'],
            'owner_name'       => ['string', 120, 'null'],
            'owner_email'      => ['string', 191],
            'owner_phone'      => ['string', 40, 'null'],
            'country'          => ['string', 64, 'null'],
            'city'             => ['string', 96, 'null'],
            'timezone'         => ['string', 64, 'default' => 'UTC'],
            'currency'         => ['string', 8, 'default' => 'USD'],
            'locale'           => ['string', 12, 'default' => 'en'],
            'status'           => ['string', 24, 'default' => 'trial'], // trial|active|past_due|suspended|cancelled
            'plan_id'          => ['int', 'null'],
            'billing_cycle'    => ['string', 16, 'default' => 'monthly'],
            'seat_count'       => ['int', 'default' => 5],
            'db_name'          => ['string', 96, 'unique'],
            'db_driver'        => ['string', 16, 'default' => 'mysql'],
            'storage_path'     => ['string', 255, 'null'],
            'subdomain'        => ['string', 96, 'null'],
            'custom_domain'    => ['string', 191, 'null'],
            'trial_ends_at'    => ['datetime', 'null'],
            'onboarded_at'     => ['datetime', 'null'],
            'activated_at'     => ['datetime', 'null'],
            'suspended_at'     => ['datetime', 'null'],
            'cancelled_at'     => ['datetime', 'null'],
            'suspended_reason' => ['string', 255, 'null'],
            'last_activity_at' => ['datetime', 'null'],
            'health_score'     => ['int', 'default' => 100],
            'mrr'              => ['decimal', 'default' => 0],
            'notes'            => ['text', 'null'],
            'metadata'         => ['json', 'null'],
            'created_by'       => ['int', 'null'],
            'created_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], [
            'index'  => [
                ['columns' => ['status']],
                ['columns' => ['plan_id']],
                ['columns' => ['created_at']],
            ],
            'unique' => [['access_code']],
        ]);

        Schema::createTable($conn, $driver, 'tenant_domains', [
            'id'          => ['id'],
            'tenant_id'   => ['int', 'index' => true],
            'hostname'    => ['string', 191, 'unique'],
            'is_primary'  => ['bool', 'default' => 0],
            'verified_at' => ['datetime', 'null'],
            'ssl_status'  => ['string', 24, 'default' => 'pending'],
            'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ]);

        Schema::createTable($conn, $driver, 'tenant_features', [
            'id'          => ['id'],
            'tenant_id'   => ['int'],
            'feature_key' => ['string', 64],
            'enabled'     => ['bool', 'default' => 1],
            'limit_value' => ['int', 'null'],
            'note'        => ['string', 191, 'null'],
            'expires_at'  => ['datetime', 'null'],
            'updated_by'  => ['int', 'null'],
            'updated_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['unique' => [['tenant_id', 'feature_key']]]);

        /* ---------------- billing ---------------- */

        Schema::createTable($conn, $driver, 'subscriptions', [
            'id'                   => ['id'],
            'tenant_id'            => ['int', 'index' => true],
            'plan_id'              => ['int'],
            'status'               => ['string', 24, 'default' => 'trialing'], // trialing|active|past_due|paused|cancelled
            'billing_cycle'        => ['string', 16, 'default' => 'monthly'],
            'quantity'             => ['int', 'default' => 1],
            'unit_amount'          => ['decimal', 'default' => 0],
            'amount'               => ['decimal', 'default' => 0],
            'currency'             => ['string', 8, 'default' => 'USD'],
            'discount_percent'     => ['decimal', 'default' => 0],
            'trial_start'          => ['datetime', 'null'],
            'trial_end'            => ['datetime', 'null'],
            'current_period_start' => ['datetime', 'null'],
            'current_period_end'   => ['datetime', 'null'],
            'cancel_at_period_end' => ['bool', 'default' => 0],
            'cancelled_at'         => ['datetime', 'null'],
            'paused_at'            => ['datetime', 'null'],
            'started_at'           => ['datetime', 'null'],
            'ended_at'             => ['datetime', 'null'],
            'gateway'              => ['string', 32, 'default' => 'manual'],
            'gateway_ref'          => ['string', 191, 'null'],
            'created_at'           => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at'           => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['index' => [['columns' => ['status']]]]);

        Schema::createTable($conn, $driver, 'invoices', [
            'id'              => ['id'],
            'tenant_id'       => ['int', 'index' => true],
            'subscription_id' => ['int', 'null'],
            'number'          => ['string', 48, 'unique'],
            'status'          => ['string', 24, 'default' => 'open'], // draft|open|paid|past_due|void|refunded
            'currency'        => ['string', 8, 'default' => 'USD'],
            'subtotal'        => ['decimal', 'default' => 0],
            'discount'        => ['decimal', 'default' => 0],
            'tax'             => ['decimal', 'default' => 0],
            'total'           => ['decimal', 'default' => 0],
            'amount_paid'     => ['decimal', 'default' => 0],
            'period_start'    => ['datetime', 'null'],
            'period_end'      => ['datetime', 'null'],
            'issued_at'       => ['datetime', 'null'],
            'due_at'          => ['datetime', 'null'],
            'paid_at'         => ['datetime', 'null'],
            'voided_at'       => ['datetime', 'null'],
            'lines'           => ['json', 'null'],
            'notes'           => ['string', 255, 'null'],
            'payment_method'  => ['string', 32, 'default' => 'manual'],
            'gateway_ref'     => ['string', 191, 'null'],
            'created_at'      => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at'      => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['index' => [['columns' => ['status']], ['columns' => ['due_at']]]]);

        /* ---------------- metering & support ---------------- */

        Schema::createTable($conn, $driver, 'usage_events', [
            'id'          => ['id'],
            'tenant_id'   => ['int', 'index' => true],
            'metric'      => ['string', 48],
            'quantity'    => ['int', 'default' => 1],
            'source'      => ['string', 48, 'default' => 'app'],
            'occurred_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
            'meta'        => ['json', 'null'],
        ], ['index' => [['columns' => ['metric']], ['columns' => ['occurred_at']]]]);

        Schema::createTable($conn, $driver, 'usage_counters', [
            'id'         => ['id'],
            'tenant_id'  => ['int'],
            'metric'     => ['string', 48],
            'period'     => ['string', 8],   // YYYY-MM
            'value'      => ['int', 'default' => 0],
            'updated_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['unique' => [['tenant_id', 'metric', 'period']]]);

        Schema::createTable($conn, $driver, 'support_notes', [
            'id'          => ['id'],
            'tenant_id'   => ['int', 'index' => true],
            'author_id'   => ['int', 'null'],
            'author_name' => ['string', 120, 'null'],
            'body'        => ['text'],
            'is_pinned'   => ['bool', 'default' => 0],
            'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ]);

        Schema::createTable($conn, $driver, 'impersonation_tokens', [
            'id'               => ['id'],
            'tenant_id'        => ['int', 'index' => true],
            'platform_user_id' => ['int'],
            'token_hash'       => ['string', 128, 'unique'],
            'reason'           => ['string', 191, 'null'],
            'expires_at'       => ['datetime'],
            'used_at'          => ['datetime', 'null'],
            'created_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ]);

        Schema::createTable($conn, $driver, 'tenant_activity', [
            'id'         => ['id'],
            'tenant_id'  => ['int', 'index' => true],
            'type'       => ['string', 48],
            'title'      => ['string', 191],
            'meta'       => ['json', 'null'],
            'created_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
        ], ['index' => [['columns' => ['created_at']]]]);
    }
}
