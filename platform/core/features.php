<?php
/**
 * RestaurantOS — plan features, quotas and per-tenant overrides.
 *
 * FeatureGate answers two questions the legacy application could not:
 *   1. "Is this restaurant allowed to use this module?"  (features)
 *   2. "Have they hit the ceiling of their plan?"        (quotas)
 *
 * @package Resto\Tenancy
 */

namespace Resto\Tenancy;

use Resto\Billing\Features;
use Resto\Billing\Plan;
use Resto\Billing\PlanRepository;
use Resto\Billing\UsageService;
use Resto\Database\Connection;
use Resto\Database\Manager;
use Resto\Http\ApiException;
use Resto\Support\Clock;

final class FeatureGate
{
    private ?Plan $plan = null;
    private ?array $overrides = null;
    private array $usage = [];

    public function __construct(private Tenant $tenant)
    {
    }

    public function plan(): ?Plan
    {
        if ($this->plan === null) {
            $planId = (int) ($this->tenant->get('plan_id') ?? 0);
            $this->plan = $planId > 0 ? (new PlanRepository())->find($planId) : null;
        }
        return $this->plan;
    }

    /** Per-tenant toggles that override the plan (support comps, add-ons). */
    public function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }
        $out = [];
        try {
            $stmt = Manager::platform()->prepare(
                'SELECT feature_key, enabled, limit_value, note, expires_at FROM tenant_features WHERE tenant_id = ?'
            );
            $stmt->execute([$this->tenant->id()]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!empty($row['expires_at']) && $row['expires_at'] < Clock::now()) {
                    continue;
                }
                $out[$row['feature_key']] = $row;
            }
        } catch (\Throwable) {
            // Table missing on very old installs.
        }
        return $this->overrides = $out;
    }

    public function allows(string $feature): bool
    {
        $override = $this->overrides()[$feature] ?? null;
        if ($override !== null) {
            return (bool) $override['enabled'];
        }
        return $this->plan()?->hasFeature($feature) ?? false;
    }

    public function denies(string $feature): bool
    {
        return !$this->allows($feature);
    }

    /** Effective limit for a metric: null = unlimited. */
    public function limit(string $metric): ?int
    {
        $override = $this->overrides()[$metric] ?? null;
        if ($override !== null && $override['limit_value'] !== null && (int) $override['limit_value'] >= 0) {
            return (int) $override['limit_value'];
        }

        $limit = $this->plan()?->limit($metric);
        if ($limit === null || $limit === -1 || $limit === '-1' || $limit === 'unlimited') {
            return null;
        }
        if (is_numeric($limit) && (int) $limit === 0) {
            return null; // 0 means "not metered"
        }
        return (int) $limit;
    }

    /** What has been consumed this period. */
    public function used(string $metric): int
    {
        if (isset($this->usage[$metric])) {
            return $this->usage[$metric];
        }

        $value = match ($metric) {
            'menu_items', 'staff_users', 'tables', 'customers' => UsageService::actual($this->tenant, $metric),
            'storage_mb' => $this->storageUsedMb(),
            default => UsageService::current($this->tenant->id(), $metric),
        };

        return $this->usage[$metric] = $value;
    }

    /** Every feature => allowed, for the console's feature panel. */
    public function allowsAll(): array
    {
        $out = [];
        foreach (array_keys(Features::CATALOG) as $feature) {
            $out[$feature] = $this->allows($feature);
        }
        return $out;
    }

    /** Every metric => effective limit (null = unlimited). */
    public function limitMap(): array
    {
        $out = [];
        foreach (array_keys(Features::LIMITS) as $metric) {
            $out[$metric] = $this->limit($metric);
        }
        return $out;
    }

    /** Definitions used to render override controls. */
    public static function catalog(): array
    {
        return ['features' => Features::CATALOG, 'limits' => Features::LIMITS];
    }

    public function remaining(string $metric): ?int
    {
        $limit = $this->limit($metric);
        return $limit === null ? null : max(0, $limit - $this->used($metric));
    }

    public function exceeds(string $metric, int $adding = 1): bool
    {
        $limit = $this->limit($metric);
        if ($limit === null) {
            return false;
        }
        return ($this->used($metric) + $adding) > $limit;
    }

    /** @throws ApiException when the plan ceiling is reached */
    public function checkOrFail(string $metric, int $adding = 1): void
    {
        if (!$this->exceeds($metric, $adding)) {
            return;
        }
        $meta  = Features::LIMITS[$metric] ?? ['label' => $metric];
        $limit = $this->limit($metric);

        throw new ApiException(
            sprintf(
                'You have reached your plan limit of %d %s. Upgrade your plan to add more.',
                (int) $limit,
                strtolower((string) ($meta['label'] ?? $metric))
            ),
            402,
            [
                'metric' => $metric,
                'limit'  => $limit,
                'used'   => $this->used($metric),
                'upgrade_url' => '/admin/billing.php',
            ],
            'quota_exceeded'
        );
    }

    /** Feature + quota snapshot for the tenant billing screen. */
    public function usageReport(): array
    {
        $report = [];
        foreach (Features::LIMITS as $metric => $meta) {
            $limit = $this->limit($metric);
            $used  = $this->used($metric);
            $report[] = [
                'metric'      => $metric,
                'label'       => $meta['label'],
                'unit'        => $meta['unit'],
                'hard'        => $meta['hard'],
                'used'        => $used,
                'limit'       => $limit,
                'remaining'   => $limit === null ? null : max(0, $limit - $used),
                'percentage'  => $limit === null || $limit === 0 ? null : min(100, (int) round(($used / $limit) * 100)),
                'unlimited'   => $limit === null,
                'exceeded'    => $limit !== null && $used > $limit,
            ];
        }
        return $report;
    }

    public function featureList(): array
    {
        $list = [];
        foreach (Features::catalog() as $feature) {
            $list[] = $feature + [
                'enabled'  => $this->allows($feature['key']),
                'override' => isset($this->overrides()[$feature['key']]),
            ];
        }
        return $list;
    }

    /** Support/comp overrides — used by the superadmin console. */
    public function setOverride(string $featureKey, bool $enabled, ?int $limitValue = null, string $note = '', ?int $updatedBy = null): void
    {
        $existing = $this->overrides()[$featureKey] ?? null;

        if ($existing === null) {
            Manager::platform()->prepare(
                'INSERT INTO tenant_features (tenant_id, feature_key, enabled, limit_value, note, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$this->tenant->id(), $featureKey, $enabled ? 1 : 0, $limitValue, $note, $updatedBy, Clock::now()]);
        } else {
            Manager::platform()->prepare(
                'UPDATE tenant_features SET enabled = ?, limit_value = ?, note = ?, updated_by = ?, updated_at = ?
                 WHERE tenant_id = ? AND feature_key = ?'
            )->execute([$enabled ? 1 : 0, $limitValue, $note, $updatedBy, Clock::now(), $this->tenant->id(), $featureKey]);
        }

        $this->overrides = null;
    }

    public function clearOverride(string $featureKey): void
    {
        Manager::platform()->prepare('DELETE FROM tenant_features WHERE tenant_id = ? AND feature_key = ?')
            ->execute([$this->tenant->id(), $featureKey]);
        $this->overrides = null;
    }

    private function storageUsedMb(): int
    {
        if (Manager::driver() !== 'sqlite') {
            return 0;
        }
        $file = Manager::tenantSqlitePath($this->tenant->toArray());
        return file_exists($file) ? (int) round(filesize($file) / 1048576) : 0;
    }
}

