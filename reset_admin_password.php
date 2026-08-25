<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($username === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'Please complete all fields.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ?");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if (!$admin) {
                    $stmt = $conn->prepare("INSERT INTO admins (username, password, email, role, created_at) VALUES (?, ?, ?, 'admin', NOW())");
                    $stmt->execute([$username, password_hash($newPassword, PASSWORD_DEFAULT), 'admin@local.local']);
                    $message = 'Admin account created successfully. You can now login with the new password.';
                } else {
                    $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $admin['id']]);
                    $message = 'Admin password updated successfully.';
                }
            } catch (Exception $e) {
                $error = 'Error updating password: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Admin Password</title>
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #f4f6fb;
            font-family: Arial, sans-serif;
        }
        .reset-box {
            width: min(420px, 92vw);
            background: white;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            padding: 2rem;
        }
        .reset-box h1 {
            margin: 0 0 0.5rem;
            font-size: 1.8rem;
        }
        .reset-box p {
            margin: 0 0 1.25rem;
            color: #666;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display:block; margin-bottom:0.45rem; font-weight:600; }
        .form-control {
            width: 100%;
            padding: 0.9rem 0.8rem;
            border: 1px solid #dfe7ef;
            border-radius: 8px;
            box-sizing: border-box;
        }
        .btn {
            width: 100%;
            border: none;
            border-radius: 8px;
            background: #667eea;
            color: white;
            padding: 0.9rem 1rem;
            font-weight: 700;
            cursor: pointer;
        }
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 600;
        }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .helper { margin-top: 1rem; text-align: center; }
        .helper a { color: #667eea; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="reset-box">
        <h1>Reset Admin Password</h1>
        <p>Use this only if you've forgotten the admin login password.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-group">
                <label for="username">Admin username</label>
                <input type="text" id="username" name="username" class="form-control" value="admin" required>
            </div>

            <div class="form-group">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
            </div>

            <button type="submit" class="btn">Update Password</button>
        </form>

        <div class="helper">
            <a href="<?php echo BASE_URL; ?>/admin/login.php">Back to admin login</a>
        </div>
    </div>
</body>
</html>