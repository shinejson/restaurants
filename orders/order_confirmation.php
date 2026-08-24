<?php
session_start();
require '../config/db.php';
require_once '../includes/functions.php';
include '../includes/header.php';

$ref = filter_input(INPUT_GET, 'ref', FILTER_SANITIZE_STRING);

$stmt = $conn->prepare("SELECT * FROM orders WHERE order_reference = ?");
$stmt->execute([$ref]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found');
}

$item_stmt = $conn->prepare("SELECT oi.*, fi.item_name FROM order_items oi JOIN food_items fi ON oi.food_item_id = fi.id WHERE order_id = ?");
$item_stmt->execute([$order['id']]);
$items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="confirmation-wrapper" style="max-width: 800px; margin: 4rem auto; padding: 0 1rem;">
    <div class="confirmation-card"
        style="background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); overflow: hidden; animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);">

        <!-- Header Section -->
        <div class="card-header"
            style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); padding: 3rem 2rem; text-align: center; color: white;">
            <div class="success-icon" style="font-size: 5rem; margin-bottom: 1rem; animation: scaleIn 0.5s 0.3s both;">
                <i class="fas fa-check-circle"></i>
            </div>
            <h1 style="margin: 0; font-size: 2.5rem; font-weight: 800;">Order Confirmed!</h1>
            <p style="margin: 0.5rem 0 0; opacity: 0.9; font-size: 1.1rem;">Thank you for choosing FoodExpress. Your
                meal is being prepared.</p>
        </div>

        <div class="card-body" style="padding: 2.5rem;">
            <!-- Order Reference & Status -->
            <div
                style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <span
                        style="display: block; color: #888; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; letter-spacing: 1px;">Order
                        Reference</span>
                    <strong
                        style="font-size: 1.25rem; color: #333;"><?php echo htmlspecialchars($order['order_reference']); ?></strong>
                </div>
                <div style="text-align: right;">
                    <span
                        style="display: block; color: #888; text-transform: uppercase; font-size: 0.75rem; font-weight: 700; letter-spacing: 1px;">Status</span>
                    <span class="status-badge"
                        style="display: inline-block; padding: 0.4rem 1rem; border-radius: 20px; font-weight: 700; font-size: 0.85rem; background: #e8f5e9; color: #2e7d32; text-transform: uppercase;">
                        <?php echo htmlspecialchars($order['status']); ?>
                    </span>
                </div>
            </div>

            <!-- Items Table -->
            <div style="margin-bottom: 2.5rem;">
                <h3
                    style="margin-bottom: 1rem; color: #333; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-receipt" style="color: #28a745;"></i> Order Summary
                </h3>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr
                                style="border-bottom: 2px solid #f0f0f0; text-align: left; color: #666; font-size: 0.9rem;">
                                <th style="padding: 1rem 0;">Item</th>
                                <th style="padding: 1rem 0; text-align: center;">Qty</th>
                                <th style="padding: 1rem 0; text-align: right;">Price</th>
                                <th style="padding: 1rem 0; text-align: right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr style="border-bottom: 1px solid #f9f9f9;">
                                    <td style="padding: 1.2rem 0; font-weight: 600; color: #333;">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </td>
                                    <td style="padding: 1.2rem 0; text-align: center; color: #666;">
                                        <?php echo $item['quantity']; ?>
                                    </td>
                                    <td style="padding: 1.2rem 0; text-align: right; color: #666;">
                                        <?php echo format_currency($item['price']); ?>
                                    </td>
                                    <td style="padding: 1.2rem 0; text-align: right; font-weight: 700; color: #333;">
                                        <?php echo format_currency($item['price'] * $item['quantity']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3"
                                    style="padding: 1.5rem 0 0.5rem; text-align: right; font-weight: 600; color: #888;">
                                    Subtotal:</td>
                                <td style="padding: 1.5rem 0 0.5rem; text-align: right; font-weight: 700; color: #333;">
                                    <?php echo format_currency($order['total'] - $order['delivery_charge']); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3"
                                    style="padding: 0.5rem 0; text-align: right; font-weight: 600; color: #888;">
                                    Delivery Charge:</td>
                                <td style="padding: 0.5rem 0; text-align: right; font-weight: 700; color: #2ecc71;">
                                    <?php echo format_currency($order['delivery_charge']); ?>
                                </td>
                            </tr>
                            <tr style="border-top: 2px solid #f0f0f0;">
                                <td colspan="3"
                                    style="padding: 1rem 0; text-align: right; font-weight: 600; color: #888;">
                                    Grand Total:</td>
                                <td
                                    style="padding: 1rem 0; text-align: right; font-size: 1.75rem; font-weight: 800; color: #28a745;">
                                    <?php echo format_currency($order['total']); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Timeline/Steps -->
            <?php
            $status = $order['status'];
            $steps = [
                'Placed' => ['icon' => 'fa-check', 'label' => 'Placed'],
                'Preparing' => ['icon' => 'fa-utensils', 'label' => 'Preparing'],
                'On the Way' => ['icon' => 'fa-motorcycle', 'label' => 'On the Way'],
                'Delivered' => ['icon' => 'fa-home', 'label' => 'Delivered']
            ];

            $current_step_index = 0;
            $status_to_index = [
                'Placed' => 1,
                'Preparing' => 2,
                'On the Way' => 3,
                'Delivered' => 4,
                'Cancelled' => 0
            ];

            $active_index = $status_to_index[$status] ?? 1;
            ?>
            <div class="order-timeline"
                style="display: flex; justify-content: space-between; margin-bottom: 3rem; position: relative; padding: 0 1rem;">
                <div
                    style="position: absolute; top: 15px; left: 10%; right: 10%; height: 2px; background: #eee; z-index: 1;">
                    <div
                        style="width: <?php echo ($active_index - 1) * 33.33; ?>%; height: 100%; background: #28a745; transition: width 0.5s;">
                    </div>
                </div>

                <?php
                $i = 1;
                foreach ($steps as $key => $step):
                    $is_active = $i <= $active_index;
                    $is_current = $i == $active_index;
                    $color = $is_active ? '#28a745' : '#ccc';
                    $bg = $is_active ? ($is_current ? '#fff' : '#28a745') : '#fff';
                    $border = $is_active ? '2px solid #28a745' : '2px solid #eee';
                    $text_color = $is_active ? '#28a745' : '#aaa';
                    ?>
                    <div style="position: relative; z-index: 2; text-align: center; flex: 1;">
                        <div
                            style="width: 32px; height: 32px; background: <?php echo $bg; ?>; border: <?php echo $border; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: <?php echo $is_active && !$is_current ? '#white' : $color; ?>; margin: 0 auto 0.5rem; transition: all 0.3s;">
                            <i class="fas <?php echo $step['icon']; ?>"
                                style="font-size: 0.8rem; color: <?php echo $is_active && !$is_current ? 'white' : $color; ?>;"></i>
                        </div>
                        <span
                            style="font-size: 0.75rem; font-weight: 600; color: <?php echo $text_color; ?>;"><?php echo $step['label']; ?></span>
                    </div>
                    <?php
                    $i++;
                endforeach; ?>
            </div>

            <!-- Actions -->
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="<?php echo BASE_URL; ?>/index.php" class="btn-primary"
                    style="flex: 1; min-width: 200px; background: var(--primary-color); color: white; text-align: center; padding: 1.2rem; border-radius: 12px; text-decoration: none; font-weight: 700; transition: all 0.3s; box-shadow: 0 4px 15px rgba(255,107,53,0.3);">
                    Return to Home
                </a>
                <a href="<?php echo BASE_URL; ?>/profile.php?tab=orders" class="btn-secondary"
                    style="flex: 1; min-width: 200px; background: #f8f9fa; color: #333; text-align: center; padding: 1.2rem; border-radius: 12px; text-decoration: none; font-weight: 700; border: 1px solid #e0e0e0;">
                    Track All My Orders
                </a>
            </div>
        </div>

        <div
            style="padding: 1.5rem; text-align: center; border-top: 1px solid #f0f0f0; color: #888; font-size: 0.9rem;">
            <i class="fas fa-info-circle"></i> A confirmation email has been sent to your account.
        </div>
    </div>
</div>

<style>
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes scaleIn {
        from {
            opacity: 0;
            transform: scale(0.5);
        }

        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .confirmation-card a:hover {
        transform: translateY(-2px);
    }

    .btn-primary:hover {
        background: #e55a2b !important;
        box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4) !important;
    }

    .btn-secondary:hover {
        background: #eee !important;
    }
</style>

<?php include '../includes/footer.php'; ?>