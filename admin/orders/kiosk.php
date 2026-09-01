<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$search = trim($_GET['q'] ?? '');
$menu_query = "SELECT * FROM food_items WHERE inactive = 0 OR inactive IS NULL";
$params = [];
if ($search !== '') {
    $menu_query .= " AND item_name LIKE ?";
    $params[] = "%$search%";
}
$menu_query .= " ORDER BY item_name ASC";
$menu_stmt = $conn->prepare($menu_query);
$menu_stmt->execute($params);
$menu_items = $menu_stmt->fetchAll();

$alert = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $customer_id = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $customer_email = trim($_POST['customer_email'] ?? '');
        $customer_address = trim($_POST['customer_address'] ?? '');
        $order_type = strtolower(trim($_POST['order_type'] ?? 'dine_in'));
        $table_number = trim($_POST['table_number'] ?? '');
        $room_number = trim($_POST['room_number'] ?? '');
        $guest_count = max(1, (int) ($_POST['guest_count'] ?? 1));

        $cart_data = json_decode($_POST['cart_data'] ?? '[]', true);
        if (!is_array($cart_data) || empty($cart_data)) {
            throw new Exception('Please add at least one item to the sale.');
        }

        $selected_items = [];
        $order_total = 0;

        foreach ($cart_data as $item_id => $qty) {
            $item_id = (int) $item_id;
            $qty = max(0, (int) $qty);
            if ($qty <= 0) {
                continue;
            }

            $item_stmt = $conn->prepare("SELECT * FROM food_items WHERE id = ?");
            $item_stmt->execute([$item_id]);
            $item = $item_stmt->fetch();
            if (!$item) {
                continue;
            }

            $unit_price = !empty($item['promo_price']) ? (float) $item['promo_price'] : (float) $item['price'];
            $request_price = isset($_POST['request_price_' . $item_id]) ? (float) $_POST['request_price_' . $item_id] : 0.0;
            $special_requests = trim($_POST['special_requests_' . $item_id] ?? '');
            $recipient_name = trim($_POST['recipient_name_' . $item_id] ?? '');

            $item_total = ($unit_price * $qty) + ($request_price * $qty);
            $selected_items[] = [
                'id' => $item_id,
                'name' => $item['item_name'],
                'qty' => $qty,
                'price' => $unit_price,
                'request_price' => $request_price,
                'special_requests' => $special_requests,
                'recipient_name' => $recipient_name,
                'total' => $item_total,
            ];
            $order_total += $item_total;
        }

        if (empty($selected_items)) {
            throw new Exception('Please add a valid item to continue.');
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

        $order_reference = 'KIO-' . strtoupper(uniqid());
        $columns = ['user_id', 'order_reference', 'total', 'status', 'delivery_charge', 'delivery_zone_id', 'company_id', 'created_at', 'order_type', 'table_number', 'room_number', 'guest_count'];
        $values = [$customer_id, $order_reference, $order_total, 'Placed', 0, null, null, date('Y-m-d H:i:s'), $order_type, $table_number, $room_number, $guest_count];

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO orders (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $conn->prepare($sql);
        $stmt->execute($values);
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
        $alert = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

$customers = $conn->query("SELECT id, full_name, username, email, phone FROM customers ORDER BY full_name ASC")->fetchAll();
$admin_title = 'Cashier Kiosk';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    /* Kiosk theme variables mapped to global admin theme */
    :root {
        --kiosk-bg: var(--light-bg, #f3f4f6);
        --kiosk-border: var(--border-color, #edf2f7);
        --kiosk-border-dark: #dfe7ef;
        --kiosk-text-main: var(--text-main, #111827);
        --kiosk-text-muted: var(--text-muted, #64748b);
        --kiosk-primary: var(--primary-color, #f97316);
        --kiosk-primary-dark: #ea580c;
        --kiosk-card-bg: var(--white, #ffffff);
        --kiosk-card-alt: var(--glass, #f8fafc);
        --kiosk-btn-gradient-from: var(--kiosk-primary);
        --kiosk-btn-gradient-to: var(--kiosk-primary-dark);
    }

    html[data-theme="dark"] {
        --kiosk-bg: var(--light-bg);
        --kiosk-border: var(--border-color);
        --kiosk-border-dark: #2d3748;
        --kiosk-text-main: var(--text-main);
        --kiosk-text-muted: var(--text-muted);
        --kiosk-card-bg: var(--white);
        --kiosk-card-alt: rgba(255,255,255,0.02);
    }

    .kiosk-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.7fr) minmax(300px, 420px);
        gap: 2rem;
        align-items: start;
    }

    .card {
        background: var(--kiosk-card-bg);
        border: 1px solid var(--kiosk-border);
        border-radius: 20px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        padding: 1.5rem;
    }

    .search-box {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 1.2rem;
        flex-wrap: wrap;
    }

    .search-box input {
        flex: 1;
        min-width: 200px;
        padding: 0.9rem 1rem;
        border: 1px solid var(--kiosk-border-dark);
        border-radius: 12px;
        font-size: 0.95rem;
        background: var(--kiosk-card-bg);
        color: var(--kiosk-text-main);
    }

    .quick-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.9rem;
    }

    .quick-item {
        background: linear-gradient(180deg, var(--kiosk-card-bg) 0%, rgba(255,247,243,0.9) 100%);
        border: 1px solid rgba(249,115,22,0.12);
        border-radius: 16px;
        padding: 0.9rem;
        min-height: 120px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 0.6rem;
    }

    .quick-item h4 {
        margin: 0;
        font-size: 0.96rem;
        color: var(--kiosk-text-main);
    }

    .quick-item .item-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: var(--kiosk-primary);
        font-weight: 800;
    }

    .quick-btn {
        border: none;
        border-radius: 10px;
        background: linear-gradient(135deg, var(--kiosk-btn-gradient-from) 0%, var(--kiosk-btn-gradient-to) 100%);
        color: var(--kiosk-card-bg);
        padding: 0.7rem 0.8rem;
        font-weight: 800;
        cursor: pointer;
    }

    .sale-panel {
        position: sticky;
        top: 20px;
    }

    .form-grid {
        display: grid;
        gap: 0.9rem;
        margin-bottom: 1rem;
    }

    .form-field label {
        display: block;
        margin-bottom: 0.45rem;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 800;
        color: #475569;
    }

    .form-field input,
    .form-field select,
    .form-field textarea {
        width: 100%;
        padding: 0.8rem 0.9rem;
        border: 1px solid var(--kiosk-border-dark);
        border-radius: 10px;
        font-size: 0.95rem;
        background: var(--kiosk-card-bg);
        color: var(--kiosk-text-main);
    }

    .cart-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 0.7rem;
    }

    .cart-item {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        align-items: center;
        background: var(--kiosk-card-alt);
        border: 1px solid var(--kiosk-border);
        border-radius: 12px;
        padding: 0.7rem 0.8rem;
    }

    .cart-item strong {
        display: block;
        color: var(--kiosk-text-main);
    }

    .cart-item small {
        color: var(--kiosk-text-muted);
    }

    .remove-btn {
        border: none;
        background: rgba(254,226,226,0.9);
        color: var(--danger-color, #991b1b);
        border-radius: 8px;
        width: 30px;
        height: 30px;
        cursor: pointer;
        font-weight: 800;
    }

    .empty-note {
        color: var(--kiosk-text-muted);
        font-weight: 700;
    }

    .totals {
        margin-top: 1rem;
        border-top: 1px solid var(--kiosk-border);
        padding-top: 0.9rem;
    }

    .total-row {
        display: flex;
        justify-content: space-between;
        padding: 0.4rem 0;
        color: var(--kiosk-text-main);
        font-weight: 700;
    }

    .total-row.grand {
        color: var(--kiosk-primary-dark);
        font-size: 1.15rem;
        font-weight: 800;
        border-top: 1px solid rgba(254,215,170,0.5);
        margin-top: 0.5rem;
        padding-top: 0.8rem;
    }

    .btn-submit {
        width: 100%;
        border: none;
        background: linear-gradient(135deg, var(--kiosk-btn-gradient-from) 0%, var(--kiosk-btn-gradient-to) 100%);
        color: var(--kiosk-card-bg);
        padding: 1rem 1.2rem;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 800;
        cursor: pointer;
        margin-top: 1rem;
    }

    .alert {
        padding: 0.9rem 1rem;
        border-radius: 10px;
        margin-bottom: 1.2rem;
        font-weight: 600;
    }

    .alert-danger {
        color: var(--danger-color, #991b1b);
        background: rgba(254,226,226,0.9);
        border: 1px solid rgba(254,202,202,0.9);
    }

    @media (max-width: 980px) {
        .kiosk-layout {
            grid-template-columns: 1fr;
        }
    }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;">
    <div>
        <h1 style="margin:0; font-size:2rem; font-weight:800;">Cashier Kiosk</h1>
        <p style="margin:0.45rem 0 0; color:var(--text-muted);">Quick service with item search and fast sale entry.</p>
    </div>
    <a href="index.php" class="btn-submit" style="width:auto; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; background:linear-gradient(135deg,var(--kiosk-btn-gradient-from) 0%,var(--kiosk-btn-gradient-to) 100%); box-shadow:none; color:var(--kiosk-card-bg);">
        <i class="fas fa-arrow-left"></i> Dashboard
    </a>
</div>

<?php echo $alert; ?>

<form method="POST" id="kioskForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="cart_data" id="cart_data" value='[]'>

    <div class="kiosk-layout">
        <section class="card">
            <div class="search-box">
                <input type="text" id="itemSearch" placeholder="Search menu items..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="button" class="btn-submit" style="width:auto; margin:0; padding:0.9rem 1.2rem;" onclick="window.location='kiosk.php?q='+encodeURIComponent(document.getElementById('itemSearch').value)">Search</button>
            </div>

            <div class="quick-grid">
                <?php foreach ($menu_items as $item): ?>
                    <?php $unit_price = !empty($item['promo_price']) ? (float) $item['promo_price'] : (float) $item['price']; ?>
                    <div class="quick-item" data-id="<?php echo (int) $item['id']; ?>">
                        <div>
                            <h4><?php echo htmlspecialchars($item['item_name']); ?></h4>
                        </div>
                        <div class="item-meta">
                            <span><?php echo format_currency($unit_price); ?></span>
                            <span><?php echo (int) ($item['inventory_count'] ?? 0); ?></span>
                        </div>
                        <button type="button" class="quick-btn" data-id="<?php echo (int) $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['item_name']); ?>" data-price="<?php echo $unit_price; ?>">Add</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="card sale-panel">
            <div class="form-grid">
                <div class="form-field">
                    <label>Order type</label>
                    <select name="order_type">
                        <option value="dine_in" selected>Dine-In</option>
                        <option value="takeaway">Takeaway</option>
                        <option value="delivery">Delivery</option>
                    </select>
                </div>
                <div style="display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:0.8rem;">
                    <div class="form-field">
                        <label>Table</label>
                        <input type="text" name="table_number" placeholder="A1">
                    </div>
                    <div class="form-field">
                        <label>Room</label>
                        <input type="text" name="room_number" placeholder="Room 101">
                    </div>
                </div>
                <div class="form-field">
                    <label>Guest count</label>
                    <input type="number" name="guest_count" value="1" min="1">
                </div>
                <div class="form-field">
                    <label>Customer</label>
                    <select name="customer_id">
                        <option value="0">Walk-in / new customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo (int) $customer['id']; ?>"><?php echo htmlspecialchars($customer['full_name'] ?: $customer['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label>Customer name</label>
                    <input type="text" name="customer_name" placeholder="Walk-in Guest">
                </div>
                <div class="form-field">
                    <label>Phone</label>
                    <input type="text" name="customer_phone" placeholder="024 000 0000">
                </div>
                <div class="form-field">
                    <label>Address</label>
                    <textarea name="customer_address" rows="2" placeholder="Delivery or room address"></textarea>
                </div>
            </div>

            <h3 style="margin:1rem 0 0.75rem;">Current sale</h3>
            <ul class="cart-list" id="cartList">
                <li class="empty-note">No items selected.</li>
            </ul>

            <div class="totals">
                <div class="total-row">
                    <span>Subtotal</span>
                    <span id="subtotalValue">GH₵0.00</span>
                </div>
                <div class="total-row grand">
                    <span>Total</span>
                    <span id="grandTotalValue">GH₵0.00</span>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-check-circle"></i> Save Order
            </button>
        </aside>
    </div>
</form>

<script>
const cart = {};
const cartList = document.getElementById('cartList');
const cartData = document.getElementById('cart_data');
const subtotalValue = document.getElementById('subtotalValue');
const grandTotalValue = document.getElementById('grandTotalValue');

function formatCurrency(value) {
    return 'GH₵' + Number(value || 0).toFixed(2);
}

function renderCart() {
    const entries = Object.entries(cart);
    if (!entries.length) {
        cartList.innerHTML = '<li class="empty-note">No items selected.</li>';
        subtotalValue.textContent = formatCurrency(0);
        grandTotalValue.textContent = formatCurrency(0);
        cartData.value = '[]';
        return;
    }

    let subtotal = 0;
    const rows = entries.map(([id, item]) => {
        const lineTotal = Number(item.price) * Number(item.qty);
        subtotal += lineTotal;
        return `
            <li class="cart-item">
                <div>
                    <strong>${item.name}</strong>
                    <small>Qty: ${item.qty} &nbsp; • &nbsp; ${formatCurrency(item.price)}</small>
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <span><strong>${formatCurrency(lineTotal)}</strong></span>
                    <button type="button" class="remove-btn" data-id="${id}">×</button>
                </div>
            </li>
        `;
    }).join('');

    cartList.innerHTML = rows;
    subtotalValue.textContent = formatCurrency(subtotal);
    grandTotalValue.textContent = formatCurrency(subtotal);
    cartData.value = JSON.stringify(cart);

    document.querySelectorAll('.remove-btn').forEach(button => {
        button.addEventListener('click', () => {
            delete cart[button.dataset.id];
            renderCart();
        });
    });
}

const quickButtons = document.querySelectorAll('.quick-btn');
quickButtons.forEach(button => {
    button.addEventListener('click', () => {
        const id = button.dataset.id;
        const name = button.dataset.name;
        const price = Number(button.dataset.price || 0);
        if (!cart[id]) {
            cart[id] = { id, name, qty: 1, price };
        } else {
            cart[id].qty += 1;
        }
        renderCart();
    });
});

const itemSearch = document.getElementById('itemSearch');
itemSearch.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        window.location = 'kiosk.php?q=' + encodeURIComponent(itemSearch.value);
    }
});
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
