<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Redirect if already logged in as customer
if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer') {
    header('Location: ' . tenant_url('profile.php'));
    exit();
}

// Support redirect parameter
$redirect = clean_input($_GET['redirect'] ?? $_POST['redirect'] ?? '');
if (empty($redirect) && !empty($_SESSION['redirect_url'])) {
    $redirect = $_SESSION['redirect_url'];
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$info_msg = '';

if (isset($_SESSION['session_msg'])) {
    $info_msg = $_SESSION['session_msg'];
    unset($_SESSION['session_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean_input($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);

    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid security token';
    } else {
        // Check user credentials
        $stmt = $conn->prepare("SELECT id, username, email, password FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if (password_verify($password, $user['password'])) {
                // Regenerate session ID for security
                session_regenerate_id(true);

                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_role'] = 'customer';

                // Redirect to target or home
                if (!empty($redirect)) {
                    unset($_SESSION['redirect_url']);
                    if (str_starts_with($redirect, 'http://') || str_starts_with($redirect, 'https://')) {
                        header('Location: ' . $redirect);
                    } else {
                        header('Location: ' . tenant_url(ltrim($redirect, '/')));
                    }
                    exit();
                }

                header('Location: ' . tenant_url('index.php'));
                exit();
            } else {
                $error = 'Invalid email or password. Please verify your credentials or register for an account.';
            }
        } else {
            $error = 'Invalid email or password. If you have not registered with this restaurant yet, please click "Sign up here" below.';
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

    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
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

    .admin-login-link {
        text-align: center;
        margin-top: 1rem;
    }
</style>

<div class="auth-container">
    <div class="auth-box">
        <div class="auth-header">
            <i class="fas fa-sign-in-alt"></i>
            <h1>Welcome Back</h1>
            <p>Sign in to <strong><?php echo htmlspecialchars(get_setting('company_name', 'our restaurant')); ?></strong></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($info_msg): ?>
            <div class="alert alert-info"
                style="background: #e3f2fd; border-color: #bbdefb; color: #0d47a1; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; border: 1px solid;">
                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($info_msg); ?>
            </div>
        <?php endif; ?>

        <?php if (function_exists('current_tenant') && current_tenant()?->slug() === 'demo'): ?>
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; font-size: 0.85rem; color: #166534; line-height: 1.4;">
                <div style="font-weight: 700; margin-bottom: 0.2rem;"><i class="fas fa-flask"></i> Demo Customer Credentials:</div>
                Email: <code>ama.boateng@example.com</code> &bull; Password: <code>demo1234</code><br>
                <a href="#" id="fillDemoCustomer" style="color: #15803d; font-weight: 600; text-decoration: underline; display: inline-block; margin-top: 0.3rem;">
                    &rarr; Click here to auto-fill demo customer
                </a>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <?php if (!empty($redirect)): ?>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                <input type="email" id="email" name="email" class="form-control"
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required
                    autofocus>
            </div>

            <div class="form-group">
                <label for="password"><i class="fas fa-key"></i> Password</label>
                <input type="password" id="password" name="password" class="form-control" required>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Remember me</label>
                </div>
            </div>

            <button type="submit" class="btn-auth">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>

            <?php
            $googleActive   = (get_setting('google_login_enabled', '0') === '1') && !empty(get_setting('google_client_id'));
            $facebookActive = (get_setting('facebook_login_enabled', '0') === '1') && !empty(get_setting('facebook_app_id'));
            $appleActive    = (get_setting('apple_login_enabled', '0') === '1') && !empty(get_setting('apple_client_id'));
            $twitterActive  = (get_setting('twitter_login_enabled', '0') === '1') && !empty(get_setting('twitter_client_id'));

            $hasActiveSocials = $googleActive || $facebookActive || $appleActive || $twitterActive;
            ?>

            <?php if ($hasActiveSocials): ?>
            <div style="text-align: center; margin: 1.5rem 0; position: relative;">
                <hr style="border: 0; border-top: 1px solid #eee;">
                <span style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: white; padding: 0 10px; color: #666; font-size: 0.9rem;">or continue with</span>
            </div>

            <div style="display: grid; gap: 0.85rem;">
                <?php if ($googleActive): ?>
                <!-- Google Login -->
                <a href="<?php echo tenant_url('auth/google_login.php'); ?>" class="btn-social"
                    style="display: flex; align-items: center; justify-content: center; gap: 10px; padding: 0.8rem; background: white; border: 1px solid #ddd; border-radius: 4px; color: #444; font-weight: 500; text-decoration: none; transition: all 0.2s;">
                    <img src="https://www.svgrepo.com/show/475656/google-color.svg" alt="Google"
                        style="width: 20px; height: 20px;">
                    Continue with Gmail
                </a>
                <?php endif; ?>

                <?php if ($facebookActive): ?>
                <!-- Facebook Login -->
                <a href="<?php echo tenant_url('auth/facebook_login.php'); ?>" class="btn-social"
                    style="display: flex; align-items: center; justify-content: center; gap: 10px; padding: 0.8rem; background: #1877F2; border: 1px solid #1877F2; border-radius: 4px; color: white; font-weight: 500; text-decoration: none; transition: all 0.2s;">
                    <i class="fab fa-facebook-f" style="font-size: 1.15rem;"></i>
                    Continue with Facebook
                </a>
                <?php endif; ?>

                <?php if ($appleActive): ?>
                <!-- Apple Login -->
                <a href="<?php echo tenant_url('auth/apple_login.php'); ?>" class="btn-social"
                    style="display: flex; align-items: center; justify-content: center; gap: 10px; padding: 0.8rem; background: #000; border: 1px solid #000; border-radius: 4px; color: white; font-weight: 500; text-decoration: none; transition: all 0.2s;">
                    <i class="fab fa-apple" style="font-size: 1.2rem;"></i>
                    Continue with iPhone
                </a>
                <?php endif; ?>

                <?php if ($twitterActive): ?>
                <!-- Twitter / X Login -->
                <a href="<?php echo tenant_url('auth/twitter_login.php'); ?>" class="btn-social"
                    style="display: flex; align-items: center; justify-content: center; gap: 10px; padding: 0.8rem; background: #0f1419; border: 1px solid #0f1419; border-radius: 4px; color: white; font-weight: 500; text-decoration: none; transition: all 0.2s;">
                    <i class="fab fa-x-twitter" style="font-size: 1.15rem;"></i>
                    Continue with X
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </form>

        <div class="auth-links">
            <p>Don't have an account? <a href="<?php echo tenant_url('auth/register.php' . (!empty($redirect) ? '?redirect=' . urlencode($redirect) : '')); ?>">Sign up here</a></p>
            <p><a href="<?php echo tenant_url('auth/forgot_password.php'); ?>">Forgot your password?</a></p>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
    // Focus on email field
    document.getElementById('email').focus();

    const fillDemo = document.getElementById('fillDemoCustomer');
    if (fillDemo) {
        fillDemo.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('email').value = 'ama.boateng@example.com';
            document.getElementById('password').value = 'demo1234';
        });
    }
</script>
</body>

</html>