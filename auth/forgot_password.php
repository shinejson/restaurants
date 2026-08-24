<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean_input($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if customer exists
        $stmt = $conn->prepare("SELECT id, username FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $customer = $stmt->fetch();

        if ($customer) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store token
            $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            if ($stmt->execute([$email, $token, $expiry])) {
                // Send email
                $reset_link = BASE_URL . "/auth/reset_password.php?token=" . $token . "&email=" . urlencode($email);

                $subject = "Password Reset Request";
                $message = "
                <html>
                <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                    <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 5px;'>
                        <h2 style='color: #d35400;'>Password Reset Request</h2>
                        <p>Hi " . htmlspecialchars($customer['username']) . ",</p>
                        <p>We received a request to reset your password. Click the button below to choose a new password. This link will expire in 1 hour.</p>
                        
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='" . $reset_link . "' style='background: #e67e22; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Reset Password</a>
                        </div>
                        
                        <p>If you didn't request this, you can safely ignore this email.</p>
                        <p>Regards,<br>FoodExpress Team</p>
                        <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                        <p style='font-size: 0.8rem; color: #777;'>If the button doesn't work, copy and paste this link into your browser:<br>" . $reset_link . "</p>
                    </div>
                </body>
                </html>";

                if (send_email($email, $subject, $message)) {
                    $success = "A password reset link has been sent to your email.";
                } else {
                    $error = "Failed to send email. Please try again later.";
                }
            } else {
                $error = "An error occurred. Please try again.";
            }
        } else {
            // SECURITY: Don't reveal if email exists, but we'll show success for generic response or error for clarity
            // Given it's a small app, showing error might be more helpful, but for "Best Practice" we'd show success.
            // Let's go with a slightly vague success message.
            $success = "If an account exists with that email, a reset link has been sent.";
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
            <i class="fas fa-key"></i>
            <h1>Forgot Password</h1>
            <p>Enter your email to receive a reset link</p>
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
        <?php else: ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required autofocus>
                </div>

                <button type="submit" class="btn-auth">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>
        <?php endif; ?>

        <div class="auth-links">
            <p>Remember your password? <a href="login.php">Sign in here</a></p>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
</body>

</html>