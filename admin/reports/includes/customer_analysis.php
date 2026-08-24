<?php
// Customer Analysis Report
$query = "SELECT 
            u.id,
            u.username,
            u.email,
            u.full_name,
            u.phone,
            COUNT(DISTINCT o.id) as total_orders,
            SUM(o.total) as total_spent,
            AVG(o.total) as avg_order_value,
            MIN(o.created_at) as first_order,
            MAX(o.created_at) as last_order,
            DATEDIFF(NOW(), MAX(o.created_at)) as days_since_last_order
          FROM customers u
          LEFT JOIN orders o ON u.id = o.user_id AND o.status = 'Completed'
          GROUP BY u.id
          HAVING total_orders > 0
          ORDER BY total_spent DESC";

$stmt = $conn->query($query);
$customers = $stmt->fetchAll();

// Customer segmentation
$segmentation_query = "SELECT 
                         CASE 
                           WHEN total_orders >= 10 THEN 'VIP'
                           WHEN total_orders >= 5 THEN 'Regular'
                           WHEN total_orders >= 2 THEN 'Occasional'
                           ELSE 'New'
                         END as segment,
                         COUNT(*) as customer_count,
                         SUM(total_spent) as segment_revenue,
                         AVG(total_spent) as avg_spent_per_customer
                       FROM (
                         SELECT 
                           u.id,
                           COUNT(DISTINCT o.id) as total_orders,
                           SUM(o.total) as total_spent
                         FROM customers u
                         LEFT JOIN orders o ON u.id = o.user_id AND o.status = 'Completed'
                         GROUP BY u.id
                       ) as customer_stats
                       GROUP BY segment
                       ORDER BY segment_revenue DESC";

$segmentation_stmt = $conn->query($segmentation_query);
$segments = $segmentation_stmt->fetchAll();

// New vs returning customers
$period_query = "SELECT 
                   DATE_FORMAT(o.created_at, '%Y-%m') as month,
                   COUNT(DISTINCT o.user_id) as total_customers,
                   COUNT(DISTINCT CASE WHEN first_order.month = DATE_FORMAT(o.created_at, '%Y-%m') THEN o.user_id END) as new_customers,
                   COUNT(DISTINCT CASE WHEN first_order.month < DATE_FORMAT(o.created_at, '%Y-%m') THEN o.user_id END) as returning_customers
                 FROM orders o
                 LEFT JOIN (
                   SELECT user_id, DATE_FORMAT(MIN(created_at), '%Y-%m') as month
                   FROM orders
                   GROUP BY user_id
                 ) as first_order ON o.user_id = first_order.user_id
                 WHERE o.status = 'Completed'
                 AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                 GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                 ORDER BY month DESC
                 LIMIT 6";

$period_stmt = $conn->query($period_query);
$customer_trends = $period_stmt->fetchAll();
?>

<h2><i class="fas fa-user-chart"></i> Customer Analysis Report</h2>
<p class="text-muted">Customer insights and behavior analysis</p>

<!-- Customer Segments -->
<div class="stats-cards">
    <?php
    $total_customers = count($customers);
    $total_revenue = array_sum(array_column($customers, 'total_spent'));
    $avg_order_value = $total_customers > 0 ? $total_revenue / $total_customers : 0;
    ?>

    <div class="stats-cards">
        <?php
        $total_customers = count($customers);
        $total_revenue = array_sum(array_column($customers, 'total_spent'));
        $avg_order_value = $total_customers > 0 ? $total_revenue / $total_customers : 0;
        ?>

        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #4361ee; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
                <?php echo number_format($total_customers); ?></div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Active
                Customers</div>
        </div>

        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #28a745; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
                ₵<?php echo number_format($total_revenue, 2); ?></div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Total
                Customer Revenue</div>
        </div>

        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #ffc107; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
                ₵<?php echo number_format($avg_order_value, 2); ?></div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Avg.
                Customer Value</div>
        </div>

        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #17a2b8; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
                <?php
                $repeat_customers = array_filter($customers, function ($c) {
                    return $c['total_orders'] > 1;
                });
                echo count($repeat_customers);
                ?>
            </div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Repeat
                Customers</div>
        </div>
    </div>
</div>

<!-- Customer Segmentation Chart -->
<div class="chart-container">
    <canvas id="segmentChart"></canvas>
</div>

