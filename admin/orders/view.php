<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$id = (int) $_GET['id'];

// Get order details
$stmt = $conn->prepare("SELECT o.*, u.username, u.email, u.phone, u.full_name, u.address, c.name as company_name, c.location as company_location
                       FROM orders o 
                       JOIN customers u ON o.user_id = u.id 
                       LEFT JOIN companies c ON o.company_id = c.id
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: index.php');
    exit();
}

// Get order items
$stmt = $conn->prepare("SELECT oi.*, fi.item_name, fi.image_url 
                       FROM order_items oi 
                       JOIN food_items fi ON oi.food_item_id = fi.id 
                       WHERE oi.order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$admin_title = 'Order Details #' . $order['order_reference'];
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-shopping-bag"></i> Order Details</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Reference: <strong>
                <?php echo htmlspecialchars($order['order_reference']); ?>
            </strong> | Placed on
            <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?>
        </p>
    </div>
    <div style="display: flex; gap: 1rem;">
        <a href="labels.php?id=<?php echo $id; ?>" target="_blank" class="btn-submit"
            style="background: var(--primary-color); color: white; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 12px rgba(255,107,53,0.3);">
            <i class="fas fa-tags"></i> Generate Labels
        </a>
        <a href="receipt.php?id=<?php echo $id; ?>" target="_blank" class="btn-submit"
            style="background: var(--dark-color); color: white; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600;">
            <i class="fas fa-print"></i> Print Receipt
        </a>
        <a href="index.php" class="btn-submit"
            style="background: var(--light-bg); color: var(--text-main); text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600; border: 1px solid var(--border-color);">
            <i class="fas fa-arrow-left"></i> Back to Orders
        </a>
    </div>
</div>

<div class="grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
    <!-- Order Items -->
    <div>
        <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.8rem;">
            Order Items & Special Requests</h3>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item Name / Recipient</th>
                        <th>Base Price</th>
                        <th>Qty</th>
                        <th>Extra Request</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr
                            style="<?php echo !empty($item['special_requests']) ? 'background: rgba(255, 107, 53, 0.03);' : ''; ?>">
                            <td style="display: flex; align-items: flex-start; gap: 1rem; padding: 1.2rem;">
                                <img src="<?php echo !empty($item['image_url']) ? BASE_URL . '/' . htmlspecialchars($item['image_url']) : BASE_URL . '/assets/images/food-placeholder.jpg'; ?>"
                                    style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px;">
                                <div>
                                    <div style="font-weight: 700; font-size: 1.1rem;">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </div>
                                    <?php if (!empty($item['recipient_name'])): ?>
                                        <div
                                            style="margin-top: 0.4rem; color: var(--primary-color); font-weight: 800; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                            <i class="fas fa-user-tag"></i> For:
                                            <?php echo htmlspecialchars($item['recipient_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['special_requests'])): ?>
                                        <div
                                            style="margin-top: 0.5rem; font-size: 0.85rem; color: #d35400; background: #fff3cd; padding: 0.5rem; border-radius: 4px; border-left: 3px solid #f39c12;">
                                            <i class="fas fa-comment-dots"></i> <strong>Request:</strong>
                                            <?php echo htmlspecialchars($item['special_requests']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo format_currency($item['price']); ?></td>
                            <td>x <?php echo $item['quantity']; ?></td>
                            <td>
                                <?php if ($item['request_price'] > 0): ?>
                                    <span style="color: #e67e22; font-weight: 700;">+
                                        <?php echo format_currency($item['request_price']); ?></span>
                                <?php else: ?>
                                    <span style="color: #ccc;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 700;">
                                <?php echo format_currency(($item['price'] * $item['quantity']) + $item['request_price']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4"
                            style="text-align: right; padding: 1rem; font-weight: 600; color: var(--text-muted);">
                            Subtotal (Items & Extras)</td>
                        <td style="padding: 1rem; font-weight: 700; color: var(--text-main);">
                            <?php echo format_currency($order['total'] - ($order['delivery_charge'] ?? 0)); ?>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="4"
                            style="text-align: right; padding: 0.5rem 1rem; font-weight: 600; color: var(--text-muted);">
                            Delivery Charge</td>
                        <td style="padding: 0.5rem 1rem; font-weight: 700; color: #2ecc71;">
                            <?php echo format_currency($order['delivery_charge'] ?? 0); ?>
                        </td>
                    </tr>
                    <tr style="background: var(--light-bg); border-top: 2px solid var(--border-color);">
                        <td colspan="4"
                            style="text-align: right; padding: 1.2rem; font-weight: 800; font-size: 1.1rem; color: var(--text-main);">
                            Total Paid</td>
                        <td style="padding: 1.2rem; font-weight: 800; font-size: 1.3rem; color: var(--primary-color);">
                            <?php echo format_currency($order['total']); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Price Adjustment Form -->
        <div
            style="margin-top: 2rem; padding: 1.5rem; background: var(--light-bg); border-radius: 12px; border: 1px dashed var(--border-color);">
            <h4 style="margin-bottom: 1rem;"><i class="fas fa-edit"></i> Adjust Final Order Total</h4>
            <form method="POST" action="update_price.php"
                style="display: flex; align-items: flex-end; gap: 1.5rem; flex-wrap: wrap;">
                <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div style="flex: 1; min-width: 200px;">
                    <label
                        style="display: block; font-size: 0.8rem; font-weight: 700; color: #666; margin-bottom: 0.5rem;">Current
                        Total (GH₵)</label>
                    <input type="number" name="new_total" step="0.01" min="0" value="<?php echo $order['total']; ?>"
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; font-weight: 700; font-size: 1.1rem; color: var(--primary-color);">
                </div>
                <button type="submit" class="btn-submit"
                    style="background: var(--dark-color); color: white; border: none; padding: 0.9rem 2rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    Update Final Price
                </button>
            </form>
            <p style="margin-top: 0.8rem; font-size: 0.8rem; color: #888;">
                <i class="fas fa-info-circle"></i> Use this to finalize the price if you've fulfilled extra requests not
                fully covered by the customer's initial offer.
            </p>
        </div>
    </div>
</div>

<!-- Info Sidebar -->
<div style="display: flex; flex-direction: column; gap: 2rem;">
    <!-- Status Management -->
    <div class="dashboard-card">
        <h3 style="margin-bottom: 1.2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
            Update Status</h3>
        <form method="POST" action="update_status.php" style="display: grid; gap: 1rem;">
            <input type="hidden" name="order_id" value="<?php echo $id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="redirect_to" value="view.php?id=<?php echo $id; ?>">
            <select name="status"
                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main); font-weight: 600;">
                <option value="Placed" <?php echo $order['status'] == 'Placed' ? 'selected' : ''; ?>>Placed</option>
                <option value="Preparing" <?php echo $order['status'] == 'Preparing' ? 'selected' : ''; ?>>Preparing
                </option>
                <option value="On the Way" <?php echo $order['status'] == 'On the Way' ? 'selected' : ''; ?>>On the Way
                </option>
                <option value="Delivered" <?php echo $order['status'] == 'Delivered' ? 'selected' : ''; ?>>Delivered
                </option>
                <option value="Cancelled" <?php echo $order['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled
                </option>
            </select>
            <button type="submit" class="btn-submit"
                style="background: var(--primary-color); color: white; border: none; padding: 0.8rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                Update Order Status
            </button>
        </form>
    </div>

    <!-- Company Information -->
    <?php if (!empty($order['company_name'])): ?>
        <div class="dashboard-card" style="border-left: 5px solid var(--primary-color);">
            <h3 style="margin-bottom: 1.2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                Company Information</h3>
            <div style="display: grid; gap: 0.8rem;">
                <div style="font-size: 0.95rem;">
                    <div
                        style="font-weight: 700; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; margin-bottom: 0.2rem;">
                        Company Name</div>
                    <div style="font-weight: 700; color: #333; font-size: 1.2rem;">
                        <?php echo htmlspecialchars($order['company_name']); ?>
                    </div>
                </div>
                <div style="font-size: 0.95rem;">
                    <div
                        style="font-weight: 700; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; margin-bottom: 0.2rem;">
                        Office Location</div>
                    <div>
                        <?php echo htmlspecialchars($order['company_location'] ?: 'N/A'); ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>