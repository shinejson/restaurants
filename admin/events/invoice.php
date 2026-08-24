<?php
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_check.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $conn->prepare("SELECT b.*, p.name as package_name, p.base_price_per_head, u.full_name, u.email, u.phone,
u.address
FROM event_bookings b
JOIN event_packages p ON b.package_id = p.id
JOIN customers u ON b.user_id = u.id
WHERE b.id = ?");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking)
    die("Booking not found");

$stmt = $conn->prepare("SELECT * FROM event_materials WHERE booking_id = ?");
$stmt->execute([$id]);
$materials = $stmt->fetchAll();

$company_name = get_setting('company_name', 'Food & Events Co.');
$company_address = get_setting('contact_address', '123 Event St');
$company_phone = get_setting('contact_phone', '123-456-7890');
$company_email = get_setting('contact_email', 'events@example.com');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Invoice #IV-
        <?php echo str_pad($booking['id'], 5, '0', STR_PAD_LEFT); ?>
    </title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            padding: 40px;
            color: #333;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
        }

        .company-details h1 {
            color: #e67e22;
            margin: 0 0 10px 0;
        }

        .invoice-title {
            text-align: right;
        }

        .invoice-title h2 {
            margin: 0;
            color: #7f8c8d;
            font-size: 2.5em;
        }

        .bill-to {
            margin-bottom: 30px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #ddd;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .total-section {
            text-align: right;
        }

        .total-row {
            font-size: 1.2em;
            margin-bottom: 10px;
        }

        .grand-total {
            font-size: 1.5em;
            font-weight: bold;
            color: #e67e22;
        }

        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <button class="no-print" onclick="window.print()"
        style="margin-bottom: 20px; padding: 10px 20px; cursor: pointer;">Print Invoice</button>

    <div class="invoice-header">
        <div class="company-details">
            <h1>
                <?php echo htmlspecialchars($company_name); ?>
            </h1>
            <p>
                <?php echo nl2br(htmlspecialchars($company_address)); ?><br>
                <?php echo htmlspecialchars($company_phone); ?><br>
                <?php echo htmlspecialchars($company_email); ?>
            </p>
        </div>
        <div class="invoice-title">
            <h2>INVOICE</h2>
            <p><strong>Invoice #:</strong> IV-
                <?php echo str_pad($booking['id'], 5, '0', STR_PAD_LEFT); ?><br>
                <strong>Date:</strong>
                <?php echo date('M d, Y'); ?><br>
                <strong>Status:</strong>
                <?php echo ucfirst($booking['payment_status']); ?>
            </p>
        </div>
    </div>

    <div class="bill-to">
        <h3>Bill To:</h3>
        <p><strong>
                <?php echo htmlspecialchars($booking['full_name']); ?>
            </strong><br>
            <?php echo nl2br(htmlspecialchars($booking['address'])); ?><br>
            <?php echo htmlspecialchars($booking['email']); ?><br>
            <?php echo htmlspecialchars($booking['phone']); ?>
        </p>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: center;">Quantity</th>
                <th style="text-align: right;">Unit Price</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Package:
                        <?php echo htmlspecialchars($booking['package_name']); ?>
                    </strong><br>
                    Event Date:
                    <?php echo date('M d, Y', strtotime($booking['event_date'])); ?>
                </td>
                <td style="text-align: center;">
                    <?php echo $booking['guest_count']; ?> guests
                </td>
                <td style="text-align: right;">
                    <?php echo format_currency($booking['base_price_per_head']); ?>
                </td>
                <td style="text-align: right;">
                    <?php echo format_currency($booking['guest_count'] * $booking['base_price_per_head']); ?>
                </td>
            </tr>

            <?php foreach ($materials as $mat): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($mat['name']); ?>
                    </td>
                    <td style="text-align: center;">
                        <?php echo $mat['quantity']; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php echo format_currency($mat['unit_price']); ?>
                    </td>
                    <td style="text-align: right;">
                        <?php echo format_currency($mat['total_price']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="total-section">
        <div class="total-row">Subtotal:
            <?php echo format_currency($booking['total_amount']); ?>
        </div>
        <div class="total-row grand-total">Total:
            <?php echo format_currency($booking['total_amount']); ?>
        </div>
    </div>

    <div
        style="margin-top: 50px; border-top: 1px solid #ccc; padding-top: 20px; text-align: center; font-size: 0.9em; color: #777;">
        <p>Thank you for choosing
            <?php echo htmlspecialchars($company_name); ?>. Please allow up to 48 hours for payment confirmation.
        </p>
    </div>
</body>

</html>