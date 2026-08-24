<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

require_once '../../includes/permissions.php';

// Check permission
require_permission('view_reports');

// Set default date range (last 30 days)
$start_date = isset($_GET['start_date']) ? clean_input($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? clean_input($_GET['end_date']) : date('Y-m-d');
$category_filter = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

// Fetch Categories for Dropdown
$dropdown_categories = $conn->query("SELECT id, name FROM main_categories ORDER BY name")->fetchAll();

// Get report type
$report_type = isset($_GET['report']) ? clean_input($_GET['report']) : 'sales_summary';

$admin_title = 'Reports & Analytics';
include '../includes/admin_header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

<style>
    .reports-container {
        padding: 0;
    }

    .date-filter {
        background: var(--glass);
        backdrop-filter: blur(12px);
        padding: 1.5rem;
        border-radius: 16px;
        margin-bottom: 2rem;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--glass-border);
    }

    .report-grid {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 2rem;
    }

    @media (max-width: 1200px) {
        .report-grid {
            grid-template-columns: 1fr;
        }
    }

    .report-sidebar {
        background: var(--white);
        border-radius: 16px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        border: 1px solid var(--border-color);
        height: fit-content;
        position: sticky;
        top: 100px;
    }

    .report-category {
        border-bottom: 1px solid var(--border-color);
    }

    .report-category h3 {
        padding: 0.85rem 1.25rem;
        margin: 0;
        background: var(--light-bg);
        color: var(--text-main);
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .report-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .report-item {
        border-bottom: 1px solid var(--border-color);
    }

    .report-item a {
        padding: 0.75rem 1.25rem;
        color: var(--text-muted);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .report-item a:hover {
        background: var(--light-bg);
        color: var(--primary-color);
    }

    .report-item.active {
        background: rgba(255, 107, 53, 0.05);
        border-left: 4px solid var(--primary-color);
    }

    .report-item.active a {
        color: var(--primary-color);
        font-weight: 600;
    }

    .report-content {
        background: var(--glass);
        backdrop-filter: blur(12px);
        border-radius: 20px;
        box-shadow: var(--shadow-md);
        padding: 2rem;
        min-height: 600px;
        min-width: 0;
        border: 1px solid var(--glass-border);
    }

    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2.5rem;
    }

    .chart-container {
        height: 400px;
        margin-bottom: 2rem;
        padding: 1.5rem;
        background: var(--glass);
        backdrop-filter: blur(12px);
        border-radius: 20px;
        border: 1px solid var(--glass-border);
        box-shadow: var(--shadow-sm);
        position: relative;
        overflow: hidden;
    }

    /* Responsive Tables */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1.5rem;
        border-radius: 12px;
    }

    @media (max-width: 768px) {
        .report-content {
            padding: 1.25rem;
        }

        .stats-cards {
            grid-template-columns: 1fr;
        }

        .chart-container {
            height: 300px;
            padding: 1rem;
        }

        .report-sidebar {
            position: static;
            margin-bottom: 2rem;
        }
    }
</style>

<div class="content-header">
    <div>
        <h1>Reports & Analytics</h1>
        <p>Date Range: <?php echo date('M d, Y', strtotime($start_date)); ?> to
            <?php echo date('M d, Y', strtotime($end_date)); ?>
        </p>
    </div>
</div>