/* -------------------------------------------------------------------------
 * Global helpers used by the (procedural) tenant application
 * ---------------------------------------------------------------------- */

/** The restaurant serving the current request. */
function tenant(): ?Tenant
{
    return Context::get();
}

function current_tenant(): ?Tenant
{
    return Context::get();
}

function tenant_id(): ?int
{
    return Context::id();
}

/** Look a restaurant up by the code a visitor typed at sign-in. */
function tenant_by_code(string $code): ?Tenant
{
    return (new TenantRepository())->findByCode($code);
}

/**
 * Switch this request — and the visitor's session — to another restaurant.
 *
 * The sign-in screens use it once a code has identified the tenant, so the
 * credentials are then checked against that restaurant's own database:
 *
 *     $conn = use_tenant($tenant);
 */
function use_tenant(Tenant $tenant): Connection
{
    Resolver::remember($tenant);
    Context::set($tenant);
    $GLOBALS['resto']['tenant'] = $tenant;

    return Manager::tenant($tenant->toArray());
}

/** Is a plan feature switched on for this restaurant? */
function feature_enabled(string $feature): bool
{
    $tenant = Context::get();
    if ($tenant === null) {
        return true; // legacy/CLI contexts keep working
    }
    static $gates = [];
    $gate = $gates[$tenant->id()] ??= new FeatureGate($tenant);
    return $gate->allows($feature);
}

/** Hard gate for tenant pages: renders an upgrade prompt when not entitled. */
function require_feature(string $feature): void
{
    if (feature_enabled($feature)) {
        return;
    }

    $tenant = Context::get();
    $name   = $tenant?->name() ?? 'This restaurant';
    $plan   = $tenant ? (new PlanRepository())->find((int) $tenant->get('plan_id')) : null;
    $label  = Features::CATALOG[$feature]['label'] ?? $feature;

    http_response_code(403);
    $billing = htmlspecialchars(tenant_url('admin/billing.php'), ENT_QUOTES);
    $title   = htmlspecialchars($name, ENT_QUOTES);
    $label   = htmlspecialchars($label, ENT_QUOTES);
    $planName = htmlspecialchars($plan?->name() ?? 'current', ENT_QUOTES);

    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>{$label} is not included in your plan</title>
<style>
 body { font: 16px/1.6 system-ui, -apple-system, Segoe UI, sans-serif; background:#0b1120; color:#e2e8f0;
        display:grid; place-items:center; min-height:100vh; margin:0 }
 .card { max-width:38rem; padding:3rem }
 .badge { font-size:.75rem; letter-spacing:.08em; text-transform:uppercase; background:#4c1d95; color:#ddd6fe;
          padding:.35rem .7rem; border-radius:999px }
 h1 { font-size:1.6rem; margin:1.2rem 0 .6rem } p { color:#94a3b8 } a { color:#a5b4fc }
</style></head><body><div class="card">
 <span class="badge">Plan limit</span>
 <h1>{$label} is not part of your {$planName} plan</h1>
 <p>{$title} is on a plan that does not include this module. Upgrade to unlock it — your data stays exactly where it is.</p>
 <p><a href="{$billing}">Compare plans and upgrade →</a></p>
</div></body></html>
HTML;
    exit;
}

/** Enforce a plan quota, e.g. before creating a menu item. */
function quota_check(string $metric, int $adding = 1): void
{
    $tenant = Context::get();
    if ($tenant === null) {
        return;
    }
    static $gates = [];
    $gate = $gates[$tenant->id()] ??= new FeatureGate($tenant);
    $gate->checkOrFail($metric, $adding);
}

/** Meter a business event against the tenant's usage counters. */
function record_usage(string $metric, int $quantity = 1, array $meta = []): void
{
    $tenantId = Context::id();
    if ($tenantId === null) {
        return;
    }
    UsageService::record($tenantId, $metric, $quantity, 'app', $meta);
}
