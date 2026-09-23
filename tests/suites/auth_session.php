<?php
/**
 * Console (superadmin) session lifecycle: expiry signs the user out, a
 * platform user id without its token is never trusted, and logout revokes
 * the platform_sessions row.
 */
use Resto\Database\Manager;
use Resto\Platform\Auth;
use Resto\Support\Clock;

return function (TestRunner $t): void {
    $t->suite('auth_session', function (TestRunner $t) {
        $db = Manager::platform();

        // The control-plane migrations seed a platform user — reuse the first one.
        $seeded = $db->query('SELECT id, status FROM platform_users ORDER BY id ASC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if (!$seeded) {
            $t->fail('a platform user is seeded for the session tests');
            return;
        }
        $userId = (int) $seeded['id'];
        $t->same('active', (string) $seeded['status'], 'the seeded platform user is active');

        $insert = $db->prepare(
            'INSERT INTO platform_sessions (user_id, token_hash, ip, user_agent, device, last_seen_at, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $seed = static function (string $token, string $expiresAt, string $lastSeen) use ($db, $insert, $userId): int {
            $insert->execute([
                $userId,
                hash('sha256', $token),
                '127.0.0.1',
                'test-agent',
                'Test device',
                $lastSeen,
                $expiresAt,
                Clock::now(),
            ]);
            return (int) $db->lastInsertId();
        };

        // --- a live session keeps the superadmin signed in ----------------
        $expiresIn7Days = Clock::addDays(Clock::now(), 7);
        $liveToken      = 'live-' . bin2hex(random_bytes(8));
        $seed($liveToken, $expiresIn7Days, Clock::now());

        $_SESSION['platform_user_id']       = $userId;
        $_SESSION['platform_session_token'] = $liveToken;

        $signedIn = Auth::user();
        $t->ok($signedIn !== null && (int) $signedIn['id'] === $userId, 'a live session resolves the signed-in superadmin');
        $t->same($expiresIn7Days, Auth::sessionExpiresAt(), 'the session expiry is exposed for the console timer');

        // --- logout signs out and revokes ---------------------------------
        Auth::logout();
        $t->ok(Auth::user() === null, 'logout clears the signed-in user');
        $t->same(null, Auth::sessionExpiresAt(), 'logout clears the reported expiry');
        $t->ok(
            !isset($_SESSION['platform_user_id']) && !isset($_SESSION['platform_session_token']) && !isset($_SESSION['platform_csrf']),
            'logout removes the session keys'
        );

        $revokedStmt = $db->prepare('SELECT revoked_at FROM platform_sessions WHERE token_hash = ?');
        $revokedStmt->execute([hash('sha256', $liveToken)]);
        $t->ok((string) $revokedStmt->fetchColumn() !== '', 'logout revokes the platform_sessions row');

        // --- an expired session signs the user out ------------------------
        $expiredToken = 'expired-' . bin2hex(random_bytes(8));
        $seed($expiredToken, Clock::addDays(Clock::now(), -1), Clock::now());

        $_SESSION['platform_user_id']       = $userId;
        $_SESSION['platform_session_token'] = $expiredToken;

        $t->ok(Auth::user() === null, 'an expired session signs the superadmin out');
        $t->ok(!isset($_SESSION['platform_user_id']) && !isset($_SESSION['platform_session_token']), 'expiry clears the session keys');
        $t->same(null, Auth::sessionExpiresAt(), 'an expired session reports no expiry');

        // --- a user id without its token is never a session ----------------
        $_SESSION['platform_user_id'] = $userId; // platform_session_token is absent
        $t->ok(Auth::user() === null, 'a platform user id without its session token is not a session');
        $t->ok(!isset($_SESSION['platform_user_id']), 'the dangling id is cleaned up immediately');

        // --- a revoked session is rejected ---------------------------------
        $revokedToken = 'revoked-' . bin2hex(random_bytes(8));
        $revokedId    = $seed($revokedToken, Clock::addDays(Clock::now(), 7), Clock::now());
        $db->prepare('UPDATE platform_sessions SET revoked_at = ? WHERE id = ?')->execute([Clock::now(), $revokedId]);

        $_SESSION['platform_user_id']       = $userId;
        $_SESSION['platform_session_token'] = $revokedToken;

        $t->ok(Auth::user() === null, 'a revoked session is rejected');

        // --- last_seen_at keeps the device list honest ---------------------
        $staleToken = 'stale-' . bin2hex(random_bytes(8));
        $staleId    = $seed($staleToken, Clock::addDays(Clock::now(), 7), gmdate('Y-m-d H:i:s', time() - 300));

        $_SESSION['platform_user_id']       = $userId;
        $_SESSION['platform_session_token'] = $staleToken;

        $t->ok(Auth::user() !== null, 'the stale-but-live session still authenticates');
        $seen = $db->prepare('SELECT last_seen_at FROM platform_sessions WHERE id = ?');
        $seen->execute([$staleId]);
        $t->ok(
            (string) $seen->fetchColumn() >= gmdate('Y-m-d H:i:s', time() - 5),
            'last_seen_at is refreshed while the session is in use'
        );

        Auth::logout(); // leave no signed-in static state behind
        unset($_SESSION['platform_user_id'], $_SESSION['platform_session_token']);
    });
};
