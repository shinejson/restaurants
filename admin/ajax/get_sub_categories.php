<?php
// admin/ajax/get_sub_categories.php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

if (isset($_GET['main_category_id'])) {
    $main_id = (int) $_GET['main_category_id'];
    $stmt = $conn->prepare("SELECT id, name FROM sub_categories WHERE main_category_id = ? ORDER BY name");
    $stmt->execute([$main_id]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($subs);
} else {
    echo json_encode([]);
}
exit();
