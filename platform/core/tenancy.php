<?php
/**
 * RestaurantOS — tenancy.
 *
 * A tenant is one restaurant business. Tenants are resolved from the request
 * (custom domain, sub-domain, header, query string or sticky session) and are
 * the unit of isolation: every tenant owns a private database, a subscription,
 * a plan and its own users.
 *
 * @package Resto\Tenancy
 */

namespace Resto\Tenancy;

use Resto\Database\Connection;
use Resto\Database\Manager;
use Resto\Support\Clock;
use Resto\Support\Config;
use Resto\Support\Str;

/* -------------------------------------------------------------------------
 * Tenant — a single restaurant account
 * ---------------------------------------------------------------------- */

final class Tenant
{
    public function __construct(private array $attributes, public string $pathPrefix = '')
    {
    }

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function id(): int
    {
        return (int) ($this->attributes['id'] ?? 0);
    }

    public function slug(): string
    {
        return (string) ($this->attributes['slug'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->attributes['name'] ?? 'Restaurant');
    }

    /** The short code staff type at sign-in to say which restaurant they are signing into. */
    public function accessCode(): string
    {
        return (string) ($this->attributes['access_code'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->attributes['status'] ?? 'trial');
    }

    public function isActive(): bool
    {
        return in_array($this->status(), ['active', 'trial'], true);
    }

    public function onTrial(): bool
    {
        return $this->status() === 'trial' && (string) ($this->attributes['trial_ends_at'] ?? '') >= Clock::now();
    }

    public function trialDaysLeft(): ?int
    {
        return Clock::daysLeft($this->attributes['trial_ends_at'] ?? null);
    }

    /** Public hostname for this tenant (custom domain wins). */
    public function hostname(): string
    {
        if (!empty($this->attributes['custom_domain'])) {
            return (string) $this->attributes['custom_domain'];
        }
        $root = (string) Config::get('app.root_domain');
        return $this->slug() . '.' . $root;
    }

    public function baseUrl(string $path = ''): string
    {
        $scheme = (string) Config::get('app.scheme', 'http');
        $base   = $scheme . '://' . $this->hostname() . $this->pathPrefix;
        return $path === '' ? $base : rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    public function dbName(): string
    {
        return (string) ($this->attributes['db_name'] ?? Manager::tenantDatabaseName($this->slug()));
    }

    public function connection(): Connection
    {
        return Manager::tenant($this->attributes);
    }

    public function metadata(): array
    {
        $meta = $this->attributes['metadata'] ?? null;
        if (is_string($meta)) {
            return json_decode($meta, true) ?: [];
        }
        return is_array($meta) ? $meta : [];
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->attributes, array_flip($keys));
    }
}

/* -------------------------------------------------------------------------
 * TenantCode — the short code that identifies a restaurant at sign-in
 * ---------------------------------------------------------------------- */

/**
 * Every restaurant account owns a unique, human-readable code such as "K7M2Q".
 * Visitors type it on the sign-in form: on a single-host installation
 * (localhost, a sandbox preview, one shared landing page) the code is what
 * tells the application which tenant database to authenticate against.
 *
 * The alphabet leaves out 0/O, 1/I/L and U so a code read over the phone or
 * printed on a receipt cannot be confused. The code is an identifier, not a
 * secret — treat it like a company/branch number, never as a password.
 */
final class TenantCode
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Characters per code (config: tenancy.access_code_length, default 5). */
    public static function length(): int
    {
        return max(4, min(12, (int) Config::get('tenancy.access_code_length', 5)));
    }

    /** A fresh random code, e.g. "K7M2Q". */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max      = strlen($alphabet) - 1;
        $code     = '';
        for ($i = 0, $length = self::length(); $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    /** " k7m-2q " => "K7M2Q" — accept a code however a human types it. */
    public static function normalise(string $code): string
    {
        return preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($code))) ?? '';
    }

    /** Does this look like one of our codes? (Older codes may differ in length.) */
    public static function isWellFormed(string $code): bool
    {
        $code   = self::normalise($code);
        $length = strlen($code);

        return $length >= 4 && $length <= 12 && strspn($code, self::ALPHABET) === $length;
    }
}

