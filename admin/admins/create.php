<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_admins');

// Roles come from the RBAC tables so custom roles can be assigned here too.
$available_roles = rbac_roles();

$admin_title = 'Add New Admin';

$error = '';
$success = '';

// Check for messages from redirect
if (isset($_SESSION['admin_success'])) {
    $success = $_SESSION['admin_success'];
    unset($_SESSION['admin_success']);
}
if (isset($_SESSION['admin_error'])) {
    $error = $_SESSION['admin_error'];
    unset($_SESSION['admin_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['admin_error'] = "Invalid security token.";
        header('Location: create.php');
        exit;
    } else {
        $username = clean_input($_POST['username']);
        $email = clean_input($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        $role = clean_input($_POST['role'] ?? 'staff');

        if (!isset($available_roles[$role])) {
            $_SESSION['admin_error'] = "Please choose a valid role.";
        } elseif (strlen($username) < 3) {
            $_SESSION['admin_error'] = "Username too short.";
        } elseif (strlen($password) < 6) {
            $_SESSION['admin_error'] = "Password too short.";
        } elseif ($password !== $confirm_password) {
            $_SESSION['admin_error'] = "Passwords do not match.";
        } else {
            // Check existence - only check email if it's not empty
            if (!empty($email)) {
                $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
            } else {
                $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ?");
                $stmt->execute([$username]);
            }

            if ($stmt->fetch()) {
                $_SESSION['admin_error'] = "Username or email already exists.";
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO admins (username, email, password, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $email, $hash, $role]);
                    $_SESSION['admin_success'] = "Admin created successfully.";
                } catch (PDOException $e) {
                    $_SESSION['admin_error'] = "Error creating admin: " . $e->getMessage();
                }
            }
        }
        header('Location: create.php');
        exit;
    }
}
?>
<?php include '../includes/admin_header.php'; ?>

<div class="content-header">
    <div>
        <h1>Add New Admin</h1>
        <p>Create a new administrative user.</p>
    </div>
    <a href="index.php?tab=users" class="btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <?php if ($error): ?>
        <div id="errorAlert" class="alert alert-danger" style="transition: opacity 0.5s ease-out;">
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
        <div id="successAlert" class="alert alert-success" style="transition: opacity 0.5s ease-out;">
            <?php echo htmlspecialchars($success); ?>
            <p><a href="index.php?tab=users">Return to Admin List</a></p>
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
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Email (Optional)</label>
                <input type="email" name="email" class="form-control">
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Privilege / Access Level</label>
                <select name="role" class="form-control" required>
                    <?php foreach ($available_roles as $role_slug => $role_option): ?>
                        <option value="<?php echo htmlspecialchars($role_slug); ?>" <?php echo $role_slug === 'staff' ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role_option['name']); ?>
                            <?php echo !empty($role_option['is_system']) ? '' : ' (custom role)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="display:block; margin-top:0.4rem; color: var(--text-muted);">
                    What each role can do is configured under
                    <a href="index.php?tab=roles">Manage Admins &rsaquo; User Roles</a>.
                </small>
            </div>

            <button type="submit" class="btn-primary">Create Admin</button>
        </form>
    <?php endif; ?>
</div>

<?php include '../includes/admin_footer.php'; ?>