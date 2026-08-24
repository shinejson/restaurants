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
$top_profit_products = [];
$top_qty_products = [];
$category_margins = [];

// Top 5 Products by Profit in period
$sql = "SELECT fi.item_name, SUM((oi.price - IFNULL(fi.cost, 0)) * oi.quantity) as Profit
FROM order_items oi
JOIN food_items fi ON oi.food_item_id = fi.id
JOIN orders o ON oi.order_id = o.id
LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$sql .= " GROUP BY fi.item_name ORDER BY Profit DESC LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$top_profit_products = $stmt->fetchAll();

// Top 5 Products by Sales Quantity in period
$sql = "SELECT fi.item_name, SUM(oi.quantity) as Quantity
FROM order_items oi
JOIN food_items fi ON oi.food_item_id = fi.id
JOIN orders o ON oi.order_id = o.id
LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
WHERE o.status != 'Cancelled' AND DATE(o.created_at) BETWEEN ? AND ?";
if ($category_filter > 0)
    $sql .= $cat_where;
$sql .= " GROUP BY fi.item_name ORDER BY Quantity DESC LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$top_qty_products = $stmt->fetchAll();

// Profit Margin % by Category
$sql = "SELECT
mc.name as Category,
SUM((oi.price - IFNULL(fi.cost, 0)) * oi.quantity) as Profit,
SUM(oi.price * oi.quantity) as Revenue
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
$category_margins = $stmt->fetchAll();

// Get Categories for Dropdown
$categories = $conn->query("SELECT id, name FROM main_categories ORDER BY name")->fetchAll();
?>

<div class="analytics-products">
    <div class="view-header">
        <h2>Products Analysis</h2>
        <div class="header-actions">
            <form method="GET" class="filter-controls"
                style="display: flex; gap: 1rem; align-items: center; background: var(--white); padding: 0.75rem 1.5rem; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
                <input type="hidden" name="view" value="products">

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

    <!-- Charts Grid -->
    <div class="products-grid">
        <div class="chart-card">
            <div class="chart-header">Top 5 Products by Profit</div>
            <div class="chart-body">
                <canvas id="topProfitProductsChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Top 5 Products by Quantity</div>
            <div class="chart-body">
                <canvas id="topQtyProductsChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Profit Margin % by Category</div>
            <div class="chart-body">
                <canvas id="marginByCategoryChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">Revenue vs Profit Contribution</div>
            <div class="chart-body">
                <canvas id="revenueProfitChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Top Profit Products Bar
        const topProfitCtx = document.getElementById('topProfitProductsChart').getContext('2d');
        const profitData = <?php echo json_encode($top_profit_products); ?>;

        new Chart(topProfitCtx, {
            type: 'bar',
            data: {
                labels: profitData.map(d => d.item_name),
                datasets: [{
                    label: 'Profit',
                    data: profitData.map(d => d.Profit),
                    backgroundColor: '#ff8042',
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

        // 2. Top Qty Products Bar
        const topQtyCtx = document.getElementById('topQtyProductsChart').getContext('2d');
        const qtyData = <?php echo json_encode($top_qty_products); ?>;

        new Chart(topQtyCtx, {
            type: 'bar',
            data: {
                labels: qtyData.map(d => d.item_name),
                datasets: [{
                    label: 'Quantity',
                    data: qtyData.map(d => d.Quantity),
                    backgroundColor: '#00c49f',
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

        // 3. Margin by Category Donut
        const marginCtx = document.getElementById('marginByCategoryChart').getContext('2d');
        const marginData = <?php echo json_encode($category_margins); ?>;

        new Chart(marginCtx, {
            type: 'doughnut',
            data: {
                labels: marginData.map(d => d.Category),
                datasets: [{
                    data: marginData.map(d => d.Revenue > 0 ? (d.Profit / d.Revenue * 100) : 0),
                    backgroundColor: ['#0088fe', '#00c49f', '#ffbb28', '#ff8042'],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '60%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#888' } },
                    tooltip: {
                        callbacks: {
                            label: (context) => context.label + ': ' + context.parsed.toFixed(1) + '%'
                        }
                    }
                }
            }
        });

        // 4. Revenue vs Profit (Radar or Polar)
        const revProfitCtx = document.getElementById('revenueProfitChart').getContext('2d');
        new Chart(revProfitCtx, {
            type: 'polarArea',
            data: {
                labels: marginData.map(d => d.Category),
                datasets: [{
                    label: 'Revenue Weight',
                    data: marginData.map(d => d.Revenue),
                    backgroundColor: [
                        'rgba(0, 136, 254, 0.5)',
                        'rgba(0, 196, 159, 0.5)',
                        'rgba(255, 187, 40, 0.5)',
                        'rgba(255, 128, 66, 0.5)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: '#888' } } },
                scales: {
                    r: { ticks: { display: false }, grid: { color: 'rgba(255,255,255,0.1)' } }
                }
            }
        });
    });
</script>