<?php
/**
 * Seed a realistic set of tenants so the console has something to show.
 *
 * Each tenant gets its own database with its own menu, tables, staff and
 * trading history — the demo is genuinely multi-tenant, not a mock-up. Billing
 * states (past due, suspended, cancelled) are produced by real subscription
 * and invoice records, so the dunning views show genuine data.
 */
use Resto\Billing\InvoiceService;
use Resto\Billing\PlanRepository;
use Resto\Billing\SubscriptionService;
use Resto\Support\Clock;
use Resto\Tenancy\Provisioner;
use Resto\Tenancy\TenantRepository;

return [
    'up' => static function (PDO $conn, string $driver): void {
        $repo  = new TenantRepository();
        $plans = [];
        foreach ((new PlanRepository())->all() as $plan) {
            $plans[$plan->code()] = $plan;
        }
        if ($plans === []) {
            return;
        }

        $seed = [
            [
                'slug' => 'demo', 'name' => 'Aurora Kitchen', 'owner_name' => 'Adjoa Mensah',
                'owner_email' => 'adjoa@aurorakitchen.test', 'owner_phone' => '+233 24 111 0001',
                'city' => 'Accra', 'country' => 'Ghana', 'currency' => 'USD', 'plan' => 'growth',
                'status' => 'active', 'demo' => true, 'seats' => 12, 'cycle' => 'monthly',
            ],
            [
                'slug' => 'verde-bowl', 'name' => 'Verde Bowl', 'owner_name' => 'Marco Rossi',
                'owner_email' => 'marco@verdebowl.test', 'owner_phone' => '+39 06 123456',
                'city' => 'Milan', 'country' => 'Italy', 'currency' => 'EUR', 'plan' => 'pro',
                'status' => 'trial', 'demo' => true, 'seats' => 24, 'cycle' => 'yearly',
                'trial_days' => 21, 'trial_ends_in' => 3,
            ],
            [
                'slug' => 'sunset-grill', 'name' => 'Sunset Grill', 'owner_name' => 'Dana Whitfield',
                'owner_email' => 'dana@sunsetgrill.test', 'owner_phone' => '+1 415 555 0142',
                'city' => 'San Francisco', 'country' => 'United States', 'currency' => 'USD', 'plan' => 'starter',
                'status' => 'trial', 'demo' => false, 'seats' => 4, 'cycle' => 'monthly',
                'trial_days' => 14, 'trial_ends_in' => 9, 'age_days' => 12,
            ],
            [
                'slug' => 'le-petit-bistro', 'name' => 'Le Petit Bistro', 'owner_name' => 'Claire Dubois',
                'owner_email' => 'claire@petitbistro.test', 'city' => 'Lyon', 'country' => 'France',
                'currency' => 'EUR', 'plan' => 'starter', 'status' => 'past_due', 'demo' => false,
                'seats' => 3, 'cycle' => 'monthly', 'age_days' => 150,
            ],
            [
                'slug' => 'harbour-noodle-bar', 'name' => 'Harbour Noodle Bar', 'owner_name' => 'Kenji Tanaka',
                'owner_email' => 'kenji@harbournoodle.test', 'city' => 'Singapore', 'country' => 'Singapore',
                'currency' => 'USD', 'plan' => 'growth', 'status' => 'suspended', 'demo' => false,
                'seats' => 9, 'cycle' => 'monthly', 'age_days' => 300,
                'suspended_reason' => 'Payment overdue — invoice unpaid for 21 days',
            ],
            [
                'slug' => 'city-diner', 'name' => 'City Diner', 'owner_name' => 'Priya Nair',
                'owner_email' => 'priya@citydiner.test', 'city' => 'Bengaluru', 'country' => 'India',
                'currency' => 'USD', 'plan' => 'starter', 'status' => 'cancelled', 'demo' => false,
                'seats' => 2, 'cycle' => 'monthly', 'age_days' => 420,
            ],
        ];

        $subscriptions = new SubscriptionService();
        $invoices      = new InvoiceService();

        foreach ($seed as $definition) {
            if ($repo->slugExists($definition['slug'])) {
                continue;
            }

            $plan   = $plans[$definition['plan']] ?? reset($plans);
            $status = (string) $definition['status'];
            $cycle  = (string) ($definition['cycle'] ?? 'monthly');
            $age    = (int) ($definition['age_days'] ?? random_int(30, 240));

            $trialEnds = $status === 'trial'
                ? Clock::addDays(Clock::now(), (int) ($definition['trial_ends_in'] ?? 14))
                : null;

            $conn->beginTransaction();
            try {
                $tenant = $repo->create([
                    'name'             => $definition['name'],
                    'slug'             => $definition['slug'],
                    'owner_name'       => $definition['owner_name'],
                    'owner_email'      => $definition['owner_email'],
                    'owner_phone'      => $definition['owner_phone'] ?? null,
                    'city'             => $definition['city'] ?? null,
                    'country'          => $definition['country'] ?? null,
                    'currency'         => $definition['currency'] ?? 'USD',
                    'timezone'         => 'UTC',
                    'status'           => $status,
                    'plan_id'          => $plan->id(),
                    'billing_cycle'    => $cycle,
                    'seat_count'       => (int) ($definition['seats'] ?? 5),
                    'trial_ends_at'    => $trialEnds,
                    'suspended_reason' => $definition['suspended_reason'] ?? null,
                ]);

                // Backdate the signup so growth charts have history.
                $conn->prepare('UPDATE tenants SET created_at = ?, last_activity_at = ? WHERE id = ?')
                    ->execute([Clock::addDays(Clock::now(), -$age), Clock::addDays(Clock::now(), -random_int(0, 3)), $tenant->id()]);

                $result = Provisioner::provision($tenant, [
                    'demo'       => (bool) ($definition['demo'] ?? false),
                    'plan_id'    => $plan->id(),
                    'trial_days' => (int) ($definition['trial_days'] ?? 14),
                    // Fixture tenants: a known password so the owner login (and
                    // support impersonation) can actually be demonstrated.
                    'owner_password' => getenv('DEMO_OWNER_PASSWORD') ?: 'demo1234',
                ]);
                $tenant = $result['tenant'];

                if ($status !== 'trial') {
                    // Paid subscription with a real invoice trail.
                    $subscriptions->activate($tenant, $plan, $cycle, false, [
                        'period_start' => Clock::addDays(Clock::now(), -$age % 30),
                    ]);

                    $subscription = $subscriptions->forTenant($tenant->id()) ?? [];
                    $invoice      = $invoices->createForSubscription($subscription, [
                        'issued_at' => Clock::addDays(Clock::now(), -($age % 30)),
                    ]);

                    if ($invoice !== []) {
                        $invoiceId = (int) $invoice['id'];
                        $dueAt     = Clock::addDays(Clock::now(), -($age % 30) + 14);

                        if ($status === 'active') {
                            $invoices->markPaid($invoiceId, (float) $invoice['total'], 'card');
                        } else {
                            // Overdue states keep the invoice open and in the past.
                            $conn->prepare('UPDATE invoices SET status = ?, due_at = ?, issued_at = ? WHERE id = ?')
                                ->execute(['past_due', Clock::addDays(Clock::now(), -12), Clock::addDays(Clock::now(), -26), $invoiceId]);
                            $repo->update($tenant->id(), [
                                'status'       => $status,
                                'suspended_at' => $status === 'suspended' ? Clock::addDays(Clock::now(), -9) : null,
                            ]);
                        }
                    }

                    if ($status === 'cancelled') {
                        $subscriptions->cancel($tenant, true, 'Consolidated into a group account');
                    }
                    if ($status === 'suspended') {
                        $repo->update($tenant->id(), [
                            'status'           => 'suspended',
                            'suspended_at'     => Clock::addDays(Clock::now(), -9),
                            'suspended_reason' => $definition['suspended_reason'] ?? 'Payment overdue',
                        ]);
                    }
                }

                // Meter whatever trading history this tenant actually has.
                \Resto\Billing\UsageService::backfill($tenant, (bool) ($definition['demo'] ?? false));

                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                error_log('[seed] tenant ' . $definition['slug'] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    },
];
