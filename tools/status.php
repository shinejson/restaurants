<?php
/**
 * Control-plane status: migrations, tenants, plans and the demo tenant's data.
 *
 *   node tools/php-cli.mjs tools/status.php
 */

require_once dirname(__DIR__) . '/platform/bootstrap.php';

use Resto\Database\Manager;
use Resto\Platform\Metrics;
use Resto\Tenancy\TenantRepository;

echo "driver: ", Manager::driver(), " | php ", PHP_VERSION, "\n";

$applied = Resto\Database\Migrator::applied(Manager::platform());
echo "migrations applied: ", count($applied), "\n";
foreach ($applied as $m) {
    echo "  - {$m['migration']}\n";
}

printf(
    "plans: %d | platform users: %d | tenants: %d\n",
    Manager::platform()->query('SELECT COUNT(*) FROM plans')->fetchColumn(),
    Manager::platform()->query('SELECT COUNT(*) FROM platform_users')->fetchColumn(),
    Manager::platform()->query('SELECT COUNT(*) FROM tenants')->fetchColumn()
);

echo "\ntenants\n";
foreach (Manager::platform()->query('SELECT id, slug, name, status, mrr, db_name, access_code FROM tenants ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $t) {
    printf("  #%-3d %-18s %-22s %-10s mrr=%-8s code=%-6s %s\n", $t['id'], $t['slug'], $t['name'], $t['status'], $t['mrr'], $t['access_code'], $t['db_name']);
}

$overview = Metrics::overview();
echo "\noverview: ", json_encode($overview['tenants']), "\n";
echo "revenue:  ", json_encode($overview['revenue']), "\n";

$demo = (new TenantRepository())->findBySlug((string) Resto\Support\Config::get('tenancy.demo_slug', 'demo'));
if ($demo) {
    echo "\ndemo tenant rows\n";
    foreach (['food_items', 'orders', 'order_items', 'customers', 'restaurant_tables', 'events', 'settings'] as $table) {
        printf("  %-20s %s\n", $table, $demo->connection()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
    }
}
