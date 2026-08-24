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
$total_profit = 0;
$avg_profit_per_order = 0;

// Total Profit
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
$total_profit = $stmt->fetch()['total'] ?? 0;

// Avg Profit per Order
$sql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o
LEFT JOIN order_items oi ON o.id = oi.order_id
LEFT JOIN food_items fi ON oi.food_item_id = fi.id
LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$total_completed_orders = $stmt->fetch()['total'] ?? 0;
$avg_profit_per_order = $total_completed_orders > 0 ? $total_profit / $total_completed_orders : 0;

// Top 5 Products by Revenue
$sql = "SELECT fi.item_name, SUM(oi.price * oi.quantity) as Revenue
FROM order_items oi
JOIN food_items fi ON oi.food_item_id = fi.id
JOIN orders o ON oi.order_id = o.id
LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$sql .= " GROUP BY fi.item_name ORDER BY Revenue DESC LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$top_products = $stmt->fetchAll();

// Sales by Category
$sql = "SELECT mc.name as Category, SUM(oi.price * oi.quantity) as Revenue
FROM order_items oi
JOIN food_items fi ON oi.food_item_id = fi.id
JOIN sub_categories sc ON fi.sub_category_id = sc.id
JOIN main_categories mc ON sc.main_category_id = mc.id
JOIN orders o ON oi.order_id = o.id
WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$sql .= " GROUP BY Category";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$sales_by_category = $stmt->fetchAll();

// Get Categories for Dropdown
$categories = $conn->query("SELECT id, name FROM main_categories ORDER BY name")->fetchAll();
?>

<div class="analytics-sales">
    <div class="view-header">
        <h2>Sales Analysis</h2>
        <div class="header-actions">
            <form method="GET" class="filter-controls"
                style="display: flex; gap: 1rem; align-items: center; background: var(--white); padding: 0.75rem 1.5rem; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
                <input type="hidden" name="view" value="sales">

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

    <div class="sales-stats-row">
        <div class="stat-card-vertical">
            <div class="stat-value">
                <?php echo format_currency($avg_profit_per_order); ?>
            </div>
            <div class="stat-label">Avg. Profit / Order</div>
        </div>
        <div class="stat-card-vertical">
            <div class="stat-value">
                <?php echo format_currency($total_profit); ?>
            </div>
            <div class="stat-label">Total Profit</div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="sales-charts-grid">
        <div class="chart-card">
            <div class="chart-header">Top 5 Products</div>
            <div class="chart-body">
                <canvas id="topProductsChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Price vs Sales Volume (Mock Correlation)</div>
            <div class="chart-body">
                <canvas id="scatterChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Total Sales by Category</div>
            <div class="chart-body">
                <canvas id="salesByCategoryChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Monthly Sales Trend</div>
            <div class="chart-body">
                <canvas id="monthlyTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Top Products Bar
        const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
        const productData = <?php echo json_encode($top_products); ?>;

        new Chart(topProductsCtx, {
            type: 'bar',
            data: {
                labels: productData.map(d => d.item_name),
                datasets: [{
                    label: 'Revenue',
                    data: productData.map(d => d.Revenue),
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
                    x: { ticks: { color: '#888' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    y: { ticks: { color: '#888' }, grid: { display: false } }
                }
            }
        });

        // 2. Scatter Chart (Price vs Sales)
        const scatterCtx = document.getElementById('scatterChart').getContext('2d');
        // Generating some synthetic correlation for aesthetic
        const scatterData = [];
        for (let i = 0; i < 30; i++) {
            scatterData.push({ x: Math.random() * 100, y: Math.random() * 1000 });
        }

        new Chart(scatterCtx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Products',
                    data: scatterData,
                    backgroundColor: '#0088fe'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { title: { display: true, text: 'Price', color: '#888' }, ticks: { color: '#888' } },
                    y: { title: { display: true, text: 'Sales', color: '#888' }, ticks: { color: '#888' } }
                }
            }
        });

        // 3. Sales By Category Donut
        const salesCatCtx = document.getElementById('salesByCategoryChart').getContext('2d');
        const catData = <?php echo json_encode($sales_by_category); ?>;

        new Chart(salesCatCtx, {
            type: 'doughnut',
            data: {
                labels: catData.map(d => d.Category),
                datasets: [{
                    data: catData.map(d => d.Revenue),
                    backgroundColor: ['#0088fe', '#00c49f', '#ffbb28', '#ff8042'],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '60%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: '#888' } } }
            }
        });

        // 4. Trend Chart (Area)
        const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        const trendData = <?php
        $stmt = $conn->prepare("SELECT DATE(created_at) as Date, SUM(total) as Sales FROM orders WHERE (status = 'Delivered' OR status = 'Placed' OR status = 'Preparing' OR status = 'On the Way') AND DATE(created_at) BETWEEN ? AND ? GROUP BY Date ORDER BY Date");
        $stmt->execute([$start_date, $end_date]);
        echo json_encode($stmt->fetchAll());
        ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendData.map(d => d.Date),
                datasets: [{
                    label: 'Sales',
                    data: trendData.map(d => d.Sales),
                    borderColor: '#8884d8',
                    backgroundColor: 'rgba(136, 132, 216, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { color: '#888' } },
                    x: { ticks: { color: '#888' } }
                }
            }
        });
    });
</script>