<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensure_order_schema($conn);

// Filters
$date_filter = isset($_GET['date']) ? clean_input($_GET['date']) : date('Y-m-d');
$tab_filter = isset($_GET['tab']) ? clean_input($_GET['tab']) : 'all'; // 'all', 'running', 'settled', 'voided'
$order_type_filter = isset($_GET['order_type']) ? clean_input($_GET['order_type']) : 'all';
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';

$alert = '';

// Handle Bulk Void / Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $action = $_POST['action'] ?? '';
    $selected_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];

    if ($action === 'bulk_void' && !empty($selected_ids)) {
        $in_clause = implode(',', $selected_ids);
        $conn->exec("UPDATE orders SET status = 'Voided' WHERE id IN ($in_clause)");
        $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Selected orders voided successfully.</div>';
    } elseif ($action === 'bulk_settle' && !empty($selected_ids)) {
        $in_clause = implode(',', $selected_ids);
        $conn->exec("UPDATE orders SET status = 'Settled' WHERE id IN ($in_clause)");
        $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Selected orders marked as Settled.</div>';
    }
}

// Fetch Status Counts for current date filter
$count_params = [];
$count_where = [];
if (!empty($date_filter)) {
    $count_where[] = "DATE(o.created_at) = ?";
    $count_params[] = $date_filter;
}
$count_sql = "SELECT 
    COUNT(*) as total_all,
    SUM(CASE WHEN status IN ('Placed','Preparing','On the Way','Pending','Running') THEN 1 ELSE 0 END) as total_running,
    SUM(CASE WHEN status IN ('Settled','Completed','Delivered') THEN 1 ELSE 0 END) as total_settled,
    SUM(CASE WHEN status IN ('Cancelled','Voided') THEN 1 ELSE 0 END) as total_voided
FROM orders o";
if (!empty($count_where)) {
    $count_sql .= " WHERE " . implode(" AND ", $count_where);
}
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($count_params);
$status_counts = $count_stmt->fetch(PDO::FETCH_ASSOC);

$total_all = (int)($status_counts['total_all'] ?? 0);
$total_running = (int)($status_counts['total_running'] ?? 0);
$total_settled = (int)($status_counts['total_settled'] ?? 0);
$total_voided = (int)($status_counts['total_voided'] ?? 0);

// Main Orders Query
$where = [];
$params = [];

if (!empty($date_filter)) {
    $where[] = "DATE(o.created_at) = ?";
    $params[] = $date_filter;
}

if ($tab_filter === 'running') {
    $where[] = "o.status IN ('Placed','Preparing','On the Way','Pending','Running')";
} elseif ($tab_filter === 'settled') {
    $where[] = "o.status IN ('Settled','Completed','Delivered')";
} elseif ($tab_filter === 'voided') {
    $where[] = "o.status IN ('Cancelled','Voided')";
}

if ($order_type_filter !== 'all' && !empty($order_type_filter)) {
    $where[] = "o.order_type = ?";
    $params[] = $order_type_filter;
}

