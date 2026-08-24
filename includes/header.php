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

</head>

<body>
    <!-- Header -->
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <a href="<?php echo BASE_URL; ?>/index.php" class="logo">
                    <i class="fas fa-utensils"></i> Food<span>Express</span>
                </a>

                <button class="menu-toggle" aria-label="Toggle Navigation">
                    <i class="fas fa-bars"></i>
                </button>

                <nav class="main-nav">
                    <ul>
                        <li><a href="<?php echo BASE_URL; ?>/index.php"><i class="fas fa-home"></i> Home</a></li>
                        <li><a href="<?php echo BASE_URL; ?>/menu.php"><i class="fas fa-book-open"></i> Menu</a></li>
                        <li><a href="<?php echo BASE_URL; ?>/events.php"><i class="fas fa-calendar-alt"></i> Events</a>
                        </li>
                        <li><a href="<?php echo BASE_URL; ?>/contact.php"><i class="fas fa-phone"></i> Contact</a>
                        </li>

                        <?php if (isset($_SESSION['user_id'])): ?>
                            <li><a href="<?php echo BASE_URL; ?>/profile.php"><i class="fas fa-user-circle"></i> Profile</a>
                            </li>
                        <?php elseif (isset($_SESSION['admin_id'])): ?>
                            <li><a href="<?php echo BASE_URL; ?>/admin/dashboard.php"><i class="fas fa-user-shield"></i>
                                    Admin Panel</a></li>
                        <?php else: ?>
                            <li><a href="<?php echo BASE_URL; ?>/auth/login.php"><i class="fas fa-sign-in-alt"></i>
                                    Login</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/auth/register.php"><i class="fas fa-user-plus"></i>
                                    Register</a></li>
                        <?php endif; ?>

                        <li>
                            <a href="<?php echo BASE_URL; ?>/cart.php" class="cart-icon">
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