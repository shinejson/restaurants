<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if (empty($token) || empty($email)) {
    die("Invalid request. Missing token or email.");
}

// Verify token
$stmt = $conn->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
$stmt->execute([$email, $token]);
$reset_request = $stmt->fetch();

if (!$reset_request) {
    die("Invalid or expired reset link. Please request a new one.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Update customer password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("UPDATE customers SET password = ? WHERE email = ?");
            $stmt->execute([$password_hash, $email]);

            // Delete the reset token
            $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt->execute([$email]);

            $conn->commit();
            $success = "Your password has been reset successfully. You can now log in.";
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Failed to update password. Please try again.";
        }
    }
}
?>
<?php include '../includes/header.php'; ?>

<style>
    .auth-container {
        min-height: 80vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
    }

    .auth-box {
        background: white;
        padding: 2.5rem;
        border-radius: 10px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        width: 100%;
        max-width: 450px;
    }

    .auth-header {
        text-align: center;
        margin-bottom: 2rem;
    }

    .auth-header h1 {
        color: var(--dark-color);
        margin-bottom: 0.5rem;
    }

    .auth-header p {
        color: #666;
    }

    .auth-header i {
        color: var(--primary-color);
        font-size: 3rem;
        margin-bottom: 1rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: #333;
        font-weight: 500;
    }

    .form-control {
        width: 100%;
        padding: 0.8rem 1rem;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 1rem;
        transition: border-color 0.3s;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
    }

    .btn-auth {
        width: 100%;
        padding: 0.8rem;
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 4px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.3s;
    }

    .btn-auth:hover {
        background: #e55a2b;
    }

    .auth-links {
        text-align: center;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #eee;
    }

    .auth-links a {
        color: var(--primary-color);
        text-decoration: none;
    }

    .alert {
        padding: 1rem;
        border-radius: 4px;
        margin-bottom: 1rem;
        border: 1px solid transparent;
    }

    .alert-danger {
        background: #fed7d7;
        border-color: #fc8181;
        color: #c53030;
    }

    .alert-success {
        background: #c6f6d5;
        border-color: #9ae6b4;
        color: #22543d;
    }
</style>

<div class="auth-container">
    <div class="auth-box">
        <div class="auth-header">
            <i class="fas fa-lock-open"></i>
            <h1>Reset Password</h1>
            <p>Enter your new password below</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <div class="auth-links">
                <a href="<?php echo tenant_url('auth/login.php'); ?>" class="btn-auth" style="text-decoration: none; text-align: center; display: block;">
                    <i class="fas fa-sign-in-alt"></i> Go to Login
                </a>
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="password"><i class="fas fa-key"></i> New Password</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="8"
                        autofocus>
                </div>

                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-key"></i> Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required
                        minlength="8">
                </div>

                <button type="submit" class="btn-auth">
                    <i class="fas fa-save"></i> Update Password
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
</body>

</html>