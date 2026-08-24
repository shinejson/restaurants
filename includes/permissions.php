<?php
/**
 * Role-Based Access Control (RBAC) Helper Functions
 * Manages permissions for staff, manager, and admin roles
 */

// Define permission mapping: feature => allowed roles
$RBAC_PERMISSIONS = [
    // Dashboard
    'view_dashboard' => ['staff', 'manager', 'admin'],

    // Orders
    'manage_orders' => ['staff', 'manager', 'admin'],
    'view_orders' => ['staff', 'manager', 'admin'],

    // Menu & Categories
    'manage_menu' => ['manager', 'admin'],
    'manage_categories' => ['manager', 'admin'],

    // Events
    'manage_events' => ['manager', 'admin'],

    // Customer Management
    'manage_customers' => ['manager', 'admin'],
    'view_customers' => ['manager', 'admin'],

    // Admin Management
    'manage_admins' => ['admin'],
    'view_admins' => ['admin'],

    // Reports
    'view_reports' => ['manager', 'admin'],

    // Settings
    'manage_settings' => ['admin'],

    // Data Import
    'import_data' => ['admin'],
];

/**
 * Check if current user has permission for a feature
 * @param string $feature The feature to check
 * @return bool True if user has permission
 */
function has_permission($feature)
{
    global $RBAC_PERMISSIONS;

    // Check if user is logged in as admin
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role'])) {
        return false;
    }

    $user_role = $_SESSION['admin_role'];

    // Check if feature exists in permissions
    if (!isset($RBAC_PERMISSIONS[$feature])) {
        return false; // Feature not defined, deny by default
    }

    // Check if user's role is in allowed roles for this feature
    return in_array($user_role, $RBAC_PERMISSIONS[$feature]);
}

/**
 * Require permission or redirect to permission denied page
 * @param string $feature The feature to check
 */
function require_permission($feature)
{
    if (!has_permission($feature)) {
        header('Location: ' . BASE_URL . '/admin/permission_denied.php');
        exit();
    }
}

/**
 * Check if current user is a super admin
 * @return bool
 */
function is_super_admin()
{
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin';
}

/**
 * Check if current user is manager or above
 * @return bool
 */
function is_manager_or_above()
{
    return isset($_SESSION['admin_role']) &&
        in_array($_SESSION['admin_role'], ['manager', 'admin']);
}

/**
 * Check if current user is at least staff level
 * @return bool
 */
function is_staff_or_above()
{
    return isset($_SESSION['admin_role']) &&
        in_array($_SESSION['admin_role'], ['staff', 'manager', 'admin']);
}

/**
 * Get user's role display name
 * @return string
 */
function get_role_name()
{
    if (!isset($_SESSION['admin_role'])) {
        return 'Guest';
    }

    $role = $_SESSION['admin_role'];

    switch ($role) {
        case 'admin':
            return 'Super Admin';
        case 'manager':
            return 'Manager';
        case 'staff':
            return 'Staff';
        default:
            return ucfirst($role);
    }
}
?>