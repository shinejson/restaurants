<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

if (empty($_SESSION['cart'])) {
    header('Location: ' . tenant_url('index.php'));
    exit;
}

// Guest checkout support: check if logged in or proceeding as guest
$is_guest = !isset($_SESSION['user_id']);
$user_id = $_SESSION['user_id'] ?? null;
$current_customer = null;

if (!$is_guest) {
    $cstmt = $conn->prepare("SELECT id, username, email, full_name, phone, address FROM customers WHERE id = ?");
    $cstmt->execute([$user_id]);
    $current_customer = $cstmt->fetch(PDO::FETCH_ASSOC);

    // Fetch User's Companies for searchable selection
    $stmt = $conn->prepare("SELECT id, name, location FROM companies WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $user_companies = [];
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch Delivery Zones
$delivery_zones = $conn->query("SELECT * FROM delivery_zones ORDER BY zone_name")->fetchAll();

// Calculate total with special requests
$total = 0;
$cart_items_details = [];
foreach ($_SESSION['cart'] as $id => $qty) {
    $stmt = $conn->prepare("SELECT item_name, price, promo_price, description, tax_group_id, tax_group, is_rate_inclusive FROM food_items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if ($item) {
        // Use promo_price if it exists
        $price = !empty($item['promo_price']) ? $item['promo_price'] : $item['price'];
        // Initial subtotal without extras
        $subtotal = $price * $qty;
        $total += $subtotal;
        $cart_items_details[] = [
            'id' => $id,
            'name' => $item['item_name'],
            'description' => $item['description'],
            'price' => $price,
            'qty' => $qty,
            'subtotal' => $subtotal,
            'tax_group_id' => $item['tax_group_id'],
            'tax_group' => $item['tax_group'],
            'is_rate_inclusive' => $item['is_rate_inclusive']
        ];
    }
}

// Group cart items by tax group & compute the tax breakdown
$tax_grouped = group_items_by_tax($cart_items_details);
$total_tax_included = 0;
foreach ($tax_grouped as $tg) {
    $total_tax_included += $tg['total_tax'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf']) && $_POST['csrf'] == $_SESSION['csrf_token']) {
    try {
        $conn->beginTransaction();

        $delivery_zone_id = (int) $_POST['delivery_zone_id'];
        $delivery_stmt = $conn->prepare("SELECT delivery_fee FROM delivery_zones WHERE id = ?");
        $delivery_stmt->execute([$delivery_zone_id]);
        $delivery_charge = $delivery_stmt->fetchColumn() ?: 0;

        // Calculate final total including user-provided request prices
        $items_total = 0;
        foreach ($cart_items_details as $item) {
            $req_price = isset($_POST['request_price'][$item['id']]) ? floatval($_POST['request_price'][$item['id']]) : 0;
            $items_total += ($item['price'] * $item['qty']) + $req_price;
        }

        $final_total = $items_total + $delivery_charge;

        // Customer / Guest resolution
        $order_username = '';
        if ($is_guest) {
            $guest_name = clean_input($_POST['guest_name'] ?? '');
            $guest_email = clean_input($_POST['guest_email'] ?? '');
            $guest_phone = clean_input($_POST['guest_phone'] ?? '');

            if (empty($guest_name) || empty($guest_email) || empty($guest_phone)) {
                throw new Exception('Please provide your name, email, and phone number for guest checkout.');
            }

            // Check if existing customer record by email in this tenant
            $chk = $conn->prepare("SELECT id, full_name FROM customers WHERE email = ? LIMIT 1");
            $chk->execute([$guest_email]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $user_id = (int) $existing['id'];
                $order_username = $guest_name;
            } else {
                $safe = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($guest_name));
                $uname = 'guest_' . ($safe ?: 'cust') . '_' . substr(uniqid(), -5);
                $ins = $conn->prepare("INSERT INTO customers (username, email, password, full_name, phone, type, created_at) VALUES (?, ?, ?, ?, ?, 'guest', NOW())");
                $ins->execute([
                    $uname,
                    $guest_email,
                    password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    $guest_name,
                    $guest_phone
                ]);
                $user_id = (int) $conn->lastInsertId();
                $order_username = $guest_name;
            }
        } else {
            $order_username = $current_customer['full_name'] ?: ($_SESSION['username'] ?? '');
        }

        // Place order
        $order_ref = 'ORD-' . strtoupper(uniqid());
        $company_id = (!empty($_POST['selected_company_id'])) ? (int) $_POST['selected_company_id'] : null;

        $stmt = $conn->prepare("INSERT INTO orders (user_id, company_id, order_reference, total, delivery_charge, delivery_zone_id, username) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $company_id, $order_ref, $final_total, $delivery_charge, $delivery_zone_id, $order_username]);
        $order_id = $conn->lastInsertId();

        foreach ($_SESSION['cart'] as $id => $qty) {
            $stmt = $conn->prepare("SELECT price, promo_price FROM food_items WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            $price = !empty($item['promo_price']) ? $item['promo_price'] : $item['price'];

            $special_requests = isset($_POST['special_requests'][$id]) ? $_POST['special_requests'][$id] : '';
            $request_price = isset($_POST['request_price'][$id]) ? floatval($_POST['request_price'][$id]) : 0;
            $recipient_name = isset($_POST['recipient_name'][$id]) ? $_POST['recipient_name'][$id] : '';

            $oi_stmt = $conn->prepare("INSERT INTO order_items (order_id, food_item_id, quantity, price, special_requests, request_price, recipient_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $oi_stmt->execute([$order_id, $id, $qty, $price, $special_requests, $request_price, $recipient_name]);
        }

        $conn->commit();

        send_order_notifications($order_id);

        unset($_SESSION['cart']);
        header("Location: " . tenant_url("orders/order_confirmation.php?ref=" . urlencode($order_ref)));
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Order processing failed: " . $e->getMessage();
    }
}

include 'includes/header.php';
?>

<section class="container" style="padding: 2rem 0;">
    <div style="max-width: 800px; margin: 0 auto;">
        <h1 style="margin-bottom: 2rem;">Checkout Details</h1>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"
                style="background: #fed7d7; color: #c53030; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($is_guest): ?>
            <!-- Guest Notice Banner -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <strong style="color: #1e293b; font-size: 1rem;"><i class="fas fa-user-circle" style="color: var(--primary-color);"></i> Have a customer account?</strong>
                    <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.9rem;">Sign in to access saved addresses and track orders in your profile.</p>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <a href="<?php echo tenant_url('auth/login.php?redirect=' . urlencode('checkout.php')); ?>" class="btn" style="background: white; border: 1px solid #cbd5e1; padding: 0.5rem 1.1rem; border-radius: 6px; color: #334155; font-weight: 600; text-decoration: none; font-size: 0.9rem;">Sign In</a>
                    <a href="<?php echo tenant_url('auth/register.php?redirect=' . urlencode('checkout.php')); ?>" class="btn" style="background: var(--primary-color); border: none; padding: 0.5rem 1.1rem; border-radius: 6px; color: white; font-weight: 600; text-decoration: none; font-size: 0.9rem;">Create Account</a>
                </div>
            </div>
        <?php else: ?>
            <!-- Logged In Status Banner -->
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 1rem 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <span style="font-weight: 700; color: #166534;"><i class="fas fa-check-circle"></i> Ordering as:</span>
                    <strong style="color: #15803d;"><?php echo htmlspecialchars($current_customer['full_name'] ?: $current_customer['username']); ?></strong>
                    <span style="color: #86efac; margin: 0 0.3rem;">&bull;</span>
                    <span style="color: #166534; font-size: 0.9rem;"><?php echo htmlspecialchars($current_customer['email']); ?></span>
                </div>
                <a href="<?php echo tenant_url('profile.php'); ?>" style="color: #15803d; font-size: 0.85rem; font-weight: 600; text-decoration: underline;">View Profile</a>
            </div>
        <?php endif; ?>

        <form method="POST" id="checkoutForm">
            <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">

            <?php if ($is_guest): ?>
                <!-- Guest Contact Details Section -->
                <div style="background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 1.5rem; border: 1px solid #eee; margin-bottom: 2rem;">
                    <h4 style="margin-top: 0; margin-bottom: 1rem;"><i class="fas fa-id-card" style="color: var(--primary-color);"></i> Guest Contact Information</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #444; margin-bottom: 0.4rem;">Full Name *</label>
                            <input type="text" name="guest_name" required placeholder="e.g. John Doe" style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.95rem;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #444; margin-bottom: 0.4rem;">Email Address *</label>
                            <input type="email" name="guest_email" required placeholder="john@example.com" style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.95rem;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #444; margin-bottom: 0.4rem;">Phone Number *</label>
                            <input type="tel" name="guest_phone" required placeholder="024 123 4567" style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.95rem;">
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Company Section (Left) & Delivery Section (Right) -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <!-- Company Selection -->
                <div
                    style="background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 1.5rem; border: 1px solid #eee;">
                    <h4 style="margin-top: 0; margin-bottom: 1rem;"><i class="fas fa-building"
                            style="color: var(--primary-color);"></i> Group Order (Optional)</h4>
                    <div style="position: relative;">
                        <input type="text" id="companySearch" list="companyList" placeholder="Search for a company..."
                            style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem;">
                        <datalist id="companyList">
                            <?php foreach ($user_companies as $comp): ?>
                                <option value="<?php echo htmlspecialchars($comp['name']); ?>"
                                    data-id="<?php echo $comp['id']; ?>">
                                    <?php echo htmlspecialchars($comp['location']); ?>
                                </option>
                            <?php endforeach; ?>
                        </datalist>
                        <input type="hidden" name="selected_company_id" id="selectedCompanyId">
                    </div>
                </div>

                <!-- Delivery Zone Selection -->
                <div
                    style="background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 1.5rem; border: 1px solid #eee;">
                    <h4 style="margin-top: 0; margin-bottom: 1rem;"><i class="fas fa-map-marker-alt"
                            style="color: var(--primary-color);"></i> Delivery Location *</h4>
                    <select name="delivery_zone_id" id="deliveryZone" required
                        style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; background: #fff; cursor: pointer;">
                        <option value="" data-fee="0">Select your area...</option>
                        <?php foreach ($delivery_zones as $zone): ?>
                            <option value="<?php echo $zone['id']; ?>" data-fee="<?php echo $zone['delivery_fee']; ?>">
                                <?php echo htmlspecialchars($zone['zone_name']); ?>
                                (<?php echo format_currency($zone['delivery_fee']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Order Items Section -->
            <div
                style="background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); padding: 2.5rem;">
                <h3 style="margin-bottom: 2rem; border-bottom: 1px solid #eee; padding-bottom: 1rem;">Order Items &
                    Labeling</h3>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid #eee; text-align: left; color: #666;">
                                <th style="padding: 1rem 0.5rem;">Food Item</th>
                                <th style="padding: 1rem 0.5rem; text-align: center;">Qty</th>
                                <th style="padding: 1rem 0.5rem; text-align: right;">Price</th>
                                <th style="padding: 1rem 0.5rem; text-align: center;">Customize</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tax_grouped as $tgroup): ?>
                                <!-- Tax group separator -->
                                <tr style="background: #f8f9fa;">
                                    <td colspan="4"
                                        style="padding: 0.9rem 0.5rem; font-weight: 700; color: #555; border-bottom: 1px solid #eee; font-size: 0.9rem;">
                                        <i class="fas fa-layer-group" style="color: var(--primary-color);"></i>
                                        <?php echo $tgroup['config'] ? 'Tax Group: ' . htmlspecialchars($tgroup['name']) : 'No Tax Group'; ?>
                                        <?php if ($tgroup['config'] && $tgroup['total_rate'] > 0): ?>
                                            <span
                                                style="background: rgba(255,107,53,.12); color: var(--primary-color); padding: 0.15rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; margin-left: 0.5rem;">
                                                <?php echo number_format($tgroup['total_rate'] * 100, 2); ?>% (included in prices)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php foreach ($tgroup['items'] as $item): ?>
                                <tr style="border-bottom: 1px solid #f9f9f9;">
                                    <td style="padding: 1.5rem 0.5rem;">
                                        <div style="font-weight: 700; color: #333;">
                                            <?php echo htmlspecialchars($item['name']); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #888; margin-top: 0.3rem; line-height: 1.4;">
                                            <?php echo htmlspecialchars($item['description'] ?? 'No description available'); ?>
                                        </div>
                                    </td>
                                    <td style="padding: 1.5rem 0.5rem; text-align: center; color: #555;">x
                                        <?php echo $item['qty']; ?>
                                    </td>
                                    <td style="padding: 1.5rem 0.5rem; text-align: right; font-weight: 600;">
                                        <?php echo format_currency($item['subtotal']); ?>
                                    </td>
                                    <td style="padding: 1.5rem 0.5rem; text-align: center;">
                                        <button type="button" class="toggle-request-btn"
                                            data-id="<?php echo $item['id']; ?>"
                                            style="background: #f0f0f0; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; color: #666; transition: all 0.2s;">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr id="request-row-<?php echo $item['id']; ?>" style="display: none; background: #fafafa;">
                                    <td colspan="4" style="padding: 2rem; border-bottom: 1px solid #eee;">
                                        <div style="display: grid; gap: 1.5rem;">
                                            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 1.5rem;">
                                                <div>
                                                    <label
                                                        style="display: block; font-size: 0.85rem; font-weight: 700; color: #444; margin-bottom: 0.5rem;">Recipient
                                                        / Owner Name</label>
                                                    <input type="text" name="recipient_name[<?php echo $item['id']; ?>]"
                                                        placeholder="Who is this food for? (e.g. John Doe - HR)"
                                                        style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px;">
                                                </div>
                                                <div>
                                                    <label
                                                        style="display: block; font-size: 0.85rem; font-weight: 700; color: #444; margin-bottom: 0.5rem;">Extra
                                                        Pay (GH₵)</label>
                                                    <input type="number" name="request_price[<?php echo $item['id']; ?>]"
                                                        value="0.00" step="0.01" min="0" class="request-price-input"
                                                        style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px;">
                                                </div>
                                            </div>
                                            <div>
                                                <label
                                                    style="display: block; font-size: 0.85rem; font-weight: 700; color: #444; margin-bottom: 0.5rem;">Special
                                                    Instructions</label>
                                                <textarea name="special_requests[<?php echo $item['id']; ?>]"
                                                    placeholder="e.g. Extra Fish, No Cabbage..."
                                                    style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px; min-height: 60px; font-family: inherit;"></textarea>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2"
                                    style="padding: 2rem 0.5rem 0.5rem; text-align: right; color: #888; font-weight: 600;">
                                    Subtotal:</td>
                                <td style="padding: 2rem 0.5rem 0.5rem; text-align: right; font-weight: 600; color: #333;"
                                    id="display-subtotal"><?php echo format_currency($total); ?></td>
                                <td></td>
                            </tr>
                            <?php if ($total_tax_included > 0): ?>
                                <tr>
                                    <td colspan="2"
                                        style="padding: 0.4rem 0.5rem; text-align: right; color: #aaa; font-weight: 600; font-size: 0.9rem;">
                                        (of which taxes included):</td>
                                    <td
                                        style="padding: 0.4rem 0.5rem; text-align: right; color: #aaa; font-weight: 600; font-size: 0.9rem;">
                                        <?php echo format_currency($total_tax_included); ?></td>
                                    <td></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="2"
                                    style="padding: 0.5rem; text-align: right; color: #888; font-weight: 600;">Optional
                                    Extras:</td>
                                <td style="padding: 0.5rem; text-align: right; font-weight: 600; color: #ff6b35;"
                                    id="display-extras">GH₵0.00</td>
                                <td></td>
                            </tr>
                            <tr style="border-bottom: 2px solid #eee;">
                                <td colspan="2"
                                    style="padding: 0.5rem; text-align: right; color: #888; font-weight: 600;">Delivery
                                    Charge:</td>
                                <td style="padding: 0.5rem 0.5rem 1rem; text-align: right; font-weight: 600; color: #2ecc71;"
                                    id="display-delivery">GH₵0.00</td>
                                <td></td>
                            </tr>
                            <tr style="font-size: 1.8rem; font-weight: 900;">
                                <td colspan="2" style="padding: 1.5rem 0.5rem; text-align: right; color: #333;">Total
                                    Paid:</td>
                                <td style="padding: 1.5rem 0.5rem; text-align: right; color: var(--primary-color);"
                                    id="display-total"><?php echo format_currency($total); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Tax Summary (taxes are already included in the item prices) -->
                <div
                    style="background: #fff8f3; border: 1px solid #ffe0cc; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem;">
                    <h4 style="margin: 0 0 1rem; color: #333;">
                        <i class="fas fa-percentage" style="color: var(--primary-color);"></i> Tax Summary
                        <span style="font-weight: 400; color: #888; font-size: 0.85rem;">(already included in item
                            prices)</span>
                    </h4>
                    <?php foreach ($tax_grouped as $tgroup): ?>
                        <div style="margin-bottom: 1rem;">
                            <div style="font-weight: 700; color: #555; margin-bottom: 0.4rem;">
                                <?php echo $tgroup['config'] ? htmlspecialchars($tgroup['name']) : 'No Tax Group'; ?>
                                <?php if ($tgroup['config'] && $tgroup['total_rate'] > 0): ?>
                                    <span style="font-weight: 400; color: #888;">—
                                        <?php echo number_format($tgroup['total_rate'] * 100, 2); ?>% combined</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($tgroup['components'])): ?>
                                <?php foreach ($tgroup['components'] as $comp): ?>
                                    <div
                                        style="display: flex; justify-content: space-between; padding: 0.25rem 0 0.25rem 1rem; color: #666;">
                                        <span><?php echo htmlspecialchars($comp['name']); ?>
                                            (<?php echo number_format($comp['rate'] * 100, 2); ?>%)</span>
                                        <span><?php echo format_currency($comp['amount']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div
                                    style="display: flex; justify-content: space-between; padding: 0.4rem 0 0.25rem 1rem; font-weight: 700; color: #333; border-top: 1px dashed #ffd9b8; margin-top: 0.3rem;">
                                    <span>Total tax in this group</span>
                                    <span><?php echo format_currency($tgroup['total_tax']); ?></span>
                                </div>
                            <?php else: ?>
                                <div style="padding-left: 1rem; color: #999; font-size: 0.9rem;">No active taxes
                                    configured for this group yet.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($total_tax_included > 0): ?>
                        <div
                            style="display: flex; justify-content: space-between; font-weight: 800; color: #333; border-top: 2px solid #ffe0cc; padding-top: 0.8rem;">
                            <span>Total tax included in your order</span>
                            <span style="color: var(--primary-color);"><?php echo format_currency($total_tax_included); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; border-top: 2px solid #f0f0f0; padding-top: 2rem;">
                    <a href="<?php echo tenant_url('cart.php'); ?>"
                        style="color: #666; text-decoration: none; font-weight: 600;">
                        <i class="fas fa-arrow-left"></i> Edit Cart
                    </a>
                    <button type="submit" class="btn btn-primary"
                        style="background: var(--primary-color); color: white; border: none; padding: 1.2rem 3rem; border-radius: 12px; font-weight: 800; font-size: 1.2rem; cursor: pointer; box-shadow: 0 4px 15px rgba(255,107,53,0.3); transition: all 0.3s;">
                        Place Order <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</section>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggleBtns = document.querySelectorAll('.toggle-request-btn');
        const priceInputs = document.querySelectorAll('.request-price-input');
        const deliverySelect = document.getElementById('deliveryZone');
        const subtotal = <?php echo $total; ?>;

        function updateTotal() {
            let extras = 0;
            priceInputs.forEach(input => {
                extras += parseFloat(input.value) || 0;
            });

            const selectedOption = deliverySelect.options[deliverySelect.selectedIndex];
            const deliveryFee = parseFloat(selectedOption.dataset.fee) || 0;

            document.getElementById('display-extras').textContent = `GH₵${extras.toFixed(2)}`;
            document.getElementById('display-delivery').textContent = `GH₵${deliveryFee.toFixed(2)}`;
            document.getElementById('display-total').textContent = `GH₵${(subtotal + extras + deliveryFee).toFixed(2)}`;
        }

        priceInputs.forEach(input => {
            input.addEventListener('input', updateTotal);
        });

        deliverySelect.addEventListener('change', updateTotal);

        // Company Search Logic
        const companyInput = document.getElementById('companySearch');
        const companyList = document.getElementById('companyList');
        const hiddenCompanyId = document.getElementById('selectedCompanyId');

        companyInput.addEventListener('change', function () {
            const selectedVal = this.value;
            const options = companyList.options;
            hiddenCompanyId.value = ''; // Reset

            for (let i = 0; i < options.length; i++) {
                if (options[i].value === selectedVal) {
                    hiddenCompanyId.value = options[i].dataset.id;
                    break;
                }
            }
        });

        toggleBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const row = document.getElementById(`request-row-${id}`);
                const icon = btn.querySelector('i');

                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                    icon.className = 'fas fa-minus';
                    btn.style.background = '#ff6b35';
                    btn.style.color = 'white';
                } else {
                    row.style.display = 'none';
                    icon.className = 'fas fa-plus';
                    btn.style.background = '#f0f0f0';
                    btn.style.color = '#666';
                }
            });
        });
    });
</script>

<?php include 'includes/footer.php'; ?>