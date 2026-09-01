<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Get search and filter parameters (mirrors index.php logic)
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$category = isset($_GET['category']) ? clean_input($_GET['category']) : '';

try {
    $query = "SELECT fi.*, sc.name AS sub_category, mc.name AS main_category 
              FROM food_items fi 
              JOIN sub_categories sc ON fi.sub_category_id = sc.id 
              JOIN main_categories mc ON sc.main_category_id = mc.id 
              WHERE fi.inactive = 0";

    $params = [];

    if ($search) {
        $query .= " AND (fi.item_name LIKE ? OR fi.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($category) {
        $query .= " AND mc.name = ?";
        $params[] = $category;
    }

    $query .= " ORDER BY mc.name, sc.name, fi.item_name";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add pre-formatted prices so display stays consistent with the server-side render
    foreach ($items as &$item) {
        $item['price_formatted'] = format_currency($item['price']);
        $item['promo_formatted'] = !empty($item['promo_price']) ? format_currency($item['promo_price']) : null;
    }
    unset($item);

    echo json_encode(['success' => true, 'items' => $items]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>