<?php
/**
 * RestaurantOS — tenant provisioning.
 *
 * TenantSchema is the canonical definition of a restaurant database. The
 * legacy application never shipped one (its tables were created by hand and
 * patched by "upgrade_db.php"-style scripts), which is why onboarding a new
 * restaurant used to be a manual, error-prone job. Provisioning a tenant now
 * means: create the database, run this schema, seed starter content, create
 * the subscription and record everything in the audit trail.
 *
 * @package Resto\Tenancy
 */

namespace Resto\Tenancy;

use PDO;
use Resto\Database\Connection;
use Resto\Database\Manager;
use Resto\Database\Schema;
use Resto\Support\Clock;
use Resto\Support\Str;

/* -------------------------------------------------------------------------
 * TenantSchema — the restaurant application's tables
 * ---------------------------------------------------------------------- */

final class TenantSchema
{
    /** Tables in dependency order. */
    public static function definitions(): array
    {
        return [
            /* -------- people -------- */
            'customers' => [
                'columns' => [
                    'id'                => ['id'],
                    'username'          => ['string', 64, 'unique'],
                    'email'             => ['string', 191, 'unique'],
                    'password'          => ['string', 255],
                    'full_name'         => ['string', 160],
                    'phone'             => ['string', 40, 'null'],
                    'address'           => ['text', 'null'],
                    'type'              => ['string', 24, 'default' => 'customer'],
                    'google_id'         => ['string', 191, 'null'],
                    'apple_id'          => ['string', 191, 'null'],
                    'facebook_id'       => ['string', 191, 'null'],
                    'twitter_id'        => ['string', 191, 'null'],
                    'status'            => ['string', 24, 'default' => 'active'],
                    'email_verified_at' => ['datetime', 'null'],
                    'created_at'        => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at'        => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'admins' => [
                'columns' => [
                    'id'            => ['id'],
                    'username'      => ['string', 64, 'unique'],
                    'password'      => ['string', 255],
                    'email'         => ['string', 191, 'null'],
                    'full_name'     => ['string', 160, 'null'],
                    'phone'         => ['string', 40, 'null'],
                    'role'          => ['string', 24, 'default' => 'admin'],
                    'is_active'     => ['bool', 'default' => 1],
                    'last_login_at' => ['datetime', 'null'],
                    'created_at'    => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at'    => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'roles' => [
                'columns' => [
                    'id'          => ['id'],
                    'slug'        => ['string', 64, 'unique'],
                    'name'        => ['string', 96],
                    'description' => ['string', 191, 'null'],
                    'icon'        => ['string', 48, 'null'],
                    'color'       => ['string', 24, 'null'],
                    'is_system'   => ['bool', 'default' => 0],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'role_permissions' => [
                'columns' => [
                    'id'             => ['id'],
                    'role_slug'      => ['string', 64],
                    'permission_key' => ['string', 64],
                    'allowed'        => ['bool', 'default' => 1],
                    'created_at'     => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'unique' => [['role_slug', 'permission_key']],
            ],
            'password_resets' => [
                'columns' => [
                    'id'         => ['id'],
                    'email'      => ['string', 191],
                    'token'      => ['string', 255],
                    'expires_at' => ['datetime', 'null'],
                    'created_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['email']], ['columns' => ['token']]],
            ],
            'companies' => [
                'columns' => [
                    'id'         => ['id'],
                    'user_id'    => ['int', 'null'],
                    'name'       => ['string', 160],
                    'email'      => ['string', 191, 'null'],
                    'phone'      => ['string', 40, 'null'],
                    'address'    => ['string', 255, 'null'],
                    'location'   => ['string', 160, 'null'],
                    'created_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['user_id']]],
            ],

            /* -------- catalogue -------- */
            'main_categories' => [
                'columns' => [
                    'id'          => ['id'],
                    'name'        => ['string', 120],
                    'description' => ['string', 255, 'null'],
                    'image_url'   => ['string', 255, 'null'],
                    'sort_order'  => ['int', 'default' => 0],
                    'is_active'   => ['bool', 'default' => 1],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'sub_categories' => [
                'columns' => [
                    'id'               => ['id'],
                    'main_category_id' => ['int', 'null'],
                    'name'             => ['string', 120],
                    'description'      => ['string', 255, 'null'],
                    'image_url'        => ['string', 255, 'null'],
                    'sort_order'       => ['int', 'default' => 0],
                    'is_active'        => ['bool', 'default' => 1],
                    'created_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['main_category_id']]],
            ],
            'food_items' => [
                'columns' => [
                    'id'               => ['id'],
                    'item_name'        => ['string', 191],
                    'description'      => ['text', 'null'],
                    'price'            => ['decimal', 'default' => 0],
                    'promo_price'      => ['decimal', 'null'],
                    'cost'             => ['decimal', 'default' => 0],
                    'image_url'        => ['string', 255, 'null'],
                    'sub_category_id'  => ['int', 'null'],
                    'tax_group'        => ['string', 64, 'default' => 'Standard'],
                    'tax_group_id'     => ['int', 'null'],
                    'is_vegetarian'    => ['bool', 'default' => 0],
                    'is_spicy'         => ['bool', 'default' => 0],
                    'is_rate_inclusive' => ['bool', 'default' => 0],
                    'calories'         => ['int', 'null'],
                    'cooking_time'     => ['int', 'null'],
                    'inventory_count'  => ['int', 'default' => 0],
                    'sold_by_weight'   => ['bool', 'default' => 0],
                    'printer_id'       => ['int', 'null'],
                    'inactive'         => ['bool', 'default' => 0],
                    'fields'           => ['text', 'null'],
                    'other'            => ['text', 'null'],
                    'created_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [
                    ['columns' => ['sub_category_id']],
                    ['columns' => ['inactive']],
                ],
            ],
            'inventory_logs' => [
                'columns' => [
                    'id'             => ['id'],
                    'item_id'        => ['int'],
                    'user_id'        => ['int', 'null'],
                    'action'         => ['string', 48, 'default' => 'adjust'],
                    'quantity'       => ['int', 'default' => 0],
                    'previous_value' => ['int', 'default' => 0],
                    'new_value'      => ['int', 'default' => 0],
                    'created_at'     => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['item_id']]],
            ],

            /* -------- orders -------- */
            'orders' => [
                'columns' => [
                    'id'               => ['id'],
                    'user_id'          => ['int', 'null'],
                    'company_id'       => ['int', 'null'],
                    'order_reference'  => ['string', 64, 'index' => true],
                    'status'           => ['string', 32, 'default' => 'Placed'],
                    'order_type'       => ['string', 32, 'null'],
                    'total'            => ['decimal', 'default' => 0],
                    'delivery_charge'  => ['decimal', 'default' => 0],
                    'delivery_zone_id' => ['int', 'null'],
                    'table_number'     => ['string', 64, 'null'],
                    'room_number'      => ['string', 64, 'null'],
                    'guest_count'      => ['int', 'null'],
                    'extra_charge'     => ['decimal', 'default' => 0],
                    'discount_percent' => ['decimal', 'default' => 0],
                    'payment_status'   => ['string', 32, 'default' => 'unpaid'],
                    'payment_method'   => ['string', 32, 'null'],
                    'notes'            => ['text', 'null'],
                    'username'         => ['string', 96, 'null'],
                    'created_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at'       => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [
                    ['columns' => ['status']],
                    ['columns' => ['created_at']],
                    ['columns' => ['user_id']],
                ],
            ],
            'order_items' => [
                'columns' => [
                    'id'              => ['id'],
                    'order_id'        => ['int'],
                    'food_item_id'    => ['int', 'null'],
                    'item_name'       => ['string', 191, 'null'],
                    'description'     => ['text', 'null'],
                    'image_url'       => ['string', 255, 'null'],
                    'quantity'        => ['int', 'default' => 1],
                    'price'           => ['decimal', 'default' => 0],
                    'promo_price'     => ['decimal', 'null'],
                    'request_price'   => ['decimal', 'default' => 0],
                    'special_requests' => ['text', 'null'],
                    'recipient_name'  => ['string', 120, 'null'],
                    'created_at'      => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['order_id']], ['columns' => ['food_item_id']]],
            ],

            /* -------- service floor -------- */
            'restaurant_tables' => [
                'columns' => [
                    'id'           => ['id'],
                    'table_name'   => ['string', 96, 'unique'],
                    'seat_count'   => ['int', 'default' => 2],
                    'status'       => ['string', 32, 'default' => 'Available'],
                    'order_status' => ['string', 32, 'null'],
                    'notes'        => ['text', 'null'],
                    'qr_code'      => ['string', 191, 'null'],
                    'created_at'   => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at'   => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'restaurant_seats' => [
                'columns' => [
                    'id'          => ['id'],
                    'table_id'    => ['int'],
                    'seat_name'   => ['string', 96],
                    'seat_number' => ['int', 'default' => 1],
                    'status'      => ['string', 32, 'default' => 'Available'],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['table_id']]],
            ],
            'table_logs' => [
                'columns' => [
                    'id'         => ['id'],
                    'table_name' => ['string', 96, 'null'],
                    'event_type' => ['string', 48, 'default' => 'info'],
                    'message'    => ['string', 255, 'null'],
                    'created_at' => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['created_at']]],
            ],

            /* -------- fulfilment -------- */
            'delivery_zones' => [
                'columns' => [
                    'id'           => ['id'],
                    'zone_name'    => ['string', 120],
                    'delivery_fee' => ['decimal', 'default' => 0],
                    'is_active'    => ['bool', 'default' => 1],
                    'created_at'   => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'printers' => [
                'columns' => [
                    'id'              => ['id'],
                    'name'            => ['string', 120],
                    'type'            => ['string', 48, 'default' => 'receipt'],
                    'connection_type' => ['string', 32, 'default' => 'network'],
                    'ip_address'      => ['string', 64, 'null'],
                    'port'            => ['int', 'null'],
                    'paper_width'     => ['int', 'default' => 80],
                    'is_default'      => ['bool', 'default' => 0],
                    'status'          => ['string', 32, 'default' => 'active'],
                    'created_at'      => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'terminals' => [
                'columns' => [
                    'id'                => ['id'],
                    'name'              => ['string', 120],
                    'terminal_code'     => ['string', 64, 'unique'],
                    'location'          => ['string', 120, 'null'],
                    'receipt_printer_id' => ['int', 'null'],
                    'kot_printer_id'    => ['int', 'null'],
                    'bot_printer_id'    => ['int', 'null'],
                    'status'            => ['string', 32, 'default' => 'active'],
                    'created_at'        => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],

            /* -------- tax -------- */
            'tax_groups' => [
                'columns' => [
                    'id'          => ['id'],
                    'name'        => ['string', 120],
                    'description' => ['string', 255, 'null'],
                    'rate'        => ['decimal', 'default' => 0],
                    'is_active'   => ['bool', 'default' => 1],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'tax_items' => [
                'columns' => [
                    'id'          => ['id'],
                    'name'        => ['string', 120],
                    'description' => ['string', 255, 'null'],
                    'rate'        => ['decimal', 'default' => 0],
                    'is_active'   => ['bool', 'default' => 1],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'tax_group_items' => [
                'columns' => [
                    'id'          => ['id'],
                    'tax_group_id' => ['int'],
                    'tax_item_id' => ['int'],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'unique' => [['tax_group_id', 'tax_item_id']],
            ],

            /* -------- events -------- */
            'events' => [
                'columns' => [
                    'id'          => ['id'],
                    'title'       => ['string', 191],
                    'description' => ['text', 'null'],
                    'event_date'  => ['date', 'null'],
                    'start_time'  => ['string', 16, 'null'],
                    'location'    => ['string', 191, 'null'],
                    'image_url'   => ['string', 255, 'null'],
                    'params'      => ['text', 'null'],
                    'is_active'   => ['bool', 'default' => 1],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'event_packages' => [
                'columns' => [
                    'id'                  => ['id'],
                    'name'                => ['string', 191],
                    'description'         => ['text', 'null'],
                    'base_price_per_head' => ['decimal', 'default' => 0],
                    'image_url'           => ['string', 255, 'null'],
                    'params'              => ['text', 'null'],
                    'is_active'           => ['bool', 'default' => 1],
                    'created_at'          => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
            'event_bookings' => [
                'columns' => [
                    'id'             => ['id'],
                    'user_id'        => ['int', 'null'],
                    'package_id'     => ['int', 'null'],
                    'name'           => ['string', 191, 'null'],
                    'type'           => ['string', 64, 'null'],
                    'event_date'     => ['date', 'null'],
                    'guest_count'    => ['int', 'default' => 0],
                    'total_amount'   => ['decimal', 'default' => 0],
                    'payment_status' => ['string', 32, 'default' => 'unpaid'],
                    'status'         => ['string', 32, 'default' => 'pending'],
                    'notes'          => ['text', 'null'],
                    'created_at'     => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['event_date']], ['columns' => ['status']]],
            ],
            'event_materials' => [
                'columns' => [
                    'id'          => ['id'],
                    'booking_id'  => ['int'],
                    'name'        => ['string', 191],
                    'quantity'    => ['int', 'default' => 1],
                    'unit_price'  => ['decimal', 'default' => 0],
                    'total_price' => ['decimal', 'default' => 0],
                    'created_at'  => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
                'index' => [['columns' => ['booking_id']]],
            ],

            /* -------- configuration -------- */
            'settings' => [
                'columns' => [
                    'id'            => ['id'],
                    'setting_key'   => ['string', 191, 'unique'],
                    'setting_value' => ['text', 'null'],
                    'category'      => ['string', 64, 'default' => 'general'],
                    'created_at'    => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at'    => ['datetime', 'default' => 'CURRENT_TIMESTAMP'],
                ],
            ],
        ];
    }

    /** Create every table (idempotent). */
    public static function migrate(Connection $conn, bool $repair = true): array
    {
        $created = [];
        foreach (self::definitions() as $table => $definition) {
            Schema::createTable(
                $conn,
                $conn->driverName,
                $table,
                $definition['columns'],
                array_merge(
                    ['index' => $definition['index'] ?? [], 'unique' => $definition['unique'] ?? []],
                    []
                )
            );
            $created[] = $table;
        }

        if ($repair) {
            self::repair($conn);
        }

        return $created;
    }

    /**
     * Add columns that are missing from an existing restaurant database.
     * Mirrors what the old upgrade scripts did, but systematic and safe.
     */
    public static function repair(Connection $conn): array
    {
        $fixed = [];
        foreach (self::definitions() as $table => $definition) {
            if (!Schema::hasTable($conn, $table)) {
                continue;
            }
            foreach ($definition['columns'] as $column => $spec) {
                if (Schema::hasColumn($conn, $table, $column)) {
                    continue;
                }
                Schema::addColumn($conn, $conn->driverName, $table, $column, (array) $spec);
                $fixed[] = "$table.$column";
            }
        }
        return $fixed;
    }

    /** Default configuration rows for a new restaurant. */
    public static function defaultSettings(array $tenant): array
    {
        return [
            'company_name'     => $tenant['name'] ?? 'My Restaurant',
            'contact_email'    => $tenant['owner_email'] ?? '',
            'contact_phone'    => $tenant['owner_phone'] ?? '',
            'contact_address'  => trim(($tenant['city'] ?? '') . ' ' . ($tenant['country'] ?? '')),
            'currency'         => $tenant['currency'] ?? 'USD',
            'currency_symbol'  => \Resto\Support\Money::symbol($tenant['currency'] ?? 'USD'),
            'timezone'         => $tenant['timezone'] ?? 'UTC',
            'site_tagline'     => 'Order online, pick up in store or dine in.',
            'smtp_host'        => '',
            'smtp_port'        => '587',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => 'tls',
                        'receipt_footer'   => 'Thank you for dining with us!',
            'service_charge'   => '0',
            'tax_inclusive_pricing' => '0',
            // Structured-data / landing-page display values
            'site_cuisine'     => 'Local, Continental',
            'geo_latitude'     => '0.0000',
            'geo_longitude'    => '0.0000',
        ];
    }
}

/* -------------------------------------------------------------------------
 * StarterData — content for a brand-new restaurant
 * ---------------------------------------------------------------------- */

final class StarterData
{
    /**
     * @param bool $full seed a rich demo dataset (menu, orders, customers…)
     */
    public static function seed(Connection $conn, array $tenant, bool $full = false): void
    {
        $now = Clock::now();

        // The whole dataset is written in a single transaction. Committing each
        // row on its own made provisioning a demo tenant thousands of fsyncs
        // slow, and a failure halfway through left a half-seeded restaurant
        // behind. ($conn may already be inside a transaction — the migration
        // runner does that — so only own one when nobody else does.)
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) {
            $conn->beginTransaction();
        }

        try {
            self::seedSettings($conn, $tenant, $now);
            self::seedRoles($conn);
            $adminId = self::seedAdmin($conn, $tenant, $now);
            self::seedTaxes($conn, $now);
            self::seedCatalogue($conn, $now, $full);
            self::seedFloor($conn, $now, $full);
            self::seedDelivery($conn, $now);
            self::seedPrinters($conn, $now);

            if ($full) {
                self::seedCustomersAndOrders($conn, $now);
                self::seedEvents($conn, $now);
            }

            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private static function seedSettings(Connection $conn, array $tenant, string $now): void
    {
        $stmt = $conn->prepare(
            'INSERT INTO settings (setting_key, setting_value, category, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach (TenantSchema::defaultSettings($tenant) as $key => $value) {
            $category = match (true) {
                str_starts_with($key, 'smtp_') => 'email',
                str_starts_with($key, 'contact_') => 'contact',
                str_starts_with($key, 'currency') => 'localisation',
                default => 'general',
            };
            $stmt->execute([$key, (string) $value, $category, $now, $now]);
        }
    }

    private static function seedRoles(Connection $conn): void
    {
        $roles = [
            ['admin', 'Super Admin', 'Full access to every feature', 'fa-crown', '#6366f1', 1],
            ['manager', 'Manager', 'Runs the restaurant day to day', 'fa-user-tie', '#0ea5e9', 1],
            ['staff', 'Staff', 'Takes orders and serves tables', 'fa-user', '#22c55e', 1],
        ];
        $stmt = $conn->prepare(
            'INSERT INTO roles (slug, name, description, icon, color, is_system, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($roles as $role) {
            $stmt->execute([...$role, Clock::now(), Clock::now()]);
        }
    }

    private static function seedAdmin(Connection $conn, array $tenant, string $now): int
    {
        $existing = (int) $conn->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($existing > 0) {
            return (int) $conn->query("SELECT id FROM admins WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        }

        // Owner credentials: their platform e-mail + a generated password shown
        // once in the console after provisioning.
        $username = Str::slug((string) ($tenant['owner_name'] ?? 'owner'), 24) ?: 'owner';
        $password = $tenant['_owner_password'] ?? Str::token(6);
        $email    = (string) ($tenant['owner_email'] ?? '');

        $stmt = $conn->prepare(
            'INSERT INTO admins (username, password, email, full_name, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $email,
            $tenant['owner_name'] ?? 'Owner',
            'admin',
            $now,
            $now,
        ]);

        return (int) $conn->lastInsertId();
    }

    private static function seedTaxes(Connection $conn, string $now): void
    {
        if ((int) $conn->query('SELECT COUNT(*) FROM tax_groups')->fetchColumn() === 0) {
            $stmt = $conn->prepare('INSERT INTO tax_groups (name, description, rate, is_active, created_at) VALUES (?, ?, ?, 1, ?)');
            $stmt->execute(['Standard', 'Default VAT / sales tax', 0.075, $now]);
            $stmt->execute(['Zero rated', 'Exempt items', 0.0, $now]);
        }
        if ((int) $conn->query('SELECT COUNT(*) FROM tax_items')->fetchColumn() === 0) {
            $stmt = $conn->prepare('INSERT INTO tax_items (name, description, rate, is_active, created_at) VALUES (?, ?, ?, 1, ?)');
            $stmt->execute(['VAT', 'Value added tax', 0.075, $now]);
            $stmt->execute(['Service', 'Service levy', 0.01, $now]);
        }
    }

    private static function seedCatalogue(Connection $conn, string $now, bool $full): void
    {
        if ((int) $conn->query('SELECT COUNT(*) FROM main_categories')->fetchColumn() > 0) {
            return;
        }

        $menu = [
            'Starters' => [
                ['Spring Rolls', 'Crispy vegetable rolls with sweet chilli dip', 6.50, 2.10],
                ['Soup of the Day', 'Chef\'s daily soup with warm bread', 5.00, 1.40],
                ['Garlic Prawns', 'Pan-seared prawns, garlic butter, parsley', 9.80, 4.20],
            ],
            'Mains' => [
                ['Grilled Chicken', 'Half chicken, jollof rice, garden salad', 14.50, 5.60],
                ['Beef Burger', 'Smashed beef patty, cheddar, brioche bun, fries', 12.90, 4.80],
                ['Pan-Fried Salmon', 'Salmon fillet, seasonal vegetables, lemon butter', 17.50, 7.90],
                ['Vegetable Curry', 'Coconut curry, chickpeas, basmati rice', 11.00, 3.10],
            ],
            'Desserts' => [
                ['Chocolate Fondant', 'Warm chocolate cake, vanilla ice cream', 7.20, 2.30],
                ['Cheesecake', 'Baked vanilla cheesecake, berry compote', 6.80, 2.10],
            ],
            'Drinks' => [
                ['Fresh Lemonade', 'House-made, mint & lime', 3.50, 0.80],
                ['Espresso', 'Double shot, single origin', 2.80, 0.60],
                ['Bottled Water', 'Still, 500ml', 1.80, 0.40],
            ],
        ];

        $categoryStmt = $conn->prepare('INSERT INTO main_categories (name, description, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, ?)');
        $subStmt      = $conn->prepare('INSERT INTO sub_categories (main_category_id, name, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, ?)');
        $itemStmt     = $conn->prepare(
            'INSERT INTO food_items (item_name, description, price, promo_price, cost, sub_category_id, tax_group, inventory_count, is_vegetarian, is_spicy, cooking_time, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        // Without the demo dataset a new tenant still gets one starter category.
        $menu = $full ? $menu : ['Mains' => $menu['Mains'], 'Drinks' => $menu['Drinks']];

        $sort = 1;
        foreach ($menu as $category => $items) {
            $categoryStmt->execute([$category, null, $sort++, $now]);
            $categoryId = (int) $conn->lastInsertId();
            $subStmt->execute([$categoryId, $category, 1, $now]);
            $subId = (int) $conn->lastInsertId();

            foreach ($items as $index => [$name, $description, $price, $cost]) {
                $itemStmt->execute([
                    $name,
                    $description,
                    $price,
                    null,
                    $cost,
                    $subId,
                    'Standard',
                    $full ? 25 + ($index * 3) : 0,
                    str_contains(strtolower($name), 'vegetable') || str_contains(strtolower($name), 'soup') ? 1 : 0,
                    str_contains(strtolower($name), 'curry') ? 1 : 0,
                    15 + $index,
                    $now,
                    $now,
                ]);
            }
        }
    }

    private static function seedFloor(Connection $conn, string $now, bool $full): void
    {
        if ((int) $conn->query('SELECT COUNT(*) FROM restaurant_tables')->fetchColumn() > 0) {
            return;
        }
        $tables = $full
            ? [['T1', 2], ['T2', 2], ['T3', 4], ['T4', 4], ['T5', 6], ['T6', 8], ['Bar 1', 2], ['Terrace 1', 4]]
            : [['T1', 2], ['T2', 4], ['T3', 6]];

        $tableStmt = $conn->prepare('INSERT INTO restaurant_tables (table_name, seat_count, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $seatStmt  = $conn->prepare('INSERT INTO restaurant_seats (table_id, seat_name, seat_number, status, created_at) VALUES (?, ?, ?, ?, ?)');

        foreach ($tables as [$name, $seats]) {
            $tableStmt->execute([$name, $seats, 'Available', $now, $now]);
            $tableId = (int) $conn->lastInsertId();
            for ($i = 1; $i <= $seats; $i++) {
                $seatStmt->execute([$tableId, "Seat $i", $i, 'Available', $now]);
            }
        }
    }

    private static function seedDelivery(Connection $conn, string $now): void
    {
        if ((int) $conn->query('SELECT COUNT(*) FROM delivery_zones')->fetchColumn() > 0) {
            return;
        }
        $stmt = $conn->prepare('INSERT INTO delivery_zones (zone_name, delivery_fee, is_active, created_at) VALUES (?, ?, 1, ?)');
        foreach ([['In town', 2.50], ['Greater area', 5.00], ['Outskirts', 8.50]] as [$zone, $fee]) {
            $stmt->execute([$zone, $fee, $now]);
        }
    }

    private static function seedPrinters(Connection $conn, string $now): void
    {
        if ((int) $conn->query('SELECT COUNT(*) FROM printers')->fetchColumn() > 0) {
            return;
        }
        $stmt = $conn->prepare(
            'INSERT INTO printers (name, type, connection_type, ip_address, port, paper_width, is_default, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Receipt printer', 'receipt', 'network', '192.168.1.50', 9100, 80, 1, 'active', $now]);
        $stmt->execute(['Kitchen printer', 'kot', 'network', '192.168.1.51', 9100, 80, 0, 'active', $now]);
    }

    private static function seedCustomersAndOrders(Connection $conn, string $now): void
    {
        if ((int) $conn->query('SELECT COUNT(*) FROM customers')->fetchColumn() > 0) {
            return;
        }

        $people = [
            ['ama', 'Ama Boateng', 'ama.boateng@example.com', '+233 24 000 0001'],
            ['kwame', 'Kwame Mensah', 'kwame.mensah@example.com', '+233 24 000 0002'],
            ['nadia', 'Nadia Rahman', 'nadia.rahman@example.com', '+233 24 000 0003'],
            ['chen', 'Chen Wei', 'chen.wei@example.com', '+233 24 000 0004'],
            ['lena', 'Lena Fischer', 'lena.fischer@example.com', '+233 24 000 0005'],
        ];
        $customerStmt = $conn->prepare(
            'INSERT INTO customers (username, email, password, full_name, phone, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ids = [];
        foreach ($people as [$username, $fullName, $email, $phone]) {
            $customerStmt->execute([$username, $email, password_hash('demo1234', PASSWORD_DEFAULT), $fullName, $phone, $now]);
            $ids[] = (int) $conn->lastInsertId();
        }

        $items = $conn->query('SELECT id, item_name, price FROM food_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            return;
        }

        $orderStmt = $conn->prepare(
            'INSERT INTO orders (user_id, order_reference, status, order_type, total, payment_status, payment_method, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $lineStmt = $conn->prepare(
            'INSERT INTO order_items (order_id, food_item_id, item_name, quantity, price, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );

        $statuses = ['Completed', 'Completed', 'Completed', 'Preparing', 'Placed', 'Cancelled'];
        $types    = ['dine_in', 'delivery', 'takeaway'];

        // 90 days of trading history so the tenant dashboards have real data.
        for ($day = 90; $day >= 0; $day--) {
            $ordersToday = max(1, (int) round(2 + (7 - ($day / 14)) + random_int(0, 3)));
            for ($n = 0; $n < $ordersToday; $n++) {
                $placedAt = gmdate('Y-m-d H:i:s', strtotime("-{$day} days") - random_int(0, 40000));
                $status   = $day < 2 ? $statuses[array_rand($statuses)] : 'Completed';
                $userId   = $ids[array_rand($ids)];
                $type     = $types[array_rand($types)];

                $lineCount = random_int(1, 3);
                $total     = 0.0;
                $lines     = [];
                for ($l = 0; $l < $lineCount; $l++) {
                    $item     = $items[array_rand($items)];
                    $quantity = random_int(1, 3);
                    $total   += (float) $item['price'] * $quantity;
                    $lines[]  = [$item['id'], $item['item_name'], $quantity, (float) $item['price']];
                }

                $orderStmt->execute([
                    $userId,
                    'ORD-' . strtoupper(bin2hex(random_bytes(3))),
                    $status,
                    $type,
                    round($total, 2),
                    $status === 'Completed' ? 'paid' : 'unpaid',
                    $status === 'Completed' ? (random_int(0, 1) ? 'card' : 'cash') : null,
                    $placedAt,
                    $placedAt,
                ]);
                $orderId = (int) $conn->lastInsertId();

                foreach ($lines as [$itemId, $itemName, $quantity, $price]) {
                    $lineStmt->execute([$orderId, $itemId, $itemName, $quantity, $price, $placedAt]);
                }
            }
        }
    }

    private static function seedEvents(Connection $conn, string $now): void
    {
        if ((int) $conn->query('SELECT COUNT(*) FROM event_packages')->fetchColumn() > 0) {
            return;
        }
        $packages = [
            ['Birthday Party', 'Private room, cake, decorations and a set menu for up to 30 guests', 28.00],
            ['Corporate Lunch', 'Three-course working lunch with coffee and AV support', 22.50],
            ['Wedding Reception', 'Full venue hire, canapés, seated dinner and service staff', 65.00],
        ];
        $stmt = $conn->prepare('INSERT INTO event_packages (name, description, base_price_per_head, is_active, created_at) VALUES (?, ?, ?, 1, ?)');
        foreach ($packages as [$name, $description, $price]) {
            $stmt->execute([$name, $description, $price, $now]);
        }

        $eventStmt = $conn->prepare('INSERT INTO events (title, description, event_date, start_time, location, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)');
        $eventStmt->execute(['Wine Tasting Evening', 'Six wines, six small plates, hosted by our sommelier', gmdate('Y-m-d', strtotime('+12 days')), '19:00', 'Terrace', $now]);
        $eventStmt->execute(['Sunday Jazz Brunch', 'Live trio and a bottomless brunch menu', gmdate('Y-m-d', strtotime('+5 days')), '11:30', 'Main hall', $now]);

        $bookingStmt = $conn->prepare(
            'INSERT INTO event_bookings (user_id, package_id, name, type, event_date, guest_count, total_amount, payment_status, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $bookingStmt->execute([null, 1, 'Nadia Rahman', 'birthday', gmdate('Y-m-d', strtotime('+18 days')), 24, 672.00, 'deposit_paid', 'confirmed', $now]);
        $bookingStmt->execute([null, 2, 'Zenith Corp', 'corporate', gmdate('Y-m-d', strtotime('+30 days')), 40, 900.00, 'unpaid', 'pending', $now]);
    }
}

/* -------------------------------------------------------------------------
 * Provisioner — create a tenant end to end
 * ---------------------------------------------------------------------- */

final class Provisioner
{
    /**
     * Provision (or re-provision) a tenant:
     *   1. create the private database
     *   2. install the restaurant schema
     *   3. seed starter content
     *   4. start a trial subscription
     *   5. announce it in the audit log + notifications
     *
     * @param array $options fresh(bool), demo(bool), plan_id(int|null), trial_days(int|null)
     */
    public static function provision(Tenant|array $tenant, array $options = []): array
    {
        $isModel = $tenant instanceof Tenant;
        $record  = $isModel ? $tenant->toArray() : $tenant;

        $demo       = (bool) ($options['demo'] ?? true);
        $planId     = $options['plan_id'] ?? ($record['plan_id'] ?? null);
        $trialDays  = (int) ($options['trial_days'] ?? Config::get('tenancy.trial_days', 14));
        $ownerPassword = (string) ($options['owner_password'] ?? Str::token(6));

        // The owner's login is needed whether or not demo content is seeded.
        $record['_owner_password'] = $ownerPassword;

        // 1. database
        Manager::createTenantDatabase($record);

        // 2. schema + 3. content
        $conn = Manager::tenant($record);
        TenantSchema::migrate($conn);
        StarterData::seed($conn, $record, $demo);

        // 4. subscription & trial
        $repo     = new TenantRepository();
        $planId   = $planId ?: self::defaultPlanId();
        $plan     = $planId ? (new \Resto\Billing\PlanRepository())->find((int) $planId) : null;
        $cycle    = (string) ($record['billing_cycle'] ?? 'monthly');

        $updates = [
            'plan_id'      => $planId,
            'db_name'      => Manager::tenantDatabaseName((string) $record['slug']),
            'db_driver'    => Manager::driver(),
            'trial_ends_at' => $record['trial_ends_at'] ?? Clock::addDays(Clock::now(), $trialDays),
            'status'       => $record['status'] ?? 'trial',
        ];

        // A tenant always needs its sign-in code: accounts created before codes
        // existed (or through a path that bypassed the repository) get one here.
        if ((string) ($record['access_code'] ?? '') === '') {
            $updates['access_code'] = $repo->uniqueAccessCode();
        }

        if ($isModel) {
            $repo->update($tenant->id(), $updates);
        }

        $tenantModel = $isModel ? $repo->find($tenant->id(), true) : Tenant::fromRow($record);

        if ($plan !== null && $planId) {
            (new \Resto\Billing\SubscriptionService())->startTrial($tenantModel, $plan, $trialDays);
        }

        // Meter whatever content was seeded/imported so usage is truthful.
        \Resto\Billing\UsageService::backfill($tenantModel, $demo);

        // 5. audit trail
        \Resto\Platform\Audit::record([
            'action'      => 'tenant.provisioned',
            'tenant_id'   => $tenantModel->id(),
            'target_type' => 'tenant',
            'target_id'   => (string) $tenantModel->id(),
            'description' => sprintf('Provisioned %s (%s)', $tenantModel->name(), $tenantModel->dbName()),
            'severity'    => 'notice',
            'meta'        => ['demo_data' => $demo, 'tables' => count(TenantSchema::definitions())],
        ]);

        (new \Resto\Platform\Notifications())->push([
            'tenant_id' => $tenantModel->id(),
            'type'      => 'tenant.provisioned',
            'level'     => 'success',
            'title'     => $tenantModel->name() . ' is live',
            'body'      => 'Restaurant database created and starter content installed.',
        ]);

        return [
            'tenant'         => $tenantModel,
            'tables'         => array_keys(TenantSchema::definitions()),
            'owner_password' => $ownerPassword,
            'admin_username' => Str::slug((string) ($record['owner_name'] ?? 'owner'), 24) ?: 'owner',
        ];
    }

    /** Remove every trace of a tenant (danger zone). */
    public static function destroy(Tenant $tenant, bool $dropDatabase = true): void
    {
        $platform = Manager::platform();
        if ($dropDatabase) {
            Manager::dropTenantDatabase($tenant->toArray());
        }

        foreach (['usage_events', 'usage_counters', 'invoices', 'subscriptions', 'tenant_features', 'tenant_domains', 'support_notes', 'impersonation_tokens', 'tenant_activity'] as $table) {
            try {
                $platform->prepare("DELETE FROM {$table} WHERE tenant_id = ?")->execute([$tenant->id()]);
            } catch (\Throwable) {
                // Table may not exist yet in older installs.
            }
        }

        (new TenantRepository())->delete($tenant->id());

        \Resto\Platform\Audit::record([
            'action'      => 'tenant.deleted',
            'target_type' => 'tenant',
            'target_id'   => (string) $tenant->id(),
            'description' => 'Deleted tenant ' . $tenant->name(),
            'severity'    => 'critical',
            'meta'        => ['slug' => $tenant->slug(), 'database_dropped' => $dropDatabase],
        ]);
    }

    private static function defaultPlanId(): ?int
    {
        $stmt = Manager::platform()->query('SELECT id FROM plans WHERE is_archived = 0 ORDER BY sort_order ASC, price_monthly ASC LIMIT 1');
        $id   = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
