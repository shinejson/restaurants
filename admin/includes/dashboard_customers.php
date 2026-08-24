<?php
// 1. Filter Logic
$start_date = isset($_GET['start_date']) ? clean_input($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? clean_input($_GET['end_date']) : date('Y-m-d');
$category_filter = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

$params = [$start_date, $end_date];
$cat_where = "";
if ($category_filter > 0) {
    $cat_where = " AND sc.main_category_id = ?";
    $params[] = $category_filter;
}

// 2. Calculations
$stmt = $conn->query("SELECT COUNT(*) as total FROM customers");
$total_customers = $stmt->fetch()['total'] ?? 0;

$sql = "SELECT SUM(o.total) as total FROM orders o WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND
?";
if ($category_filter > 0) {
    $sql = "SELECT SUM(o.total) as total FROM orders o
WHERE o.id IN (
SELECT DISTINCT oi.order_id
FROM order_items oi
JOIN food_items fi ON oi.food_item_id = fi.id
JOIN sub_categories sc ON fi.sub_category_id = sc.id
WHERE sc.main_category_id = ?
) AND o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
    $params = [$category_filter, $start_date, $end_date];
} else {
    $params = [$start_date, $end_date];
}
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$total_revenue = $stmt->fetch()['total'] ?? 0;
$avg_sales_per_customer = $total_customers > 0 ? $total_revenue / $total_customers : 0;

// Top 5 Customers by Sales in period
$sql = "SELECT u.username, SUM(o.total) as TotalSales FROM orders o JOIN customers u ON o.user_id = u.id WHERE o.status
!= 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0) {
    $sql = "SELECT u.username, SUM(o.total) as TotalSales FROM orders o
JOIN customers u ON o.user_id = u.id
WHERE o.id IN (
SELECT DISTINCT oi.order_id
FROM order_items oi
JOIN food_items fi ON oi.food_item_id = fi.id
JOIN sub_categories sc ON fi.sub_category_id = sc.id
WHERE sc.main_category_id = ?
) AND o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?
GROUP BY u.username ORDER BY TotalSales DESC LIMIT 5";
    $params = [$category_filter, $start_date, $end_date];
} else {
    $params = [$start_date, $end_date];
    $sql .= " GROUP BY u.username ORDER BY TotalSales DESC LIMIT 5";
}
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$top_customers = $stmt->fetchAll();

// 3. Customer Segmentation (Activity in period)
// Fix inflation by using DISTINCT
$sql = "SELECT 
            CASE 
                WHEN order_count > 5 THEN 'Corporate/Loyal'
                WHEN order_count > 2 THEN 'Regular'
                ELSE 'New/Individual'
            END as Segment,
            COUNT(*) as CustomerCount,
            SUM(total_spent) as SegmentRevenue
        FROM (
            SELECT o.user_id, COUNT(DISTINCT o.id) as order_count, SUM(o.total) as total_spent
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN food_items fi ON oi.food_item_id = fi.id
            LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
            WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= " AND sc.main_category_id = " . (int) $category_filter;
$sql .= " GROUP BY o.user_id
        ) as customer_orders
        GROUP BY Segment";
$stmt = $conn->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$segments = $stmt->fetchAll();

// Get Categories for Dropdown
$categories = $conn->query("SELECT id, name FROM main_categories ORDER BY name")->fetchAll();
?>

<div class="analytics-customers">
    <div class="view-header">
        <h2>Customers Analysis</h2>
        <div class="header-actions">
            <form method="GET" class="filter-controls"
                style="display: flex; gap: 1rem; align-items: center; background: var(--white); padding: 0.75rem 1.5rem; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
                <input type="hidden" name="view" value="customers">

                <div class="filter-group" style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <label
                        style="font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Range</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-control"
                            style="padding: 0.4rem; border-radius: 8px; font-size: 0.85rem;">
                        <span>to</span>
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

    <div class="customers-stats-row">
        <div class="stat-card">
            <div class="stat-value">
                <?php echo number_format($total_customers); ?>
            </div>
            <div class="stat-label">Total Customers</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?php echo format_currency($avg_sales_per_customer); ?>
            </div>
            <div class="stat-label">Avg. Sales per Customer</div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="customers-grid">
        <div class="chart-card">
            <div class="chart-header">Top 5 Customers by Sales</div>
            <div class="chart-body">
                <canvas id="topCustomersChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Total Sales by Segment</div>
            <div class="chart-body">
                <canvas id="salesBySegmentChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Customer Retention Trend (Mock)</div>
            <div class="chart-body">
                <canvas id="retentionChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Top Customers Bar
        const topCustomersCtx = document.getElementById('topCustomersChart').getContext('2d');
        const customerData = <?php echo json_encode($top_customers); ?>;

        new Chart(topCustomersCtx, {
            type: 'bar',
            data: {
                labels: customerData.map(d => d.username),
                datasets: [{
                    label: 'Total Sales',
                    data: customerData.map(d => d.TotalSales),
                    backgroundColor: '#82ca9d',
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#888' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    y: { ticks: { color: '#888' }, grid: { display: false } }
                }
            }
        });

        // 2. Sales by Segment Donut
        const segmentCtx = document.getElementById('salesBySegmentChart').getContext('2d');
        const segmentData = <?php echo json_encode($segments); ?>;

        new Chart(segmentCtx, {
            type: 'doughnut',
            data: {
                labels: segmentData.map(d => d.Segment),
                datasets: [{
                    data: segmentData.map(d => d.SegmentRevenue),
                    backgroundColor: ['#0088fe', '#00c49f', '#ffbb28'],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '70%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: '#888' } } }
            }
        });

        // 3. Retention Chart (Area)
        const retentionCtx = document.getElementById('retentionChart').getContext('2d');
        new Chart(retentionCtx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'],
                datasets: [{
                    label: 'Returning Customers',
                    data: [65, 59, 80, 81, 56, 55],
                    borderColor: '#82ca9d',
                    backgroundColor: 'rgba(130, 202, 157, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { ticks: { color: '#888' } },
                    x: { ticks: { color: '#888' } }
                }
            }
        });
    });
</script>