<!-- Customer Segments Table -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-users"></i> Customer Segmentation</h3>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Segment</th>
                    <th>Customers</th>
                    <th>% of Total</th>
                    <th>Total Revenue</th>
                    <th>Avg. Spend/Customer</th>
                    <th>CLV Estimate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($segments as $segment):
                    $percentage = $total_customers > 0 ? ($segment['customer_count'] / $total_customers * 100) : 0;
                    $clv = $segment['avg_spent_per_customer'] * ($segment['segment'] == 'VIP' ? 12 : ($segment['segment'] == 'Regular' ? 6 : 3));
                    ?>
                    <tr>
                        <td>
                            <span class="badge"
                                style="background: 
                                <?php echo $segment['segment'] == 'VIP' ? '#dc3545' :
                                    ($segment['segment'] == 'Regular' ? '#28a745' :
                                        ($segment['segment'] == 'Occasional' ? '#ffc107' : '#17a2b8')); ?>; color: white;">
                                <?php echo $segment['segment']; ?>
                            </span>
                        </td>
                        <td><?php echo number_format($segment['customer_count']); ?></td>
                        <td><?php echo number_format($percentage, 1); ?>%</td>
                        <td>₵<?php echo number_format($segment['segment_revenue'], 2); ?></td>
                        <td>₵<?php echo number_format($segment['avg_spent_per_customer'], 2); ?></td>
                        <td>₵<?php echo number_format($clv, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Customer Trends -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-chart-line"></i> Customer Acquisition Trends</h3>
    <div class="chart-container" style="height: 300px;">
        <canvas id="trendChart"></canvas>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Total Customers</th>
                    <th>New Customers</th>
                    <th>Returning Customers</th>
                    <th>Retention</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customer_trends as $trend):
                    $retention_rate = $trend['total_customers'] > 0 ? ($trend['returning_customers'] / $trend['total_customers'] * 100) : 0;
                    ?>
                    <tr>
                        <td style="white-space: nowrap;"><?php echo date('M Y', strtotime($trend['month'] . '-01')); ?></td>
                        <td><?php echo number_format($trend['total_customers']); ?></td>
                        <td><?php echo number_format($trend['new_customers']); ?></td>
                        <td><?php echo number_format($trend['returning_customers']); ?></td>
                        <td>
                            <span class="<?php echo $retention_rate > 50 ? 'text-success' : 'text-warning'; ?>">
                                <?php echo number_format($retention_rate, 1); ?>%
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Top Customers -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-crown"></i> Top 20 Customers by Revenue</h3>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Orders</th>
                    <th>Spent</th>
                    <th>Avg. Order</th>
                    <th>Last Order</th>
                    <th>Segment</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($customers, 0, 20) as $index => $customer):
                    $rank = $index + 1;
                    $segment = $customer['total_orders'] >= 10 ? 'VIP' :
                        ($customer['total_orders'] >= 5 ? 'Regular' :
                            ($customer['total_orders'] >= 2 ? 'Occasional' : 'New'));
                    ?>
                    <tr>
                        <td>
                            <?php if ($rank <= 3): ?>
                                <span class="badge"
                                    style="background: <?php echo $rank == 1 ? '#ffd700' : ($rank == 2 ? '#c0c0c0' : '#cd7f32'); ?>; color: #000;">
                                    <?php echo $rank; ?>
                                </span>
                            <?php else: ?>
                                <?php echo $rank; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="min-width: 150px;">
                                <strong><?php echo htmlspecialchars($customer['full_name'] ?: $customer['username']); ?></strong>
                                <?php if ($customer['phone']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($customer['phone']); ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($customer['email']); ?></td>
                        <td><?php echo number_format($customer['total_orders']); ?></td>
                        <td><strong>₵<?php echo number_format($customer['total_spent'], 2); ?></strong></td>
                        <td>₵<?php echo number_format($customer['avg_order_value'], 2); ?></td>
                        <td style="white-space: nowrap;">
                            <?php echo $customer['last_order'] ? date('M d, Y', strtotime($customer['last_order'])) : 'N/A'; ?>
                        </td>
                        <td>
                            <span class="badge" style="background: 
                                <?php echo $segment == 'VIP' ? '#dc3545' :
                                    ($segment == 'Regular' ? '#28a745' :
                                        ($segment == 'Occasional' ? '#ffc107' : '#17a2b8')); ?>; color: white;">
                                <?php echo $segment; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Customer Lifetime Value Analysis -->
<div
    style="margin-top: 2rem; background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-sm);">
    <h3><i class="fas fa-chart-pie"></i> Customer Lifetime Value Analysis</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div>
            <h4>Average Customer Value</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">₵<?php echo number_format($avg_order_value, 2); ?></p>
        </div>
        <div>
            <h4>Repeat Purchase Rate</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">
                <?php echo $total_customers > 0 ? number_format((count($repeat_customers) / $total_customers * 100), 1) : 0; ?>%
            </p>
        </div>
        <div>
            <h4>Avg. Purchase Frequency</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">
                <?php echo $total_customers > 0 ? number_format(array_sum(array_column($customers, 'total_orders')) / $total_customers, 1) : 0; ?>
                orders
            </p>
        </div>
        <div>
            <h4>Customer Retention</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">
                <?php
                $active_customers = array_filter($customers, function ($c) {
                    return $c['days_since_last_order'] <= 30;
                });
                echo $total_customers > 0 ? number_format((count($active_customers) / $total_customers * 100), 1) : 0;
                ?>%
            </p>
        </div>
    </div>
</div>

<div class="export-buttons">
    <button onclick="exportReport('pdf')" class="btn btn-danger">
        <i class="fas fa-file-pdf"></i> Export PDF
    </button>
    <button onclick="exportReport('excel')" class="btn btn-success">
        <i class="fas fa-file-excel"></i> Export Excel
    </button>
</div>

<script>
    function initializeCharts() {
        // Segment Chart
        const segmentCtx = document.getElementById('segmentChart').getContext('2d');
        const segmentLabels = <?php echo json_encode(array_column($segments, 'segment')); ?>;
        const segmentRevenue = <?php echo json_encode(array_column($segments, 'segment_revenue')); ?>;

        new Chart(segmentCtx, {
            type: 'pie',
            data: {
                labels: segmentLabels,
                datasets: [{
                    data: segmentRevenue,
                    backgroundColor: ['#dc3545', '#28a745', '#ffc107', '#17a2b8']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Revenue by Customer Segment'
                    },
                    legend: {
                        position: 'right'
                    }
                }
            }
        });

        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const months = <?php echo json_encode(array_column($customer_trends, 'month')); ?>;
        const newCustomers = <?php echo json_encode(array_column($customer_trends, 'new_customers')); ?>;
        const returningCustomers = <?php echo json_encode(array_column($customer_trends, 'returning_customers')); ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: months.map(m => new Date(m + '-01').toLocaleDateString('en-US', { month: 'short', year: 'numeric' })),
                datasets: [
                    {
                        label: 'New Customers',
                        data: newCustomers,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        fill: true
                    },
                    {
                        label: 'Returning Customers',
                        data: returningCustomers,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Customer Acquisition Trends'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
</script>