<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

$prefill_order_type = isset($_GET['order_type']) ? strtolower(trim($_GET['order_type'])) : 'dine_in';
$prefill_table_number = trim($_GET['table_number'] ?? '');

if (!has_permission('manage_orders')) {
    header('Location: ' . BASE_URL . '/admin/permission_denied.php');
    exit();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensure_order_schema($conn);

// System Dynamic Queries
$customers = $conn->query("SELECT id, full_name, username, email, phone FROM customers ORDER BY full_name ASC")->fetchAll();
$restaurant_tables = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_name ASC")->fetchAll();

$main_categories = $conn->query("
    SELECT mc.id, mc.name, COUNT(DISTINCT fi.id) as item_count 
    FROM main_categories mc 
    LEFT JOIN sub_categories sc ON sc.main_category_id = mc.id 
    LEFT JOIN food_items fi ON fi.sub_category_id = sc.id AND (fi.inactive = 0 OR fi.inactive IS NULL) 
    GROUP BY mc.id, mc.name 
    ORDER BY mc.name ASC
")->fetchAll();

$sub_categories = $conn->query("
    SELECT sc.id, sc.main_category_id, sc.name, mc.name as main_category_name, COUNT(DISTINCT fi.id) as item_count 
    FROM sub_categories sc 
    LEFT JOIN main_categories mc ON sc.main_category_id = mc.id 
    LEFT JOIN food_items fi ON fi.sub_category_id = sc.id AND (fi.inactive = 0 OR fi.inactive IS NULL) 
    GROUP BY sc.id, sc.main_category_id, sc.name, mc.name 
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $customer_id = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
        $order_type = isset($_POST['order_type']) ? strtolower(trim($_POST['order_type'])) : 'dine_in';
        $table_number = trim($_POST['table_number'] ?? '');
        $room_number = trim($_POST['room_number'] ?? '');
        $guest_count = max(1, (int) ($_POST['guest_count'] ?? 1));
        $order_notes = trim($_POST['order_notes'] ?? '');

        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $customer_email = trim($_POST['customer_email'] ?? '');
        $customer_address = trim($_POST['customer_address'] ?? '');

        $selected_items = [];
        $order_total = 0;

        foreach ($menu_items as $item) {
            $qty = isset($_POST['qty'][$item['id']]) ? (int) $_POST['qty'][$item['id']] : 0;
            if ($qty > 0) {
                $unit_price = !empty($item['promo_price']) ? (float) $item['promo_price'] : (float) $item['price'];
                $request_price = isset($_POST['request_price'][$item['id']]) ? (float) $_POST['request_price'][$item['id']] : 0.00;
                $item_total = ($unit_price * $qty) + ($request_price * $qty);

                $selected_items[] = [
                    'id' => (int) $item['id'],
                    'name' => $item['item_name'],
                    'qty' => $qty,
                    'price' => $unit_price,
                    'special_requests' => trim($_POST['special_requests'][$item['id']] ?? ''),
                    'request_price' => $request_price,
                    'recipient_name' => trim($_POST['recipient_name'][$item['id']] ?? ''),
                    'total' => $item_total,
                ];
                $order_total += $item_total;
            }
        }

        if (empty($selected_items)) {
            throw new Exception('Please select at least one item for the order.');
        }

        if ($customer_id <= 0) {
            if ($customer_name === '') {
                $customer_name = 'Walk-in Guest';
            }
            $safe_name = preg_replace('/[^A-Za-z0-9]+/', '_', $customer_name);
            $safe_name = trim($safe_name, '_');
            $username = $safe_name !== '' ? strtolower($safe_name) . '_' . time() : 'walkin_' . time();
            $email = $customer_email !== '' ? $customer_email : $username . '@restaurant.local';

            $check_stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? OR username = ? OR phone = ?");
            $check_stmt->execute([$email, $username, $customer_phone !== '' ? $customer_phone : $email]);
            $existing_customer = $check_stmt->fetch();

            if ($existing_customer) {
                $customer_id = (int) $existing_customer['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO customers (username, email, password, full_name, phone, address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $username,
                    $email,
                    password_hash('restaurant-order', PASSWORD_DEFAULT),
                    $customer_name,
                    $customer_phone,
                    $customer_address
                ]);
                $customer_id = (int) $conn->lastInsertId();
            }
        }

        $order_reference = 'POS-' . strtoupper(uniqid());

        // Toolbar action: 'settle' (Settle / Quick Pay) or 'save_draft' (Save button)
        $form_action = (isset($_POST['form_action']) && $_POST['form_action'] === 'save_draft') ? 'save_draft' : 'settle';
        $order_status = $form_action === 'save_draft' ? 'Draft' : 'Placed';

        // Apply Extra Charge & Discount from the POS toolbar to the final total
        $extra_charge = max(0, (float) ($_POST['extra_charge'] ?? 0));
        $discount_percent = min(100, max(0, (float) ($_POST['discount_percent'] ?? 0)));
        $discount_amount = round($order_total * ($discount_percent / 100), 2);
        $order_total = max(0, round($order_total + $extra_charge - $discount_amount, 2));

        $order_fields = [
            'user_id', 'order_reference', 'total', 'status', 'delivery_charge', 'delivery_zone_id', 'company_id', 'created_at'
        ];
        $order_values = [
            $customer_id, $order_reference, $order_total, $order_status, 0, null, null, date('Y-m-d H:i:s')
        ];

        $order_columns = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        $extra_columns = [
            'order_type' => $order_type,
            'table_number' => $table_number,
            'room_number' => $room_number,
            'guest_count' => $guest_count,
            'extra_charge' => $extra_charge,
            'discount_percent' => $discount_percent,
        ];
        foreach ($extra_columns as $extra_column => $extra_value) {
            if (in_array($extra_column, $order_columns, true)) {
                $order_fields[] = $extra_column;
                $order_values[] = $extra_value;
            }
        }

        $placeholders = implode(', ', array_fill(0, count($order_fields), '?'));
        $sql = 'INSERT INTO orders (' . implode(', ', $order_fields) . ') VALUES (' . $placeholders . ')';

        $stmt = $conn->prepare($sql);
        $stmt->execute($order_values);
        $order_id = (int) $conn->lastInsertId();

        foreach ($selected_items as $item) {
            $insert_stmt = $conn->prepare("INSERT INTO order_items (order_id, food_item_id, quantity, price, special_requests, request_price, recipient_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->execute([
                $order_id,
                $item['id'],
                $item['qty'],
                $item['price'],
                $item['special_requests'],
                $item['request_price'],
                $item['recipient_name']
            ]);
        }

        header('Location: view.php?id=' . $order_id);
        exit();
    } catch (Exception $e) {
        $alert = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

$admin_title = 'Order Screen POS';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    /* Map local POS variables to global admin theme variables */
    :root {
        --pos-bg: var(--light-bg, #f3f4f6);
        --pos-border: var(--border-color, #e5e7eb);
        --pos-border-dark: #d1d5db;
        --pos-text-main: var(--text-main, #1f2937);
        --pos-text-muted: var(--text-muted, #6b7280);
        --pos-primary: var(--dark-color, #0f172a);
        --pos-accent: var(--primary-color, #2563eb);
        --pos-active-tab: var(--pos-primary);
        --pos-card-bg: var(--white, #ffffff);
        --pos-card-alt: var(--glass, #f8fafc);
        --pos-row-hover: rgba(0,0,0,0.02);
    }

    /* Dark theme-specific tweaks */
    html[data-theme="dark"] {
        --pos-bg: var(--light-bg);
        --pos-border: var(--border-color);
        --pos-border-dark: #2d3748;
        --pos-text-main: var(--text-main);
        --pos-text-muted: var(--text-muted);
        --pos-primary: var(--dark-color);
        --pos-card-bg: var(--white);
        --pos-card-alt: rgba(255,255,255,0.02);
        --pos-row-hover: rgba(255,255,255,0.02);
    }

    body {
        background-color: var(--pos-bg);
    }

    .pos-header-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--pos-card-bg);
        border: 1px solid var(--pos-border);
        border-radius: 8px;
        padding: 0.6rem 1rem;
        margin-bottom: 0.75rem;
    }

    .pos-title-group {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .pos-title-group h1 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--pos-text-main);
    }

    .pos-status-icons {
        display: flex;
        align-items: center;
        gap: 1rem;
        color: var(--pos-text-muted);
        font-size: 0.95rem;
    }

    .pos-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--success-color, #16a34a);
        background: rgba(16,185,129,0.08);
        padding: 0.25rem 0.6rem;
        border-radius: 9999px;
        border: 1px solid rgba(16,185,129,0.15);
    }

    .pos-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 0.75rem;
        align-items: start;
    }

    @media (max-width: 1100px) {
        .pos-layout {
            grid-template-columns: 1fr;
        }
    }

    /* Left Section */
    .pos-main-panel {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
    }

    .pos-search-row {
        display: grid;
        grid-template-columns: 1fr 220px;
        gap: 0.65rem;
    }

    @media (max-width: 640px) {
        .pos-search-row {
            grid-template-columns: 1fr;
        }
    }

    .pos-input-group {
        position: relative;
        display: flex;
        align-items: center;
    }

    .pos-input-group i {
        position: absolute;
        left: 0.85rem;
        color: var(--pos-text-muted);
        font-size: 0.95rem;
        pointer-events: none;
    }

    .pos-input {
        width: 100%;
        padding: 0.55rem 0.85rem 0.55rem 2.4rem;
        border: 1px solid var(--pos-border-dark);
        border-radius: 6px;
        font-size: 0.9rem;
        background: var(--pos-card-bg);
        color: var(--pos-text-main);
        outline: none;
        transition: border-color 0.15s ease;
    }

    .pos-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    /* Category Nav */
    .pos-cat-section {
        background: var(--pos-card-bg);
        border: 1px solid var(--pos-border);
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .pos-cat-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        overflow-x: auto;
        white-space: nowrap;
        scrollbar-width: none;
        padding-bottom: 2px;
    }

    .pos-cat-row::-webkit-scrollbar {
        display: none;
    }

    .pos-pill {
        background: var(--pos-card-bg);
        border: 1px solid var(--pos-border-dark);
        border-radius: 6px;
        padding: 0.35rem 0.85rem;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--pos-text-muted);
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .pos-pill:hover {
        border-color: var(--pos-primary);
        color: var(--pos-primary);
    }

    .pos-pill.active {
        background: var(--pos-primary);
        color: var(--pos-card-bg);
        border-color: var(--pos-primary);
    }

    .pos-subcat-row-container {
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .pos-scroll-btn {
        background: var(--pos-card-alt);
        border: 1px solid var(--pos-border-dark);
        border-radius: 6px;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: var(--pos-text-muted);
        font-size: 0.8rem;
        flex-shrink: 0;
    }

    .pos-scroll-btn:hover {
        background: #e2e8f0;
    }

    /* Product Grid */
    .pos-grid-container {
        background: var(--pos-card-bg);
        border: 1px solid var(--pos-border);
        border-radius: 8px;
        padding: 0.75rem;
        min-height: 480px;
        max-height: 620px;
        overflow-y: auto;
    }

    .pos-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 0.65rem;
    }

    .pos-card {
        background: var(--pos-card-alt);
        border: 1px solid var(--pos-border);
        border-radius: 8px;
        padding: 0.6rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        height: 140px;
        cursor: pointer;
        position: relative;
        transition: all 0.15s ease;
        user-select: none;
    }

    .pos-card:hover {
        border-color: var(--pos-accent);
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
        transform: translateY(-1px);
        background: var(--pos-card-bg);
    }

    .pos-card.selected {
        border: 2px solid var(--pos-accent);
        background: rgba(37,99,235,0.08);
    }

    .pos-card-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        background: var(--pos-accent);
        color: var(--pos-card-bg);
        font-size: 0.72rem;
        font-weight: 700;
        padding: 0.15rem 0.45rem;
        border-radius: 9999px;
    }

    .pos-card-img-wrap {
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--pos-card-bg);
        border-radius: 6px;
        overflow: hidden;
        border: 1px solid var(--pos-border);
        margin-bottom: 0.4rem;
    }

    .pos-card-img-wrap img {
        max-height: 100%;
        max-width: 100%;
        object-fit: contain;
    }

    .pos-card-icon {
        font-size: 1.6rem;
        color: var(--pos-text-muted);
    }

    .pos-card-title {
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--pos-text-main);
        text-align: center;
        line-height: 1.25;
        text-transform: uppercase;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        margin-bottom: 0.2rem;
    }

    .pos-card-price {
        font-size: 0.76rem;
        font-weight: 700;
        color: var(--pos-accent);
        text-align: center;
    }

    /* Bottom Actions Bar */
    .pos-action-bar {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 0.4rem;
        background: var(--pos-card-bg);
        border: 1px solid var(--pos-border);
        border-radius: 8px;
        padding: 0.5rem;
    }

    @media (max-width: 768px) {
        .pos-action-bar {
            grid-template-columns: repeat(4, 1fr);
        }
    }

    .pos-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.2rem;
        padding: 0.5rem 0.25rem;
        border: 1px solid var(--pos-border-dark);
        border-radius: 6px;
        background: var(--pos-card-bg);
        color: var(--pos-text-main);
        font-size: 0.72rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .pos-btn:hover {
        background: var(--pos-row-hover);
        border-color: var(--pos-primary);
    }

    .pos-btn i {
        font-size: 0.95rem;
    }

    .pos-btn-cancel {
        border-color: rgba(252,165,165,0.6);
        color: var(--danger-color, #dc2626);
        background: rgba(255,245,245,0.6);
    }

    .pos-btn-cancel:hover {
        background: rgba(254,226,226,0.8);
        border-color: var(--danger-color, #dc2626);
    }

    /* Right Section - Cart Panel */
    .pos-cart-panel {
        background: var(--pos-card-bg);
        border: 1px solid var(--pos-border);
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        height: calc(100vh - 120px);
        min-height: 650px;
        position: sticky;
        top: 15px;
    }

    .pos-cart-header {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--pos-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .pos-cart-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--pos-text-main);
    }

    .pos-cart-icons {
        display: flex;
        gap: 0.5rem;
    }

    .pos-icon-btn {
        background: none;
        border: 1px solid var(--pos-border-dark);
        border-radius: 6px;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #475569;
        cursor: pointer;
        font-size: 0.85rem;
    }

    .pos-icon-btn:hover {
        background: #f1f5f9;
    }

    .pos-cart-count-bar {
        padding: 0.45rem 1rem;
        background: var(--pos-card-alt);
        border-bottom: 1px solid var(--pos-border);
        font-size: 0.8rem;
        color: var(--pos-text-muted);
        font-weight: 600;
    }

    /* Customer details expander */
    .pos-customer-expander {
        border-bottom: 1px solid var(--pos-border);
        padding: 0.6rem 1rem;
        background: var(--pos-card-alt);
    }

    .pos-customer-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--pos-text-main);
        cursor: pointer;
    }

    .pos-customer-details {
        display: none;
        margin-top: 0.6rem;
        padding-top: 0.6rem;
        border-top: 1px dashed var(--pos-border);
    }

    .pos-customer-details.show {
        display: grid;
        gap: 0.5rem;
    }

    .pos-cart-items {
        flex: 1;
        overflow-y: auto;
        padding: 0.75rem 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }

    .pos-empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: var(--pos-text-muted);
        text-align: center;
        padding: 2rem;
    }

    .pos-empty-state i {
        font-size: 3rem;
        margin-bottom: 0.6rem;
        opacity: 0.5;
    }

    .pos-empty-state p {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: #64748b;
    }

    /* Cart Item Card */
    .pos-cart-item {
        background: var(--pos-card-bg);
        border: 1px solid var(--pos-border);
        border-radius: 6px;
        padding: 0.6rem;
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .pos-cart-item-main {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .pos-cart-item-name {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--pos-text-main);
        flex: 1;
    }

    .pos-cart-item-controls {
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    .pos-qty-btn {
        width: 26px;
        height: 26px;
        border: 1px solid var(--pos-border-dark);
        border-radius: 4px;
        background: var(--pos-card-bg);
        color: var(--pos-primary);
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }

    .pos-qty-btn:hover {
        background: var(--pos-row-hover);
    }

    .pos-qty-val {
        width: 32px;
        text-align: center;
        font-size: 0.82rem;
        font-weight: 700;
        border: none;
        background: transparent;
    }

    .pos-cart-item-price {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--pos-primary);
        min-width: 65px;
        text-align: right;
    }

    .pos-cart-item-opt-btn {
        background: none;
        border: none;
        color: var(--pos-text-muted);
        cursor: pointer;
        font-size: 0.8rem;
        padding: 0 0.2rem;
    }

    .pos-cart-item-opt-btn:hover {
        color: #2563eb;
    }

    .pos-cart-item-opts {
        display: none;
        padding-top: 0.4rem;
        border-top: 1px dashed #e2e8f0;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem;
    }

    .pos-cart-item-opts.show {
        display: grid;
    }

    .pos-cart-item-opts input {
        width: 100%;
        padding: 0.3rem 0.5rem;
        font-size: 0.75rem;
        border: 1px solid var(--pos-border-dark);
        border-radius: 4px;
    }

    /* Summary Totals */
    .pos-summary {
        border-top: 1px solid var(--pos-border);
        padding: 0.75rem 1rem;
        background: var(--pos-card-bg);
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .pos-summary-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        color: var(--pos-text-muted);
    }

    .pos-summary-row.total {
        font-size: 1rem;
        font-weight: 800;
        color: var(--pos-primary);
        margin-top: 0.3rem;
        padding-top: 0.3rem;
        border-top: 1px solid var(--pos-border);
    }

    .btn-quick-pay {
        width: 100%;
        background: var(--pos-primary);
        color: var(--pos-card-bg);
        border: none;
        border-radius: 6px;
        padding: 0.85rem 1rem;
        font-size: 0.95rem;
        font-weight: 800;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: background 0.15s ease;
        margin-top: 0.5rem;
    }

    .btn-quick-pay:hover {
        background: var(--pos-primary);
        opacity: 0.95;
    }

    /* Modal dialogs */
    .pos-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(15, 23, 42, 0.4);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .pos-modal-overlay.show {
        display: flex;
    }

    .pos-modal {
        background: var(--pos-card-bg);
        border-radius: 8px;
        width: 360px;
        max-width: 90%;
        padding: 1.25rem;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }

    .pos-modal h3 {
        margin: 0 0 1rem;
        font-size: 1rem;
        color: #0f172a;
    }

    .pos-modal-btns {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    /* ===== Receipt printing (Print button) ===== */
    #posReceipt { display: none; }

    @media print {
        /* Hide the entire POS screen, show only the receipt ticket */
        body * {
            visibility: hidden !important;
        }

        #posReceipt,
        #posReceipt * {
            visibility: visible !important;
        }

        #posReceipt {
            display: block !important;
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            max-width: 400px;
            margin: 0;
            padding: 10px;
            font-family: 'Courier New', Courier, monospace;
            color: #000;
            background: #fff;
        }

        /* Collapse the tall POS layout so no blank pages are emitted */
        .pos-header-bar,
        .pos-layout,
        .pos-modal-overlay {
            display: none !important;
        }

        html,
        body {
            height: auto !important;
            background: #fff !important;
        }
    }
