<?php
/**
 * RBAC setup / repair
 * ------------------------------------------------------------------
 * Creates the `roles` and `role_permissions` tables used by
 * Admin > Admins > User Roles and seeds the built-in roles.
 *
 * Safe to run as many times as you like: everything is CREATE IF NOT
 * EXISTS / INSERT IGNORE, and no existing admin account is touched.
 */
require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/permissions.php';

require_permission('manage_settings');

$admin_title = 'RBAC Setup';
$is_super = is_super_admin();

$messages = [];
$errors = [];

/** Does a table exist? */
function rbac_table_exists($conn, $table)
{
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_ok = isset($_POST['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token']);

    if (!$csrf_ok) {
        $errors[] = 'Invalid security token. Please try again.';
    } elseif (!$is_super) {
        $errors[] = 'Only a Super Admin can run the RBAC setup.';
    } else {
        if (rbac_ensure_schema($conn, true)) {
            $messages[] = 'RBAC tables are in place — built-in roles (Super Admin, Manager, Staff) have been seeded.';
        } else {
            $errors[] = 'The tables could not be created. The database user needs CREATE TABLE permission on "' . DB_NAME . '".';
        }
    }
}

$tables = [
    'roles' => rbac_table_exists($conn, 'roles'),
    'role_permissions' => rbac_table_exists($conn, 'role_permissions'),
];
$rbac_ok = $tables['roles'] && $tables['role_permissions'];

$role_rows = [];
$position_rows = [];
if ($rbac_ok) {
    try {
        $role_rows = $conn->query("SELECT * FROM roles ORDER BY is_system DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errors[] = 'Could not read the roles table: ' . $e->getMessage();
    }

    try {
        $stmt = $conn->query("SELECT role_slug, COUNT(*) AS overrides FROM role_permissions GROUP BY role_slug");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $position_rows[$row['role_slug']] = (int) $row['overrides'];
        }
    } catch (PDOException $e) {
        // Not fatal — the matrix simply has no overrides yet.
    }
}

$user_counts = rbac_role_user_counts();

include '../includes/admin_header.php';
?>

<style>
    .rbac-status {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .rbac-status .card {
        margin-bottom: 0;
        padding: 1.25rem;
    }

    .rbac-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.78rem;
        font-weight: 800;
        padding: 0.25rem 0.7rem;
        border-radius: 999px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .rbac-pill.ok {
        background: rgba(16, 185, 129, 0.12);
        color: #047857;
    }

    .rbac-pill.missing {
        background: rgba(239, 68, 68, 0.1);
        color: #b91c1c;
    }
</style>

<div class="content-header">
    <div>
        <h1><i class="fas fa-shield-halved"></i> RBAC Setup</h1>
        <p>Roles &amp; permission tables used by Manage Admins &rsaquo; User Roles.</p>
    </div>
    <a href="admins/index.php?tab=roles" class="btn-primary">
        <i class="fas fa-user-tag"></i> Open User Roles
    </a>
</div>

<?php foreach ($messages as $message): ?>
    <div class="alert alert-success"
        style="background:#c6f6d5;color:#22543d;padding:1rem;border-radius:12px;margin-bottom:1.25rem;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endforeach; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"
        style="background:#fed7d7;color:#c53030;padding:1rem;border-radius:12px;margin-bottom:1.25rem;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endforeach; ?>

<div class="rbac-status">
    <div class="card">
        <h3 style="margin:0 0 0.6rem;"><i class="fas fa-table"></i> Table status</h3>
        <p style="margin:0 0 0.35rem;">
            <code>roles</code>
            <span class="rbac-pill <?php echo $tables['roles'] ? 'ok' : 'missing'; ?>">
                <?php echo $tables['roles'] ? 'ready' : 'missing'; ?>
            </span>
        </p>
        <p style="margin:0;">
            <code>role_permissions</code>
            <span class="rbac-pill <?php echo $tables['role_permissions'] ? 'ok' : 'missing'; ?>">
                <?php echo $tables['role_permissions'] ? 'ready' : 'missing'; ?>
            </span>
        </p>
    </div>

    <div class="card">
        <h3 style="margin:0 0 0.6rem;"><i class="fas fa-database"></i> Database</h3>
        <p style="margin:0; color: var(--text-muted); font-size: 0.9rem;">
            <?php echo htmlspecialchars(DB_NAME); ?> @ <?php echo htmlspecialchars(DB_HOST); ?>
        </p>
    </div>

    <div class="card">
        <h3 style="margin:0 0 0.6rem;"><i class="fas fa-screwdriver-wrench"></i> Action</h3>
        <?php if ($is_super): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-play"></i> <?php echo $rbac_ok ? 'Re-run setup (safe)' : 'Create RBAC tables'; ?>
                </button>
            </form>
            <p style="margin:0.75rem 0 0; color: var(--text-muted); font-size: 0.85rem;">
                Re-running only inserts anything that is missing.
            </p>
        <?php else: ?>
            <p style="margin:0; color: var(--text-muted); font-size: 0.9rem;">
                Only a Super Admin can run this setup.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($rbac_ok): ?>
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:1.25rem; border-bottom:1px solid var(--border-color);">
            <h3 style="margin:0;"><i class="fas fa-list"></i> Roles in the database</h3>
        </div>
        <div class="table-responsive" style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Key</th>
                        <th>Type</th>
                        <th>Admins</th>
                        <th>Custom permission rows</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($role_rows)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:2rem; color:var(--text-muted);">
                                No roles stored yet — run the setup to seed the built-in ones.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($role_rows as $role_row): ?>
                            <tr>
                                <td>
                                    <i class="fas <?php echo htmlspecialchars($role_row['icon']); ?>"
                                        style="color: <?php echo htmlspecialchars($role_row['color']); ?>;"></i>
                                    <strong><?php echo htmlspecialchars($role_row['name']); ?></strong>
                                </td>
                                <td><code><?php echo htmlspecialchars($role_row['slug']); ?></code></td>
                                <td><?php echo ((int) $role_row['is_system'] === 1) ? 'Built-in' : 'Custom'; ?></td>
                                <td><?php echo isset($user_counts[$role_row['slug']]) ? (int) $user_counts[$role_row['slug']] : 0; ?></td>
                                <td><?php echo isset($position_rows[$role_row['slug']]) ? (int) $position_rows[$role_row['slug']] : 0; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
