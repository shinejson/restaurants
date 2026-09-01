<?php
// admin/setup_printing_tables.php
// One-time setup: creates printers & terminals tables and seeds default printing settings.
require_once __DIR__ . '/../config/db.php';

$output = [];

try {
    /* ------------------------------------------------------------------
     * 1. Printers table (Receipt / KOT / BOT printers)
     * ------------------------------------------------------------------ */
    $conn->exec("CREATE TABLE IF NOT EXISTS printers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        type ENUM('receipt','kot','bot') NOT NULL DEFAULT 'receipt',
        connection_type ENUM('network','usb','windows','cloud') NOT NULL DEFAULT 'network',
        ip_address VARCHAR(45) DEFAULT NULL,
        port INT DEFAULT 9100,
        paper_width ENUM('58mm','80mm') NOT NULL DEFAULT '80mm',
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $output[] = "Table 'printers' created successfully.";

    /* ------------------------------------------------------------------
     * 2. Terminals / Workstations table
     * ------------------------------------------------------------------ */
    $conn->exec("CREATE TABLE IF NOT EXISTS terminals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        location VARCHAR(150) DEFAULT NULL,
        terminal_code VARCHAR(50) DEFAULT NULL,
        receipt_printer_id INT DEFAULT NULL,
        kot_printer_id INT DEFAULT NULL,
        bot_printer_id INT DEFAULT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        last_active DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (receipt_printer_id) REFERENCES printers(id) ON DELETE SET NULL,
        FOREIGN KEY (kot_printer_id) REFERENCES printers(id) ON DELETE SET NULL,
        FOREIGN KEY (bot_printer_id) REFERENCES printers(id) ON DELETE SET NULL
    )");
    $output[] = "Table 'terminals' created successfully.";

    /* ------------------------------------------------------------------
     * 3. Seed default printing settings (category = 'printing')
     * ------------------------------------------------------------------ */
    $default_settings = [
        // Receipt printer
        ['printing', 'receipt_enabled', '1'],
        ['printing', 'receipt_copies', '1'],
        ['printing', 'receipt_paper_width', '80mm'],
        ['printing', 'receipt_header_note', 'Thank you for dining with us!'],
        ['printing', 'receipt_footer_note', 'Visit us again soon'],
        ['printing', 'receipt_show_logo', '1'],
        ['printing', 'receipt_show_tax_summary', '1'],
        ['printing', 'receipt_auto_print', '1'],
        // Kitchen Order Ticket (KOT)
        ['printing', 'kot_enabled', '1'],
        ['printing', 'kot_auto_print', '1'],
        ['printing', 'kot_paper_width', '80mm'],
        ['printing', 'kot_show_special_requests', '1'],
        ['printing', 'kot_header_note', '*** KITCHEN ORDER ***'],
        // Bar Order Ticket (BOT)
        ['printing', 'bot_enabled', '1'],
        ['printing', 'bot_auto_print', '1'],
        ['printing', 'bot_paper_width', '80mm'],
        ['printing', 'bot_categories', 'Drinks,Beverages,Juices'],
        ['printing', 'bot_header_note', '*** BAR ORDER ***'],
    ];

    // Per-template configuration (KOT, BOT, Order Receipt, Payment Receipt)
    $tpl_field_defaults = [
        'show_logo'        => '1',
        'show_restaurant'  => '1',
        'show_address'     => '1',
        'show_phone'       => '1',
        'show_ref'         => '1',
        'show_datetime'    => '1',
        'show_server'      => '1',
        'show_table'       => '1',
        'show_customer'    => '1',
        'show_prices'      => '1',
        'show_requests'    => '1',
        'show_tax'         => '1',
        'show_totals'      => '1',
        'paper_width'      => '80mm',
    ];
    $tpl_headers = [
        'kot'     => '*** KITCHEN ORDER ***',
        'bot'     => '*** BAR ORDER ***',
        'order'   => 'ORDER TICKET',
        'payment' => 'OFFICIAL RECEIPT',
    ];
    $tpl_footers = [
        'kot'     => '',
        'bot'     => '',
        'order'   => 'Please present this ticket to receive your order',
        'payment' => 'Thank you for dining with us!',
    ];
    foreach ($tpl_headers as $tpl_key => $tpl_header) {
        foreach ($tpl_field_defaults as $field => $val) {
            $default_settings[] = ['printing', $tpl_key . '_' . $field, $val];
        }
        $default_settings[] = ['printing', $tpl_key . '_header_note', $tpl_header];
        $default_settings[] = ['printing', $tpl_key . '_footer_note', $tpl_footers[$tpl_key]];
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO settings (category, setting_key, setting_value) VALUES (?, ?, ?)");
    foreach ($default_settings as $s) {
        $stmt->execute($s);
    }
    $output[] = "Default printing settings seeded successfully.";

    /* ------------------------------------------------------------------
     * 4. Per-item printer routing: add printer_id to food_items
     * ------------------------------------------------------------------ */
    $col_check = $conn->query("SHOW COLUMNS FROM food_items LIKE 'printer_id'")->fetch();
    if (!$col_check) {
        $conn->exec("ALTER TABLE food_items ADD COLUMN printer_id INT DEFAULT NULL");
        $output[] = "Column 'printer_id' added to 'food_items'.";
    } else {
        $output[] = "Column 'printer_id' already exists on 'food_items'.";
    }

    /* ------------------------------------------------------------------
     * 5. Seed a default terminal if none exists
     * ------------------------------------------------------------------ */
    $count = (int) $conn->query("SELECT COUNT(*) FROM terminals")->fetchColumn();
    if ($count === 0) {
        $conn->exec("INSERT INTO terminals (name, location, terminal_code, status) VALUES ('Main Cashier', 'Front Desk', 'TERM-01', 'active')");
        $output[] = "Default terminal 'Main Cashier' created.";
    }

    echo '<div style="font-family:sans-serif; padding:2rem;">';
    echo '<h2 style="color:#16a34a;">&#10003; Printing Setup Complete</h2>';
    foreach ($output as $line) {
        echo '<p>' . htmlspecialchars($line) . '</p>';
    }
    echo '<p><a href="printing/index.php" style="color:#ff6b35; font-weight:bold;">Go to Printing Configuration &rarr;</a></p>';
    echo '</div>';
} catch (PDOException $e) {
    echo '<div style="font-family:sans-serif; padding:2rem; color:#dc2626;">';
    echo '<h2>Setup Error</h2><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}