<?php
// Order Analytics Report
$query = "SELECT 
            DATE(created_at) as order_date,
            HOUR(created_at) as order_hour,
            DAYNAME(created_at) as day_name,
            COUNT(*) as order_count,
            SUM(total) as total_revenue,
            AVG(total) as avg_order_value,
            MIN(created_at) as first_order_time,
            MAX(created_at) as last_order_time
          FROM orders
          WHERE DATE(created_at) BETWEEN ? AND ?
          AND status = 'Completed'
          GROUP BY DATE(created_at)
          ORDER BY order_date DESC";

$stmt = $conn->prepare($query);
$stmt->execute([$start_date, $end_date]);
$daily_orders = $stmt->fetchAll();

// Order status distribution
$status_query = "SELECT 
                   status,
                   COUNT(*) as order_count,
                   SUM(total) as revenue,
                   AVG(total) as avg_value,
                   MIN(created_at) as earliest,
                   MAX(created_at) as latest
                 FROM orders
                 WHERE DATE(created_at) BETWEEN ? AND ?
                 GROUP BY status
                 ORDER BY order_count DESC";

$status_stmt = $conn->prepare($status_query);
$status_stmt->execute([$start_date, $end_date]);
$status_distribution = $status_stmt->fetchAll();

// Peak hours analysis
$hour_query = "SELECT 
                 HOUR(created_at) as hour,
                 COUNT(*) as order_count,
                 SUM(total) as revenue,
                 AVG(total) as avg_order_value
               FROM orders
               WHERE DATE(created_at) BETWEEN ? AND ?
               AND status = 'Completed'
               GROUP BY HOUR(created_at)
               ORDER BY hour";

$hour_stmt = $conn->prepare($hour_query);
$hour_stmt->execute([$start_date, $end_date]);
$hour_analysis = $hour_stmt->fetchAll();

// Day of week analysis
$day_query = "SELECT 
                DAYNAME(created_at) as day_name,
                DAYOFWEEK(created_at) as day_num,
                COUNT(*) as order_count,
                SUM(total) as revenue,
                AVG(total) as avg_order_value
              FROM orders
              WHERE DATE(created_at) BETWEEN ? AND ?
              AND status = 'Completed'
              GROUP BY DAYNAME(created_at), DAYOFWEEK(created_at)
              ORDER BY day_num";

$day_stmt = $conn->prepare($day_query);
$day_stmt->execute([$start_date, $end_date]);
$day_analysis = $day_stmt->fetchAll();
?>

<h2><i class="fas fa-shopping-cart"></i> Order Analytics Report</h2>
<p class="text-muted">Date Range: <?php echo date('M d, Y', strtotime($start_date)); ?> to
    <?php echo date('M d, Y', strtotime($end_date)); ?>
</p>

<!-- Order Summary -->
<?php
$total_orders = array_sum(array_column($daily_orders, 'order_count'));
$total_revenue = array_sum(array_column($daily_orders, 'total_revenue'));
$avg_daily_orders = count($daily_orders) > 0 ? $total_orders / count($daily_orders) : 0;
$avg_daily_revenue = count($daily_orders) > 0 ? $total_revenue / count($daily_orders) : 0;
?>
<div class="stats-cards">
    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #4361ee; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            <?php echo number_format($total_orders); ?></div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Total
            Orders</div>
    </div>

    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #28a745; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            ₵<?php echo number_format($total_revenue, 2); ?></div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Total
            Revenue</div>
    </div>

    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #ffc107; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            <?php echo number_format($avg_daily_orders, 1); ?></div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Avg. Daily
            Orders</div>
    </div>

    <div class="stat-card"
        style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #17a2b8; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div class="stat-value" style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);">
            ₵<?php echo number_format($avg_daily_revenue, 2); ?></div>
        <div class="stat-label"
            style="color: var(--text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Avg. Daily
            Revenue</div>
    </div>
</div>