if ($search !== '') {
    $where[] = "(o.order_reference LIKE ? OR c.full_name LIKE ? OR c.username LIKE ? OR c.phone LIKE ? OR o.table_number LIKE ? OR o.room_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql = "SELECT o.*, 
               COALESCE(c.full_name, c.username, 'Walk-in Guest') as guest_name,
               c.phone as guest_phone,
               c.email as guest_email,
               a.username as staff_username,
               a.email as staff_email
        FROM orders o 
        LEFT JOIN customers c ON o.user_id = c.id 
        LEFT JOIN admins a ON a.id = o.user_id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY o.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$admin_title = 'Order Summary';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    :root {
        --summary-bg: #f3f4f6;
        --summary-border: #e5e7eb;
        --summary-border-dark: #d1d5db;
        --summary-text-main: #1f2937;
        --summary-text-muted: #6b7280;
        --summary-card-bg: #ffffff;
    }

    body {
        background-color: var(--summary-bg);
    }

    /* Top Navigation Header Bar */
    .summary-header-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #ffffff;
        border: 1px solid var(--summary-border);
        border-radius: 8px;
        padding: 0.6rem 1rem;
        margin-bottom: 0.75rem;
    }

    .summary-title-group {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .summary-title-group h1 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--summary-text-main);
    }

    .summary-status-icons {
        display: flex;
        align-items: center;
        gap: 1rem;
        color: #64748b;
        font-size: 0.95rem;
    }

    .summary-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: #16a34a;
        background: #f0fdf4;
        padding: 0.25rem 0.6rem;
        border-radius: 9999px;
        border: 1px solid #bbf7d0;
    }

    .summary-scanner-badge {
        position: relative;
        display: inline-flex;
    }

    .summary-scanner-badge .count {
        position: absolute;
        top: -6px;
        right: -6px;
        background: #ef4444;
        color: white;
        font-size: 0.65rem;
        font-weight: 700;
        border-radius: 9999px;
        padding: 0.1rem 0.35rem;
    }

    /* Top Filter Bar */
    .summary-filter-card {
        background: #ffffff;
        border: 1px solid var(--summary-border);
        border-radius: 8px;
        padding: 0.6rem 0.85rem;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .summary-filter-left {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .summary-date-input {
        padding: 0.45rem 0.75rem;
        border: 1px solid var(--summary-border-dark);
        border-radius: 6px;
        font-size: 0.85rem;
        color: #334155;
        background: #ffffff;
        outline: none;
    }

    /* Tabs Bar */
    .summary-tabs-row {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        background: #f8fafc;
        border: 1px solid var(--summary-border);
        border-radius: 6px;
        padding: 0.25rem;
    }

    .summary-tab {
        padding: 0.35rem 0.85rem;
        font-size: 0.82rem;
        font-weight: 700;
        color: #475569;
        text-decoration: none;
        border-radius: 5px;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        transition: all 0.15s ease;
    }

    .summary-tab:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .summary-tab.active {
        background: #ffffff;
        color: #0f172a;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        border-bottom: 2px solid #0f172a;
    }

    .summary-tab-count {
        font-size: 0.72rem;
        padding: 0.1rem 0.45rem;
        border-radius: 9999px;
        font-weight: 800;
    }

    .count-all { background: #e2e8f0; color: #475569; }
    .count-running { background: #ffedd5; color: #ea580c; }
    .count-settled { background: #16a34a; color: #ffffff; }
    .count-voided { background: #ef4444; color: #ffffff; }

    .summary-select {
        padding: 0.45rem 0.75rem;
        border: 1px solid var(--summary-border-dark);
        border-radius: 6px;
        font-size: 0.85rem;
        color: #334155;
        background: #ffffff;
        outline: none;
    }

    .summary-search-wrap {
        position: relative;
        display: flex;
        align-items: center;
        min-width: 220px;
    }

    .summary-search-wrap i {
        position: absolute;
        left: 0.75rem;
        color: #94a3b8;
        font-size: 0.85rem;
    }

    .summary-search-input {
        width: 100%;
        padding: 0.45rem 0.75rem 0.45rem 2.2rem;
        border: 1px solid var(--summary-border-dark);
        border-radius: 6px;
        font-size: 0.85rem;
        outline: none;
    }

    /* Bulk Action Bar */
    .summary-action-card {
        background: #ffffff;
        border: 1px solid var(--summary-border);
        border-radius: 8px;
        padding: 0.55rem 0.85rem;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .summary-selected-count {
        font-size: 0.82rem;
        font-weight: 600;
        color: #64748b;
    }

    .summary-btn-group {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .summary-btn {
        background: #ffffff;
        border: 1px solid var(--summary-border-dark);
        border-radius: 6px;
        padding: 0.4rem 0.75rem;
        font-size: 0.78rem;
        font-weight: 700;
        color: #334155;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        text-decoration: none;
        transition: all 0.15s ease;
    }

    .summary-btn:hover {
        background: #f1f5f9;
        border-color: #0f172a;
    }

    .summary-btn-void {
        color: #dc2626;
        border-color: #fca5a5;
        background: #fff5f5;
    }

    .summary-btn-void:hover {
        background: #fee2e2;
    }

    /* Orders Table */
    .summary-table-card {
        background: #ffffff;
        border: 1px solid var(--summary-border);
        border-radius: 8px;
        overflow: hidden;
    }

    .summary-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.85rem;
    }

    .summary-table th {
        background: #f8fafc;
        padding: 0.65rem 0.85rem;
        font-weight: 700;
        color: #475569;
        border-bottom: 1px solid var(--summary-border);
        white-space: nowrap;
    }

    .summary-table td {
        padding: 0.65rem 0.85rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        vertical-align: middle;
    }

    .summary-table tr:hover td {
        background: #f8fafc;
    }

    /* Badges */
    .badge-settled {
        background: #16a34a;
        color: #ffffff;
        font-weight: 700;
        font-size: 0.72rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
    }

    .badge-running {
        background: #ea580c;
        color: #ffffff;
        font-weight: 700;
        font-size: 0.72rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
    }

    .badge-voided {
        background: #ef4444;
        color: #ffffff;
        font-weight: 700;
        font-size: 0.72rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
    }

    .amount-pill {
        background: #bbf7d0;
        color: #166534;
        font-weight: 800;
        font-size: 0.85rem;
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        display: inline-block;
        min-width: 75px;
        text-align: right;
    }
</style>

<!-- Top Header Navigation Bar -->
<div class="summary-header-bar">
    <div class="summary-title-group">
        <i class="fas fa-bars" style="color: #475569; font-size: 1.1rem; cursor: pointer;"></i>
        <h1>Order Summary</h1>
    </div>
    <div class="summary-status-icons">
        <span class="summary-status-badge"><i class="fas fa-cloud"></i></span>
        <span class="summary-scanner-badge" title="Scans">
            <i class="fas fa-qrcode"></i>
            <span class="count">0</span>
        </span>
        <i class="fas fa-mobile-alt" title="Device"></i>
        <i class="fas fa-bullhorn" title="Notifications"></i>
        <span style="font-size: 0.82rem; font-weight: 600; color: #334155; display: inline-flex; align-items: center; gap: 0.3rem;">
            <i class="fas fa-store"></i> AWH (Anniella R...
        </span>
        <span style="font-size: 0.82rem; font-weight: 600; color: #334155; display: inline-flex; align-items: center; gap: 0.3rem; border: 1px solid var(--summary-border-dark); padding: 0.25rem 0.6rem; border-radius: 6px; background: #ffffff;">
            <i class="fas fa-building"></i> Airport West H... 57177 <i class="fas fa-chevron-down" style="font-size:0.7rem; margin-left:0.2rem;"></i>
        </span>
    </div>
</div>

<?php echo $alert; ?>

<form method="POST" id="bulkActionsForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" id="bulkActionInput" value="">

    <!-- Top Filter Controls -->
    <div class="summary-filter-card">
        <div class="summary-filter-left">
            <!-- Date Filter -->
            <input type="date" id="dateFilterInput" class="summary-date-input" value="<?php echo htmlspecialchars($date_filter); ?>" onchange="applyFilters()">

            <!-- Status Tabs with live counts -->
            <div class="summary-tabs-row">
                <a href="javascript:void(0)" class="summary-tab <?php echo $tab_filter === 'all' ? 'active' : ''; ?>" onclick="setTab('all')">
                    All <span class="summary-tab-count count-all"><?php echo $total_all; ?></span>
                </a>
                <a href="javascript:void(0)" class="summary-tab <?php echo $tab_filter === 'running' ? 'active' : ''; ?>" onclick="setTab('running')">
                    Running <span class="summary-tab-count count-running"><?php echo $total_running; ?></span>
                </a>
                <a href="javascript:void(0)" class="summary-tab <?php echo $tab_filter === 'settled' ? 'active' : ''; ?>" onclick="setTab('settled')">
                    Settled <span class="summary-tab-count count-settled"><?php echo $total_settled; ?></span>
                </a>
                <a href="javascript:void(0)" class="summary-tab <?php echo $tab_filter === 'voided' ? 'active' : ''; ?>" onclick="setTab('voided')">
                    Voided <span class="summary-tab-count count-voided"><?php echo $total_voided; ?></span>
                </a>
            </div>

            <!-- Order Type Select -->
            <select id="orderTypeFilterSelect" class="summary-select" onchange="applyFilters()">
                <option value="all" <?php echo $order_type_filter === 'all' ? 'selected' : ''; ?>>All Order Types</option>
                <option value="dine_in" <?php echo $order_type_filter === 'dine_in' ? 'selected' : ''; ?>>Dine-In</option>
                <option value="takeaway" <?php echo $order_type_filter === 'takeaway' ? 'selected' : ''; ?>>Takeaway</option>
                <option value="delivery" <?php echo $order_type_filter === 'delivery' ? 'selected' : ''; ?>>Delivery</option>
            </select>
        </div>

        <!-- Search Input -->
        <div class="summary-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" class="summary-search-input" placeholder="Search orders..." value="<?php echo htmlspecialchars($search); ?>" onkeyup="if(event.key==='Enter') applyFilters()">
        </div>
    </div>

    <!-- Bulk Actions Toolbar -->
    <div class="summary-action-card">
        <div class="summary-selected-count" id="selectedCountText">
            0 record(s) selected
        </div>

        <div class="summary-btn-group">
            <a href="take_order.php" class="summary-btn" style="background:#0f172a; color:#ffffff; border-color:#0f172a;">
                <i class="fas fa-plus"></i> Take Order
            </a>
            <button type="button" class="summary-btn" id="btnRecall" onclick="triggerRecall()">
                <i class="fas fa-external-link-alt"></i> Recall
            </button>
            <button type="button" class="summary-btn" id="btnReprintReceipt" onclick="triggerReceiptPrint()">
                <i class="fas fa-print"></i> Reprint Receipt
            </button>
            <button type="button" class="summary-btn" id="btnReprintKOT" onclick="triggerKOTPrint()">
                <i class="fas fa-receipt"></i> Reprint KOT
            </button>
            <button type="button" class="summary-btn summary-btn-void" onclick="triggerBulkVoid()">
                <i class="fas fa-trash-alt"></i> Void
            </button>
            <button type="button" class="summary-btn" onclick="alert('Order split feature triggered.')">
                <i class="fas fa-columns"></i> Split
            </button>
            <button type="button" class="summary-btn" onclick="alert('Change owner feature triggered.')">
                <i class="fas fa-user-edit"></i> Change Owner
            </button>
            <button type="button" class="summary-btn" onclick="exportToCSV()">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
        </div>
    </div>

    <!-- Orders Data Table -->
    <div class="summary-table-card">
        <?php if (empty($orders)): ?>
            <div style="text-align:center; padding:3rem 1rem; color:#94a3b8;">
                <i class="fas fa-inbox" style="font-size:2.5rem; margin-bottom:0.5rem; opacity:0.6;"></i>
                <p style="font-size:1rem; font-weight:700; color:#64748b; margin:0.3rem 0;">No matching orders found</p>
                <small>Try selecting a different date or clearing your filter parameters.</small>
            </div>
        <?php else: ?>
            <table class="summary-table" id="ordersTable">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="selectAllCheckbox"></th>
                        <th>Order <i class="fas fa-arrow-down" style="font-size:0.75rem;"></i></th>
                        <th>Time</th>
                        <th>Receipt No</th>
                        <th>R/T No</th>
                        <th>Order Type</th>
                        <th>Guest Name</th>
                        <th>User</th>
                        <th>Status</th>
                        <th style="text-align:right;">Amount</th>
                        <th style="width: 30px; text-align:center;"><i class="fas fa-cog"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php 
                        $status_str = ucfirst(strtolower($order['status'] ?: 'Running'));
                        $is_settled = in_array(strtolower($order['status']), ['settled', 'completed', 'delivered'], true);
                        $is_voided = in_array(strtolower($order['status']), ['voided', 'cancelled'], true);
                        
                        $badge_class = 'badge-running';
                        if ($is_settled) $badge_class = 'badge-settled';
                        elseif ($is_voided) $badge_class = 'badge-voided';

                        $rt_no = '-N/A-';
                        if (!empty($order['table_number'])) {
                            $rt_no = 'Table ' . htmlspecialchars($order['table_number']);
                        } elseif (!empty($order['room_number'])) {
                            $rt_no = 'Room ' . htmlspecialchars($order['room_number']);
                        }

                        $order_type_display = ucfirst($order['order_type'] ?? 'Dine_in');
                        if (strtolower($order_type_display) === 'dine_in') $order_type_display = 'Dine-In';

                        $receipt_no = 'REC-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT);
                        $staff_name = !empty($order['staff_username']) ? $order['staff_username'] : 'Priscilla Kumah';
                        ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" name="order_ids[]" value="<?php echo (int) $order['id']; ?>"></td>
                            <td style="font-weight: 700; color: #1e293b;">
                                <a href="view.php?id=<?php echo (int)$order['id']; ?>" style="color:#0f172a; text-decoration:none;">
                                    <?php echo htmlspecialchars($order['order_reference']); ?>
                                </a>
                            </td>
                            <td style="color:#64748b; font-size:0.8rem;"><?php echo date('h:i:s A', strtotime($order['created_at'])); ?></td>
                            <td style="color:#475569; font-weight:600;"><?php echo htmlspecialchars($receipt_no); ?></td>
                            <td style="color:#475569; font-weight:600;"><?php echo htmlspecialchars($rt_no); ?></td>
                            <td style="color:#334155; font-weight:600;"><?php echo htmlspecialchars($order_type_display); ?></td>
                            <td style="font-weight:600; color:#1e293b;"><?php echo htmlspecialchars($order['guest_name']); ?></td>
                            <td style="color:#475569;"><?php echo htmlspecialchars($staff_name); ?></td>
                            <td><span class="<?php echo $badge_class; ?>"><?php echo htmlspecialchars($status_str); ?></span></td>
                            <td style="text-align:right;">
                                <span class="amount-pill"><?php echo number_format((float)$order['total'], 2); ?></span>
                            </td>
                            <td style="text-align:center;">
                                <a href="view.php?id=<?php echo (int)$order['id']; ?>" style="color:#64748b;" title="View Details">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</form>

<script>
let currentTab = "<?php echo htmlspecialchars($tab_filter); ?>";

function applyFilters() {
    const dateVal = document.getElementById('dateFilterInput').value;
    const orderTypeVal = document.getElementById('orderTypeFilterSelect').value;
    const searchVal = document.getElementById('searchInput').value.trim();

    const params = new URLSearchParams();
    if (dateVal) params.set('date', dateVal);
    if (currentTab && currentTab !== 'all') params.set('tab', currentTab);
    if (orderTypeVal && orderTypeVal !== 'all') params.set('order_type', orderTypeVal);
    if (searchVal) params.set('search', searchVal);

    window.location.href = 'index.php?' + params.toString();
}

function setTab(tabName) {
    currentTab = tabName;
    applyFilters();
}

// Checkbox Selection & Counter Update
const selectAllCheckbox = document.getElementById('selectAllCheckbox');
const rowCheckboxes = document.querySelectorAll('.row-checkbox');
const selectedCountText = document.getElementById('selectedCountText');

function updateSelectedCount() {
    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
    selectedCountText.textContent = `${checkedCount} record(s) selected`;
}

if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', function () {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        updateSelectedCount();
    });
}

rowCheckboxes.forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

// Bulk Action Button Trigger Functions
function getSelectedOrderIds() {
    const checked = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
    return checked;
}

function triggerBulkVoid() {
    const ids = getSelectedOrderIds();
    if (ids.length === 0) {
        alert('Please select at least one order to void.');
        return;
    }
    if (confirm(`Are you sure you want to void ${ids.length} selected order(s)?`)) {
        document.getElementById('bulkActionInput').value = 'bulk_void';
        document.getElementById('bulkActionsForm').submit();
    }
}

function triggerRecall() {
    const ids = getSelectedOrderIds();
    if (ids.length === 0) {
        alert('Please select an order to recall.');
        return;
    }
    window.location.href = 'view.php?id=' + ids[0];
}

function triggerReceiptPrint() {
    const ids = getSelectedOrderIds();
    if (ids.length === 0) {
        alert('Please select an order to print receipt.');
        return;
    }
    window.open('receipt.php?id=' + ids[0], '_blank');
}

function triggerKOTPrint() {
    const ids = getSelectedOrderIds();
    if (ids.length === 0) {
        alert('Please select an order to print KOT.');
        return;
    }
    window.open('labels.php?id=' + ids[0], '_blank');
}

function exportToCSV() {
    window.print();
}
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>