/* -------------------------------------------------------------------------
 * Context — the tenant of the current request
 * ---------------------------------------------------------------------- */

final class Context
{
    private static ?Tenant $tenant = null;
    private static bool $impersonating = false;

    public static function set(Tenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function get(): ?Tenant
    {
        return self::$tenant;
    }

    public static function id(): ?int
    {
        return self::$tenant?->id();
    }

    public static function require(): Tenant
    {
        if (self::$tenant === null) {
            throw new \RuntimeException('No tenant in context');
        }
        return self::$tenant;
    }

    public static function setImpersonating(bool $value): void
    {
        self::$impersonating = $value;
    }

    public static function isImpersonating(): bool
    {
        return self::$impersonating;
    }
}

/* -------------------------------------------------------------------------
 * Resolver — which tenant is this request for?
 * ---------------------------------------------------------------------- */

final class Resolver
{
    private const SESSION_KEY = 'resto_tenant';

    private static ?Tenant $resolved = null;
    private static bool $attempted = false;
    private static bool $platformRequest = false;

    public static function isPlatformRequest(): bool
    {
        return self::$platformRequest;
    }

    public static function markPlatformRequest(): void
    {
        self::$platformRequest = true;
    }

    public static function resolve(bool $fresh = false): ?Tenant
    {
        if (self::$attempted && !$fresh) {
            return self::$resolved;
        }
        self::$attempted = true;

        $repo = new TenantRepository();

        // 1. Explicit header (API clients, internal service calls).
        $header = $_SERVER['HTTP_X_TENANT'] ?? null;
        if ($header) {
            $tenant = $repo->findBySlug((string) $header);
            if ($tenant) {
                return self::$resolved = $tenant;
            }
        }

        // 2. ?__tenant=slug or ?__tenant=code — lets one domain serve several restaurants
        //    (used by the clean /t/<slug> rewrite, sandbox preview and quick tenant switching).
        if (isset($_GET['__tenant'])) {
            $val = trim((string) $_GET['__tenant']);
            $tenant = $repo->findBySlug($val) ?? $repo->findByCode($val);
            if ($tenant) {
                $_SESSION[self::SESSION_KEY] = $tenant->slug();
                return self::$resolved = $tenant;
            }
        }

        $host = self::host();
        $host = preg_replace('/:\d+$/', '', $host ?? '') ?? '';

        // 3. Custom domain.
        if ($host !== '') {
            $tenant = $repo->findByHostname($host);
            if ($tenant) {
                return self::$resolved = $tenant;
            }
        }

        // 4. Sub-domain of the platform root domain.
        $sub = self::subdomainOf($host);
        if ($sub !== '') {
            $tenant = $repo->findBySlug($sub);
            if ($tenant) {
                return self::$resolved = $tenant;
            }
        }

        // 5. Sticky session (sandbox/preview convenience, and local dev).
        $sticky = $_SESSION[self::SESSION_KEY] ?? null;
        if ($sticky && (Config::isLocal() || !Links::usesHostRouting())) {
            $tenant = $repo->findBySlug((string) $sticky);
            if ($tenant) {
                return self::$resolved = $tenant;
            }
        }

        // 6. Single-host environments (sandbox preview, localhost) fall back to
        //    the demo restaurant so the storefront and admin always resolve.
        //    Production with wildcard DNS turns this off with
        //    TENANCY_FALLBACK_TO_DEMO=false and gets the "no restaurant here"
        //    page instead.
        $isSingleHost = Config::isLocal()
            || !Links::usesHostRouting()
            || $host === $root;

        if ($isSingleHost && Config::get('tenancy.fallback_to_demo', true)) {
            $demo = $repo->findBySlug((string) Config::get('tenancy.demo_tenant', 'demo'))
                ?? $repo->firstActive();
            if ($demo) {
                return self::$resolved = $demo;
            }
        }

        return self::$resolved = null;
    }

