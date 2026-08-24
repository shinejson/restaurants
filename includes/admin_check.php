<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Check if user is logged in as admin (any role: staff, manager, or admin)
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role'])) {
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit();
}
?>