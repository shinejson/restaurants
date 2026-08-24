<?php
// Cost Analysis Report
// Logic from user snippet

$params = [$start_date, $end_date];
$cat_where = "";
if ($category_filter > 0) {
    $cat_where = " AND sc.main_category_id = ?";
    $params[] = $category_filter;
}

// 1. Total Profit
$sql = "SELECT SUM((oi.price - IFNULL(fi.cost, 0)) * oi.quantity) as total 
        FROM order_items oi 
        JOIN orders o ON oi.order_id = o.id 
        JOIN food_items fi ON oi.food_item_id = fi.id 
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id 
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0) $sql .= $cat_where;

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$total_profit = $stmt->fetch()['total'] ?? 0;

// 2. Avg Profit per Order
$sql = "SELECT COUNT(DISTINCT o.id) as total 
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id 
        LEFT JOIN food_items fi ON oi.food_item_id = fi.id 
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id 
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0) $sql .= $cat_where;

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$total_completed_orders = $stmt->fetch()['total'] ?? 0;
$avg_profit_per_order = $total_completed_orders > 0 ? $total_profit / $total_completed_orders : 0;

// 3. Top 5 Products by Revenue
$sql = "SELECT fi.item_name, SUM(oi.price * oi.quantity) as Revenue 
        FROM order_items oi 
        JOIN food_items fi ON oi.food_item_id = fi.id 
        JOIN orders o ON oi.order_id = o.id 
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id 
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0) $sql .= $cat_where;
$sql .= " GROUP BY fi.item_name ORDER BY Revenue DESC LIMIT 5";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$top_products = $stmt->fetchAll();

// 4. Sales by Category
$sql = "SELECT mc.name as Category, SUM(oi.price * oi.quantity) as Revenue 
        FROM order_items oi 
        JOIN food_items fi ON oi.food_item_id = fi.id 
        JOIN sub_categories sc ON fi.sub_category_id = sc.id 
        JOIN main_categories mc ON sc.main_category_id = mc.id 
        JOIN orders o ON oi.order_id = o.id 
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0) $sql .= $cat_where;
$sql .= " GROUP BY Category";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$sales_by_category = $stmt->fetchAll();

// Daily Profit Trend (Extra for visualization)
$sql = "SELECT DATE(o.created_at) as date, SUM((oi.price - IFNULL(fi.cost, 0)) * oi.quantity) as daily_profit
        FROM order_items oi 
        JOIN orders o ON oi.order_id = o.id 
        JOIN food_items fi ON oi.food_item_id = fi.id 
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id 
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0) $sql .= $cat_where;
$sql .= " GROUP BY DATE(o.created_at) ORDER BY date";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$daily_profit_trends = $stmt->fetchAll();
?>

<h2><i class="fas fa-money-bill-wave"></i> Cost & Profit Analysis</h2>
<p class="text-muted">Analyze your margins and product performance.</p>

<div class="stats-cards">
    <div class="stat-card" style="border-top: 4px solid #2ecc71;">
        <div class="stat-value">₵<?php echo number_format($total_profit, 2); ?></div>
        <div class="stat-label">Total Estimated Profit</div>
    </div>
    <div class="stat-card" style="border-top: 4px solid #3498db;">
        <div class="stat-value">₵<?php echo number_format($avg_profit_per_order, 2); ?></div>
        <div class="stat-label">Avg Profit Per Order</div>
    </div>
    <div class="stat-card" style="border-top: 4px solid #f1c40f;">
        <div class="stat-value"><?php echo number_format($total_completed_orders); ?></div>
        <div class="stat-label">Completed Orders</div>
    </div>
</div>

<div class="charts-grid-main" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">
    <div class="chart-card">
        <div class="chart-header">
            <span>Profit Trend</span>
        </div>
        <div class="chart-body" style="height: 300px;">
            <canvas id="profitTrendChart"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="chart-header">
            <span>Sales by Category</span>
        </div>
        <div class="chart-body" style="height: 300px;">
            <canvas id="categorySalesChart"></canvas>
        </div>
    </div>
</div>

<div style="margin-top: 3rem; display: grid; grid-template-columns: 1.5fr 1fr; gap: 2rem;">
    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-trophy"></i> Top 5 Products by Revenue</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th style="text-align: right;">Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_products as $product): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['item_name']); ?></td>
                        <td style="text-align: right; font-weight: 700;">₵<?php echo number_format($product['Revenue'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($top_products)): ?>
                    <tr><td colspan="2" class="text-center">No data available for this range</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-tags"></i> Category Revenue</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th style="text-align: right;">Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales_by_category as $cat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cat['Category']); ?></td>
                        <td style="text-align: right; font-weight: 700;">₵<?php echo number_format($cat['Revenue'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($sales_by_category)): ?>
                    <tr><td colspan="2" class="text-center">No data available</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function initializeCharts() {
    // Profit Trend Chart
    const profitCtx = document.getElementById('profitTrendChart').getContext('2d');
    const profitDates = <?php echo json_encode(array_column($daily_profit_trends, 'date')); ?>;
    const profitValues = <?php echo json_encode(array_column($daily_profit_trends, 'daily_profit')); ?>;

    new Chart(profitCtx, {
        type: 'line',
        data: {
            labels: profitDates.map(d => new Date(d).toLocaleDateString(undefined, {month:'short', day:'numeric'})),
            datasets: [{
                label: 'Daily Profit',
                data: profitValues,
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Category Sales Chart
    const catCtx = document.getElementById('categorySalesChart').getContext('2d');
    const catLabels = <?php echo json_encode(array_column($sales_by_category, 'Category')); ?>;
    const catValues = <?php echo json_encode(array_column($sales_by_category, 'Revenue')); ?>;

    new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: catLabels,
            datasets: [{
                data: catValues,
                backgroundColor: ['#4361ee', '#3f37c9', '#480ca8', '#4cc9f0', '#4895ef']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}
</script>