    /**
     * Did the request host identify the tenant on its own — a custom domain or
     * a tenant sub-domain? Single-host installations (localhost, a sandbox
     * preview, one shared landing page) cannot, and that is exactly when a
     * visitor has to type their restaurant code at sign-in.
     */
    public static function hostIdentifiesTenant(): bool
    {
        $host = preg_replace('/:\d+$/', '', (string) (self::host() ?? '')) ?? '';
        if ($host === '') {
            return false;
        }

        return self::subdomainOf($host) !== ''
            || (new TenantRepository())->findByHostname($host) !== null;
    }

    /**
     * Remember a tenant for the rest of this session.
     *
     * Used by the sign-in screens: once a visitor has identified their
     * restaurant by code, every later request must keep using it.
     */
    public static function remember(Tenant $tenant): void
    {
        self::$resolved  = $tenant;
        self::$attempted = true;

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $tenant->slug();
        }
    }

    /**
     * Tenant sub-domain of a host, or '' when the host is not a tenant
     * sub-domain of the platform root domain (or is a reserved one).
     */
    private static function subdomainOf(string $host): string
    {
        $root = (string) Config::get('app.root_domain');
        if ($host === '' || $root === '' || !str_ends_with($host, '.' . $root)) {
            return '';
        }

        $sub = substr($host, 0, -(strlen($root) + 1));
        // Deep sub-domains may also point at us: "eu.aurora.restaurantos.test".
        $sub = str_contains($sub, '.') ? substr($sub, strrpos($sub, '.') + 1) : $sub;

        if ($sub === '' || in_array($sub, (array) Config::get('tenancy.reserved_hosts', []), true)) {
            return '';
        }

        return $sub;
    }

    /** Reset memoised state (used by tests and the provisioning wizard). */
    public static function flush(): void
    {
        self::$resolved  = null;
        self::$attempted = false;
    }

    public static function forgetSticky(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        self::flush();
    }

    private static function host(): ?string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
        if ($host === null && PHP_SAPI === 'cli') {
            $host = (string) Config::get('app.root_domain');
        }
        return $host;
    }
}

/* -------------------------------------------------------------------------
 * Gatekeeper — what happens when a subscription lapses
 * ---------------------------------------------------------------------- */

final class Gatekeeper
{
    /** Paths a suspended tenant may still reach (billing, login, assets). */
    private const ALLOWED = [
        'subscription.php', 'billing.php', 'auth/login.php', 'auth/logout.php',
        'admin/login.php', 'admin/logout.php', 'admin/billing.php', 'platform/impersonate.php',
    ];

    /**
     * Plain-language health report for the tenant detail page.
     *
     * @return array{score:int,status:string,issues:array<int,array{level:string,title:string,detail:string}>}
     */
    public static function healthReport(Tenant $tenant): array
    {
        $issues = [];
        $score  = 100;

        if ($tenant->status() === 'past_due') {
            $issues[] = ['level' => 'critical', 'title' => 'Payment overdue', 'detail' => 'The last invoice has not been settled.'];
            $score -= 35;
        }
        if ($tenant->status() === 'suspended') {
            $issues[] = ['level' => 'critical', 'title' => 'Suspended', 'detail' => (string) ($tenant->get('suspended_reason') ?: 'Access is blocked for this restaurant.')];
            $score -= 45;
        }
        if ($tenant->status() === 'cancelled') {
            $issues[] = ['level' => 'warning', 'title' => 'Cancelled', 'detail' => 'The subscription has ended.'];
            $score -= 20;
        }
        if ($tenant->onTrial()) {
            $left = (int) $tenant->trialDaysLeft();
            $issues[] = [
                'level'  => $left <= 3 ? 'warning' : 'info',
                'title'  => 'Trial ends in ' . $left . ' day(s)',
                'detail' => 'Reach out before it expires to keep the workspace running.',
            ];
            $score -= $left <= 3 ? 15 : 5;
        }

        $lastSeen = $tenant->get('last_activity_at');
        if ($lastSeen) {
            $idle = Clock::daysBetween((string) $lastSeen, Clock::now());
            if ($idle >= 30) {
                $issues[] = ['level' => 'warning', 'title' => 'Dormant for ' . $idle . ' days', 'detail' => 'No orders or sign-ins in over a month.'];
                $score -= 20;
            } elseif ($idle >= 14) {
                $issues[] = ['level' => 'info', 'title' => 'Quiet for ' . $idle . ' days', 'detail' => 'Worth a check-in call.'];
                $score -= 8;
            }
        }

        $openInvoices = (int) \Resto\Platform\Metrics::scalar(
            "SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND status IN ('open','past_due')",
            [$tenant->id()]
        );
        if ($openInvoices > 0 && $tenant->status() !== 'past_due') {
            $issues[] = ['level' => 'info', 'title' => $openInvoices . ' open invoice(s)', 'detail' => 'Nothing to worry about yet — they are not overdue.'];
        }

        $score = max(0, min(100, $score));

        return [
            'score'  => $score,
            'status' => match (true) {
                $score >= 85 => 'healthy',
                $score >= 60 => 'watch',
                default      => 'at_risk',
            },
            'issues' => $issues,
        ];
    }

