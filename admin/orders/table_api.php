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
    ensure_order_schema($conn);
    ensure_table_logs_schema($conn);
    ensure_restaurant_tables_schema($conn);

    if ($action === 'fetch_board') {
        $tables = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $unassigned = $conn->query("SELECT id, order_reference, total, created_at FROM orders WHERE order_type='dine_in' AND (table_number IS NULL OR table_number = '') AND status IN ('Placed','Preparing') ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'tables' => $tables, 'unassigned' => $unassigned]);
        exit;
    }

    if ($action === 'get_seats') {
        $table_id = (int) ($_POST['table_id'] ?? 0);
        if ($table_id <= 0) throw new Exception('Invalid table ID');

        $stmt = $conn->prepare("SELECT * FROM restaurant_seats WHERE table_id = ? ORDER BY seat_number ASC");
        $stmt->execute([$table_id]);
        $seats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'seats' => $seats]);
        exit;
    }

    if ($action === 'save_seats') {
        $table_id = (int) ($_POST['table_id'] ?? 0);
        $seats_json = $_POST['seats'] ?? '[]';
        if ($table_id <= 0) throw new Exception('Invalid table ID');

        $seats = json_decode($seats_json, true);
        if (!is_array($seats)) throw new Exception('Invalid seats data');

        $conn->prepare("DELETE FROM restaurant_seats WHERE table_id = ?")->execute([$table_id]);
        $seat_insert = $conn->prepare("INSERT INTO restaurant_seats (table_id, seat_name, seat_number) VALUES (?, ?, ?)");

        foreach ($seats as $index => $seat_name) {
            $name = trim($seat_name) !== '' ? trim($seat_name) : 'Seat ' . ($index + 1);
            $seat_insert->execute([$table_id, $name, ($index + 1)]);
        }

        $new_count = count($seats);
        $conn->prepare("UPDATE restaurant_tables SET seat_count = ? WHERE id = ?")->execute([$new_count, $table_id]);

        echo json_encode(['success' => true, 'message' => 'Seats updated successfully']);
        exit;
    }

    if ($action === 'create_table') {
        $table_name = trim($_POST['table_name'] ?? '');
        $seat_count = max(1, (int) ($_POST['seat_count'] ?? 4));
        $status = in_array($_POST['status'] ?? '', ['available', 'occupied', 'reserved'], true) ? $_POST['status'] : 'available';
        $notes = trim($_POST['notes'] ?? '');

        if ($table_name === '') throw new Exception('Table name is required');

        $check = $conn->prepare("SELECT id FROM restaurant_tables WHERE table_name = ?");
        $check->execute([$table_name]);
        if ($check->fetch()) throw new Exception('Table name already exists');

        $qr_url = BASE_URL . '/customer_order.php?table_name=' . urlencode($table_name);

        $stmt = $conn->prepare("INSERT INTO restaurant_tables (table_name, seat_count, status, notes, qr_code) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$table_name, $seat_count, $status, $notes, $qr_url]);
        $table_id = $conn->lastInsertId();

        for ($i = 1; $i <= $seat_count; $i++) {
            $seat_stmt = $conn->prepare("INSERT INTO restaurant_seats (table_id, seat_name, seat_number) VALUES (?, ?, ?)");
            $seat_stmt->execute([$table_id, 'Seat ' . $i, $i]);
        }

        echo json_encode(['success' => true, 'message' => 'Table created successfully', 'table_id' => $table_id]);
        exit;
    }

    if ($action === 'delete_table') {
        $table_id = (int) ($_POST['table_id'] ?? 0);
        if ($table_id <= 0) throw new Exception('Invalid table ID');

        $conn->prepare("DELETE FROM restaurant_tables WHERE id = ?")->execute([$table_id]);
        echo json_encode(['success' => true, 'message' => 'Table deleted successfully']);
        exit;
    }

    if ($action === 'update_table') {
        $table_name = trim($_POST['table_name'] ?? '');
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
        if ($order_id <= 0 || $table_name === '') throw new Exception('Missing parameters');

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

        $stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'available', notes = NULL WHERE table_name = ?");
        $stmt->execute([$table_name]);

        $log = $conn->prepare("INSERT INTO table_logs (table_name, event_type, message) VALUES (?, 'cleaned', ?)");
        $log->execute([$table_name, 'Marked as cleaned']);

        echo json_encode(['success' => true, 'message' => 'Table marked as cleaned and available']);
        exit;
    }

    if ($action === 'unassign_order') {
        $table_name = $_POST['table_name'] ?? '';
        if ($table_name === '') throw new Exception('Missing table_name');

        $stmt = $conn->prepare("SELECT id FROM orders WHERE table_number = ? AND status IN ('Placed','Preparing','On the Way') LIMIT 1");
        $stmt->execute([$table_name]);
        $order = $stmt->fetch();
        if ($order) {
            $u = $conn->prepare("UPDATE orders SET table_number = '' WHERE id = ?");
            $u->execute([$order['id']]);
        }

        $u2 = $conn->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_name = ?");
        $u2->execute([$table_name]);

        $log = $conn->prepare("INSERT INTO table_logs (table_name, event_type, message) VALUES (?, 'released', ?)");
        $log->execute([$table_name, 'Table released / order unassigned']);

        echo json_encode(['success' => true, 'message' => 'Table released and unassigned']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