</style>

<!-- Top Navigation Header -->
<div class="pos-header-bar">
    <div class="pos-title-group">
        <i class="fas fa-cash-register" style="color: #0f172a; font-size: 1.2rem;"></i>
        <h1>Order Screen</h1>
        <span class="pos-status-badge"><i class="fas fa-check-circle"></i> Online</span>
    </div>
    <div class="pos-status-icons">
        <i class="fas fa-cog" title="Settings"></i>
        <i class="fas fa-mobile-alt" title="Device"></i>
        <i class="fas fa-bullhorn" title="Notifications"></i>
        <span style="font-size: 0.82rem; font-weight: 600; color: #334155; display: inline-flex; align-items: center; gap: 0.3rem;">
            <i class="fas fa-store"></i> Airport West Hotel
        </span>
        <a href="index.php" style="color: #0f172a; font-weight: 700; font-size: 0.85rem; text-decoration: none; border: 1px solid var(--pos-border-dark); padding: 0.35rem 0.75rem; border-radius: 6px; background: #ffffff;">
            <i class="fas fa-arrow-left"></i> Orders
        </a>
    </div>
</div>

<?php echo $alert; ?>

<form method="POST" id="posOrderForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="extra_charge" id="extraChargeAmount" value="0">
    <input type="hidden" name="discount_percent" id="discountPercent" value="0">
    <input type="hidden" name="form_action" id="formAction" value="settle">

    <div class="pos-layout">
        <!-- Main Panel (Menu & Controls) -->
        <div class="pos-main-panel">
            <!-- Search Row -->
            <div class="pos-search-row">
                <div class="pos-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" id="posSearchInput" class="pos-input" placeholder="Search item by name or description...">
                </div>
                <div class="pos-input-group">
                    <i class="fas fa-barcode"></i>
                    <input type="text" id="posSkuInput" class="pos-input" placeholder="Item Code/SKU">
                </div>
            </div>

            <!-- Categories Section (Dynamic from Database) -->
            <div class="pos-cat-section">
                <!-- Main Categories -->
                <div class="pos-cat-row" id="mainCatRow">
                    <div class="pos-pill active" data-main-id="all">
                        All Categories (<?php echo count($menu_items); ?>)
                    </div>
                    <?php foreach ($main_categories as $mcat): ?>
                        <div class="pos-pill" data-main-id="<?php echo (int) $mcat['id']; ?>">
                            <?php echo htmlspecialchars($mcat['name']); ?> (<?php echo (int) $mcat['item_count']; ?>)
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Sub Categories -->
                <div class="pos-subcat-row-container">
                    <button type="button" class="pos-scroll-btn" id="subCatScrollLeft"><i class="fas fa-chevron-left"></i></button>
                    <div class="pos-cat-row" id="subCatRow" style="flex:1;">
                        <div class="pos-pill active" data-sub-id="all" data-main-parent="all">
                            All Subcategories
                        </div>
                        <?php foreach ($sub_categories as $scat): ?>
                            <div class="pos-pill" data-sub-id="<?php echo (int) $scat['id']; ?>" data-main-parent="<?php echo (int) $scat['main_category_id']; ?>">
                                <?php echo htmlspecialchars($scat['name']); ?> (<?php echo (int) $scat['item_count']; ?>)
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="pos-scroll-btn" id="subCatScrollRight"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>

            <!-- Product Grid (Dynamic from Database) -->
            <div class="pos-grid-container">
                <div class="pos-grid" id="posProductGrid">
                    <?php foreach ($menu_items as $item): ?>
                        <?php 
                        $unit_price = !empty($item['promo_price']) ? (float) $item['promo_price'] : (float) $item['price'];
                        ?>
                        <div class="pos-card" 
                             data-item-id="<?php echo (int) $item['id']; ?>"
                             data-main-id="<?php echo (int) ($item['main_category_id'] ?? 0); ?>"
                             data-sub-id="<?php echo (int) ($item['sub_category_id'] ?? 0); ?>"
                             data-name="<?php echo htmlspecialchars(strtolower($item['item_name'])); ?>"
                             data-sku="<?php echo htmlspecialchars(strtolower($item['sku'] ?? '')); ?>"
                             data-barcode="<?php echo htmlspecialchars(strtolower($item['barcode'] ?? '')); ?>"
                             data-price="<?php echo $unit_price; ?>"
                             onclick="addItemToCart(<?php echo (int) $item['id']; ?>)">
                            
                            <span class="pos-card-badge" id="cardBadge_<?php echo (int) $item['id']; ?>" style="display:none;">0</span>

                            <div class="pos-card-img-wrap">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars(BASE_URL . '/' . ltrim($item['image_url'], '/')); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <i class="fas fa-utensils pos-card-icon" style="display:none;"></i>
                                <?php else: ?>
                                    <i class="fas fa-utensils pos-card-icon"></i>
                                <?php endif; ?>
                            </div>

                            <div class="pos-card-title">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if (!empty($item['is_vegetarian'])): ?> <span title="Vegetarian">🌱</span><?php endif; ?>
                                <?php if (!empty($item['is_spicy'])): ?> <span title="Spicy">🌶️</span><?php endif; ?>
                            </div>

                            <div class="pos-card-price">
                                <?php if (!empty($item['promo_price'])): ?>
                                    <span style="text-decoration: line-through; color: #94a3b8; font-size: 0.7rem; font-weight: normal; margin-right: 0.2rem;"><?php echo format_currency((float)$item['price']); ?></span>
                                    <span><?php echo format_currency((float)$item['promo_price']); ?></span>
                                <?php else: ?>
                                    <span><?php echo format_currency((float)$item['price']); ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Hidden Form Inputs for POST -->
                            <input type="hidden" class="pos-qty-input" name="qty[<?php echo (int) $item['id']; ?>]" id="inputQty_<?php echo (int) $item['id']; ?>" value="0" data-price="<?php echo $unit_price; ?>" data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>">
                            <input type="hidden" name="recipient_name[<?php echo (int) $item['id']; ?>]" id="inputRecipient_<?php echo (int) $item['id']; ?>" value="">
                            <input type="hidden" class="pos-request-price" name="request_price[<?php echo (int) $item['id']; ?>]" id="inputRequestPrice_<?php echo (int) $item['id']; ?>" value="0">
                            <input type="hidden" name="special_requests[<?php echo (int) $item['id']; ?>]" id="inputSpecial_<?php echo (int) $item['id']; ?>" value="">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Action Toolbar -->
            <div class="pos-action-bar">
                <button type="button" class="pos-btn" id="btnExtraCharge">
                    <i class="fas fa-plus"></i>
                    <span>Extra Charge</span>
                </button>
                <button type="button" class="pos-btn" id="btnDiscount">
                    <i class="fas fa-percent"></i>
                    <span>Discount</span>
                </button>
                <button type="button" class="pos-btn" id="btnNoCharge">
                    <i class="fas fa-dollar-sign"></i>
                    <span>No Charge</span>
                </button>
                <button type="button" class="pos-btn pos-btn-cancel" id="btnCancelOrder">
                    <i class="fas fa-times"></i>
                    <span>Cancel</span>
                </button>
                <button type="button" class="pos-btn" id="btnPrintPreview">
                    <i class="fas fa-print"></i>
                    <span>Print</span>
                </button>
                <button type="button" class="pos-btn" id="btnSaveDraft">
                    <i class="fas fa-save"></i>
                    <span>Save</span>
                </button>
                <button type="submit" class="pos-btn" style="background:#0f172a; color:#fff; border-color:#0f172a;">
                    <i class="fas fa-credit-card"></i>
                    <span>Settle</span>
                </button>
            </div>
        </div>

        <!-- Right Cart Panel -->
        <div class="pos-cart-panel">
            <div class="pos-cart-header">
                <span class="pos-cart-title" id="orderRefTitle">New Order-1</span>
                <div class="pos-cart-icons">
                    <button type="button" class="pos-icon-btn" title="Toggle Customer & Order Options" id="btnToggleCustomer">
                        <i class="fas fa-sliders-h"></i>
                    </button>
                    <button type="button" class="pos-icon-btn" title="Fullscreen/Settings">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                </div>
            </div>

            <div class="pos-cart-count-bar" id="cartCountBar">
                0 item(s) in cart
            </div>

            <!-- Customer & Table Details Accordion -->
            <div class="pos-customer-expander" id="customerExpander">
                <div class="pos-customer-summary" onclick="toggleCustomerForm()">
                    <span><i class="fas fa-user-circle"></i> <span id="customerSummaryText">Walk-in Guest | Dine-In</span></span>
                    <i class="fas fa-chevron-down" id="customerChevron"></i>
                </div>
                <div class="pos-customer-details" id="customerFormDetails">
                    <div>
                        <label style="font-size:0.72rem; font-weight:700; color:#475569;">Order Type</label>
                        <select name="order_type" class="pos-input" style="padding:0.4rem; font-size:0.82rem;">
                            <option value="dine_in" <?php echo $prefill_order_type === 'dine_in' ? 'selected' : ''; ?>>Dine-In</option>
                            <option value="takeaway" <?php echo $prefill_order_type === 'takeaway' ? 'selected' : ''; ?>>Takeaway</option>
                            <option value="delivery" <?php echo $prefill_order_type === 'delivery' ? 'selected' : ''; ?>>Delivery</option>
                        </select>
                    </div>

                    <div>
                        <label style="font-size:0.72rem; font-weight:700; color:#475569;">Existing Customer</label>
                        <select name="customer_id" id="customerIdSelect" class="pos-input" style="padding:0.4rem; font-size:0.82rem;">
                            <option value="0">Walk-in / New Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo (int) $cust['id']; ?>"><?php echo htmlspecialchars($cust['full_name'] ?: $cust['username']); ?> - <?php echo htmlspecialchars($cust['phone']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.4rem;">
                        <input type="text" name="customer_name" class="pos-input" style="padding:0.4rem; font-size:0.82rem;" placeholder="Guest Name">
                        <input type="text" name="customer_phone" class="pos-input" style="padding:0.4rem; font-size:0.82rem;" placeholder="Phone Number">
                    </div>

                    <!-- Dynamic Restaurant Table Selection -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.4rem;">
                        <div>
                            <label style="font-size:0.72rem; font-weight:700; color:#475569;">Table</label>
                            <select name="table_number_select" id="tableNumberSelect" class="pos-input" style="padding:0.4rem; font-size:0.82rem;" onchange="document.getElementById('tableNumberInput').value = this.value">
                                <option value="">Select Table...</option>
                                <?php foreach ($restaurant_tables as $tbl): ?>
                                    <option value="<?php echo htmlspecialchars($tbl['table_name']); ?>" <?php echo ($prefill_table_number === $tbl['table_name']) ? 'selected' : ''; ?>>
                                        Table <?php echo htmlspecialchars($tbl['table_name']); ?> (<?php echo (int) $tbl['seat_count']; ?> Seats - <?php echo ucfirst($tbl['status']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="table_number" id="tableNumberInput" value="<?php echo htmlspecialchars($prefill_table_number); ?>">
                        </div>
                        <div>
                            <label style="font-size:0.72rem; font-weight:700; color:#475569;">Room No.</label>
                            <input type="text" name="room_number" class="pos-input" style="padding:0.4rem; font-size:0.82rem;" placeholder="Room 101 / Suite 2">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 2fr; gap:0.4rem;">
                        <input type="number" name="guest_count" min="1" value="1" class="pos-input" style="padding:0.4rem; font-size:0.82rem;" placeholder="Guests">
                        <input type="text" name="order_notes" class="pos-input" style="padding:0.4rem; font-size:0.82rem;" placeholder="Kitchen Notes">
                    </div>
                </div>
            </div>

            <!-- Cart Items List / Empty State -->
            <div class="pos-cart-items" id="posCartItemsList">
                <div class="pos-empty-state" id="posEmptyState">
                    <i class="fas fa-box-open"></i>
                    <p>No Data</p>
                    <span style="font-size:0.78rem;">Click food items on the left to add to order</span>
                </div>
            </div>

            <!-- Summary Breakdown -->
            <div class="pos-summary">
                <div class="pos-summary-row">
                    <span>Amount</span>
                    <span id="summaryAmount">0.00</span>
                </div>
                <div class="pos-summary-row" id="summaryExtraRow" style="display:none;">
                    <span>Extra Charge</span>
                    <span id="summaryExtra">0.00</span>
                </div>
                <div class="pos-summary-row" id="summaryDiscountRow" style="display:none;">
                    <span>Discount</span>
                    <span id="summaryDiscount">0.00</span>
                </div>
                <div class="pos-summary-row">
                    <span>Tax</span>
                    <span>0.00</span>
                </div>
                <div class="pos-summary-row total">
                    <span>Total</span>
                    <span id="summaryTotal">0.00</span>
                </div>

                <button type="submit" class="btn-quick-pay">
                    <span>Quick Pay</span>
                    <span id="btnQuickPayTotal">0.00</span>
                </button>
            </div>
        </div>
    </div>
</form>

<!-- Modal Dialog for Extra Charge & Discount -->
<div class="pos-modal-overlay" id="posModalOverlay">
    <div class="pos-modal">
        <h3 id="modalTitle">Modal Action</h3>
        <div id="modalBody">
            <!-- Modal input content -->
        </div>
        <div class="pos-modal-btns">
            <button type="button" class="pos-btn" onclick="closeModal()">Cancel</button>
            <button type="button" class="pos-btn" style="background:#0f172a; color:#fff;" id="modalSubmitBtn">Apply</button>
        </div>
    </div>
</div>

<!-- Hidden Receipt Preview (populated by the Print button) -->
<div id="posReceipt" aria-hidden="true">
    <div style="text-align:center;">
        <h2 style="margin:0;">AIRPORT WEST HOTEL</h2>
        <p style="margin:2px 0;">ORDER TICKET (PREVIEW)</p>
    </div>
    <div id="posReceiptMeta" style="border-top:1px dashed #ccc; border-bottom:1px dashed #ccc; padding:6px 0; margin:8px 0; font-size:0.85rem;"></div>
    <table id="posReceiptItems" style="width:100%; border-collapse:collapse;"></table>
    <div id="posReceiptTotals" style="border-top:1px dashed #ccc; padding:6px 0; margin:8px 0;"></div>
    <div style="text-align:center; font-size:0.8rem;">
        <p style="margin:4px 0;">** Preview only — not a paid receipt **</p>
    </div>
</div>

<script>
// Dynamic Category Filtering
const mainCatRow = document.getElementById('mainCatRow');
const subCatRow = document.getElementById('subCatRow');
const productGrid = document.getElementById('posProductGrid');
const searchInput = document.getElementById('posSearchInput');
const skuInput = document.getElementById('posSkuInput');

let activeMainCat = 'all';
let activeSubCat = 'all';

mainCatRow.querySelectorAll('.pos-pill').forEach(pill => {
    pill.addEventListener('click', () => {
        mainCatRow.querySelectorAll('.pos-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        activeMainCat = pill.dataset.mainId;
        activeSubCat = 'all';

        // Filter subcategories visibility dynamically based on parent main category
        subCatRow.querySelectorAll('.pos-pill').forEach(sp => {
            sp.classList.remove('active');
            if (sp.dataset.subId === 'all') {
                sp.classList.add('active');
                sp.style.display = 'inline-block';
            } else if (activeMainCat === 'all' || String(sp.dataset.mainParent) === String(activeMainCat)) {
                sp.style.display = 'inline-block';
            } else {
                sp.style.display = 'none';
            }
        });

        filterProducts();
    });
});

subCatRow.querySelectorAll('.pos-pill').forEach(pill => {
    pill.addEventListener('click', () => {
        subCatRow.querySelectorAll('.pos-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        activeSubCat = pill.dataset.subId;
        filterProducts();
    });
});

function filterProducts() {
    const query = searchInput.value.trim().toLowerCase();
    const skuQuery = skuInput.value.trim().toLowerCase();

    const cards = productGrid.querySelectorAll('.pos-card');
    cards.forEach(card => {
        const itemMain = card.dataset.mainId;
        const itemSub = card.dataset.subId;
        const itemName = card.dataset.name;
        const itemSku = card.dataset.sku;
        const itemBarcode = card.dataset.barcode;

        let matchCat = true;
        if (activeMainCat !== 'all' && String(itemMain) !== String(activeMainCat)) matchCat = false;
        if (activeSubCat !== 'all' && String(itemSub) !== String(activeSubCat)) matchCat = false;

        let matchSearch = true;
        if (query !== '' && !itemName.includes(query)) matchSearch = false;
        if (skuQuery !== '' && !itemSku.includes(skuQuery) && !itemBarcode.includes(skuQuery)) matchSearch = false;

        if (matchCat && matchSearch) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

searchInput.addEventListener('input', filterProducts);
skuInput.addEventListener('input', filterProducts);

// Barcode Enter key auto-add
skuInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const code = skuInput.value.trim().toLowerCase();
        if (!code) return;
        const card = Array.from(productGrid.querySelectorAll('.pos-card')).find(c => 
            c.dataset.sku === code || c.dataset.barcode === code
        );
        if (card) {
            addItemToCart(parseInt(card.dataset.itemId, 10));
            skuInput.value = '';
            filterProducts();
        }
    }
});

// Category Row Scroll Buttons
document.getElementById('subCatScrollLeft').addEventListener('click', () => {
    subCatRow.scrollBy({ left: -150, behavior: 'smooth' });
});
document.getElementById('subCatScrollRight').addEventListener('click', () => {
    subCatRow.scrollBy({ left: 150, behavior: 'smooth' });
});

// Cart & Order Calculation Logic
function formatMoney(val) {
    return Number(val || 0).toFixed(2);
}

function addItemToCart(itemId) {
    const qtyInput = document.getElementById('inputQty_' + itemId);
    if (!qtyInput) return;
    const currentQty = parseInt(qtyInput.value || 0, 10);
    qtyInput.value = currentQty + 1;
    updateCartUI();
}

function adjustItemQty(itemId, delta) {
    const qtyInput = document.getElementById('inputQty_' + itemId);
    if (!qtyInput) return;
    const nextQty = Math.max(0, parseInt(qtyInput.value || 0, 10) + delta);
    qtyInput.value = nextQty;
    updateCartUI();
}

function removeItemFromCart(itemId) {
    const qtyInput = document.getElementById('inputQty_' + itemId);
    if (!qtyInput) return;
    qtyInput.value = 0;
    updateCartUI();
}

function toggleItemOpts(itemId) {
    const opts = document.getElementById('cartOpts_' + itemId);
    if (opts) {
        opts.classList.toggle('show');
    }
}

function updateCartUI() {
    const cartItemsList = document.getElementById('posCartItemsList');
    const emptyState = document.getElementById('posEmptyState');
    const qtyInputs = document.querySelectorAll('.pos-qty-input');

    let totalItemCount = 0;
    let subtotal = 0;
    const activeItems = [];

    qtyInputs.forEach(input => {
        const qty = parseInt(input.value || 0, 10);
        const itemId = input.id.replace('inputQty_', '');
        const card = document.querySelector(`.pos-card[data-item-id="${itemId}"]`);
        const badge = document.getElementById('cardBadge_' + itemId);

        if (qty > 0) {
            totalItemCount += qty;
            const price = parseFloat(input.dataset.price || 0);
            const reqPriceInput = document.getElementById('inputRequestPrice_' + itemId);
            const reqPrice = parseFloat(reqPriceInput ? reqPriceInput.value || 0 : 0);
            const lineTotal = (price * qty) + (reqPrice * qty);
            subtotal += lineTotal;

            if (card) card.classList.add('selected');
            if (badge) {
                badge.textContent = qty;
                badge.style.display = 'block';
            }

            activeItems.push({
                id: itemId,
                name: input.dataset.itemName,
                qty: qty,
                price: price,
                lineTotal: lineTotal
            });
        } else {
            if (card) card.classList.remove('selected');
            if (badge) badge.style.display = 'none';
        }
    });

    document.getElementById('cartCountBar').textContent = `${totalItemCount} item(s) in cart`;

    if (activeItems.length === 0) {
        cartItemsList.innerHTML = '';
        cartItemsList.appendChild(emptyState);
        emptyState.style.display = 'flex';
    } else {
        emptyState.style.display = 'none';
        cartItemsList.innerHTML = activeItems.map(item => `
            <div class="pos-cart-item">
                <div class="pos-cart-item-main">
                    <div class="pos-cart-item-name">${item.name}</div>
                    <div class="pos-cart-item-controls">
                        <button type="button" class="pos-qty-btn" onclick="adjustItemQty(${item.id}, -1)">-</button>
                        <span class="pos-qty-val">${item.qty}</span>
                        <button type="button" class="pos-qty-btn" onclick="adjustItemQty(${item.id}, 1)">+</button>
                    </div>
                    <div class="pos-cart-item-price">${formatMoney(item.lineTotal)}</div>
                    <button type="button" class="pos-cart-item-opt-btn" onclick="toggleItemOpts(${item.id})" title="Notes / Extra Pay">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="pos-cart-item-opt-btn" onclick="removeItemFromCart(${item.id})" style="color:#ef4444;" title="Remove">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
                <div class="pos-cart-item-opts" id="cartOpts_${item.id}">
                    <input type="text" placeholder="Recipient" oninput="syncOpt(${item.id}, 'Recipient', this.value)">
                    <input type="number" step="0.01" placeholder="Extra Pay" oninput="syncOpt(${item.id}, 'RequestPrice', this.value)">
                    <input type="text" style="grid-column: 1 / -1;" placeholder="Special Request / Kitchen Note" oninput="syncOpt(${item.id}, 'Special', this.value)">
                </div>
            </div>
        `).join('');
    }

    // Calculations for discount & extra charge
    const extraVal = parseFloat(document.getElementById('extraChargeAmount').value || 0);
    const discPct = parseFloat(document.getElementById('discountPercent').value || 0);

    const discountVal = (subtotal * (discPct / 100));
    const grandTotal = Math.max(0, subtotal + extraVal - discountVal);

    document.getElementById('summaryAmount').textContent = formatMoney(subtotal);

    const extraRow = document.getElementById('summaryExtraRow');
    if (extraVal > 0) {
        extraRow.style.display = 'flex';
        document.getElementById('summaryExtra').textContent = formatMoney(extraVal);
    } else {
        extraRow.style.display = 'none';
    }

    const discRow = document.getElementById('summaryDiscountRow');
    if (discountVal > 0) {
        discRow.style.display = 'flex';
        document.getElementById('summaryDiscount').textContent = formatMoney(discountVal);
    } else {
        discRow.style.display = 'none';
    }

    document.getElementById('summaryTotal').textContent = formatMoney(grandTotal);
    document.getElementById('btnQuickPayTotal').textContent = formatMoney(grandTotal);
}

function syncOpt(itemId, type, val) {
    const input = document.getElementById(`input${type}_${itemId}`);
    if (input) {
        input.value = val;
        if (type === 'RequestPrice') {
            updateCartUI();
        }
    }
}

// Toggle Customer Form Accordion
function toggleCustomerForm() {
    const details = document.getElementById('customerFormDetails');
    const chevron = document.getElementById('customerChevron');
    details.classList.toggle('show');
    chevron.classList.toggle('fa-chevron-up');
    chevron.classList.toggle('fa-chevron-down');
}

document.getElementById('btnToggleCustomer').addEventListener('click', toggleCustomerForm);

// Modal Dialog Handlers
const overlay = document.getElementById('posModalOverlay');
const modalTitle = document.getElementById('modalTitle');
const modalBody = document.getElementById('modalBody');
const modalSubmitBtn = document.getElementById('modalSubmitBtn');

function openModal(title, bodyHtml, submitHandler) {
    modalTitle.textContent = title;
    modalBody.innerHTML = bodyHtml;
    modalSubmitBtn.onclick = submitHandler;
    overlay.classList.add('show');
}

function closeModal() {
    overlay.classList.remove('show');
}

// Action Bar Buttons
document.getElementById('btnExtraCharge').addEventListener('click', () => {
    const curr = document.getElementById('extraChargeAmount').value || 0;
    openModal('Apply Extra Charge', `
        <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:0.4rem;">Extra Fee Amount (GH₵)</label>
        <input type="number" step="0.01" id="modalInputVal" class="pos-input" value="${curr}" placeholder="0.00">
    `, () => {
        const val = parseFloat(document.getElementById('modalInputVal').value || 0);
        document.getElementById('extraChargeAmount').value = val;
        updateCartUI();
        closeModal();
    });
});

document.getElementById('btnDiscount').addEventListener('click', () => {
    const curr = document.getElementById('discountPercent').value || 0;
    openModal('Apply Discount (%)', `
        <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:0.4rem;">Discount Percentage (%)</label>
        <input type="number" step="1" min="0" max="100" id="modalInputVal" class="pos-input" value="${curr}" placeholder="e.g. 10">
    `, () => {
        const val = Math.min(100, Math.max(0, parseFloat(document.getElementById('modalInputVal').value || 0)));
        document.getElementById('discountPercent').value = val;
        updateCartUI();
        closeModal();
    });
});

document.getElementById('btnNoCharge').addEventListener('click', () => {
    const discInput = document.getElementById('discountPercent');
    // Toggle: apply a full 100% comp, or clear it if the order is already fully comped
    discInput.value = parseFloat(discInput.value || 0) >= 100 ? 0 : 100;
    updateCartUI();
});

document.getElementById('btnCancelOrder').addEventListener('click', () => {
    if (confirm('Are you sure you want to cancel and clear the current cart?')) {
        document.querySelectorAll('.pos-qty-input').forEach(input => input.value = 0);
        document.querySelectorAll('.pos-request-price').forEach(input => input.value = 0);
        document.querySelectorAll('[id^="inputRecipient_"]').forEach(input => input.value = '');
        document.querySelectorAll('[id^="inputSpecial_"]').forEach(input => input.value = '');
        document.getElementById('extraChargeAmount').value = 0;
        document.getElementById('discountPercent').value = 0;
        closeModal();
        updateCartUI();
    }
});

// Print — renders a live order ticket from the cart and prints only that ticket
document.getElementById('btnPrintPreview').addEventListener('click', () => {
    if (!cartHasItems()) {
        alert('Cart is empty. Add at least one item before printing.');
        return;
    }
    buildReceiptPreview();
    window.print();
});

// Save — stores the order as a Draft so it can be continued later from the Orders list
document.getElementById('btnSaveDraft').addEventListener('click', () => {
    if (!cartHasItems()) {
        alert('Cart is empty. Add at least one item before saving.');
        return;
    }
    if (!confirm('Save this order as a Draft? You can continue it later from the Orders list.')) return;
    document.getElementById('formAction').value = 'save_draft';
    document.getElementById('posOrderForm').submit();
});

// Guard Settle / Quick Pay submits against an empty cart; default the action to settle
document.getElementById('posOrderForm').addEventListener('submit', (e) => {
    if (!cartHasItems()) {
        e.preventDefault();
        alert('Cart is empty. Please add at least one item to the order.');
        return;
    }
    document.getElementById('formAction').value = 'settle';
});

function cartHasItems() {
    let count = 0;
    document.querySelectorAll('.pos-qty-input').forEach(input => {
        count += parseInt(input.value || 0, 10);
    });
    return count > 0;
}

function escHtml(str) {
    return String(str === null || str === undefined ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Builds the printable order ticket from the live cart state
function buildReceiptPreview() {
    let subtotal = 0;
    const rows = [];

    document.querySelectorAll('.pos-qty-input').forEach(input => {
        const qty = parseInt(input.value || 0, 10);
        if (qty <= 0) return;
        const itemId = input.id.replace('inputQty_', '');
        const price = parseFloat(input.dataset.price || 0);
        const reqInput = document.getElementById('inputRequestPrice_' + itemId);
        const reqPrice = parseFloat(reqInput ? reqInput.value || 0 : 0);
        const lineTotal = (price + reqPrice) * qty;
        subtotal += lineTotal;
        rows.push(
            '<tr>' +
                '<td style="padding:3px 0;">' + escHtml(input.dataset.itemName || 'Item') + '</td>' +
                '<td style="padding:3px 0; text-align:center;">' + qty + '</td>' +
                '<td style="padding:3px 0; text-align:right;">GH\u20B5' + formatMoney(lineTotal) + '</td>' +
            '</tr>'
        );
    });

    const extraVal = parseFloat(document.getElementById('extraChargeAmount').value || 0);
    const discPct = parseFloat(document.getElementById('discountPercent').value || 0);
    const discountVal = subtotal * (discPct / 100);
    const grandTotal = Math.max(0, subtotal + extraVal - discountVal);

    const orderType = document.querySelector('select[name="order_type"]');
    const tableNo = document.getElementById('tableNumberInput') ? document.getElementById('tableNumberInput').value : '';
    const roomNo = document.querySelector('input[name="room_number"]');
    const guestName = document.querySelector('input[name="customer_name"]');

    document.getElementById('posReceiptMeta').innerHTML =
        '<div style="display:flex; justify-content:space-between;"><span>DATE:</span><span>' + new Date().toLocaleString() + '</span></div>' +
        '<div style="display:flex; justify-content:space-between;"><span>TYPE:</span><span>' +
            (orderType ? escHtml(orderType.options[orderType.selectedIndex].text) : 'Dine-In') +
            (tableNo ? ' / Table ' + escHtml(tableNo) : '') +
            (roomNo && roomNo.value ? ' / Room ' + escHtml(roomNo.value) : '') +
        '</span></div>' +
        '<div style="display:flex; justify-content:space-between;"><span>GUEST:</span><span>' +
            (guestName && guestName.value ? escHtml(guestName.value) : 'Walk-in Guest') +
        '</span></div>';

    document.getElementById('posReceiptItems').innerHTML =
        '<thead><tr style="font-weight:bold;">' +
            '<td style="width:55%;">ITEM</td>' +
            '<td style="width:15%; text-align:center;">QTY</td>' +
            '<td style="width:30%; text-align:right;">AMOUNT</td>' +
        '</tr></thead><tbody>' + rows.join('') + '</tbody>';

    document.getElementById('posReceiptTotals').innerHTML =
        '<div style="display:flex; justify-content:space-between; margin-bottom:3px;"><span>Subtotal:</span><span>GH\u20B5' + formatMoney(subtotal) + '</span></div>' +
        (extraVal > 0 ? '<div style="display:flex; justify-content:space-between; margin-bottom:3px;"><span>Extra Charge:</span><span>GH\u20B5' + formatMoney(extraVal) + '</span></div>' : '') +
        (discountVal > 0 ? '<div style="display:flex; justify-content:space-between; margin-bottom:3px;"><span>Discount (' + formatMoney(discPct) + '%):</span><span>-GH\u20B5' + formatMoney(discountVal) + '</span></div>' : '') +
        '<div style="display:flex; justify-content:space-between; font-weight:bold; font-size:1.1rem; margin-top:5px;"><span>TOTAL:</span><span>GH\u20B5' + formatMoney(grandTotal) + '</span></div>';
}

// Initialize UI
updateCartUI();
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
