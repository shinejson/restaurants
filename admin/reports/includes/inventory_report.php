<?php
// Inventory Report
$query = "SELECT 
            fi.id,
            fi.item_name,
            fi.image_url,
            fi.inventory_count,
            fi.price,
            fi.cost,
            fi.sold_by_weight,
            fi.inactive,
            mc.name as main_category,
            sc.name as sub_category,
            (fi.price - fi.cost) as profit_per_item,
            (fi.price - fi.cost) / NULLIF(fi.price, 0) * 100 as profit_margin
          FROM food_items fi
          LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
          LEFT JOIN main_categories mc ON sc.main_category_id = mc.id
          WHERE fi.inactive = 0
          ORDER BY fi.inventory_count ASC, fi.item_name";

$stmt = $conn->query($query);
$inventory_items = $stmt->fetchAll();

// Inventory summary
$summary_query = "SELECT 
                    COUNT(*) as total_items,
                    SUM(inventory_count) as total_stock,
                    SUM(CASE WHEN inventory_count <= 10 THEN 1 ELSE 0 END) as low_stock_items,
                    SUM(CASE WHEN inventory_count = 0 THEN 1 ELSE 0 END) as out_of_stock_items,
                    AVG(price) as avg_price,
                    AVG(cost) as avg_cost
                  FROM food_items 
                  WHERE inactive = 0";

$summary_stmt = $conn->query($summary_query);
$inventory_summary = $summary_stmt->fetch();

// Low stock items (less than 10)
$low_stock_query = "SELECT * FROM food_items WHERE inventory_count <= 10 AND inventory_count > 0 AND inactive = 0 ORDER BY inventory_count ASC";
$low_stock_stmt = $conn->query($low_stock_query);
$low_stock_items = $low_stock_stmt->fetchAll();

// Out of stock items
$out_of_stock_query = "SELECT * FROM food_items WHERE inventory_count = 0 AND inactive = 0 ORDER BY item_name";
$out_of_stock_stmt = $conn->query($out_of_stock_query);
$out_of_stock_items = $out_of_stock_stmt->fetchAll();
?>

<h2><i class="fas fa-boxes"></i> Inventory Management Report</h2>
<p class="text-muted">Current Stock Levels as of <?php echo date('F j, Y'); ?></p>

<style>
    .report-tabs {
        display: flex;
        gap: 0.5rem;
        margin: 2rem 0;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0px;
        overflow-x: auto;
    }

    .report-tab {
        padding: 0.75rem 1.5rem;
        cursor: pointer;
        font-weight: 600;
        color: var(--text-muted);
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        transition: var(--transition);
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .report-tab:hover {
        color: var(--primary-color);
        background: rgba(255, 107, 53, 0.05);
        border-radius: 8px 8px 0 0;
    }

    .report-tab.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
    }

    .tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }

    .tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<div class="report-tabs">
    <div class="report-tab active" data-tab="overview">
        <i class="fas fa-chart-pie"></i> Overview
    </div>
    <div class="report-tab" data-tab="list">
        <i class="fas fa-list"></i> Inventory List
    </div>
    <div class="report-tab" data-tab="alerts">
        <i class="fas fa-exclamation-triangle"></i> Alerts
        <?php if ($inventory_summary['low_stock_items'] + $inventory_summary['out_of_stock_items'] > 0): ?>
            <span class="badge badge-danger"
                style="padding: 2px 6px; font-size: 0.7rem;"><?php echo $inventory_summary['low_stock_items'] + $inventory_summary['out_of_stock_items']; ?></span>
        <?php endif; ?>
    </div>
    <div class="report-tab" data-tab="valuation">
        <i class="fas fa-calculator"></i> Valuation
    </div>
</div>

<div id="overview" class="tab-content active">

    <!-- Inventory Summary -->
    <div class="stats-cards">
        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #17a2b8; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.5rem; font-weight: 800; color: var(--text-main);">
                <?php echo number_format($inventory_summary['total_items']); ?>
            </div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">
                <?php echo $inventory_summary['total_items'] == 1 ? 'Item' : 'Total Items'; ?>
            </div>
        </div>

        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #28a745; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.5rem; font-weight: 800; color: var(--text-main);">
                <?php echo number_format($inventory_summary['total_stock']); ?>
            </div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Total
                Stock</div>
        </div>

        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #ffc107; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.5rem; font-weight: 800; color: var(--text-main);">
                <?php echo number_format($inventory_summary['low_stock_items']); ?>
            </div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Low
                Stock
            </div>
        </div>

        <div class="stat-card"
            style="background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-top: 4px solid #dc3545; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div class="stat-value" style="font-size: 1.5rem; font-weight: 800; color: var(--text-main);">
                <?php echo number_format($inventory_summary['out_of_stock_items']); ?>
            </div>
            <div class="stat-label"
                style="color: var(--text-muted); font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Out of
                Stock</div>
        </div>
    </div>

    <div class="chart-container">
        <canvas id="stockChart"></canvas>
    </div>
