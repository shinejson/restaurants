<?php
/**
 * Plans, subscriptions, invoices and usage metering.
 *
 * GET  /api/v1/plans                     list plans
 * POST /api/v1/plans                     create a plan
 * PATCH /api/v1/plans/{id}               edit
 * POST /api/v1/plans/{id}/archive
 * GET  /api/v1/subscriptions             list every subscription
 * POST /api/v1/subscriptions/{tenant}/activate|change-plan|cancel|resume
 * GET  /api/v1/invoices                  list (filters: status, tenant_id, overdue)
 * POST /api/v1/invoices                  manual invoice
 * POST /api/v1/invoices/{id}/pay|void
 * GET  /api/v1/invoices/{id}
 * GET  /api/v1/billing/summary
 * POST /api/v1/billing/run-dunning
 * GET  /api/v1/usage                     platform-wide usage
 * GET  /api/v1/usage/{tenantId}
 */

use Resto\Billing\Features;
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
use Resto\Tenancy\TenantRepository;

return static function (Router $router): void {

    /* ===================== plans ===================== */
    $router->group('/api/v1/plans', [Middleware::platformContext()], static function (Router $router): void {
        $plans = new PlanRepository();

        $router->get('', static function () use ($plans): Response {
            Auth::requirePermission('plans.view');

            return Response::json([
                'data'   => array_map(static fn ($plan) => $plan->toArray(), $plans->all(true)),
                'catalog' => ['features' => Features::CATALOG, 'limits' => Features::LIMITS],
            ]);
        });

        $router->get('/catalog', static function (): Response {
            Auth::requirePermission('plans.view');
            return Response::ok(['features' => Features::CATALOG, 'limits' => Features::LIMITS]);
        });

        $router->post('', static function (Request $request) use ($plans): Response {
            Auth::requirePermission('plans.manage');

            Validator::make($request->all(), [
                'name'          => 'required|min:2|max:120',
                'code'          => 'required|slug',
                'price_monthly' => 'required|numeric',
            ])->validateOrFail();

            if ($plans->findByCode($request->string('code')) !== null) {
                throw ApiException::conflict('A plan with that code already exists.');
            }

            $plan = $plans->create([
                'code'          => $request->string('code'),
                'name'          => $request->string('name'),
                'tagline'       => $request->string('tagline'),
                'description'   => $request->string('description'),
                'price_monthly' => $request->float('price_monthly'),
                'price_yearly'  => $request->float('price_yearly'),
                'currency'      => strtoupper($request->string('currency', 'USD')),
                'trial_days'    => $request->int('trial_days', 14),
                'is_public'     => $request->bool('is_public', true),
                'badge'         => $request->string('badge') ?: null,
                'accent_color'  => $request->string('accent_color', '#6366f1'),
                'limits'        => (array) $request->input('limits', []),
                'features'      => (array) $request->input('features', []),
                'sort_order'    => $request->int('sort_order', 99),
            ]);

            return Response::created($plan->toArray());
        }, [Middleware::csrf()]);

        $router->patch('/{id}', static function (Request $request, string $id) use ($plans): Response {
            Auth::requirePermission('plans.manage');

            if ($plans->find((int) $id) === null) {
                throw ApiException::notFound('Plan not found');
            }

            $updated = $plans->update((int) $id, $request->all());

            return Response::ok($updated->toArray());
        }, [Middleware::csrf()]);

        $router->post('/{id}/archive', static function (Request $request, string $id) use ($plans): Response {
            Auth::requirePermission('plans.manage');

            if ($plans->find((int) $id) === null) {
                throw ApiException::notFound('Plan not found');
            }
            $plans->archive((int) $id);

            return Response::ok(['message' => 'Plan archived']);
        }, [Middleware::csrf()]);
    });

    /* ===================== subscriptions ===================== */
    $router->group('/api/v1/subscriptions', [Middleware::platformContext()], static function (Router $router): void {
        $subscriptions = new SubscriptionService();

        $router->get('', static function (Request $request): Response {
            Auth::requirePermission('billing.view');

            $where  = [];
            $params = [];

            $status = $request->string('status');
            if ($status !== '' && $status !== 'all') {
                $statuses = explode(',', $status);
                $where[]  = 's.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
                $params   = array_merge($params, $statuses);
            }
            $search = $request->string('search');
            if ($search !== '') {
                $where[] = '(t.name LIKE ? OR t.slug LIKE ?)';
                $term    = '%' . $search . '%';
                $params  = array_merge($params, [$term, $term]);
            }
            if ($request->int('tenant_id')) {
                $where[]  = 's.tenant_id = ?';
                $params[] = $request->int('tenant_id');
            }

            $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $stmt   = Manager::platform()->prepare(
                "SELECT s.*, t.name AS tenant_name, t.slug AS tenant_slug, t.status AS tenant_status,
                        p.name AS plan_name, p.code AS plan_code, p.accent_color AS plan_color
                 FROM subscriptions s
                 JOIN tenants t ON t.id = s.tenant_id
                 LEFT JOIN plans p ON p.id = s.plan_id" . $clause
                . ' ORDER BY s.id DESC LIMIT 200'
            );
            $stmt->execute($params);

            $rows = array_map(static function (array $row): array {
                $row['amount']         = (float) $row['amount'];
                $row['unit_amount']    = (float) $row['unit_amount'];
                $row['quantity']       = (int) $row['quantity'];
                $row['days_remaining'] = \Resto\Support\Clock::daysLeft($row['current_period_end']);
                return $row;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            $byStatus = [];
            foreach (Manager::platform()->query('SELECT status, COUNT(*) AS total FROM subscriptions GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byStatus[$row['status']] = (int) $row['total'];
            }

            return Response::json(['data' => $rows, 'meta' => ['by_status' => $byStatus]]);
        });

        $router->post('/{tenantId}/activate', static function (Request $request, string $tenantId) use ($subscriptions): Response {
            Auth::requirePermission('billing.manage');

            $tenant = (new TenantRepository())->find((int) $tenantId);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $plan = (new PlanRepository())->find($request->int('plan_id') ?: (int) $tenant->get('plan_id'));
            if ($plan === null) {
                throw ApiException::invalid(['plan_id' => ['Choose a plan']]);
            }

            $cycle = $request->string('billing_cycle', (string) $tenant->get('billing_cycle', 'monthly'));
            if (!in_array($cycle, ['monthly', 'yearly'], true)) {
                throw ApiException::invalid(['billing_cycle' => ['Must be monthly or yearly']]);
            }

            $subscription = $subscriptions->activate($tenant, $plan, $cycle, $request->bool('invoice', true));

            Audit::record([
                'action'      => 'subscription.activated',
                'tenant_id'   => $tenant->id(),
                'description' => sprintf('Activated %s on the %s plan (%s)', $tenant->name(), $plan->name(), $cycle),
                'severity'    => 'notice',
                'meta'        => ['amount' => $subscription['amount'] ?? null, 'cycle' => $cycle],
            ]);

            return Response::ok([
                'subscription' => $subscription,
                'tenant'       => (new TenantRepository())->find($tenant->id(), true)->toArray(),
            ]);
        }, [Middleware::csrf()]);

        $router->post('/{tenantId}/change-plan', static function (Request $request, string $tenantId) use ($subscriptions): Response {
            Auth::requirePermission('billing.manage');

            $tenant = (new TenantRepository())->find((int) $tenantId);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $plan = (new PlanRepository())->find($request->int('plan_id'));
            if ($plan === null) {
                throw ApiException::invalid(['plan_id' => ['Choose a plan']]);
            }

            $subscription = $subscriptions->changePlan($tenant, $plan, $request->string('billing_cycle') ?: null);

            Audit::record([
                'action'      => 'subscription.plan_changed',
                'tenant_id'   => $tenant->id(),
                'description' => sprintf('%s moved to the %s plan', $tenant->name(), $plan->name()),
                'severity'    => 'notice',
            ]);

            return Response::ok(['subscription' => $subscription]);
        }, [Middleware::csrf()]);

        $router->post('/{tenantId}/cancel', static function (Request $request, string $tenantId) use ($subscriptions): Response {
            Auth::requirePermission('billing.manage');

            $tenant = (new TenantRepository())->find((int) $tenantId);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $subscriptions->cancel($tenant, $request->bool('immediately'), $request->string('reason'));

            Audit::record([
                'action'      => 'subscription.cancelled',
                'tenant_id'   => $tenant->id(),
                'description' => sprintf('Cancelled the subscription of %s', $tenant->name()),
                'severity'    => 'warning',
                'meta'        => ['immediately' => $request->bool('immediately'), 'reason' => $request->string('reason')],
            ]);

            return Response::ok(['message' => 'Subscription cancelled']);
        }, [Middleware::csrf()]);

        $router->post('/{tenantId}/resume', static function (Request $request, string $tenantId) use ($subscriptions): Response {
            Auth::requirePermission('billing.manage');

            $tenant = (new TenantRepository())->find((int) $tenantId);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $subscriptions->resume($tenant);

            Audit::record([
                'action'      => 'subscription.resumed',
                'tenant_id'   => $tenant->id(),
                'description' => sprintf('Resumed the subscription of %s', $tenant->name()),
                'severity'    => 'notice',
            ]);

            return Response::ok(['message' => 'Subscription resumed']);
        }, [Middleware::csrf()]);

        $router->post('/{tenantId}/prorate', static function (Request $request, string $tenantId) use ($subscriptions): Response {
            Auth::requirePermission('billing.view');

            $tenant = (new TenantRepository())->find((int) $tenantId);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            $plan  = (new PlanRepository())->find($request->int('plan_id'));
            $cycle = $request->string('billing_cycle', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
            if ($plan === null) {
                throw ApiException::invalid(['plan_id' => ['Choose a plan']]);
            }

            $current = $subscriptions->forTenant($tenant->id());
            if ($current === null) {
                throw ApiException::conflict('This tenant has no subscription to prorate.');
            }

            return Response::ok($subscriptions->prorate($current, $plan, $cycle));
        });
    });

    /* ===================== invoices ===================== */
    $router->group('/api/v1/invoices', [Middleware::platformContext()], static function (Router $router): void {
        $invoices = new InvoiceService();

        $router->get('', static function (Request $request) use ($invoices): Response {
            Auth::requirePermission('billing.view');

            $status = $request->string('status');
            $result = $invoices->paginate([
                'status'    => $status !== '' && $status !== 'all' ? explode(',', $status) : null,
                'tenant_id' => $request->int('tenant_id') ?: null,
                'search'    => $request->string('search'),
                'overdue'   => $request->bool('overdue'),
            ], min(100, max(5, $request->int('per_page', 25))), max(1, $request->int('page', 1)));

            return Response::json([
                'data' => $result['data'],
                'meta' => $result['meta'] + ['summary' => $invoices->summary()],
            ]);
        });

        $router->get('/{id}', static function (Request $request, string $id) use ($invoices): Response {
            Auth::requirePermission('billing.view');

            $invoice = $invoices->find((int) $id);
            if ($invoice === null) {
                throw ApiException::notFound('Invoice not found');
            }

            return Response::ok($invoices->present($invoice));
        });

        $router->post('', static function (Request $request) use ($invoices): Response {
            Auth::requirePermission('billing.manage');

            $tenant = (new TenantRepository())->find($request->int('tenant_id'));
            if ($tenant === null) {
                throw ApiException::invalid(['tenant_id' => ['Choose a tenant']]);
            }

            $lines = (array) $request->input('lines', []);
            if ($lines === []) {
                throw ApiException::invalid(['lines' => ['Add at least one line item']]);
            }

            $invoice = $invoices->createManual($tenant, $lines, [
                'currency' => $request->string('currency') ?: null,
                'discount' => $request->float('discount'),
                'notes'    => $request->string('notes'),
            ]);

            Audit::record([
                'action'      => 'invoice.created',
                'tenant_id'   => $tenant->id(),
                'description' => sprintf('Issued invoice %s to %s', $invoice['number'] ?? '?', $tenant->name()),
                'severity'    => 'notice',
                'meta'        => ['total' => $invoice['total'] ?? null],
            ]);

            return Response::created($invoice);
        }, [Middleware::csrf()]);

        $router->post('/{id}/pay', static function (Request $request, string $id) use ($invoices): Response {
            Auth::requirePermission('billing.manage');

            $invoice = $invoices->find((int) $id);
            if ($invoice === null) {
                throw ApiException::notFound('Invoice not found');
            }

            $amount = $request->has('amount') ? $request->float('amount') : round((float) $invoice['total'] - (float) $invoice['amount_paid'], 2);
            $paid   = $invoices->markPaid((int) $id, $amount, $request->string('method', 'manual'));

            Audit::record([
                'action'      => 'invoice.paid',
                'tenant_id'   => (int) $invoice['tenant_id'],
                'description' => sprintf('Recorded a payment of %s on invoice %s', number_format($amount, 2), $invoice['number']),
                'severity'    => 'notice',
            ]);

            return Response::ok($paid);
        }, [Middleware::csrf()]);

        $router->post('/{id}/void', static function (Request $request, string $id) use ($invoices): Response {
            Auth::requirePermission('billing.manage');

            $invoice = $invoices->find((int) $id);
            if ($invoice === null) {
                throw ApiException::notFound('Invoice not found');
            }

            Audit::record([
                'action'      => 'invoice.voided',
                'tenant_id'   => (int) $invoice['tenant_id'],
                'description' => 'Voided invoice ' . $invoice['number'],
                'severity'    => 'warning',
            ]);

            return Response::ok($invoices->void((int) $id, $request->string('reason')));
        }, [Middleware::csrf()]);
    });

    /* ===================== billing overview ===================== */
    $router->group('/api/v1/billing', [Middleware::platformContext()], static function (Router $router): void {
        $router->get('/summary', static function (): Response {
            Auth::requirePermission('billing.view');

            $invoices = new InvoiceService();
            $overview = Metrics::overview();

            return Response::ok([
                'invoices' => $invoices->summary(),
                'revenue'  => $overview['revenue'],
                'risk'     => $overview['risk'],
                'series'   => Metrics::series(12),
                'mrr'      => Metrics::mrr(),
            ]);
        });

        $router->post('/run-dunning', static function (): Response {
            Auth::requirePermission('billing.manage');

            $result = (new SubscriptionService())->runDunning();

            Audit::record([
                'action'      => 'billing.dunning_run',
                'description' => 'Ran the dunning cycle',
                'severity'    => 'notice',
                'meta'        => $result,
            ]);

            return Response::ok($result);
        }, [Middleware::csrf()]);
    });

    /* ===================== usage ===================== */
    $router->group('/api/v1/usage', [Middleware::platformContext()], static function (Router $router): void {
        $router->get('', static function (): Response {
            Auth::requirePermission('usage.view');

            $rows   = UsageService::platformSummary();
            $totals = ['orders' => 0, 'api_calls' => 0, 'emails' => 0];
            foreach ($rows as $row) {
                $totals['orders']    += (int) ($row['orders'] ?? 0);
                $totals['api_calls'] += (int) ($row['api_calls'] ?? 0);
                $totals['emails']    += (int) ($row['emails'] ?? 0);
            }

            return Response::json([
                'data' => $rows,
                'meta' => ['period' => gmdate('Y-m'), 'totals' => $totals, 'tenants' => count($rows), 'metrics' => Features::LIMITS],
            ]);
        });

        $router->get('/{tenantId}', static function (Request $request, string $tenantId): Response {
            Auth::requirePermission('usage.view');

            $tenant = (new TenantRepository())->find((int) $tenantId);
            if ($tenant === null) {
                throw ApiException::notFound('Tenant not found');
            }

            return Response::ok(UsageService::summary($tenant));
        });
    });
};