<!-- Order Trend Chart -->
<div class="chart-container">
    <canvas id="orderTrendChart"></canvas>
</div>

<!-- Order Status Distribution -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-chart-pie"></i> Order Status Distribution</h3>
    <div class="chart-container" style="height: 300px;">
        <canvas id="statusChart"></canvas>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Orders</th>
                    <th>% of Total</th>
                    <th>Revenue</th>
                    <th>Avg. Order Value</th>
                    <th>Time Range</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($status_distribution as $status):
                    $percentage = $total_orders > 0 ? ($status['order_count'] / $total_orders * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <span class="status-badge status-<?php echo strtolower($status['status']); ?>">
                                <?php echo $status['status']; ?>
                            </span>
                        </td>
                        <td><?php echo number_format($status['order_count']); ?></td>
                        <td><?php echo number_format($percentage, 1); ?>%</td>
                        <td>₵<?php echo number_format($status['revenue'], 2); ?></td>
                        <td>₵<?php echo number_format($status['avg_value'], 2); ?></td>
                        <td style="white-space: nowrap;">
                            <?php if ($status['earliest']): ?>
                                <?php echo date('M d', strtotime($status['earliest'])); ?> -
                                <?php echo date('M d', strtotime($status['latest'])); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Peak Hours Analysis -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-clock"></i> Peak Order Hours</h3>
    <div class="chart-container" style="height: 300px;">
        <canvas id="hourChart"></canvas>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Hour</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Avg. Order Value</th>
                    <th>Performance</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $max_orders = !empty($hour_analysis) ? max(array_column($hour_analysis, 'order_count')) : 0;
                foreach ($hour_analysis as $hour):
                    $performance = $hour['order_count'] / max(1, $max_orders) * 100;
                    $performance_class = $performance > 80 ? 'success' : ($performance > 50 ? 'warning' : 'danger');
                    ?>
                    <tr>
                        <td style="white-space: nowrap;">
                            <strong><?php echo sprintf('%02d:00', $hour['hour']); ?> -
                                <?php echo sprintf('%02d:59', $hour['hour']); ?></strong>
                        </td>
                        <td><?php echo number_format($hour['order_count']); ?></td>
                        <td>₵<?php echo number_format($hour['revenue'], 2); ?></td>
                        <td>₵<?php echo number_format($hour['avg_order_value'], 2); ?></td>
                        <td>
                            <div class="progress"
                                style="height: 12px; width: 100px; background: rgba(0,0,0,0.1); border-radius: 6px; overflow: hidden;">
                                <div class="progress-bar bg-<?php echo $performance_class; ?>"
                                    style="height: 100%; width: <?php echo $performance; ?>%; border-radius: 6px;">
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Day of Week Analysis -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-calendar-alt"></i> Day of Week Performance</h3>
    <div class="chart-container" style="height: 300px;">
        <canvas id="dayChart"></canvas>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Avg. Order Value</th>
                    <th>% of Weekly Total</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $weekly_orders = array_sum(array_column($day_analysis, 'order_count'));
                $weekly_revenue = array_sum(array_column($day_analysis, 'revenue'));
                foreach ($day_analysis as $day):
                    $order_percentage = $weekly_orders > 0 ? ($day['order_count'] / $weekly_orders * 100) : 0;
                    $revenue_percentage = $weekly_revenue > 0 ? ($day['revenue'] / $weekly_revenue * 100) : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo $day['day_name']; ?></strong></td>
                        <td><?php echo number_format($day['order_count']); ?></td>
                        <td>₵<?php echo number_format($day['revenue'], 2); ?></td>
                        <td>₵<?php echo number_format($day['avg_order_value'], 2); ?></td>
                        <td style="white-space: nowrap;">
                            <span class="badge"
                                style="background: rgba(67, 97, 238, 0.1); color: #4361ee; border: 1px solid rgba(67, 97, 238, 0.2);">
                                Orders: <?php echo number_format($order_percentage, 1); ?>%
                            </span>
                            <span class="badge"
                                style="background: rgba(40, 167, 69, 0.1); color: #28a745; border: 1px solid rgba(40, 167, 69, 0.2);">
                                Revenue: <?php echo number_format($revenue_percentage, 1); ?>%
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Order Fulfillment Metrics -->
<div
    style="margin-top: 2rem; background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-sm);">
    <h3><i class="fas fa-tachometer-alt"></i> Order Fulfillment Metrics</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div>
            <h4>Completion Rate</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">
                <?php
                $completed_orders = 0;
                foreach ($status_distribution as $status) {
                    if ($status['status'] == 'Completed') {
                        $completed_orders = $status['order_count'];
                        break;
                    }
                }
                echo $total_orders > 0 ? number_format(($completed_orders / $total_orders * 100), 1) : 0;
                ?>%
            </p>
        </div>
        <div>
            <h4>Cancellation Rate</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">
                <?php
                $cancelled_orders = 0;
                foreach ($status_distribution as $status) {
                    if ($status['status'] == 'Cancelled') {
                        $cancelled_orders = $status['order_count'];
                        break;
                    }
                }
                echo $total_orders > 0 ? number_format(($cancelled_orders / $total_orders * 100), 1) : 0;
                ?>%
            </p>
        </div>
        <div>
            <h4>Avg. Order Processing Time</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">--:--</p>
            <small class="text-muted">(Time tracking required)</small>
        </div>
        <div>
            <h4>Peak Hour Efficiency</h4>
            <p style="font-size: 1.5rem; font-weight: bold;">
                <?php
                $peak_hour = !empty($hour_analysis) ? $hour_analysis[array_search(max(array_column($hour_analysis, 'order_count')), array_column($hour_analysis, 'order_count'))] : null;
                echo $peak_hour ? $peak_hour['hour'] . ':00' : 'N/A';
                ?>
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
        // Order Trend Chart
        const trendCtx = document.getElementById('orderTrendChart').getContext('2d');
        const dates = <?php echo json_encode(array_column($daily_orders, 'order_date')); ?>;
        const orderCounts = <?php echo json_encode(array_column($daily_orders, 'order_count')); ?>;
        const revenues = <?php echo json_encode(array_column($daily_orders, 'total_revenue')); ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: dates.map(date => new Date(date).toLocaleDateString()),
                datasets: [
                    {
                        label: 'Order Count',
                        data: orderCounts,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Revenue',
                        data: revenues,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Daily Order Trends'
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Order Count'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Revenue'
                        },
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
        const statusLabels = <?php echo json_encode(array_column($status_distribution, 'status')); ?>;
        const statusData = <?php echo json_encode(array_column($status_distribution, 'order_count')); ?>;

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: ['#ffc107', '#17a2b8', '#28a745', '#dc3545']
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

        // Hour Chart
        const hourCtx = document.getElementById('hourChart').getContext('2d');
        const hours = <?php echo json_encode(array_column($hour_analysis, 'hour')); ?>;
        const hourOrders = <?php echo json_encode(array_column($hour_analysis, 'order_count')); ?>;

        new Chart(hourCtx, {
            type: 'bar',
            data: {
                labels: hours.map(h => h + ':00'),
                datasets: [{
                    label: 'Orders per Hour',
                    data: hourOrders,
                    backgroundColor: '#4361ee',
                    borderColor: '#3a0ca3',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Order Distribution by Hour'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Day Chart
        const dayCtx = document.getElementById('dayChart').getContext('2d');
        const days = <?php echo json_encode(array_column($day_analysis, 'day_name')); ?>;
        const dayOrders = <?php echo json_encode(array_column($day_analysis, 'order_count')); ?>;

        new Chart(dayCtx, {
            type: 'bar',
            data: {
                labels: days,
                datasets: [{
                    label: 'Orders',
                    data: dayOrders,
                    backgroundColor: '#28a745',
                    borderColor: '#1e7e34',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Order Distribution by Day of Week'
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