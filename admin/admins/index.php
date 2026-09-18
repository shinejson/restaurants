<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_admins');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$admin_title = 'Manage Admins';

/* ------------------------------------------------------------------
 * RBAC wiring
 * ------------------------------------------------------------------ */
$rbac_ready = rbac_ensure_schema($conn);
$can_edit_roles = can_manage_roles() && $rbac_ready;
$current_admin_id = (int) $_SESSION['admin_id'];

/*
 * Which tab the page opens on. Change to 'roles' if you would rather
 * land on the User Roles tab; the tab bar itself always shows both.
 */
$default_tab = 'users';

$valid_tabs = ['users', 'roles'];
$tab = (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs, true)) ? $_GET['tab'] : $default_tab;

if (!function_exists('admins_flash')) {
    function admins_flash($type, $message)
    {
        $_SESSION['admins_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('admin_initials')) {
    function admin_initials($name)
    {
        $parts = preg_split('/[\s_.-]+/', trim((string) $name));
        $ini = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            if ($part !== '') {
                $ini .= strtoupper(substr($part, 0, 1));
            }
        }
        return $ini !== '' ? $ini : 'A';
    }
}

/** Avatar colour derived from the account id (stable). */
function admin_avatar_color($id)
{
    $colors = ['#ff6b35', '#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899'];
    return $colors[((int) $id) % count($colors)];
}

/* ------------------------------------------------------------------
 * Actions (POST -> redirect -> GET)
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : (isset($_POST['delete_id']) ? 'delete_admin' : '');
    $role_actions = ['save_permissions', 'reset_permissions', 'save_role', 'delete_role'];
    $redirect_tab = in_array($action, $role_actions, true) ? 'roles' : 'users';

    $csrf_ok = isset($_POST['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token']);
    if (!$csrf_ok) {
        admins_flash('error', 'Invalid security token. Please reload the page and try again.');
        header('Location: index.php?tab=' . $redirect_tab);
        exit;
    }

    switch ($action) {
        /* ---------------- Admin user actions ---------------- */
        case 'delete_admin':
            $delete_id = (int) (isset($_POST['delete_id']) ? $_POST['delete_id'] : 0);

            if ($delete_id <= 0) {
                admins_flash('error', 'Admin account not found.');
                break;
            }
            if ($delete_id === $current_admin_id) {
                admins_flash('error', 'You cannot delete your own account.');
                break;
            }

            $stmt = $conn->prepare("SELECT id, username, role FROM admins WHERE id = ?");
            $stmt->execute([$delete_id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                admins_flash('error', 'Admin account not found.');
                break;
            }
            if ($target['role'] === 'admin' && rbac_super_admin_count() <= 1) {
                admins_flash('error', 'This is the last Super Admin account — promote another admin before deleting it.');
                break;
            }

            try {
                $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
                $stmt->execute([$delete_id]);
                admins_flash('success', 'Admin "' . $target['username'] . '" deleted successfully.');
            } catch (PDOException $e) {
                admins_flash('error', 'Error deleting admin: ' . $e->getMessage());
            }
            break;

        case 'bulk_delete':
            $ids = isset($_POST['admin_ids']) && is_array($_POST['admin_ids']) ? $_POST['admin_ids'] : [];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
                return $id > 0;
            })));
            $ids = array_values(array_diff($ids, [$current_admin_id]));

            if (empty($ids)) {
                admins_flash('error', 'Select at least one admin first (your own account cannot be deleted).');
                break;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT id, username, role FROM admins WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $remaining_super_admins = rbac_super_admin_count();
            $deleted = 0;
            $protected = 0;

            try {
                $delete = $conn->prepare("DELETE FROM admins WHERE id = ?");
                foreach ($targets as $target) {
                    if ($target['role'] === 'admin' && $remaining_super_admins <= 1) {
                        $protected++;
                        continue;
                    }
                    $delete->execute([$target['id']]);
                    if ($target['role'] === 'admin') {
                        $remaining_super_admins--;
                    }
                    $deleted++;
                }

                if ($deleted > 0) {
                    $message = $deleted . ' admin account(s) deleted.';
                    if ($protected > 0) {
                        $message .= ' ' . $protected . ' Super Admin account(s) kept — the panel always needs one.';
                    }
                    admins_flash('success', $message);
                } else {
                    admins_flash('error', 'Nothing was deleted — at least one Super Admin must remain.');
                }
            } catch (PDOException $e) {
                admins_flash('error', 'Error deleting admins: ' . $e->getMessage());
            }
            break;

        /* ---------------- Role & permission actions ---------------- */
        case 'save_permissions':
            if (!$can_edit_roles) {
                admins_flash('error', 'Only a Super Admin can change role permissions.');
                break;
            }

            $posted = isset($_POST['perm']) && is_array($_POST['perm']) ? $_POST['perm'] : [];
            $posted_roles = isset($_POST['perm_roles']) && is_array($_POST['perm_roles']) ? $_POST['perm_roles'] : [];

            $result = rbac_save_permissions($posted, $posted_roles);
            if (!empty($result['errors'])) {
                admins_flash('error', implode(' ', $result['errors']));
            } else {
                admins_flash('success', 'Permissions saved. ' . count($posted_roles) . ' role(s) updated — changes apply immediately to every user with those roles.');
            }
            break;

        case 'reset_permissions':
            if (!$can_edit_roles) {
                admins_flash('error', 'Only a Super Admin can change role permissions.');
                break;
            }

            $role_slug = isset($_POST['role_current']) ? (string) $_POST['role_current'] : '';
            $targets = [];
            if ($role_slug === '__all__' || $role_slug === '') {
                $targets = array_keys(rbac_roles());
            } else {
                $targets = [$role_slug];
            }

            $reset = 0;
            foreach ($targets as $slug) {
                if (rbac_is_locked_role($slug)) {
                    continue;
                }
                rbac_reset_role_permissions($slug);
                $reset++;
            }
            admins_flash('success', $reset . ' role(s) reset to the shipped default permissions.');
            break;

        case 'save_role':
            if (!$can_edit_roles) {
                admins_flash('error', 'Only a Super Admin can create or edit roles.');
                break;
            }

            $current_slug = isset($_POST['role_current']) ? (string) $_POST['role_current'] : '';
            $role_data = [
                'slug' => isset($_POST['role_slug']) ? $_POST['role_slug'] : '',
                'name' => isset($_POST['role_name']) ? $_POST['role_name'] : '',
                'description' => isset($_POST['role_description']) ? $_POST['role_description'] : '',
                'icon' => isset($_POST['role_icon']) ? $_POST['role_icon'] : 'fa-user',
                'color' => isset($_POST['role_color']) ? $_POST['role_color'] : '#64748b',
            ];

            $result = ($current_slug === '')
                ? rbac_create_role($role_data)
                : rbac_update_role($current_slug, $role_data);

            if (empty($result['ok'])) {
                admins_flash('error', $result['error']);
            } else {
                admins_flash('success', $current_slug === ''
                    ? 'Role "' . $role_data['name'] . '" created. Grant its permissions in the matrix below.'
                    : 'Role updated successfully.');
            }
            break;

        case 'delete_role':
            if (!$can_edit_roles) {
                admins_flash('error', 'Only a Super Admin can delete roles.');
                break;
            }

            $role_slug = isset($_POST['role_current']) ? (string) $_POST['role_current'] : '';
            $reassign_to = isset($_POST['role_reassign']) ? (string) $_POST['role_reassign'] : '';

            $result = rbac_delete_role($role_slug, $reassign_to);
            if (empty($result['ok'])) {
                admins_flash('error', $result['error']);
            } else {
                $message = 'Role deleted.';
                if (!empty($result['moved'])) {
                    $message .= ' ' . (int) $result['moved'] . ' admin(s) moved to ' . rbac_role_name($reassign_to) . '.';
                }
                admins_flash('success', $message);
            }
            break;

        default:
            admins_flash('error', 'Unknown action requested.');
            break;
    }

    header('Location: index.php?tab=' . $redirect_tab);
    exit;
}