</div>

<div id="alerts" class="tab-content">
    <!-- Low Stock Alert -->
    <?php if (!empty($low_stock_items)): ?>
        <div
            style="margin-top: 1rem; background: var(--glass); backdrop-filter: blur(12px); border: 1px solid rgba(255, 193, 7, 0.3); border-left: 5px solid #ffc107; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <h3 style="color: #ffc107;"><i class="fas fa-exclamation-triangle"></i> Low Stock Alert</h3>
            <p>The following items are running low on stock (10 or less units remaining):</p>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th>Price</th>
                            <th>Reorder Suggested</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($low_stock_items as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                <td>
                                    <?php
                                    $cat_stmt = $conn->prepare("SELECT mc.name FROM food_items fi 
                                                           JOIN sub_categories sc ON fi.sub_category_id = sc.id 
                                                           JOIN main_categories mc ON sc.main_category_id = mc.id 
                                                           WHERE fi.id = ?");
                                    $cat_stmt->execute([$item['id']]);
                                    $category = $cat_stmt->fetch();
                                    echo htmlspecialchars($category['name'] ?? 'N/A');
                                    ?>
                                </td>
                                <td>
                                    <span class="badge"
                                        style="background: rgba(255, 193, 7, 0.2); color: #ffc107; border: 1px solid rgba(255, 193, 7, 0.3);">
                                        <?php echo $item['inventory_count']; ?> units
                                    </span>
                                </td>
                                <td>₵<?php echo number_format($item['price'], 2); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="reorderItem(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-shopping-cart"></i> Reorder
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Out of Stock Items -->
    <?php if (!empty($out_of_stock_items)): ?>
        <div
            style="margin-top: 2rem; background: var(--glass); backdrop-filter: blur(12px); border: 1px solid rgba(220, 53, 69, 0.3); border-left: 5px solid #dc3545; border-radius: 16px; padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <h3 style="color: #dc3545;"><i class="fas fa-times-circle"></i> Out of Stock Items</h3>
            <p>The following items are currently out of stock:</p>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($out_of_stock_items as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                <td>
                                    <?php
                                    $cat_stmt = $conn->prepare("SELECT mc.name FROM food_items fi 
                                                           JOIN sub_categories sc ON fi.sub_category_id = sc.id 
                                                           JOIN main_categories mc ON sc.main_category_id = mc.id 
                                                           WHERE fi.id = ?");
                                    $cat_stmt->execute([$item['id']]);
                                    $category = $cat_stmt->fetch();
                                    echo htmlspecialchars($category['name'] ?? 'N/A');
                                    ?>
                                </td>
                                <td>₵<?php echo number_format($item['price'], 2); ?></td>
                                <td><span class="badge"
                                        style="background: rgba(220, 53, 69, 0.1); color: #dc3545; border: 1px solid rgba(220, 53, 69, 0.2);">Out
                                        of Stock</span></td>
                                <td>
                                    <button class="btn btn-sm btn-danger" onclick="reorderItem(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-exclamation-triangle"></i> Urgent Reorder
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($low_stock_items) && empty($out_of_stock_items)): ?>
        <div class="alert alert-success" style="margin-top: 1rem; border-radius: 16px;">
            <i class="fas fa-check-circle"></i> No low stock or out of stock items found.
        </div>
    <?php endif; ?>
</div>

<div id="list" class="tab-content">
    <div style="margin-top: 1rem;">
        <h3><i class="fas fa-list"></i> Complete Inventory List</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Cost</th>
                        <th>Price</th>
                        <th>Profit/Item</th>
                        <th>Margin</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory_items as $item):
                        $stock_class = $item['inventory_count'] == 0 ? 'danger' : ($item['inventory_count'] <= 10 ? 'warning' : 'success');
                        $margin_class = $item['profit_margin'] > 50 ? 'success' : ($item['profit_margin'] > 30 ? 'warning' : 'danger');
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px; min-width: 150px;">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                            alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                            style="width: 32px; height: 32px; object-fit: cover; border-radius: 4px;">
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($item['main_category']); ?> /
                                <?php echo htmlspecialchars($item['sub_category']); ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $stock_class; ?>">
                                    <?php echo number_format($item['inventory_count']); ?> units
                                </span>
                            </td>
                            <td>₵<?php echo number_format($item['cost'], 2); ?></td>
                            <td>₵<?php echo number_format($item['price'], 2); ?></td>
                            <td>₵<?php echo number_format($item['profit_per_item'], 2); ?></td>
                            <td>
                                <span class="text-<?php echo $margin_class; ?>">
                                    <?php echo number_format($item['profit_margin'], 1); ?>%
                                </span>
                            </td>
                            <td>
                                <?php if ($item['inventory_count'] == 0): ?>
                                    <span class="badge badge-danger">Out of Stock</span>
                                <?php elseif ($item['inventory_count'] <= 10): ?>
                                    <span class="badge badge-warning">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <td style="min-width: 150px;">
                                <button class="btn btn-sm btn-outline-primary"
                                    onclick="updateStock(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-success"
                                    onclick="viewItemHistory(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-history"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="valuation" class="tab-content">
    <!-- Inventory Value Summary -->
    <?php
    $total_cost_value = 0;
    $total_retail_value = 0;
    foreach ($inventory_items as $item) {
        $total_cost_value += $item['cost'] * $item['inventory_count'];
        $total_retail_value += $item['price'] * $item['inventory_count'];
    }
    $potential_profit = $total_retail_value - $total_cost_value;
    ?>
    <div
        style="margin-top: 1rem; background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-sm);">
        <h3><i class="fas fa-calculator"></i> Inventory Valuation</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
            <div>
                <h4 style="font-size: 0.85rem;">Total Cost Value</h4>
                <p style="font-size: 1.25rem; font-weight: bold;">₵<?php echo number_format($total_cost_value, 2); ?>
                </p>
            </div>
            <div>
                <h4 style="font-size: 0.85rem;">Total Retail Value</h4>
                <p style="font-size: 1.25rem; font-weight: bold;">₵<?php echo number_format($total_retail_value, 2); ?>
                </p>
            </div>
            <div>
                <h4 style="font-size: 0.85rem;">Potential Profit</h4>
                <p style="font-size: 1.25rem; font-weight: bold; color: #28a745;">
                    ₵<?php echo number_format($potential_profit, 2); ?></p>
            </div>
            <div>
                <h4 style="font-size: 0.85rem;">Profit Margin</h4>
                <p style="font-size: 1.25rem; font-weight: bold; color: #28a745;">
                    <?php echo $total_retail_value > 0 ? number_format(($potential_profit / $total_retail_value * 100), 1) : 0; ?>%
                </p>
            </div>
        </div>
    </div>
</div>

<div class="export-buttons" style="margin-top: 2rem;">
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
        const stockCtx = document.getElementById('stockChart').getContext('2d');

        // Group items by stock level
        const stockLevels = {
            'Out of Stock (0)': <?php echo $inventory_summary['out_of_stock_items']; ?>,
            'Low Stock (1-10)': <?php echo $inventory_summary['low_stock_items']; ?>,
            'In Stock (>10)': <?php echo $inventory_summary['total_items'] - $inventory_summary['low_stock_items'] - $inventory_summary['out_of_stock_items']; ?>
        };

        new Chart(stockCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(stockLevels),
                datasets: [{
                    data: Object.values(stockLevels),
                    backgroundColor: ['#dc3545', '#ffc107', '#28a745']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Stock Level Distribution'
                    },
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
    }

    function reorderItem(itemId) {
        const quantity = prompt('Enter quantity to reorder:', '20');
        if (quantity && !isNaN(quantity) && quantity > 0) {
            // AJAX call to update inventory
            fetch('ajax/update_inventory.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    item_id: itemId,
                    quantity: parseInt(quantity),
                    action: 'add'
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Inventory updated successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error updating inventory');
                });
        }
    }

    function updateStock(itemId) {
        const quantity = prompt('Enter new stock quantity:', '0');
        if (quantity !== null && !isNaN(quantity)) {
            // AJAX call to update inventory
            fetch('ajax/update_inventory.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    item_id: itemId,
                    quantity: parseInt(quantity),
                    action: 'set'
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Stock updated successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        }
    }

    function viewItemHistory(itemId) {
        window.open('item_history.php?id=' + itemId, '_blank');
    }

    // Tab switching logic
    document.querySelectorAll('.report-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.getAttribute('data-tab');

            // Update tabs
            document.querySelectorAll('.report-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            // Update content
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(target).classList.add('active');
        });
    });

    // Auto-select tab if low_stock report is requested
    <?php if ($report_type == 'low_stock'): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const alertsTab = document.querySelector('.report-tab[data-tab="alerts"]');
            if (alertsTab) alertsTab.click();
        });
    <?php endif; ?>
</script>