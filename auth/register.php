<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Redirect if already logged in as customer
if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer') {
    header('Location: ../profile.php');
    exit();
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    // Get and validate input
    $username = clean_input($_POST['username']);
    $email = clean_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = clean_input($_POST['full_name']);
    $phone = clean_input($_POST['phone']);
    $address = clean_input($_POST['address']);

    // Validation
    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }

    // Check if username or email already exists
    $stmt = $conn->prepare("SELECT id FROM customers WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        $errors[] = 'Username or email already exists';
    }

    // Create user if no errors
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Updated: Using full_name, phone, address and password (not password_hash)
            $stmt = $conn->prepare("INSERT INTO customers (username, email, password, full_name, phone, address) 
                                   VALUES (?, ?, ?, ?, ?, ?)");

            $stmt->execute([$username, $email, $password_hash, $full_name, $phone, $address]);

            // Get the new user ID
            $user_id = $conn->lastInsertId();

            // Auto-login after registration
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['user_role'] = 'customer';

            // Redirect to home
            header('Location: ../index.php?registered=1');
            exit();

        } catch (PDOException $e) {
            $errors[] = 'Registration failed: ' . $e->getMessage();
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
        max-width: 600px;
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

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
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

    .alert ul {
        margin: 0;
        padding-left: 1.5rem;
    }

    .alert li {
        margin-bottom: 0.5rem;
    }

    .password-strength {
        margin-top: 0.5rem;
        font-size: 0.9rem;
    }

    .strength-meter {
        height: 5px;
        background: #eee;
        border-radius: 3px;
        margin-top: 0.25rem;
        overflow: hidden;
    }

    .strength-fill {
        height: 100%;
        width: 0%;
        background: #dc3545;
        border-radius: 3px;
        transition: width 0.3s, background-color 0.3s;
    }
</style>

<div class="auth-container">
    <div class="auth-box">
        <div class="auth-header">
            <i class="fas fa-user-plus"></i>
            <h1>Create Account</h1>
            <p>Join our food ordering community</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="registerForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Username *</label>
                    <input type="text" id="username" name="username" class="form-control"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                        required>
                    <small class="form-text text-muted">At least 3 characters</small>
                </div>

                <div class="form-group">
                    <label for="full_name"><i class="fas fa-id-card"></i> Full Name *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                        value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>"
                        required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                        value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="address"><i class="fas fa-map-marker-alt"></i> Delivery Address</label>
                <textarea id="address" name="address" class="form-control"
                    rows="2"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password"><i class="fas fa-key"></i> Password *</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                    <div class="password-strength">
                        <div>Password strength: <span id="strengthText">Weak</span></div>
                        <div class="strength-meter">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-key"></i> Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    <div id="passwordMatch" class="form-text"></div>
                </div>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">I agree to the <a href="../terms.php" target="_blank">Terms of Service</a> and <a
                            href="../privacy.php" target="_blank">Privacy Policy</a></label>
                </div>
            </div>

            <button type="submit" class="btn-auth" id="submitBtn">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>

        <div class="auth-links">
            <p>Already have an account? <a href="login.php">Sign in here</a></p>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
    // Password strength checker
    document.getElementById('password').addEventListener('input', function () {
        const password = this.value;
        const strengthText = document.getElementById('strengthText');
        const strengthFill = document.getElementById('strengthFill');

        let strength = 0;

        // Check password length
        if (password.length >= 8) strength += 25;
        if (password.length >= 12) strength += 15;

        // Check for mixed case
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength += 20;

        // Check for numbers
        if (/\d/.test(password)) strength += 20;

        // Check for special characters
        if (/[^A-Za-z0-9]/.test(password)) strength += 20;

        // Update UI
        strengthFill.style.width = strength + '%';

        if (strength < 40) {
            strengthText.textContent = 'Weak';
            strengthText.style.color = '#dc3545';
            strengthFill.style.backgroundColor = '#dc3545';
        } else if (strength < 70) {
            strengthText.textContent = 'Fair';
            strengthText.style.color = '#ffc107';
            strengthFill.style.backgroundColor = '#ffc107';
        } else if (strength < 90) {
            strengthText.textContent = 'Good';
            strengthText.style.color = '#17a2b8';
            strengthFill.style.backgroundColor = '#17a2b8';
        } else {
            strengthText.textContent = 'Strong';
            strengthText.style.color = '#28a745';
            strengthFill.style.backgroundColor = '#28a745';
        }
    });

    // Password match checker
    document.getElementById('confirm_password').addEventListener('input', function () {
        const password = document.getElementById('password').value;
        const confirmPassword = this.value;
        const matchDiv = document.getElementById('passwordMatch');

        if (confirmPassword === '') {
            matchDiv.textContent = '';
            matchDiv.style.color = '';
        } else if (password === confirmPassword) {
            matchDiv.textContent = '✓ Passwords match';
            matchDiv.style.color = '#28a745';
        } else {
            matchDiv.textContent = '✗ Passwords do not match';
            matchDiv.style.color = '#dc3545';
        }
    });

    // Form validation
    document.getElementById('registerForm').addEventListener('submit', function (e) {
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;

        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Please make sure passwords match.');
            document.getElementById('confirm_password').focus();
        }
    });

    // Focus on username field
    document.getElementById('username').focus();
</script>
</body>

</html>