/* ------------------------------------------------------------------
 * Data
 * ------------------------------------------------------------------ */
$flash = null;
if (!empty($_SESSION['admins_flash'])) {
    $flash = $_SESSION['admins_flash'];
    unset($_SESSION['admins_flash']);
}

$roles = rbac_roles();
$role_user_counts = rbac_role_user_counts();

$stmt = $conn->query("SELECT * FROM admins ORDER BY created_at DESC, id DESC");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_admins = count($admins);
$stats = ['total' => $total_admins];
foreach ($roles as $slug => $role) {
    $stats[$slug] = isset($role_user_counts[$slug]) ? (int) $role_user_counts[$slug] : 0;
}

// Roles that actually have users — drives the filter dropdown and stat cards.
$used_roles = [];
foreach ($roles as $slug => $role) {
    if (!empty($stats[$slug])) {
        $used_roles[$slug] = $role;
    }
}

$column_toggles = [
    'email' => 'Email',
    'role' => 'Role',
    'created' => 'Created',
    'id' => 'Account ID',
];
$columns_hidden_by_default = ['id'];

include '../includes/admin_header.php';
?>

<style>
    :root {
        --adm-radius: 14px;
    }

    /* ---------- Tabs (page level navigation) ---------- */
    .admins-tabs {
        display: flex;
        gap: 0.35rem;
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: var(--adm-radius);
        padding: 0.4rem;
        margin-bottom: 1.5rem;
        overflow-x: auto;
    }

    .admins-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.7rem 1.25rem;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.92rem;
        color: var(--text-muted);
        text-decoration: none;
        white-space: nowrap;
        transition: background 0.2s ease, color 0.2s ease;
    }

    .admins-tab:hover {
        background: var(--light-bg);
        color: var(--text-main);
    }

    .admins-tab.active {
        background: var(--primary-color);
        color: #fff;
        box-shadow: 0 6px 16px -6px rgba(255, 107, 53, 0.8);
    }

    .admins-tab .tab-count {
        background: rgba(0, 0, 0, 0.08);
        border-radius: 999px;
        padding: 0.05rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 800;
    }

    .admins-tab.active .tab-count {
        background: rgba(255, 255, 255, 0.25);
        color: #fff;
    }

    /* ---------- Alerts ---------- */
    .admins-alert {
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
        padding: 0.9rem 1.1rem;
        border-radius: 12px;
        margin-bottom: 1.25rem;
        font-weight: 600;
        font-size: 0.92rem;
        border: 1px solid transparent;
    }

    .admins-alert.success {
        background: rgba(16, 185, 129, 0.1);
        color: #047857;
        border-color: rgba(16, 185, 129, 0.25);
    }

    .admins-alert.error {
        background: rgba(239, 68, 68, 0.08);
        color: #b91c1c;
        border-color: rgba(239, 68, 68, 0.25);
    }

    .admins-alert .alert-close {
        margin-left: auto;
        background: none;
        border: none;
        color: inherit;
        cursor: pointer;
        opacity: 0.6;
        padding: 0 0.2rem;
    }

    /* ---------- Stats ---------- */
    .admins-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: var(--adm-radius);
        padding: 1.1rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.9rem;
    }

    .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .stat-card .stat-num {
        font-size: 1.4rem;
        font-weight: 800;
        line-height: 1.1;
        color: var(--text-main);
    }

    .stat-card .stat-label {
        font-size: 0.78rem;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    /* ---------- Data table shell ---------- */
    .dt-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: var(--adm-radius);
        overflow: hidden;
    }

    .dt-hidden-form {
        display: none;
    }

    .dt-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 1rem 1.15rem;
        border-bottom: 1px solid var(--border-color);
    }

    .dt-toolbar-group {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.55rem;
    }

    .dt-search {
        position: relative;
        display: flex;
        align-items: center;
    }

    .dt-search > i {
        position: absolute;
        left: 0.85rem;
        color: var(--text-muted);
        font-size: 0.85rem;
        pointer-events: none;
    }

    .dt-input,
    .dt-select {
        font-family: inherit;
        font-size: 0.9rem;
        color: var(--text-main);
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 0.6rem 0.85rem;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .dt-search .dt-input {
        padding-left: 2.35rem;
        padding-right: 2.1rem;
        min-width: 240px;
    }

    .dt-input:focus,
    .dt-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.12);
    }

    .dt-search-clear {
        position: absolute;
        right: 0.5rem;
        border: none;
        background: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 0.25rem;
        border-radius: 6px;
    }

    .dt-search-clear:hover {
        color: var(--text-main);
        background: var(--light-bg);
    }

    .dt-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.6rem 0.95rem;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        background: var(--white);
        color: var(--text-main);
        font-family: inherit;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .dt-btn:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    .dt-btn.danger:hover {
        border-color: #ef4444;
        color: #b91c1c;
        background: rgba(239, 68, 68, 0.06);
    }

    .dt-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-primary.dt-btn-primary {
        padding: 0.6rem 1rem;
        border-radius: 10px;
        font-size: 0.88rem;
    }

    /* Dropdown (columns / row actions) */
    .dt-dropdown {
        position: relative;
    }

    .dt-dropdown-menu {
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        min-width: 190px;
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        box-shadow: 0 16px 40px -20px rgba(15, 23, 42, 0.5);
        padding: 0.5rem;
        z-index: 50;
        display: none;
    }

    .dt-dropdown.open .dt-dropdown-menu {
        display: block;
    }

    .dt-dropdown-menu .dt-check {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.45rem 0.55rem;
        border-radius: 8px;
        font-size: 0.88rem;
        font-weight: 600;
        color: var(--text-main);
        cursor: pointer;
    }

    .dt-dropdown-menu .dt-check:hover {
        background: var(--light-bg);
    }

    .dt-dropdown-menu .dt-check input {
        accent-color: var(--primary-color);
    }

    /* Selection bar */
    .dt-bulkbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.85rem;
        padding: 0.7rem 1.15rem;
        background: rgba(59, 130, 246, 0.06);
        border-bottom: 1px solid var(--border-color);
        font-size: 0.88rem;
        font-weight: 700;
        color: #1d4ed8;
    }

    .dt-bulkbar[hidden] {
        display: none;
    }

    .dt-bulkbar .dt-bulk-spacer {
        flex: 1;
    }

    /* ---------- Table ---------- */
    .dt-scroll {
        width: 100%;
        overflow-x: auto;
    }

    .dt-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 720px;
    }

    .dt-table th,
    .dt-table td {
        padding: 0.85rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.9rem;
        vertical-align: middle;
        white-space: nowrap;
    }

    .dt-table thead th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        background: var(--light-bg);
        font-weight: 800;
        position: relative;
    }

    .dt-table tbody tr:hover td {
        background: rgba(249, 115, 22, 0.03);
    }

    .dt-table tbody tr.dt-row.is-self td:first-child {
        box-shadow: inset 3px 0 0 var(--primary-color);
    }

    .dt-sortable {
        cursor: pointer;
        user-select: none;
    }

    .dt-sortable:hover {
        color: var(--text-main);
    }

    .dt-sort-icon {
        margin-left: 0.3rem;
        opacity: 0.35;
        font-size: 0.7rem;
    }

    .dt-sortable[aria-sort="ascending"] .dt-sort-icon,
    .dt-sortable[aria-sort="descending"] .dt-sort-icon {
        opacity: 1;
        color: var(--primary-color);
    }

    .dt-col-check {
        width: 40px;
        text-align: center;
    }

    .dt-table input[type="checkbox"] {
        accent-color: var(--primary-color);
        width: 15px;
        height: 15px;
        cursor: pointer;
    }

    .dt-cell-hidden {
        display: none !important;
    }

    .admin-user {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .admin-avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.85rem;
        color: #fff;
        flex-shrink: 0;
    }

    .you-badge {
        background: rgba(59, 130, 246, 0.12);
        color: #1d4ed8;
        font-size: 0.68rem;
        font-weight: 800;
        padding: 2px 8px;
        border-radius: 20px;
        margin-left: 0.45rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.7rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 800;
        background: rgba(100, 116, 139, 0.12);
        color: inherit;
    }

    .admins-actions {
        display: inline-flex;
        flex-direction: row;
        align-items: center;
        gap: 0.45rem;
    }

    .admins-actions form {
        display: inline-flex;
        margin: 0;
    }

    .act-btn {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--border-color);
        background: var(--white);
        color: var(--text-muted);
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s ease;
        font-size: 0.85rem;
    }

    .act-btn:hover {
        transform: translateY(-1px);
    }

    .act-btn.edit:hover {
        background: rgba(59, 130, 246, 0.1);
        color: #1d4ed8;
        border-color: #93c5fd;
    }

    .act-btn.delete:hover {
        background: rgba(220, 38, 38, 0.1);
        color: #b91c1c;
        border-color: #fca5a5;
    }

    /* ---------- Footer / pagination ---------- */
    .dt-footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.9rem 1.15rem;
    }

    .dt-info {
        font-size: 0.86rem;
        color: var(--text-muted);
        font-weight: 600;
    }

    .dt-pager {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.3rem;
    }

    .dt-page {
        min-width: 34px;
        height: 34px;
        padding: 0 0.5rem;
        border-radius: 9px;
        border: 1px solid var(--border-color);
        background: var(--white);
        color: var(--text-main);
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .dt-page:hover:not(:disabled) {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    .dt-page.active {
        background: var(--primary-color);
        border-color: var(--primary-color);
        color: #fff;
    }

    .dt-page:disabled {
        opacity: 0.45;
        cursor: not-allowed;
    }

    .dt-page.dt-ellipsis {
        border: none;
        background: none;
        cursor: default;
        color: var(--text-muted);
    }

    /* ---------- Empty states ---------- */
    .dt-empty {
        text-align: center;
        padding: 3rem 1.5rem;
        color: var(--text-muted);
    }

    .dt-empty i {
        font-size: 2.5rem;
        opacity: 0.5;
        margin-bottom: 0.75rem;
        display: block;
    }

    .dt-empty h3 {
        margin: 0 0 0.35rem;
        color: var(--text-main);
        font-size: 1.15rem;
    }

    @media (max-width: 720px) {
        .dt-toolbar {
            align-items: stretch;
        }

        .dt-toolbar-group {
            width: 100%;
        }

        .dt-search {
            width: 100%;
        }

        .dt-search .dt-input {
            min-width: 0;
            width: 100%;
        }

        .dt-select {
            flex: 1;
        }
    }
</style>

<div class="content-header">
    <div>
        <h1><i class="fas fa-users-cog"></i> Manage Admins</h1>
        <p>Admin accounts, roles and what each role is allowed to do.</p>
    </div>
    <a href="create.php" class="btn-primary"><i class="fas fa-plus"></i> Add New Admin</a>
</div>

<!-- Page tabs (top of the page) -->
<div class="admins-tabs" role="tablist" aria-label="Manage admins sections">
    <a class="admins-tab <?php echo $tab === 'users' ? 'active' : ''; ?>" href="index.php?tab=users" role="tab"
        aria-selected="<?php echo $tab === 'users' ? 'true' : 'false'; ?>">
        <i class="fas fa-users"></i> Admin Users
        <span class="tab-count"><?php echo $total_admins; ?></span>
    </a>
    <a class="admins-tab <?php echo $tab === 'roles' ? 'active' : ''; ?>" href="index.php?tab=roles" role="tab"
        aria-selected="<?php echo $tab === 'roles' ? 'true' : 'false'; ?>">
        <i class="fas fa-user-tag"></i> User Roles
        <span class="tab-count"><?php echo count($roles); ?></span>
    </a>
</div>

<?php if ($flash && !empty($flash['message'])): ?>
    <div class="admins-alert <?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" id="adminsFlash">
        <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?>"></i>
        <span><?php echo htmlspecialchars($flash['message']); ?></span>
        <button type="button" class="alert-close" onclick="this.parentNode.remove()" aria-label="Dismiss">
            <i class="fas fa-times"></i>
        </button>
    </div>
<?php endif; ?>

<?php if (!$rbac_ready): ?>
    <div class="admins-alert error">
        <i class="fas fa-triangle-exclamation"></i>
        <span>
            The <strong>roles</strong> and <strong>role_permissions</strong> tables are not available, so roles are
            running on the built-in defaults. Create them from
            <a href="<?php echo BASE_URL; ?>/admin/upgrade_rbac_roles.php">Admin &rsaquo; RBAC setup</a> or ask your
            database administrator to run that script.
        </span>
    </div>
<?php endif; ?>

<?php if ($tab === 'roles'): ?>
    <?php
    define('ADMINS_PAGE', true);
    include __DIR__ . '/roles_panel.php';
    ?>
<?php else: ?>
    <section id="panel-users" role="tabpanel" aria-label="Admin users">

        <!-- Stats -->
        <div class="admins-stats">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(59,130,246,0.12); color: #1d4ed8;"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-num"><?php echo $total_admins; ?></div>
                    <div class="stat-label">Total Admins</div>
                </div>
            </div>
            <?php foreach ($used_roles as $slug => $role): ?>
                <div class="stat-card">
                    <div class="stat-icon"
                        style="background: <?php echo htmlspecialchars($role['color']); ?>1f; color: <?php echo htmlspecialchars($role['color']); ?>;">
                        <i class="fas <?php echo htmlspecialchars($role['icon']); ?>"></i>
                    </div>
                    <div>
                        <div class="stat-num"><?php echo (int) $stats[$slug]; ?></div>
                        <div class="stat-label"><?php echo htmlspecialchars($role['name']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dt-card">
            <!-- Hidden bulk action form (filled in by the table script) -->
            <form method="POST" id="dtBulkForm" class="dt-hidden-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="bulk_delete">
                <span id="dtBulkFields"></span>
            </form>

            <!-- Toolbar -->
            <div class="dt-toolbar">
                <div class="dt-toolbar-group">
                    <div class="dt-search">
                        <i class="fas fa-search"></i>
                        <input type="search" id="dtSearch" class="dt-input" placeholder="Search name, email or role…"
                            autocomplete="off" aria-label="Search admins">
                        <button type="button" class="dt-search-clear" id="dtSearchClear" hidden aria-label="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <select id="dtRoleFilter" class="dt-select" aria-label="Filter by role">
                        <option value="all">All roles (<?php echo $total_admins; ?>)</option>
                        <?php foreach ($roles as $slug => $role): ?>
                            <option value="<?php echo htmlspecialchars($slug); ?>">
                                <?php echo htmlspecialchars($role['name']); ?> (<?php echo (int) $stats[$slug]; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="dtPageSize" class="dt-select" aria-label="Rows per page">
                        <option value="5">5 / page</option>
                        <option value="10" selected>10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="all">Show all</option>
                    </select>
                </div>

                <div class="dt-toolbar-group">
                    <div class="dt-dropdown" id="dtColumns">
                        <button type="button" class="dt-btn" aria-expanded="false" aria-haspopup="true">
                            <i class="fas fa-table-columns"></i> Columns
                        </button>
                        <div class="dt-dropdown-menu" role="menu">
                            <?php foreach ($column_toggles as $key => $label): ?>
                                <label class="dt-check">
                                    <input type="checkbox" data-column-toggle="<?php echo htmlspecialchars($key); ?>"
                                        <?php echo in_array($key, $columns_hidden_by_default, true) ? '' : 'checked'; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" class="dt-btn" id="dtExport">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>

                    <a href="create.php" class="btn-primary dt-btn-primary"><i class="fas fa-plus"></i> Add Admin</a>
                </div>
            </div>

            <!-- Selection bar -->
            <div class="dt-bulkbar" id="dtBulkBar" hidden>
                <span id="dtSelectedCount">0 selected</span>
                <button type="button" class="dt-btn danger" id="dtBulkDelete">
                    <i class="fas fa-trash"></i> Delete selected
                </button>
                <span class="dt-bulk-spacer"></span>
                <button type="button" class="dt-btn" id="dtClearSelection">Clear selection</button>
            </div>

            <?php if (empty($admins)): ?>
                <div class="dt-empty">
                    <i class="fas fa-users-cog"></i>
                    <h3>No admins found</h3>
                    <p>Add your first administrator to get started.</p>
                    <a href="create.php" class="btn-primary dt-btn-primary"><i class="fas fa-plus"></i> Add Admin</a>
                </div>
            <?php else: ?>
                <div class="dt-scroll">
                    <table class="dt-table" id="adminsTable">
                        <thead>
                            <tr>
                                <th class="dt-col-check">
                                    <input type="checkbox" id="dtSelectAll" aria-label="Select all rows on this page">
                                </th>
                                <th class="dt-sortable" data-sort-key="name" tabindex="0" role="button" aria-sort="none">
                                    Admin <i class="fas fa-sort dt-sort-icon"></i>
                                </th>
                                <th class="dt-sortable col-email" data-sort-key="email" tabindex="0" role="button"
                                    aria-sort="none">
                                    Email <i class="fas fa-sort dt-sort-icon"></i>
                                </th>
                                <th class="dt-sortable col-role" data-sort-key="role" tabindex="0" role="button"
                                    aria-sort="none">
                                    Role <i class="fas fa-sort dt-sort-icon"></i>
                                </th>
                                <th class="dt-sortable col-created" data-sort-key="created" tabindex="0" role="button"
                                    aria-sort="none">
                                    Created <i class="fas fa-sort dt-sort-icon"></i>
                                </th>
                                <th class="dt-sortable col-id" data-sort-key="id" tabindex="0" role="button"
                                    aria-sort="none">
                                    ID <i class="fas fa-sort dt-sort-icon"></i>
                                </th>
                                <th style="width: 110px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="dtBody">
                            <?php foreach ($admins as $admin): ?>
                                <?php
                                $role_slug = (string) ($admin['role'] ?? 'staff');
                                $role_meta = rbac_role($role_slug);
                                $role_permissions = rbac_role_permissions($role_slug);
                                $is_self = ((int) $admin['id'] === $current_admin_id);
                                $created_ts = !empty($admin['created_at']) ? strtotime($admin['created_at']) : 0;
                                $created_label = $created_ts ? date('M d, Y', $created_ts) : 'N/A';
                                $search_haystack = strtolower(implode(' ', [
                                    (string) $admin['username'],
                                    (string) $admin['email'],
                                    $role_slug,
                                    $role_meta['name'],
                                    (string) $admin['id'],
                                ]));
                                ?>
                                <tr class="dt-row <?php echo $is_self ? 'is-self' : ''; ?>"
                                    data-id="<?php echo (int) $admin['id']; ?>"
                                    data-role="<?php echo htmlspecialchars($role_slug); ?>"
                                    data-self="<?php echo $is_self ? '1' : '0'; ?>"
                                    data-name="<?php echo htmlspecialchars($admin['username'], ENT_QUOTES); ?>"
                                    data-email="<?php echo htmlspecialchars((string) $admin['email'], ENT_QUOTES); ?>"
                                    data-role-label="<?php echo htmlspecialchars($role_meta['name'], ENT_QUOTES); ?>"
                                    data-created-label="<?php echo htmlspecialchars($created_label, ENT_QUOTES); ?>"
                                    data-sort-name="<?php echo htmlspecialchars(strtolower($admin['username']), ENT_QUOTES); ?>"
                                    data-sort-email="<?php echo htmlspecialchars(strtolower((string) $admin['email']), ENT_QUOTES); ?>"
                                    data-sort-role="<?php echo htmlspecialchars(strtolower($role_meta['name']), ENT_QUOTES); ?>"
                                    data-sort-created="<?php echo (int) $created_ts; ?>"
                                    data-sort-id="<?php echo (int) $admin['id']; ?>"
                                    data-search="<?php echo htmlspecialchars($search_haystack, ENT_QUOTES); ?>">
                                    <td class="dt-col-check">
                                        <input type="checkbox" class="dt-row-check" value="<?php echo (int) $admin['id']; ?>"
                                            aria-label="Select <?php echo htmlspecialchars($admin['username'], ENT_QUOTES); ?>">
                                    </td>
                                    <td>
                                        <div class="admin-user">
                                            <div class="admin-avatar"
                                                style="background: <?php echo admin_avatar_color($admin['id']); ?>;">
                                                <?php echo htmlspecialchars(admin_initials($admin['username'])); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700;">
                                                    <?php echo htmlspecialchars($admin['username']); ?>
                                                    <?php if ($is_self): ?><span class="you-badge">You</span><?php endif; ?>
                                                </div>
                                                <small style="color: var(--text-muted);">
                                                    <?php echo count(array_filter($role_permissions)); ?> permissions
                                                    <?php if (!empty($admin['created_at'])): ?>
                                                        · joined <?php echo date('M Y', $created_ts); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="col-email">
                                        <?php if (!empty($admin['email'])): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($admin['email']); ?>"
                                                style="color: var(--text-main); text-decoration: none;">
                                                <?php echo htmlspecialchars($admin['email']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-role">
                                        <span class="role-badge"
                                            style="background: <?php echo htmlspecialchars($role_meta['color']); ?>1f; color: <?php echo htmlspecialchars($role_meta['color']); ?>;">
                                            <i class="fas <?php echo htmlspecialchars($role_meta['icon']); ?>" style="font-size: 0.7rem;"></i>
                                            <?php echo htmlspecialchars($role_meta['name']); ?>
                                        </span>
                                    </td>
                                    <td class="col-created"><?php echo htmlspecialchars($created_label); ?></td>
                                    <td class="col-id">#<?php echo (int) $admin['id']; ?></td>
                                    <td>
                                        <div class="admins-actions">
                                            <a href="edit.php?id=<?php echo (int) $admin['id']; ?>" class="act-btn edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if (!$is_self): ?>
                                                <form method="POST"
                                                    onsubmit="return confirm('Delete <?php echo htmlspecialchars($admin['username'], ENT_QUOTES); ?>? This cannot be undone.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="delete_admin">
                                                    <input type="hidden" name="delete_id" value="<?php echo (int) $admin['id']; ?>">
                                                    <button type="submit" class="act-btn delete" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="act-btn" style="opacity: 0.35; cursor: not-allowed;"
                                                    title="You cannot delete your own account">
                                                    <i class="fas fa-trash"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="dt-empty" id="dtNoMatch" hidden>
                    <i class="fas fa-filter-circle-xmark"></i>
                    <h3>No matching admins</h3>
                    <p>Try a different search term, role or page size.</p>
                    <button type="button" class="dt-btn" id="dtResetFilters">
                        <i class="fas fa-rotate-left"></i> Reset filters
                    </button>
                </div>
            <?php endif; ?>

            <!-- Footer: info + pagination -->
            <div class="dt-footer" id="dtFooter" <?php echo empty($admins) ? 'hidden' : ''; ?>>
                <div class="dt-info" id="dtInfo"></div>
                <div class="dt-pager" id="dtPager"></div>
            </div>
        </div>
    </section>

    <script>
        (function () {
            'use strict';

            var table = document.getElementById('adminsTable');
            if (!table) {
                return;
            }

            var STORAGE_KEY = 'admins.table.prefs.v1';
            var tbody = document.getElementById('dtBody');
            var searchInput = document.getElementById('dtSearch');
            var searchClear = document.getElementById('dtSearchClear');
            var roleFilter = document.getElementById('dtRoleFilter');
            var pageSizeSelect = document.getElementById('dtPageSize');
            var infoEl = document.getElementById('dtInfo');
            var pagerEl = document.getElementById('dtPager');
            var footerEl = document.getElementById('dtFooter');
            var noMatchEl = document.getElementById('dtNoMatch');
            var selectAll = document.getElementById('dtSelectAll');
            var bulkBar = document.getElementById('dtBulkBar');
            var bulkCount = document.getElementById('dtSelectedCount');
            var bulkForm = document.getElementById('dtBulkForm');
            var bulkFields = document.getElementById('dtBulkFields');
            var columnsDropdown = document.getElementById('dtColumns');

            /* ---------------- Collect rows ---------------- */
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.dt-row')).map(function (el) {
                return {
                    el: el,
                    id: parseInt(el.getAttribute('data-id'), 10) || 0,
                    role: el.getAttribute('data-role') || '',
                    self: el.getAttribute('data-self') === '1',
                    search: (el.getAttribute('data-search') || '').toLowerCase(),
                    check: el.querySelector('.dt-row-check'),
                    values: {
                        name: el.getAttribute('data-sort-name') || '',
                        email: el.getAttribute('data-sort-email') || '',
                        role: el.getAttribute('data-sort-role') || '',
                        created: parseInt(el.getAttribute('data-sort-created'), 10) || 0,
                        id: parseInt(el.getAttribute('data-sort-id'), 10) || 0
                    }
                };
            });
            var totalRows = rows.length;

            var visibleRowsCache = [];

            var state = {
                query: '',
                role: 'all',
                sortKey: 'created',
                sortDir: 'desc',
                page: 1,
                pageSize: 10,
                hiddenColumns: [],
                selected: {}
            };

            /* ---------------- Persisted preferences ---------------- */
            var columnsFromStorage = false;

            function loadPrefs() {
                try {
                    var raw = window.localStorage.getItem(STORAGE_KEY);
                    if (!raw) {
                        return;
                    }
                    var prefs = JSON.parse(raw);
                    if (prefs && typeof prefs === 'object') {
                        if (prefs.pageSize) {
                            state.pageSize = prefs.pageSize;
                        }
                        if (prefs.sortKey) {
                            state.sortKey = prefs.sortKey;
                        }
                        if (prefs.sortDir === 'asc' || prefs.sortDir === 'desc') {
                            state.sortDir = prefs.sortDir;
                        }
                        if (Object.prototype.toString.call(prefs.hiddenColumns) === '[object Array]') {
                            state.hiddenColumns = prefs.hiddenColumns;
                            columnsFromStorage = true;
                        }
                    }
                } catch (e) { /* storage disabled — carry on with defaults */ }
            }

            function savePrefs() {
                try {
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                        pageSize: state.pageSize,
                        sortKey: state.sortKey,
                        sortDir: state.sortDir,
                        hiddenColumns: state.hiddenColumns
                    }));
                } catch (e) { /* ignore */ }
            }

            /* ---------------- Columns ---------------- */
            function applyColumns() {
                Array.prototype.forEach.call(document.querySelectorAll('[data-column-toggle]'), function (input) {
                    var key = input.getAttribute('data-column-toggle');
                    var hidden = state.hiddenColumns.indexOf(key) !== -1;
                    input.checked = !hidden;
                    Array.prototype.forEach.call(table.querySelectorAll('.col-' + key), function (cell) {
                        cell.classList.toggle('dt-cell-hidden', hidden);
                    });
                });
            }

            /* ---------------- Filtering / sorting ---------------- */
            function filteredRows() {
                var query = state.query;
                var list = rows.filter(function (row) {
                    if (state.role !== 'all' && row.role !== state.role) {
                        return false;
                    }
                    return query === '' || row.search.indexOf(query) !== -1;
                });

                list.sort(function (a, b) {
                    var av = a.values[state.sortKey];
                    var bv = b.values[state.sortKey];
                    var cmp;

                    if (typeof av === 'number' && typeof bv === 'number') {
                        cmp = av - bv;
                    } else {
                        cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: 'base', numeric: true });
                    }

                    if (cmp === 0) {
                        cmp = a.id - b.id;
                    }

                    return state.sortDir === 'asc' ? cmp : -cmp;
                });

                return list;
            }

            function pageNumbers(current, total) {
                var pages = [];
                var i;

                if (total <= 7) {
                    for (i = 1; i <= total; i++) {
                        pages.push(i);
                    }
                    return pages;
                }

                pages.push(1);
                var start = Math.max(2, current - 1);
                var end = Math.min(total - 1, current + 1);

                if (start > 2) {
                    pages.push('…');
                }
                for (i = start; i <= end; i++) {
                    pages.push(i);
                }
                if (end < total - 1) {
                    pages.push('…');
                }
                pages.push(total);

                return pages;
            }

            /* ---------------- Selection ---------------- */
            function selectedIds() {
                return Object.keys(state.selected).filter(function (key) {
                    return state.selected[key];
                });
            }

            function updateSelectionUi(visiblePageRows) {
                var count = selectedIds().length;
                bulkBar.hidden = count === 0;
                bulkCount.textContent = count + ' selected';

                var pageRows = visiblePageRows || [];
                var checkedOnPage = pageRows.filter(function (row) {
                    return !!state.selected[row.id];
                }).length;

                if (selectAll) {
                    selectAll.checked = pageRows.length > 0 && checkedOnPage === pageRows.length;
                    selectAll.indeterminate = checkedOnPage > 0 && checkedOnPage < pageRows.length;
                }
            }

            function clearSelection() {
                state.selected = {};
                Array.prototype.forEach.call(tbody.querySelectorAll('.dt-row-check'), function (input) {
                    input.checked = false;
                });
                updateSelectionUi(visibleRowsCache);
            }

            /* ---------------- Render ---------------- */
            function render() {
                var list = filteredRows();
                var total = list.length;
                var pageSize = state.pageSize === 'all' ? Math.max(total, 1) : parseInt(state.pageSize, 10) || 10;
                var totalPages = Math.max(1, Math.ceil(total / pageSize));

                if (state.page > totalPages) {
                    state.page = totalPages;
                }
                if (state.page < 1) {
                    state.page = 1;
                }

                var start = (state.page - 1) * pageSize;
                var end = Math.min(start + pageSize, total);
                var visible = list.slice(start, end);
                var visibleIds = {};

                visible.forEach(function (row) {
                    visibleIds[row.id] = true;
                });

                // Re-order the DOM so the visible slice follows the sort order.
                visible.forEach(function (row) {
                    row.el.hidden = false;
                    row.el.style.display = '';
                    tbody.appendChild(row.el);
                });
                rows.forEach(function (row) {
                    if (!visibleIds[row.id]) {
                        row.el.hidden = true;
                        row.el.style.display = 'none';
                    }
                    if (row.check) {
                        row.check.checked = !!state.selected[row.id];
                    }
                });

                // Sort indicators
                Array.prototype.forEach.call(table.querySelectorAll('.dt-sortable'), function (th) {
                    var key = th.getAttribute('data-sort-key');
                    var icon = th.querySelector('.dt-sort-icon');
                    if (key === state.sortKey) {
                        th.setAttribute('aria-sort', state.sortDir === 'asc' ? 'ascending' : 'descending');
                        if (icon) {
                            icon.className = 'fas dt-sort-icon ' + (state.sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                        }
                    } else {
                        th.setAttribute('aria-sort', 'none');
                        if (icon) {
                            icon.className = 'fas fa-sort dt-sort-icon';
                        }
                    }
                });

                // Info + empty state
                if (total === 0) {
                    infoEl.textContent = 'No entries to show';
                    noMatchEl.hidden = false;
                    pagerEl.innerHTML = '';
                } else {
                    noMatchEl.hidden = true;
                    var filteredNote = total === totalRows ? '' : ' (filtered from ' + totalRows + ' total)';
                    infoEl.textContent = 'Showing ' + (start + 1) + ' to ' + end + ' of ' + total + ' entries' + filteredNote;

                    // Pagination
                    pagerEl.innerHTML = '';
                    var makeButton = function (label, page, opts) {
                        opts = opts || {};
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'dt-page' + (opts.active ? ' active' : '') + (opts.ellipsis ? ' dt-ellipsis' : '');
                        btn.innerHTML = label;
                        if (opts.disabled || opts.ellipsis) {
                            btn.disabled = true;
                        }
                        if (!opts.ellipsis) {
                            btn.addEventListener('click', function () {
                                state.page = page;
                                render();
                            });
                        }
                        if (opts.aria) {
                            btn.setAttribute('aria-label', opts.aria);
                        }
                        return btn;
                    };

                    pagerEl.appendChild(makeButton('<i class="fas fa-angles-left"></i>', 1, {
                        disabled: state.page === 1, aria: 'First page'
                    }));
                    pagerEl.appendChild(makeButton('<i class="fas fa-angle-left"></i>', state.page - 1, {
                        disabled: state.page === 1, aria: 'Previous page'
                    }));

                    pageNumbers(state.page, totalPages).forEach(function (p) {
                        if (p === '…') {
                            pagerEl.appendChild(makeButton('…', 0, { ellipsis: true }));
                        } else {
                            pagerEl.appendChild(makeButton(String(p), p, {
                                active: p === state.page, aria: 'Page ' + p
                            }));
                        }
                    });

                    pagerEl.appendChild(makeButton('<i class="fas fa-angle-right"></i>', state.page + 1, {
                        disabled: state.page === totalPages, aria: 'Next page'
                    }));
                    pagerEl.appendChild(makeButton('<i class="fas fa-angles-right"></i>', totalPages, {
                        disabled: state.page === totalPages, aria: 'Last page'
                    }));
                }

                footerEl.hidden = false;
                visibleRowsCache = visible;
                updateSelectionUi(visible);
            }

            /* ---------------- Events ---------------- */
            var searchTimer = null;
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    if (searchClear) {
                        searchClear.hidden = searchInput.value === '';
                    }
                    window.clearTimeout(searchTimer);
                    searchTimer = window.setTimeout(function () {
                        state.query = searchInput.value.trim().toLowerCase();
                        state.page = 1;
                        render();
                    }, 150);
                });

                searchInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        searchInput.value = '';
                        if (searchClear) {
                            searchClear.hidden = true;
                        }
                        state.query = '';
                        state.page = 1;
                        render();
                    }
                });
            }

            if (searchClear) {
                searchClear.addEventListener('click', function () {
                    searchInput.value = '';
                    searchClear.hidden = true;
                    state.query = '';
                    state.page = 1;
                    render();
                    searchInput.focus();
                });
            }

            if (roleFilter) {
                roleFilter.addEventListener('change', function () {
                    state.role = roleFilter.value;
                    state.page = 1;
                    render();
                });
            }

            if (pageSizeSelect) {
                pageSizeSelect.addEventListener('change', function () {
                    state.pageSize = pageSizeSelect.value;
                    state.page = 1;
                    savePrefs();
                    render();
                });
            }

            Array.prototype.forEach.call(table.querySelectorAll('.dt-sortable'), function (th) {
                var key = th.getAttribute('data-sort-key');

                function toggleSort() {
                    if (state.sortKey === key) {
                        state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        state.sortKey = key;
                        state.sortDir = (key === 'created' || key === 'id') ? 'desc' : 'asc';
                    }
                    state.page = 1;
                    savePrefs();
                    render();
                }

                th.addEventListener('click', toggleSort);
                th.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        toggleSort();
                    }
                });
            });

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    visibleRowsCache.forEach(function (row) {
                        state.selected[row.id] = selectAll.checked;
                    });
                    render();
                });
            }

            tbody.addEventListener('change', function (event) {
                var input = event.target;
                if (!input.classList || !input.classList.contains('dt-row-check')) {
                    return;
                }
                var id = parseInt(input.value, 10);
                state.selected[id] = input.checked;
                updateSelectionUi(visibleRowsCache);
            });

            var clearSelectionBtn = document.getElementById('dtClearSelection');
            if (clearSelectionBtn) {
                clearSelectionBtn.addEventListener('click', clearSelection);
            }

            var bulkDeleteBtn = document.getElementById('dtBulkDelete');
            if (bulkDeleteBtn) {
                bulkDeleteBtn.addEventListener('click', function () {
                    var ids = selectedIds();
                    if (!ids.length) {
                        return;
                    }
                    if (!window.confirm('Delete ' + ids.length + ' selected admin account(s)? This cannot be undone.')) {
                        return;
                    }
                    bulkFields.innerHTML = '';
                    ids.forEach(function (id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'admin_ids[]';
                        input.value = id;
                        bulkFields.appendChild(input);
                    });
                    bulkForm.submit();
                });
            }

            var resetFiltersBtn = document.getElementById('dtResetFilters');
            if (resetFiltersBtn) {
                resetFiltersBtn.addEventListener('click', function () {
                    state.query = '';
                    state.role = 'all';
                    state.page = 1;
                    if (searchInput) {
                        searchInput.value = '';
                    }
                    if (searchClear) {
                        searchClear.hidden = true;
                    }
                    if (roleFilter) {
                        roleFilter.value = 'all';
                    }
                    render();
                });
            }

            /* Columns dropdown */
            if (columnsDropdown) {
                var columnsButton = columnsDropdown.querySelector('button');
                columnsButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    var open = columnsDropdown.classList.toggle('open');
                    columnsButton.setAttribute('aria-expanded', open ? 'true' : 'false');
                });

                var columnsMenu = columnsDropdown.querySelector('.dt-dropdown-menu');
                if (columnsMenu) {
                    columnsMenu.addEventListener('click', function (event) {
                        event.stopPropagation();
                    });
                }

                document.addEventListener('click', function () {
                    columnsDropdown.classList.remove('open');
                    columnsButton.setAttribute('aria-expanded', 'false');
                });

                Array.prototype.forEach.call(columnsDropdown.querySelectorAll('[data-column-toggle]'), function (input) {
                    input.addEventListener('change', function () {
                        var key = input.getAttribute('data-column-toggle');
                        var index = state.hiddenColumns.indexOf(key);

                        if (input.checked && index !== -1) {
                            state.hiddenColumns.splice(index, 1);
                        } else if (!input.checked && index === -1) {
                            state.hiddenColumns.push(key);
                        }

                        savePrefs();
                        applyColumns();
                    });
                });
            }

            /* CSV export of the current view */
            var exportBtn = document.getElementById('dtExport');
            if (exportBtn) {
                exportBtn.addEventListener('click', function () {
                    var list = filteredRows();
                    if (!list.length) {
                        window.alert('There is nothing to export with the current filters.');
                        return;
                    }

                    var escape = function (value) {
                        var text = String(value === null || value === undefined ? '' : value);
                        if (/[",\n]/.test(text)) {
                            return '"' + text.replace(/"/g, '""') + '"';
                        }
                        return text;
                    };

                    var lines = [['ID', 'Username', 'Email', 'Role', 'Created', 'Self'].join(',')];
                    list.forEach(function (row) {
                        lines.push([
                            row.id,
                            row.el.getAttribute('data-name') || '',
                            row.el.getAttribute('data-email') || '',
                            row.el.getAttribute('data-role-label') || '',
                            row.el.getAttribute('data-created-label') || '',
                            row.self ? 'Yes' : ''
                        ].map(escape).join(','));
                    });

                    var csv = lines.join('\r\n');
                    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                    var url = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    var stamp = new Date().toISOString().slice(0, 10);

                    link.href = url;
                    link.download = 'admin-users-' + stamp + '.csv';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                });
            }

            /* ---------------- Boot ---------------- */
            loadPrefs();

            if (pageSizeSelect) {
                var hasOption = false;
                Array.prototype.forEach.call(pageSizeSelect.options, function (option) {
                    if (option.value === String(state.pageSize)) {
                        hasOption = true;
                    }
                });
                if (!hasOption) {
                    state.pageSize = 10;
                }
                pageSizeSelect.value = String(state.pageSize);
            }

            if (!columnsFromStorage) {
                state.hiddenColumns = [];
                Array.prototype.forEach.call(document.querySelectorAll('[data-column-toggle]'), function (input) {
                    if (!input.checked) {
                        state.hiddenColumns.push(input.getAttribute('data-column-toggle'));
                    }
                });
            }

            applyColumns();
            render();
        })();
    </script>

    <script>
        // Auto-dismiss the flash message so it does not linger.
        (function () {
            var flash = document.getElementById('adminsFlash');
            if (!flash) {
                return;
            }
            window.setTimeout(function () {
                flash.style.transition = 'opacity 0.4s ease';
                flash.style.opacity = '0';
                window.setTimeout(function () {
                    if (flash.parentNode) {
                        flash.parentNode.removeChild(flash);
                    }
                }, 400);
            }, 6000);
        })();
    </script>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
