<?php
require_once '../config/db.php';

try {
    // Add columns if they don't exist
    $conn->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS google_id VARCHAR(255)");
    $conn->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS apple_id VARCHAR(255)");
    echo "Added columns google_id and apple_id to customers table.\n";

    // Sync data
    $sql = "UPDATE customers c 
            JOIN users_backup u ON c.username = u.username 
            SET c.google_id = u.google_id, c.apple_id = u.apple_id";
    $conn->exec($sql);
    echo "Synced social IDs from users_backup.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>