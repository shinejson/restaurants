<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit();
}

$order_id = (int) $_GET['order_id'];
$user_id = $_SESSION['user_id'];

try {
    // Verify order belongs to user
    $stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$order_id, $user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }

    // Get items
    $stmt = $conn->prepare("SELECT oi.food_item_id as id, fi.item_name as name, fi.price, fi.promo_price, fi.image_url as image, oi.quantity 
                           FROM order_items oi 
                           JOIN food_items fi ON oi.food_item_id = fi.id 
                           WHERE oi.order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Adjust prices to current promo prices if active
    foreach ($items as &$item) {
        if (!empty($item['promo_price'])) {
            $item['price'] = $item['promo_price'];
        }
    }

    echo json_encode(['success' => true, 'items' => $items]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>