<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php
    // Default SEO values if not set by individual pages
    $page_title = $page_title ?? 'Food Ordering System - Delicious Meals Delivered';
    $page_description = $page_description ?? 'Order delicious food online from our restaurant. Fresh ingredients, fast delivery, and a wide variety of meals to choose from.';
    $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    ?>

    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <link rel="canonical" href="<?php echo $current_url; ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $current_url; ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:image" content="<?php echo BASE_URL; ?>/assets/images/og-image.jpg">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo $current_url; ?>">
    <meta property="twitter:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="twitter:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="twitter:image" content="<?php echo BASE_URL; ?>/assets/images/og-image.jpg">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon"
        href="<?php echo BASE_URL . '/' . get_setting('site_favicon', 'assets/images/favicon/favicon.ico'); ?>">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    <script>
        const BASE_URL = '<?php echo BASE_URL; ?>';
    </script>

    <?php
    $hasThemeCustomization = function_exists('feature_enabled') && feature_enabled('theme_customization');
    $primaryColor = $hasThemeCustomization ? get_setting('theme_primary_color') : '';
    $secondaryColor = $hasThemeCustomization ? get_setting('theme_secondary_color') : '';
    $cardRadius = $hasThemeCustomization ? get_setting('theme_card_radius') : '';
    if ($primaryColor || $secondaryColor || $cardRadius):
    ?>
    <style id="tenant-theme-variables">
        :root {
            <?php if ($primaryColor): ?>--primary-color: <?php echo htmlspecialchars($primaryColor); ?>;<?php endif; ?>
            <?php if ($secondaryColor): ?>--secondary-color: <?php echo htmlspecialchars($secondaryColor); ?>;<?php endif; ?>
            <?php if ($cardRadius): ?>--card-radius: <?php echo htmlspecialchars($cardRadius); ?>;<?php endif; ?>
        }
    </style>
    <?php endif; ?>

    <?php
    $hasCustomCss = function_exists('feature_enabled') && feature_enabled('custom_css');
    $customCssRules = $hasCustomCss ? get_setting('custom_css_rules') : '';
    if (!empty($customCssRules)):
    ?>
    <style id="tenant-custom-css">
        <?php echo strip_tags($customCssRules); ?>
    </style>
    <?php endif; ?>

</head>

<body>
    <?php
    $hasBanner = function_exists('feature_enabled') && feature_enabled('announcement_banner');
    $bannerEnabled = $hasBanner ? get_setting('promo_banner_enabled', '0') : '0';
    $bannerText = $hasBanner ? get_setting('promo_banner_text', '') : '';
    $bannerCtaText = $hasBanner ? get_setting('promo_banner_cta_text', '') : '';
    $bannerCtaLink = $hasBanner ? get_setting('promo_banner_cta_link', '') : '';

    if ($bannerEnabled === '1' && !empty($bannerText)):
        $ctaUrl = '';
        if (!empty($bannerCtaLink)) {
            $ctaUrl = (strpos($bannerCtaLink, 'http://') === 0 || strpos($bannerCtaLink, 'https://') === 0 || strpos($bannerCtaLink, '#') === 0)
                ? $bannerCtaLink
                : tenant_url($bannerCtaLink);
        }
    ?>
    <div class="announcement-bar">
        <div class="container announcement-bar-content">
            <span class="announcement-text"><i class="fas fa-bullhorn"></i> <?php echo htmlspecialchars($bannerText); ?></span>
            <?php if (!empty($bannerCtaText) && !empty($ctaUrl)): ?>
                <a href="<?php echo htmlspecialchars($ctaUrl); ?>" class="announcement-cta"><?php echo htmlspecialchars($bannerCtaText); ?> &rarr;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <!-- Header -->
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                                                <a href="<?php echo tenant_url(); ?>" class="logo">
                    <i class="fas fa-utensils"></i> <?php echo htmlspecialchars(get_setting('company_name', 'Restaurant')); ?>
                </a>

                <button class="menu-toggle" aria-label="Toggle Navigation">
                    <i class="fas fa-bars"></i>
                </button>

                <nav class="main-nav">
                    <ul>
                        <li><a href="<?php echo tenant_url('index.php'); ?>"><i class="fas fa-home"></i> Home</a></li>
                        <li><a href="<?php echo tenant_url('menu.php'); ?>"><i class="fas fa-book-open"></i> Menu</a></li>
                        <li><a href="<?php echo tenant_url('events.php'); ?>"><i class="fas fa-calendar-alt"></i> Events</a>
                        </li>
                        <li><a href="<?php echo tenant_url('contact.php'); ?>"><i class="fas fa-phone"></i> Contact</a>
                        </li>

                        <?php if (isset($_SESSION['user_id'])): ?>
                            <li><a href="<?php echo tenant_url('profile.php'); ?>"><i class="fas fa-user-circle"></i> Profile</a>
                            </li>
                            <li><a href="<?php echo tenant_url('auth/logout.php'); ?>"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </li>
                        <?php elseif (isset($_SESSION['admin_id'])): ?>
                            <li><a href="<?php echo tenant_url('admin/dashboard.php'); ?>"><i class="fas fa-user-shield"></i>
                                    Admin Panel</a></li>
                        <?php else: ?>
                            <li><a href="<?php echo tenant_url('auth/register.php'); ?>"><i class="fas fa-user-plus"></i>
                                    Register</a></li>
                            <li class="nav-signin-item">
                                <a href="<?php echo tenant_url('auth/login.php'); ?>" class="nav-signin-btn">
                                    <i class="fas fa-sign-in-alt"></i> Sign In
                                </a>
                            </li>
                        <?php endif; ?>

                        <li>
                            <a href="<?php echo tenant_url('cart.php'); ?>" class="cart-icon">
                                <i class="fas fa-shopping-cart"></i>
                                <span class="cart-count" id="cartCount">
                                    <?php echo isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0; ?>
                                </span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </header>
    <main>