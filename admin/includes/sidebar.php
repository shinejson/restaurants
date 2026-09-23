<?php
// admin/includes/sidebar.php
require_once __DIR__ . '/../../includes/permissions.php';

/* ------------------------------------------------------------------
 * Active-state helpers
 * ------------------------------------------------------------------ */
$current_page = basename($_SERVER['PHP_SELF']);
$script_path  = $_SERVER['PHP_SELF'];
$dash_view    = isset($_GET['view']) ? $_GET['view'] : 'overview';
$admins_tab   = isset($_GET['tab']) ? $_GET['tab'] : '';

$in_orders    = strpos($script_path, '/orders/')    !== false;
$in_events    = strpos($script_path, '/events/')    !== false;
$in_items     = strpos($script_path, '/items/')     !== false;
$in_tax       = strpos($script_path, '/tax/')       !== false;
$in_reports   = strpos($script_path, '/reports/')   !== false;
$in_customers = strpos($script_path, '/customers/') !== false;
$in_admins    = strpos($script_path, '/admins/')    !== false;
$in_printing  = strpos($script_path, '/printing/')  !== false;

/* Detail pages should highlight their parent list item */
$orders_child_page = in_array($current_page, ['take_order.php', 'guest_table_order.php', 'kiosk.php', 'table_map.php', 'cleaning_log.php']);
$events_child_page = in_array($current_page, ['manage_events.php', 'manage_packages.php']);

/* A tab is only rendered when the current role can access
 * at least one of the pages inside its panel. */
$can_see_menu_group     = has_permission('manage_menu') || has_permission('manage_categories') || has_permission('manage_settings') || has_permission('import_data');
$can_see_orders_group   = has_permission('manage_orders') || has_permission('manage_events');
$can_see_settings_group = has_permission('manage_customers') || has_permission('manage_admins') || has_permission('manage_settings');
?>
<div class="admin-sidebar" id="adminSidebar">

    <!-- ===== Top Icon Tabs ===== -->
    <div class="sidebar-tabs" role="tablist">
        <button type="button" class="sidebar-tab active" data-tab="dashboard" title="Dashboard & Reports">
            <i class="fas fa-user"></i>
        </button>
        <?php if ($can_see_menu_group): ?>
            <button type="button" class="sidebar-tab" data-tab="menu" title="Menu Management">
                <i class="fas fa-bars"></i>
            </button>
        <?php endif; ?>
        <?php if ($can_see_orders_group): ?>
            <button type="button" class="sidebar-tab" data-tab="orders" title="Orders & Tables">
                <i class="fas fa-shopping-cart"></i>
            </button>
        <?php endif; ?>
        <?php if ($can_see_settings_group): ?>
            <button type="button" class="sidebar-tab" data-tab="settings" title="Administration">
                <i class="fas fa-cog"></i>
            </button>
        <?php endif; ?>
        <button type="button" id="closeSidebar" class="sidebar-close" title="Close menu">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- ===== Switchable Menu Panels ===== -->
    <nav class="sidebar-nav">

        <!-- Panel 1: Dashboard / Analytics / Reports -->
        <ul class="sidebar-menu sidebar-group active" id="sidebar-group-dashboard">
            <li class="<?php echo ($current_page == 'dashboard.php' && $dash_view == 'overview') ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=overview">
                    <i class="fas fa-home"></i><span class="menu-text">Overview</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'dashboard.php' && $dash_view == 'sales') ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=sales">
                    <i class="fas fa-dollar-sign"></i><span class="menu-text">Sales Analysis</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'dashboard.php' && $dash_view == 'customers') ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=customers">
                    <i class="fas fa-users"></i><span class="menu-text">Customers Analysis</span>
                </a>
            </li>
            <li class="<?php echo ($current_page == 'dashboard.php' && $dash_view == 'products') ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=products">
                    <i class="fas fa-box"></i><span class="menu-text">Products Analysis</span>
                </a>
            </li>
            <?php if (has_permission('view_reports')): ?>
                <li class="<?php echo $in_reports ? 'active' : ''; ?>">
                    <a href="<?php echo BASE_URL; ?>/admin/reports/index.php" target="_blank"
                        rel="noopener">
                        <i class="fas fa-chart-line"></i><span class="menu-text">Reports</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <?php if ($can_see_menu_group): ?>
            <!-- Panel 2: Menu Management -->
            <ul class="sidebar-menu sidebar-group" id="sidebar-group-menu">
                <?php if (has_permission('manage_categories')): ?>
                    <li class="<?php echo $current_page == 'manage_categories.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/manage_categories.php">
                            <i class="fas fa-list"></i><span class="menu-text">Categories</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (has_permission('manage_menu')): ?>
                    <li class="<?php echo $in_items ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/items/index.php">
                            <i class="fas fa-hamburger"></i><span class="menu-text">Menu Items</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (has_permission('manage_settings') || has_permission('manage_menu')): ?>
                    <li class="<?php echo ($in_tax && $current_page != 'manage_groups.php') ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/tax/index.php">
                            <i class="fas fa-percentage"></i><span class="menu-text">Tax Items</span>
                        </a>
                    </li>
                    <li class="<?php echo ($in_tax && $current_page == 'manage_groups.php') ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/tax/manage_groups.php">
                            <i class="fas fa-layer-group"></i><span class="menu-text">Tax Groups</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (has_permission('import_data')): ?>
                    <li class="<?php echo $current_page == 'import.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/import.php">
                            <i class="fas fa-file-import"></i><span class="menu-text">Import Data</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($can_see_orders_group): ?>
            <!-- Panel 3: Orders & Tables -->
            <ul class="sidebar-menu sidebar-group" id="sidebar-group-orders">
                <?php if (has_permission('manage_orders')): ?>
                    <li class="<?php echo $in_orders && !$orders_child_page ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/orders/index.php">
                            <i class="fas fa-shopping-bag"></i><span class="menu-text">Orders</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'take_order.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/orders/take_order.php">
                            <i class="fas fa-cash-register"></i><span class="menu-text">Take Order</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'guest_table_order.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/orders/guest_table_order.php">
                            <i class="fas fa-concierge-bell"></i><span class="menu-text">Guest Table Order</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'kiosk.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/orders/kiosk.php">
                            <i class="fas fa-tablet-alt"></i><span class="menu-text">Cashier Kiosk</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'table_map.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/orders/table_map.php">
                            <i class="fas fa-map-marked-alt"></i><span class="menu-text">Table Map</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'cleaning_log.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/orders/cleaning_log.php">
                            <i class="fas fa-broom"></i><span class="menu-text">Cleaning Log</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (has_permission('manage_events')): ?>
                    <li class="<?php echo $in_events && !$events_child_page ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/events/index.php">
                            <i class="fas fa-calendar-alt"></i><span class="menu-text">Calendar & Bookings</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'manage_events.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/events/manage_events.php">
                            <i class="fas fa-calendar-plus"></i><span class="menu-text">Manage Events</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'manage_packages.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/events/manage_packages.php">
                            <i class="fas fa-box"></i><span class="menu-text">Manage Packages</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($can_see_settings_group): ?>
            <!-- Panel 4: Administration -->
            <ul class="sidebar-menu sidebar-group" id="sidebar-group-settings">
                <?php if (has_permission('manage_customers')): ?>
                    <li class="<?php echo $in_customers ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/customers/index.php">
                            <i class="fas fa-users"></i><span class="menu-text">Manage Customers</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (has_permission('manage_admins')): ?>
                    <li class="<?php echo ($in_admins && $admins_tab !== 'roles') ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/admins/index.php?tab=users">
                            <i class="fas fa-users-cog"></i><span class="menu-text">Manage Admins</span>
                        </a>
                    </li>
                    <li class="<?php echo ($in_admins && $admins_tab === 'roles') ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/admins/index.php?tab=roles">
                            <i class="fas fa-user-tag"></i><span class="menu-text">User Roles</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (has_permission('manage_settings')): ?>
                    <li class="<?php echo $current_page == 'manage_delivery.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/manage_delivery.php">
                            <i class="fas fa-truck-loading"></i><span class="menu-text">Delivery Zones</span>
                        </a>
                    </li>
                    <li class="<?php echo ($current_page == 'settings.php' && ($_GET['tab'] ?? '') != 'storefront') ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/settings.php">
                            <i class="fas fa-cog"></i><span class="menu-text">General Settings</span>
                        </a>
                    </li>
                    <li class="<?php echo ($current_page == 'settings.php' && ($_GET['tab'] ?? '') == 'storefront') ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/settings.php?tab=storefront">
                            <i class="fas fa-palette"></i><span class="menu-text">Storefront &amp; Theme</span>
                        </a>
                    </li>
                    <li class="<?php echo $current_page == 'billing.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/admin/billing.php">
                            <i class="fas fa-receipt"></i><span class="menu-text">Plan &amp; Billing</span>
                        </a>
                    </li>
                    <?php if (has_permission('manage_settings')): ?>
                        <li class="<?php echo $in_printing && $current_page != 'terminals.php' ? 'active' : ''; ?>">
                            <a href="<?php echo BASE_URL; ?>/admin/printing/index.php">
                                <i class="fas fa-print"></i><span class="menu-text">Printing & Printers</span>
                            </a>
                        </li>
                        <li class="<?php echo $current_page == 'terminals.php' ? 'active' : ''; ?>">
                            <a href="<?php echo BASE_URL; ?>/admin/printing/terminals.php">
                                <i class="fas fa-desktop"></i><span class="menu-text">Terminals</span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <!-- Logout - Always visible -->
        <ul class="sidebar-menu sidebar-logout">
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/logout.php">
                    <i class="fas fa-sign-out-alt"></i><span class="menu-text">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</div>

