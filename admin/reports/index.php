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

/* Helper: build a URL for a report keeping the current filters */
function report_url($type, $start, $end, $cat)
{
    return '?report=' . urlencode($type) . '&start_date=' . urlencode($start) . '&end_date=' . urlencode($end) . '&category_id=' . urlencode($cat);
}

$admin_title = 'Reports & Analytics';

// Render full-width without the main admin sidebar (opened in its own tab)
$hide_sidebar = true;
include '../includes/admin_header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

<style>
    /* ==========================================================
       Full-height layout: the report sidebar stretches from the
       topnav down to the bottom of the window, while the content
       column (header + filter + report) scrolls independently.
       ========================================================== */
    .admin-content-padding {
        height: calc(100vh - var(--topnav-height));
        max-width: none;
        padding: 0;
        margin: 0;
        overflow: hidden;
    }

    .reports-layout {
        display: flex;
        height: 100%;
        min-height: 0;
    }

    /* ---- Fixed report sidebar (full height) ---- */
    .report-sidebar {
        width: 280px;
        flex-shrink: 0;
        background: var(--white);
        border-right: 1px solid var(--border-color);
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    /* ---- Scrollable content column ---- */
    .report-content {
        flex: 1;
        min-width: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
        padding: 2rem 2.5rem;
    }

    /* Slim scrollbars */
    .report-sidebar::-webkit-scrollbar,
    .report-content::-webkit-scrollbar {
        width: 8px;
    }

    .report-sidebar::-webkit-scrollbar-thumb,
    .report-content::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.15);
        border-radius: 4px;
    }

    .report-sidebar::-webkit-scrollbar-thumb:hover,
    .report-content::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 0, 0, 0.25);
    }

    /* ---- Page header (inside content) ---- */
    .reports-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .reports-header h1 {
        margin: 0;
        font-size: 1.75rem;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .reports-header p {
        margin: 0.35rem 0 0;
        color: var(--text-muted);
        font-size: 0.95rem;
    }

    .btn-back-admin {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--white);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        padding: 0.6rem 1.1rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.875rem;
        text-decoration: none;
        transition: var(--transition);
        white-space: nowrap;
    }

    .btn-back-admin:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    /* ---- Filter bar (inside content) ---- */
    .date-filter {
        background: var(--white);
        padding: 1rem 1.25rem;
        border-radius: 14px;
        margin-bottom: 1.5rem;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border-color);
    }

    .filter-row {
        display: flex;
        gap: 1rem;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .filter-row .form-group {
        margin-bottom: 0;
        flex: 1 1 170px;
        min-width: 150px;
    }

    .filter-row .form-group label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin-bottom: 0.35rem;
    }

    .filter-row .form-control {
        padding: 0.6rem 0.85rem;
        font-size: 0.9rem;
        border-radius: 10px;
        height: 44px;
    }

    .filter-actions {
        flex: 0 0 auto;
    }

    .filter-actions-row {
        display: flex;
        gap: 0.6rem;
    }

    .btn-apply-filter {
        background: var(--primary-color);
        color: white;
        border: none;
        height: 44px;
        padding: 0 1.5rem;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: var(--transition);
        white-space: nowrap;
    }

    .btn-apply-filter:hover {
        background: var(--primary-light);
        transform: translateY(-1px);
    }

    .btn-reset-filter {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--white);
        border: 1px solid var(--border-color);
        color: var(--text-muted);
        height: 44px;
        padding: 0 1.1rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        text-decoration: none;
        transition: var(--transition);
        white-space: nowrap;
    }

    .btn-reset-filter:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    /* ---- Report category list ---- */
    .report-category {
        border-bottom: 1px solid var(--border-color);
    }

    .report-category:last-child {
        border-bottom: none;
    }

    .report-category h3 {
        padding: 0.85rem 1.25rem;
        margin: 0;
        background: var(--light-bg);
        color: var(--text-main);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        position: sticky;
        top: 0;
        z-index: 2;
    }

    .report-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .report-item {
        border-bottom: 1px solid var(--border-color);
    }

    .report-item:last-child {
        border-bottom: none;
    }

    .report-item a {
        padding: 0.7rem 1.25rem;
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

    /* ---- Inner report widgets ---- */
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

    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1.5rem;
        border-radius: 12px;
    }

    /* ---- Export buttons ---- */
    .export-buttons {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px dashed var(--border-color);
    }

    .export-buttons .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        height: 44px;
        padding: 0 1.4rem;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        border: 1px solid transparent;
        transition: var(--transition);
        white-space: nowrap;
        font-family: inherit;
    }

    .export-buttons .btn:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }

    .export-buttons .btn:active {
        transform: translateY(0);
    }

    /* PDF - red */
    .export-buttons .btn-danger {
        background: #ef4444;
        color: #ffffff;
        border-color: #ef4444;
    }

    .export-buttons .btn-danger:hover {
        background: #dc2626;
        border-color: #dc2626;
    }

    /* Excel - green */
    .export-buttons .btn-success {
        background: #10b981;
        color: #ffffff;
        border-color: #10b981;
    }

    .export-buttons .btn-success:hover {
        background: #059669;
        border-color: #059669;
    }

    /* CSV - orange (brand) */
    .export-buttons .btn-primary {
        background: var(--primary-color);
        color: #ffffff;
        border-color: var(--primary-color);
    }

    .export-buttons .btn-primary:hover {
        background: var(--primary-light);
        border-color: var(--primary-light);
    }

    /* ---- Responsive fallback: stack on small screens ---- */
    @media (max-width: 992px) {
        .admin-content-padding {
            height: auto;
            overflow: visible;
        }

        .reports-layout {
            flex-direction: column;
            overflow: visible;
        }

        .report-sidebar {
            width: 100%;
            overflow: visible;
            border-right: none;
            border-bottom: 1px solid var(--border-color);
        }

        .report-content {
            overflow: visible;
            padding: 1.25rem;
        }

        .reports-header {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    @media (max-width: 768px) {
        .stats-cards {
            grid-template-columns: 1fr;
        }

        .chart-container {
            height: 300px;
            padding: 1rem;
        }
    }
</style>

<div class="reports-layout">

    <!-- Fixed Report Sidebar (spans from topnav to bottom) -->
    <aside class="report-sidebar">
        <div class="report-category">
            <h3><i class="fas fa-chart-line"></i> Sales & Revenue</h3>
            <ul class="report-list">
                <li class="report-item <?php echo $report_type == 'sales_summary' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('sales_summary', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-file-invoice-dollar"></i> Sales Summary
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'sales_by_category' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('sales_by_category', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-tags"></i> Sales by Category
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'sales_by_item' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('sales_by_item', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-utensils"></i> Sales by Item
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'revenue_trends' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('revenue_trends', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-chart-line"></i> Revenue Trends
                    </a>
                </li>
            </ul>
        </div>

        <div class="report-category">
            <h3><i class="fas fa-utensils"></i> Menu Performance</h3>
            <ul class="report-list">
                <li class="report-item <?php echo $report_type == 'top_selling_items' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('top_selling_items', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-trophy"></i> Top Selling Items
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'low_selling_items' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('low_selling_items', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-chart-line-down"></i> Low Selling Items
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'menu_performance' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('menu_performance', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-chart-pie"></i> Menu Performance
                    </a>
                </li>
            </ul>
        </div>

        <div class="report-category">
            <h3><i class="fas fa-box"></i> Inventory & Cost</h3>
            <ul class="report-list">
                <li class="report-item <?php echo $report_type == 'inventory_report' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('inventory_report', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-boxes"></i> Inventory Report
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'low_stock' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('low_stock', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-exclamation-triangle"></i> Low Stock Alert
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'cost_analysis' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('cost_analysis', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-money-bill-wave"></i> Cost Analysis
                    </a>
                </li>
            </ul>
        </div>

        <div class="report-category">
            <h3><i class="fas fa-users"></i> Customer Analytics</h3>
            <ul class="report-list">
                <li class="report-item <?php echo $report_type == 'customer_analysis' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('customer_analysis', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-user-chart"></i> Customer Analysis
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'order_analytics' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('order_analytics', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-shopping-cart"></i> Order Analytics
                    </a>
                </li>
                <li class="report-item <?php echo $report_type == 'peak_hours' ? 'active' : ''; ?>">
                    <a href="<?php echo report_url('peak_hours', $start_date, $end_date, $category_filter); ?>">
                        <i class="fas fa-clock"></i> Peak Hours
                    </a>
                </li>
            </ul>
        </div>
    </aside>

    <!-- Scrollable Content Column -->
    <main class="report-content">
        <!-- Page Header -->
        <div class="reports-header">
            <div>
                <h1><i class="fas fa-chart-line"></i> Reports & Analytics</h1>
                <p>Date Range: <?php echo date('M d, Y', strtotime($start_date)); ?> to
                    <?php echo date('M d, Y', strtotime($end_date)); ?>
                </p>
            </div>
            <a href="<?php echo BASE_URL; ?>/admin/dashboard.php?view=overview" class="btn-back-admin">
                <i class="fas fa-arrow-left"></i> Back to Admin
            </a>
        </div>

        <!-- Filter (inside content section) -->
        <div class="date-filter">
            <form method="GET" class="filter-form">
                <div class="filter-row">
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control"
                            value="<?php echo $start_date; ?>">
                    </div>

                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control"
                            value="<?php echo $end_date; ?>">
                    </div>

                    <div class="form-group">
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

                    <div class="form-group">
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

                    <div class="form-group filter-actions">
                        <div class="filter-actions-row">
                            <button type="submit" class="btn-apply-filter">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="<?php echo BASE_URL; ?>/admin/reports/index.php" class="btn-reset-filter"
                                title="Reset all filters">
                                <i class="fas fa-rotate-left"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Report Output -->
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
    </main>
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