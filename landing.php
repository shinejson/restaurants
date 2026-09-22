<?php
/**
 * RestaurantOS — SaaS Marketing Landing Page & Tenant Onboarding Portal.
 *
 * Served at the root platform level (or /landing.php) to showcase platform features,
 * infrastructure, pricing tiers, and allow prospective restaurant owners to self-register
 * and instantly provision their isolated restaurant workspace.
 *
 * @package Resto
 */

declare(strict_types=1);

require_once __DIR__ . '/platform/bootstrap.php';

use Resto\Billing\PlanRepository;
use Resto\Platform\Settings;
use Resto\Tenancy\Links;

$platformName   = (string) Settings::get('platform_name', 'RestaurantOS');
$supportEmail   = (string) Settings::get('support_email', 'support@restaurantos.test');
$signupEnabled  = Settings::get('signup_enabled', '1') === '1';
$defaultCurrency = (string) Settings::get('default_currency', 'USD');
$baseUrl        = Links::baseUrl();
$superadminUrl  = Links::console();
$demoStoreUrl   = $baseUrl . '/t/demo/';

// Fetch active plans for pricing section
try {
    $planRepo = new PlanRepository();
    $plans = $planRepo->all(false);
} catch (Throwable $e) {
    $plans = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($platformName); ?> — Multi-Tenant Restaurant Management & Ordering Platform</title>
    <meta name="description" content="Launch your restaurant's digital storefront, contactless QR menu, and online ordering system with zero commissions in under 60 seconds.">

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl); ?>/assets/css/landing.css">

    <!-- Anti-FOUC Theme Script -->
    <script>
        (function () {
            const stored = localStorage.getItem('resto_theme');
            const theme = stored || (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.documentElement.setAttribute('data-theme', theme);
        })();
        window.RESTAURANT_APP_BASE = <?php echo json_encode($baseUrl); ?>;
    </script>
</head>
<body class="landing-page">

    <!-- Edge-to-Edge Sticky Navigation Header -->
    <header class="landing-header">
        <div class="header-inner">
            <!-- Brand Group -->
            <a href="#home" class="header-brand">
                <span class="brand-icon">
                    <i class="fas fa-utensils"></i>
                </span>
                <span class="brand-text-group">
                    <span class="brand-name"><?php echo htmlspecialchars($platformName); ?></span>
                    <span class="brand-badge">v2.0</span>
                </span>
            </a>

            <!-- Centered Desktop Nav Links -->
            <ul class="nav-links">
                <li><a href="#home" class="nav-link active">Home</a></li>
                <li><a href="#features" class="nav-link">Features</a></li>
                <li><a href="#how-it-works" class="nav-link">How It Works</a></li>
                <li><a href="#pricing" class="nav-link">Pricing</a></li>
                <li><a href="#about" class="nav-link">About</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
            </ul>

            <!-- Header Action Buttons -->
            <div class="header-actions">
                <!-- Theme Toggle Button (Light / Dark) -->
                <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Toggle light/dark theme" title="Toggle Light / Dark mode">
                    <i class="fas fa-sun theme-icon-sun"></i>
                    <i class="fas fa-moon theme-icon-moon"></i>
                </button>

                <!-- Desktop-only Actions -->
                <div class="header-desktop-actions">
                    <button type="button" class="btn btn-ghost btn-sm" id="btnOpenFindModal" title="Find a restaurant with access code or slug">
                        <i class="fas fa-search"></i> <span>Find</span>
                    </button>

                    <a href="<?php echo htmlspecialchars($superadminUrl); ?>" class="btn btn-ghost btn-sm" title="Superadmin and Staff Console">
                        <i class="fas fa-arrow-right-to-bracket"></i> <span>Sign In</span>
                    </a>
                </div>

                <!-- Start Free Trial CTA -->
                <a href="#get-started" class="btn btn-primary btn-sm btn-header-cta">
                    <span>Free Trial</span> <i class="fas fa-arrow-right"></i>
                </a>

                <!-- Mobile Hamburger Button -->
                <button type="button" class="mobile-toggle-btn" id="mobileMenuToggle" aria-label="Open mobile menu">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>

        <!-- Full-Width Mobile Navigation Drawer -->
        <div class="mobile-nav-drawer" id="mobileNavDrawer">
            <ul class="mobile-nav-links">
                <li><a href="#home" class="mobile-nav-link">Home</a></li>
                <li><a href="#features" class="mobile-nav-link">Features & Services</a></li>
                <li><a href="#how-it-works" class="mobile-nav-link">How It Works</a></li>
                <li><a href="#pricing" class="mobile-nav-link">Pricing Plans</a></li>
                <li><a href="#about" class="mobile-nav-link">About RestaurantOS</a></li>
                <li><a href="#contact" class="mobile-nav-link">Contact Sales</a></li>
            </ul>

            <div class="mobile-nav-actions">
                <button type="button" class="btn btn-secondary" id="btnMobileFindModal">
                    <i class="fas fa-search"></i> Find Restaurant by Code
                </button>
                <a href="<?php echo htmlspecialchars($superadminUrl); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-right-to-bracket"></i> Staff / Superadmin Sign In
                </a>
                <a href="#get-started" class="btn btn-primary" id="btnMobileGetStarted">
                    <i class="fas fa-rocket"></i> Start 14-Day Free Trial
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="landing-container">
            <div class="hero-content">
                <div class="badge-pill">
                    <i class="fas fa-bolt"></i> <span>RestaurantOS Multi-Tenant Architecture is Live</span>
                </div>
                <h1 class="hero-title">
                    Launch Your Restaurant's Storefront & Ordering in <span class="text-gradient">Under 60 Seconds</span>
                </h1>
                <p class="hero-lead">
                    Stop paying 30% third-party commission fees. Get your own dedicated ordering link, contactless QR menus, live kitchen display, and complete customer ownership—ready immediately.
                </p>

                <div class="hero-buttons">
                    <a href="#get-started" class="btn btn-primary btn-lg">
                        <i class="fas fa-rocket"></i> Start 14-Day Free Trial
                    </a>
                    <a href="<?php echo htmlspecialchars($demoStoreUrl); ?>" target="_blank" class="btn btn-secondary btn-lg">
                        <i class="fas fa-store"></i> Explore Live Demo Store <i class="fas fa-arrow-up-right-from-square"></i>
                    </a>
                </div>

                <div class="hero-trust-pills">
                    <span><i class="fas fa-check-circle"></i> 0% Commission on Food Sales</span>
                    <span><i class="fas fa-check-circle"></i> Instant Private Database</span>
                    <span><i class="fas fa-check-circle"></i> No Credit Card Required</span>
                </div>
            </div>

            <!-- Product Showcase Mockup -->
            <div class="mockup-wrapper">
                <div class="mockup-header-bar">
                    <div class="mockup-dots">
                        <span></span><span></span><span></span>
                    </div>
                    <div class="mockup-url-bar">
                        <i class="fas fa-lock"></i>
                        <span><?php echo htmlspecialchars(parse_url($baseUrl, PHP_URL_HOST) ?: 'platform.local'); ?>/t/your-restaurant/</span>
                    </div>
                    <div style="font-size:0.75rem; color:var(--accent-emerald); font-weight:700;">
                        <i class="fas fa-circle" style="font-size:0.55rem"></i> LIVE WORKSPACE
                    </div>
                </div>

                <div class="mockup-body">
                    <!-- Left: Customer Ordering Experience -->
                    <div class="mockup-panel">
                        <div class="mockup-panel-title">
                            <span><i class="fas fa-mobile-screen"></i> Customer Digital Menu</span>
                            <span class="badge-pill" style="margin:0; padding:2px 8px; font-size:0.7rem;">Cart (2)</span>
                        </div>
                        <div class="mock-food-cards">
                            <div class="mock-food-card">
                                <div class="mock-food-info">
                                    <div class="mock-food-img">🍔</div>
                                    <div>
                                        <div class="mock-food-name">Artisan Truffle Burger</div>
                                        <div class="mock-food-price">$16.50</div>
                                    </div>
                                </div>
                                <button class="mock-add-btn">+ Add</button>
                            </div>
                            <div class="mock-food-card">
                                <div class="mock-food-info">
                                    <div class="mock-food-img">🍕</div>
                                    <div>
                                        <div class="mock-food-name">Woodfired Margherita</div>
                                        <div class="mock-food-price">$14.00</div>
                                    </div>
                                </div>
                                <button class="mock-add-btn">+ Add</button>
                            </div>
                            <div class="mock-food-card">
                                <div class="mock-food-info">
                                    <div class="mock-food-img">🥗</div>
                                    <div>
                                        <div class="mock-food-name">Mediterranean Bowl</div>
                                        <div class="mock-food-price">$12.50</div>
                                    </div>
                                </div>
                                <button class="mock-add-btn">+ Add</button>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Kitchen Display System (KDS) -->
                    <div class="mockup-panel">
                        <div class="mockup-panel-title">
                            <span><i class="fas fa-fire-burner"></i> Live Kitchen Display (KDS)</span>
                            <span style="font-size:0.75rem; color:#38bdf8;"><i class="fas fa-volume-high"></i> Alerts On</span>
                        </div>
                        <div class="mock-order-list">
                            <div class="mock-order-card">
                                <div class="mock-order-head">
                                    <strong>Order #1042 — Table 4</strong>
                                    <span class="mock-order-badge preparing"><i class="fas fa-clock"></i> Preparing (4m)</span>
                                </div>
                                <div style="font-size:0.85rem; color:var(--text-muted);">
                                    2x Truffle Burger • 1x Sweet Potato Fries (No Salt)
                                </div>
                            </div>
                            <div class="mock-order-card">
                                <div class="mock-order-head">
                                    <strong>Order #1041 — Takeout</strong>
                                    <span class="mock-order-badge ready"><i class="fas fa-check"></i> Ready for Pickup</span>
                                </div>
                                <div style="font-size:0.85rem; color:var(--text-muted);">
                                    1x Woodfired Margherita • 1x Mediterranean Bowl
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trust Stats Bar -->
    <section class="trust-bar">
        <div class="landing-container">
            <div class="stats-grid">
                <div class="stat-item">
                    <h3>99.9%</h3>
                    <p>Platform Uptime SLA</p>
                </div>
                <div class="stat-item">
                    <h3>0%</h3>
                    <p>Order Commission Cuts</p>
                </div>
                <div class="stat-item">
                    <h3>&lt; 60s</h3>
                    <p>Instant Tenant Provisioning</p>
                </div>
                <div class="stat-item">
                    <h3>100%</h3>
                    <p>Dedicated Database Isolation</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features & Platform Services -->
    <section class="section-padding" id="features">
        <div class="landing-container">
            <div class="section-head">
                <div class="badge-pill"><i class="fas fa-sparkles"></i> Comprehensive Platform Features</div>
                <h2>Everything Your Restaurant Needs to Succeed Online</h2>
                <p>From single food trucks to multi-location hospitality groups, RestaurantOS delivers an enterprise-grade digital operating system.</p>
            </div>

            <div class="features-grid">
                <!-- Feature 1 -->
                <div class="feature-card">
                    <div class="feature-icon icon-indigo">
                        <i class="fas fa-link"></i>
                    </div>
                    <h3>Unique Dedicated Links</h3>
                    <p>Every restaurant gets its own dedicated link (<code>/t/{your-slug}/</code>), unique 5-character access code, and support for custom subdomains and domains.</p>
                </div>

                <!-- Feature 2 -->
                <div class="feature-card">
                    <div class="feature-icon icon-cyan">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <h3>Digital QR Menus & Ordering</h3>
                    <p>Generate high-resolution table QR codes. Diners scan and order directly from their phone with allergen tags, modifiers, and instant out-of-stock toggles.</p>
                </div>

                <!-- Feature 3 -->
                <div class="feature-card">
                    <div class="feature-icon icon-green">
                        <i class="fas fa-kitchen-set"></i>
                    </div>
                    <h3>Live Kitchen Display (KDS)</h3>
                    <p>Replace paper tickets with real-time digital kitchen boards featuring audible chime alerts, preparation timers, and order status transitions.</p>
                </div>

                <!-- Feature 4 -->
                <div class="feature-card">
                    <div class="feature-icon icon-amber">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3>Staff Roles & Granular RBAC</h3>
                    <p>Isolate duties safely. Create tailored accounts for Managers, Cashiers, Kitchen Line Cooks, and Delivery Drivers with custom permissions.</p>
                </div>

                <!-- Feature 5 -->
                <div class="feature-card">
                    <div class="feature-icon icon-rose">
                        <i class="fas fa-print"></i>
                    </div>
                    <h3>Receipt & Kitchen Printing</h3>
                    <p>Built-in ESC/POS thermal printer support for automated order tickets, kitchen expedition slips, and customer tax receipts.</p>
                </div>

                <!-- Feature 6 -->
                <div class="feature-card">
                    <div class="feature-icon icon-violet">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3>Events & Table Booking</h3>
                    <p>Host tasting menus, chef masterclasses, and ticketed events with guest management, seating capacity caps, and automatic confirmations.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Flow -->
    <section class="section-padding" id="how-it-works" style="background: rgba(13, 21, 39, 0.3);">
        <div class="landing-container">
            <div class="section-head">
                <div class="badge-pill"><i class="fas fa-route"></i> Simple 3-Step Setup</div>
                <h2>From Sign-Up to First Order in 3 Steps</h2>
                <p>No software installations, no complex manual database configurations. Everything is automated.</p>
            </div>

            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <h3>Claim Your Restaurant Link</h3>
                    <p>Fill out the signup form below with your restaurant name and claim your custom URL (e.g. <code>/t/your-bistro/</code>).</p>
                </div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <h3>Add Your Menu & Branding</h3>
                    <p>Upload your logo, set your business hours, and add your menu items with images, pricing, and category tags.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <h3>Share Link & Take Orders</h3>
                    <p>Print your table QR codes and share your link on Instagram, Google Maps, and your website. Keep 100% of your revenue.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Interactive Pricing Section -->
    <section class="section-padding" id="pricing">
        <div class="landing-container">
            <div class="section-head">
                <div class="badge-pill"><i class="fas fa-tag"></i> Transparent Pricing</div>
                <h2>Simple, Predictable Plans. Zero Hidden Fees.</h2>
                <p>Pick the plan that fits your operation. Every plan includes a 14-day free trial with full feature access.</p>
            </div>

            <div class="billing-switch">
                <span id="monthLabel" class="active">Monthly Billing</span>
                <div class="switch-control" id="billingSwitch" title="Toggle Annual Discount">
                    <div class="switch-slider"></div>
                </div>
                <span id="yearLabel">Annual Billing <span class="save-pill">SAVE 20%</span></span>
            </div>

            <div class="pricing-grid">
                <?php if (!empty($plans)): ?>
                    <?php foreach ($plans as $plan): ?>
                        <?php 
                            $isFeatured = $plan->badge === 'Most popular' || $plan->code === 'growth';
                            $monthlyPrice = (int) $plan->priceFor('monthly');
                            $yearlyPrice  = (int) $plan->priceFor('yearly');
                        ?>
                        <div class="pricing-card <?php echo $isFeatured ? 'featured' : ''; ?>">
                            <?php if ($plan->badge): ?>
                                <div class="pricing-badge"><?php echo htmlspecialchars($plan->badge); ?></div>
                            <?php endif; ?>

                            <h3 class="plan-name"><?php echo htmlspecialchars($plan->name); ?></h3>
                            <p class="plan-desc"><?php echo htmlspecialchars((string) ($plan->tagline ?? $plan->description)); ?></p>

                            <div class="plan-price-wrap">
                                <span class="plan-currency">$</span>
                                <span class="plan-price" data-monthly="<?php echo $monthlyPrice; ?>" data-yearly="<?php echo $yearlyPrice; ?>">
                                    <?php echo $monthlyPrice; ?>
                                </span>
                                <span class="plan-period">/mo</span>
                            </div>

                            <ul class="plan-features-list">
                                <?php 
                                    $limits = $plan->limits();
                                    $staffLimit = $limits['staff_users'] ?? -1;
                                    $itemLimit = $limits['menu_items'] ?? -1;
                                    $orderLimit = $limits['orders'] ?? -1;
                                ?>
                                <li class="plan-feature-item">
                                    <i class="fas fa-check"></i>
                                    <span><?php echo $staffLimit == -1 ? 'Unlimited' : $staffLimit; ?> Staff Accounts</span>
                                </li>
                                <li class="plan-feature-item">
                                    <i class="fas fa-check"></i>
                                    <span><?php echo $itemLimit == -1 ? 'Unlimited' : $itemLimit; ?> Menu Items</span>
                                </li>
                                <li class="plan-feature-item">
                                    <i class="fas fa-check"></i>
                                    <span><?php echo $orderLimit == -1 ? 'Unlimited' : number_format((float) $orderLimit); ?> Orders / month</span>
                                </li>
                                <li class="plan-feature-item">
                                    <i class="fas fa-check"></i>
                                    <span>Contactless QR & Digital Ordering</span>
                                </li>
                                <li class="plan-feature-item">
                                    <i class="fas fa-check"></i>
                                    <span>Kitchen Display & Sound Alerts</span>
                                </li>
                                <?php if ($plan->hasFeature('printing')): ?>
                                <li class="plan-feature-item">
                                    <i class="fas fa-check"></i>
                                    <span>Thermal Receipt & Kitchen Printing</span>
                                </li>
                                <?php endif; ?>
                                <?php if ($plan->hasFeature('custom_domain')): ?>
                                <li class="plan-feature-item">
                                    <i class="fas fa-check"></i>
                                    <span>Custom Domain Connection</span>
                                </li>
                                <?php endif; ?>
                            </ul>

                            <button type="button" class="btn <?php echo $isFeatured ? 'btn-primary' : 'btn-secondary'; ?> btn-select-plan"
                                    data-plan-id="<?php echo $plan->id(); ?>" 
                                    data-plan-name="<?php echo htmlspecialchars($plan->name); ?>">
                                <span>Start 14-Day Free Trial</span> <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Fallback Static Cards if plans table not loaded -->
                    <div class="pricing-card">
                        <h3 class="plan-name">Starter</h3>
                        <p class="plan-desc">For cafés, food trucks, and single counters.</p>
                        <div class="plan-price-wrap">
                            <span class="plan-currency">$</span>
                            <span class="plan-price" data-monthly="29" data-yearly="290">29</span>
                            <span class="plan-period">/mo</span>
                        </div>
                        <ul class="plan-features-list">
                            <li class="plan-feature-item"><i class="fas fa-check"></i> 3 Staff Accounts</li>
                            <li class="plan-feature-item"><i class="fas fa-check"></i> 60 Menu Items</li>
                            <li class="plan-feature-item"><i class="fas fa-check"></i> Digital Menu & Cart</li>
                        </ul>
                        <a href="#get-started" class="btn btn-secondary">Start Free Trial</a>
                    </div>
                    <div class="pricing-card featured">
                        <div class="pricing-badge">Most Popular</div>
                        <h3 class="plan-name">Growth</h3>
                        <p class="plan-desc">For busy restaurants and growing teams.</p>
                        <div class="plan-price-wrap">
                            <span class="plan-currency">$</span>
                            <span class="plan-price" data-monthly="79" data-yearly="790">79</span>
                            <span class="plan-period">/mo</span>
                        </div>
                        <ul class="plan-features-list">
                            <li class="plan-feature-item"><i class="fas fa-check"></i> 15 Staff Accounts</li>
                            <li class="plan-feature-item"><i class="fas fa-check"></i> 400 Menu Items</li>
                            <li class="plan-feature-item"><i class="fas fa-check"></i> Kitchen Printing & KDS</li>
                        </ul>
                        <a href="#get-started" class="btn btn-primary">Start Free Trial</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Tenant Self-Service Onboarding Wizard -->
    <section class="section-padding signup-section" id="get-started">
        <div class="landing-container">
            <div class="section-head">
                <div class="badge-pill"><i class="fas fa-rocket"></i> Instant Registration</div>
                <h2>Create Your Restaurant Workspace</h2>
                <p>Enter your restaurant details below. Your isolated workspace and admin portal will be provisioned in real time.</p>
            </div>

            <div class="signup-box">
                <?php if (!$signupEnabled): ?>
                    <div style="text-align:center; padding: 30px;">
                        <i class="fas fa-lock" style="font-size: 2.5rem; color: var(--accent-amber); margin-bottom: 16px;"></i>
                        <h3 style="margin-bottom: 8px;">Public Signups Are Currently Paused</h3>
                        <p style="color: var(--text-muted); max-width: 450px; margin: 0 auto;">
                            We are onboarding our current cohort of restaurant partners. Please reach out via our contact form below to request an invitation.
                        </p>
                    </div>
                <?php else: ?>
                    <form id="tenantSignupForm">
                        <input type="hidden" id="selectedPlanInput" value="<?php echo !empty($plans) ? $plans[0]->id() : 1; ?>">
                        <input type="hidden" id="billingCycleInput" value="monthly">

                        <div class="signup-form-grid">
                            <!-- Restaurant Details -->
                            <div class="form-group full-col">
                                <label class="form-label" for="restaurantName">Restaurant Name <span class="required">*</span></label>
                                <input type="text" id="restaurantName" class="form-control" placeholder="e.g. Bella Italia Bistro" required>
                            </div>

                            <div class="form-group full-col">
                                <label class="form-label" for="restaurantSlug">Your Dedicated Ordering URL <span class="required">*</span></label>
                                <div class="slug-input-wrap">
                                    <span class="slug-prefix"><?php echo htmlspecialchars(parse_url($baseUrl, PHP_URL_HOST) ?: 'platform.local'); ?>/t/</span>
                                    <input type="text" id="restaurantSlug" placeholder="bella-italia" required autocomplete="off">
                                    <span id="slugStatus" class="slug-status"></span>
                                </div>
                                <small style="color:var(--text-subtle); margin-top:4px;">
                                    This will be your unique online storefront link for customers and QR codes.
                                </small>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="currency">Operating Currency</label>
                                <select id="currency" class="form-control">
                                    <option value="USD" <?php echo $defaultCurrency === 'USD' ? 'selected' : ''; ?>>USD ($) — US Dollar</option>
                                    <option value="EUR" <?php echo $defaultCurrency === 'EUR' ? 'selected' : ''; ?>>EUR (€) — Euro</option>
                                    <option value="GBP" <?php echo $defaultCurrency === 'GBP' ? 'selected' : ''; ?>>GBP (£) — British Pound</option>
                                    <option value="CAD" <?php echo $defaultCurrency === 'CAD' ? 'selected' : ''; ?>>CAD ($) — Canadian Dollar</option>
                                    <option value="AUD" <?php echo $defaultCurrency === 'AUD' ? 'selected' : ''; ?>>AUD ($) — Australian Dollar</option>
                                    <option value="GHS">GHS (₵) — Ghanaian Cedi</option>
                                    <option value="NGN">NGN (₦) — Nigerian Naira</option>
                                    <option value="KES">KES (KSh) — Kenyan Shilling</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="city">City / Location</label>
                                <input type="text" id="city" class="form-control" placeholder="e.g. New York">
                            </div>

                            <!-- Owner Account -->
                            <div class="form-group">
                                <label class="form-label" for="ownerName">Owner Full Name <span class="required">*</span></label>
                                <input type="text" id="ownerName" class="form-control" placeholder="e.g. Marco Rossi" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="ownerEmail">Business Email <span class="required">*</span></label>
                                <input type="email" id="ownerEmail" class="form-control" placeholder="marco@bellaitalia.com" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="ownerPassword">Admin Password <span class="required">*</span></label>
                                <input type="password" id="ownerPassword" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="ownerPhone">Phone Number</label>
                                <input type="tel" id="ownerPhone" class="form-control" placeholder="+1 (555) 000-0000">
                            </div>

                            <div class="form-group full-col" style="margin-top: 10px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.03); padding:12px 18px; border-radius:var(--radius-sm); border:1px solid var(--border-glass);">
                                    <span style="font-size:0.9rem; color:var(--text-muted);">
                                        Selected Plan: <strong id="selectedPlanDisplay" style="color:#ffffff;">Growth</strong> (14-Day Free Trial)
                                    </span>
                                    <a href="#pricing" style="font-size:0.85rem; color:#818cf8; text-decoration:none;">Change Plan</a>
                                </div>
                            </div>

                            <div class="form-group full-col" style="margin-top: 10px;">
                                <button type="submit" id="btnSubmitSignup" class="btn btn-primary btn-lg" style="width:100%;">
                                    <i class="fas fa-rocket"></i> Create Restaurant & Launch Storefront
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Real-Time Provisioning Progress Animation -->
                    <div id="provisionProgress" class="provisioning-progress">
                        <h4 style="margin-bottom:14px; color:#ffffff; font-size:0.95rem;">Setting up your isolated workspace...</h4>
                        <div class="provisioning-step active">
                            <i class="fas fa-spinner fa-spin"></i> <span>1. Allocating private tenant database...</span>
                        </div>
                        <div class="provisioning-step">
                            <i class="far fa-circle"></i> <span>2. Running schema migrations and security tables...</span>
                        </div>
                        <div class="provisioning-step">
                            <i class="far fa-circle"></i> <span>3. Seeding starter menu categories & dish templates...</span>
                        </div>
                        <div class="provisioning-step">
                            <i class="far fa-circle"></i> <span>4. Minting unique access code and credentials...</span>
                        </div>
                    </div>

                    <!-- Instant Provisioning Success Card -->
                    <div id="provisionSuccessCard" class="provision-success-card">
                        <div class="success-icon-wrap">
                            <i class="fas fa-check"></i>
                        </div>
                        <h3 style="color:#ffffff; margin-bottom: 6px; font-size: 1.5rem;">Your Restaurant Is Live!</h3>
                        <p style="color:var(--text-muted); font-size: 0.95rem;">
                            Your database has been created and your storefront is now ready for diners and staff.
                        </p>

                        <div class="tenant-url-boxes">
                            <div class="url-box">
                                <div>
                                    <div class="url-box-label">Public Storefront URL</div>
                                    <div class="url-box-value" id="resStorefrontUrl"></div>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <button class="btn btn-secondary btn-sm btn-copy-url" data-target="resStorefrontUrl"><i class="fas fa-copy"></i> Copy</button>
                                    <a id="resStorefrontLink" href="#" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-arrow-up-right-from-square"></i> Open</a>
                                </div>
                            </div>

                            <div class="url-box">
                                <div>
                                    <div class="url-box-label">Staff & Admin Login URL</div>
                                    <div class="url-box-value" id="resAdminUrl"></div>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <button class="btn btn-secondary btn-sm btn-copy-url" data-target="resAdminUrl"><i class="fas fa-copy"></i> Copy</button>
                                    <a id="resAdminLink" href="#" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-arrow-up-right-from-square"></i> Open</a>
                                </div>
                            </div>

                            <div class="url-box">
                                <div>
                                    <div class="url-box-label">Restaurant Sign-In Access Code</div>
                                    <div class="url-box-value" id="resAccessCode" style="font-size:1.2rem; letter-spacing:2px; color:#fbbf24;"></div>
                                </div>
                                <div>
                                    <button class="btn btn-secondary btn-sm btn-copy-url" data-target="resAccessCode"><i class="fas fa-copy"></i> Copy</button>
                                </div>
                            </div>
                        </div>

                        <a id="btnEnterAdmin" href="#" class="btn btn-primary btn-lg" style="margin-top:16px;">
                            <i class="fas fa-gauge-high"></i> Enter Restaurant Admin Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="section-padding" id="about">
        <div class="landing-container">
            <div class="about-grid">
                <div>
                    <div class="badge-pill"><i class="fas fa-heart"></i> Our Mission</div>
                    <h2 style="font-size:2.5rem; font-weight:800; line-height:1.2; margin-bottom:20px;">
                        Built to Give Restaurants Their Independence Back
                    </h2>
                    <p style="color:var(--text-muted); font-size:1.05rem; line-height:1.7; margin-bottom:16px;">
                        Third-party delivery apps built billion-dollar businesses by placing themselves between you and your loyal diners, extracting 20% to 35% on every meal.
                    </p>
                    <p style="color:var(--text-muted); font-size:1.05rem; line-height:1.7;">
                        <?php echo htmlspecialchars($platformName); ?> was engineered as an open, fair, multi-tenant digital backbone. You keep 100% of your menu revenue, control your own customer relationships, and run your kitchen with modern cloud technology.
                    </p>

                    <div class="about-features-list">
                        <div class="about-feature-row">
                            <div class="about-feature-icon"><i class="fas fa-shield-halved"></i></div>
                            <div>
                                <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:4px;">100% Data Ownership</h4>
                                <p style="color:var(--text-muted); font-size:0.9rem;">Your customer contact details, order histories, and sales figures belong strictly to you.</p>
                            </div>
                        </div>
                        <div class="about-feature-row">
                            <div class="about-feature-icon"><i class="fas fa-bolt"></i></div>
                            <div>
                                <h4 style="color:#ffffff; font-size:1.1rem; margin-bottom:4px;">Zero Hardware Lock-In</h4>
                                <p style="color:var(--text-muted); font-size:0.9rem;">Works seamlessly on any tablet, iPad, phone, POS browser, or thermal ESC/POS network printer.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="background:var(--bg-card); border:1px solid var(--border-glass); border-radius:var(--radius-lg); padding:40px;">
                    <h3 style="color:#ffffff; font-size:1.4rem; margin-bottom:18px;">Why Choose <?php echo htmlspecialchars($platformName); ?>?</h3>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--border-glass);">
                            <span style="color:var(--text-muted);">Commission per order</span>
                            <span style="color:var(--accent-emerald); font-weight:700;">0% (Free)</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--border-glass);">
                            <span style="color:var(--text-muted);">Setup time</span>
                            <span style="color:#ffffff; font-weight:700;">Under 60 seconds</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--border-glass);">
                            <span style="color:var(--text-muted);">Database architecture</span>
                            <span style="color:#ffffff; font-weight:700;">Isolated per restaurant</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--border-glass);">
                            <span style="color:var(--text-muted);">Kitchen Display (KDS)</span>
                            <span style="color:#38bdf8; font-weight:700;">Included built-in</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:12px 0;">
                            <span style="color:var(--text-muted);">Event & Table Booking</span>
                            <span style="color:#818cf8; font-weight:700;">Included built-in</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact & Enterprise Inquiries -->
    <section class="section-padding" id="contact" style="background: rgba(13, 21, 39, 0.4);">
        <div class="landing-container">
            <div class="section-head">
                <div class="badge-pill"><i class="fas fa-envelope"></i> Get In Touch</div>
                <h2>Questions or Custom Enterprise Setup?</h2>
                <p>Have multiple locations, custom payment integrations, or franchise inquiries? Our hospitality engineers are here to assist.</p>
            </div>

            <div class="contact-grid">
                <div>
                    <h3 style="color:#ffffff; font-size:1.5rem; margin-bottom:12px;">Let's Talk Hospitality Tech</h3>
                    <p style="color:var(--text-muted); line-height:1.7;">
                        Whether you need help importing an existing menu catalog or connecting bespoke hardware, send us a note and we'll reply within a few hours.
                    </p>

                    <div class="contact-info-list">
                        <div class="contact-info-item">
                            <div class="contact-icon"><i class="fas fa-envelope"></i></div>
                            <div>
                                <div style="color:var(--text-subtle); font-size:0.8rem; text-transform:uppercase; font-weight:700;">Email Support</div>
                                <a href="mailto:<?php echo htmlspecialchars($supportEmail); ?>" style="color:#ffffff; font-weight:600;"><?php echo htmlspecialchars($supportEmail); ?></a>
                            </div>
                        </div>
                        <div class="contact-info-item">
                            <div class="contact-icon"><i class="fas fa-server"></i></div>
                            <div>
                                <div style="color:var(--text-subtle); font-size:0.8rem; text-transform:uppercase; font-weight:700;">System Status</div>
                                <span style="color:var(--accent-emerald); font-weight:600;"><i class="fas fa-circle" style="font-size:0.6rem"></i> All Systems Operational</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="contact-card-box">
                    <form id="contactForm">
                        <div style="display:flex; flex-direction:column; gap:16px;">
                            <div class="form-group">
                                <label class="form-label" for="contactName">Your Name <span class="required">*</span></label>
                                <input type="text" id="contactName" class="form-control" placeholder="e.g. Chef Anthony" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="contactEmail">Your Email <span class="required">*</span></label>
                                <input type="email" id="contactEmail" class="form-control" placeholder="anthony@restaurant.com" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="contactRestaurant">Restaurant / Brand Name</label>
                                <input type="text" id="contactRestaurant" class="form-control" placeholder="e.g. Trattoria Nostra">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="contactSubject">Topic</label>
                                <select id="contactSubject" class="form-control">
                                    <option value="General Inquiry">General Inquiry</option>
                                    <option value="Multi-Location / Franchise">Multi-Location / Franchise Group</option>
                                    <option value="Custom Integration">Custom Payment / POS Integration</option>
                                    <option value="Menu Import Assistance">Menu Import Assistance</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="contactMessage">Message <span class="required">*</span></label>
                                <textarea id="contactMessage" class="form-control" rows="4" placeholder="Tell us how we can help..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg" style="margin-top:8px;">
                                <i class="fas fa-paper-plane"></i> Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- "Find My Restaurant" Modal -->
    <div class="modal-overlay" id="modalFindRestaurant">
        <div class="modal-card">
            <button class="modal-close" id="btnCloseFindModal"><i class="fas fa-times"></i></button>
            <div style="text-align:center; margin-bottom:20px;">
                <div class="brand-icon" style="margin:0 auto 12px auto;"><i class="fas fa-store"></i></div>
                <h3 style="color:#ffffff; font-size:1.35rem;">Find Your Restaurant</h3>
                <p style="color:var(--text-muted); font-size:0.9rem;">
                    Enter your restaurant's 5-character Access Code or unique link name to jump straight to your storefront.
                </p>
            </div>
            <form id="formFindRestaurant">
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="inputRestaurantCode">Restaurant Code or Slug</label>
                    <input type="text" id="inputRestaurantCode" class="form-control" placeholder="e.g. K7M2Q or city-diner" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-arrow-right"></i> Go to Restaurant
                </button>
            </form>
        </div>
    </div>

    <!-- Platform Footer -->
    <footer class="landing-footer">
        <div class="landing-container">
            <div class="footer-grid">
                <div class="footer-brand-col">
                    <div class="header-brand">
                        <div class="brand-icon"><i class="fas fa-utensils"></i></div>
                        <span class="brand-name"><?php echo htmlspecialchars($platformName); ?></span>
                    </div>
                    <p>The modern, multi-tenant digital operating system empowering restaurants to sell online commission-free.</p>
                </div>

                <div class="footer-col">
                    <h4>Platform</h4>
                    <ul class="footer-links">
                        <li><a href="#features">Features</a></li>
                        <li><a href="#how-it-works">How It Works</a></li>
                        <li><a href="#pricing">Pricing Plans</a></li>
                        <li><a href="#get-started">Start Free Trial</a></li>
                        <li><a href="<?php echo htmlspecialchars($demoStoreUrl); ?>" target="_blank">Demo Store ↗</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Restaurant Access</h4>
                    <ul class="footer-links">
                        <li><a href="#modalFindRestaurant" id="btnFooterFind">Find by Code</a></li>
                        <li><a href="<?php echo htmlspecialchars($superadminUrl); ?>">Superadmin Console</a></li>
                        <li><a href="mailto:<?php echo htmlspecialchars($supportEmail); ?>">Help & Support</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Company</h4>
                    <ul class="footer-links">
                        <li><a href="#about">About RestaurantOS</a></li>
                        <li><a href="#contact">Contact Sales</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom-bar">
                <div>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($platformName); ?> SaaS Platform. All rights reserved.</div>
                <div>Designed for independent restaurateurs worldwide.</div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="<?php echo htmlspecialchars($baseUrl); ?>/assets/js/landing.js"></script>
    <script>
        // Wire up footer find link to modal
        document.getElementById('btnFooterFind')?.addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('modalFindRestaurant')?.classList.add('active');
            document.getElementById('inputRestaurantCode')?.focus();
        });
    </script>
</body>
</html>
