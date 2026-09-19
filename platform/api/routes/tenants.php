<?php
/**
 * Tenant management — the heart of the superadmin console.
 *
 * GET    /api/v1/tenants                 list (search, filter, paginate)
 * POST   /api/v1/tenants                 create + provision a restaurant
 * GET    /api/v1/tenants/{id}            full profile
 * PATCH  /api/v1/tenants/{id}            update profile
 * DELETE /api/v1/tenants/{id}            destroy (guarded by slug confirmation)
 * POST   /api/v1/tenants/{id}/status     activate | suspend | cancel | trial
 * POST   /api/v1/tenants/{id}/extend-trial
 * POST   /api/v1/tenants/{id}/features   per-tenant feature/limit override
 * POST   /api/v1/tenants/{id}/impersonate
 * POST   /api/v1/tenants/{id}/notes      support notes
 * GET    /api/v1/tenants/{id}/users      staff + customers in the tenant DB
 * GET    /api/v1/tenants/{id}/stats      live row counts + usage
 */

use Resto\Billing\InvoiceService;
use Resto\Billing\PlanRepository;
use Resto\Billing\SubscriptionService;
use Resto\Billing\UsageService;
use Resto\Database\Manager;
use Resto\Http\ApiException;
use Resto\Http\Middleware;
use Resto\Http\Request;
use Resto\Http\Response;
use Resto\Http\Router;
use Resto\Http\Validator;
use Resto\Platform\Audit;
use Resto\Platform\Auth;
use Resto\Platform\Metrics;
use Resto\Platform\Notifications;
use Resto\Support\Clock;
use Resto\Support\Config;
use Resto\Support\Str;
use Resto\Tenancy\FeatureGate;
use Resto\Tenancy\Gatekeeper;
use Resto\Tenancy\Links;
use Resto\Tenancy\Provisioner;
use Resto\Tenancy\Tenant;
use Resto\Tenancy\TenantRepository;

/* -------------------------------------------------------------------------
 * Presenters
 * ---------------------------------------------------------------------- */

/** @return array<string,mixed> */
function resto_present_tenant(Tenant $tenant, bool $withUsage = false): array
{
    $gate    = new FeatureGate($tenant);
    $plan    = $gate->plan();
    $links   = [
        'storefront' => Links::storefront($tenant, ''),
        'admin'      => Links::admin($tenant),
        'billing'    => Links::billing($tenant),
    ];

    $data = [
        'id'              => $tenant->id(),
        'uuid'            => $tenant->get('uuid'),
        'name'            => $tenant->name(),
        'slug'            => $tenant->slug(),
        'legal_name'      => $tenant->get('legal_name'),
        'owner_name'      => $tenant->get('owner_name'),
        'owner_email'     => $tenant->get('owner_email'),
        'owner_phone'     => $tenant->get('owner_phone'),
        'city'            => $tenant->get('city'),
        'country'         => $tenant->get('country'),
        'timezone'        => $tenant->get('timezone'),
        'currency'        => $tenant->get('currency'),
        'status'          => $tenant->status(),
        'status_label'    => ucfirst(str_replace('_', ' ', $tenant->status())),
        'plan_id'         => (int) $tenant->get('plan_id'),
        'plan'            => $plan ? [
            'id'            => $plan->id(),
            'code'          => $plan->code(),
            'name'          => $plan->name(),
            'price_monthly' => (float) $plan->priceFor('monthly'),
            'price_yearly'  => (float) $plan->priceFor('yearly'),
            'accent_color'  => $plan->accent_color,
            'badge'         => $plan->badge,
        ] : null,
        'billing_cycle'   => $tenant->get('billing_cycle'),
        'seat_count'      => (int) $tenant->get('seat_count'),
        'mrr'             => (float) $tenant->get('mrr'),
        'health_score'    => (int) $tenant->get('health_score'),
        'trial_ends_at'   => $tenant->get('trial_ends_at'),
        'trial_days_left' => $tenant->trialDaysLeft(),
        'on_trial'        => $tenant->onTrial(),
        'suspended_at'    => $tenant->get('suspended_at'),
        'suspended_reason' => $tenant->get('suspended_reason'),
        'cancelled_at'    => $tenant->get('cancelled_at'),
        'last_activity_at' => $tenant->get('last_activity_at'),
        'last_activity_ago' => Clock::human($tenant->get('last_activity_at')),
        'created_at'      => $tenant->get('created_at'),
        'age_days'        => Clock::daysSince($tenant->get('created_at')),
        'subdomain'       => $tenant->get('subdomain'),
        'custom_domain'   => $tenant->get('custom_domain'),
        'db_name'         => $tenant->dbName(),
        'notes'           => $tenant->get('notes'),
        'metadata'        => $tenant->metadata(),
        'features'        => $gate->allowsAll(),
        'limits'          => $gate->limitMap(),
        'links'           => $links,
    ];

    if ($withUsage) {
        $data['usage']    = UsageService::summary($tenant);
        $data['snapshot'] = Metrics::tenantSnapshot($tenant->id());
    }

    return $data;
}

