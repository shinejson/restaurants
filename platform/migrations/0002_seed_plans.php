<?php
/**
 * Seed the four standard plans.
 *
 * Limits are per billing period; null/-1 means unlimited. Feature keys come
 * from Resto\Billing\Features::CATALOG.
 */
return [
    'up' => static function (PDO $conn, string $driver): void {
        $plans = [
            [
                'code' => 'starter', 'name' => 'Starter', 'tagline' => 'For a single café or food truck',
                'description' => 'Everything you need to start taking orders online. One location, one counter, no fluff.',
                'price_monthly' => 29, 'price_yearly' => 290, 'trial_days' => 14, 'sort_order' => 1,
                'badge' => null, 'accent_color' => '#0ea5e9',
                'limits' => ['staff_users' => 3, 'menu_items' => 60, 'orders' => 500, 'tables' => 10, 'locations' => 1, 'storage_mb' => 512, 'api_calls' => 0, 'email_sends' => 500],
                'features' => ['online_ordering' => true, 'pos' => true, 'table_service' => true, 'delivery' => true, 'reports' => true, 'customers_crm' => true, 'tax_engine' => true, 'staff_roles' => true],
            ],
            [
                'code' => 'growth', 'name' => 'Growth', 'tagline' => 'For busy restaurants with a team',
                'description' => 'Adds events, printing, deeper reporting and more staff seats. Our most popular plan.',
                'price_monthly' => 79, 'price_yearly' => 790, 'trial_days' => 14, 'sort_order' => 2,
                'badge' => 'Most popular', 'accent_color' => '#6366f1',
                'limits' => ['staff_users' => 15, 'menu_items' => 400, 'orders' => 5000, 'tables' => 60, 'locations' => 2, 'storage_mb' => 4096, 'api_calls' => 5000, 'email_sends' => 5000],
                'features' => ['online_ordering' => true, 'pos' => true, 'table_service' => true, 'delivery' => true, 'events' => true, 'reports' => true, 'customers_crm' => true, 'printing' => true, 'tax_engine' => true, 'staff_roles' => true, 'multi_location' => true],
            ],
            [
                'code' => 'pro', 'name' => 'Pro', 'tagline' => 'For multi-site groups that need integrations',
                'description' => 'API access, custom domain, white-label receipts and priority support for growing groups.',
                'price_monthly' => 199, 'price_yearly' => 1990, 'trial_days' => 21, 'sort_order' => 3,
                'badge' => null, 'accent_color' => '#8b5cf6',
                'limits' => ['staff_users' => 60, 'menu_items' => -1, 'orders' => 25000, 'tables' => -1, 'locations' => 10, 'storage_mb' => 20480, 'api_calls' => 50000, 'email_sends' => 25000],
                'features' => ['online_ordering' => true, 'pos' => true, 'table_service' => true, 'delivery' => true, 'events' => true, 'reports' => true, 'customers_crm' => true, 'printing' => true, 'tax_engine' => true, 'staff_roles' => true, 'multi_location' => true, 'api_access' => true, 'custom_domain' => true, 'white_label' => true, 'priority_support' => true],
            ],
            [
                'code' => 'enterprise', 'name' => 'Enterprise', 'tagline' => 'Franchise and hospitality groups',
                'description' => 'Unlimited seats and locations, SSO-ready, dedicated onboarding and a named success manager.',
                'price_monthly' => 499, 'price_yearly' => 4990, 'trial_days' => 30, 'sort_order' => 4,
                'badge' => 'Custom', 'accent_color' => '#f59e0b',
                'limits' => ['staff_users' => -1, 'menu_items' => -1, 'orders' => -1, 'tables' => -1, 'locations' => -1, 'storage_mb' => -1, 'api_calls' => -1, 'email_sends' => -1],
                'features' => ['online_ordering' => true, 'pos' => true, 'table_service' => true, 'delivery' => true, 'events' => true, 'reports' => true, 'customers_crm' => true, 'printing' => true, 'tax_engine' => true, 'staff_roles' => true, 'multi_location' => true, 'api_access' => true, 'custom_domain' => true, 'white_label' => true, 'priority_support' => true],
            ],
        ];

        $exists = $conn->prepare('SELECT COUNT(*) FROM plans WHERE code = ?');
        $insert = $conn->prepare(
            'INSERT INTO plans (code, name, tagline, description, price_monthly, price_yearly, currency, trial_days,
                                is_public, badge, accent_color, limits, features, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
        );

        $now = gmdate('Y-m-d H:i:s');

        foreach ($plans as $plan) {
            $exists->execute([$plan['code']]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }
            $insert->execute([
                $plan['code'],
                $plan['name'],
                $plan['tagline'],
                $plan['description'],
                $plan['price_monthly'],
                $plan['price_yearly'],
                'USD',
                $plan['trial_days'],
                $plan['badge'],
                $plan['accent_color'],
                json_encode($plan['limits']),
                json_encode($plan['features']),
                $plan['sort_order'],
                $now,
                $now,
            ]);
        }
    },
];
