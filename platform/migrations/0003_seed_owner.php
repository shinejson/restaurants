<?php
/**
 * Seed the first platform (superadmin) account and the default settings.
 *
 * Credentials come from PLATFORM_OWNER_EMAIL / PLATFORM_OWNER_PASSWORD, with a
 * documented development fallback so a fresh clone can sign in immediately.
 */
return [
    'up' => static function (PDO $conn, string $driver): void {
        $now   = gmdate('Y-m-d H:i:s');
        $count = (int) $conn->query('SELECT COUNT(*) FROM platform_users')->fetchColumn();

        if ($count === 0) {
            $email    = getenv('PLATFORM_OWNER_EMAIL') ?: 'owner@restaurantos.test';
            $password = getenv('PLATFORM_OWNER_PASSWORD') ?: 'SuperAdmin123!';
            $name     = getenv('PLATFORM_OWNER_NAME') ?: 'Platform Owner';

            $conn->prepare(
                'INSERT INTO platform_users (name, email, password_hash, role, status, job_title, avatar_color, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $name,
                strtolower($email),
                password_hash($password, PASSWORD_DEFAULT),
                'owner',
                'active',
                'Founder',
                '#6366f1',
                $now,
                $now,
            ]);
        }

        // A support account, handy for demoing role restrictions.
        $supportEmail = getenv('PLATFORM_SUPPORT_EMAIL') ?: 'support@restaurantos.test';
        $stmt = $conn->prepare('SELECT COUNT(*) FROM platform_users WHERE email = ?');
        $stmt->execute([$supportEmail]);
        if ((int) $stmt->fetchColumn() === 0) {
            $conn->prepare(
                'INSERT INTO platform_users (name, email, password_hash, role, status, job_title, avatar_color, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'Support Agent',
                strtolower($supportEmail),
                password_hash(getenv('PLATFORM_SUPPORT_PASSWORD') ?: 'Support123!', PASSWORD_DEFAULT),
                'support',
                'active',
                'Customer support',
                '#0ea5e9',
                $now,
                $now,
            ]);
        }

        /* -------- platform settings -------- */
        $settings = [
            ['platform_name', 'RestaurantOS', 'brand'],
            ['support_email', getenv('PLATFORM_SUPPORT_EMAIL') ?: 'support@restaurantos.test', 'brand'],
            ['default_currency', 'USD', 'billing'],
            ['default_trial_days', getenv('DEFAULT_TRIAL_DAYS') ?: '14', 'billing'],
            ['default_plan', 'growth', 'billing'],
            ['invoice_prefix', 'INV', 'billing'],
            ['invoice_due_days', '14', 'billing'],
            ['tax_rate', '0', 'billing'],
            ['past_due_grace_days', '7', 'billing'],
            ['auto_suspend', '1', 'billing'],
            ['signup_enabled', '1', 'access'],
            ['maintenance_mode', '0', 'access'],
            ['maintenance_message', 'We are performing scheduled maintenance and will be back shortly.', 'access'],
            ['announcement_banner', '', 'brand'],
            ['new_tenant_notifications', '1', 'notifications'],
            ['brand_accent', '#6366f1', 'brand'],
        ];

        $insert = $conn->prepare('SELECT COUNT(*) FROM platform_settings WHERE setting_key = ?');
        $write  = $conn->prepare('INSERT INTO platform_settings (setting_key, setting_value, setting_group, updated_at) VALUES (?, ?, ?, ?)');

        foreach ($settings as [$key, $value, $group]) {
            $insert->execute([$key]);
            if ((int) $insert->fetchColumn() === 0) {
                $write->execute([$key, (string) $value, $group, $now]);
            }
        }
    },
];
