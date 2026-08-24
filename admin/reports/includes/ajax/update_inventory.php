<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['item_id']) || !isset($input['quantity'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$item_id = (int)$input['item_id'];
$quantity = (int)$input['quantity'];
$action = $input['action'] ?? 'set'; // 'set' or 'add'

try {
    if ($action === 'add') {
        // Add to existing quantity
        $stmt = $conn->prepare("UPDATE food_items SET inventory_count = inventory_count + ? WHERE id = ?");
    } else {
        // Set to specific quantity
        $stmt = $conn->prepare("UPDATE food_items SET inventory_count = ? WHERE id = ?");
    }
    
    $stmt->execute([$quantity, $item_id]);
    
    // Log the inventory change
    $log_stmt = $conn->prepare("INSERT INTO inventory_logs (item_id, user_id, action, quantity, previous_value, new_value) 
                               VALUES (?, ?, ?, ?, (SELECT inventory_count FROM food_items WHERE id = ?), ?)");
    $log_stmt->execute([
        $item_id,
        $_SESSION['user_id'],
        $action,
        $quantity,
        $item_id,
        $quantity
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Inventory updated successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>