<div class="reports-container">
    <!-- Date Filter -->
    <div class="date-filter">
        <form method="GET" class="filter-form">
            <div class="filter-row" style="display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control"
                        value="<?php echo $start_date; ?>">
                </div>

                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control"
                        value="<?php echo $end_date; ?>">
                </div>

                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label for="report">Report Type</label>
                    <select id="report" name="report" class="form-control">
                        <option value="sales_summary" <?php echo $report_type == 'sales_summary' ? 'selected' : ''; ?>>
                            Sales Summary</option>
                        <option value="sales_by_category" <?php echo $report_type == 'sales_by_category' ? 'selected' : ''; ?>>Sales by Category</option>
                        <option value="top_selling_items" <?php echo $report_type == 'top_selling_items' ? 'selected' : ''; ?>>Top Selling Items</option>
                        <option value="order_analytics" <?php echo $report_type == 'order_analytics' ? 'selected' : ''; ?>>Order Analytics</option>
                        <option value="inventory_report" <?php echo $report_type == 'inventory_report' ? 'selected' : ''; ?>>Inventory Report</option>
                        <option value="customer_analysis" <?php echo $report_type == 'customer_analysis' ? 'selected' : ''; ?>>Customer Analysis</option>
                    </select>
                </div>

                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="form-control">
                        <option value="0">All Categories</option>
                        <?php foreach ($dropdown_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn-primary" style="height: 48px; padding: 0 2rem;">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="report-grid">
        <!-- Sidebar -->
        <div class="report-sidebar">
            <div class="report-category">
                <h3><i class="fas fa-chart-line"></i> Sales & Revenue</h3>
                <ul class="report-list">
                    <li class="report-item <?php echo $report_type == 'sales_summary' ? 'active' : ''; ?>">
                        <a
                            href="?report=sales_summary&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-file-invoice-dollar"></i> Sales Summary
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'sales_by_category' ? 'active' : ''; ?>">
                        <a
                            href="?report=sales_by_category&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-tags"></i> Sales by Category
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'sales_by_item' ? 'active' : ''; ?>">
                        <a
                            href="?report=sales_by_item&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-utensils"></i> Sales by Item
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'revenue_trends' ? 'active' : ''; ?>">
                        <a
                            href="?report=revenue_trends&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-chart-line"></i> Revenue Trends
                        </a>
                    </li>
                </ul>
            </div>

            <div class="report-category">
                <h3><i class="fas fa-utensils"></i> Menu Performance</h3>
                <ul class="report-list">
                    <li class="report-item <?php echo $report_type == 'top_selling_items' ? 'active' : ''; ?>">
                        <a
                            href="?report=top_selling_items&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-trophy"></i> Top Selling Items
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'low_selling_items' ? 'active' : ''; ?>">
                        <a
                            href="?report=low_selling_items&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-chart-line-down"></i> Low Selling Items
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'menu_performance' ? 'active' : ''; ?>">
                        <a
                            href="?report=menu_performance&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-chart-pie"></i> Menu Performance
                        </a>
                    </li>
                </ul>
            </div>

            <div class="report-category">
                <h3><i class="fas fa-box"></i> Inventory & Cost</h3>
                <ul class="report-list">
                    <li class="report-item <?php echo $report_type == 'inventory_report' ? 'active' : ''; ?>">
                        <a href="?report=inventory_report">
                            <i class="fas fa-boxes"></i> Inventory Report
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'low_stock' ? 'active' : ''; ?>">
                        <a href="?report=low_stock">
                            <i class="fas fa-exclamation-triangle"></i> Low Stock Alert
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'cost_analysis' ? 'active' : ''; ?>">
                        <a
                            href="?report=cost_analysis&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-money-bill-wave"></i> Cost Analysis
                        </a>
                    </li>
                </ul>
            </div>

            <div class="report-category">
                <h3><i class="fas fa-users"></i> Customer Analytics</h3>
                <ul class="report-list">
                    <li class="report-item <?php echo $report_type == 'customer_analysis' ? 'active' : ''; ?>">
                        <a
                            href="?report=customer_analysis&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-user-chart"></i> Customer Analysis
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'order_analytics' ? 'active' : ''; ?>">
                        <a
                            href="?report=order_analytics&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-shopping-cart"></i> Order Analytics
                        </a>
                    </li>
                    <li class="report-item <?php echo $report_type == 'peak_hours' ? 'active' : ''; ?>">
                        <a
                            href="?report=peak_hours&start_date=<?php echo $start_date; ?>&category_id=<?php echo $category_filter; ?>">
                            <i class="fas fa-clock"></i> Peak Hours
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="report-content">
            <?php
            // Security check for report path
            $safe_report_type = preg_replace('/[^a-zA-Z0-9_]/', '', $report_type);
            $report_path = 'includes/' . $safe_report_type . '.php';

            if (file_exists($report_path)) {
                include $report_path;
            } else {
                // For reports that might be integrated into other files or not yet created
                switch ($safe_report_type) {
                    case 'sales_by_item':
                        // If it's essentially top selling items, we can redirect or include that
                        include 'includes/top_selling_items.php';
                        break;
                    case 'low_selling_items':
                        include 'includes/top_selling_items.php';
                        break;
                    case 'low_stock':
                        include 'includes/inventory_report.php';
                        break;
                    default:
                        include 'includes/coming_soon.php';
                        break;
                }
            }
            ?>
        </div>
    </div>
</div>

<script>
    // Initialize charts and reports
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize any report-specific JavaScript here
        if (typeof initializeCharts === 'function') {
            initializeCharts();
        }
    });

    // Export functionality
    function exportReport(format) {
        const reportType = '<?php echo $report_type; ?>';
        const startDate = '<?php echo $start_date; ?>';
        const endDate = '<?php echo $end_date; ?>';

        window.open(`export.php?format=${format}&report=${reportType}&start_date=${startDate}&end_date=${endDate}`, '_blank');
    }
</script>

<?php include '../includes/admin_footer.php'; ?>