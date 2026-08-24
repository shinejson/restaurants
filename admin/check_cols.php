<?php
require_once '../config/db.php';
$stmt = $conn->query("SHOW COLUMNS FROM users_backup");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>