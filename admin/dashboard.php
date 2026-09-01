<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

// Get current view
$view = isset($_GET['view']) ? clean_input($_GET['view']) : 'overview';

// Set page title
$admin_title = ucfirst($view) . ' Analysis';

include 'includes/admin_header.php';
?>

<style>
    /* Analytics Dashboard Styles */
    .view-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2.5rem;
    }

    .view-header h2 {
        margin: 0;
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--text-main);
        letter-spacing: -0.02em;
    }

    .btn-filter {
        background: var(--white);
        border: 1px solid var(--border-color);
        padding: 0.8rem 1.5rem;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 700;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transition: var(--transition);
        box-shadow: var(--shadow-sm);
    }

    .btn-filter:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
        transform: translateY(-2px);
    }

    /* Stats Cards - Premium Look */
    .stats-row,
    .sales-stats-row,
    .customers-stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 3rem;
    }

    .stat-box,
    .stat-card-vertical,
    .stat-card {
        background: var(--glass);
        backdrop-filter: blur(25px);
        -webkit-backdrop-filter: blur(25px);
        border: 1px solid var(--glass-border);
        border-radius: 20px;
        padding: 2rem;
        box-shadow: var(--shadow-md);
        transition: var(--transition);
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }

    .stat-box::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 100px;
        height: 100px;
        background: radial-gradient(circle, var(--primary-color) 0%, transparent 70%);
        opacity: 0.05;
        transform: translate(30%, -30%);
    }

    .stat-box:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
        border-color: rgba(255, 107, 53, 0.3);
    }

    .stat-label {
        color: var(--text-muted);
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin-bottom: 0.75rem;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 900;
        color: var(--text-main);
        letter-spacing: -0.01em;
    }

    /* Charts Layouts */
    .charts-grid-main,
    .sales-charts-grid,
    .customers-grid,
    .products-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 2rem;
        margin-bottom: 2.5rem;
    }

    .charts-grid-secondary {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 1.5rem;
    }

    @media (max-width: 1200px) {

        .charts-grid-main,
        .sales-charts-grid,
        .customers-grid,
        .products-grid,
        .charts-grid-secondary {
            grid-template-columns: 1fr;
        }
    }

    .chart-card,
    .chart-card-lg,
    .chart-card-sm {
        background: var(--glass);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        padding: 2.5rem;
        box-shadow: var(--shadow-lg);
        min-height: 450px;
        display: flex;
        flex-direction: column;
    }

    .chart-header {
        font-weight: 800;
        color: var(--text-main);
        margin-bottom: 2rem;
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .chart-body {
        flex: 1;
        position: relative;
    }

    /* Mock Components */
    .mock-map {
        background: rgba(0, 0, 0, 0.05);
        border-radius: 12px;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    [data-theme="dark"] .mock-map {
        background: rgba(255, 255, 255, 0.05);
    }

    /* Smooth Transitions */
    .analytics-view {
        animation: fadeIn 0.4s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<div class="analytics-view">
    <?php
    $allowed_views = ['overview', 'sales', 'customers', 'products'];
    if (in_array($view, $allowed_views)) {
        include 'includes/dashboard_' . $view . '.php';
    } else {
        include 'includes/dashboard_overview.php';
    }
    ?>
</div>

<!-- Recent Activity with Status Colors -->
<div class="dashboard-card" style="margin-top: 3rem;">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Recent Activity</h3>
        <a href="orders/" class="view-all-link">View All Orders</a>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Only show orders placed today
                $today = date('Y-m-d');
                $stmt = $conn->prepare("SELECT o.*, u.username FROM orders o JOIN customers u ON o.user_id = u.id WHERE DATE(o.created_at) = ? ORDER BY o.created_at DESC LIMIT 10");
                $stmt->execute([$today]);
                $recent_orders = $stmt->fetchAll();

                foreach ($recent_orders as $order):
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
                        <td><strong><?php echo htmlspecialchars($order['order_reference']); ?></strong></td>
                        <td><?php echo htmlspecialchars($order['username']); ?></td>
                        <td><?php echo format_currency($order['total']); ?></td>
                        <td>
                            <span class="badge <?php echo $status_class; ?>">
                                <?php echo $curr_status; ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $date = strtotime($order['created_at']);
                            if (date('Y-m-d', $date) == $today) {
                                echo "Today, " . date('h:i A', $date);
                            } else {
                                echo date('M d, Y', $date);
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recent_orders)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            <i class="fas fa-inbox" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                            No orders placed today (<?php echo date('M d, Y'); ?>)
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>