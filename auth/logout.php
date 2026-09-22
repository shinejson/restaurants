<?php
session_start();
require_once '../config/db.php';

// Clear remember me token if exists
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE token = ?");
    $stmt->execute([hash('sha256', $token)]);

    // Clear cookie
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Unset all session variables
$_SESSION = [];

// Destroy the session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Redirect to home page
header('Location: ' . tenant_url('index.php'));
exit();
?>