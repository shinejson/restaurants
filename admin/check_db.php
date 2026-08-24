<?php
require_once '../config/db.php';

echo "Checking database state...\n";

try {
    // Check tables
    $tables = ['users', 'users_backup', 'customers', 'admins'];
    foreach ($tables as $table) {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "Table '$table' exists.\n";
            $count = $conn->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo "  - Rows: $count\n";
        } else {
            echo "Table '$table' DOES NOT exist.\n";
        }
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>