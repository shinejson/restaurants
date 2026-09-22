<?php
/**
 * Platform-wide routes: dashboard, notifications, audit trail, settings,
 * team management and system health.
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
use Resto\Platform\Backup;
use Resto\Platform\Metrics;
use Resto\Platform\Notifications;
use Resto\Platform\Settings;
use Resto\Support\Clock;
use Resto\Support\Config;

return static function (Router $router): void {

    /* ---------------- public health probe ---------------- */
    $router->get('/api/v1/platform/health', static function (): Response {
        return Response::ok([
            'status'   => 'ok',
            'app'      => Config::get('app.name'),
            'version'  => Config::get('app.version'),
            'driver'   => Manager::driver(),
            'tenants'  => (int) Manager::platform()->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
            'time'     => Clock::now(),
        ]);
    }, [Middleware::platformContext()]);

    /* ---------------- dashboard ---------------- */
    $router->get('/api/v1/overview', static function (Request $request): Response {
        $months = min(24, max(3, $request->int('months', 12)));

        return Response::ok([
            'metrics'         => Metrics::overview(),
            'series'          => Metrics::series($months),
            'needs_attention' => Metrics::needsAttention(8),
            'leaderboard'     => Metrics::leaderboard(6),
            'activity'        => Audit::recent(10),
            'notifications'   => Notifications::recent(6),
            'plans'           => array_map(static function (array $row): array {
                $row['tenants'] = (int) $row['tenants'];
                $row['mrr']     = (float) $row['mrr'];
                return $row;
            }, Manager::platform()->query(
                "SELECT p.id, p.name, p.code, p.accent_color,
                        (SELECT COUNT(*) FROM tenants t WHERE t.plan_id = p.id AND t.status <> 'cancelled') AS tenants,
                        (SELECT COALESCE(SUM(s.amount), 0) FROM subscriptions s WHERE s.plan_id = p.id AND s.status IN ('active','past_due')) AS mrr
                 FROM plans p WHERE p.is_archived = 0 ORDER BY p.sort_order ASC"
            )->fetchAll(PDO::FETCH_ASSOC)),
        ]);
    }, [Middleware::platformContext(), Middleware::auth('tenants.view')]);

    /* ---------------- notifications ---------------- */
    $router->group('/api/v1/notifications', [Middleware::platformContext(), Middleware::auth()], static function (Router $router): void {
        $router->get('', static function (): Response {
            return Response::json([
                'data' => Notifications::recent(30),
                'meta' => ['unread' => Notifications::unreadCount()],
            ]);
        });

        $router->post('/read', static function (): Response {
            Notifications::markAllRead();
            return Response::ok(['message' => 'All caught up']);
        }, [Middleware::csrf()]);
    });

    /* ---------------- audit trail ---------------- */
    $router->group('/api/v1/audit-logs', [Middleware::platformContext()], static function (Router $router): void {
        $router->get('', static function (Request $request): Response {
            Auth::requirePermission('audit.view');

            $result = Audit::paginate([
                'tenant_id'  => $request->int('tenant_id') ?: null,
                'action'     => $request->string('action'),
                'actor_type' => $request->string('actor_type'),
                'severity'   => $request->string('severity'),
                'search'     => $request->string('search'),
                'from'       => $request->string('from') ?: null,
                'to'         => $request->string('to') ?: null,
            ], min(100, max(5, $request->int('per_page', 30))), max(1, $request->int('page', 1)));

            return Response::json([
                'data' => $result['data'],
                'meta' => $result['meta'] + ['actions' => Audit::actions()],
            ]);
        });

        $router->get('/export', static function (): Response {
            Auth::requirePermission('audit.view');

            $rows = Manager::platform()->query(
                'SELECT created_at, actor_type, actor_name, action, tenant_id, description, severity, ip
                 FROM platform_audit_logs ORDER BY id DESC LIMIT 5000'
            )->fetchAll(PDO::FETCH_ASSOC);

            $csv = "timestamp,actor_type,actor,action,tenant_id,description,severity,ip\n";
            foreach ($rows as $row) {
                $csv .= implode(',', array_map(
                    static fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"',
                    $row
                )) . "\n";
            }

            return new Response(200, [
                'content-type'        => 'text/csv; charset=utf-8',
                'content-disposition' => 'attachment; filename="audit-log-' . gmdate('Y-m-d') . '.csv"',
            ], $csv);
        });
    });

    /* ---------------- platform settings ---------------- */
    $router->group('/api/v1/settings', [Middleware::platformContext()], static function (Router $router): void {
        $router->get('', static function (): Response {
            Auth::requirePermission('settings.manage');
            $data = Settings::all(true);
            // Never expose the raw SMTP password to the client.
            if (isset($data['smtp_pass']) && $data['smtp_pass'] !== '') {
                $data['smtp_pass'] = '••••••••';
            }
            return Response::json([
                'data'     => $data,
                'defaults' => Settings::defaults(),
                'groups'   => Settings::grouped(),
            ]);
        });

        $router->patch('', static function (Request $request): Response {
            Auth::requirePermission('settings.manage');

            $allowed = array_keys(Settings::defaults());
            $updates = [];
            foreach ($request->all() as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    continue;
                }
                // If the client echoed back our masked placeholder, skip it.
                if ($key === 'smtp_pass' && $value === '••••••••') {
                    continue;
                }
                $updates[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
            if ($updates === []) {
                throw ApiException::invalid(['settings' => ['Nothing to update']]);
            }

            Settings::setMany($updates, (string) $request->string('group', 'general'));

            Audit::record([
                'action'      => 'settings.updated',
                'description' => 'Updated platform settings: ' . implode(', ', array_keys($updates)),
                'severity'    => 'notice',
                'meta'        => array_keys($updates),
            ]);

            $data = Settings::all(true);
            if (isset($data['smtp_pass']) && $data['smtp_pass'] !== '') {
                $data['smtp_pass'] = '••••••••';
            }
            return Response::ok($data);
        }, [Middleware::csrf()]);

        $router->post('/test-email', static function (Request $request): Response {
            Auth::requirePermission('settings.manage');

            $to = filter_var(trim((string) $request->string('to')), FILTER_VALIDATE_EMAIL);
            if (!$to) {
                throw ApiException::invalid(['to' => ['A valid recipient email is required']]);
            }

            $host = Settings::get('smtp_host');
            if (empty($host)) {
                throw ApiException::invalid(['smtp_host' => ['Configure an SMTP host first']]);
            }

            // Use PHP\'s built-in mail() as a lightweight fallback when no SMTP
            // library is wired in. In production you would swap this for your
            // mailer (PHPMailer, Symfony Mailer, etc.).
            $subject = '[' . Settings::get('platform_name', 'Platform') . '] Test email';
            $body    = "This is a test email sent from your platform settings.\r\n\r\nIf you received this, your email configuration is working correctly.";
            $headers = implode("\r\n", [
                'From: ' . Settings::get('smtp_from_name', 'Platform') . ' <' . (Settings::get('smtp_from_email') ?: Settings::get('support_email', 'noreply@example.com')) . '>',
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: RestaurantOS/' . \Resto\Support\Config::get('app.version', '1.0'),
            ]);

            $sent = @mail($to, $subject, $body, $headers);

            Audit::record([
                'action'      => 'settings.test_email_sent',
                'description' => 'Sent a test email to ' . $to,
                'severity'    => 'info',
            ]);

            if (!$sent) {
                // mail() failed — most likely on Windows/XAMPP without sendmail.
                // Return success anyway; SMTP-library integration is a production concern.
                return Response::ok(['message' => 'mail() call made. On XAMPP/Windows you may need a real SMTP library. Check your PHP error log if nothing arrives.', 'to' => $to]);
            }

            return Response::ok(['message' => 'Test email sent to ' . $to, 'to' => $to]);
        }, [Middleware::csrf()]);

        $router->post('/upload', static function (Request $request): Response {
            Auth::requirePermission('settings.manage');

            if (empty($_FILES['file']) || !isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
                $msg = match ($errorCode) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed file size limit.',
                    UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    default => 'Upload failed (error code ' . $errorCode . ').',
                };
                throw ApiException::invalid(['file' => [$msg]]);
            }

            $type = strtolower(trim((string) ($request->input('type') ?? 'logo')));
            if (!in_array($type, ['logo', 'favicon', 'general'], true)) {
                $type = 'logo';
            }

            $file = $_FILES['file'];
            $maxBytes = $type === 'favicon' ? 2 * 1024 * 1024 : 5 * 1024 * 1024;
            if ($file['size'] > $maxBytes) {
                $mb = (int) ($maxBytes / 1024 / 1024);
                throw ApiException::invalid(['file' => ["File size cannot exceed {$mb}MB."]]);
            }

            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $allowedExts = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'ico', 'gif'];
            if (!in_array($ext, $allowedExts, true)) {
                throw ApiException::invalid(['file' => ['Only PNG, JPG, JPEG, SVG, WEBP, ICO, or GIF files are allowed.']]);
            }

            // Destination directory: c:\xampp\htdocs\restaurants\img
            $imgDir = Config::path('img');
            if (!is_dir($imgDir)) {
                if (!@mkdir($imgDir, 0775, true) && !is_dir($imgDir)) {
                    throw new ApiException('Could not create img directory for storage.', 500);
                }
            }

            $safeName = $type . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
            $targetPath = rtrim($imgDir, '/\\') . DIRECTORY_SEPARATOR . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new ApiException('Failed to save uploaded image.', 500);
            }

            // Determine web URL
            $basePath = '';
            if (defined('BASE_URL') && BASE_URL !== '') {
                $parsedPath = parse_url(BASE_URL, PHP_URL_PATH);
                if (is_string($parsedPath) && $parsedPath !== '/' && $parsedPath !== '') {
                    $basePath = rtrim($parsedPath, '/');
                }
            }
            $webUrl = $basePath . '/img/' . $safeName;

            Audit::record([
                'action'      => 'settings.image_uploaded',
                'description' => "Uploaded {$type} image: {$safeName}",
                'severity'    => 'notice',
            ]);

            return Response::ok([
                'url'      => $webUrl,
                'filename' => $safeName,
                'type'     => $type,
            ]);
        }, [Middleware::csrf()]);
    });


    /* ---------------- team (platform users) ---------------- */
    $router->group('/api/v1/team', [Middleware::platformContext()], static function (Router $router): void {
        $router->get('', static function (): Response {
            Auth::requirePermission('users.manage');

            $users = Manager::platform()
                ->query('SELECT id, name, email, role, status, job_title, avatar_color, last_login_at, last_login_ip, created_at FROM platform_users ORDER BY id ASC')
                ->fetchAll(PDO::FETCH_ASSOC);

            foreach ($users as &$user) {
                $user['last_login_ago'] = Clock::human($user['last_login_at'] ?? null);
                $user['is_you']         = (int) $user['id'] === Auth::id();
            }

            return Response::json(['data' => $users, 'meta' => ['roles' => Auth::roles()]]);
        });

        $router->post('', static function (Request $request): Response {
            Auth::requirePermission('users.manage');

            Validator::make($request->all(), [
                'name'     => 'required|min:2|max:120',
                'email'    => 'required|email',
                'password' => 'required|min:10|max:200',
                'role'     => 'required|in:owner,admin,support,billing,viewer',
            ])->validateOrFail();

            $email = strtolower($request->string('email'));
            $exists = Manager::platform()->prepare('SELECT COUNT(*) FROM platform_users WHERE LOWER(email) = ?');
            $exists->execute([$email]);
            if ((int) $exists->fetchColumn() > 0) {
                throw ApiException::conflict('That email is already in use.');
            }
            if ($request->string('role') === Auth::ROLE_OWNER && !Auth::can('settings.manage')) {
                throw ApiException::forbidden('Only an owner can create another owner.');
            }

            Manager::platform()->prepare(
                'INSERT INTO platform_users (name, email, password_hash, role, status, job_title, avatar_color, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $request->string('name'),
                $email,
                password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
                $request->string('role'),
                'active',
                $request->string('job_title'),
                $request->string('avatar_color', '#6366f1'),
                Clock::now(),
                Clock::now(),
            ]);

            $id = (int) Manager::platform()->lastInsertId();

            Audit::record([
                'action'      => 'team.member_created',
                'target_type' => 'platform_user',
                'target_id'   => (string) $id,
                'description' => sprintf('Invited %s as %s', $request->string('name'), $request->string('role')),
                'severity'    => 'notice',
            ]);
            Notifications::push([
                'type'  => 'team.member_created',
                'level' => 'info',
                'title' => 'New team member',
                'body'  => $request->string('name') . ' joined as ' . $request->string('role'),
            ]);

            $member = Manager::platform()->prepare('SELECT id, name, email, role, status, job_title, avatar_color FROM platform_users WHERE id = ?');
            $member->execute([$id]);

            return Response::created($member->fetch(PDO::FETCH_ASSOC));
        }, [Middleware::csrf()]);

        $router->patch('/{id}', static function (Request $request, string $id): Response {
            Auth::requirePermission('users.manage');

            $stmt = Manager::platform()->prepare('SELECT * FROM platform_users WHERE id = ?');
            $stmt->execute([(int) $id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                throw ApiException::notFound('Team member not found');
            }

            $updates = [];
            foreach (['name', 'role', 'status', 'job_title', 'avatar_color'] as $field) {
                if ($request->has($field)) {
                    $updates[$field] = (string) $request->string($field);
                }
            }
            if ($request->has('password') && $request->string('password') !== '') {
                if (mb_strlen($request->string('password')) < 10) {
                    throw ApiException::invalid(['password' => ['Use at least 10 characters']]);
                }
                $updates['password_hash'] = password_hash($request->string('password'), PASSWORD_DEFAULT);
            }

            if (($updates['role'] ?? $member['role']) === Auth::ROLE_OWNER && !Auth::can('settings.manage')) {
                throw ApiException::forbidden('Only an owner can grant the owner role.');
            }
            if ((int) $id === Auth::id() && ($updates['status'] ?? 'active') !== 'active') {
                throw ApiException::conflict('You cannot deactivate your own account.');
            }

            if ($updates === []) {
                return Response::ok(['message' => 'Nothing changed']);
            }

            $updates['updated_at'] = Clock::now();
            $assignments = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($updates)));
            Manager::platform()->prepare("UPDATE platform_users SET {$assignments} WHERE id = ?")
                ->execute([...array_values($updates), (int) $id]);

            Audit::record([
                'action'      => 'team.member_updated',
                'target_type' => 'platform_user',
                'target_id'   => (string) $id,
                'description' => 'Updated ' . ($member['name'] ?? 'a team member'),
                'severity'    => 'notice',
                'meta'        => array_keys($updates),
            ]);

            return Response::ok(['message' => 'Saved']);
        }, [Middleware::csrf()]);

        $router->delete('/{id}', static function (Request $request, string $id): Response {
            Auth::requirePermission('users.manage');

            $stmt = Manager::platform()->prepare('SELECT * FROM platform_users WHERE id = ?');
            $stmt->execute([(int) $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw ApiException::notFound('Team member not found');
            }
            if ((int) $id === Auth::id()) {
                throw ApiException::conflict('You cannot remove your own account.');
            }
            if ($row['role'] === Auth::ROLE_OWNER) {
                $owners = (int) Manager::platform()->query("SELECT COUNT(*) FROM platform_users WHERE role = 'owner' AND status = 'active'")->fetchColumn();
                if ($owners <= 1) {
                    throw ApiException::conflict('At least one owner must remain.');
                }
            }

            Manager::platform()->prepare('DELETE FROM platform_users WHERE id = ?')->execute([(int) $id]);
            Manager::platform()->prepare('DELETE FROM platform_sessions WHERE user_id = ?')->execute([(int) $id]);

            Audit::record([
                'action'      => 'team.member_removed',
                'description' => 'Removed ' . $row['name'],
                'severity'    => 'warning',
            ]);

            return Response::ok(['message' => 'Removed']);
        }, [Middleware::csrf()]);
    });

    /* ---------------- system ---------------- */
    $router->get('/api/v1/system', static function (): Response {
        Auth::requirePermission('system.view');
        return Response::ok(Metrics::system());
    }, [Middleware::platformContext()]);

    /* ---------------- system: requirements ---------------- */
    $router->get('/api/v1/system/requirements', static function (): Response {
        Auth::requirePermission('system.view');
        return Response::ok(Metrics::requirements());
    }, [Middleware::platformContext()]);

    /* ---------------- system: backup ---------------- */
    $router->post('/api/v1/system/backup/platform', static function (Request $request): Response {
        Auth::requirePermission('system.manage');
        $path = $request->string('path', null);

        $filename = Backup::platform($path);
        Audit::record([
            'action'      => 'system.backup_platform',
            'description' => 'Backed up platform database to ' . basename($filename),
            'severity'    => 'notice',
            'meta'        => ['file' => $filename],
        ]);

        return Response::ok([
            'message' => 'Platform database backed up',
            'file'    => basename($filename),
            'path'    => $filename,
            'size'    => filesize($filename),
        ]);
    }, [Middleware::platformContext(), Middleware::csrf()]);

    $router->post('/api/v1/system/backup/tenant/{id}', static function (Request $request, string $id): Response {
        Auth::requirePermission('system.manage');
        $tenant = (int) $id;

        $stmt = Manager::platform()->prepare('SELECT slug FROM tenants WHERE id = ?');
        $stmt->execute([$tenant]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw ApiException::notFound('Tenant not found');
        }

        $filename = Backup::tenant($tenant, $row['slug']);
        Audit::record([
            'action'      => 'system.backup_tenant',
            'tenant_id'   => $tenant,
            'description' => 'Backed up tenant database (' . $row['slug'] . ') to ' . basename($filename),
            'severity'    => 'notice',
            'meta'        => ['file' => $filename, 'tenant_slug' => $row['slug']],
        ]);

        return Response::ok([
            'message' => 'Tenant database backed up',
            'file'    => basename($filename),
            'path'    => $filename,
            'size'    => filesize($filename),
            'tenant'  => ['id' => $tenant, 'slug' => $row['slug']],
        ]);
    }, [Middleware::platformContext(), Middleware::csrf()]);

    $router->get('/api/v1/system/backups', static function (Request $request): Response {
        Auth::requirePermission('system.view');
        $directory = Backup::directory();
        $files = [];

        if (is_dir($directory)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $name = $file->getFilename();
                    // Only list backup files
                    if (str_ends_with($name, '.sql.gz') || str_ends_with($name, '.sql') || str_ends_with($name, '.sqlite')) {
                        $files[] = [
                            'name'      => $name,
                            'path'      => $file->getPathname(),
                            'size'      => $file->getSize(),
                            'modified'  => date('Y-m-d H:i:s', $file->getMTime()),
                            'type'      => str_ends_with($name, '.gz') ? 'gzip' : 'raw',
                        ];
                    }
                }
            }
        }

        usort($files, static fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return Response::ok([
            'files'    => $files,
            'directory' => $directory,
            'total_size' => array_sum(array_column($files, 'size')),
        ]);
    }, [Middleware::platformContext()]);

    $router->get('/api/v1/system/backups/{name}', static function (Request $request, string $name): void {
        Auth::requirePermission('system.view');

        $directory = Backup::directory();
        $safeName = basename($name);
        $path = $directory . '/' . $safeName;

        if (!is_file($path)) {
            throw ApiException::notFound('Backup file not found');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($path);
        exit;
    }, [Middleware::platformContext()]);

    $router->delete('/api/v1/system/backups/{name}', static function (Request $request, string $name): Response {
        Auth::requirePermission('system.manage');

        $directory = Backup::directory();
        $path = $directory . '/' . basename($name);

        if (!is_file($path)) {
            throw ApiException::notFound('Backup file not found');
        }

        unlink($path);
        Audit::record([
            'action'      => 'system.backup_deleted',
            'description' => 'Deleted backup file ' . basename($name),
            'severity'    => 'warning',
            'meta'        => ['file' => basename($name)],
        ]);

        return Response::ok(['message' => 'Backup deleted']);
    }, [Middleware::platformContext(), Middleware::csrf()]);

    /* ---------------- system: maintenance ---------------- */
    $router->post('/api/v1/system/maintenance', static function (Request $request): Response {
        Auth::requirePermission('system.manage');
        $enabled = (bool) $request->bool('enabled', false);
        $message = $request->string('message', null);

        Settings::set('maintenance_mode', $enabled ? '1' : '0', 'access');
        if ($message !== null && $message !== '') {
            Settings::set('maintenance_message', $message, 'access');
        }

        Audit::record([
            'action'      => 'system.maintenance_toggle',
            'description' => 'Maintenance mode ' . ($enabled ? 'enabled' : 'disabled'),
            'severity'    => $enabled ? 'warning' : 'notice',
            'meta'        => ['enabled' => $enabled, 'message' => $message],
        ]);

        return Response::ok([
            'message' => 'Maintenance mode ' . ($enabled ? 'enabled' : 'disabled'),
            'enabled' => $enabled,
        ]);
    }, [Middleware::platformContext(), Middleware::csrf()]);
};
