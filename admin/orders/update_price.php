<?php
// admin/orders/update_price.php
session_start();
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] == $_SESSION['csrf_token']) {
    $order_id = (int) $_POST['order_id'];
    $new_total = floatval($_POST['new_total']);

    if ($order_id > 0 && $new_total >= 0) {
        try {
            $stmt = $conn->prepare("UPDATE orders SET total = ? WHERE id = ?");
            $stmt->execute([$new_total, $order_id]);
            $_SESSION['success_msg'] = "Order total updated successfully.";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "Failed to update order total: " . $e->getMessage();
        }
    }
}

header("Location: view.php?id=$order_id");
exit();
?>