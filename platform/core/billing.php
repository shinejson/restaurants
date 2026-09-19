<?php
/**
 * RestaurantOS — plans, subscriptions, invoices, usage metering and features.
 *
 * Every SaaS concern the original application was missing: what a restaurant is
 * paying for, what they are allowed to use, what they have consumed this month,
 * and what happens when a payment fails.
 *
 * @package Resto\Billing
 */

namespace Resto\Billing;

use Resto\Database\Manager;
use Resto\Platform\Audit;
use Resto\Platform\Metrics;
use Resto\Platform\Notifications;
use Resto\Platform\Settings;
use Resto\Support\Clock;
use Resto\Support\Money;
use Resto\Support\Str;
use Resto\Tenancy\Tenant;

/* -------------------------------------------------------------------------
 * Feature catalogue — what a plan can switch on
 * ---------------------------------------------------------------------- */

final class Features
{
    /** Feature key => metadata. Plans enable subsets of these. */
    public const CATALOG = [
        'online_ordering'  => ['label' => 'Online ordering', 'description' => 'Customer-facing ordering and checkout', 'group' => 'Sales'],
        'pos'              => ['label' => 'Point of sale', 'description' => 'POS, kiosk and counter ordering', 'group' => 'Sales'],
        'table_service'    => ['label' => 'Table service', 'description' => 'Table map, seats, QR codes and cleaning log', 'group' => 'Sales'],
        'delivery'         => ['label' => 'Delivery & zones', 'description' => 'Delivery zones and delivery fees', 'group' => 'Sales'],
        'events'           => ['label' => 'Events & bookings', 'description' => 'Event packages, bookings and enquiries', 'group' => 'Growth'],
        'reports'          => ['label' => 'Reports & analytics', 'description' => 'Sales, product and customer reporting', 'group' => 'Growth'],
        'customers_crm'    => ['label' => 'Customer accounts', 'description' => 'Customer sign-up, profiles and history', 'group' => 'Growth'],
        'printing'         => ['label' => 'Printing & terminals', 'description' => 'Receipt/KOT printers and POS terminals', 'group' => 'Operations'],
        'tax_engine'       => ['label' => 'Tax engine', 'description' => 'Tax groups, tax items and inclusive pricing', 'group' => 'Operations'],
        'multi_location'   => ['label' => 'Multiple locations', 'description' => 'Run more than one restaurant site on one account', 'group' => 'Operations'],
        'staff_roles'      => ['label' => 'Staff & roles', 'description' => 'Role based access control for staff', 'group' => 'Operations'],
        'api_access'       => ['label' => 'API access', 'description' => 'Programmatic access to orders and menu', 'group' => 'Platform'],
        'custom_domain'    => ['label' => 'Custom domain', 'description' => 'Serve the storefront on your own domain', 'group' => 'Platform'],
        'white_label'      => ['label' => 'White label', 'description' => 'Remove RestaurantOS branding from receipts and pages', 'group' => 'Platform'],
        'priority_support' => ['label' => 'Priority support', 'description' => 'Same-day support response target', 'group' => 'Platform'],
    ];

    /** Metric key => metadata for metered limits. */
    public const LIMITS = [
        'staff_users'   => ['label' => 'Staff accounts', 'unit' => 'users', 'hard' => true],
        'menu_items'    => ['label' => 'Menu items', 'unit' => 'items', 'hard' => true],
        'orders'        => ['label' => 'Orders / month', 'unit' => 'orders', 'hard' => false],
        'tables'        => ['label' => 'Tables', 'unit' => 'tables', 'hard' => true],
        'locations'     => ['label' => 'Locations', 'unit' => 'sites', 'hard' => false],
        'storage_mb'    => ['label' => 'Storage', 'unit' => 'MB', 'hard' => false],
        'api_calls'     => ['label' => 'API calls / day', 'unit' => 'calls', 'hard' => false],
        'email_sends'   => ['label' => 'Emails / month', 'unit' => 'emails', 'hard' => false],
    ];

    public static function catalog(): array
    {
        $out = [];
        foreach (self::CATALOG as $key => $meta) {
            $out[] = ['key' => $key] + $meta;
        }
        return $out;
    }
}

/* -------------------------------------------------------------------------
 * Plan
 * ---------------------------------------------------------------------- */

final class Plan
{
    public function __construct(private array $attributes)
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

    public function id(): int
    {
        return (int) $this->attributes['id'];
    }

    public function code(): string
    {
        return (string) $this->attributes['code'];
    }

    public function name(): string
    {
        return (string) $this->attributes['name'];
    }

    public function priceFor(string $cycle): float
    {
        return (float) ($cycle === 'yearly' ? $this->attributes['price_yearly'] : $this->attributes['price_monthly']);
    }

