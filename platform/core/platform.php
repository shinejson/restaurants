<?php
/**
 * RestaurantOS — platform services.
 *
 * Cross-cutting control-plane concerns: the audit trail, platform settings,
 * the notification feed, and the analytics that drive the superadmin
 * dashboard.
 *
 * @package Resto\Platform
 */

namespace Resto\Platform;

use Resto\Database\Manager;
use Resto\Support\Clock;
use Resto\Support\Config;
use Resto\Support\Money;

/* -------------------------------------------------------------------------
 * Audit — every meaningful action, forever
 * ---------------------------------------------------------------------- */

final class Audit
{
    private static array $queue = [];

    /**
     * @param array{action:string,actor_type?:string,actor_id?:int,actor_name?:string,tenant_id?:int,
     *              target_type?:string,target_id?:string,description?:string,severity?:string,
     *              ip?:string,user_agent?:string,meta?:array} $entry
     */
    public static function record(array $entry): void
    {
        $user = Auth::user();

        $row = [
            'actor_type'  => $entry['actor_type'] ?? ($user ? 'platform' : 'system'),
            'actor_id'    => $entry['actor_id'] ?? ($user['id'] ?? null),
            'actor_name'  => $entry['actor_name'] ?? ($user['name'] ?? null),
            'tenant_id'   => $entry['tenant_id'] ?? null,
            'action'      => $entry['action'],
            'target_type' => $entry['target_type'] ?? null,
            'target_id'   => $entry['target_id'] ?? null,
            'description' => isset($entry['description']) ? substr((string) $entry['description'], 0, 255) : null,
            'severity'    => $entry['severity'] ?? 'info',
            'ip'          => $entry['ip'] ?? self::requestIp(),
            'user_agent'  => $entry['user_agent'] ?? substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'meta'        => isset($entry['meta']) ? json_encode($entry['meta']) : null,
            'created_at'  => Clock::now(),
        ];

        try {
            $columns = array_keys($row);
            Manager::platform()->prepare(
                'INSERT INTO platform_audit_logs (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')'
            )->execute(array_values($row));
        } catch (\Throwable $e) {
            // Never let auditing break a request.
            error_log('[audit] ' . $e->getMessage());
            self::$queue[] = $row;
        }
    }

    /**
     * @param array{tenant_id?:int,action?:string,actor_type?:string,severity?:string,search?:string,
     *              from?:string,to?:string} $filters
     */
    public static function paginate(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        [$where, $params] = self::buildFilters($filters);

        $countStmt = Manager::platform()->prepare('SELECT COUNT(*) FROM platform_audit_logs a' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT a.*, t.name AS tenant_name, t.slug AS tenant_slug
                FROM platform_audit_logs a
                LEFT JOIN tenants t ON t.id = a.tenant_id' . $where
            . ' ORDER BY a.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage);

        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);

        return [
            'data' => array_map([self::class, 'present'], $stmt->fetchAll(\PDO::FETCH_ASSOC)),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    public static function recent(int $limit = 12, ?int $tenantId = null): array
    {
        $sql    = 'SELECT a.*, t.name AS tenant_name FROM platform_audit_logs a LEFT JOIN tenants t ON t.id = a.tenant_id';
        $params = [];
        if ($tenantId !== null) {
            $sql     .= ' WHERE a.tenant_id = ?';
            $params[] = $tenantId;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . max(1, $limit);

        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'present'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Distinct action names, for the filter dropdown. */
    public static function actions(): array
    {
        $rows = Manager::platform()->query('SELECT DISTINCT action FROM platform_audit_logs ORDER BY action ASC')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter($rows));
    }

    private static function buildFilters(array $filters): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['tenant_id'])) {
            $where[]  = 'a.tenant_id = ?';
            $params[] = (int) $filters['tenant_id'];
        }
        if (!empty($filters['action'])) {
            $where[]  = 'a.action LIKE ?';
            $params[] = $filters['action'] . '%';
        }
        if (!empty($filters['actor_type'])) {
            $where[]  = 'a.actor_type = ?';
            $params[] = $filters['actor_type'];
        }
        if (!empty($filters['severity'])) {
            $where[]  = 'a.severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(a.description LIKE ? OR a.actor_name LIKE ? OR a.action LIKE ? OR t.name LIKE ?)';
            $term    = '%' . $filters['search'] . '%';
            $params  = array_merge($params, [$term, $term, $term, $term]);
        }
        if (!empty($filters['from'])) {
            $where[]  = 'a.created_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'a.created_at <= ?';
            $params[] = $filters['to'];
        }

        if ($where !== [] && !empty($filters['search'])) {
            // The join is only needed when the search touches the tenant name.
            return [' WHERE ' . implode(' AND ', $where), $params];
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    private static function present(array $row): array
    {
        $row['meta'] = $row['meta'] ? json_decode((string) $row['meta'], true) : null;
        $row['ago']  = Clock::human($row['created_at'] ?? null);
        return $row;
    }

    private static function requestIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', (string) $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}

/* -------------------------------------------------------------------------
 * Settings — key/value platform configuration
 * ---------------------------------------------------------------------- */

final class Settings
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'platform_name'          => 'RestaurantOS',
            'support_email'          => 'support@restaurantos.test',
            'default_currency'       => 'USD',
            'default_trial_days'     => '14',
            'default_plan'           => 'growth',
            'tax_rate'               => '0',
            'invoice_prefix'         => 'INV',
            'invoice_due_days'       => '14',
            'signup_enabled'         => '1',
            'maintenance_mode'       => '0',
            'maintenance_message'    => 'We are performing scheduled maintenance and will be back shortly.',
            'announcement_banner'    => '',
            'new_tenant_notifications' => '1',
            'past_due_grace_days'    => '7',
            'auto_suspend'           => '1',
            'brand_accent'           => '#6366f1',
        ];
    }

    public static function all(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }
        $values = self::defaults();
        try {
            foreach (Manager::platform()->query('SELECT setting_key, setting_value FROM platform_settings')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $values[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Throwable) {
            // Fresh install before migrations ran.
        }
        return self::$cache = $values;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function set(string $key, string $value, string $group = 'general'): void
    {
        $stmt = Manager::platform()->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value, setting_group, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$key, $value, $group, Clock::now()]);
        self::$cache = null;
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            self::set((string) $key, is_scalar($value) ? (string) $value : json_encode($value), $group);
        }
    }

    public static function grouped(): array
    {
        $rows = Manager::platform()->query('SELECT setting_key, setting_value, setting_group FROM platform_settings ORDER BY setting_group, setting_key')->fetchAll(\PDO::FETCH_ASSOC);
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['setting_group']][] = $row;
        }
        return $out;
    }
}

