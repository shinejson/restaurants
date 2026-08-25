<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';
try {
    // Create logs table if missing
    $conn->exec("CREATE TABLE IF NOT EXISTS table_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(20) NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        message VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Ensure the dine-in order columns also exist
    ensure_order_schema($conn);


    // Support fetch_board via POST for polling
    if ($action === 'fetch_board') {
        // return tables and unassigned dine-in orders
        $tables = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $unassigned = $conn->query("SELECT id, order_reference, total, created_at FROM orders WHERE order_type='dine_in' AND (table_number IS NULL OR table_number = '') AND status IN ('Placed','Preparing') ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'tables' => $tables, 'unassigned' => $unassigned]);
        exit;
    }
    if ($action === 'update_table') {
        $table_name = $_POST['table_name'] ?? '';
        $seat_count = max(1, (int) ($_POST['seat_count'] ?? 2));
        $status = in_array($_POST['status'] ?? '', ['available', 'occupied', 'reserved'], true) ? $_POST['status'] : 'available';
        $notes = $_POST['notes'] ?? null;

        $stmt = $conn->prepare("UPDATE restaurant_tables SET seat_count = ?, status = ?, notes = ? WHERE table_name = ?");
        $stmt->execute([$seat_count, $status, $notes, $table_name]);

        echo json_encode(['success' => true, 'message' => 'Table updated']);
        exit;
    }

    if ($action === 'assign_order') {
        $table_name = $_POST['table_name'] ?? '';
        $order_id = (int) ($_POST['order_id'] ?? 0);
        if ($order_id <= 0 || $table_name === '') {
            throw new Exception('Missing parameters');
        }

        // assign order to table and mark table occupied
        $u1 = $conn->prepare("UPDATE orders SET table_number = ? WHERE id = ?");
        $u1->execute([$table_name, $order_id]);

        $u2 = $conn->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_name = ?");
        $u2->execute([$table_name]);

        echo json_encode(['success' => true, 'message' => 'Order assigned to table']);
        exit;
    }

    if ($action === 'mark_clean') {
        $table_name = $_POST['table_name'] ?? '';
        if ($table_name === '') throw new Exception('Missing table_name');

        // clear any table assignment from completed orders if needed (optional)
        // mark table available
        $stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'available', notes = NULL WHERE table_name = ?");
        $stmt->execute([$table_name]);

        // Log cleaning event
        $log = $conn->prepare("INSERT INTO table_logs (table_name, event_type, message) VALUES (?, 'cleaned', ?)");
        $log->execute([$table_name, 'Marked as cleaned']);

        echo json_encode(['success' => true, 'message' => 'Table marked as cleaned and available']);
        exit;
    }

    if ($action === 'unassign_order') {
        $table_name = $_POST['table_name'] ?? '';
        if ($table_name === '') throw new Exception('Missing table_name');

        // Find order assigned to this table and clear it
        $stmt = $conn->prepare("SELECT id FROM orders WHERE table_number = ? AND status IN ('Placed','Preparing','On the Way') LIMIT 1");
        $stmt->execute([$table_name]);
        $order = $stmt->fetch();
        if ($order) {
            $u = $conn->prepare("UPDATE orders SET table_number = '' WHERE id = ?");
            $u->execute([$order['id']]);
        }

        // Mark table available
        $u2 = $conn->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_name = ?");
        $u2->execute([$table_name]);

        // Log release
        $log = $conn->prepare("INSERT INTO table_logs (table_name, event_type, message) VALUES (?, 'released', ?)");
        $log->execute([$table_name, 'Table released / order unassigned']);

        echo json_encode(['success' => true, 'message' => 'Table released and unassigned']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
