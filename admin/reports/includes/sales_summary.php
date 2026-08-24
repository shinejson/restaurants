<?php
// Sales Summary Report
$query = "SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COUNT(oi.id) as total_items,
            SUM(o.total) as total_revenue,
            AVG(o.total) as avg_order_value,
            COUNT(DISTINCT o.user_id) as unique_customers
          FROM orders o
          LEFT JOIN order_items oi ON o.id = oi.order_id
          WHERE DATE(o.created_at) BETWEEN ? AND ?
          AND o.status = 'Delivered'";

$stmt = $conn->prepare($query);
$stmt->execute([$start_date, $end_date]);
$summary = $stmt->fetch();

// Daily sales trend
$trend_query = "SELECT 
                  DATE(created_at) as date,
                  COUNT(*) as order_count,
                  SUM(total) as daily_revenue
                FROM orders
                WHERE DATE(created_at) BETWEEN ? AND ?
                AND status = 'Completed'
                GROUP BY DATE(created_at)
                ORDER BY date";

$trend_stmt = $conn->prepare($trend_query);
$trend_stmt->execute([$start_date, $end_date]);
$daily_trends = $trend_stmt->fetchAll();

// Sales by status
$status_query = "SELECT 
                   status,
                   COUNT(*) as order_count,
                   SUM(total) as revenue
                 FROM orders
                 WHERE DATE(created_at) BETWEEN ? AND ?
                 GROUP BY status";

$status_stmt = $conn->prepare($status_query);
$status_stmt->execute([$start_date, $end_date]);
$status_data = $status_stmt->fetchAll();
?>

<h2><i class="fas fa-file-invoice-dollar"></i> Sales Summary Report</h2>
<p class="text-muted">Date Range: <?php echo date('M d, Y', strtotime($start_date)); ?> to
    <?php echo date('M d, Y', strtotime($end_date)); ?>
</p>

<!-- Summary Stats -->
<div class="stats-cards">
    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #4361ee; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            ₵<?php echo number_format($summary['total_revenue'] ?? 0, 2); ?></div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Total
            Revenue</div>
    </div>

    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #28a745; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            <?php echo number_format($summary['total_orders'] ?? 0); ?>
        </div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Total
            Orders</div>
    </div>

    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #ffc107; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            <?php echo number_format($summary['unique_customers'] ?? 0); ?>
        </div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Unique
            Customers</div>
    </div>

    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #17a2b8; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            ₵<?php echo number_format($summary['avg_order_value'] ?? 0, 2); ?></div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Average
            Order Value</div>
    </div>
</div>

<!-- Revenue Trend Chart -->
<div class="chart-container">
    <canvas id="revenueChart"></canvas>
</div>

<!-- Order Status Breakdown -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-chart-pie"></i> Order Status Breakdown</h3>
    <div class="chart-container" style="height: 300px;">
        <canvas id="statusChart"></canvas>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_orders = array_sum(array_column($status_data, 'order_count'));
                foreach ($status_data as $status):
                    $percentage = $total_orders > 0 ? ($status['order_count'] / $total_orders * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <span class="status-badge status-<?php echo strtolower($status['status']); ?>">
                                <?php echo $status['status']; ?>
                            </span>
                        </td>
                        <td><?php echo number_format($status['order_count']); ?></td>
                        <td>₵<?php echo number_format($status['revenue'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($percentage, 1); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Daily Sales Table -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-calendar-alt"></i> Daily Sales Trend</h3>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Avg. Order Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daily_trends as $day):
                    $avg_value = $day['order_count'] > 0 ? $day['daily_revenue'] / $day['order_count'] : 0;
                    ?>
                    <tr>
                        <td style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($day['date'])); ?></td>
                        <td><?php echo number_format($day['order_count']); ?></td>
                        <td>₵<?php echo number_format($day['daily_revenue'], 2); ?></td>
                        <td>₵<?php echo number_format($avg_value, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="export-buttons">
    <button onclick="exportReport('pdf')" class="btn btn-danger">
        <i class="fas fa-file-pdf"></i> Export PDF
    </button>
    <button onclick="exportReport('excel')" class="btn btn-success">
        <i class="fas fa-file-excel"></i> Export Excel
    </button>
    <button onclick="exportReport('csv')" class="btn btn-primary">
        <i class="fas fa-file-csv"></i> Export CSV
    </button>
</div>

<script>
    function initializeCharts() {
        // Revenue Trend Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const dates = <?php echo json_encode(array_column($daily_trends, 'date')); ?>;
        const revenues = <?php echo json_encode(array_column($daily_trends, 'daily_revenue')); ?>;

        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: dates.map(date => new Date(date).toLocaleDateString()),
                datasets: [{
                    label: 'Daily Revenue',
                    data: revenues,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Revenue Trend'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return '₵' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusLabels = <?php echo json_encode(array_column($status_data, 'status')); ?>;
        const statusCounts = <?php echo json_encode(array_column($status_data, 'order_count')); ?>;

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusCounts,
                    backgroundColor: [
                        '#ffc107', // Pending
                        '#17a2b8', // Preparing
                        '#28a745', // Completed
                        '#dc3545'  // Cancelled
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
    }
</script>