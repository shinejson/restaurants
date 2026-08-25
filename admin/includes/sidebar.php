<?php
// admin/includes/sidebar.php
require_once __DIR__ . '/../../includes/permissions.php';
?>
<div class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-header"
        style="padding: 0 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; gap: 1rem;">
        <i class="fas fa-utensils"></i>
        <div class="sidebar-header-text">
            <h2 style="margin: 0; font-size: 1.2rem; white-space: nowrap;">FoodExpress</h2>
            <p style="margin: 0; opacity: 0.7; font-size: 0.8rem;">Admin Panel</p>
        </div>
        <button id="closeSidebar" class="mobile-only"
            style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; display: none;">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <nav>
        <ul style="list-style: none; padding: 0;">
            <!-- Analytics Dashboards (Matching requested images) -->
            <li class="sidebar-label"
                style="padding: 1rem 1.5rem 0.5rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.5;">
                Analytics</li>
            <li
                class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && (!isset($_GET['view']) || $_GET['view'] == 'overview')) ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=overview">
                    <i class="fas fa-home"></i>
                    <span class="menu-text">Overview</span>
                </a>
            </li>
            <li
                class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && isset($_GET['view']) && $_GET['view'] == 'sales') ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=sales">
                    <i class="fas fa-dollar-sign"></i>
                    <span class="menu-text">Sales Analysis</span>
                </a>
            </li>
            <li
                class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && isset($_GET['view']) && $_GET['view'] == 'customers') ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=customers">
                    <i class="fas fa-users"></i>
                    <span class="menu-text">Customers Analysis</span>
                </a>
            </li>
            <li
                class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && isset($_GET['view']) && $_GET['view'] == 'products') ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=products">
                    <i class="fas fa-box"></i>
                    <span class="menu-text">Products Analysis</span>
                </a>
            </li>


            <!-- Orders - All roles -->
            <?php if (has_permission('manage_orders')): ?>
                <li class="<?php echo strpos($_SERVER['PHP_SELF'], '/orders/') !== false ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/orders/index.php">
                        <i class="fas fa-shopping-bag"></i>
                        <span class="menu-text">Orders</span>
                    </a>
                </li>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'take_order.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/orders/take_order.php">
                        <i class="fas fa-cash-register"></i>
                        <span class="menu-text">Take Order</span>
                    </a>
                </li>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'kiosk.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/orders/kiosk.php">
                        <i class="fas fa-tablet-alt"></i>
                        <span class="menu-text">Cashier Kiosk</span>
                    </a>
                </li>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'table_map.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/orders/table_map.php">
                        <i class="fas fa-map-marked-alt"></i>
                        <span class="menu-text">Table Map</span>
                    </a>
                </li>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'cleaning_log.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/orders/cleaning_log.php">
                        <i class="fas fa-broom"></i>
                        <span class="menu-text">Cleaning Log</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Events - Manager & Admin only -->
            <?php if (has_permission('manage_events')): ?>
                <li class="sidebar-label"
                    style="padding: 1.5rem 1.5rem 0.5rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.5;">
                    Event Management
                </li>
                <li
                    class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['REQUEST_URI'], 'events') !== false ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/events/index.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="menu-text">Calendar & Bookings</span>
                    </a>
                </li>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_events.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/events/manage_events.php">
                        <i class="fas fa-calendar-plus"></i>
                        <span class="menu-text">Manage Events</span>
                    </a>
                </li>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_packages.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/events/manage_packages.php">
                        <i class="fas fa-box"></i>
                        <span class="menu-text">Manage Packages</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Menu Items - Manager & Admin only -->
            <?php if (has_permission('manage_menu')): ?>
                <li class="<?php echo strpos($_SERVER['PHP_SELF'], '/items/') !== false ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/items/">
                        <i class="fas fa-hamburger"></i>
                        <span class="menu-text">Menu Items</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Categories - Manager & Admin only -->
            <?php if (has_permission('manage_categories')): ?>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_categories.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/manage_categories.php">
                        <i class="fas fa-list"></i>
                        <span class="menu-text">Categories</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Reports - Manager & Admin only -->
            <?php if (has_permission('view_reports')): ?>
                <li class="<?php echo strpos($_SERVER['PHP_SELF'], '/reports/') !== false ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/reports/index.php">
                        <i class="fas fa-chart-line"></i>
                        <span class="menu-text">Reports</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Import Data - Admin only -->
            <?php if (has_permission('import_data')): ?>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'import.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/import.php">
                        <i class="fas fa-file-import"></i>
                        <span class="menu-text">Import Data</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- User Management Section - Only if user has access to any of these -->
            <?php if (has_permission('manage_customers') || has_permission('manage_admins')): ?>
                <li class="sidebar-label"
                    style="padding: 1.5rem 1.5rem 0.5rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.5;">
                    User Management</li>
            <?php endif; ?>

            <!-- Manage Customers - Manager & Admin only -->
            <?php if (has_permission('manage_customers')): ?>
                <li class="<?php echo strpos($_SERVER['PHP_SELF'], '/customers/') !== false ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/customers/index.php">
                        <i class="fas fa-users"></i>
                        <span class="menu-text">Manage Customers</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Manage Admins - Admin only -->
            <?php if (has_permission('manage_admins')): ?>
                <li class="<?php echo strpos($_SERVER['PHP_SELF'], '/admins/') !== false ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/admins/index.php">
                        <i class="fas fa-users-cog"></i>
                        <span class="menu-text">Manage Admins</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Delivery Zones - Admin only -->
            <?php if (has_permission('manage_settings')): ?>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_delivery.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/manage_delivery.php">
                        <i class="fas fa-truck-loading"></i>
                        <span class="menu-text">Delivery Zones</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Settings - Admin only -->
            <?php if (has_permission('manage_settings')): ?>
                <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/settings.php">
                        <i class="fas fa-cog"></i>
                        <span class="menu-text">Settings</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Logout - Always visible -->
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="menu-text">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</div>