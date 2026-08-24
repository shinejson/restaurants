<?php
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_check.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $status = clean_input($_POST['status']);
        $stmt = $conn->prepare("UPDATE event_bookings SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        $success_msg = "Status updated successfully.";
    } elseif ($_POST['action'] === 'update_payment') {
        $payment_status = clean_input($_POST['payment_status']);
        $stmt = $conn->prepare("UPDATE event_bookings SET payment_status = ? WHERE id = ?");
        $stmt->execute([$payment_status, $id]);
        $success_msg = "Payment status updated successfully.";
    }
}

// Fetch Booking Details
$stmt = $conn->prepare("SELECT b.*, p.name as package_name, p.base_price_per_head, u.full_name, u.email, u.phone
FROM event_bookings b
JOIN event_packages p ON b.package_id = p.id
JOIN customers u ON b.user_id = u.id
WHERE b.id = ?");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: index.php');
    exit();
}

// Fetch Materials
$stmt = $conn->prepare("SELECT * FROM event_materials WHERE booking_id = ?");
$stmt->execute([$id]);
$materials = $stmt->fetchAll();

$admin_title = 'View Event #' . $id;
$current_page = 'events';

include '../includes/admin_header.php';
?>

<div class="dashboard-container">
    <div style="margin-bottom: 2rem;">
        <a href="index.php" style="color: #666; text-decoration: none;"><i class="fas fa-arrow-left"></i> Back to
            Calendar</a>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem;">
        <div>
            <h1>Event #
                <?php echo $booking['id']; ?>
            </h1>
            <p style="color: #666;">Booking Date:
                <?php echo date('M d, Y H:i', strtotime($booking['created_at'])); ?>
            </p>
        </div>
        <div style="text-align: right;">
            <a href="invoice.php?id=<?php echo $id; ?>" target="_blank" class="btn-submit"
                style="background: #34495e; margin-right: 0.5rem;">
                <i class="fas fa-file-invoice"></i> Limit Invoice
            </a>
            <?php if ($booking['payment_status'] === 'paid'): ?>
                <a href="receipt.php?id=<?php echo $id; ?>" target="_blank" class="btn-submit">
                    <i class="fas fa-receipt"></i> Print Receipt
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success">
            <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
        <!-- Left Column -->
        <div>
            <div class="dashboard-card" style="margin-bottom: 2rem;">
                <h3><i class="fas fa-info-circle"></i> Event Details</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <strong>Package:</strong><br>
                        <?php echo htmlspecialchars($booking['package_name']); ?>
                    </div>
                    <div>
                        <strong>Event Date:</strong><br>
                        <?php echo date('F d, Y', strtotime($booking['event_date'])); ?>
                    </div>
                    <div>
                        <strong>Guest Count:</strong><br>
                        <?php echo $booking['guest_count']; ?> people
                    </div>
                    <div>
                        <strong>Base Price:</strong><br>
                        <?php echo format_currency($booking['base_price_per_head']); ?> / head
                    </div>
                </div>
            </div>

            <div class="dashboard-card">
                <h3><i class="fas fa-cubes"></i> Additional Materials</h3>
                <?php if (empty($materials)): ?>
                    <p style="color: #666;">No additional materials requested.</p>
                <?php else: ?>
                    <table class="table" style="width: 100%;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="text-align: left; padding: 0.5rem;">Item</th>
                                <th style="text-align: center; padding: 0.5rem;">Qty</th>
                                <th style="text-align: right; padding: 0.5rem;">Unit Price</th>
                                <th style="text-align: right; padding: 0.5rem;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials as $mat): ?>
                                <tr>
                                    <td style="padding: 0.5rem; border-bottom: 1px solid #eee;">
                                        <?php echo htmlspecialchars($mat['name']); ?>
                                    </td>
                                    <td style="padding: 0.5rem; text-align: center; border-bottom: 1px solid #eee;">
                                        <?php echo $mat['quantity']; ?>
                                    </td>
                                    <td style="padding: 0.5rem; text-align: right; border-bottom: 1px solid #eee;">
                                        <?php echo format_currency($mat['unit_price']); ?>
                                    </td>
                                    <td style="padding: 0.5rem; text-align: right; border-bottom: 1px solid #eee;">
                                        <?php echo format_currency($mat['total_price']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <div style="margin-top: 1.5rem; text-align: right; font-size: 1.2rem; font-weight: 700;">
                    Grand Total:
                    <?php echo format_currency($booking['total_amount']); ?>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <div class="dashboard-card" style="margin-bottom: 2rem;">
                <h3><i class="fas fa-user"></i> Customer Info</h3>
                <p>
                    <strong>Name:</strong>
                    <?php echo htmlspecialchars($booking['full_name']); ?><br>
                    <strong>Email:</strong>
                    <?php echo htmlspecialchars($booking['email']); ?><br>
                    <strong>Phone:</strong>
                    <?php echo htmlspecialchars($booking['phone']); ?>
                </p>
            </div>

            <div class="dashboard-card">
                <h3><i class="fas fa-tasks"></i> Management</h3>

                <form method="POST" style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Event Status</label>
                    <input type="hidden" name="action" value="update_status">
                    <div style="display: flex; gap: 0.5rem;">
                        <select name="status" class="form-control">
                            <option value="pending" <?php echo $booking['status'] == 'pending' ? 'selected' : ''; ?>>
                                Pending</option>
                            <option value="confirmed" <?php echo $booking['status'] == 'confirmed' ? 'selected' : ''; ?>>
                                Confirmed</option>
                            <option value="completed" <?php echo $booking['status'] == 'completed' ? 'selected' : ''; ?>>
                                Completed</option>
                            <option value="cancelled" <?php echo $booking['status'] == 'cancelled' ? 'selected' : ''; ?>>
                                Cancelled</option>
                        </select>
                        <button type="submit" class="btn-submit">Update</button>
                    </div>
                </form>

                <form method="POST">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Payment Status</label>
                    <input type="hidden" name="action" value="update_payment">
                    <div style="display: flex; gap: 0.5rem;">
                        <select name="payment_status" class="form-control">
                            <option value="unpaid" <?php echo $booking['payment_status'] == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="paid" <?php echo $booking['payment_status'] == 'paid' ? 'selected' : ''; ?>>
                                Paid</option>
                            <option value="refunded" <?php echo $booking['payment_status'] == 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        </select>
                        <button type="submit" class="btn-submit">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>