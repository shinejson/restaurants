<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf']) && $_POST['csrf'] == $_SESSION['csrf_token']) {
    $action = $_POST['action'];
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);

    if ($action == 'add') {
        $qty = filter_input(INPUT_POST, 'quantity', FILTER_SANITIZE_NUMBER_INT) ?: 1;
        if (isset($_SESSION['cart'][$item_id])) {
            $_SESSION['cart'][$item_id] += $qty;
        } else {
            $_SESSION['cart'][$item_id] = $qty;
        }
    } elseif ($action == 'update') {
        $qty = filter_input(INPUT_POST, 'quantity', FILTER_SANITIZE_NUMBER_INT);
        if ($qty > 0) {
            $_SESSION['cart'][$item_id] = $qty;
        } else {
            unset($_SESSION['cart'][$item_id]);
        }
    } elseif ($action == 'remove') {
        unset($_SESSION['cart'][$item_id]);
    }

    // For AJAX, return JSON
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        $total_count = array_sum($_SESSION['cart']);
        echo json_encode(['success' => true, 'cartCount' => $total_count]);
        exit;
    }
}

// Fetch cart items
$cart_items = [];
$total = 0;
if (!empty($_SESSION['cart'])) {
    $ids = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $stmt = $conn->prepare("SELECT id, item_name, price, image_url FROM food_items WHERE id IN ($ids)");
    $stmt->execute();
    $items = $stmt->fetchAll();
    foreach ($items as $item) {
        $qty = $_SESSION['cart'][$item['id']];
        $subtotal = $item['price'] * $qty;
        $total += $subtotal;
        $cart_items[] = ['item' => $item, 'qty' => $qty, 'subtotal' => $subtotal];
    }
}

include 'includes/header.php';
?>

<section class="container cart-section">
    <h1>Your Shopping Cart</h1>

    <?php if (empty($cart_items)): ?>
        <div class="empty-state-card">
            <i class="fas fa-shopping-basket"></i>
            <h3>Your cart is empty</h3>
            <p>Looks like you haven't added anything to your cart yet.</p>
            <a href="<?php echo BASE_URL; ?>/index.php" class="btn btn-hero">
                Browse Menu
            </a>
        </div>
    <?php else: ?>
        <div class="cart-table-wrapper">
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cart_items as $ci): ?>
                        <tr>
                            <td>
                                <div class="cart-item-info">
                                    <?php
                                    $image = !empty($ci['item']['image_url']) ? htmlspecialchars($ci['item']['image_url']) : BASE_URL . '/assets/images/food-placeholder.jpg';
                                    ?>
                                    <img src="<?php echo $image; ?>" class="cart-item-img">
                                    <span
                                        class="cart-item-name"><?php echo htmlspecialchars($ci['item']['item_name']); ?></span>
                                </div>
                            </td>
                            <td><?php echo format_currency($ci['item']['price']); ?></td>
                            <td>
                                <input type="number" class="quantity cart-qty-input" data-id="<?php echo $ci['item']['id']; ?>"
                                    value="<?php echo $ci['qty']; ?>" min="1">
                            </td>
                            <td class="item-total"><?php echo format_currency($ci['subtotal']); ?></td>
                            <td>
                                <button class="remove-item btn-action btn-cancel" data-id="<?php echo $ci['item']['id']; ?>">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="cart-tfoot">
                    <tr>
                        <th colspan="3" class="total-label">Total Amount:</th>
                        <th id="total" class="total-value"><?php echo format_currency($total); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>

            <div class="cart-footer">
                <a href="<?php echo BASE_URL; ?>/index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Continue Shopping
                </a>
                <a href="<?php echo BASE_URL; ?>/checkout.php" class="btn btn-hero">
                    Proceed to Checkout <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
    <?php endif; ?>
</section>

<?php include 'includes/footer.php'; ?>