<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_admins');

$admin_title = 'Manage Admins';

// Handle deletion
if (isset($_POST['delete_id'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token.";
    } else {
        $delete_id = clean_input($_POST['delete_id']);
        // Prevent deleting yourself
        if ($delete_id == $_SESSION['admin_id']) {
            $error = "You cannot delete your own account.";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
                $stmt->execute([$delete_id]);
                $success = "Admin deleted successfully.";
            } catch (PDOException $e) {
                $error = "Error deleting admin: " . $e->getMessage();
            }
        }
    }
}

// Fetch admins
$stmt = $conn->query("SELECT * FROM admins ORDER BY created_at DESC");
$admins = $stmt->fetchAll();

// Role stats
$stats = ['total' => count($admins), 'admin' => 0, 'manager' => 0, 'staff' => 0];
foreach ($admins as $a) {
    $role = $a['role'] ?? 'staff';
    if (isset($stats[$role])) {
        $stats[$role]++;
    } else {
        $stats['staff']++;
    }
}

function admin_initials($name)
{
    $parts = preg_split('/[\s_.-]+/', trim($name));
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $ini .= strtoupper($part[0]);
        }
    }
    return $ini ?: 'A';
}
?>
<?php include '../includes/admin_header.php'; ?>

<style>
    .admins-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 14px;
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
    }

    .stat-card .stat-label {
        font-size: 0.78rem;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .admins-table {
        width: 100%;
        border-collapse: collapse;
    }

    .admins-table th,
    .admins-table td {
        padding: 0.9rem 1.1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.92rem;
        vertical-align: middle;
    }

    .admins-table th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        background: var(--light-bg);
    }

    .admins-table tbody tr:hover {
        background: rgba(249, 115, 22, 0.03);
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
    }

    .role-admin {
        background: rgba(220, 38, 38, 0.1);
        color: #b91c1c;
    }

    .role-manager {
        background: rgba(245, 158, 11, 0.12);
        color: #b45309;
    }

    .role-staff {
        background: rgba(100, 116, 139, 0.12);
        color: #475569;
    }

    /* Horizontal action buttons */
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
</style>

<div class="content-header">
    <div>
        <h1><i class="fas fa-users-cog"></i> Manage Admins</h1>
        <p>View and manage administrative users.</p>
    </div>
    <a href="create.php" class="btn-primary"><i class="fas fa-plus"></i> Add New Admin</a>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<!-- Stats -->
<div class="admins-stats">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(59,130,246,0.12); color: #1d4ed8;"><i class="fas fa-users"></i></div>
        <div>
            <div class="stat-num"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Admins</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(220,38,38,0.1); color: #b91c1c;"><i class="fas fa-user-shield"></i></div>
        <div>
            <div class="stat-num"><?php echo $stats['admin']; ?></div>
            <div class="stat-label">Super Admins</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245,158,11,0.12); color: #b45309;"><i class="fas fa-user-tie"></i></div>
        <div>
            <div class="stat-num"><?php echo $stats['manager']; ?></div>
            <div class="stat-label">Managers</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(100,116,139,0.12); color: #475569;"><i class="fas fa-user"></i></div>
        <div>
            <div class="stat-num"><?php echo $stats['staff']; ?></div>
            <div class="stat-label">Staff</div>
        </div>
    </div>
</div>

<div class="card" style="padding: 0; border-radius: 14px; overflow: hidden;">
    <?php if (empty($admins)): ?>
        <div style="text-align: center; padding: 3rem;">
            <i class="fas fa-users-cog fa-3x" style="color: var(--border-color); margin-bottom: 1rem;"></i>
            <h3 style="margin: 0 0 0.4rem;">No admins found</h3>
            <p style="color: var(--text-muted); margin: 0 0 1.25rem;">Add your first administrator to get started.</p>
            <a href="create.php" class="btn-primary"><i class="fas fa-plus"></i> Add Admin</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admins-table">
                <thead>
                    <tr>
                        <th>Admin</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <?php
                        $role = $admin['role'] ?? 'staff';
                        $role_name = $role === 'admin' ? 'Super Admin' : ($role === 'manager' ? 'Manager' : 'Staff');
                        $role_icon = $role === 'admin' ? 'fa-user-shield' : ($role === 'manager' ? 'fa-user-tie' : 'fa-user');
                        $is_self = $admin['id'] == $_SESSION['admin_id'];
                        $avatar_colors = ['#ff6b35', '#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899'];
                        $avatar_color = $avatar_colors[$admin['id'] % count($avatar_colors)];
                        ?>
                        <tr>
                            <td>
                                <div class="admin-user">
                                    <div class="admin-avatar"
                                        style="width:38px;height:38px;border-radius:50%;background:<?php echo $avatar_color; ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;flex-shrink:0;">
                                        <?php echo admin_initials($admin['username']); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 700;">
                                            <?php echo htmlspecialchars($admin['username']); ?>
                                            <?php if ($is_self): ?><span class="you-badge you">You</span><?php endif; ?>
                                        </div>
                                        <small style="color: var(--text-muted);">ID: <?php echo $admin['id']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($admin['email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($admin['email']); ?>"
                                        style="color: var(--text-main); text-decoration: none;">
                                        <?php echo htmlspecialchars($admin['email']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="role-badge role-<?php echo htmlspecialchars($role); ?>">
                                    <i class="fas <?php echo $role_icon; ?>" style="font-size: 0.7rem;"></i>
                                    <?php echo $role_name; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($admin['created_at'])); ?>
                            </td>
                            <td>
                                <div class="admins-actions">
                                    <a href="edit.php?id=<?php echo $admin['id']; ?>" class="act-btn edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if (!$is_self): ?>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this admin?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="delete_id" value="<?php echo $admin['id']; ?>">
                                            <button type="submit" class="act-btn delete" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="act-btn" style="opacity: 0.35; cursor: not-allowed;" title="You cannot delete your own account">
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
    <?php endif; ?>
</div>

<?php include '../includes/admin_footer.php'; ?>