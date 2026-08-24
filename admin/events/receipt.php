<?php
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_check.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $conn->prepare("SELECT b.*, p.name as package_name, u.full_name
FROM event_bookings b
JOIN event_packages p ON b.package_id = p.id
JOIN customers u ON b.user_id = u.id
WHERE b.id = ?");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking || $booking['payment_status'] !== 'paid')
    die("Receipt unavailable or unpaid.");

$company_name = get_setting('company_name', 'Food & Events Co.');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Receipt #RC-
        <?php echo str_pad($booking['id'], 5, '0', STR_PAD_LEFT); ?>
    </title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            padding: 40px;
            max-width: 600px;
            margin: 0 auto;
            background: #f9f9f9;
        }

        .receipt-box {
            border: 2px dashed #999;
            padding: 40px;
            background: white;
        }

        h1 {
            text-align: center;
            margin-bottom: 5px;
        }

        .sub-head {
            text-align: center;
            margin-bottom: 30px;
            font-size: 0.9em;
        }

        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .divider {
            border-bottom: 1px dashed #000;
            margin: 20px 0;
        }

        .total {
            font-weight: bold;
            font-size: 1.2em;
        }

        .paid-stamp {
            color: #27ae60;
            border: 3px solid #27ae60;
            display: inline-block;
            padding: 10px 20px;
            transform: rotate(-10deg);
            font-size: 2em;
            font-weight: bold;
            opacity: 0.8;
            position: absolute;
            top: 20%;
            right: 20%;
        }
    </style>
</head>

<body>
    <div class="receipt-box" style="position: relative;">
        <div class="paid-stamp">PAID</div>

        <h1>
            <?php echo htmlspecialchars($company_name); ?>
        </h1>
        <div class="sub-head">official Receipt</div>

        <div class="row">
            <span>Date:</span>
            <span>
                <?php echo date('Y-m-d H:i'); ?>
            </span>
        </div>
        <div class="row">
            <span>Receipt #:</span>
            <span>RC-
                <?php echo str_pad($booking['id'], 5, '0', STR_PAD_LEFT); ?>
            </span>
        </div>
        <div class="row">
            <span>Customer:</span>
            <span>
                <?php echo htmlspecialchars($booking['full_name']); ?>
            </span>
        </div>

        <div class="divider"></div>

        <div class="row">
            <span>Event Package</span>
            <span>
                <?php echo format_currency($booking['total_amount']); ?>
            </span>
        </div>

        <div class="divider"></div>

        <div class="row total">
            <span>TOTAL PAID</span>
            <span>
                <?php echo format_currency($booking['total_amount']); ?>
            </span>
        </div>

        <div style="text-align: center; margin-top: 40px; font-size: 0.8em;">
            Thank you for your business!
        </div>

        <div style="text-align: center; margin-top: 20px;" class="no-print">
            <button onclick="window.print()">Print Receipt</button>
        </div>
    </div>
</body>

</html>