<script>
    (function () {
        function initSidebarTabs() {
            var tabs = document.querySelectorAll('.sidebar-tab');
            var groups = document.querySelectorAll('.sidebar-group');

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var target = tab.getAttribute('data-tab');

                    tabs.forEach(function (t) { t.classList.remove('active'); });
                    tab.classList.add('active');

                    groups.forEach(function (g) { g.classList.remove('active'); });
                    var panel = document.getElementById('sidebar-group-' + target);
                    if (panel) { panel.classList.add('active'); }

                    // Persist the selected tab
                    try { localStorage.setItem('admin-sidebar-tab', target); } catch (e) { /* noop */ }
                });
            });

            // Auto-select the tab that contains the active page,
            // falling back to the last used tab.
            var activeLi = document.querySelector('.sidebar-group li.active');
            var activeGroup = activeLi ? activeLi.closest('.sidebar-group') : null;
            var restored = null;
            try { restored = localStorage.getItem('admin-sidebar-tab'); } catch (e) { /* noop */ }

            var targetTab = null;
            if (activeGroup && activeGroup.id) {
                targetTab = activeGroup.id.replace('sidebar-group-', '');
            } else if (restored && document.getElementById('sidebar-group-' + restored)) {
                targetTab = restored;
            }

            if (targetTab) {
                var btn = document.querySelector('.sidebar-tab[data-tab="' + targetTab + '"]');
                if (btn && !btn.classList.contains('active')) { btn.click(); }
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSidebarTabs);
        } else {
            initSidebarTabs();
        }
    })();
</script>