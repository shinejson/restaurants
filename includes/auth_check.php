<?php
session_start();

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'customer') {
    require_once __DIR__ . '/../config/db.php';
    header('Location: ' . tenant_url('auth/login.php'));
    exit();
}

// Get current user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'customer';
$username = $_SESSION['username'] ?? '';
?>