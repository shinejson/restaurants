<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

// Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filters
$status = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
// DEFAULT: Show last 30 days instead of just today
$date_from = isset($_GET['date_from']) ? clean_input($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? clean_input($_GET['date_to']) : date('Y-m-d');

// Build query
$query = "SELECT o.*, u.username, u.email, COUNT(oi.id) as item_count 
          FROM orders o 
          JOIN customers u ON o.user_id = u.id 
          LEFT JOIN order_items oi ON o.id = oi.order_id";

$where = [];
$params = [];

if ($status) {
    $where[] = "o.status = ?";
    $params[] = $status;
}

if ($search) {
    $where[] = "(o.order_reference LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($date_from) {
    $where[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset";

// Get orders
$stmt = $conn->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Total count for pagination
$count_query = "SELECT COUNT(DISTINCT o.id) as total FROM orders o JOIN customers u ON o.user_id = u.id";
if (!empty($where)) {
    $count_query .= " WHERE " . implode(" AND ", $where);
}

$count_stmt = $conn->prepare($count_query);
$count_stmt->execute($params);
$total_orders = $count_stmt->fetch()['total'] ?? 0;
$total_pages = ceil($total_orders / $limit);

$admin_title = 'Manage Orders';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    .orders-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2.5rem;
    }

    .orders-header h1 {
        font-size: 2.2rem;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .orders-filter-bar {
        background: var(--white);
        padding: 1.5rem;
        border-radius: 20px;
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-md);
        margin-bottom: 2.5rem;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1.5rem;
        align-items: end;
    }

    .filter-btn-group {
        display: flex;
        gap: 0.75rem;
    }

    .btn-action {
        width: 100%;
        padding: 0.8rem;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn-apply {
        background: var(--primary-color);
        color: white;
        border: none;
    }

    .btn-reset {
        background: var(--light-bg);
        color: var(--text-main);
        border: 1px solid var(--border-color);
        text-decoration: none;
    }

    .btn-apply:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
    }

    .btn-reset:hover {
        background: var(--border-color);
    }

    .orders-table-card {
        background: var(--glass);
        backdrop-filter: blur(20px);
        border-radius: 24px;
        border: 1px solid var(--glass-border);
        padding: 2rem;
        box-shadow: var(--shadow-lg);
    }

    .customer-info {
        display: flex;
        flex-direction: column;
    }

    .customer-info .username {
        font-weight: 700;
        color: var(--text-main);
    }

    .customer-info .email {
        font-size: 0.85rem;
        color: var(--text-muted);
    }

    .status-pill {
        padding: 0.5rem 1rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 800;
        text-transform: uppercase;
        display: inline-block;
    }

    .action-link {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        color: white;
        text-decoration: none;
        transition: var(--transition);
    }

    .action-link:hover {
        transform: scale(1.1);
    }
</style>

<div class="orders-header">
    <div>
        <h1>Order Management</h1>
        <p style="color: var(--text-muted);">View, track and update customer orders.</p>
    </div>
    <div style="text-align: right;">
        <span
            style="font-size: 2rem; font-weight: 800; color: var(--primary-color);"><?php echo number_format($total_orders); ?></span>
        <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Total
            Orders</div>
    </div>
</div>

<div class="orders-filter-bar">
    <form method="GET" class="filter-grid">
        <div class="form-group">
            <label
                style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; display: block;">Status</label>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="Placed" <?php echo $status == 'Placed' ? 'selected' : ''; ?>>Placed</option>
                <option value="Preparing" <?php echo $status == 'Preparing' ? 'selected' : ''; ?>>Preparing</option>
                <option value="On the Way" <?php echo $status == 'On the Way' ? 'selected' : ''; ?>>On the Way</option>
                <option value="Delivered" <?php echo $status == 'Delivered' ? 'selected' : ''; ?>>Delivered</option>
                <option value="Cancelled" <?php echo $status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="form-group">
            <label
                style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; display: block;">Search</label>
            <input type="text" name="search" placeholder="Reference, Name..."
                value="<?php echo htmlspecialchars($search); ?>" class="form-control">
        </div>
        <div class="form-group">
            <label
                style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; display: block;">From</label>
            <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="form-control">
        </div>
        <div class="form-group">
            <label
                style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; display: block;">To</label>
            <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="form-control">
        </div>
        <div class="filter-btn-group">
            <button type="submit" class="btn-action btn-apply"><i class="fas fa-filter"></i> Apply</button>
            <a href="index.php" class="btn-action btn-reset"><i class="fas fa-redo"></i></a>
        </div>
    </form>
</div>

<div class="orders-table-card">
    <?php if (empty($orders)): ?>
        <div style="text-align: center; padding: 5rem 2rem;">
            <div style="font-size: 4rem; color: var(--border-color); margin-bottom: 1.5rem;"><i class="fas fa-inbox"></i>
            </div>
            <h2 style="color: var(--text-main);">No matching orders</h2>
            <p style="color: var(--text-muted);">We couldn't find any orders matching those filters.</p>
            <a href="index.php" class="btn-primary" style="margin-top: 1.5rem;">Reset All Filters</a>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Qty</th>
                    <th>Subtotal</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order):
                    $curr_status = $order['status'] ?: 'Pending';
                    $status_class = 'badge-secondary';
                    if (strtolower($curr_status) == 'delivered')
                        $status_class = 'badge-success';
                    elseif (strtolower($curr_status) == 'on the way')
                        $status_class = 'badge-info';
                    elseif (strtolower($curr_status) == 'preparing')
                        $status_class = 'badge-warning';
                    elseif (strtolower($curr_status) == 'placed')
                        $status_class = 'badge-primary';
                    elseif (strtolower($curr_status) == 'cancelled')
                        $status_class = 'badge-danger';
                    ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 700; color: var(--primary-color);">
                            #<?php echo htmlspecialchars($order['order_reference']); ?></td>
                        <td>
                            <div class="customer-info">
                                <span class="username"><?php echo htmlspecialchars($order['username']); ?></span>
                                <span class="email"><?php echo htmlspecialchars($order['email']); ?></span>
                            </div>
                        </td>
                        <td><span style="font-weight: 700;"><?php echo $order['item_count']; ?></span> items</td>
                        <td style="font-weight: 800; color: var(--text-main);"><?php echo format_currency($order['total']); ?>
                        </td>
                        <td>
                            <form method="POST" action="update_status.php" class="status-form">
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <select name="status" class="status-select" data-order-id="<?php echo $order['id']; ?>"
                                    style="padding: 0.4rem; border-radius: 10px; border: 1px solid var(--border-color); background: var(--white); font-size: 0.8rem; font-weight: 700; cursor: pointer;">
                                    <option value="Placed" <?php echo $order['status'] == 'Placed' ? 'selected' : ''; ?>>Placed
                                    </option>
                                    <option value="Preparing" <?php echo $order['status'] == 'Preparing' ? 'selected' : ''; ?>>
                                        Preparing</option>
                                    <option value="On the Way" <?php echo $order['status'] == 'On the Way' ? 'selected' : ''; ?>>
                                        On the Way</option>
                                    <option value="Delivered" <?php echo $order['status'] == 'Delivered' ? 'selected' : ''; ?>>
                                        Delivered</option>
                                    <option value="Cancelled" <?php echo $order['status'] == 'Cancelled' ? 'selected' : ''; ?>>
                                        Cancelled</option>
                                </select>
                            </form>
                        </td>
                        <td style="white-space: nowrap; font-size: 0.85rem; color: var(--text-muted);">
                            <?php echo date('M d, Y', strtotime($order['created_at'])); ?><br>
                            <span style="font-weight: 600;"><?php echo date('h:i A', strtotime($order['created_at'])); ?></span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                                <a href="view.php?id=<?php echo $order['id']; ?>" class="action-link"
                                    style="background: var(--info-color);" title="View Details"><i
                                        class="fas fa-expand-alt"></i></a>
                                <a href="receipt.php?id=<?php echo $order['id']; ?>" class="action-link"
                                    style="background: var(--dark-color);" title="Print Receipt" target="_blank"><i
                                        class="fas fa-print"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 3rem;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                        class="btn-reset"
                        style="padding: 0.8rem 1.2rem; border-radius: 12px; display: flex; align-items: center;"><i
                            class="fas fa-chevron-left"></i></a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                        style="padding: 0.8rem 1.2rem; border-radius: 12px; font-weight: 700; text-decoration: none; transition: var(--transition);
                       <?php echo $i == $page ? 'background: var(--primary-color); color: white; box-shadow: 0 4px 12px rgba(255,107,53,0.3);' : 'background: var(--light-bg); color: var(--text-main); border: 1px solid var(--border-color);'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                        class="btn-reset"
                        style="padding: 0.8rem 1.2rem; border-radius: 12px; display: flex; align-items: center;"><i
                            class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', function () {
            if (confirm(`Do you want to update this order status to ${this.value}?`)) {
                this.closest('form').submit();
            } else {
                this.value = this.dataset.originalValue;
            }
        });
        select.dataset.originalValue = select.value;
    });
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>