    public function monthlyEquivalent(string $cycle): float
    {
        $price = $this->priceFor($cycle);
        return $cycle === 'yearly' ? round($price / 12, 2) : $price;
    }

    public function currency(): string
    {
        return (string) ($this->attributes['currency'] ?? 'USD');
    }

    public function trialDays(): int
    {
        return (int) ($this->attributes['trial_days'] ?? (int) Settings::get('default_trial_days', '14'));
    }

    /** @return array<string,bool> */
    public function features(): array
    {
        $raw = $this->attributes['features'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,int|string|null> limit => value (null/-1 = unlimited) */
    public function limits(): array
    {
        $raw = $this->attributes['limits'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    public function limit(string $metric): int|string|null
    {
        return $this->limits()[$metric] ?? null;
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) ($this->features()[$feature] ?? false);
    }

    public function isFree(): bool
    {
        return (float) $this->attributes['price_monthly'] <= 0 && (float) $this->attributes['price_yearly'] <= 0;
    }

    public function toArray(): array
    {
        $data = $this->attributes;
        $data['features'] = $this->features();
        $data['limits']   = $this->limits();
        return $data;
    }
}

final class PlanRepository
{
    public function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM plans';
        if (!$includeArchived) {
            $sql .= ' WHERE is_archived = 0';
        }
        $sql .= ' ORDER BY sort_order ASC, price_monthly ASC';

        return array_map([Plan::class, 'fromRow'], Manager::platform()->query($sql)->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function find(int $id): ?Plan
    {
        $stmt = Manager::platform()->prepare('SELECT * FROM plans WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Plan::fromRow($row) : null;
    }

    public function findByCode(string $code): ?Plan
    {
        $stmt = Manager::platform()->prepare('SELECT * FROM plans WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Plan::fromRow($row) : null;
    }

    public function default(): ?Plan
    {
        $code = (string) Settings::get('default_plan', 'growth');
        return $this->findByCode($code) ?? ($this->all()[0] ?? null);
    }

    public function create(array $data): Plan
    {
        $pdo = Manager::platform();
        $pdo->prepare(
            'INSERT INTO plans (code, name, tagline, description, price_monthly, price_yearly, currency, trial_days,
                                is_public, badge, accent_color, limits, features, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            Str::slug((string) $data['code']),
            $data['name'],
            $data['tagline'] ?? null,
            $data['description'] ?? null,
            (float) ($data['price_monthly'] ?? 0),
            (float) ($data['price_yearly'] ?? 0),
            strtoupper((string) ($data['currency'] ?? 'USD')),
            (int) ($data['trial_days'] ?? 14),
            !empty($data['is_public']) ? 1 : 0,
            $data['badge'] ?? null,
            $data['accent_color'] ?? '#6366f1',
            json_encode($data['limits'] ?? []),
            json_encode($data['features'] ?? []),
            (int) ($data['sort_order'] ?? 0),
            Clock::now(),
            Clock::now(),
        ]);

        Audit::record([
            'action'      => 'plan.created',
            'target_type' => 'plan',
            'description' => 'Created plan ' . $data['name'],
            'severity'    => 'notice',
        ]);

        return $this->find((int) $pdo->lastInsertId());
    }

    public function update(int $id, array $data): Plan
    {
        $allowed = ['name', 'tagline', 'description', 'price_monthly', 'price_yearly', 'currency', 'trial_days', 'is_public', 'is_archived', 'badge', 'accent_color', 'sort_order', 'limits', 'features'];
        $sets    = [];
        $values  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['limits', 'features'], true) && is_array($value)) {
                $value = json_encode($value);
            }
            if (in_array($field, ['is_public', 'is_archived'], true)) {
                $value = !empty($value) ? 1 : 0;
            }
            $sets[]   = "$field = ?";
            $values[] = $value;
        }

        if ($sets !== []) {
            $values[] = Clock::now();
            $values[] = $id;
            Manager::platform()->prepare('UPDATE plans SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id = ?')->execute($values);
        }

        Audit::record([
            'action'      => 'plan.updated',
            'target_type' => 'plan',
            'target_id'   => (string) $id,
            'description' => 'Updated plan #' . $id,
            'severity'    => 'notice',
            'meta'        => $data,
        ]);

        return $this->find($id);
    }

    /** Never delete a plan that tenants are on — archive it instead. */
    public function archive(int $id): void
    {
        Manager::platform()->prepare('UPDATE plans SET is_archived = 1, updated_at = ? WHERE id = ?')->execute([Clock::now(), $id]);
        Audit::record([
            'action'      => 'plan.archived',
            'target_type' => 'plan',
            'target_id'   => (string) $id,
            'description' => 'Archived plan #' . $id,
            'severity'    => 'warning',
        ]);
    }
}

/* -------------------------------------------------------------------------
 * Subscription
 * ---------------------------------------------------------------------- */

final class SubscriptionService
{
    public function forTenant(int $tenantId): ?array
    {
        $stmt = Manager::platform()->prepare(
            'SELECT s.*, p.name AS plan_name, p.code AS plan_code, p.features AS plan_features, p.limits AS plan_limits
             FROM subscriptions s LEFT JOIN plans p ON p.id = s.plan_id
             WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Start the trial that every new tenant gets. */
    public function startTrial(Tenant $tenant, Plan $plan, int $trialDays): array
    {
        $pdo   = Manager::platform();
        $start = Clock::now();
        $end   = Clock::addDays($start, max(1, $trialDays));

        $pdo->prepare(
            'INSERT INTO subscriptions (tenant_id, plan_id, status, billing_cycle, quantity, unit_amount, amount, currency,
                                        trial_start, trial_end, current_period_start, current_period_end, started_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tenant->id(),
            $plan->id(),
            'trialing',
            $tenant->get('billing_cycle', 'monthly'),
            1,
            $plan->priceFor((string) $tenant->get('billing_cycle', 'monthly')),
            $plan->priceFor((string) $tenant->get('billing_cycle', 'monthly')),
            $plan->currency(),
            $start,
            $end,
            $start,
            $end,
            $start,
            $start,
            $start,
        ]);

        return $this->forTenant($tenant->id()) ?? [];
    }

    /** Activate (or extend) a paid subscription and invoice for it. */
    public function activate(Tenant $tenant, Plan $plan, string $cycle, bool $invoice = true, array $options = []): array
    {
        $pdo    = Manager::platform();
        $amount = $plan->priceFor($cycle);
        $start  = $options['period_start'] ?? Clock::now();
        $end    = $cycle === 'yearly' ? Clock::addMonths($start, 12) : Clock::addMonths($start, 1);

        $existing = $this->forTenant($tenant->id());

        if ($existing) {
            $pdo->prepare(
                'UPDATE subscriptions SET plan_id = ?, status = ?, billing_cycle = ?, unit_amount = ?, amount = ?, currency = ?,
                        current_period_start = ?, current_period_end = ?, started_at = COALESCE(started_at, ?),
                        cancel_at_period_end = 0, cancelled_at = NULL, ended_at = NULL, updated_at = ?
                 WHERE id = ?'
            )->execute([
                $plan->id(), 'active', $cycle, $amount, $amount, $plan->currency(),
                $start, $end, $start, Clock::now(), $existing['id'],
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO subscriptions (plan_id, status, billing_cycle, unit_amount, amount, currency,
                                            current_period_start, current_period_end, started_at, created_at, updated_at,
                                            tenant_id, quantity)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                $plan->id(), 'active', $cycle, $amount, $amount, $plan->currency(),
                $start, $end, $start, Clock::now(), Clock::now(), $tenant->id(),
            ]);
        }

        // Keep the tenant record in sync.
        (new \Resto\Tenancy\TenantRepository())->update($tenant->id(), [
            'plan_id'       => $plan->id(),
            'status'        => 'active',
            'billing_cycle' => $cycle,
            'mrr'           => $cycle === 'yearly' ? round($amount / 12, 2) : $amount,
            'activated_at'  => $tenant->get('activated_at') ?: Clock::now(),
        ]);

        if ($invoice && $amount > 0) {
            (new InvoiceService())->createForSubscription($this->forTenant($tenant->id()) ?? [], $options);
        }

        Audit::record([
            'action'      => 'subscription.activated',
            'tenant_id'   => $tenant->id(),
            'target_type' => 'tenant',
            'target_id'   => (string) $tenant->id(),
            'description' => sprintf('Activated %s plan (%s) for %s', $plan->name(), $cycle, $tenant->name()),
            'severity'    => 'notice',
            'meta'        => ['amount' => $amount, 'currency' => $plan->currency()],
        ]);

        return $this->forTenant($tenant->id()) ?? [];
    }

    public function changePlan(Tenant $tenant, Plan $newPlan, ?string $cycle = null): array
    {
        $current = $this->forTenant($tenant->id());
        $cycle   = $cycle ?: (string) ($current['billing_cycle'] ?? 'monthly');

        if ($current === null) {
            return $this->activate($tenant, $newPlan, $cycle, false);
        }

        $previous = $current['plan_name'] ?? 'none';
        $prorated = $this->prorate($current, $newPlan, $cycle);

        Manager::platform()->prepare(
            'UPDATE subscriptions SET plan_id = ?, billing_cycle = ?, unit_amount = ?, amount = ?, currency = ?, updated_at = ? WHERE id = ?'
        )->execute([$newPlan->id(), $cycle, $newPlan->priceFor($cycle), $newPlan->priceFor($cycle), $newPlan->currency(), Clock::now(), $current['id']]);

        (new \Resto\Tenancy\TenantRepository())->update($tenant->id(), [
            'plan_id'       => $newPlan->id(),
            'billing_cycle' => $cycle,
            'mrr'           => $cycle === 'yearly' ? round($newPlan->priceFor($cycle) / 12, 2) : $newPlan->priceFor($cycle),
        ]);

        Audit::record([
            'action'      => 'subscription.plan_changed',
            'tenant_id'   => $tenant->id(),
            'target_type' => 'tenant',
            'target_id'   => (string) $tenant->id(),
            'description' => sprintf('%s moved from %s to %s', $tenant->name(), $previous, $newPlan->name()),
            'severity'    => 'notice',
            'meta'        => ['proration' => $prorated],
        ]);

        return $this->forTenant($tenant->id()) ?? [];
    }

    /** Rough mid-cycle proration credit, surfaced in the UI before confirming. */
    public function prorate(array $subscription, Plan $newPlan, string $cycle): array
    {
        $start = strtotime((string) ($subscription['current_period_start'] ?? Clock::now()) . ' UTC');
        $end   = strtotime((string) ($subscription['current_period_end'] ?? Clock::now()) . ' UTC');
        $now   = time();

        $totalDays = max(1, (int) round(($end - $start) / 86400));
        $leftDays  = max(0, (int) round(($end - $now) / 86400));
        $ratio     = $leftDays / $totalDays;

        $credit   = round((float) $subscription['amount'] * $ratio, 2);
        $charge   = round($newPlan->priceFor($cycle) * $ratio, 2);

        return [
            'days_remaining' => $leftDays,
            'credit'         => $credit,
            'charge'         => $charge,
            'due_now'        => max(0, round($charge - $credit, 2)),
        ];
    }

    public function cancel(Tenant $tenant, bool $immediately = false, string $reason = ''): void
    {
        $subscription = $this->forTenant($tenant->id());
        if (!$subscription) {
            return;
        }

        if ($immediately) {
            Manager::platform()->prepare('UPDATE subscriptions SET status = ?, cancelled_at = ?, ended_at = ?, updated_at = ? WHERE id = ?')
                ->execute(['cancelled', Clock::now(), Clock::now(), Clock::now(), $subscription['id']]);
            (new \Resto\Tenancy\TenantRepository())->update($tenant->id(), [
                'status'       => 'cancelled',
                'cancelled_at' => Clock::now(),
                'mrr'          => 0,
            ]);
        } else {
            Manager::platform()->prepare('UPDATE subscriptions SET cancel_at_period_end = 1, updated_at = ? WHERE id = ?')
                ->execute([Clock::now(), $subscription['id']]);
        }

        Audit::record([
            'action'      => $immediately ? 'subscription.cancelled' : 'subscription.cancel_scheduled',
            'tenant_id'   => $tenant->id(),
            'target_type' => 'tenant',
            'target_id'   => (string) $tenant->id(),
            'description' => ($immediately ? 'Cancelled ' : 'Scheduled cancellation for ') . $tenant->name() . ($reason !== '' ? " — {$reason}" : ''),
            'severity'    => 'warning',
        ]);
    }

    /** Resume a subscription that was set to cancel at period end. */
    public function resume(Tenant $tenant): void
    {
        Manager::platform()->prepare('UPDATE subscriptions SET cancel_at_period_end = 0, status = ?, cancelled_at = NULL, updated_at = ? WHERE tenant_id = ?')
            ->execute(['active', Clock::now(), $tenant->id()]);
        (new \Resto\Tenancy\TenantRepository())->update($tenant->id(), ['status' => 'active']);

        Audit::record([
            'action'      => 'subscription.resumed',
            'tenant_id'   => $tenant->id(),
            'target_type' => 'tenant',
            'target_id'   => (string) $tenant->id(),
            'description' => 'Resumed subscription for ' . $tenant->name(),
            'severity'    => 'notice',
        ]);
    }

    /** Flag overdue invoices: open => past_due, and (optionally) suspend the tenant. */
    public function runDunning(?int $tenantId = null): array
    {
        $graceDays = (int) Settings::get('past_due_grace_days', '7');
        $pdo       = Manager::platform();

        $sql    = "SELECT * FROM invoices WHERE status IN ('open', 'past_due') AND due_at IS NOT NULL AND due_at < ?";
        $params = [Clock::now()];
        if ($tenantId !== null) {
            $sql     .= ' AND tenant_id = ?';
            $params[] = $tenantId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $marked   = 0;

        foreach ($invoices as $invoice) {
            if ($invoice['status'] !== 'past_due') {
                $pdo->prepare('UPDATE invoices SET status = ?, updated_at = ? WHERE id = ?')
                    ->execute(['past_due', Clock::now(), $invoice['id']]);
                $pdo->prepare("UPDATE tenants SET status = 'past_due', updated_at = ? WHERE id = ? AND status = 'active'")
                    ->execute([Clock::now(), $invoice['tenant_id']]);

                Audit::record([
                    'action'      => 'invoice.past_due',
                    'tenant_id'   => (int) $invoice['tenant_id'],
                    'target_type' => 'invoice',
                    'target_id'   => (string) $invoice['id'],
                    'description' => 'Invoice ' . $invoice['number'] . ' is past due',
                    'severity'    => 'warning',
                ]);

                Notifications::push([
                    'tenant_id' => (int) $invoice['tenant_id'],
                    'type'      => 'billing.past_due',
                    'level'     => 'warning',
                    'title'     => 'Invoice ' . $invoice['number'] . ' is overdue',
                    'body'      => Money::format((float) $invoice['total'], (string) $invoice['currency']) . ' was due ' . $invoice['due_at'],
                ]);
                $marked++;
            }

            // Auto-suspend long overdue tenants.
            if (Settings::bool('auto_suspend', true) && Clock::daysBetween($invoice['due_at']) > $graceDays) {
                $tenant = (new \Resto\Tenancy\TenantRepository())->find((int) $invoice['tenant_id']);
                if ($tenant && $tenant->status() === 'past_due') {
                    (new \Resto\Tenancy\TenantRepository())->update($tenant->id(), [
                        'status'           => 'suspended',
                        'suspended_at'     => Clock::now(),
                        'suspended_reason' => 'Payment overdue — invoice ' . $invoice['number'],
                    ]);
                    Audit::record([
                        'action'      => 'tenant.suspended',
                        'tenant_id'   => $tenant->id(),
                        'description' => 'Auto-suspended ' . $tenant->name() . ' after ' . $graceDays . ' days past due',
                        'severity'    => 'critical',
                    ]);
                }
            }
        }

        return ['scanned' => count($invoices), 'marked_past_due' => $marked];
    }
}

/* -------------------------------------------------------------------------
 * Invoices
 * ---------------------------------------------------------------------- */

final class InvoiceService
{
    public function nextNumber(): string
    {
        $prefix = (string) Settings::get('invoice_prefix', 'INV');
        $period = gmdate('Ym');
        $count  = (int) Metrics::scalar('SELECT COUNT(*) FROM invoices WHERE number LIKE ?', [$prefix . '-' . $period . '-%']) + 1;
        return sprintf('%s-%s-%04d', $prefix, $period, $count);
    }

    public function createForSubscription(array $subscription, array $options = []): array
    {
        if ($subscription === []) {
            return [];
        }

        $tenant = (new \Resto\Tenancy\TenantRepository())->find((int) $subscription['tenant_id']);
        if ($tenant === null) {
            return [];
        }

        $plan        = (new PlanRepository())->find((int) $subscription['plan_id']);
        $amount      = (float) $subscription['amount'];
        $discount    = round($amount * ((float) $subscription['discount_percent'] / 100), 2);
        $taxRate     = (float) Settings::get('tax_rate', '0');
        $tax         = round(($amount - $discount) * ($taxRate / 100), 2);
        $total       = round($amount - $discount + $tax, 2);
        $dueDays     = (int) Settings::get('invoice_due_days', '14');
        $issuedAt    = $options['issued_at'] ?? Clock::now();

        $number = $this->nextNumber();
        Manager::platform()->prepare(
            'INSERT INTO invoices (tenant_id, subscription_id, number, status, currency, subtotal, discount, tax, total,
                                   period_start, period_end, issued_at, due_at, lines, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tenant->id(),
            (int) $subscription['id'],
            $number,
            'open',
            (string) $subscription['currency'],
            $amount,
            $discount,
            $tax,
            $total,
            $subscription['current_period_start'],
            $subscription['current_period_end'],
            $issuedAt,
            Clock::addDays($issuedAt, $dueDays),
            json_encode([[
                'description' => ($plan?->name() ?? 'Subscription') . ' — ' . ($subscription['billing_cycle'] ?? 'monthly') . ' plan',
                'quantity'    => 1,
                'unit_price'  => $amount,
                'total'       => $amount,
            ]]),
            $options['notes'] ?? null,
            Clock::now(),
            Clock::now(),
        ]);

        $id = (int) Manager::platform()->lastInsertId();

        Audit::record([
            'action'      => 'invoice.created',
            'tenant_id'   => $tenant->id(),
            'target_type' => 'invoice',
            'target_id'   => (string) $id,
            'description' => sprintf('Issued %s for %s (%s)', $number, $tenant->name(), Money::format($total, (string) $subscription['currency'])),
            'severity'    => 'notice',
        ]);

        return $this->find($id) ?? [];
    }

    /** Ad-hoc invoice (e.g. onboarding fee, extra locations). */
    public function createManual(Tenant $tenant, array $lines, array $options = []): array
    {
        $currency = $options['currency'] ?? (string) $tenant->get('currency', 'USD');
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) ($line['total'] ?? ((float) ($line['unit_price'] ?? 0) * (int) ($line['quantity'] ?? 1)));
        }
        $discount = (float) ($options['discount'] ?? 0);
        $tax      = round(($subtotal - $discount) * ((float) Settings::get('tax_rate', '0') / 100), 2);
        $total    = round($subtotal - $discount + $tax, 2);
        $issuedAt = Clock::now();

        $number = $this->nextNumber();
        Manager::platform()->prepare(
            'INSERT INTO invoices (tenant_id, subscription_id, number, status, currency, subtotal, discount, tax, total,
                                   issued_at, due_at, lines, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tenant->id(),
            null,
            $number,
            'open',
            $currency,
            $subtotal,
            $discount,
            $tax,
            $total,
            $issuedAt,
            Clock::addDays($issuedAt, (int) Settings::get('invoice_due_days', '14')),
            json_encode($lines),
            $options['notes'] ?? null,
            Clock::now(),
            Clock::now(),
        ]);

        return $this->find((int) Manager::platform()->lastInsertId()) ?? [];
    }

