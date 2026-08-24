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
?>
<?php include '../includes/admin_header.php'; ?>

<div class="content-header">
    <div>
        <h1>Manage Admins</h1>
        <p>View and manage administrative users.</p>
    </div>
    <a href="create.php" class="btn-primary"><i class="fas fa-plus"></i> Add New Admin</a>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($admin['username']); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($admin['email']); ?>
                        </td>
                        <td>
                            <?php
                            $role_class = 'badge-secondary';
                            $role_name = 'Staff';
                            if (($admin['role'] ?? '') === 'admin') {
                                $role_class = 'badge-danger';
                                $role_name = 'Super Admin';
                            } elseif (($admin['role'] ?? '') === 'manager') {
                                $role_class = 'badge-warning';
                                $role_name = 'Manager';
                            }
                            ?>
                            <span class="badge <?php echo $role_class; ?>">
                                <?php echo $role_name; ?>
                            </span>
                        </td>
                        <td>
                            <?php echo date('M d, Y', strtotime($admin['created_at'])); ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="edit.php?id=<?php echo $admin['id']; ?>" class="btn-icon" title="Edit"><i
                                        class="fas fa-edit"></i></a>
                                <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                    <form method="POST"
                                        onsubmit="return confirm('Are you sure you want to delete this admin?');"
                                        style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="delete_id" value="<?php echo $admin['id']; ?>">
                                        <button type="submit" class="btn-icon delete" title="Delete"><i
                                                class="fas fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>