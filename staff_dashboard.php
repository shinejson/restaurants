<?php
require_once __DIR__ . '/includes/admin_check.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$today = date('Y-m-d');
$total_orders = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = '$today'")->fetchColumn();
$open_orders = $conn->query("SELECT COUNT(*) FROM orders WHERE status IN ('Placed','Preparing','On the Way') AND DATE(created_at) = '$today'")->fetchColumn();
$dine_in_orders = $conn->query("SELECT COUNT(*) FROM orders WHERE order_type = 'dine_in' AND DATE(created_at) = '$today'")->fetchColumn();
$active_tables = $conn->query("SELECT COUNT(DISTINCT table_number) FROM orders WHERE order_type = 'dine_in' AND table_number IS NOT NULL AND table_number <> '' AND status IN ('Placed','Preparing','On the Way')")->fetchColumn();

$recent_orders = $conn->query("SELECT o.*, u.full_name as customer_name FROM orders o LEFT JOIN customers u ON u.id = o.user_id ORDER BY o.created_at DESC LIMIT 5")->fetchAll();

$admin_title = 'Staff Dashboard';
include __DIR__ . '/admin/includes/admin_header.php';
?>

<style>
    .staff-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .staff-card {
        background: linear-gradient(135deg, #fff 0%, #fff7f3 100%);
        border: 1px solid #f2d7c6;
        border-radius: 18px;
        padding: 1.3rem;
        box-shadow: 0 10px 24px rgba(15,23,42,0.04);
    }

    .staff-card .label {
        color: #9a4d00;
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        font-weight: 800;
        margin-bottom: 0.5rem;
    }

    .staff-card .value {
        font-size: 2rem;
        font-weight: 900;
        color: #111827;
    }

    .dashboard-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .action-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.8rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 18px;
        padding: 1.2rem 1rem;
        font-weight: 800;
        color: #111827;
        text-decoration: none;
        box-shadow: 0 8px 18px rgba(15,23,42,0.04);
        transition: transform 0.18s ease;
    }

    .action-btn:hover {
        transform: translateY(-2px);
    }

    .action-btn.primary {
        background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
        color: #fff;
        border-color: transparent;
    }

    .table-box {
        background: #fff;
        border: 1px solid #edf2f7;
        border-radius: 18px;
        padding: 1.5rem;
        box-shadow: 0 10px 24px rgba(15,23,42,0.03);
    }

    .activity-table {
        width: 100%;
        border-collapse: collapse;
    }

    .activity-table th,
    .activity-table td {
        padding: 0.85rem 0.7rem;
        border-bottom: 1px solid #edf2f7;
        text-align: left;
    }

    .badge {
        display: inline-block;
        padding: 0.4rem 0.7rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 800;
    }

    .badge-placed { background: #fff7ed; color: #9a4d00; }
    .badge-preparing { background: #fef3c7; color: #92400e; }
    .badge-way { background: #dbeafe; color: #1d4ed8; }
    .badge-delivered { background: #dcfce7; color: #166534; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
    <div>
        <h1 style="margin:0; font-size:2rem; font-weight:800;">Staff Dashboard</h1>
        <p style="margin:0.4rem 0 0; color:var(--text-muted);">Restaurant staff operations for take-away, dine-in, and in-house service.</p>
    </div>
    <a href="admin/orders/take_order.php" class="btn-submit" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; width:auto;">
        <i class="fas fa-cash-register"></i> New Order
    </a>
</div>

<div class="staff-grid">
    <div class="staff-card">
        <div class="label">Orders today</div>
        <div class="value"><?php echo (int) $total_orders; ?></div>
    </div>
    <div class="staff-card">
        <div class="label">Open orders</div>
        <div class="value"><?php echo (int) $open_orders; ?></div>
    </div>
    <div class="staff-card">
        <div class="label">Dine-in tables</div>
        <div class="value"><?php echo (int) $dine_in_orders; ?></div>
    </div>
    <div class="staff-card">
        <div class="label">Active tables</div>
        <div class="value"><?php echo (int) $active_tables; ?></div>
    </div>
</div>

<div class="dashboard-actions">
    <a class="action-btn primary" href="admin/orders/take_order.php">
        <i class="fas fa-cash-register"></i>
        <span>Take Order</span>
    </a>
    <a class="action-btn" href="admin/orders/kiosk.php">
        <i class="fas fa-tablet-alt"></i>
        <span>Cashier Kiosk</span>
    </a>
    <a class="action-btn" href="admin/orders/table_map.php">
        <i class="fas fa-map-marked-alt"></i>
        <span>Table Map</span>
    </a>
    <a class="action-btn" href="admin/orders/index.php">
        <i class="fas fa-shopping-bag"></i>
        <span>All Orders</span>
    </a>
</div>

<div class="table-box">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
        <h3 style="margin:0; font-size:1.1rem;">Recent activity</h3>
        <a href="admin/orders/index.php" style="color:#d97706; font-weight:700; text-decoration:none;">View all</a>
    </div>

    <table class="activity-table">
        <thead>
            <tr>
                <th>Ref</th>
                <th>Customer</th>
                <th>Table</th>
                <th>Status</th>

                <!-- Embedded live table board -->
                <div class="table-box">
        </thead>
        <tbody>
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <a href="admin/orders/table_map.php" style="color:#d97706; font-weight:700; text-decoration:none; margin-right: 0.7rem;">Open table board</a>
                            <a href="admin/orders/kiosk.php" class="btn-submit" style="background:#0f172a; padding:0.5rem 0.7rem; color:#fff; text-decoration:none; border-radius:8px;">Kiosk</a>
                        </div>
                <tr>
                    <div style="display:grid; grid-template-columns: 1fr; gap:1rem;">
                        <?php
                        // small embedded table board: fetch tables and show status
                        $boards = $conn->query("SELECT rt.table_name, rt.seat_count, rt.status, rt.notes, o.order_reference, o.status as order_status, o.guest_count FROM restaurant_tables rt LEFT JOIN orders o ON o.table_number = rt.table_name AND o.status IN ('Placed','Preparing','On the Way') ORDER BY rt.table_name")->fetchAll();
                        if (empty($boards)) {
                            echo '<div style="color:#64748b;">No table configuration yet. Open full board to initialize tables.</div>';
                        } else {
                            echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:0.8rem;">';
                            foreach ($boards as $b) {
                                $s = $b['status'];
                                $pill = $s === 'occupied' ? 'badge-preparing' : ($s === 'reserved' ? 'badge-way' : 'badge-placed');
                                echo '<div style="background:#fff; border:1px solid #edf2f7; padding:0.8rem; border-radius:12px;">';
                                echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;"><strong>' . htmlspecialchars($b['table_name']) . '</strong><span class="' . $pill . '" style="padding:0.25rem 0.5rem; border-radius:8px;">' . htmlspecialchars(ucfirst($s)) . '</span></div>';
                                if (!empty($b['order_reference'])) {
                                    echo '<div style="color:#64748b; font-size:0.9rem;">' . htmlspecialchars($b['order_reference']) . ' • ' . htmlspecialchars($b['order_status']) . '</div>';
                                } else {
                                    echo '<div style="color:#64748b; font-size:0.9rem;">Seats: ' . (int)$b['seat_count'] . '</div>';
                                }
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