    public function find(int $id): ?array
    {
        $stmt = Manager::platform()->prepare(
            'SELECT i.*, t.name AS tenant_name, t.slug AS tenant_slug, t.owner_email
             FROM invoices i LEFT JOIN tenants t ON t.id = i.tenant_id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->present($row) : null;
    }

    public function markPaid(int $id, float $amount, string $method = 'manual'): array
    {
        $invoice = $this->find($id);
        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found');
        }

        $paidAt = Clock::now();
        Manager::platform()->prepare(
            'UPDATE invoices SET status = ?, amount_paid = ?, paid_at = ?, payment_method = ?, updated_at = ? WHERE id = ?'
        )->execute(['paid', $amount, $paidAt, $method, $paidAt, $id]);

        // Settle the tenant again and roll the period forward.
        $tenant = (new \Resto\Tenancy\TenantRepository())->find((int) $invoice['tenant_id']);
        if ($tenant) {
            (new \Resto\Tenancy\TenantRepository())->update($tenant->id(), ['status' => 'active', 'suspended_at' => null, 'suspended_reason' => null]);

            $subscription = (new SubscriptionService())->forTenant($tenant->id());
            if ($subscription) {
                $cycle = (string) $subscription['billing_cycle'];
                $start = Clock::now();
                $end   = $cycle === 'yearly' ? Clock::addMonths($start, 12) : Clock::addMonths($start, 1);
                Manager::platform()->prepare(
                    'UPDATE subscriptions SET status = ?, current_period_start = ?, current_period_end = ?, cancel_at_period_end = 0, updated_at = ? WHERE id = ?'
                )->execute(['active', $start, $end, Clock::now(), $subscription['id']]);
            }

            if ((bool) Settings::bool('new_tenant_notifications', true)) {
                Notifications::push([
                    'tenant_id' => $tenant->id(),
                    'type'      => 'billing.paid',
                    'level'     => 'success',
                    'title'     => 'Payment received from ' . $tenant->name(),
                    'body'      => Money::format($amount, (string) $invoice['currency']) . ' for invoice ' . $invoice['number'],
                ]);
            }
        }

        Audit::record([
            'action'      => 'invoice.paid',
            'tenant_id'   => (int) $invoice['tenant_id'],
            'target_type' => 'invoice',
            'target_id'   => (string) $id,
            'description' => 'Marked ' . $invoice['number'] . ' paid (' . Money::format($amount, (string) $invoice['currency']) . ')',
            'severity'    => 'notice',
        ]);

        return $this->find($id) ?? [];
    }

