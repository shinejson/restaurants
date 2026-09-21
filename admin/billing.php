<?php
/**
 * Plan & Billing — the tenant-side upgrade screen.
 *
 * Restaurant owners see their current plan, live usage against the plan's
 * quotas, the plans they can move to, and their invoice history. Switching a
 * plan goes through SubscriptionService so the control plane stays the single
 * source of truth (the superadmin console sees the change immediately).
 */

use Resto\Billing\InvoiceService;
use Resto\Billing\PlanRepository;
use Resto\Billing\SubscriptionService;
use Resto\Tenancy\FeatureGate;

require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/permissions.php';

$admin_title = 'Plan & Billing';

$tenant = current_tenant();
if ($tenant === null) {
    include 'includes/admin_header.php';
    echo '<div class="admin-content-padding"><div class="card" style="padding:28px">'
        . '<h2>No restaurant selected</h2><p>This page is only available inside a restaurant workspace.</p></div></div>';
    include 'includes/admin_footer.php';
    exit;
}

if (!has_permission('manage_settings')) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$plans         = new PlanRepository();
$subscriptions = new SubscriptionService();
$invoices      = new InvoiceService();
$gate          = new FeatureGate($tenant);

$subscription = $subscriptions->forTenant($tenant->id());
$currentPlan  = $gate->plan();
$currency     = (string) $tenant->get('currency', 'USD');

