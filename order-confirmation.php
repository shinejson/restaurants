<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

// Check if order ID is provided
if (!isset($_GET['order_id'])) {
    header('Location: index.php');
    exit();
}

$order_id = (int)$_GET['order_id'];

// Verify order belongs to user
$stmt = $conn->prepare("SELECT o.*, u.username, u.email FROM orders o 
                       JOIN users u ON o.user_id = u.id 
                       WHERE o.id = ? AND o.user_id = ?");
$stmt->execute([$order_id, $_SESSION['user_id']]);
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
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation - Food Ordering System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .confirmation-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .confirmation-header {
            margin-bottom: 2rem;
        }
        
        .confirmation-icon {
            font-size: 4rem;
            color: #28a745;
            margin-bottom: 1rem;
        }
        
        .confirmation-header h1 {
            color: #28a745;
            margin-bottom: 0.5rem;
        }
        
        .confirmation-header p {
            color: #666;
            font-size: 1.1rem;
        }
        
        .order-details {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            margin: 2rem 0;
            text-align: left;
        }
        
        .order-details h2 {
            color: var(--dark-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .detail-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .detail-row {
                grid-template-columns: 1fr;
            }
        }
        
        .detail-label {
            font-weight: 600;
            color: #555;
        }
        
        .detail-value {
            color: #333;
        }
        
        .order-items {
            margin: 2rem 0;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #eee;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .item-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .item-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .item-name {
            font-weight: 600;
            color: #333;
        }
        
        .item-quantity {
            color: #666;
        }
        
        .item-price {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .order-total {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--dark-color);
            text-align: right;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid #ddd;
        }
        
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.8rem 2rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: #e55a2b;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            margin-left: 1rem;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-preparing {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .timeline {
            margin: 2rem 0;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .timeline h3 {
            margin-bottom: 1rem;
            color: var(--dark-color);
        }
        
        .timeline-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        
        .timeline-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 10%;
            right: 10%;
            height: 2px;
            background: #ddd;
            z-index: 1;
        }
        
        .timeline-step {
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .step-icon {
            width: 40px;
            height: 40px;
            background: white;
            border: 2px solid #ddd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            color: #999;
        }
        
        .step-icon.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .step-label {
            font-size: 0.9rem;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="confirmation-container">
        <div class="confirmation-header">
            <div class="confirmation-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h1>Order Confirmed!</h1>
            <p>Thank you for your order. Your food is being prepared.</p>
            <p>Order Reference: <strong><?php echo htmlspecialchars($order['order_ref']); ?></strong></p>
        </div>
        
        <div class="order-details">
            <h2><i class="fas fa-receipt"></i> Order Details</h2>
            
            <div class="detail-row">
                <div>
                    <span class="detail-label">Order ID:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['order_ref']); ?></span>
                </div>
                <div>
                    <span class="detail-label">Order Date:</span>
                    <span class="detail-value"><?php echo date('F j, Y g:i A', strtotime($order['created_at'])); ?></span>
                </div>
            </div>
            
            <div class="detail-row">
                <div>
                    <span class="detail-label">Customer Name:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['username']); ?></span>
                </div>
                <div>
                    <span class="detail-label">Email:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['email']); ?></span>
                </div>
            </div>
            
            <div class="detail-row">
                <div>
                    <span class="detail-label">Delivery Address:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                </div>
                <div>
                    <span class="detail-label">Contact Phone:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['contact_phone']); ?></span>
                </div>
            </div>
            
            <div class="detail-row">
                <div>
                    <span class="detail-label">Payment Method:</span>
                    <span class="detail-value"><?php echo ucfirst($order['payment_method']); ?></span>
                </div>
                <div>
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <?php echo ucfirst($order['status']); ?>
                        <span class="status-badge status-<?php echo $order['status']; ?>">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </span>
                </div>
            </div>
            
            <?php if ($order['special_instructions']): ?>
                <div class="detail-row">
                    <div>
                        <span class="detail-label">Special Instructions:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($order['special_instructions']); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Order Timeline -->
        <div class="timeline">
            <h3><i class="fas fa-history"></i> Order Status</h3>
            <div class="timeline-steps">
                <div class="timeline-step">
                    <div class="step-icon <?php echo $order['status'] != 'pending' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="step-label">Order Placed</div>
                </div>
                
                <div class="timeline-step">
                    <div class="step-icon <?php echo in_array($order['status'], ['preparing', 'completed']) ? 'active' : ''; ?>">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <div class="step-label">Preparing</div>
                </div>
                
                <div class="timeline-step">
                    <div class="step-icon <?php echo $order['status'] == 'completed' ? 'active' : ''; ?>">
                        <i class="fas fa-motorcycle"></i>
                    </div>
                    <div class="step-label">On the Way</div>
                </div>
                
                <div class="timeline-step">
                    <div class="step-icon <?php echo $order['status'] == 'completed' ? 'active' : ''; ?>">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="step-label">Delivered</div>
                </div>
            </div>
        </div>
        
        <!-- Order Items -->
        <div class="order-items">
            <h2><i class="fas fa-list"></i> Order Items</h2>
            
            <?php foreach ($order_items as $item): ?>
                <div class="order-item">
                    <div class="item-info">
                        <img src="<?php echo !empty($item['image_url']) ? htmlspecialchars($item['image_url']) : 'assets/images/food-placeholder.jpg'; ?>" 
                             alt="<?php echo htmlspecialchars($item['item_name']); ?>" 
                             class="item-image">
                        <div>
                            <div class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="item-quantity">Quantity: <?php echo $item['quantity']; ?></div>
                        </div>
                    </div>
                    <div class="item-price">
                        ₹<?php echo number_format($item['subtotal'], 2); ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div class="order-total">
                Total Amount: ₹<?php echo number_format($order['total_amount'], 2); ?>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Back to Home
            </a>
            <a href="profile.php?tab=orders" class="btn btn-secondary">
                <i class="fas fa-clipboard-list"></i> View All Orders
            </a>
            <a href="receipt.php?order_id=<?php echo $order_id; ?>" class="btn btn-secondary" target="_blank">
                <i class="fas fa-print"></i> Print Receipt
            </a>
        </div>
        
        <p style="margin-top: 2rem; color: #666; font-size: 0.9rem;">
            <i class="fas fa-info-circle"></i> You will receive an email confirmation shortly.
            For any queries, please contact our customer support.
        </p>
    </div>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>