    public function void(int $id, string $reason = ''): array
    {
        Manager::platform()->prepare('UPDATE invoices SET status = ?, voided_at = ?, notes = ?, updated_at = ? WHERE id = ?')
            ->execute(['void', Clock::now(), $reason, Clock::now(), $id]);

        $invoice = $this->find($id);
        Audit::record([
            'action'      => 'invoice.voided',
            'tenant_id'   => $invoice['tenant_id'] ?? null,
            'target_type' => 'invoice',
            'target_id'   => (string) $id,
            'description' => 'Voided invoice ' . ($invoice['number'] ?? $id) . ($reason !== '' ? " — {$reason}" : ''),
            'severity'    => 'warning',
        ]);

        return $invoice ?? [];
    }

    /**
     * @param array{tenant_id?:int,status?:string|array,search?:string} $filters
     */
    public function paginate(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['tenant_id'])) {
            $where[]  = 'i.tenant_id = ?';
            $params[] = (int) $filters['tenant_id'];
        }
        if (!empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $where[]  = 'i.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params   = array_merge($params, $statuses);
        }
        if (!empty($filters['search'])) {
            $where[] = '(i.number LIKE ? OR t.name LIKE ? OR t.owner_email LIKE ?)';
            $term    = '%' . $filters['search'] . '%';
            $params  = array_merge($params, [$term, $term, $term]);
        }
        if (!empty($filters['overdue'])) {
            $where[]  = "i.status IN ('open','past_due') AND i.due_at < ?";
            $params[] = Clock::now();
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = Manager::platform()->prepare('SELECT COUNT(*) FROM invoices i LEFT JOIN tenants t ON t.id = i.tenant_id' . $clause);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT i.*, t.name AS tenant_name, t.slug AS tenant_slug
                FROM invoices i LEFT JOIN tenants t ON t.id = i.tenant_id' . $clause
            . ' ORDER BY i.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage);

        $stmt = Manager::platform()->prepare($sql);
        $stmt->execute($params);

        return [
            'data' => array_map([$this, 'present'], $stmt->fetchAll(\PDO::FETCH_ASSOC)),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    public function summary(): array
    {
        return [
            'paid'      => (float) Metrics::scalar("SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE status = 'paid' AND paid_at >= ?", [Clock::periodStart()]),
            'open'      => (float) Metrics::scalar("SELECT COALESCE(SUM(total - amount_paid),0) FROM invoices WHERE status = 'open'"),
            'past_due'  => (float) Metrics::scalar("SELECT COALESCE(SUM(total - amount_paid),0) FROM invoices WHERE status = 'past_due'"),
            'due_soon'  => (float) Metrics::scalar("SELECT COALESCE(SUM(total - amount_paid),0) FROM invoices WHERE status = 'open' AND due_at <= ?", [Clock::addDays(Clock::now(), 7)]),
            'counts'    => [
                'open'     => (int) Metrics::scalar("SELECT COUNT(*) FROM invoices WHERE status = 'open'"),
                'past_due' => (int) Metrics::scalar("SELECT COUNT(*) FROM invoices WHERE status = 'past_due'"),
                'paid'     => (int) Metrics::scalar("SELECT COUNT(*) FROM invoices WHERE status = 'paid'"),
            ],
        ];
    }

    public function present(array $row): array
    {
        $row['lines'] = $row['lines'] ? json_decode((string) $row['lines'], true) : [];
        $row['total'] = (float) $row['total'];
        $row['amount_paid'] = (float) $row['amount_paid'];
        $row['balance'] = round((float) $row['total'] - (float) $row['amount_paid'], 2);
        $row['overdue'] = in_array($row['status'], ['open', 'past_due'], true)
            && !empty($row['due_at'])
            && $row['due_at'] < Clock::now();
        return $row;
    }
}

/* -------------------------------------------------------------------------
 * Usage metering
 * ---------------------------------------------------------------------- */

final class UsageService
{
    /**
     * Record a metered event (an order, an API call, a storage delta…).
     * Also bumps the monthly counter and touches the tenant's last activity.
     */
    public static function record(int $tenantId, string $metric, int $quantity = 1, string $source = 'app', array $meta = []): void
    {
        if ($tenantId <= 0 || $quantity === 0) {
            return;
        }

        try {
            $pdo = Manager::platform();
            $pdo->prepare('INSERT INTO usage_events (tenant_id, metric, quantity, source, occurred_at, meta) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$tenantId, $metric, $quantity, $source, Clock::now(), $meta === [] ? null : json_encode($meta)]);

            $period = gmdate('Y-m');
            $pdo->prepare(
                'INSERT INTO usage_counters (tenant_id, metric, period, value, updated_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = value + VALUES(value), updated_at = VALUES(updated_at)'
            )->execute([$tenantId, $metric, $period, $quantity, Clock::now()]);
        } catch (\Throwable $e) {
            error_log('[usage] ' . $e->getMessage());
        }
    }

    public static function current(int $tenantId, string $metric, ?string $period = null): int
    {
        $stmt = Manager::platform()->prepare('SELECT COALESCE(value, 0) FROM usage_counters WHERE tenant_id = ? AND metric = ? AND period = ?');
        $stmt->execute([$tenantId, $metric, $period ?? gmdate('Y-m')]);
        return (int) $stmt->fetchColumn();
    }

    /** Count live rows in the tenant database for quota checks. */
    public static function actual(Tenant $tenant, string $metric): int
    {
        $table = match ($metric) {
            'menu_items' => 'food_items',
            'staff_users' => 'admins',
            'tables' => 'restaurant_tables',
            'customers' => 'customers',
            default => null,
        };
        if ($table === null) {
            return 0;
        }
        try {
            return (int) $tenant->connection()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Usage dashboard data for one tenant. */
    public static function summary(Tenant $tenant): array
    {
        $period = gmdate('Y-m');
        $pdo    = Manager::platform();

        $stmt = $pdo->prepare('SELECT metric, value FROM usage_counters WHERE tenant_id = ? AND period = ?');
        $stmt->execute([$tenant->id(), $period]);
        $counters = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counters[$row['metric']] = (int) $row['value'];
        }

        $history = [];
        $stmt    = $pdo->prepare('SELECT period, metric, value FROM usage_counters WHERE tenant_id = ? AND period >= ? ORDER BY period ASC');
        $stmt->execute([$tenant->id(), gmdate('Y-m', strtotime('-11 months'))]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $history[$row['period']][$row['metric']] = (int) $row['value'];
        }

        $plan = (new \Resto\Tenancy\FeatureGate($tenant));

        return [
            'period'   => $period,
            'counters' => $counters,
            'history'  => $history,
            'quotas'   => $plan->usageReport(),
            'recent'   => self::recentEvents($tenant->id()),
        ];
    }

    public static function recentEvents(int $tenantId, int $limit = 20): array
    {
        $stmt = Manager::platform()->prepare(
            'SELECT metric, quantity, source, occurred_at FROM usage_events WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['ago'] = Clock::human($row['occurred_at']);
        }
        return $rows;
    }

    /**
     * Rebuild monthly counters from the tenant's own order history.
     *
     * Called after importing data / provisioning, so a restaurant that
     * migrates in with three years of orders has accurate usage from day one.
     *
     * @param bool $deriveExtras also estimate API calls and emails (demo fixtures)
     */
    public static function backfill(Tenant $tenant, bool $deriveExtras = false): array
    {
        $conn = $tenant->connection();

        $counters = [];
        try {
            $rows = $conn->query(
                "SELECT SUBSTR(created_at, 1, 7) AS period, COUNT(*) AS total, MAX(created_at) AS last_order
                 FROM orders GROUP BY SUBSTR(created_at, 1, 7)"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $pdo = Manager::platform();
            $upsert = $pdo->prepare(
                'INSERT INTO usage_counters (tenant_id, metric, period, value, updated_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
            );

            $latest = null;
            foreach ($rows as $row) {
                if (empty($row['period'])) {
                    continue;
                }
                $orders = (int) $row['total'];
                $upsert->execute([$tenant->id(), 'orders', $row['period'], $orders, Clock::now()]);
                $counters[$row['period']] = $orders;
                if ($deriveExtras) {
                    $upsert->execute([$tenant->id(), 'api_calls', $row['period'], (int) round($orders * 1.6), Clock::now()]);
                    $upsert->execute([$tenant->id(), 'email_sends', $row['period'], (int) round($orders * 1.2), Clock::now()]);
                }
                if ($latest === null || $row['last_order'] > $latest) {
                    $latest = $row['last_order'];
                }
            }

            if ($latest !== null) {
                (new \Resto\Tenancy\TenantRepository())->update($tenant->id(), ['last_activity_at' => $latest]);
            }
        } catch (\Throwable $e) {
            error_log('[usage:backfill] ' . $e->getMessage());
        }

        return $counters;
    }

    /** Platform-wide usage table for the console. */
    public static function platformSummary(): array
    {
        $period = gmdate('Y-m');
        $stmt   = Manager::platform()->prepare(
            "SELECT t.id, t.name, t.slug, t.status, t.seat_count, p.name AS plan_name,
                    COALESCE(u.value, 0) AS orders,
                    (SELECT COALESCE(SUM(value),0) FROM usage_counters c WHERE c.tenant_id = t.id AND c.metric = 'api_calls' AND c.period = ?) AS api_calls,
                    (SELECT COALESCE(SUM(value),0) FROM usage_counters c WHERE c.tenant_id = t.id AND c.metric = 'email_sends' AND c.period = ?) AS emails
             FROM tenants t
             LEFT JOIN plans p ON p.id = t.plan_id
             LEFT JOIN usage_counters u ON u.tenant_id = t.id AND u.metric = 'orders' AND u.period = ?
             WHERE t.status <> 'cancelled'
             ORDER BY orders DESC, t.name ASC"
        );
        $stmt->execute([$period, $period, $period]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
