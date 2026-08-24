<?php
// admin/orders/labels.php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if (!isset($_GET['id'])) {
    die("Order ID required");
}

$id = (int) $_GET['id'];

// Get order items and company info
$stmt = $conn->prepare("SELECT oi.*, fi.item_name, o.order_reference, c.name as company_name 
                       FROM order_items oi 
                       JOIN food_items fi ON oi.food_item_id = fi.id 
                       JOIN orders o ON oi.order_id = o.id
                       LEFT JOIN companies c ON o.company_id = c.id
                       WHERE oi.order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

if (empty($items)) {
    die("No items found for this order");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Name Tags -
        <?php echo $items[0]['order_reference']; ?>
    </title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f0f0;
            margin: 0;
            padding: 20px;
        }

        .print-btn {
            background: #333;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
            font-weight: 600;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        @media print {
            .print-btn {
                display: none;
            }

            body {
                background: white;
                padding: 0;
            }
        }

        .labels-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            max-width: 800px;
            margin: 0 auto;
        }

        .label-tag {
            background: white;
            border: 2px solid #333;
            border-radius: 10px;
            padding: 20px;
            min-height: 180px;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .label-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-ref {
            font-size: 0.7rem;
            color: #888;
            text-transform: uppercase;
            font-weight: 800;
        }

        .recipient-section {
            margin-bottom: 15px;
        }

        .recipient-label {
            font-size: 0.7rem;
            color: #888;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .recipient-name {
            font-size: 1.4rem;
            font-weight: 900;
            color: #ff6b35;
            margin: 0;
        }

        .food-item {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            margin: 5px 0;
            border-left: 4px solid #333;
            padding-left: 10px;
        }

        .instructions {
            background: #f9f9f9;
            padding: 8px;
            border-radius: 5px;
            font-size: 0.8rem;
            font-style: italic;
            color: #555;
            margin-top: 10px;
            border: 1px dashed #ddd;
        }

        .company-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #333;
            color: white;
            padding: 15px 15px 5px 15px;
            transform: rotate(15deg);
            font-size: 0.6rem;
            font-weight: 800;
            text-transform: uppercase;
        }
    </style>
</head>

<body>

    <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print Name Tags</button>

    <div class="labels-container">
        <?php foreach ($items as $item): ?>
            <?php for ($i = 0; $i < $item['quantity']; $i++): // Generate a tag for each quantity ?>
                <div class="label-tag">
                    <?php if (!empty($item['company_name'])): ?>
                        <div class="company-badge">
                            <?php echo htmlspecialchars($item['company_name']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="label-header">
                        <span class="order-ref">
                            <?php echo htmlspecialchars($item['order_reference']); ?>
                        </span>
                        <i class="fas fa-utensils" style="color: #eee;"></i>
                    </div>

                    <div class="recipient-section">
                        <div class="recipient-label">Deliver To:</div>
                        <h2 class="recipient-name">
                            <?php echo htmlspecialchars($item['recipient_name'] ?: 'Customer'); ?>
                        </h2>
                    </div>

                    <div class="food-item">
                        <?php echo htmlspecialchars($item['item_name']); ?>
                    </div>

                    <?php if (!empty($item['special_requests'])): ?>
                        <div class="instructions">
                            <strong>Note:</strong>
                            <?php echo htmlspecialchars($item['special_requests']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        <?php endforeach; ?>
    </div>

</body>

</html>