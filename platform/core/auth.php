<?php
/**
 * RestaurantOS — platform authentication.
 *
 * The platform console has its own identity store (`platform_users`) entirely
 * separate from the tenant `admins` table: a restaurant owner can never reach
 * the superadmin console just because they are an admin of their own site.
 *
 * @package Resto\Platform
 */

namespace Resto\Platform;

use Resto\Database\Manager;
use Resto\Http\ApiException;
use Resto\Http\Request;
use Resto\Http\Response;
use Resto\Support\Clock;
use Resto\Support\Config;
use Resto\Support\Str;
use Resto\Tenancy\Context;
use Resto\Tenancy\TenantRepository;

/* -------------------------------------------------------------------------
 * RateLimiter — file-backed, works without Redis
 * ---------------------------------------------------------------------- */

final class RateLimiter
{
    public static function tooManyAttempts(string $key, int $max, int $decaySeconds = 60): bool
    {
        $data = self::read($key);
        if ($data === null || $data['reset'] < time()) {
            return false;
        }
        return $data['count'] >= $max;
    }

    public static function hit(string $key, int $decaySeconds = 60): void
    {
        $data = self::read($key);
        if ($data === null || $data['reset'] < time()) {
            $data = ['count' => 0, 'reset' => time() + $decaySeconds];
        }
        $data['count']++;
        self::write($key, $data);
    }

