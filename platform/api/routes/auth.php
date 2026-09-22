<?php
/**
 * Authentication routes for the platform API.
 *
 * POST /api/v1/auth/login    → start a console session
 * POST /api/v1/auth/logout
 * GET  /api/v1/auth/me       → the signed-in user, permissions and CSRF token
 * POST /api/v1/auth/password → change your own password
 * GET  /api/v1/auth/sessions → devices signed in as this user
 */

use Resto\Database\Manager;
use Resto\Http\ApiException;
use Resto\Http\Middleware;
use Resto\Http\Request;
use Resto\Http\Response;
use Resto\Http\Router;
use Resto\Http\Validator;
use Resto\Platform\Audit;
use Resto\Platform\Auth;
use Resto\Platform\Csrf;
use Resto\Platform\Notifications;
use Resto\Support\Clock;

return static function (Router $router): void {

    $router->group('/api/v1/auth', [Middleware::platformContext()], static function (Router $router): void {

        $router->get('/me', static function (): Response {
            $user = Auth::user();
            if ($user === null) {
                throw ApiException::unauthorized();
            }
            unset($user['password_hash']);

            $can = [];
            foreach (Auth::permissions() as $permission) {
                $can[$permission] = Auth::can($permission, $user);
            }

            return Response::ok([
                'user'          => $user,
                'csrf_token'    => Csrf::token(),
                'can'           => $can,
                'roles'         => Auth::roles(),
                'is_impersonating' => Auth::isImpersonating(),
                'unread'        => Notifications::unreadCount(),
            ]);
        }, [Middleware::auth()]);

        $router->post('/login', static function (Request $request): Response {
            $data = Validator::make($request->all(), [
                'email'    => 'required|email',
                'password' => 'required|min:6',
            ]);
            $data->validateOrFail();

            $user = Auth::attempt(
                (string) $request->input('email'),
                (string) $request->input('password'),
                $request,
                $request->bool('remember')
            );

            return Response::ok([
                'user'       => $user,
                'csrf_token' => Csrf::token(),
                'roles'      => Auth::roles(),
            ]);
        }, [Middleware::throttle('login', 20)]);

        $logoutHandler = static function (): Response {
            Auth::logout();
            return Response::ok(['message' => 'Signed out']);
        };
        $router->post('/logout', $logoutHandler);
        $router->get('/logout', $logoutHandler);

        $router->post('/password', static function (Request $request): Response {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|min:6',
                'password'         => 'required|min:10|max:200',
            ]);
            $validator->validateOrFail();

            if ($request->string('password') !== $request->string('password_confirmation')) {
                throw ApiException::invalid(['password_confirmation' => ['The passwords do not match']]);
            }

            $user = Auth::user();
            $row  = Manager::platform()->prepare('SELECT password_hash FROM platform_users WHERE id = ?');
            $row->execute([Auth::id()]);
            if (!password_verify((string) $request->input('current_password'), (string) $row->fetchColumn())) {
                throw ApiException::invalid(['current_password' => ['That is not your current password']]);
            }

            Manager::platform()
                ->prepare('UPDATE platform_users SET password_hash = ?, updated_at = ? WHERE id = ?')
                ->execute([password_hash((string) $request->input('password'), PASSWORD_DEFAULT), Clock::now(), Auth::id()]);

            Audit::record([
                'action'      => 'auth.password_changed',
                'actor_id'    => Auth::id(),
                'actor_name'  => $user['name'] ?? 'platform user',
                'description' => 'Password changed',
                'severity'    => 'notice',
                'ip'          => $request->ip(),
            ]);

            return Response::ok(['message' => 'Password updated']);
        }, [Middleware::auth(), Middleware::csrf()]);

        $router->get('/sessions', static function (): Response {
            $sessions = Auth::sessions(Auth::id());
            $current  = $_SESSION['platform_session_token'] ?? null;
            $hash     = $current ? hash('sha256', (string) $current) : null;

            foreach ($sessions as &$session) {
                $session['is_current'] = $hash !== null && hash_equals((string) $session['token_hash'], $hash);
                unset($session['token_hash']);
                $session['last_seen_ago'] = Clock::human($session['last_seen_at'] ?? null);
            }

            return Response::ok($sessions);
        }, [Middleware::auth()]);

        $router->delete('/sessions/{id}', static function (Request $request, string $id): Response {
            Auth::revokeSession((int) $id);
            return Response::ok(['message' => 'Signed out that device']);
        }, [Middleware::auth(), Middleware::csrf()]);

        $router->post('/sessions/revoke-others', static function (): Response {
            $count = Auth::revokeOtherSessions(Auth::id());
            return Response::ok([
                'message' => $count > 0 ? "Signed out {$count} other device(s)" : 'No other active sessions',
                'revoked' => $count,
            ]);
        }, [Middleware::auth(), Middleware::csrf()]);
    });
};
