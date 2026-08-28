<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

if (!has_permission('manage_orders')) {
    header('Location: ' . BASE_URL . '/admin/permission_denied.php');
    exit();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensure_order_schema($conn);
ensure_table_logs_schema($conn);
ensure_restaurant_tables_schema($conn);

// Preselect table via QR / query string (e.g. guest_table_order.php?table_name=A1)
$prefill_table = trim($_GET['table_name'] ?? $_GET['table'] ?? '');

// Data: tables, categories, menu items (same source as online ordering / POS)
$restaurant_tables = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$main_categories = $conn->query("
    SELECT mc.id, mc.name, COUNT(DISTINCT fi.id) as item_count 
    FROM main_categories mc 
    LEFT JOIN sub_categories sc ON sc.main_category_id = mc.id 
    LEFT JOIN food_items fi ON fi.sub_category_id = sc.id AND (fi.inactive = 0 OR fi.inactive IS NULL) 
    GROUP BY mc.id, mc.name 
    ORDER BY mc.name ASC
")->fetchAll();

$sub_categories = $conn->query("
    SELECT sc.id, sc.main_category_id, sc.name, COUNT(DISTINCT fi.id) as item_count 
    FROM sub_categories sc 
    LEFT JOIN main_categories mc ON sc.main_category_id = mc.id 
    LEFT JOIN food_items fi ON fi.sub_category_id = sc.id AND (fi.inactive = 0 OR fi.inactive IS NULL) 
    GROUP BY sc.id, sc.main_category_id, sc.name 
    ORDER BY sc.name ASC
")->fetchAll();

$menu_items = $conn->query("
    SELECT fi.*, sc.name as sub_category_name, sc.main_category_id, mc.name as main_category_name 
    FROM food_items fi 
    LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id 
    LEFT JOIN main_categories mc ON sc.main_category_id = mc.id 
    WHERE fi.inactive = 0 OR fi.inactive IS NULL 
    ORDER BY fi.item_name ASC
")->fetchAll();

$alert = '';
$order_submitted = false;
$placed_order = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $table_name = trim($_POST['table_name'] ?? '');
        if ($table_name === '') {
            throw new Exception('Please select a table for this order.');
        }

        $tbl_stmt = $conn->prepare("SELECT * FROM restaurant_tables WHERE table_name = ?");
        $tbl_stmt->execute([$table_name]);
        $table_row = $tbl_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$table_row) {
            throw new Exception('Selected table does not exist.');
        }

        $guest_name = trim($_POST['guest_name'] ?? '');
        if ($guest_name === '') $guest_name = 'Table Guest';
        $guest_phone = trim($_POST['guest_phone'] ?? '');
        $seat_name = trim($_POST['seat_name'] ?? '');
        if ($seat_name === '') $seat_name = 'Seat 1';
        $guest_count = max(1, (int) ($_POST['guest_count'] ?? 1));
        $special_instructions = trim($_POST['special_instructions'] ?? '');

        // Collect selected items (prices always taken from the admin-managed menu)
        $selected_items = [];
        $order_total = 0;

        foreach ($menu_items as $item) {
            $qty = isset($_POST['qty'][$item['id']]) ? (int) $_POST['qty'][$item['id']] : 0;
            if ($qty > 0) {
                $unit_price = !empty($item['promo_price']) ? (float) $item['promo_price'] : (float) $item['price'];
                $item_total = $unit_price * $qty;
                $selected_items[] = [
                    'id' => (int) $item['id'],
                    'name' => $item['item_name'],
                    'qty' => $qty,
                    'price' => $unit_price,
                    'total' => $item_total,
                ];
                $order_total += $item_total;
            }
        }

        if (empty($selected_items)) {
            throw new Exception('Please select at least one menu item.');
        }

        // Get or create the guest customer record (dedupe by phone when provided)
        $customer_id = 0;
        if ($guest_phone !== '') {
            $chk = $conn->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
            $chk->execute([$guest_phone]);
            $existing = $chk->fetch();
            if ($existing) $customer_id = (int) $existing['id'];
        }

        if ($customer_id <= 0) {
            $safe_name = preg_replace('/[^A-Za-z0-9]+/', '_', $guest_name);
            $safe_name = trim($safe_name, '_');
            $username = ($safe_name !== '' ? strtolower($safe_name) : 'table_guest') . '_' . time();
            $email = $guest_phone !== '' ? $guest_phone . '@table.local' : $username . '@table.local';

            $stmt = $conn->prepare("INSERT INTO customers (username, email, password, full_name, phone, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $username,
                $email,
                password_hash('table-guest-pass', PASSWORD_DEFAULT),
                $guest_name,
                $guest_phone
            ]);
            $customer_id = (int) $conn->lastInsertId();
        }

        $order_reference = 'TBL-' . strtoupper(uniqid());
        $order_note_full = "Seat: $seat_name" . ($special_instructions ? " | Note: $special_instructions" : "");

        $order_fields = ['user_id', 'order_reference', 'total', 'status', 'created_at', 'order_type', 'table_number', 'guest_count'];
        $order_values = [$customer_id, $order_reference, $order_total, 'Placed', date('Y-m-d H:i:s'), 'dine_in', $table_name, $guest_count];

        $placeholders = implode(', ', array_fill(0, count($order_fields), '?'));
        $sql = 'INSERT INTO orders (' . implode(', ', $order_fields) . ') VALUES (' . $placeholders . ')';
        $stmt = $conn->prepare($sql);
        $stmt->execute($order_values);
        $order_id = (int) $conn->lastInsertId();

        foreach ($selected_items as $item) {
            $insert_stmt = $conn->prepare("INSERT INTO order_items (order_id, food_item_id, quantity, price, special_requests) VALUES (?, ?, ?, ?, ?)");
            $insert_stmt->execute([
                $order_id,
                $item['id'],
                $item['qty'],
                $item['price'],
                $order_note_full
            ]);
        }

        // Mark table occupied + log the event
        $conn->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_name = ?")->execute([$table_name]);
        $log = $conn->prepare("INSERT INTO table_logs (table_name, event_type, message) VALUES (?, 'ordered', ?)");
        $log->execute([$table_name, "Guest order $order_reference placed (Seat: $seat_name)"]);

        $order_submitted = true;
        $placed_order = [
            'id' => $order_id,
            'reference' => $order_reference,
            'table_name' => $table_name,
            'seat_name' => $seat_name,
            'guest_name' => $guest_name,
            'guest_count' => $guest_count,
            'total' => $order_total,
            'items' => $selected_items
        ];
    } catch (Exception $e) {
        $alert = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

$admin_title = 'Guest Table Ordering';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    :root {
        --gto-bg: #f3f4f6;
        --gto-border: #e5e7eb;
        --gto-border-dark: #d1d5db;
        --gto-text-main: #1f2937;
        --gto-text-muted: #6b7280;
        --gto-primary: #0f172a;
        --gto-accent: #f97316;
        --gto-card-bg: #ffffff;
    }

    body {
        background-color: var(--gto-bg);
    }

    .gto-header-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #ffffff;
        border: 1px solid var(--gto-border);
        border-radius: 8px;
        padding: 0.6rem 1rem;
        margin-bottom: 0.75rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .gto-title-group {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .gto-title-group h1 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--gto-text-main);
    }

    .gto-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: #16a34a;
        background: #f0fdf4;
        padding: 0.25rem 0.6rem;
        border-radius: 9999px;
        border: 1px solid #bbf7d0;
    }

    .gto-header-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .gto-header-link {
        color: #0f172a;
        font-weight: 700;
        font-size: 0.82rem;
        text-decoration: none;
        border: 1px solid var(--gto-border-dark);
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        background: #ffffff;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .gto-header-link:hover {
        background: #f1f5f9;
    }

    .gto-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 0.75rem;
        align-items: start;
    }

    @media (max-width: 1100px) {
        .gto-layout {
            grid-template-columns: 1fr;
        }
    }

    /* Left: menu panel */
    .gto-main-panel {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
    }

    .gto-search-row {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0.65rem;
    }

    .gto-input-group {
        position: relative;
        display: flex;
        align-items: center;
    }

    .gto-input-group i {
        position: absolute;
        left: 0.85rem;
        color: #94a3b8;
        font-size: 0.95rem;
        pointer-events: none;
    }

    .gto-input {
        width: 100%;
        padding: 0.55rem 0.85rem 0.55rem 2.4rem;
        border: 1px solid var(--gto-border-dark);
        border-radius: 6px;
        font-size: 0.9rem;
        background: #ffffff;
        color: var(--gto-text-main);
        outline: none;
        transition: border-color 0.15s ease;
    }

    .gto-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .gto-cat-section {
        background: #ffffff;
        border: 1px solid var(--gto-border);
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .gto-cat-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        overflow-x: auto;
        white-space: nowrap;
        scrollbar-width: none;
        padding-bottom: 2px;
    }

    .gto-cat-row::-webkit-scrollbar {
        display: none;
    }

    .gto-pill {
        background: #ffffff;
        border: 1px solid var(--gto-border-dark);
        border-radius: 6px;
        padding: 0.35rem 0.85rem;
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .gto-pill:hover {
        border-color: #0f172a;
        color: #0f172a;
    }

    .gto-pill.active {
        background: #0f172a;
        color: #ffffff;
        border-color: #0f172a;
    }

    .gto-grid-container {
        background: #ffffff;
        border: 1px solid var(--gto-border);
        border-radius: 8px;
        padding: 0.75rem;
        min-height: 480px;
        max-height: 640px;
        overflow-y: auto;
    }

    .gto-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 0.65rem;
    }

    .gto-card {
        background: #f8fafc;
        border: 1px solid var(--gto-border);
        border-radius: 8px;
        padding: 0.6rem;
        display: flex;
        flex-direction: column;
        transition: all 0.15s ease;
    }

    .gto-card.selected {
        border: 2px solid var(--gto-accent);
        background: #fff7ed;
    }

    .gto-card-img-wrap {
        height: 80px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        border-radius: 6px;
        overflow: hidden;
        border: 1px solid #f1f5f9;
        margin-bottom: 0.45rem;
    }

    .gto-card-img-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .gto-card-icon {
        font-size: 1.8rem;
        color: #94a3b8;
    }

    .gto-card-title {
        font-size: 0.8rem;
        font-weight: 700;
        color: #1e293b;
        text-align: center;
        line-height: 1.25;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        margin-bottom: 0.2rem;
        min-height: 2rem;
    }

    .gto-card-price {
        font-size: 0.8rem;
        font-weight: 800;
        color: var(--gto-accent);
        text-align: center;
        margin-bottom: 0.45rem;
    }

    .gto-qty-ctrl {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        margin-top: auto;
    }

    .gto-qty-btn {
        width: 30px;
        height: 30px;
        border-radius: 6px;
        border: 1px solid var(--gto-border-dark);
        background: #ffffff;
        font-weight: 800;
        font-size: 1rem;
        cursor: pointer;
        color: #0f172a;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .gto-qty-btn:hover {
        background: #e2e8f0;
    }

    .gto-qty-val {
        font-weight: 800;
        min-width: 26px;
        text-align: center;
        font-size: 0.9rem;
    }

    /* Right: order panel */
    .gto-order-panel {
        background: #ffffff;
        border: 1px solid var(--gto-border);
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        position: sticky;
        top: 15px;
        max-height: calc(100vh - 40px);
    }

    .gto-order-header {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--gto-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .gto-order-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--gto-text-main);
    }

    .gto-clear-btn {
        background: none;
        border: 1px solid var(--gto-border-dark);
        border-radius: 6px;
        padding: 0.25rem 0.6rem;
        font-size: 0.75rem;
        font-weight: 700;
        color: #dc2626;
        cursor: pointer;
    }

    .gto-clear-btn:hover {
        background: #fef2f2;
    }

    .gto-order-details {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--gto-border);
        background: #fafafa;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }

    .gto-field {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }

    .gto-field.full {
        grid-column: 1 / -1;
    }

    .gto-field label {
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .gto-field select,
    .gto-field input {
        width: 100%;
        padding: 0.45rem 0.6rem;
        border: 1px solid var(--gto-border-dark);
        border-radius: 6px;
        font-size: 0.85rem;
        background: #ffffff;
        outline: none;
    }

    .gto-field select:focus,
    .gto-field input:focus {
        border-color: #3b82f6;
    }

    .gto-qr-hint {
        grid-column: 1 / -1;
        font-size: 0.72rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    .gto-qr-hint a {
        color: #2563eb;
        font-weight: 700;
        text-decoration: none;
    }

    .gto-cart-items {
        flex: 1;
        overflow-y: auto;
        padding: 0.75rem 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        min-height: 120px;
    }

    .gto-empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex: 1;
        color: #94a3b8;
        text-align: center;
        padding: 1.5rem;
    }

    .gto-empty-state i {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
        opacity: 0.5;
    }

    .gto-empty-state p {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 700;
        color: #64748b;
    }

    .gto-cart-item {
        background: #ffffff;
        border: 1px solid var(--gto-border);
        border-radius: 6px;
        padding: 0.5rem 0.6rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .gto-cart-item-name {
        font-size: 0.82rem;
        font-weight: 700;
        color: #1e293b;
        flex: 1;
    }

    .gto-cart-item-meta {
        font-size: 0.72rem;
        color: #64748b;
        font-weight: 600;
    }

    .gto-cart-item-price {
        font-size: 0.82rem;
        font-weight: 800;
        color: #0f172a;
        min-width: 65px;
        text-align: right;
    }

    .gto-cart-item-remove {
        background: none;
        border: none;
        color: #dc2626;
        cursor: pointer;
        font-size: 0.8rem;
        padding: 0.15rem;
    }

    .gto-summary {
        border-top: 1px solid var(--gto-border);
        padding: 0.75rem 1rem;
        background: #ffffff;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .gto-summary-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        color: #475569;
    }

    .gto-summary-row.total {
        font-size: 1.05rem;
        font-weight: 800;
        color: #0f172a;
        margin-top: 0.3rem;
        padding-top: 0.4rem;
        border-top: 1px solid var(--gto-border);
    }

    .gto-submit-btn {
        width: 100%;
        background: var(--gto-accent);
        color: #ffffff;
        border: none;
        border-radius: 6px;
        padding: 0.85rem 1rem;
        font-size: 0.95rem;
        font-weight: 800;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        transition: background 0.15s ease;
        margin-top: 0.5rem;
    }

    .gto-submit-btn:hover {
        background: #ea580c;
    }

    /* Success card */
    .gto-success-card {
        background: #ffffff;
        border: 1px solid var(--gto-border);
        border-radius: 8px;
        padding: 2rem 1.5rem;
        text-align: center;
        max-width: 640px;
        margin: 0 auto;
    }

    .gto-success-card .receipt {
        background: #f8fafc;
        border: 1px solid var(--gto-border);
        border-radius: 10px;
        padding: 1rem;
        text-align: left;
        margin: 1.25rem 0;
    }

    .gto-success-card .receipt p {
        margin: 0 0 0.4rem;
        font-size: 0.88rem;
        color: #334155;
    }

    .gto-success-actions {
        display: flex;
        gap: 0.6rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    .gto-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.65rem 1.25rem;
        border-radius: 6px;
        font-weight: 800;
        font-size: 0.88rem;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid var(--gto-border-dark);
        background: #ffffff;
        color: #0f172a;
    }

    .gto-btn-primary {
        background: var(--gto-accent);
        color: #ffffff;
        border-color: var(--gto-accent);
    }

    .gto-btn-primary:hover {
        background: #ea580c;
    }

    .gto-btn:hover {
        background: #f1f5f9;
    }
</style>

<!-- Top Navigation Header -->
<div class="gto-header-bar">
    <div class="gto-title-group">
        <i class="fas fa-concierge-bell" style="color: #f97316; font-size: 1.2rem;"></i>
        <h1>Guest Table Ordering</h1>
        <span class="gto-status-badge"><i class="fas fa-check-circle"></i> Live Menu</span>
    </div>
    <div class="gto-header-actions">
        <a href="table_map.php" class="gto-header-link"><i class="fas fa-map-marked-alt"></i> Table Map</a>
        <a href="index.php" class="gto-header-link"><i class="fas fa-arrow-left"></i> Orders</a>
    </div>
</div>

<?php echo $alert; ?>

<?php if ($order_submitted && $placed_order): ?>
    <!-- Success Screen -->
    <div class="gto-success-card">
        <i class="fas fa-check-circle" style="font-size: 4rem; color: #16a34a; margin-bottom: 1rem;"></i>
        <h2 style="margin: 0 0 0.4rem; color: #0f172a;">Order Sent to Kitchen!</h2>
        <p style="color: #64748b; margin: 0;">The guest order for Table <?php echo htmlspecialchars($placed_order['table_name']); ?> has been placed successfully.</p>

        <div class="receipt">
            <p><strong>Order Reference:</strong> <?php echo htmlspecialchars($placed_order['reference']); ?></p>
            <p><strong>Table:</strong> <?php echo htmlspecialchars($placed_order['table_name']); ?> &nbsp;|&nbsp; <strong>Seat:</strong> <?php echo htmlspecialchars($placed_order['seat_name']); ?></p>
            <p><strong>Guest:</strong> <?php echo htmlspecialchars($placed_order['guest_name']); ?> (<?php echo (int) $placed_order['guest_count']; ?> guest(s))</p>
            <p style="margin-bottom: 0.6rem;"><strong>Items:</strong></p>
            <?php foreach ($placed_order['items'] as $it): ?>
                <p style="margin: 0 0 0.2rem 1rem; font-size: 0.82rem;">
                    <?php echo (int) $it['qty']; ?> × <?php echo htmlspecialchars($it['name']); ?> — GH₵<?php echo number_format($it['total'], 2); ?>
                </p>
            <?php endforeach; ?>
            <p style="margin: 0.6rem 0 0; border-top: 1px dashed #cbd5e1; padding-top: 0.5rem;"><strong>Total: GH₵<?php echo number_format($placed_order['total'], 2); ?></strong></p>
        </div>

        <div class="gto-success-actions">
            <a href="guest_table_order.php?table_name=<?php echo urlencode($placed_order['table_name']); ?>" class="gto-btn gto-btn-primary">
                <i class="fas fa-plus"></i> Place Another Order
            </a>
            <a href="view.php?id=<?php echo (int) $placed_order['id']; ?>" class="gto-btn">
                <i class="fas fa-eye"></i> View Order
            </a>
            <a href="index.php" class="gto-btn">
                <i class="fas fa-list"></i> All Orders
            </a>
        </div>
    </div>
<?php else: ?>

    <form method="POST" id="guestOrderForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div class="gto-layout">
            <!-- Left: Menu Items (from admin-managed menu) -->
            <div class="gto-main-panel">
                <div class="gto-search-row">
                    <div class="gto-input-group">
                        <i class="fas fa-search"></i>
                        <input type="text" id="gtoSearchInput" class="gto-input" placeholder="Search menu items...">
                    </div>
                    <button type="button" class="gto-header-link" id="openGuestQrBtn" title="Open the guest-facing QR ordering page for the selected table">
                        <i class="fas fa-qrcode"></i> Guest QR Page
                    </button>
                </div>

                <!-- Categories -->
                <div class="gto-cat-section">
                    <div class="gto-cat-row" id="mainCatRow">
                        <div class="gto-pill active" data-main-id="all">
                            All Items (<?php echo count($menu_items); ?>)
                        </div>
                        <?php foreach ($main_categories as $mcat): ?>
                            <div class="gto-pill" data-main-id="<?php echo (int) $mcat['id']; ?>">
                                <?php echo htmlspecialchars($mcat['name']); ?> (<?php echo (int) $mcat['item_count']; ?>)
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="gto-cat-row" id="subCatRow">
                        <div class="gto-pill active" data-sub-id="all" data-main-parent="all">
                            All Subcategories
                        </div>
                        <?php foreach ($sub_categories as $scat): ?>
                            <div class="gto-pill" data-sub-id="<?php echo (int) $scat['id']; ?>" data-main-parent="<?php echo (int) $scat['main_category_id']; ?>">
                                <?php echo htmlspecialchars($scat['name']); ?> (<?php echo (int) $scat['item_count']; ?>)
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Items Grid -->
                <div class="gto-grid-container">
                    <div class="gto-grid" id="gtoProductGrid">
                        <?php foreach ($menu_items as $item): ?>
                            <?php $unit_price = !empty($item['promo_price']) ? (float) $item['promo_price'] : (float) $item['price']; ?>
                            <div class="gto-card"
                                 data-item-id="<?php echo (int) $item['id']; ?>"
                                 data-main-id="<?php echo (int) ($item['main_category_id'] ?? 0); ?>"
                                 data-sub-id="<?php echo (int) ($item['sub_category_id'] ?? 0); ?>"
                                 data-name="<?php echo htmlspecialchars(strtolower($item['item_name'])); ?>">

                                <div class="gto-card-img-wrap">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars(BASE_URL . '/' . ltrim($item['image_url'], '/')); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                        <i class="fas fa-utensils gto-card-icon" style="display:none;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-utensils gto-card-icon"></i>
                                    <?php endif; ?>
                                </div>

                                <div class="gto-card-title">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                    <?php if (!empty($item['is_vegetarian'])): ?> <span title="Vegetarian">🌱</span><?php endif; ?>
                                    <?php if (!empty($item['is_spicy'])): ?> <span title="Spicy">🌶️</span><?php endif; ?>
                                </div>

                                <div class="gto-card-price">
                                    <?php if (!empty($item['promo_price'])): ?>
                                        <span style="text-decoration: line-through; color: #94a3b8; font-size: 0.7rem; font-weight: normal; margin-right: 0.2rem;"><?php echo format_currency((float) $item['price']); ?></span>
                                    <?php endif; ?>
                                    <?php echo format_currency($unit_price); ?>
                                </div>

                                <div class="gto-qty-ctrl">
                                    <button type="button" class="gto-qty-btn" onclick="adjustQty(<?php echo (int) $item['id']; ?>, -1)">−</button>
                                    <span class="gto-qty-val" id="qtyVal_<?php echo (int) $item['id']; ?>">0</span>
                                    <button type="button" class="gto-qty-btn" onclick="adjustQty(<?php echo (int) $item['id']; ?>, 1)">+</button>
                                </div>

                                <!-- Hidden inputs submitted with the form -->
                                <input type="hidden" class="gto-qty-input" name="qty[<?php echo (int) $item['id']; ?>]" id="qtyInput_<?php echo (int) $item['id']; ?>" value="0" data-price="<?php echo $unit_price; ?>" data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Order Details Panel -->
            <div class="gto-order-panel">
                <div class="gto-order-header">
                    <div class="gto-order-title"><i class="fas fa-clipboard-list"></i> Order Details</div>
                    <button type="button" class="gto-clear-btn" onclick="clearCart()"><i class="fas fa-times"></i> Clear</button>
                </div>

                <div class="gto-order-details">
                    <div class="gto-field">
                        <label>Table *</label>
                        <select name="table_name" id="tableSelect" required>
                            <option value="">-- Select Table --</option>
                            <?php foreach ($restaurant_tables as $tbl): ?>
                                <option value="<?php echo htmlspecialchars($tbl['table_name']); ?>" data-table-id="<?php echo (int) $tbl['id']; ?>"
                                    <?php echo ($prefill_table !== '' && $tbl['table_name'] === $prefill_table) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tbl['table_name']); ?> (<?php echo htmlspecialchars(ucfirst($tbl['status'] ?? 'available')); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="gto-field">
                        <label>Seat</label>
                        <select name="seat_name" id="seatSelect">
                            <option value="Seat 1">Seat 1</option>
                        </select>
                    </div>

                    <div class="gto-field">
                        <label>Guest Name</label>
                        <input type="text" name="guest_name" placeholder="e.g. John Doe" value="Guest">
                    </div>

                    <div class="gto-field">
                        <label>Guests</label>
                        <input type="number" name="guest_count" min="1" max="50" value="1">
                    </div>

                    <div class="gto-field full">
                        <label>Phone (Optional)</label>
                        <input type="text" name="guest_phone" placeholder="0240000000">
                    </div>

                    <div class="gto-field full">
                        <label>Kitchen Notes</label>
                        <input type="text" name="special_instructions" placeholder="e.g. Extra spicy, no onions">
                    </div>

                    <div class="gto-qr-hint">
                        <i class="fas fa-info-circle"></i>
                        Guests can also order themselves via the QR page:
                        <a href="<?php echo htmlspecialchars(BASE_URL); ?>/customer_order.php" target="_blank" id="guestQrLink">Open guest page</a>
                    </div>
                </div>

                <div class="gto-cart-items" id="cartItemsList">
                    <div class="gto-empty-state" id="cartEmptyState">
                        <i class="fas fa-shopping-basket"></i>
                        <p>No items selected yet</p>
                        <small>Use the + buttons on the menu items</small>
                    </div>
                </div>

                <div class="gto-summary">
                    <div class="gto-summary-row">
                        <span>Items</span>
                        <span id="cartItemCount">0</span>
                    </div>
                    <div class="gto-summary-row total">
                        <span>Total</span>
                        <span id="cartTotalText">GH₵0.00</span>
                    </div>
                    <button type="submit" class="gto-submit-btn">
                        <i class="fas fa-paper-plane"></i> Send Order to Kitchen
                    </button>
                </div>
            </div>
        </div>
    </form>

    <script>
        const CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
        const TABLE_API_URL = '<?php echo BASE_URL; ?>/admin/orders/table_api.php';
        const GUEST_PAGE_URL = '<?php echo BASE_URL; ?>/customer_order.php?table_name=';

        function escHtml(str) {
            // Built with concatenation so HTML entities survive any processing
            const AMP = '&' + 'amp;';
            return String(str == null ? '' : str)
                .replace(/&/g, AMP)
                .replace(/</g, '&' + 'lt;')
                .replace(/>/g, '&' + 'gt;')
                .replace(/"/g, '&' + 'quot;')
                .replace(/'/g, '&' + '#39;');
        }

        /* ---------- Category & Search Filtering ---------- */
        let activeMain = 'all';
        let activeSub = 'all';
        let searchTerm = '';

        function applyItemFilters() {
            document.querySelectorAll('#gtoProductGrid .gto-card').forEach(card => {
                const matchMain = activeMain === 'all' || card.dataset.mainId === activeMain;
                const matchSub = activeSub === 'all' || card.dataset.subId === activeSub;
                const matchSearch = searchTerm === '' || (card.dataset.name || '').includes(searchTerm);
                card.style.display = (matchMain && matchSub && matchSearch) ? 'flex' : 'none';
            });
        }

        document.querySelectorAll('#mainCatRow .gto-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('#mainCatRow .gto-pill').forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                activeMain = pill.dataset.mainId;
                activeSub = 'all';

                // Reset & toggle sub category pills visibility
                document.querySelectorAll('#subCatRow .gto-pill').forEach(sp => {
                    sp.classList.remove('active');
                    sp.style.display = (sp.dataset.mainParent === 'all' || sp.dataset.mainParent === activeMain) ? 'inline-block' : 'none';
                });
                const allSubPill = document.querySelector('#subCatRow .gto-pill[data-sub-id="all"]');
                if (allSubPill) allSubPill.classList.add('active');

                applyItemFilters();
            });
        });

        document.querySelectorAll('#subCatRow .gto-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('#subCatRow .gto-pill').forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                activeSub = pill.dataset.subId;
                applyItemFilters();
            });
        });

        document.getElementById('gtoSearchInput').addEventListener('input', function () {
            searchTerm = this.value.trim().toLowerCase();
            applyItemFilters();
        });

        /* ---------- Quantity / Cart ---------- */
        function adjustQty(itemId, delta) {
            const input = document.getElementById('qtyInput_' + itemId);
            const valSpan = document.getElementById('qtyVal_' + itemId);
            if (!input || !valSpan) return;

            let nextQty = Math.max(0, parseInt(input.value || 0, 10) + delta);
            input.value = nextQty;
            valSpan.textContent = nextQty;

            const card = input.closest('.gto-card');
            if (card) card.classList.toggle('selected', nextQty > 0);

            refreshCart();
        }

        function refreshCart() {
            const list = document.getElementById('cartItemsList');
            let total = 0, count = 0, html = '';

            document.querySelectorAll('.gto-qty-input').forEach(input => {
                const qty = parseInt(input.value || 0, 10);
                if (qty <= 0) return;
                const name = input.dataset.itemName;
                const price = parseFloat(input.dataset.price || 0);
                const line = qty * price;
                total += line;
                count += qty;

                html += `
                    <div class="gto-cart-item">
                        <div>
                            <div class="gto-cart-item-name">${escHtml(name)}</div>
                            <div class="gto-cart-item-meta">${qty} × GH₵${price.toFixed(2)}</div>
                        </div>
                        <div style="display:flex; align-items:center; gap:0.4rem;">
                            <span class="gto-cart-item-price">GH₵${line.toFixed(2)}</span>
                            <button type="button" class="gto-cart-item-remove" title="Remove" onclick="setItemQty(${input.id.replace('qtyInput_', '')}, 0)">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>`;
            });

            if (count === 0) {
                list.innerHTML = `
                    <div class="gto-empty-state" id="cartEmptyState">
                        <i class="fas fa-shopping-basket"></i>
                        <p>No items selected yet</p>
                        <small>Use the + buttons on the menu items</small>
                    </div>`;
            } else {
                list.innerHTML = html;
            }

            document.getElementById('cartItemCount').textContent = count;
            document.getElementById('cartTotalText').textContent = 'GH₵' + total.toFixed(2);
        }

        function setItemQty(itemId, qty) {
            const input = document.getElementById('qtyInput_' + itemId);
            const valSpan = document.getElementById('qtyVal_' + itemId);
            if (!input) return;
            input.value = Math.max(0, qty);
            if (valSpan) valSpan.textContent = input.value;
            const card = input.closest('.gto-card');
            if (card) card.classList.toggle('selected', parseInt(input.value, 10) > 0);
            refreshCart();
        }

        function clearCart() {
            document.querySelectorAll('.gto-qty-input').forEach(input => {
                input.value = 0;
                const valSpan = document.getElementById('qtyVal_' + input.id.replace('qtyInput_', ''));
                if (valSpan) valSpan.textContent = '0';
                const card = input.closest('.gto-card');
                if (card) card.classList.remove('selected');
            });
            refreshCart();
        }

        /* ---------- Table & Seats ---------- */
        function loadSeats() {
            const tableSelect = document.getElementById('tableSelect');
            const seatSelect = document.getElementById('seatSelect');
            const opt = tableSelect.selectedOptions[0];
            const tableId = opt ? (opt.dataset.tableId || 0) : 0;

            // Update guest QR link for the selected table
            const qrLink = document.getElementById('guestQrLink');
            if (qrLink && tableSelect.value) {
                qrLink.href = GUEST_PAGE_URL + encodeURIComponent(tableSelect.value);
            }

            seatSelect.innerHTML = '<option value="Seat 1">Seat 1</option>';
            if (!tableId) return;

            const fd = new FormData();
            fd.append('action', 'get_seats');
            fd.append('table_id', tableId);
            fd.append('csrf_token', CSRF_TOKEN);

            fetch(TABLE_API_URL, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.seats && data.seats.length) {
                        seatSelect.innerHTML = data.seats.map(s =>
                            `<option value="${escHtml(s.seat_name)}">${escHtml(s.seat_name)}</option>`
                        ).join('');
                    }
                })
                .catch(() => { /* keep default seats */ });
        }

        document.getElementById('tableSelect').addEventListener('change', loadSeats);

        document.getElementById('openGuestQrBtn').addEventListener('click', function () {
            const tableSelect = document.getElementById('tableSelect');
            if (!tableSelect.value) {
                alert('Please select a table first.');
                tableSelect.focus();
                return;
            }
            window.open(GUEST_PAGE_URL + encodeURIComponent(tableSelect.value), '_blank');
        });

        /* ---------- Form Submit Validation ---------- */
        document.getElementById('guestOrderForm').addEventListener('submit', function (e) {
            const tableSelect = document.getElementById('tableSelect');
            if (!tableSelect.value) {
                e.preventDefault();
                alert('Please select a table for this order.');
                tableSelect.focus();
                return;
            }
            const hasItems = Array.from(document.querySelectorAll('.gto-qty-input')).some(i => parseInt(i.value || 0, 10) > 0);
            if (!hasItems) {
                e.preventDefault();
                alert('Please select at least one menu item.');
                return;
            }
            const btn = this.querySelector('.gto-submit-btn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            }
        });

        /* ---------- Init ---------- */
        // Hide sub pills not belonging to "all" initially & load seats for preselected table
        document.querySelectorAll('#subCatRow .gto-pill').forEach(sp => {
            if (sp.dataset.mainParent !== 'all') sp.style.display = 'none';
        });
        loadSeats();
        refreshCart();
    </script>

<?php endif; ?>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>