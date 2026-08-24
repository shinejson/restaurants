<?php
require_once 'config/db.php';

try {
    // 1. Add promo_price to food_items
    $conn->exec("ALTER TABLE food_items ADD COLUMN IF NOT EXISTS promo_price DECIMAL(10,2) DEFAULT NULL AFTER price");
    echo "Added promo_price to food_items.\n";

    // 2. Add delivery_charge and delivery_zone_id to orders
    $conn->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_charge DECIMAL(10,2) DEFAULT 0.00 AFTER total");
    $conn->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_zone_id INT NULL AFTER delivery_charge");
    echo "Added delivery columns to orders.\n";

    // 3. Create delivery_zones table
    $conn->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        zone_name VARCHAR(100) NOT NULL,
        delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Created delivery_zones table.\n";

    // 4. Seed some sample zones if empty
    $count = $conn->query("SELECT COUNT(*) FROM delivery_zones")->fetchColumn();
    if ($count == 0) {
        $stmt = $conn->prepare("INSERT INTO delivery_zones (zone_name, delivery_fee) VALUES (?, ?)");
        $stmt->execute(['Airport West', 5.00]);
        $stmt->execute(['East Legon', 10.00]);
        $stmt->execute(['Airport Residential', 7.00]);
        $stmt->execute(['Cantonments', 12.00]);
        $stmt->execute(['Labone', 15.00]);
        echo "Seeded sample delivery zones.\n";
    }

} catch (PDOException $e) {
    die("Migration Error: " . $e->getMessage());
}
?>