/** @return array<int,array> */
function resto_tenant_staff(Tenant $tenant): array
{
    $conn = $tenant->connection();
    try {
        return $conn->query(
            "SELECT a.id, a.username, a.email, a.full_name, a.role, a.is_active, a.last_login_at, a.created_at,
                    r.name AS role_name, r.color AS role_color
             FROM admins a LEFT JOIN roles r ON r.slug = a.role
             ORDER BY a.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string,int> */
function resto_tenant_counts(Tenant $tenant): array
{
    $conn   = $tenant->connection();
    $counts = [];
    foreach ([
        'orders', 'order_items', 'food_items', 'customers', 'restaurant_tables',
        'events', 'event_bookings', 'roles', 'printers', 'terminals', 'delivery_zones',
    ] as $table) {
        try {
            $counts[$table] = (int) $conn->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        } catch (Throwable $e) {
            $counts[$table] = 0;
        }
    }
    try {
        $recent = $conn->prepare('SELECT COUNT(*) FROM orders WHERE created_at >= ?');
        $recent->execute([Clock::addDays(Clock::now(), -30)]);
        $counts['orders_30d'] = (int) $recent->fetchColumn();
    } catch (Throwable $e) {
        $counts['orders_30d'] = 0;
    }
    $counts['db_size_bytes'] = Manager::tenantDatabaseSize($tenant->dbName());

    return $counts;
}

/* -------------------------------------------------------------------------
 * Routes
 * ---------------------------------------------------------------------- */

return static function (Router $router): void {

    $router->group('/api/v1/tenants', [Middleware::platformContext()], static function (Router $router): void {

        /* ---------------- list ---------------- */
        $router->get('', static function (Request $request): Response {
            Auth::requirePermission('tenants.view');

            $repo   = new TenantRepository();
            $status = $request->string('status');
            $filters = [
                'status'   => $status !== '' && $status !== 'all' ? explode(',', $status) : null,
                'plan_id'  => $request->int('plan_id') ?: null,
                'cycle'    => $request->string('cycle') ?: null,
                'search'   => $request->string('search'),
            ];

            $page  = max(1, $request->int('page', 1));
            $result = $repo->paginate(
                array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                min(100, max(5, $request->int('per_page', 25))),
                $page,
                in_array($request->string('sort'), ['created_at', 'name', 'mrr', 'last_activity_at', 'status'], true) ? $request->string('sort') : 'created_at',
                strtoupper($request->string('direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC'
            );

            $counts = [];
            foreach (Manager::platform()->query('SELECT status, COUNT(*) AS total FROM tenants GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['status']] = (int) $row['total'];
            }

            return Response::json([
                'data' => array_map(static fn (Tenant $t) => resto_present_tenant($t), $result['data']),
                'meta' => $result['meta'] + ['counts' => $counts, 'total_all' => array_sum($counts)],
            ]);
        });

        /* ---------------- create + provision ---------------- */
        $router->post('', static function (Request $request): Response {
            Auth::requirePermission('tenants.create');

            Validator::make($request->all(), [
                'name'        => 'required|min:2|max:120',
                'owner_name'  => 'required|min:2|max:120',
                'owner_email' => 'required|email',
                'plan_id'     => 'required|numeric',
                'currency'    => 'currency',
                'city'        => 'max:80',
                'country'     => 'max:80',
            ])->validateOrFail();

            $repo  = new TenantRepository();
            $email = strtolower($request->string('owner_email'));
            if ($repo->emailExists($email)) {
                throw ApiException::conflict('A tenant with that owner email already exists.');
            }

            $plans = new PlanRepository();
            $plan  = $plans->find($request->int('plan_id')) ?? $plans->default();
            if ($plan === null) {
                throw ApiException::invalid(['plan_id' => ['Choose a plan']]);
            }

            $slugInput = $request->string('slug');
            $slug = $slugInput !== '' ? Str::slug($slugInput, 48) : Str::uniqueSlug($request->string('name'), static fn (string $candidate) => $repo->slugExists($candidate));

            $seatCount = max(1, $request->int('seat_count', 5));
            $cycle     = $request->string('billing_cycle', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
            $trialDays = min(90, max(0, $request->int('trial_days', $plan->trialDays())));

            $tenant = $repo->create([
                'name'          => $request->string('name'),
                'slug'          => $slug,
                'legal_name'    => $request->string('legal_name') ?: null,
                'owner_name'    => $request->string('owner_name'),
                'owner_email'   => $email,
                'owner_phone'   => $request->string('owner_phone') ?: null,
                'city'          => $request->string('city') ?: null,
                'country'       => $request->string('country') ?: null,
                'timezone'      => $request->string('timezone', 'UTC'),
                'currency'      => strtoupper($request->string('currency', 'USD')),
                'status'        => $trialDays > 0 ? 'trial' : 'active',
                'plan_id'       => $plan->id(),
                'billing_cycle' => $cycle,
                'seat_count'    => $seatCount,
                'trial_ends_at' => $trialDays > 0 ? Clock::addDays(Clock::now(), $trialDays) : null,
                'created_by'    => Auth::id(),
                'metadata'      => $request->has('metadata') ? (array) $request->input('metadata', []) : null,
            ]);

            if (!Config::get('tenancy.auto_provision', true)) {
                return Response::created(resto_present_tenant($tenant), ['provisioned' => false]);
            }

            try {
                $result = Provisioner::provision($tenant, [
                    'demo'       => (bool) $request->bool('seed_demo_data'),
                    'plan_id'    => $plan->id(),
                    'trial_days' => $trialDays,
                ]);
            } catch (Throwable $e) {
                // Never leave a half-built tenant behind.
                try {
                    Provisioner::destroy($tenant, true);
                    $repo->delete($tenant->id());
                } catch (Throwable) {
                }
                error_log('[tenants] provisioning failed: ' . $e->getMessage());
                throw new ApiException('The restaurant database could not be created: ' . $e->getMessage(), 500, [], 'provisioning_failed');
            }

            if ($trialDays === 0) {
                (new SubscriptionService())->activate($result['tenant'], $plan, $cycle, (bool) $request->bool('invoice'));
            }

            Audit::record([
                'action'      => 'tenant.created',
                'tenant_id'   => $tenant->id(),
                'target_type' => 'tenant',
                'target_id'   => (string) $tenant->id(),
                'description' => sprintf('Created %s on the %s plan', $tenant->name(), $plan->name()),
                'severity'    => 'notice',
            ]);
            Notifications::push([
                'tenant_id' => $tenant->id(),
                'type'      => 'tenant.created',
                'level'     => 'success',
                'title'     => $tenant->name() . ' is live',
                'body'      => 'Provisioned on ' . $plan->name() . ' — the owner can sign in straight away.',
            ]);

            return Response::created([
                'tenant'         => resto_present_tenant($result['tenant'], true),
                'owner_password' => $result['owner_password'],
                'admin_username' => $result['admin_username'],
                'login_url'      => Links::admin($result['tenant']),
            ], ['tables' => count($result['tables'])]);
        }, [Middleware::auth('tenants.create'), Middleware::csrf()]);

        /* ---------------- detail ---------------- */
        $router->get('/{id}', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.view');

            $tenant = (new TenantRepository())->find((int) $id, true);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $invoices = (new InvoiceService())->paginate(['tenant_id' => $tenant->id()], 20, 1);

            $notes = Manager::platform()->prepare(
                'SELECT n.*, u.name AS author_avatar FROM support_notes n LEFT JOIN platform_users u ON u.id = n.author_id
                 WHERE n.tenant_id = ? ORDER BY n.is_pinned DESC, n.id DESC LIMIT 50'
            );
            $notes->execute([$tenant->id()]);

            $audit = Audit::recent(15, $tenant->id());

            return Response::ok([
                'tenant'       => resto_present_tenant($tenant, true),
                'subscription' => (new SubscriptionService())->forTenant($tenant->id()),
                'invoices'     => ['data' => $invoices['data'], 'meta' => $invoices['meta']],
                'staff'        => resto_tenant_staff($tenant),
                'notes'        => $notes->fetchAll(PDO::FETCH_ASSOC),
                'activity'     => $audit,
                'counts'       => resto_tenant_counts($tenant),
                'features'     => FeatureGate::catalog(),
                'health'       => Gatekeeper::healthReport($tenant),
            ]);
        });

        /* ---------------- update ---------------- */
        $router->patch('/{id}', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.update');

            $repo   = new TenantRepository();
            $tenant = $repo->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $allowed = ['name', 'legal_name', 'owner_name', 'owner_email', 'owner_phone', 'city', 'country',
                        'timezone', 'currency', 'seat_count', 'billing_cycle', 'plan_id', 'notes',
                        'custom_domain', 'health_score', 'metadata'];

            $changes = [];
            foreach ($allowed as $field) {
                if (!$request->has($field)) {
                    continue;
                }
                $value = $request->input($field);
                if ($field === 'currency') {
                    $value = strtoupper((string) $value);
                }
                if ($field === 'owner_email') {
                    $value = strtolower((string) $value);
                    if ($value !== '' && $value !== $tenant->get('owner_email') && $repo->emailExists($value, $tenant->id())) {
                        throw ApiException::conflict('Another tenant already uses that owner email.');
                    }
                }
                if ($field === 'plan_id') {
                    $plan = (new PlanRepository())->find((int) $value);
                    if ($plan === null) {
                        throw ApiException::invalid(['plan_id' => ['Unknown plan']]);
                    }
                }
                $changes[$field] = $value;
            }

            if ($request->string('name') !== '' && $request->string('name') !== $tenant->name()) {
                // Renaming keeps the slug (links stay stable) but refreshes the label.
                $changes['name'] = $request->string('name');
            }

            if ($changes === []) {
                return Response::ok(resto_present_tenant($tenant));
            }

            $before = $tenant->only(array_keys($changes));
            $updated = $repo->update($tenant->id(), $changes);

            Audit::record([
                'action'      => 'tenant.updated',
                'tenant_id'   => $tenant->id(),
                'target_type' => 'tenant',
                'target_id'   => (string) $tenant->id(),
                'description' => sprintf('Updated %s (%s)', $updated->name(), implode(', ', array_keys($changes))),
                'severity'    => 'notice',
                'meta'        => ['before' => $before, 'after' => $updated->only(array_keys($changes))],
            ]);

            return Response::ok(resto_present_tenant($updated));
        }, [Middleware::csrf()]);

        /* ---------------- lifecycle ---------------- */
        $router->post('/{id}/status', static function (Request $request, string $id): Response {
            $repo   = new TenantRepository();
            $tenant = $repo->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $status = strtolower($request->string('status'));
            if (!in_array($status, ['trial', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
                throw ApiException::invalid(['status' => ['Unknown status']]);
            }

            $permission = in_array($status, ['suspended', 'cancelled'], true) ? 'tenants.suspend' : 'tenants.update';
            Auth::requirePermission($permission);

            $changes = ['status' => $status];
            $reason  = $request->string('reason');

            if ($status === 'suspended') {
                $changes['suspended_at']      = Clock::now();
                $changes['suspended_reason']  = $reason !== '' ? $reason : 'Suspended by platform staff';
            } elseif ($status === 'cancelled') {
                $changes['cancelled_at']     = Clock::now();
                $changes['suspended_reason'] = $reason !== '' ? $reason : null;
            } elseif ($status === 'active') {
                $changes['suspended_at']      = null;
                $changes['suspended_reason']  = null;
                $changes['cancelled_at']      = null;
                $changes['activated_at']      = $tenant->get('activated_at') ?: Clock::now();
            } elseif ($status === 'trial') {
                $changes['trial_ends_at'] = $tenant->get('trial_ends_at') ?: Clock::addDays(Clock::now(), (int) Config::get('tenancy.trial_days', 14));
            }

            $updated = $repo->update($tenant->id(), $changes);

            // Keep the subscription row in step with the tenant lifecycle.
            $subscriptions = new SubscriptionService();
            if ($status === 'cancelled') {
                $subscriptions->cancel($tenant, true, $reason);
            } elseif ($status === 'active') {
                $subscription = $subscriptions->forTenant($tenant->id());
                if ($subscription !== null && in_array($subscription['status'], ['past_due', 'cancelled'], true)) {
                    $subscriptions->resume($tenant);
                }
            }

            Audit::record([
                'action'      => 'tenant.status_changed',
                'tenant_id'   => $tenant->id(),
                'target_type' => 'tenant',
                'target_id'   => (string) $tenant->id(),
                'description' => sprintf('%s marked %s%s', $updated->name(), $status, $reason !== '' ? " — {$reason}" : ''),
                'severity'    => in_array($status, ['suspended', 'cancelled'], true) ? 'warning' : 'notice',
                'meta'        => ['from' => $tenant->status(), 'to' => $status],
            ]);
            Notifications::push([
                'tenant_id' => $tenant->id(),
                'type'      => 'tenant.status_changed',
                'level'     => $status === 'active' ? 'success' : 'warning',
                'title'     => $updated->name() . ' is now ' . $status,
                'body'      => $reason !== '' ? $reason : 'Status changed by ' . (Auth::user()['name'] ?? 'platform staff'),
            ]);

            return Response::ok(resto_present_tenant($updated, true));
        }, [Middleware::csrf()]);

        $router->post('/{id}/extend-trial', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.update');

            $repo   = new TenantRepository();
            $tenant = $repo->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $days = min(180, max(1, $request->int('days', 14)));
            $base = ($tenant->get('trial_ends_at') && (string) $tenant->get('trial_ends_at') > Clock::now())
                ? (string) $tenant->get('trial_ends_at')
                : Clock::now();
            $newEnd = Clock::addDays($base, $days);

            $updated = $repo->update($tenant->id(), [
                'trial_ends_at' => $newEnd,
                'status'        => $tenant->status() === 'cancelled' ? 'cancelled' : 'trial',
            ]);

            Manager::platform()
                ->prepare('UPDATE subscriptions SET trial_end = ?, updated_at = ? WHERE tenant_id = ? AND status = ?')
                ->execute([$newEnd, Clock::now(), $tenant->id(), 'trialing']);

            Audit::record([
                'action'      => 'tenant.trial_extended',
                'tenant_id'   => $tenant->id(),
                'target_type' => 'tenant',
                'target_id'   => (string) $tenant->id(),
                'description' => sprintf('Extended the trial of %s by %d day(s)', $updated->name(), $days),
                'severity'    => 'notice',
                'meta'        => ['days' => $days, 'trial_ends_at' => $newEnd],
            ]);

            return Response::ok(resto_present_tenant($updated));
        }, [Middleware::csrf()]);

        /* ---------------- feature / limit overrides ---------------- */
        $router->post('/{id}/features', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.update');

            $repo   = new TenantRepository();
            $tenant = $repo->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $key = $request->string('feature_key');
            $catalog = FeatureGate::catalog();
            if (!isset($catalog['features'][$key]) && !isset($catalog['limits'][$key])) {
                throw ApiException::invalid(['feature_key' => ['Unknown feature or metric']]);
            }

            $enabled = $request->bool('enabled', true);
            $limit   = $request->has('limit_value') ? (int) $request->int('limit_value') : null;
            $note    = $request->string('note');
            $expires = $request->string('expires_at') ?: null;

            $existing = Manager::platform()->prepare('SELECT id FROM tenant_features WHERE tenant_id = ? AND feature_key = ?');
            $existing->execute([$tenant->id(), $key]);
            $rowId = $existing->fetchColumn();

            if ($rowId) {
                Manager::platform()->prepare(
                    'UPDATE tenant_features SET enabled = ?, limit_value = ?, note = ?, expires_at = ?, updated_by = ?, updated_at = ? WHERE id = ?'
                )->execute([$enabled ? 1 : 0, $limit, $note ?: null, $expires, Auth::id(), Clock::now(), (int) $rowId]);
            } else {
                Manager::platform()->prepare(
                    'INSERT INTO tenant_features (tenant_id, feature_key, enabled, limit_value, note, expires_at, updated_by, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$tenant->id(), $key, $enabled ? 1 : 0, $limit, $note ?: null, $expires, Auth::id(), Clock::now()]);
            }

            Audit::record([
                'action'      => 'tenant.feature_toggled',
                'tenant_id'   => $tenant->id(),
                'target_type' => 'feature',
                'target_id'   => $key,
                'description' => sprintf('%s %s for %s', $enabled ? 'Enabled' : 'Disabled', $key, $tenant->name()),
                'severity'    => 'notice',
                'meta'        => ['enabled' => $enabled, 'limit_value' => $limit, 'note' => $note],
            ]);

            return Response::ok(resto_present_tenant($repo->find($tenant->id(), true)));
        }, [Middleware::csrf()]);

        $router->delete('/{id}/features/{key}', static function (Request $request, string $id, string $key): Response {
            Auth::requirePermission('tenants.update');

            Manager::platform()->prepare('DELETE FROM tenant_features WHERE tenant_id = ? AND feature_key = ?')
                ->execute([(int) $id, $key]);

            Audit::record([
                'action'      => 'tenant.feature_reset',
                'tenant_id'   => (int) $id,
                'description' => sprintf('Cleared the %s override', $key),
                'severity'    => 'notice',
            ]);

            $tenant = (new TenantRepository())->find((int) $id, true);
            return Response::ok($tenant ? resto_present_tenant($tenant) : null);
        }, [Middleware::csrf()]);

        /* ---------------- impersonation ---------------- */
        $router->post('/{id}/impersonate', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.impersonate');

            $tenant = (new TenantRepository())->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }
            if (in_array($tenant->status(), ['suspended', 'cancelled'], true) && !$request->bool('force')) {
                throw ApiException::conflict('This restaurant is ' . $tenant->status() . '. Pass force=true to sign in anyway.');
            }

            $reason = $request->string('reason') ?: 'Support session from the console';
            $token  = Auth::createImpersonationToken($tenant->id(), $reason);

            Notifications::push([
                'tenant_id' => $tenant->id(),
                'type'      => 'tenant.impersonation',
                'level'     => 'warning',
                'title'     => 'Support session started',
                'body'      => (Auth::user()['name'] ?? 'Platform staff') . ' signed in as the owner — ' . $reason,
            ]);

            return Response::ok([
                'url'     => rtrim(Links::origin(), '/') . '/platform/impersonate.php?token=' . urlencode($token),
                'expires' => Clock::addDays(Clock::now(), 1),
                'reason'  => $reason,
            ]);
        }, [Middleware::csrf()]);

        /* ---------------- notes ---------------- */
        $router->post('/{id}/notes', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.view');

            $tenant = (new TenantRepository())->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $body = trim($request->string('body'));
            if ($body === '') {
                throw ApiException::invalid(['body' => ['Write something first']]);
            }

            Manager::platform()->prepare(
                'INSERT INTO support_notes (tenant_id, author_id, author_name, body, is_pinned, created_at) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $tenant->id(),
                Auth::id(),
                Auth::user()['name'] ?? 'Platform staff',
                mb_substr($body, 0, 4000),
                (int) $request->bool('is_pinned'),
                Clock::now(),
            ]);

            Audit::record([
                'action'      => 'tenant.note_added',
                'tenant_id'   => $tenant->id(),
                'description' => 'Added a support note',
                'severity'    => 'info',
            ]);

            $notes = Manager::platform()->prepare(
                'SELECT * FROM support_notes WHERE tenant_id = ? ORDER BY is_pinned DESC, id DESC LIMIT 50'
            );
            $notes->execute([$tenant->id()]);

            return Response::created($notes->fetchAll(PDO::FETCH_ASSOC));
        }, [Middleware::csrf()]);

        /* ---------------- staff / users ---------------- */
        $router->get('/{id}/users', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.view');

            $tenant = (new TenantRepository())->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $conn = $tenant->connection();
            try {
                $customers = (int) $conn->query('SELECT COUNT(*) FROM customers')->fetchColumn();
            } catch (Throwable) {
                $customers = 0;
            }

            return Response::ok([
                'staff'     => resto_tenant_staff($tenant),
                'customers' => $customers,
                'roles'     => $conn->query('SELECT slug, name, color FROM roles ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC),
            ]);
        });

        /* ---------------- stats ---------------- */
        $router->get('/{id}/stats', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.view');

            $tenant = (new TenantRepository())->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $ordersByDay = [];
            try {
                $stmt = $tenant->connection()->prepare(
                    'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS total, COALESCE(SUM(total), 0) AS revenue
                     FROM orders WHERE created_at >= ? GROUP BY day ORDER BY day ASC'
                );
                $stmt->execute([Clock::addDays(Clock::now(), -30)]);
                $ordersByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
            }

            return Response::ok([
                'counts'      => resto_tenant_counts($tenant),
                'usage'       => UsageService::summary($tenant),
                'orders_by_day' => $ordersByDay,
                'snapshot'    => Metrics::tenantSnapshot($tenant->id()),
                'health'      => Gatekeeper::healthReport($tenant),
            ]);
        });

        /* ---------------- destroy ---------------- */
        $router->delete('/{id}', static function (Request $request, string $id): Response {
            Auth::requirePermission('tenants.delete');

            $repo   = new TenantRepository();
            $tenant = $repo->find((int) $id);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            if ($request->string('confirm') !== $tenant->slug()) {
                throw ApiException::invalid([
                    'confirm' => ['Type the restaurant slug (' . $tenant->slug() . ') to confirm deletion'],
                ]);
            }

            $name = $tenant->name();
            Provisioner::destroy($tenant, $request->bool('drop_database', true));
            $repo->delete($tenant->id());

            Audit::record([
                'action'      => 'tenant.deleted',
                'target_type' => 'tenant',
                'target_id'   => (string) $tenant->id(),
                'description' => sprintf('Deleted %s (%s) and dropped its database', $name, $tenant->slug()),
                'severity'    => 'critical',
                'meta'        => ['mrr_lost' => (float) $tenant->get('mrr')],
            ]);

            return Response::ok(['message' => $name . ' has been deleted']);
        }, [Middleware::csrf()]);
    });
};