    public static function clear(string $key): void
    {
        $path = self::path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function availableIn(string $key): int
    {
        $data = self::read($key);
        return $data === null ? 0 : max(0, $data['reset'] - time());
    }

    private static function read(string $key): ?array
    {
        $path = self::path($key);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function write(string $key, array $data): void
    {
        $path = self::path($key);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    private static function path(string $key): string
    {
        $dir = Config::path((string) Config::get('storage.path', 'storage') . '/cache/ratelimit');
        return $dir . '/' . sha1($key) . '.json';
    }
}

/* -------------------------------------------------------------------------
 * Csrf — token issued with the session, echoed back in a header
 * ---------------------------------------------------------------------- */

final class Csrf
{
    public const HEADER = 'x-csrf-token';

    public static function token(): string
    {
        if (empty($_SESSION['platform_csrf'])) {
            $_SESSION['platform_csrf'] = Str::token(24);
        }
        return (string) $_SESSION['platform_csrf'];
    }

    public static function verify(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['platform_csrf'])
            && hash_equals((string) $_SESSION['platform_csrf'], $token);
    }

    public static function rotate(): string
    {
        $_SESSION['platform_csrf'] = Str::token(24);
        return (string) $_SESSION['platform_csrf'];
    }
}

/* -------------------------------------------------------------------------
 * Auth — platform users (superadmins)
 * ---------------------------------------------------------------------- */

final class Auth
{
    public const ROLE_OWNER   = 'owner';
    public const ROLE_ADMIN   = 'admin';
    public const ROLE_SUPPORT = 'support';
    public const ROLE_BILLING = 'billing';
    public const ROLE_VIEWER  = 'viewer';

    private const MAX_ATTEMPTS = 6;
    private const LOCKOUT_MINUTES = 15;

    private static ?array $cached = null;

    /** Role => permission keys ("*" = everything). */
    private const PERMISSIONS = [
        'owner'   => ['*'],
        'admin'   => [
            'tenants.view', 'tenants.create', 'tenants.update', 'tenants.suspend', 'tenants.delete',
            'tenants.impersonate', 'plans.view', 'plans.manage', 'billing.view', 'billing.manage',
            'usage.view', 'audit.view', 'settings.manage', 'users.manage', 'system.view',
        ],
        'support' => ['tenants.view', 'tenants.update', 'tenants.impersonate', 'usage.view', 'audit.view', 'plans.view'],
        'billing' => ['tenants.view', 'billing.view', 'billing.manage', 'plans.view', 'plans.manage', 'usage.view', 'audit.view'],
        'viewer'  => ['tenants.view', 'plans.view', 'billing.view', 'usage.view', 'audit.view', 'system.view'],
    ];

    /** Every permission the platform recognises (for /auth/me capability maps). */
    public static function permissions(): array
    {
        $all = [];
        foreach (self::PERMISSIONS as $list) {
            foreach ($list as $permission) {
                if ($permission !== '*') {
                    $all[$permission] = true;
                }
            }
        }
        return array_keys($all);
    }

    public static function roles(): array
    {
        return [
            self::ROLE_OWNER   => ['label' => 'Owner', 'description' => 'Unrestricted access, including platform settings and staff'],
            self::ROLE_ADMIN   => ['label' => 'Administrator', 'description' => 'Full tenant, billing and plan management'],
            self::ROLE_SUPPORT => ['label' => 'Support', 'description' => 'Can inspect tenants and sign in on their behalf'],
            self::ROLE_BILLING => ['label' => 'Billing', 'description' => 'Subscriptions, invoices and plans'],
            self::ROLE_VIEWER  => ['label' => 'Read only', 'description' => 'Can look, cannot touch'],
        ];
    }

    /** @return array|null The signed-in platform user */
    public static function user(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $id = $_SESSION['platform_user_id'] ?? null;
        if (!$id) {
            return null;
        }

        $token = $_SESSION['platform_session_token'] ?? null;
        if ($token) {
            $sessStmt = Manager::platform()->prepare(
                'SELECT id, expires_at FROM platform_sessions WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1'
            );
            $sessStmt->execute([hash('sha256', (string) $token)]);
            $sess = $sessStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$sess || (isset($sess['expires_at']) && $sess['expires_at'] <= Clock::now())) {
                unset($_SESSION['platform_user_id'], $_SESSION['platform_session_token'], $_SESSION['platform_csrf']);
                self::$cached = null;
                return null;
            }
        }

        $stmt = Manager::platform()->prepare('SELECT * FROM platform_users WHERE id = ? AND status = ?');
        $stmt->execute([(int) $id, 'active']);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        return self::$cached = ($user ?: null);
    }

    public static function id(): ?int
    {
        return isset(self::$cached['id']) ? (int) self::$cached['id'] : (isset($_SESSION['platform_user_id']) ? (int) $_SESSION['platform_user_id'] : null);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function can(string $permission, ?array $user = null): bool
    {
        $user ??= self::user();
        if ($user === null) {
            return false;
        }
        $granted = self::PERMISSIONS[$user['role']] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }
        return false;
    }

    public static function requirePermission(string $permission): void
    {
        if (!self::check()) {
            throw ApiException::unauthorized();
        }
        if (!self::can($permission)) {
            throw ApiException::forbidden('Your role (' . (self::user()['role'] ?? '?') . ') cannot ' . str_replace('.', ' ', $permission));
        }
    }

    /**
     * Attempt a sign-in.
     *
     * @throws ApiException on bad credentials, disabled account or lockout
     */
    public static function attempt(string $email, string $password, Request $request, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        $key   = 'login:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS, self::LOCKOUT_MINUTES * 60)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw new ApiException("Too many failed attempts. Try again in {$minutes} minute(s).", 429, [], 'rate_limited');
        }

        $stmt = Manager::platform()->prepare('SELECT * FROM platform_users WHERE LOWER(email) = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            RateLimiter::hit($key, self::LOCKOUT_MINUTES * 60);
            Audit::record([
                'action'      => 'auth.login_failed',
                'actor_type'  => 'platform',
                'actor_name'  => $email,
                'description' => 'Failed sign-in attempt from ' . $request->ip(),
                'severity'    => 'warning',
                'ip'          => $request->ip(),
            ]);
            throw new ApiException('These credentials do not match our records.', 401, [], 'invalid_credentials');
        }

        if ($user['status'] !== 'active') {
            throw ApiException::forbidden('This account is ' . $user['status'] . '. Ask an owner to restore it.');
        }

        RateLimiter::clear($key);
        self::startSession($user, $request, $remember);

        Manager::platform()
            ->prepare('UPDATE platform_users SET last_login_at = ?, last_login_ip = ?, failed_attempts = 0 WHERE id = ?')
            ->execute([Clock::now(), $request->ip(), (int) $user['id']]);

        Audit::record([
            'action'      => 'auth.login',
            'actor_type'  => 'platform',
            'actor_id'    => (int) $user['id'],
            'actor_name'  => $user['name'],
            'description' => $user['name'] . ' signed in',
            'ip'          => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        unset($user['password_hash']);
        return $user;
    }

    public static function startSession(array $user, Request $request, bool $remember = false): void
    {
        $_SESSION['platform_user_id'] = (int) $user['id'];
        self::$cached = $user;
        Csrf::rotate();

        $token = Str::token(32);
        Manager::platform()->prepare(
            'INSERT INTO platform_sessions (user_id, token_hash, ip, user_agent, device, last_seen_at, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $user['id'],
            hash('sha256', $token),
            $request->ip(),
            $request->userAgent(),
            self::describeDevice($request->userAgent()),
            Clock::now(),
            Clock::addDays(Clock::now(), $remember ? 30 : 7),
            Clock::now(),
        ]);

        // Stored in the PHP session so the API can validate the cookie pair.
        $_SESSION['platform_session_token'] = $token;
    }

    public static function logout(): void
    {
        $userId   = self::id();
        $userName = self::$cached['name'] ?? null;
        $token    = $_SESSION['platform_session_token'] ?? null;

        unset(
            $_SESSION['platform_user_id'],
            $_SESSION['platform_session_token'],
            $_SESSION['platform_csrf']
        );
        self::$cached = null;

        if ($token) {
            try {
                Manager::platform()
                    ->prepare('UPDATE platform_sessions SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL')
                    ->execute([Clock::now(), hash('sha256', (string) $token)]);
            } catch (\Throwable $e) {
                // Ignore DB error so session destruction is never blocked
            }
        }

        if ($userId) {
            try {
                Audit::record([
                    'action'      => 'auth.logout',
                    'actor_type'  => 'platform',
                    'actor_id'    => $userId,
                    'actor_name'  => $userName,
                    'description' => 'Signed out',
                ]);
            } catch (\Throwable $e) {
            }
        }

        if (self::isImpersonating()) {
            self::stopImpersonation();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Sessions listed in the console's security panel. */
    public static function sessions(?int $userId = null): array
    {
        $sql    = 'SELECT * FROM platform_sessions WHERE revoked_at IS NULL AND expires_at > ?';
        $params = [Clock::now()];
        if ($userId !== null) {
            $sql     .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY last_seen_at DESC LIMIT 25';

        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function revokeSession(int $id): void
    {
        $current = $_SESSION['platform_session_token'] ?? null;
        $currentHash = $current ? hash('sha256', (string) $current) : null;

        $stmt = Manager::platform()->prepare('SELECT token_hash FROM platform_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $targetHash = $stmt->fetchColumn();

        Manager::platform()->prepare('UPDATE platform_sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([Clock::now(), $id]);
        Audit::record([
            'action'      => 'auth.session_revoked',
            'actor_type'  => 'platform',
            'actor_id'    => self::id(),
            'actor_name'  => self::user()['name'] ?? null,
            'target_type' => 'platform_session',
            'target_id'   => (string) $id,
            'description' => 'Revoked a session',
            'severity'    => 'notice',
        ]);

        if ($currentHash && $targetHash && hash_equals((string) $targetHash, (string) $currentHash)) {
            self::logout();
        }
    }

    public static function revokeOtherSessions(int $userId, ?string $currentToken = null): int
    {
        $currentToken ??= ($_SESSION['platform_session_token'] ?? null);
        $hash = $currentToken ? hash('sha256', (string) $currentToken) : '';

        $stmt = Manager::platform()->prepare(
            'UPDATE platform_sessions SET revoked_at = ? WHERE user_id = ? AND token_hash != ? AND revoked_at IS NULL'
        );
        $stmt->execute([Clock::now(), $userId, $hash]);
        $count = $stmt->rowCount();

        Audit::record([
            'action'      => 'auth.sessions_revoked_others',
            'actor_type'  => 'platform',
            'actor_id'    => $userId,
            'description' => "Revoked {$count} other device session(s)",
            'severity'    => 'notice',
        ]);

        return $count;
    }

    /* ---------------- impersonation ---------------- */

    /** Create a single-use token that signs the superadmin into a tenant. */
    public static function createImpersonationToken(int $tenantId, string $reason): string
    {
        $token = Str::token(32);
        Manager::platform()->prepare(
            'INSERT INTO impersonation_tokens (tenant_id, platform_user_id, token_hash, reason, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $tenantId,
            self::id(),
            hash('sha256', $token),
            substr($reason, 0, 191),
            Clock::addDays(Clock::now(), 1),
            Clock::now(),
        ]);

        Audit::record([
            'action'      => 'tenant.impersonation_started',
            'actor_type'  => 'platform',
            'actor_id'    => self::id(),
            'actor_name'  => self::user()['name'] ?? null,
            'tenant_id'   => $tenantId,
            'target_type' => 'tenant',
            'target_id'   => (string) $tenantId,
            'description' => 'Started support session: ' . $reason,
            'severity'    => 'warning',
        ]);

        return $token;
    }

    /** Redeem an impersonation token: open a tenant admin session. */
    public static function redeemImpersonation(string $token): ?array
    {
        $hash = hash('sha256', $token);
        $stmt = Manager::platform()->prepare(
            'SELECT * FROM impersonation_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([$hash, Clock::now()]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        Manager::platform()->prepare('UPDATE impersonation_tokens SET used_at = ? WHERE id = ?')->execute([Clock::now(), (int) $row['id']]);

        $tenant = (new TenantRepository())->find((int) $row['tenant_id']);
        if ($tenant === null) {
            return null;
        }

        // Sign into the tenant as its owner account.
        $conn = $tenant->connection();
        $admin = $conn->query("SELECT * FROM admins WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if (!$admin) {
            return null;
        }

        Context::set($tenant);
        Context::setImpersonating(true);
        $_SESSION['admin_id']       = (int) $admin['id'];
        $_SESSION['admin_username'] = (string) $admin['username'];
        $_SESSION['admin_role']     = (string) $admin['role'];
        $_SESSION['is_admin']       = true;
        $_SESSION['resto_tenant']   = $tenant->slug();
        $_SESSION['impersonating']  = [
            'platform_user_id' => (int) $row['platform_user_id'],
            'platform_user'    => self::user()['name'] ?? 'Support',
            'reason'           => (string) $row['reason'],
            'started_at'       => Clock::now(),
        ];

        return ['tenant' => $tenant, 'admin' => $admin];
    }

    public static function isImpersonating(): bool
    {
        return !empty($_SESSION['impersonating']);
    }

    public static function stopImpersonation(): void
    {
        $info = $_SESSION['impersonating'] ?? null;
        unset($_SESSION['impersonating'], $_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role'], $_SESSION['is_admin']);

        if (is_array($info)) {
            Audit::record([
                'action'      => 'tenant.impersonation_ended',
                'actor_type'  => 'platform',
                'actor_id'    => $info['platform_user_id'] ?? null,
                'actor_name'  => $info['platform_user'] ?? null,
                'description' => 'Ended support session for tenant #' . Context::id(),
                'severity'    => 'notice',
            ]);
        }
    }

    private static function describeDevice(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown device';
        }
        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS')  => 'macOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Linux')   => 'Linux',
            default => 'Unknown OS',
        };
        $browser = match (true) {
            str_contains($userAgent, 'Edg/')    => 'Edge',
            str_contains($userAgent, 'Chrome')  => 'Chrome',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Safari')  => 'Safari',
            str_contains($userAgent, 'curl')    => 'curl',
            default => 'Browser',
        };
        return "$browser on $os";
    }
}
