<?php
/**
 * Role-Based Access Control (RBAC) — API
 * ------------------------------------------------------------------
 * The permission catalogue, the shipped defaults and the storage layer
 * now live in includes/rbac.php. Roles and any customised permissions
 * are managed from Admin > Admins > User Roles.
 *
 * The Super Admin role (`admin`) always has full access so the panel
 * can never be locked out.
 */

require_once __DIR__ . '/rbac.php';

/**
 * Shipped default matrix, kept for backwards compatibility:
 * feature => [roles that get access out of the box].
 */
$RBAC_PERMISSIONS = rbac_default_permissions();

/**
 * Check if the current user has permission for a feature.
 *
 * @param string $feature The feature to check
 * @return bool True when the role may use the feature
 */
function has_permission($feature)
{
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role'])) {
        return false;
    }

    $feature = (string) $feature;

    // Unknown feature: deny by default.
    if (rbac_feature($feature) === null) {
        return false;
    }

    return rbac_role_can((string) $_SESSION['admin_role'], $feature);
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
 * Only Super Admins may re-shape what every other role can do.
 * @return bool
 */
function can_manage_roles()
{
    return is_super_admin();
}

/**
 * Check if current user is manager or above
 * @return bool
 */
function is_manager_or_above()
{
    if (!isset($_SESSION['admin_role'])) {
        return false;
    }

    $role = (string) $_SESSION['admin_role'];
    if ($role === 'admin' || $role === 'manager') {
        return true;
    }

    // Custom roles count as "manager level" once they can run the shop.
    return rbac_role_can($role, 'manage_orders') && rbac_role_can($role, 'manage_menu');
}

/**
 * Legacy helper: true for any role that can reach the admin panel.
 * @return bool
 */
function is_staff_or_above()
{
    if (!isset($_SESSION['admin_role'])) {
        return false;
    }

    $role = (string) $_SESSION['admin_role'];
    if (in_array($role, ['staff', 'manager', 'admin'], true)) {
        return true;
    }

    return rbac_role_can($role, 'view_dashboard') || rbac_role_can($role, 'manage_orders');
}

/**
 * Get a role's display name (defaults to the signed-in user's role).
 * @param string|null $role
 * @return string
 */
function get_role_name($role = null)
{
    if ($role === null) {
        if (!isset($_SESSION['admin_role'])) {
            return 'Guest';
        }
        $role = $_SESSION['admin_role'];
    }

    return rbac_role_name($role);
}
