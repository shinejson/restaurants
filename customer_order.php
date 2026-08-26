<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

ensure_order_schema($conn);
ensure_table_logs_schema($conn);
ensure_restaurant_tables_schema($conn);

$table_name = trim($_GET['table_name'] ?? $_GET['table'] ?? '');
$table_id = (int) ($_GET['table_id'] ?? 0);

$table = null;
if ($table_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM restaurant_tables WHERE id = ?");
    $stmt->execute([$table_id]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$table && $table_name !== '') {
    $stmt = $conn->prepare("SELECT * FROM restaurant_tables WHERE table_name = ?");
    $stmt->execute([$table_name]);
    $table = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$table) {
    // Default fallback to first table if none specified
    $table = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_name ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$table_id = $table ? (int) $table['id'] : 0;
$table_name = $table ? $table['table_name'] : 'A1';

// Fetch seats for this table
$seats = [];
if ($table_id > 0) {
    $seat_stmt = $conn->prepare("SELECT * FROM restaurant_seats WHERE table_id = ? ORDER BY seat_number ASC");
    $seat_stmt->execute([$table_id]);
    $seats = $seat_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch active menu items & categories
$main_categories = $conn->query("
    SELECT mc.id, mc.name, COUNT(DISTINCT fi.id) as item_count 
    FROM main_categories mc 
    LEFT JOIN sub_categories sc ON sc.main_category_id = mc.id 
    LEFT JOIN food_items fi ON fi.sub_category_id = sc.id AND (fi.inactive = 0 OR fi.inactive IS NULL) 
    GROUP BY mc.id, mc.name 
    ORDER BY mc.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sub_categories = $conn->query("
    SELECT sc.id, sc.main_category_id, sc.name, mc.name as main_category_name 
    FROM sub_categories sc 
    LEFT JOIN main_categories mc ON sc.main_category_id = mc.id 
    ORDER BY sc.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$menu_items = $conn->query("
    SELECT fi.*, sc.name as sub_category_name, sc.main_category_id, mc.name as main_category_name 
    FROM food_items fi 
    LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id 
    LEFT JOIN main_categories mc ON sc.main_category_id = mc.id 
    WHERE fi.inactive = 0 OR fi.inactive IS NULL 
    ORDER BY fi.item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$order_submitted = false;
$placed_order = null;
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $guest_name = trim($_POST['guest_name'] ?? 'Table Guest');
        $guest_phone = trim($_POST['guest_phone'] ?? '');
        $seat_name = trim($_POST['seat_name'] ?? 'Seat 1');
        $special_instructions = trim($_POST['special_instructions'] ?? '');

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
            throw new Exception('Please select at least one menu item to order.');
        }

        // Get or create customer record
        $safe_name = preg_replace('/[^A-Za-z0-9]+/', '_', $guest_name);
        $username = 'qr_' . strtolower(trim($safe_name, '_')) . '_' . time();
        $email = $username . '@qr.local';

        $stmt = $conn->prepare("INSERT INTO customers (username, email, password, full_name, phone, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $username,
            $email,
            password_hash('qr-guest-pass', PASSWORD_DEFAULT),
            $guest_name,
            $guest_phone
        ]);
        $customer_id = (int) $conn->lastInsertId();

        $order_reference = 'QR-' . strtoupper(uniqid());
        $order_note_full = "Seat: $seat_name" . ($special_instructions ? " | Note: $special_instructions" : "");

        $order_fields = ['user_id', 'order_reference', 'total', 'status', 'created_at', 'order_type', 'table_number', 'guest_count'];
        $order_values = [$customer_id, $order_reference, $order_total, 'Placed', date('Y-m-d H:i:s'), 'dine_in', $table_name, 1];

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

        // Update table status to occupied
        $conn->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_name = ?")->execute([$table_name]);

        $order_submitted = true;
        $placed_order = [
            'reference' => $order_reference,
            'table_name' => $table_name,
            'seat_name' => $seat_name,
            'guest_name' => $guest_name,
            'total' => $order_total,
            'items' => $selected_items
        ];
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order from Table <?php echo htmlspecialchars($table_name); ?> - Airport West Hotel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0f172a;
            --accent: #f97316;
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 0;
            padding-bottom: 90px;
        }
        .header {
            background: var(--primary);
            color: white;
            padding: 1.25rem 1rem;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .header h1 { margin: 0; font-size: 1.2rem; font-weight: 800; }
        .table-pill {
            display: inline-block;
            background: var(--accent);
            color: white;
            font-weight: 800;
            font-size: 0.85rem;
            padding: 0.3rem 0.8rem;
            border-radius: 999px;
            margin-top: 0.4rem;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 1rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 0.35rem;
            text-transform: uppercase;
        }
        .form-input, select {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: #ffffff;
            margin-bottom: 0.75rem;
        }
        /* Category Pills */
        .cat-row {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            scrollbar-width: none;
        }
        .cat-row::-webkit-scrollbar { display: none; }
        .pill {
            background: #ffffff;
            border: 1px solid var(--border);
            padding: 0.4rem 0.85rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
            white-space: nowrap;
            cursor: pointer;
        }
        .pill.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        /* Item List */
        .item-card {
            display: flex;
            gap: 0.85rem;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
            background: white;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .item-img {
            width: 65px;
            height: 65px;
            border-radius: 8px;
            object-fit: cover;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #94a3b8;
        }
        .item-details { flex: 1; }
        .item-title { font-weight: 700; font-size: 0.95rem; margin-bottom: 0.2rem; }
        .item-price { font-weight: 800; color: var(--accent); font-size: 0.9rem; }
        .qty-ctrl {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .qty-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: #ffffff;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
        }
        .qty-val { font-weight: 800; width: 24px; text-align: center; font-size: 0.9rem; }
        /* Sticky Cart Bar */
        .cart-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--primary);
            color: white;
            padding: 0.85rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.15);
            z-index: 200;
        }
        .cart-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
        }
        .alert {
            padding: 0.85rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 700;
        }
        .alert-danger { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #dcfce7; color: #166534; }
    </style>
</head>
<body>

<div class="header">
    <i class="fas fa-utensils" style="font-size: 1.5rem; margin-bottom: 0.3rem;"></i>
    <h1>Airport West Hotel</h1>
    <div class="table-pill"><i class="fas fa-qrcode"></i> Table <?php echo htmlspecialchars($table_name); ?></div>
</div>

<div class="container">
    <?php if ($order_submitted): ?>
        <div class="card" style="text-align: center; padding: 2rem 1rem;">
            <i class="fas fa-check-circle" style="font-size: 4rem; color: #16a34a; margin-bottom: 1rem;"></i>
            <h2 style="margin: 0 0 0.5rem;">Order Placed Successfully!</h2>
            <p style="color: #64748b; margin-bottom: 1.5rem;">Your order is sent to the kitchen and will be served to your table shortly.</p>

            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 1rem; text-align: left; margin-bottom: 1.5rem;">
                <p style="margin: 0 0 0.4rem;"><strong>Order Reference:</strong> <?php echo htmlspecialchars($placed_order['reference']); ?></p>
                <p style="margin: 0 0 0.4rem;"><strong>Table:</strong> Table <?php echo htmlspecialchars($placed_order['table_name']); ?></p>
                <p style="margin: 0 0 0.4rem;"><strong>Seat:</strong> <?php echo htmlspecialchars($placed_order['seat_name']); ?></p>
                <p style="margin: 0 0 0.4rem;"><strong>Guest Name:</strong> <?php echo htmlspecialchars($placed_order['guest_name']); ?></p>
                <p style="margin: 0;"><strong>Total Amount:</strong> GH₵<?php echo number_format($placed_order['total'], 2); ?></p>
            </div>

            <a href="customer_order.php?table_name=<?php echo urlencode($table_name); ?>" class="cart-btn" style="text-decoration: none; display: inline-block;">
                Place Another Order
            </a>
        </div>
    <?php else: ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <form method="POST" id="qrOrderForm">
            <!-- Seat & Guest Options -->
            <div class="card">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label class="form-label">Your Seat Name</label>
                        <select name="seat_name" class="form-input">
                            <?php if (!empty($seats)): ?>
                                <?php foreach ($seats as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['seat_name']); ?>">
                                        <?php echo htmlspecialchars($s['seat_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="Seat 1">Seat 1</option>
                                <option value="Seat 2">Seat 2</option>
                                <option value="Seat 3">Seat 3</option>
                                <option value="Seat 4">Seat 4</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Your Name</label>
                        <input type="text" name="guest_name" class="form-input" placeholder="e.g. John Doe" value="Guest">
                    </div>
                </div>

                <div>
                    <label class="form-label">Phone Number (Optional)</label>
                    <input type="text" name="guest_phone" class="form-input" placeholder="0240000000">
                </div>

                <div>
                    <label class="form-label">Kitchen Request / Notes</label>
                    <input type="text" name="special_instructions" class="form-input" placeholder="e.g. Extra spicy, no onions" style="margin-bottom:0;">
                </div>
            </div>

            <!-- Categories Navbar -->
            <div class="cat-row" id="catRow">
                <div class="pill active" data-cat="all">All Items</div>
                <?php foreach ($main_categories as $mc): ?>
                    <div class="pill" data-cat="<?php echo (int) $mc['id']; ?>">
                        <?php echo htmlspecialchars($mc['name']); ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Menu Items List -->
            <div id="menuItemsList">
                <?php foreach ($menu_items as $item): ?>
                    <?php $unit_price = !empty($item['promo_price']) ? (float) $item['promo_price'] : (float) $item['price']; ?>
                    <div class="item-card" data-cat="<?php echo (int) ($item['main_category_id'] ?? 0); ?>">
                        <div class="item-img">
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars(BASE_URL . '/' . ltrim($item['image_url'], '/')); ?>" style="width:100%; height:100%; object-fit:cover; border-radius:8px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <i class="fas fa-utensils" style="display:none;"></i>
                            <?php else: ?>
                                <i class="fas fa-utensils"></i>
                            <?php endif; ?>
                        </div>
                        <div class="item-details">
                            <div class="item-title"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="item-price">GH₵<?php echo number_format($unit_price, 2); ?></div>
                        </div>
                        <div class="qty-ctrl">
                            <button type="button" class="qty-btn" onclick="adjustQty(<?php echo (int) $item['id']; ?>, -1)">-</button>
                            <span class="qty-val" id="qtyVal_<?php echo (int) $item['id']; ?>">0</span>
                            <button type="button" class="qty-btn" onclick="adjustQty(<?php echo (int) $item['id']; ?>, 1)">+</button>
                            <input type="hidden" name="qty[<?php echo (int) $item['id']; ?>]" id="qtyInput_<?php echo (int) $item['id']; ?>" value="0" data-price="<?php echo $unit_price; ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Fixed Bottom Cart Bar -->
            <div class="cart-bar">
                <div>
                    <div style="font-size:0.75rem; opacity:0.8; text-transform:uppercase; font-weight:700;">Total Amount</div>
                    <div style="font-size:1.15rem; font-weight:800;" id="cartTotalText">GH₵0.00</div>
                </div>
                <button type="submit" class="cart-btn" id="submitOrderBtn">
                    Submit Order <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </form>

    <?php endif; ?>
</div>

<script>
// Category Pill Filtering
document.querySelectorAll('#catRow .pill').forEach(pill => {
    pill.addEventListener('click', () => {
        document.querySelectorAll('#catRow .pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        const catId = pill.dataset.cat;

        document.querySelectorAll('#menuItemsList .item-card').forEach(card => {
            if (catId === 'all' || card.dataset.cat === catId) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    });
});

function adjustQty(itemId, delta) {
    const input = document.getElementById('qtyInput_' + itemId);
    const valSpan = document.getElementById('qtyVal_' + itemId);
    if (!input || !valSpan) return;

    let nextQty = Math.max(0, parseInt(input.value || 0, 10) + delta);
    input.value = nextQty;
    valSpan.textContent = nextQty;
    updateTotal();
}

function updateTotal() {
    let grandTotal = 0;
    document.querySelectorAll('input[name^="qty["]').forEach(input => {
        const qty = parseInt(input.value || 0, 10);
        const price = parseFloat(input.dataset.price || 0);
        grandTotal += (qty * price);
    });
    document.getElementById('cartTotalText').textContent = 'GH₵' + grandTotal.toFixed(2);
}
</script>

</body>
</html>
