<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_admins');

$admin_title = 'Edit Admin';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$error = '';
$success = '';

// Check for messages from redirect
if (isset($_SESSION['admin_edit_success'])) {
    $success = $_SESSION['admin_edit_success'];
    unset($_SESSION['admin_edit_success']);
}
if (isset($_SESSION['admin_edit_error'])) {
    $error = $_SESSION['admin_edit_error'];
    unset($_SESSION['admin_edit_error']);
}

// Fetch admin details
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$id]);
$admin = $stmt->fetch();

if (!$admin) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['admin_edit_error'] = "Invalid security token.";
        header("Location: edit.php?id=$id");
        exit;
    } else {
        $username = clean_input($_POST['username']);
        $email = clean_input($_POST['email']);
        $role = clean_input($_POST['role']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if (strlen($username) < 3) {
            $_SESSION['admin_edit_error'] = "Username too short.";
            header("Location: edit.php?id=$id");
            exit;
        } else {
            // Check if username already exists for another user
            $stmt = $conn->prepare("SELECT id FROM admins WHERE (username = ? OR (email != '' AND email = ?)) AND id != ?");
            $stmt->execute([$username, $email, $id]);
            if ($stmt->fetch()) {
                $_SESSION['admin_edit_error'] = "Username or email already exists.";
                header("Location: edit.php?id=$id");
                exit;
            } else {
                try {
                    $conn->beginTransaction();

                    // Update basic info
                    $stmt = $conn->prepare("UPDATE admins SET username = ?, email = ?, role = ? WHERE id = ?");
                    $stmt->execute([$username, $email, $role, $id]);

                    // Update password if provided
                    if (!empty($password)) {
                        if (strlen($password) < 6) {
                            $conn->rollBack();
                            $_SESSION['admin_edit_error'] = "Password too short.";
                            header("Location: edit.php?id=$id");
                            exit;
                        } elseif ($password !== $confirm_password) {
                            $conn->rollBack();
                            $_SESSION['admin_edit_error'] = "Passwords do not match.";
                            header("Location: edit.php?id=$id");
                            exit;
                        } else {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                            $stmt->execute([$hash, $id]);
                        }
                    }

                    $conn->commit();
                    $_SESSION['admin_edit_success'] = "Admin updated successfully.";
                    header("Location: edit.php?id=$id");
                    exit;
                } catch (PDOException $e) {
                    $conn->rollBack();
                    $_SESSION['admin_edit_error'] = "Error updating admin: " . $e->getMessage();
                    header("Location: edit.php?id=$id");
                    exit;
                }
            }
        }
    }
}

include '../includes/admin_header.php';
?>

<div class="content-header">
    <div>
        <h1>Edit Admin:
            <?php echo htmlspecialchars($admin['username']); ?>
        </h1>
        <p>Modify administrative user details and permissions.</p>
    </div>
    <a href="index.php" class="btn-primary" style="background: var(--text-muted); color: white; box-shadow: none;">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <?php if ($error): ?>
        <div id="errorAlert" class="alert alert-danger"
            style="background: #fed7d7; color: #c53030; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; transition: opacity 0.5s ease-out;">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <script>
            setTimeout(function () {
                var alert = document.getElementById('errorAlert');
                if (alert) {
                    alert.style.opacity = '0';
                    setTimeout(function () { alert.remove(); }, 500);
                }
            }, 5000);
        </script>
    <?php endif; ?>

    <?php if ($success): ?>
        <div id="successAlert" class="alert alert-success"
            style="background: #c6f6d5; color: #22543d; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; transition: opacity 0.5s ease-out;">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
        <script>
            setTimeout(function () {
                var alert = document.getElementById('successAlert');
                if (alert) {
                    alert.style.opacity = '0';
                    setTimeout(function () { alert.remove(); }, 500);
                }
            }, 5000);
        </script>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" class="form-control"
                value="<?php echo htmlspecialchars($admin['username']); ?>" required>
        </div>

        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-control"
                value="<?php echo htmlspecialchars($admin['email']); ?>">
        </div>

        <div class="form-group">
            <label>Privilege / Access Level</label>
            <select name="role" class="form-control" required>
                <option value="staff" <?php echo $admin['role'] === 'staff' ? 'selected' : ''; ?>>Staff (Limited Access)
                </option>
                <option value="manager" <?php echo $admin['role'] === 'manager' ? 'selected' : ''; ?>>Manager
                    (Intermediate Access)</option>
                <option value="admin" <?php echo $admin['role'] === 'admin' ? 'selected' : ''; ?>>Super Admin (Full
                    Access)</option>
            </select>
        </div>

        <hr style="margin: 2rem 0; border: 0; border-top: 1px solid var(--border-color);">
        <h4 style="margin-bottom: 1rem;"><i class="fas fa-key"></i> Change Password (Hidden)</h4>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">Only fill these fields if you
            want to change the admin's password.</p>

        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="password" class="form-control"
                placeholder="Leave blank to keep current password">
        </div>

        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password">
        </div>

        <div style="margin-top: 2rem;">
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center;">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </form>
</div>

<?php include '../includes/admin_footer.php'; ?>