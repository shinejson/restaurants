<?php
// Top Selling Items Report
$query = "SELECT 
            fi.id,
            fi.item_name,
            fi.image_url,
            fi.price,
            mc.name as main_category,
            sc.name as sub_category,
            COUNT(oi.id) as times_ordered,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.price * oi.quantity) as total_revenue,
            AVG(oi.price) as avg_selling_price
          FROM food_items fi
          LEFT JOIN order_items oi ON fi.id = oi.food_item_id
          LEFT JOIN orders o ON oi.order_id = o.id AND o.status = 'Delivered'
          LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
          LEFT JOIN main_categories mc ON sc.main_category_id = mc.id
          WHERE (o.created_at IS NULL OR DATE(o.created_at) BETWEEN ? AND ?)
          AND fi.inactive = 0
          GROUP BY fi.id
          HAVING total_quantity > 0
          ORDER BY total_quantity DESC, total_revenue DESC
          LIMIT 20";

$stmt = $conn->prepare($query);
$stmt->execute([$start_date, $end_date]);
$top_items = $stmt->fetchAll();

// Worst selling items
$worst_query = "SELECT 
                  fi.id,
                  fi.item_name,
                  fi.price,
                  mc.name as main_category,
                  COUNT(oi.id) as times_ordered,
                  SUM(oi.quantity) as total_quantity
                FROM food_items fi
                LEFT JOIN order_items oi ON fi.id = oi.food_item_id
                LEFT JOIN orders o ON oi.order_id = o.id AND o.status = 'Completed'
                LEFT JOIN sub_categories sc ON fi.sub_category_id = sc.id
                LEFT JOIN main_categories mc ON sc.main_category_id = mc.id
                WHERE (o.created_at IS NULL OR DATE(o.created_at) BETWEEN ? AND ?)
                AND fi.inactive = 0
                GROUP BY fi.id
                HAVING total_quantity = 0 OR total_quantity IS NULL
                ORDER BY fi.item_name
                LIMIT 10";

$worst_stmt = $conn->prepare($worst_query);
$worst_stmt->execute([$start_date, $end_date]);
$worst_items = $worst_stmt->fetchAll();
?>

<h2><i class="fas fa-trophy"></i> Top Selling Items Report</h2>
<p class="text-muted">Date Range: <?php echo date('M d, Y', strtotime($start_date)); ?> to
    <?php echo date('M d, Y', strtotime($end_date)); ?>
</p>

<!-- Top Items Chart -->
<div class="chart-container">
    <canvas id="topItemsChart"></canvas>
</div>

<!-- Top Selling Items Table -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-medal"></i> Top 20 Best Selling Items</h3>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Times Ordered</th>
                    <th>Quantity Sold</th>
                    <th>Revenue</th>
                    <th>Performance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_items as $index => $item):
                    $rank = $index + 1;
                    $performance = $item['total_quantity'] > 0 ? 'Good' : 'No Sales';
                    $performance_class = $item['total_quantity'] > 10 ? 'text-success' : ($item['total_quantity'] > 0 ? 'text-warning' : 'text-danger');
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
                            <div style="display: flex; align-items: center; gap: 10px; min-width: 200px;">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                        alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                        style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">
                                <?php endif; ?>
                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($item['main_category']); ?> /
                            <?php echo htmlspecialchars($item['sub_category']); ?>
                        </td>
                        <td>₵<?php echo number_format($item['price'], 2); ?></td>
                        <td><?php echo number_format($item['times_ordered']); ?></td>
                        <td><?php echo number_format($item['total_quantity']); ?></td>
                        <td><strong>₵<?php echo number_format($item['total_revenue'], 2); ?></strong></td>
                        <td>
                            <span class="<?php echo $performance_class; ?>" style="white-space: nowrap;">
                                <i
                                    class="fas fa-<?php echo $item['total_quantity'] > 10 ? 'arrow-up' : ($item['total_quantity'] > 0 ? 'minus' : 'times'); ?>"></i>
                                <?php echo $performance; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($top_items)): ?>
                    <tr>
                        <td colspan="8" class="text-center">No sales data available for this period</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Worst Selling Items -->
<div style="margin-top: 2rem;">
    <h3><i class="fas fa-exclamation-triangle"></i> Items with No Sales</h3>
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
                <?php foreach ($worst_items as $item): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($item['main_category']); ?></td>
                        <td>₵<?php echo number_format($item['price'], 2); ?></td>
                        <td><span class="text-danger">No Sales</span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary"
                                onclick="showItemDetails(<?php echo $item['id']; ?>)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($worst_items)): ?>
                    <tr>
                        <td colspan="5" class="text-center">All items have sales in this period</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Performance Metrics -->
<?php if (!empty($top_items)):
    $total_items_sold = array_sum(array_column($top_items, 'total_quantity'));
    $total_revenue = array_sum(array_column($top_items, 'total_revenue'));
    $avg_price = $total_items_sold > 0 ? $total_revenue / $total_items_sold : 0;
    ?>
    <div
        style="margin-top: 2rem; background: var(--glass); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-sm);">
        <h3><i class="fas fa-chart-line"></i> Performance Summary</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div>
                <h4>Total Items Sold</h4>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_items_sold); ?></p>
            </div>
            <div>
                <h4>Total Revenue</h4>
                <p style="font-size: 1.5rem; font-weight: bold;">₵<?php echo number_format($total_revenue, 2); ?></p>
            </div>
            <div>
                <h4>Average Price per Item</h4>
                <p style="font-size: 1.5rem; font-weight: bold;">₵<?php echo number_format($avg_price, 2); ?></p>
            </div>
            <div>
                <h4>Unique Items Sold</h4>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo count($top_items); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

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
        const topCtx = document.getElementById('topItemsChart').getContext('2d');
        const itemNames = <?php echo json_encode(array_slice(array_column($top_items, 'item_name'), 0, 10)); ?>;
        const itemQuantities = <?php echo json_encode(array_slice(array_column($top_items, 'total_quantity'), 0, 10)); ?>;

        new Chart(topCtx, {
            type: 'horizontalBar',
            data: {
                labels: itemNames,
                datasets: [{
                    label: 'Quantity Sold',
                    data: itemQuantities,
                    backgroundColor: '#4361ee',
                    borderColor: '#3a0ca3',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    title: {
                        display: true,
                        text: 'Top 10 Selling Items by Quantity'
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }

    function showItemDetails(itemId) {
        // Implement item details modal
        alert('Item ID: ' + itemId + '\nFeature to be implemented');
    }
</script>