<?php
// admin/includes/dashboard_overview.php

// 1. Filter Logic
$start_date = isset($_GET['start_date']) ? clean_input($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? clean_input($_GET['end_date']) : date('Y-m-d');
$category_filter = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

// Helper for SQL parameters
$params = [$start_date, $end_date];
$cat_join = "";
$cat_where = "";
if ($category_filter > 0) {
    $cat_where = " AND sc.main_category_id = ?";
    $params[] = $category_filter;
}

// 2. Summary Stats with Filters
$stats = [];

// Total Revenue (Grand Total Paid by Customers)
// Use a subquery to avoid inflation from JOINs when items/categories are involved
$sql = "SELECT SUM(o.total) as grand_total, SUM(o.delivery_charge) as delivery_revenue 
        FROM orders o 
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";

if ($category_filter > 0) {
    $sql = "SELECT SUM(DISTINCT o.total) as grand_total, SUM(DISTINCT o.delivery_charge) as delivery_revenue 
            FROM orders o 
            JOIN order_items oi ON o.id = oi.order_id
            JOIN food_items fi ON oi.food_item_id = fi.id
            JOIN sub_categories sc ON fi.sub_category_id = sc.id
            WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ? 
            AND sc.main_category_id = ?";
}

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch();
$stats['total_sales'] = $row['grand_total'] ?? 0;
$stats['delivery_revenue'] = $row['delivery_revenue'] ?? 0;
$stats['item_revenue'] = $stats['total_sales'] - $stats['delivery_revenue'];

// Total Quantity
$sql = "SELECT SUM(quantity) as total 
        FROM order_items oi 
        JOIN orders o ON oi.order_id = o.id 
        LEFT JOIN food_items fi ON oi.food_item_id = fi.id
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$stats['total_quantity'] = $stmt->fetch()['total'] ?? 0;

// Total Profit (Item Profit)
$sql = "SELECT SUM((oi.price - IFNULL(fi.cost, 0)) * oi.quantity) as total 
        FROM order_items oi 
        JOIN orders o ON oi.order_id = o.id 
        JOIN food_items fi ON oi.food_item_id = fi.id 
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$stats['total_profit'] = $stmt->fetch()['total'] ?? 0;

// Total Orders
$sql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN food_items fi ON oi.food_item_id = fi.id
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$stats['total_orders'] = $stmt->fetch()['total'] ?? 0;

// Avg Order Value
$stats['avg_order_value'] = $stats['total_orders'] > 0 ? $stats['total_sales'] / $stats['total_orders'] : 0;

// 3. Data for Charts
$sales_trend = [];
$qty_by_category = [];
$orders_by_status = [];

// Trend based on range
$sql = "SELECT DATE(o.created_at) as Date, SUM(o.total) as Sales 
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN food_items fi ON oi.food_item_id = fi.id
        JOIN sub_categories sc ON fi.sub_category_id = sc.id
        WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$sql .= " GROUP BY Date ORDER BY Date";
$trend_stmt = $conn->prepare($sql);
$trend_stmt->execute($params);
$sales_trend = $trend_stmt->fetchAll();

// Total Quantity by Category
$stmt = $conn->prepare("SELECT mc.name as Category, SUM(oi.quantity) as Quantity 
                     FROM order_items oi 
                     JOIN food_items fi ON oi.food_item_id = fi.id 
                     JOIN sub_categories sc ON fi.sub_category_id = sc.id 
                     JOIN main_categories mc ON sc.main_category_id = mc.id 
                     JOIN orders o ON oi.order_id = o.id 
                     WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?
                     GROUP BY Category 
                     ORDER BY Quantity DESC");
$stmt->execute([$start_date, $end_date]);
$qty_by_category = $stmt->fetchAll();

// Orders by Status
// Note: Changed 'Completed' to 'Delivered' in mental model, but status distribution just groups by status
$sql = "SELECT status, COUNT(DISTINCT o.id) as count FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN food_items fi ON oi.food_item_id = fi.id
        LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
        WHERE DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$sql .= " GROUP BY status";
$status_stmt = $conn->prepare($sql);
$status_stmt->execute($params);
$orders_by_status = $status_stmt->fetchAll();

// Get Categories for Dropdown
$categories = $conn->query("SELECT id, name FROM main_categories ORDER BY name")->fetchAll();
?>

<div class="analytics-overview">
    <div class="view-header">
        <h2>Overview Dashboard</h2>
        <div class="header-actions">
            <form method="GET" class="filter-controls"
                style="display: flex; gap: 1rem; align-items: center; background: var(--white); padding: 0.75rem 1.5rem; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
                <input type="hidden" name="view" value="overview">

                <div class="filter-group" style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <label
                        style="font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Range</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-control"
                            style="padding: 0.4rem; border-radius: 8px; font-size: 0.85rem;">
                        <span style="color: var(--text-muted);">to</span>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="form-control"
                            style="padding: 0.4rem; border-radius: 8px; font-size: 0.85rem;">
                    </div>
                </div>

                <div class="filter-separator" style="width: 1px; height: 30px; background: var(--border-color);"></div>

                <div class="filter-group" style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <label
                        style="font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Category</label>
                    <select name="category_id" class="form-control"
                        style="padding: 0.4rem; border-radius: 8px; font-size: 0.85rem; min-width: 150px;">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn-filter"
                    style="margin-top: 1.2rem; background: var(--primary-color); color: white; border: none; padding: 0.6rem 1.5rem;"><i
                        class="fas fa-sync-alt"></i> Apply</button>
            </form>
        </div>
    </div>

    <!-- Top Stats Row -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-label">Grand Total</div>
            <div class="stat-value">
                <?php echo format_currency($stats['total_sales']); ?>
            </div>
            <small style="color: var(--text-muted);">Incl. Delivery</small>
        </div>
        <div class="stat-box" style="border-left: 4px solid #3498db;">
            <div class="stat-label">Item Revenue</div>
            <div class="stat-value" style="color: #3498db;">
                <?php echo format_currency($stats['item_revenue']); ?>
            </div>
            <small style="color: var(--text-muted);">Food/Drinks Only</small>
        </div>
        <div class="stat-box" style="border-left: 4px solid #2ecc71;">
            <div class="stat-label">Delivery Revenue</div>
            <div class="stat-value" style="color: #2ecc71;">
                <?php echo format_currency($stats['delivery_revenue']); ?>
            </div>
            <small style="color: var(--text-muted);">Shipping Fees</small>
        </div>
        <div class="stat-box">
            <div class="stat-label">Total Profit</div>
            <div class="stat-value">
                <?php echo format_currency($stats['total_profit']); ?>
            </div>
            <small style="color: var(--text-muted);">On Items Only</small>
        </div>
        <div class="stat-box">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value">
                <?php echo number_format($stats['total_orders']); ?>
            </div>
            <small style="color: var(--text-muted);">Excl. Cancelled</small>
        </div>
    </div>

    <!-- Main Charts Row -->
    <div class="charts-grid-main">
        <div class="chart-card-lg">
            <div class="chart-header">Total Sales by Month</div>
            <div class="chart-body">
                <canvas id="salesTrendsChart"></canvas>
            </div>
        </div>
        <div class="chart-card-sm">
            <div class="chart-header">Total Quantity by Category</div>
            <div class="chart-body">
                <canvas id="quantityByCategoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Secondary Charts Row -->
    <div class="charts-grid-secondary">
        <div class="chart-card">
            <div class="chart-header">Order Status Distribution</div>
            <div class="chart-body">
                <canvas id="orderStatusChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Top Sales by Region (Mock Data)</div>
            <div class="chart-body">
                <!-- Placeholder for Map/Table -->
                <div class="mock-map">
                    <img src="<?php echo BASE_URL; ?>/assets/images/dashboard_map.png"
                        style="max-width: 100%; border-radius: 8px;" alt="Map Analytics">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Sales Trends Chart (Line/Area)
        const salesTrendsCtx = document.getElementById('salesTrendsChart').getContext('2d');
        const salesData = <?php echo json_encode($sales_trend); ?>;

        new Chart(salesTrendsCtx, {
            type: 'line',
            data: {
                labels: salesData.map(d => d.Date),
                datasets: [{
                    label: 'Sales',
                    data: salesData.map(d => d.Sales),
                    borderColor: '#8884d8',
                    backgroundColor: 'rgba(136, 132, 216, 0.2)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#888' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#888' }
                    }
                }
            }
        });

        // 2. Quantity by Category Chart (Horizontal Bar)
        const qtyByCategoryCtx = document.getElementById('quantityByCategoryChart').getContext('2d');
        const catData = <?php echo json_encode($qty_by_category); ?>;

        new Chart(qtyByCategoryCtx, {
            type: 'bar',
            data: {
                labels: catData.map(d => d.Category),
                datasets: [{
                    label: 'Quantity',
                    data: catData.map(d => d.Quantity),
                    backgroundColor: '#8884d8',
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#888' }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#888' }
                    }
                }
            }
        });

        // 3. Order Status Chart (Donut)
        const statusCtx = document.getElementById('orderStatusChart').getContext('2d');
        const statusData = <?php echo json_encode($orders_by_status); ?>;

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusData.map(d => d.status),
                datasets: [{
                    data: statusData.map(d => d.count),
                    backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                cutout: '70%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#888', padding: 20 } }
                }
            }
        });
    });
</script>