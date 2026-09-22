<?php
/**
 * Public routes for prospective tenants and landing page interactions.
 *
 * GET  /api/v1/public/check-slug  → verify tenant slug availability
 * GET  /api/v1/public/plans       → active public plans & pricing
 * POST /api/v1/public/register    → self-service tenant onboarding & provisioning
 * POST /api/v1/public/contact     → submit an inquiry / contact sales
 *
 * @package Resto\Api
 */

declare(strict_types=1);

use Resto\Billing\PlanRepository;
use Resto\Database\Manager;
use Resto\Http\ApiException;
use Resto\Http\Middleware;
use Resto\Http\Request;
use Resto\Http\Response;
use Resto\Http\Router;
use Resto\Http\Validator;
use Resto\Platform\Audit;
use Resto\Platform\Notifications;
use Resto\Platform\Settings;
use Resto\Support\Clock;
use Resto\Support\Str;
use Resto\Tenancy\Links;
use Resto\Tenancy\Provisioner;
use Resto\Tenancy\Tenant;
use Resto\Tenancy\TenantCode;
use Resto\Tenancy\TenantRepository;

return static function (Router $router): void {

    $router->group('/api/v1/public', [Middleware::platformContext()], static function (Router $router): void {

        /* -------------------------------------------------------------------------
         * Check Slug Availability
         * ---------------------------------------------------------------------- */
        $router->get('/check-slug', static function (Request $request): Response {
            $raw = trim($request->string('slug'));
            $slug = Str::slug($raw, 48);

            if ($slug === '' || strlen($slug) < 2) {
                return Response::json([
                    'available' => false,
                    'slug'      => $slug,
                    'reason'    => 'Slug must be at least 2 characters long.',
                ]);
            }

            // Reserved slugs that clash with core paths
            $reserved = [
                'admin', 'superadmin', 'api', 'auth', 'assets', 'platform', 'login', 'logout',
                'orders', 'profile', 'landing', 'checkout', 'cart', 'events', 'menu', 'about',
                'contact', 't', 'img', 'storage', 'install', 'setup', 'dashboard', 'public',
                'restaurants', 'restaurant', 'resto', 'demo', 'app', 'system', 'root',
            ];

            if (in_array(strtolower($slug), $reserved, true)) {
                return Response::json([
                    'available' => false,
                    'slug'      => $slug,
                    'reason'    => 'That name is reserved by the platform. Please try another.',
                ]);
            }

            $repo = new TenantRepository();
            if ($repo->slugExists($slug)) {
                return Response::json([
                    'available' => false,
                    'slug'      => $slug,
                    'reason'    => 'That restaurant link is already taken. Try a different name.',
                ]);
            }

            return Response::json([
                'available' => true,
                'slug'      => $slug,
                'reason'    => null,
            ]);
        });

        /* -------------------------------------------------------------------------
         * Public Plans Catalog
         * ---------------------------------------------------------------------- */
        $router->get('/plans', static function (): Response {
            $plansRepo = new PlanRepository();
            $plans = $plansRepo->all(false); // active unarchived plans only

            $data = array_map(static function ($plan) {
                return [
                    'id'            => $plan->id(),
                    'code'          => $plan->code(),
                    'name'          => $plan->name(),
                    'tagline'       => (string) ($plan->tagline ?? ''),
                    'description'   => (string) ($plan->description ?? ''),
                    'price_monthly' => (float) $plan->priceFor('monthly'),
                    'price_yearly'  => (float) $plan->priceFor('yearly'),
                    'currency'      => $plan->currency(),
                    'trial_days'    => (int) $plan->trialDays(),
                    'badge'         => $plan->badge,
                    'accent_color'  => $plan->accent_color,
                    'features'      => $plan->features(),
                    'limits'        => $plan->limits(),
                ];
            }, $plans);

            return Response::json([
                'data' => $data,
                'meta' => [
                    'currency'   => Settings::get('default_currency', 'USD'),
                    'trial_days' => (int) Settings::get('default_trial_days', '14'),
                ],
            ]);
        });

        /* -------------------------------------------------------------------------
         * Self-Service Tenant Registration
         * ---------------------------------------------------------------------- */
        $router->post('/register', static function (Request $request): Response {
            // Check if self-registration is enabled in superadmin settings
            $signupEnabled = Settings::get('signup_enabled', '1');
            if ($signupEnabled !== '1' && $signupEnabled !== 'true' && $signupEnabled !== true) {
                throw ApiException::forbidden('Public registrations are currently closed. Please contact support.');
            }

            $validator = Validator::make($request->all(), [
                'name'        => 'required|min:2|max:120',
                'owner_name'  => 'required|min:2|max:120',
                'owner_email' => 'required|email',
                'password'    => 'required|min:8|max:200',
            ]);
            $validator->validateOrFail();

            $repo = new TenantRepository();
            $email = strtolower(trim($request->string('owner_email')));
            if ($repo->emailExists($email)) {
                throw ApiException::conflict('An account with that email address already exists. Please sign in or use another email.');
            }

            // Slug generation or validation
            $rawSlug = trim($request->string('slug'));
            $slug = $rawSlug !== '' ? Str::slug($rawSlug, 48) : Str::uniqueSlug($request->string('name'), fn (string $c) => $repo->slugExists($c));

            if (strlen($slug) < 2) {
                throw ApiException::invalid(['slug' => ['Restaurant link must be at least 2 characters.']]);
            }

            if ($repo->slugExists($slug)) {
                throw ApiException::conflict('The restaurant link "' . $slug . '" is already taken. Please choose another.');
            }

            // Resolve selected plan or default
            $plansRepo = new PlanRepository();
            $planId = $request->int('plan_id');
            $plan = $planId ? $plansRepo->find($planId) : null;
            if ($plan === null) {
                $defaultCode = (string) Settings::get('default_plan', 'growth');
                $plan = $plansRepo->findByCode($defaultCode) ?? $plansRepo->default();
            }

            $cycle = $request->string('billing_cycle', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
            $trialDays = (int) Settings::get('default_trial_days', (string) ($plan ? $plan->trialDays() : 14));
            $currency = strtoupper(trim($request->string('currency', (string) Settings::get('default_currency', 'USD'))));

            // Create tenant record
            $tenant = $repo->create([
                'name'          => trim($request->string('name')),
                'slug'          => $slug,
                'owner_name'    => trim($request->string('owner_name')),
                'owner_email'   => $email,
                'owner_phone'   => trim($request->string('owner_phone')),
                'city'          => trim($request->string('city')),
                'country'       => trim($request->string('country')),
                'currency'      => $currency,
                'plan_id'       => $plan ? $plan->id() : null,
                'billing_cycle' => $cycle,
                'status'        => 'trial',
                'created_by'    => 'public_signup',
            ]);

            // Provision private database, schema, starter content, and owner admin account
            $provisionResult = Provisioner::provision($tenant, [
                'demo'           => true,
                'plan_id'        => $plan ? $plan->id() : null,
                'trial_days'     => $trialDays,
                'owner_password' => $request->string('password'),
            ]);

            // Re-fetch fresh tenant with access code
            $freshTenant = $repo->find($tenant->id(), true) ?? $tenant;

            // Notify superadmins
            if (Settings::get('new_tenant_notifications', '1') === '1') {
                (new Notifications())->push([
                    'type'      => 'tenant.signup',
                    'level'     => 'info',
                    'title'     => 'New Restaurant Joined: ' . $freshTenant->name(),
                    'body'      => sprintf('%s signed up on the %s plan (Code: %s).', $freshTenant->get('owner_name'), $plan ? $plan->name() : 'Standard', $freshTenant->accessCode()),
                    'tenant_id' => $freshTenant->id(),
                ]);
            }

            Audit::record([
                'action'      => 'tenant.self_signup',
                'tenant_id'   => $freshTenant->id(),
                'target_type' => 'tenant',
                'target_id'   => (string) $freshTenant->id(),
                'description' => sprintf('Public signup: %s (%s)', $freshTenant->name(), $freshTenant->slug()),
                'severity'    => 'info',
            ]);

            return Response::json([
                'success' => true,
                'message' => 'Congratulations! Your restaurant workspace is ready.',
                'tenant'  => [
                    'id'             => $freshTenant->id(),
                    'name'           => $freshTenant->name(),
                    'slug'           => $freshTenant->slug(),
                    'access_code'    => $freshTenant->accessCode(),
                    'storefront_url' => Links::storefront($freshTenant, ''),
                    'admin_url'      => Links::admin($freshTenant),
                    'code_url'       => Links::byCode($freshTenant),
                    'owner_email'    => $freshTenant->get('owner_email'),
                    'admin_username' => $provisionResult['admin_username'] ?? 'admin',
                ],
            ]);
        }, [Middleware::throttle('register', 10)]);

        /* -------------------------------------------------------------------------
         * Contact & Sales Inquiry Submission
         * ---------------------------------------------------------------------- */
        $router->post('/contact', static function (Request $request): Response {
            $validator = Validator::make($request->all(), [
                'name'    => 'required|min:2|max:100',
                'email'   => 'required|email',
                'message' => 'required|min:10|max:2000',
            ]);
            $validator->validateOrFail();

            $name    = trim($request->string('name'));
            $email   = trim($request->string('email'));
            $subject = trim($request->string('subject', 'General Inquiry'));
            $message = trim($request->string('message'));
            $resto   = trim($request->string('restaurant', ''));

            // Record notification for superadmins
            (new Notifications())->push([
                'type'  => 'lead.contact',
                'level' => 'info',
                'title' => 'New Contact Message from ' . $name . ($resto !== '' ? " ($resto)" : ''),
                'body'  => "Subject: $subject\nEmail: $email\n\n$message",
            ]);

            Audit::record([
                'action'      => 'lead.contact_form',
                'description' => sprintf('Contact inquiry from %s (%s): %s', $name, $email, $subject),
                'severity'    => 'info',
            ]);

            return Response::ok([
                'message' => 'Thank you for reaching out! Our team will get back to you shortly.',
            ]);
        }, [Middleware::throttle('contact', 10)]);

    });
};
