<?php
// admin/orders/update_status.php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $order_id = (int) $_POST['order_id'];
    $status = clean_input($_POST['status']);
    $redirect_to = isset($_POST['redirect_to']) ? $_POST['redirect_to'] : 'index.php';

    $allowed_status = ['Draft', 'Placed', 'Preparing', 'On the Way', 'Delivered', 'Cancelled'];
    if (in_array($status, $allowed_status)) {
        try {
            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$status, $order_id]);

            $_SESSION['message'] = "Order status updated to " . ucfirst($status);
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }

    header('Location: ' . $redirect_to);
    exit();
}