/* -------------------------------------------------------------------------
 * Notifications — the console's activity bell
 * ---------------------------------------------------------------------- */

final class Notifications
{
    public static function push(array $data): void
    {
        try {
            Manager::platform()->prepare(
                'INSERT INTO platform_notifications (tenant_id, type, level, title, body, action_url, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $data['tenant_id'] ?? null,
                $data['type'] ?? 'system',
                $data['level'] ?? 'info',
                substr((string) $data['title'], 0, 191),
                $data['body'] ?? null,
                $data['action_url'] ?? null,
                Clock::now(),
            ]);
        } catch (\Throwable $e) {
            error_log('[notifications] ' . $e->getMessage());
        }
    }

    public static function recent(int $limit = 20): array
    {
        $stmt = Manager::platform()->prepare(
            'SELECT n.*, t.name AS tenant_name FROM platform_notifications n
             LEFT JOIN tenants t ON t.id = n.tenant_id
             ORDER BY n.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        return array_map(static function (array $row): array {
            $row['ago'] = Clock::human($row['created_at'] ?? null);
            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function unreadCount(): int
    {
        try {
            return (int) Manager::platform()->query('SELECT COUNT(*) FROM platform_notifications WHERE read_at IS NULL')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function markAllRead(): void
    {
        Manager::platform()->prepare('UPDATE platform_notifications SET read_at = ? WHERE read_at IS NULL')->execute([Clock::now()]);
    }
}

/* -------------------------------------------------------------------------
 * Metrics — control-plane analytics for the dashboard
 * ---------------------------------------------------------------------- */

final class Metrics
{
    /** Headline numbers: tenants, revenue, growth. */
    public static function overview(): array
    {
        $pdo = Manager::platform();

        $tenantsByStatus = [];
        foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM tenants GROUP BY status')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tenantsByStatus[$row['status']] = (int) $row['total'];
        }
        $totalTenants = array_sum($tenantsByStatus);

        $periodStart = Clock::periodStart();
        $prevStart   = Clock::periodStart(gmdate('Y-m-d', strtotime('-1 month')));
        $prevEnd     = gmdate('Y-m-d H:i:s', strtotime($periodStart . ' UTC') - 1);

        $newThisMonth = self::scalar('SELECT COUNT(*) FROM tenants WHERE created_at >= ?', [$periodStart]);
        $newLastMonth = self::scalar('SELECT COUNT(*) FROM tenants WHERE created_at >= ? AND created_at <= ?', [$prevStart, $prevEnd]);

        $churnedThisMonth = self::scalar("SELECT COUNT(*) FROM tenants WHERE status = 'cancelled' AND cancelled_at >= ?", [$periodStart]);
        $churnedLastMonth = self::scalar("SELECT COUNT(*) FROM tenants WHERE status = 'cancelled' AND cancelled_at >= ? AND cancelled_at <= ?", [$prevStart, $prevEnd]);

        /* -------- revenue -------- */
        $collectedThisMonth = (float) self::scalar("SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE status = 'paid' AND paid_at >= ?", [$periodStart]);
        $collectedLastMonth = (float) self::scalar("SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE status = 'paid' AND paid_at >= ? AND paid_at <= ?", [$prevStart, $prevEnd]);
        $outstanding        = (float) self::scalar("SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices WHERE status IN ('open', 'past_due')");
        $overdue            = (float) self::scalar("SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices WHERE status IN ('open', 'past_due') AND due_at < ?", [Clock::now()]);

        $mrr = self::mrr();
        $activePaying = max(1, self::scalar("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','past_due')"));
        $arpa         = $mrr / $activePaying;

        /* -------- trials & risk -------- */
        $trialsEndingSoon = self::scalar(
            "SELECT COUNT(*) FROM tenants WHERE status = 'trial' AND trial_ends_at BETWEEN ? AND ?",
            [Clock::now(), Clock::addDays(Clock::now(), 7)]
        );
        $trialsExpired = self::scalar("SELECT COUNT(*) FROM tenants WHERE status = 'trial' AND trial_ends_at < ?", [Clock::now()]);
        $pastDue       = (int) ($tenantsByStatus['past_due'] ?? 0);
        $dormant       = self::scalar('SELECT COUNT(*) FROM tenants WHERE last_activity_at < ? OR last_activity_at IS NULL', [Clock::addDays(Clock::now(), -14)]);

        /* -------- usage -------- */
        $ordersThisMonth = self::scalar("SELECT COALESCE(SUM(value), 0) FROM usage_counters WHERE metric = 'orders' AND period = ?", [gmdate('Y-m')]);
        $ordersLastMonth = self::scalar("SELECT COALESCE(SUM(value), 0) FROM usage_counters WHERE metric = 'orders' AND period = ?", [gmdate('Y-m', strtotime('-1 month'))]);

        return [
            'tenants' => [
                'total'      => $totalTenants,
                'by_status'  => array_merge(['trial' => 0, 'active' => 0, 'past_due' => 0, 'suspended' => 0, 'cancelled' => 0], $tenantsByStatus),
                'new_this_month' => $newThisMonth,
                'new_last_month' => $newLastMonth,
                'new_change'     => Money::percentChange((float) $newThisMonth, (float) $newLastMonth),
                'churned_this_month' => $churnedThisMonth,
                'churned_last_month' => $churnedLastMonth,
                'growth_rate'  => $totalTenants > 0 ? round((($newThisMonth - $churnedThisMonth) / $totalTenants) * 100, 1) : 0.0,
                'dormant'      => $dormant,
            ],
            'revenue' => [
                'currency'          => (string) Settings::get('default_currency', 'USD'),
                'mrr'               => round($mrr, 2),
                'arr'               => round($mrr * 12, 2),
                'arpa'              => round($arpa, 2),
                'collected_this_month' => round($collectedThisMonth, 2),
                'collected_last_month' => round($collectedLastMonth, 2),
                'collected_change'  => Money::percentChange($collectedThisMonth, $collectedLastMonth),
                'outstanding'       => round($outstanding, 2),
                'overdue'           => round($overdue, 2),
            ],
            'risk' => [
                'trials_ending_soon' => (int) $trialsEndingSoon,
                'trials_expired'     => (int) $trialsExpired,
                'past_due'           => $pastDue,
                'dormant'            => (int) $dormant,
                'suspended'          => (int) ($tenantsByStatus['suspended'] ?? 0),
            ],
            'usage' => [
                'orders_this_month' => (int) $ordersThisMonth,
                'orders_last_month' => (int) $ordersLastMonth,
                'orders_change'     => Money::percentChange((float) $ordersThisMonth, (float) $ordersLastMonth),
            ],
        ];
    }

    /** Monthly recurring revenue from live subscriptions. */
    public static function mrr(): float
    {
        $rows = Manager::platform()->query(
            "SELECT s.amount, s.billing_cycle, s.quantity, s.discount_percent
             FROM subscriptions s
             WHERE s.status IN ('active', 'past_due')"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $mrr = 0.0;
        foreach ($rows as $row) {
            $amount = (float) $row['amount'] * max(1, (int) $row['quantity']);
            $amount -= $amount * ((float) $row['discount_percent'] / 100);
            $mrr += $row['billing_cycle'] === 'yearly' ? $amount / 12 : $amount;
        }
        return round($mrr, 2);
    }

    /** Monthly series for the dashboard charts. */
    public static function series(int $months = 12): array
    {
        $buckets = Clock::monthBuckets($months);
        $from    = array_key_first($buckets) . '-01 00:00:00';

        $signups = array_fill_keys(array_keys($buckets), 0);
        $stmt = Manager::platform()->prepare('SELECT SUBSTR(created_at, 1, 7) AS bucket, COUNT(*) AS total FROM tenants WHERE created_at >= ? GROUP BY SUBSTR(created_at, 1, 7)');
        $stmt->execute([$from]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($signups[$row['bucket']])) {
                $signups[$row['bucket']] = (int) $row['total'];
            }
        }

        $churn = array_fill_keys(array_keys($buckets), 0);
        $stmt = Manager::platform()->prepare("SELECT SUBSTR(cancelled_at, 1, 7) AS bucket, COUNT(*) AS total FROM tenants WHERE cancelled_at IS NOT NULL AND cancelled_at >= ? GROUP BY SUBSTR(cancelled_at, 1, 7)");
        $stmt->execute([$from]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($churn[$row['bucket']])) {
                $churn[$row['bucket']] = (int) $row['total'];
            }
        }

        $revenue = array_fill_keys(array_keys($buckets), 0.0);
        $stmt = Manager::platform()->prepare("SELECT SUBSTR(paid_at, 1, 7) AS bucket, COALESCE(SUM(amount_paid), 0) AS total FROM invoices WHERE status = 'paid' AND paid_at >= ? GROUP BY SUBSTR(paid_at, 1, 7)");
        $stmt->execute([$from]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($revenue[$row['bucket']])) {
                $revenue[$row['bucket']] = (float) $row['total'];
            }
        }

        $orders = array_fill_keys(array_keys($buckets), 0);
        $stmt = Manager::platform()->prepare('SELECT period, COALESCE(SUM(value), 0) AS total FROM usage_counters WHERE metric = ? AND period >= ? GROUP BY period');
        $stmt->execute(['orders', array_key_first($buckets)]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($orders[$row['period']])) {
                $orders[$row['period']] = (int) $row['total'];
            }
        }

        $out = [];
        foreach ($buckets as $key => $label) {
            $out[] = [
                'month'    => $key,
                'label'    => $label,
                'signups'  => $signups[$key],
                'churn'    => $churn[$key],
                'net'      => $signups[$key] - $churn[$key],
                'revenue'  => round($revenue[$key], 2),
                'orders'   => $orders[$key],
            ];
        }
        return $out;
    }

    /** Tenants needing attention, with the reason. */
    public static function needsAttention(int $limit = 8): array
    {
        $sql = "SELECT t.id, t.name, t.slug, t.status, t.trial_ends_at, t.mrr,
                       (SELECT COALESCE(SUM(i.total - i.amount_paid), 0) FROM invoices i WHERE i.tenant_id = t.id AND i.status IN ('open','past_due')) AS outstanding,
                       (SELECT MIN(i.due_at) FROM invoices i WHERE i.tenant_id = t.id AND i.status IN ('open','past_due')) AS oldest_due
                FROM tenants t
                WHERE t.status = 'past_due'
                   OR (t.status = 'trial' AND t.trial_ends_at IS NOT NULL AND t.trial_ends_at <= ?)
                   OR (t.last_activity_at IS NOT NULL AND t.last_activity_at < ?)
                ORDER BY CASE t.status WHEN 'past_due' THEN 0 WHEN 'trial' THEN 1 ELSE 2 END, t.id DESC
                LIMIT " . max(1, $limit);
        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute([Clock::addDays(Clock::now(), 7), Clock::addDays(Clock::now(), -14)]);

        return array_map(static function (array $row): array {
            $reason = match (true) {
                $row['status'] === 'past_due' => 'Payment overdue',
                $row['status'] === 'trial'    => 'Trial ending',
                default                        => 'No recent activity',
            };
            $row['reason'] = $reason;
            $row['outstanding'] = (float) $row['outstanding'];
            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Orders/usage leaderboard. */
    public static function leaderboard(int $limit = 6): array
    {
        $period = gmdate('Y-m');
        $stmt = Manager::platform()->prepare(
            "SELECT t.id, t.name, t.slug, t.status, t.currency,
                    COALESCE(u.value, 0) AS orders,
                    COALESCE(t.mrr, 0) AS mrr
             FROM tenants t
             LEFT JOIN usage_counters u ON u.tenant_id = t.id AND u.metric = 'orders' AND u.period = ?
             WHERE t.status IN ('active','trial','past_due')
             ORDER BY orders DESC, t.mrr DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$period]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Tenant health/usage snapshot used on the tenant detail page. */
    public static function tenantSnapshot(int $tenantId): array
    {
        $period = gmdate('Y-m');

        $usage = [];
        $stmt  = Manager::platform()->prepare('SELECT metric, value FROM usage_counters WHERE tenant_id = ? AND period = ?');
        $stmt->execute([$tenantId, $period]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $usage[$row['metric']] = (int) $row['value'];
        }

        $outstanding = (float) self::scalar("SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices WHERE tenant_id = ? AND status IN ('open','past_due')", [$tenantId]);
        $lifetime    = (float) self::scalar("SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE tenant_id = ? AND status = 'paid'", [$tenantId]);

        return [
            'period'      => $period,
            'usage'       => $usage,
            'outstanding' => round($outstanding, 2),
            'lifetime_revenue' => round($lifetime, 2),
            'open_invoices'    => self::scalar("SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND status IN ('open','past_due')", [$tenantId]),
        ];
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        try {
            $stmt = Manager::platform()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[metrics] ' . $e->getMessage());
            return 0;
        }
    }

    /** Platform health for the system page. */
    public static function system(): array
    {
        $pdo = Manager::platform();

        return [
            'php_version'      => PHP_VERSION,
            'driver'           => \Resto\Database\Manager::driver(),
            'app_env'          => (string) Config::get('app.env'),
            'debug'            => (bool) Config::get('app.debug'),
            'timezone'         => date_default_timezone_get(),
            'server_time'      => Clock::now(),
            'migrations'       => \Resto\Database\Migrator::applied($pdo),
            'tables'           => Manager::driver() === 'sqlite'
                ? self::sqliteTableCount()
                : (int) self::scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'),
            'storage'          => [
                'driver'   => Manager::driver(),
                'path'     => Manager::driver() === 'sqlite' ? Manager::sqliteDir() : '(mysql)',
                'writable' => is_writable(Config::path((string) Config::get('storage.path', 'storage'))),
                'size'     => self::storageSize(),
            ],
            'counts' => [
                'tenants'    => self::scalar('SELECT COUNT(*) FROM tenants'),
                'users'      => self::scalar('SELECT COUNT(*) FROM platform_users'),
                'plans'      => self::scalar('SELECT COUNT(*) FROM plans'),
                'invoices'   => self::scalar('SELECT COUNT(*) FROM invoices'),
                'audit_logs' => self::scalar('SELECT COUNT(*) FROM platform_audit_logs'),
            ],
        ];
    }

    private static function sqliteTableCount(): int
    {
        try {
            return (int) Manager::platform()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function storageSize(): string
    {
        $path = Config::path((string) Config::get('storage.path', 'storage'));
        if (!is_dir($path)) {
            return '0 B';
        }
        $bytes = 0;
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
