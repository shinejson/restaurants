<?php
// Database configuration
if (session_status() === PHP_SESSION_NONE) {
    // SECURITY: Harden Session Cookies
    // Prevent JavaScript access to session cookie (XSS protection)
    // Only send cookie over HTTPS if available
    // Prevent CSRF via strict same-site policy
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => isset($_SERVER['HTTPS']), // Only secure if HTTPS is on
        'httponly' => true, // Prevent JS access
        'samesite' => 'Strict' // CSRF protection
    ]);

    session_start();
}

// Global Session Timeout (30 minutes for better security)
$is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['admin_id']);
if ($is_logged_in && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > 1800) {
        $msg = "Your session has expired due to inactivity. Please login again.";
        // Clear all session data
        session_unset();
        session_destroy();

        // Start a fresh empty session so the page can still load without errors
        session_start();
        $_SESSION['session_msg'] = $msg;

        // Note: We do NOT redirect here anymore. 
        // This allows public pages (index.php) to simply show the "logged out" navbar.
        // Protected pages (profile.php, admin/*) have their own checks that will 
        // redirect to login if the session is empty.
    }
}
$_SESSION['last_activity'] = time();

// SECURITY: Allow loading from Environment Variables (Best Practice)
// Fallback to hardcoded values only if env vars are missing
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'food_ordering');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Create connection
try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Simplified and more reliable BASE_URL detection
// Only define BASE_URL if we're in a web context (not CLI)
if (php_sapi_name() !== 'cli') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];

    // Since your app is in /restaurants/ subdirectory on localhost:8080
    // We'll detect this automatically
    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

    // Extract the base path (everything before the actual script file)
    // For example: /restaurants/admin/dashboard.php -> /restaurants
    if (preg_match('#^(/[^/]+)/#', $script_name, $matches)) {
        $base_path = $matches[1];
    } else {
        $base_path = '';
    }

    define('BASE_URL', $protocol . "://" . $host . $base_path);
} else {
    // For CLI scripts, define a dummy BASE_URL
    define('BASE_URL', '');
}

// Set timezone
date_default_timezone_set('UTC');
?>