$notice = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === ($_SESSION['csrf_token'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    $cycle  = ($_POST['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $plan   = $plans->find((int) ($_POST['plan_id'] ?? 0));

    try {
        switch ($action) {
            case 'activate':
            case 'change_plan':
                if ($plan === null) {
                    throw new RuntimeException('Pick a plan to continue.');
                }
                if ($subscription === null) {
                    $subscriptions->activate($tenant, $plan, $cycle, true);
                    $notice = 'Your ' . $plan->name() . ' plan is now active.';
                } else {
                    $subscriptions->changePlan($tenant, $plan, $cycle);
                    $notice = 'Moved to the ' . $plan->name() . ' plan.';
                }
                break;

            case 'cancel':
                $subscriptions->cancel($tenant, false, (string) ($_POST['reason'] ?? ''));
                $notice = 'Your subscription will not renew. You keep access until the end of the period.';
                break;

            case 'resume':
                $subscriptions->resume($tenant);
                $notice = 'Welcome back — your subscription will renew as normal.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === null && $notice !== null) {
        header('Location: ' . BASE_URL . '/admin/billing.php?ok=1');
        exit;
    }

    // Refresh state after a failed attempt.
    $subscription = $subscriptions->forTenant($tenant->id());
    $gate         = new FeatureGate($tenant);
    $currentPlan  = $gate->plan();
}

if (isset($_GET['ok'])) {
    $notice = 'Billing updated.';
}

$usage   = $gate->usageReport();
$history = $invoices->paginate(['tenant_id' => $tenant->id()], 100, 1);

// Tenant-scoped totals (InvoiceService::summary() is platform-wide).
$openTotal = 0.0;
$paidTotal = 0.0;
$openCount = 0;
$paidCount = 0;
foreach ($history['data'] as $row) {
    if (in_array($row['status'], ['open', 'past_due'], true)) {
        $openTotal += (float) $row['balance'];
        $openCount++;
    } elseif ($row['status'] === 'paid') {
        $paidTotal += (float) $row['total'];
        $paidCount++;
    }
}
$recentInvoices = array_slice($history['data'], 0, 12);
$status     = $tenant->status();
$statusMeta = [
    'trial'     => ['Trial', 'badge-info'],
    'active'    => ['Active', 'badge-success'],
    'past_due'  => ['Past due', 'badge-danger'],
    'suspended' => ['Suspended', 'badge-danger'],
    'cancelled' => ['Cancelled', 'badge-secondary'],
][$status] ?? [ucfirst($status), 'badge-secondary'];

$money = static fn (float $amount): string => $currency . ' ' . number_format($amount, 2);

include 'includes/admin_header.php';
?>
<style>
    .billing-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:1.25rem }
    .billing-card { padding:1.4rem }
    .billing-card h3 { margin:0 0 .35rem; font-size:1.05rem }
    .billing-muted { color:var(--text-muted,#6b7280); font-size:.875rem }
    .plan-price { font-size:2rem; font-weight:800; letter-spacing:-.02em; margin:.4rem 0 }
    .plan-price span { font-size:.85rem; font-weight:600; color:var(--text-muted,#6b7280) }
    .usage-row { display:grid; grid-template-columns:1fr 150px; gap:.75rem; align-items:center; padding:.6rem 0; border-bottom:1px solid var(--border-color,#e5e7eb) }
    .usage-bar { height:8px; background:var(--border-color,#e5e7eb); border-radius:999px; overflow:hidden }
    .usage-bar > i { display:block; height:100%; background:linear-gradient(90deg,#6366f1,#8b5cf6) }
    .usage-bar > i.over { background:linear-gradient(90deg,#ef4444,#f97316) }
    .plan-card { display:flex; flex-direction:column; position:relative }
    .plan-card.current { outline:2px solid #6366f1 }
    .plan-ribbon { position:absolute; top:-11px; left:1.2rem; background:#6366f1; color:#fff; font-size:.7rem;
                   font-weight:700; padding:.2rem .6rem; border-radius:999px; letter-spacing:.04em; text-transform:uppercase }
    .plan-features { list-style:none; padding:0; margin:.6rem 0 1rem; font-size:.85rem }
    .plan-features li { padding:.2rem 0 }
    .plan-features li::before { content:'✓'; color:#10b981; font-weight:700; margin-right:.5rem }
</style>

<div class="content-header">
    <div>
        <h2 style="margin:0">Plan &amp; Billing</h2>
        <p class="billing-muted" style="margin:.35rem 0 0">
            Subscription for <strong><?php echo htmlspecialchars($tenant->name()); ?></strong>
            · <span class="badge <?php echo $statusMeta[1]; ?>"><?php echo $statusMeta[0]; ?></span>
            <?php if ($tenant->onTrial()): ?>
                · trial ends in <?php echo (int) $tenant->trialDaysLeft(); ?> day(s)
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($notice): ?>
    <div class="card" style="padding:1rem 1.25rem;border-left:4px solid #10b981;margin-bottom:1.25rem">
        <?php echo htmlspecialchars($notice); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="card" style="padding:1rem 1.25rem;border-left:4px solid #ef4444;margin-bottom:1.25rem">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($status === 'past_due'): ?>
    <div class="card" style="padding:1rem 1.25rem;border-left:4px solid #f59e0b;margin-bottom:1.25rem">
        <strong>Payment overdue.</strong> Settle the outstanding invoice to avoid your workspace being suspended.
    </div>
<?php elseif ($status === 'suspended'): ?>
    <div class="card" style="padding:1rem 1.25rem;border-left:4px solid #ef4444;margin-bottom:1.25rem">
        <strong>Workspace suspended.</strong> Contact support or bring your account up to date to restore access.
    </div>
<?php endif; ?>

<div class="billing-grid" style="margin-bottom:1.5rem">
    <div class="card billing-card">
        <h3>Current plan</h3>
        <?php if ($currentPlan): ?>
            <div class="plan-price">
                <?php echo $money((float) ($subscription['unit_amount'] ?? $currentPlan->priceFor((string) ($subscription['billing_cycle'] ?? 'monthly')))); ?>
                <span>/ <?php echo (string) ($subscription['billing_cycle'] ?? 'month'); ?></span>
            </div>
            <p class="billing-muted" style="margin:0">
                <?php echo htmlspecialchars($currentPlan->name()); ?>
                <?php if (!empty($subscription['current_period_end'])): ?>
                    · renews <?php echo htmlspecialchars(date('j M Y', strtotime((string) $subscription['current_period_end']))); ?>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="plan-price">Trial</div>
            <p class="billing-muted" style="margin:0">Pick a plan below to keep your workspace running.</p>
        <?php endif; ?>
    </div>

    <div class="card billing-card">
        <h3>Invoices</h3>
        <div class="plan-price"><?php echo $money($openTotal); ?></div>
        <p class="billing-muted" style="margin:0">
            <?php echo (int) $openCount; ?> open · <?php echo (int) $paidCount; ?> paid
            (<?php echo (int) $history['meta']['total']; ?> total)
        </p>
    </div>

    <div class="card billing-card">
        <h3>Payment method</h3>
        <div class="plan-price" style="font-size:1.1rem">Managed by RestaurantOS</div>
        <p class="billing-muted" style="margin:0">
            Invoices are issued by the platform team; contact support to switch to card or direct debit.
        </p>
    </div>
</div>

<div class="card" style="padding:1.5rem;margin-bottom:1.5rem">
    <h3 style="margin:0 0 .5rem">Usage this period</h3>
    <p class="billing-muted" style="margin:0 0 1rem">Counts reset at the start of every calendar month.</p>
    <?php foreach ($usage as $row): ?>
        <?php if ($row['limit'] === null && $row['used'] === 0) { continue; } ?>
        <div class="usage-row">
            <div>
                <strong><?php echo htmlspecialchars($row['label']); ?></strong>
                <div class="billing-muted">
                    <?php echo number_format((int) $row['used']); ?>
                    <?php if ($row['unlimited']): ?>
                        <?php echo htmlspecialchars($row['unit']); ?> — unlimited
                    <?php else: ?>
                        of <?php echo number_format((int) $row['limit']); ?> <?php echo htmlspecialchars($row['unit']); ?>
                        <?php echo $row['exceeded'] ? '· limit reached' : '· ' . number_format((int) $row['remaining']) . ' left'; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="usage-bar">
                <i class="<?php echo $row['exceeded'] ? 'over' : ''; ?>"
                   style="width:<?php echo $row['percentage'] === null ? 0 : (int) $row['percentage']; ?>%"></i>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<h3 style="margin:0 0 1rem">Change plan</h3>
<div class="billing-grid" style="margin-bottom:2rem">
    <?php foreach ($plans->all(false) as $plan): ?>
        <?php
        $isCurrent = $currentPlan !== null && $currentPlan->id() === $plan->id();
        $limits    = $plan->limits();
        $features  = array_keys(array_filter($plan->features()));
        ?>
        <div class="card billing-card plan-card <?php echo $isCurrent ? 'current' : ''; ?>">
            <?php if (!empty($plan->badge)): ?>
                <span class="plan-ribbon"><?php echo htmlspecialchars((string) $plan->badge); ?></span>
            <?php elseif ($isCurrent): ?>
                <span class="plan-ribbon">Current</span>
            <?php endif; ?>

            <h3 style="margin-top:<?php echo (!empty($plan->badge) || $isCurrent) ? '.6rem' : '0'; ?>">
                <?php echo htmlspecialchars($plan->name()); ?>
            </h3>
            <p class="billing-muted" style="margin:0"><?php echo htmlspecialchars((string) $plan->tagline); ?></p>

            <div class="plan-price">
                <?php echo $money($plan->priceFor('monthly')); ?><span>/mo</span>
            </div>
            <p class="billing-muted" style="margin:0 0 .75rem">
                or <?php echo $money($plan->priceFor('yearly')); ?> / year
                <?php if ((int) $plan->trial_days > 0): ?>· <?php echo (int) $plan->trial_days; ?>-day trial<?php endif; ?>
            </p>

            <ul class="plan-features">
                <li><?php echo (int) ($limits['staff_users'] ?? 0) ?: 'Unlimited'; ?> staff seats</li>
                <li><?php echo (int) ($limits['menu_items'] ?? 0) ?: 'Unlimited'; ?> menu items</li>
                <li><?php echo (int) ($limits['orders'] ?? 0) ?: 'Unlimited'; ?> orders / month</li>
                <li><?php echo (int) ($limits['locations'] ?? 0) ?: 'Unlimited'; ?> location(s)</li>
                <?php foreach (array_slice($features, 0, 3) as $feature): ?>
                    <li><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $feature))); ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($isCurrent): ?>
                <button class="btn-primary" type="button" disabled style="opacity:.6;cursor:default">Current plan</button>
            <?php else: ?>
                <form method="post" style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="<?php echo $subscription === null ? 'activate' : 'change_plan'; ?>">
                    <input type="hidden" name="plan_id" value="<?php echo (int) $plan->id(); ?>">
                    <button class="btn-primary" name="billing_cycle" value="monthly" type="submit">
                        <?php echo $subscription === null ? 'Activate' : 'Switch'; ?> monthly
                    </button>
                    <button class="btn-icon" name="billing_cycle" value="yearly" type="submit"
                            style="border:1px solid var(--border-color,#e5e7eb);border-radius:10px;padding:.55rem .9rem;background:#fff">
                        Yearly — save 2 months
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="card" style="padding:1.5rem;margin-bottom:1.5rem">
    <h3 style="margin:0 0 1rem">Invoice history</h3>
    <?php if ($recentInvoices === []): ?>
        <p class="billing-muted" style="margin:0">No invoices yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice</th><th>Issued</th><th>Due</th><th>Total</th><th>Balance</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentInvoices as $invoice): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string) $invoice['number']); ?></strong></td>
                        <td><?php echo $invoice['created_at'] ? htmlspecialchars(date('j M Y', strtotime((string) $invoice['created_at']))) : '—'; ?></td>
                        <td><?php echo $invoice['due_at'] ? htmlspecialchars(date('j M Y', strtotime((string) $invoice['due_at']))) : '—'; ?></td>
                        <td><?php echo $money((float) $invoice['total']); ?></td>
                        <td><?php echo $money((float) $invoice['balance']); ?></td>
                        <td>
                            <span class="badge <?php
                                echo match ($invoice['status']) {
                                    'paid'     => 'badge-success',
                                    'past_due' => 'badge-danger',
                                    'void'     => 'badge-secondary',
                                    default    => 'badge-warning',
                                };
                            ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $invoice['status']))); ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($subscription !== null): ?>
    <div class="card" style="padding:1.5rem">
        <h3 style="margin:0 0 .5rem">Subscription controls</h3>
        <?php if (($subscription['status'] ?? '') === 'cancelled'): ?>
            <p class="billing-muted">Your subscription is cancelled. You can resume it before the period ends.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="resume">
                <button class="btn-primary" type="submit">Resume subscription</button>
            </form>
        <?php else: ?>
            <p class="billing-muted">Cancelling stops future renewals — you keep access until the current period ends.</p>
            <form method="post" onsubmit="return confirm('Cancel auto-renewal for this workspace?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="cancel">
                <input class="form-control" name="reason" placeholder="Reason (optional)" style="max-width:340px;display:inline-block">
                <button class="btn-icon" type="submit" style="border:1px solid #ef4444;color:#ef4444;background:#fff;border-radius:10px;padding:.55rem .9rem">
                    Cancel auto-renewal
                </button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>
