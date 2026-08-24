<?php
// admin/orders/receipt.php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if (!isset($_GET['id']))
    die('Order ID required');
$id = (int) $_GET['id'];

$stmt = $conn->prepare("SELECT o.*, u.username, u.full_name, u.phone, u.address FROM orders o JOIN customers u ON o.user_id = u.id WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order)
    die('Order not found');

$stmt = $conn->prepare("SELECT oi.*, fi.item_name FROM order_items oi JOIN food_items fi ON oi.food_item_id = fi.id WHERE oi.order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo $order['order_reference']; ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            color: #333;
            max-width: 400px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #eee;
        }

        .center {
            text-align: center;
        }

        .sep {
            border-bottom: 1px dashed #ccc;
            margin: 15px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td {
            padding: 5px 0;
        }

        .bold {
            font-weight: bold;
        }

        .total {
            font-size: 1.2rem;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                margin: 0;
                border: none;
            }
        }
    </style>
</head>

<body onload="if(window.location.search.includes('print')) window.print();">
    <div class="center">
        <h2>FOODEXPRESS</h2>
        <p>Your Favorite Meals Delivered</p>
        <p>123 Restaurant Street, Accra, Ghana</p>
        <p>Tel: +233 123 456 789</p>
    </div>

    <div class="sep"></div>

    <div>
        <table style="font-size: 0.9rem;">
            <tr>
                <td>REF:</td>
                <td class="bold"><?php echo $order['order_reference']; ?></td>
            </tr>
            <tr>
                <td>DATE:</td>
                <td><?php echo date('d M Y, H:i', strtotime($order['created_at'])); ?></td>
            </tr>
            <tr>
                <td>CUST:</td>
                <td><?php echo $order['username']; ?></td>
            </tr>
            <?php if ($order['phone']): ?>
                <tr>
                    <td>TEL:</td>
                    <td><?php echo $order['phone']; ?></td>
                </tr><?php endif; ?>
        </table>
    </div>

    <div class="sep"></div>

    <table>
        <thead>
            <tr class="bold">
                <td style="width: 60%;">ITEM</td>
                <td style="width: 15%;">QTY</td>
                <td style="width: 25%; text-align: right;">PRICE</td>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td style="vertical-align: top;">
                        <span class="bold"><?php echo htmlspecialchars($item['item_name']); ?></span>
                        <?php if (!empty($item['special_requests'])): ?>
                            <br><small style="font-style: italic;">Request:
                                <?php echo htmlspecialchars($item['special_requests']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align: top;">x<?php echo $item['quantity']; ?></td>
                    <td style="text-align: right; vertical-align: top;">
                        <?php
                        $base_total = $item['price'] * $item['quantity'];
                        $request_price = $item['request_price'];
                        $item_total = $base_total + $request_price;

                        if ($request_price > 0) {
                            echo format_currency($base_total) . ' + ' . format_currency($request_price) . ' (Extra) = <br><strong>' . format_currency($item_total) . '</strong>';
                        } else {
                            echo format_currency($item_total);
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="sep"></div>

    <?php
    $delivery_charge = $order['delivery_charge'] ?? 0;
    $items_subtotal = $order['total'] - $delivery_charge;
    ?>

    <div style="font-size: 0.9rem;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
            <span>Subtotal:</span>
            <span><?php echo format_currency($items_subtotal); ?></span>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
            <span>Delivery:</span>
            <span><?php echo format_currency($delivery_charge); ?></span>
        </div>
    </div>

    <div class="sep"></div>

    <div style="display: flex; justify-content: space-between;" class="total bold">
        <span>TOTAL PAID:</span>
        <span><?php echo format_currency($order['total']); ?></span>
    </div>

    <div class="sep"></div>

    <div class="center" style="font-size: 0.8rem;">
        <p>THANK YOU FOR YOUR ORDER!</p>
        <p>Visit us again soon at foodexpress.com</p>
    </div>

    <div class="no-print" style="margin-top: 2rem; text-align: center;">
        <button onclick="window.print()"
            style="padding: 0.5rem 1.5rem; cursor: pointer; background: #333; color: white; border: none; border-radius: 4px;">Print
            Now</button>
        <p style="font-size: 0.8rem; margin-top: 10px; color: #666;">(Receipt window will open print dialog
            automatically)</p>
    </div>
</body>

</html>