    public static function enforce(Tenant $tenant): void
    {
        if ($tenant->isActive()) {
            // Trial that quietly expired: flag it, but let the flow continue.
            return;
        }

        if (PHP_SAPI === 'cli') {
            return;
        }

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (in_array($script, self::ALLOWED, true) || str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
            return;
        }

        http_response_code(402);
        $name   = htmlspecialchars($tenant->name(), ENT_QUOTES);
        $reason = htmlspecialchars((string) ($tenant->get('suspended_reason') ?: 'This account is not active.'), ENT_QUOTES);
        $console = htmlspecialchars(platform_url('/'), ENT_QUOTES);

        echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$name} — account inactive</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #0b1120; color: #e2e8f0;
         display: grid; place-items: center; min-height: 100vh; }
  .card { max-width: 40rem; padding: 3rem; }
  .badge { display: inline-block; font-size: .75rem; letter-spacing: .08em; text-transform: uppercase;
           padding: .35rem .7rem; border-radius: 999px; background: #7f1d1d; color: #fecaca; }
  h1 { font-size: 1.75rem; margin: 1.25rem 0 .75rem; }
  p { color: #94a3b8; }
  a { color: #818cf8; }
</style></head>
<body><div class="card">
  <span class="badge">Account {$tenant->status()}</span>
  <h1>{$name} is temporarily unavailable</h1>
  <p>{$reason}</p>
  <p>Platform administrators or owners can restore access from the platform console: <a href="{$console}">{$console}</a></p>
</div></body></html>
HTML;
        exit;
    }
}

/* -------------------------------------------------------------------------
 * TenantRepository — persistence for tenants
 * ---------------------------------------------------------------------- */

final class TenantRepository
{
    private static array $cache = [];

    /**
     * @param array{status?:string|array,plan_id?:int,search?:string,cycle?:string} $filters
     * @return Tenant[]
     */
    public function all(array $filters = [], string $orderBy = 'created_at', string $direction = 'DESC', int $limit = 0, int $offset = 0): array
    {
        $sql    = 'SELECT t.*, p.name AS plan_name, p.code AS plan_code FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id';
        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $where[]  = 't.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params   = array_merge($params, $statuses);
        }
        if (!empty($filters['plan_id'])) {
            $where[]  = 't.plan_id = ?';
            $params[] = (int) $filters['plan_id'];
        }
        if (!empty($filters['cycle'])) {
            $where[]  = 't.billing_cycle = ?';
            $params[] = $filters['cycle'];
        }
        if (!empty($filters['search'])) {
            $term    = '%' . $filters['search'] . '%';
            $code    = '%' . TenantCode::normalise((string) $filters['search']) . '%';
            $where[] = '(t.name LIKE ? OR t.slug LIKE ? OR t.owner_email LIKE ? OR t.owner_name LIKE ? OR t.access_code LIKE ?)';
            $params  = array_merge($params, [$term, $term, $term, $term, $code]);
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $orderBy   = preg_match('/^[a-z_.]+$/i', $orderBy) ? $orderBy : 'created_at';
        $sql      .= " ORDER BY {$orderBy} {$direction}";

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }

        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);

        $tenants = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tenants[] = Tenant::fromRow($row);
        }
        return $tenants;
    }

    public function paginate(array $filters = [], int $perPage = 25, int $page = 1, string $orderBy = 'created_at', string $direction = 'DESC'): array
    {
        $total = $this->count($filters);
        $page  = max(1, $page);
        return [
            'data'  => $this->all($filters, $orderBy, $direction, $perPage, ($page - 1) * $perPage),
            'meta'  => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    public function count(array $filters = []): int
    {
        $sql    = 'SELECT COUNT(*) FROM tenants t';
        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $where[]  = 't.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params   = array_merge($params, $statuses);
        }
        if (!empty($filters['plan_id'])) {
            $where[]  = 't.plan_id = ?';
            $params[] = (int) $filters['plan_id'];
        }
        if (!empty($filters['search'])) {
            $term    = '%' . $filters['search'] . '%';
            $code    = '%' . TenantCode::normalise((string) $filters['search']) . '%';
            $where[] = '(t.name LIKE ? OR t.slug LIKE ? OR t.owner_email LIKE ? OR t.owner_name LIKE ? OR t.access_code LIKE ?)';
            $params  = array_merge($params, [$term, $term, $term, $term, $code]);
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function find(int $id, bool $fresh = false): ?Tenant
    {
        if (!$fresh && isset(self::$cache['id:' . $id])) {
            return self::$cache['id:' . $id];
        }
        $stmt = Manager::platform()->prepare(
            'SELECT t.*, p.name AS plan_name, p.code AS plan_code, p.limits AS plan_limits, p.features AS plan_features
             FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return self::$cache['id:' . $id] = Tenant::fromRow($row);
    }

    public function findOrFail(int $id): Tenant
    {
        $tenant = $this->find($id);
        if (!$tenant) {
            throw new \RuntimeException('Tenant not found');
        }
        return $tenant;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }
        if (isset(self::$cache['slug:' . $slug])) {
            return self::$cache['slug:' . $slug];
        }
        $stmt = Manager::platform()->prepare(
            'SELECT t.*, p.name AS plan_name, p.code AS plan_code, p.limits AS plan_limits, p.features AS plan_features
             FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.slug = ?'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (self::$cache['slug:' . $slug] = Tenant::fromRow($row)) : null;
    }

    /** Look a tenant up by the code staff type at sign-in. */
    public function findByCode(string $code): ?Tenant
    {
        $code = TenantCode::normalise($code);
        if ($code === '') {
            return null;
        }
        if (isset(self::$cache['code:' . $code])) {
            return self::$cache['code:' . $code];
        }
        $stmt = Manager::platform()->prepare(
            'SELECT t.*, p.name AS plan_name, p.code AS plan_code, p.limits AS plan_limits, p.features AS plan_features
             FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE UPPER(t.access_code) = ?'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (self::$cache['code:' . $code] = Tenant::fromRow($row)) : null;
    }

    public function findByUuid(string $uuid): ?Tenant
    {
        $stmt = Manager::platform()->prepare('SELECT * FROM tenants WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Tenant::fromRow($row) : null;
    }

    public function findByHostname(string $hostname): ?Tenant
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }
        if (isset(self::$cache['host:' . $hostname])) {
            return self::$cache['host:' . $hostname];
        }
        $stmt = Manager::platform()->prepare(
            'SELECT t.* FROM tenants t WHERE LOWER(t.custom_domain) = ? LIMIT 1'
        );
        $stmt->execute([$hostname]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            return self::$cache['host:' . $hostname] = Tenant::fromRow($row);
        }

        $stmt = Manager::platform()->prepare('SELECT t.* FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id WHERE LOWER(d.hostname) = ? LIMIT 1');
        $stmt->execute([$hostname]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (self::$cache['host:' . $hostname] = Tenant::fromRow($row)) : null;
    }

    public function firstActive(): ?Tenant
    {
        $stmt = Manager::platform()->query("SELECT t.* FROM tenants t WHERE t.status IN ('active','trial') ORDER BY t.id ASC LIMIT 1");
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Tenant::fromRow($row) : null;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM tenants WHERE slug = ?';
        $params = [$slug];
        if ($exceptId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM tenants WHERE LOWER(owner_email) = ?';
        $params = [strtolower($email)];
        if ($exceptId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Is this sign-in code already taken by another restaurant? */
    public function accessCodeExists(string $code, ?int $exceptId = null): bool
    {
        $code = TenantCode::normalise($code);
        if ($code === '') {
            return false;
        }

        $sql    = 'SELECT COUNT(*) FROM tenants WHERE UPPER(access_code) = ?';
        $params = [$code];
        if ($exceptId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** A code no other restaurant is using yet. */
    public function uniqueAccessCode(): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = TenantCode::generate();
            if (!$this->accessCodeExists($code)) {
                return $code;
            }
        }

        // 25 collisions in a row means the short-code space is genuinely
        // crowded: widen instead of failing the signup.
        return substr(TenantCode::generate() . TenantCode::generate(), 0, 12);
    }

    /** Issue a fresh code (e.g. after one has leaked) and return the tenant. */
    public function rotateAccessCode(int $id): Tenant
    {
        return $this->update($id, ['access_code' => $this->uniqueAccessCode()]);
    }

    public function create(array $data): Tenant
    {
        $platform = Manager::platform();
        $slug     = Str::uniqueSlug((string) ($data['slug'] ?? $data['name'] ?? 'restaurant'), fn ($s) => $this->slugExists($s));

        $record = [
            'uuid'          => Str::uuid(),
            'slug'          => $slug,
            // The code staff type at sign-in. A caller may supply one (validated
            // upstream); otherwise we mint a unique 5-character code here so
            // every account created through any path has one.
            'access_code'   => isset($data['access_code']) && TenantCode::isWellFormed((string) $data['access_code'])
                ? TenantCode::normalise((string) $data['access_code'])
                : $this->uniqueAccessCode(),
            'name'          => $data['name'],
            'legal_name'    => $data['legal_name'] ?? null,
            'owner_name'    => $data['owner_name'] ?? null,
            'owner_email'   => strtolower((string) $data['owner_email']),
            'owner_phone'   => $data['owner_phone'] ?? null,
            'country'       => $data['country'] ?? null,
            'city'          => $data['city'] ?? null,
            'timezone'      => $data['timezone'] ?? 'UTC',
            'currency'      => strtoupper((string) ($data['currency'] ?? 'USD')),
            'status'        => $data['status'] ?? 'trial',
            'plan_id'       => $data['plan_id'] ?? null,
            'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
            'seat_count'    => (int) ($data['seat_count'] ?? 5),
            'db_name'       => Manager::tenantDatabaseName($slug),
            'db_driver'     => Manager::driver(),
            'storage_path'  => 'storage/tenants/' . $slug,
            'subdomain'     => $slug . '.' . Config::get('app.root_domain'),
            'custom_domain' => $data['custom_domain'] ?? null,
            'trial_ends_at' => $data['trial_ends_at'] ?? null,
            'created_by'    => $data['created_by'] ?? null,
            'created_at'    => Clock::now(),
            'updated_at'    => Clock::now(),
            'notes'         => $data['notes'] ?? null,
            'metadata'      => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ];

        $columns = array_keys($record);
        $sql     = 'INSERT INTO tenants (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $platform->prepare($sql)->execute(array_values($record));

        return $this->find((int) $platform->lastInsertId(), true);
    }

    public function update(int $id, array $data): Tenant
    {
        $data['updated_at'] = Clock::now();
        $assignments = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($data)));
        $sql = "UPDATE tenants SET {$assignments} WHERE id = ?";

        $values = array_values(array_map(
            static fn ($value) => is_array($value) ? json_encode($value) : $value,
            $data
        ));
        $values[] = $id;

        Manager::platform()->prepare($sql)->execute($values);
        return $this->find($id, true);
    }

    public function delete(int $id): void
    {
        Manager::platform()->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]);
    }
}
