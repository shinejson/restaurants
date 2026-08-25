<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$default_tables = ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4'];
$conn->exec("CREATE TABLE IF NOT EXISTS restaurant_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(20) NOT NULL UNIQUE,
    seat_count INT NOT NULL DEFAULT 4,
    status ENUM('available', 'occupied', 'reserved') NOT NULL DEFAULT 'available',
    notes VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

foreach ($default_tables as $table_name) {
    $exists = $conn->prepare("SELECT id FROM restaurant_tables WHERE table_name = ?");
    $exists->execute([$table_name]);
    if (!$exists->fetch()) {
        $conn->prepare("INSERT INTO restaurant_tables (table_name, seat_count, status) VALUES (?, ?, 'available')")->execute([$table_name, 4]);
    }
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $table_name = trim($_POST['table_name'] ?? '');
    $seat_count = max(1, (int) ($_POST['seat_count'] ?? 2));
    $status = isset($_POST['status']) && in_array($_POST['status'], ['available', 'occupied', 'reserved'], true) ? $_POST['status'] : 'available';
    $notes = trim($_POST['notes'] ?? '');

    if ($table_name !== '') {
        $update_stmt = $conn->prepare("UPDATE restaurant_tables SET seat_count = ?, status = ?, notes = ? WHERE table_name = ?");
        $update_stmt->execute([$seat_count, $status, $notes, $table_name]);
        $message = '<div class="alert success">Table status updated.</div>';
    }
}

$active_orders = [];
ensure_order_schema($conn);
$orders_stmt = $conn->query("SELECT table_number, order_reference, status, guest_count FROM orders WHERE order_type = 'dine_in' AND table_number IS NOT NULL AND table_number <> '' AND status IN ('Placed','Preparing','On the Way') ORDER BY created_at DESC");
foreach ($orders_stmt as $order) {
    $active_orders[$order['table_number']] = $order;
}

$tables = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_name ASC")->fetchAll();

$admin_title = 'Table Map';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    .table-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 1rem;
        margin-top: 1.5rem;
    }

    .table-card {
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        padding: 1rem;
        min-height: 220px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: #fff;
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.04);
    }

    .table-card.available {
        background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);
        border-color: #bbf7d0;
    }

    .table-card.occupied {
        background: linear-gradient(180deg, #fff7ed 0%, #ffffff 100%);
        border-color: #fed7aa;
    }

    .table-card.reserved {
        background: linear-gradient(180deg, #eff6ff 0%, #ffffff 100%);
        border-color: #bfdbfe;
    }

    .table-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
    }

    .table-name {
        font-size: 1.5rem;
        font-weight: 900;
        color: #111827;
    }

    .status-pill {
        display: inline-block;
        padding: 0.35rem 0.7rem;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .status-pill.available {
        background: #dcfce7;
        color: #166534;
    }

    .status-pill.occupied {
        background: #fff7ed;
        color: #9a4d00;
    }

    .status-pill.reserved {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .table-card p {
        margin: 0;
        line-height: 1.5;
        color: #475569;
        font-size: 0.88rem;
    }

    .table-form {
        display: grid;
        gap: 0.65rem;
        margin-top: 0.8rem;
    }

    .table-form .row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }

    .table-form select,
    .table-form input {
        width: 100%;
        padding: 0.6rem 0.7rem;
        border: 1px solid #dfe7ef;
        border-radius: 9px;
        font-size: 0.85rem;
        background: #fff;
    }

    .save-btn {
        border: none;
        background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
        color: white;
        border-radius: 10px;
        font-weight: 800;
        cursor: pointer;
        padding: 0.7rem 0.8rem;
    }

    .alert {
        padding: 0.85rem 1rem;
        border-radius: 10px;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .alert.success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }
    /* Flash animation for updated cards */
    .flash {
        animation: flashAnim 1s ease-in-out;
    }
    @keyframes flashAnim {
        0% { box-shadow: 0 0 0 0 rgba(249,115,22,0.0); }
        30% { box-shadow: 0 0 12px 4px rgba(249,115,22,0.18); }
        100% { box-shadow: 0 0 0 0 rgba(249,115,22,0.0); }
    }

    /* Toast messages */
    .toast-wrap {
        position: fixed;
        right: 20px;
        bottom: 20px;
        z-index: 9999;
        display: grid;
        gap: 8px;
        pointer-events: none;
    }
    .toast {
        pointer-events: auto;
        background: #111827;
        color: white;
        padding: 0.7rem 1rem;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(15,23,42,0.12);
        font-weight: 700;
        opacity: 0.98;
        transform: translateY(0);
        transition: transform 0.25s ease, opacity 0.25s ease;
    }
    .toast.success { background: linear-gradient(135deg,#10b981,#059669); }
    .toast.error { background: linear-gradient(135deg,#ef4444,#dc2626); }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;">
    <div>
        <h1 style="margin:0; font-size:2rem; font-weight:800;">Table Map</h1>
        <p style="margin:0.4rem 0 0; color:var(--text-muted);">Live overview of dine-in tables, seat count, and availability.</p>
    </div>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
        <a href="take_order.php" class="btn-submit" style="width:auto; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:0.5rem;">
            <i class="fas fa-plus"></i> New Table Order
        </a>
        <a href="index.php" class="btn-submit" style="width:auto; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:0.5rem; background:#0f172a; box-shadow:none;">
            <i class="fas fa-arrow-left"></i> Orders
        </a>
    </div>
</div>

<?php echo $message; ?>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap" aria-live="polite" aria-atomic="true"></div>

<div style="display:flex; gap:1.25rem; flex-wrap:wrap;">
    <div style="flex: 1 1 260px; min-width:260px;">
        <h3 style="margin:0 0 0.75rem;">Unassigned dine-in orders</h3>
        <div id="unassignedColumn" style="background:#fff; border:1px solid #edf2f7; border-radius:12px; padding:0.9rem; min-height:120px;">
            <?php
            $unassigned = $conn->query("SELECT id, order_reference, total, created_at FROM orders WHERE order_type='dine_in' AND (table_number IS NULL OR table_number = '') AND status IN ('Placed','Preparing') ORDER BY created_at ASC")->fetchAll();
            if (empty($unassigned)) {
                echo '<div style="color:#64748b;">No unassigned dine-in orders.</div>';
            } else {
                echo '<ul style="list-style:none; margin:0; padding:0; display:grid; gap:0.5rem;">';
                foreach ($unassigned as $u) {
                    echo '<li draggable="true" class="draggable-order" data-order-id="' . (int)$u['id'] . '" style="padding:0.6rem; border:1px solid #e6edf3; border-radius:8px; background:#fff; cursor:grab; display:flex; justify-content:space-between; align-items:center;">';
                    echo '<div><strong>' . htmlspecialchars($u['order_reference']) . '</strong><div style="font-size:0.85rem; color:#64748b;">' . format_currency($u['total']) . '</div></div>';
                    echo '<div style="font-size:0.85rem; color:#94a3b8;">Drag</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            ?>
        </div>
    </div>

    <div style="flex: 3 1 640px; min-width:320px;">
        <div class="table-grid">
    <?php foreach ($tables as $table): ?>
        <?php
        $table_name = $table['table_name'];
        $seat_count = (int)($table['seat_count'] ?? 4);
        $table_status = $table['status'];
        $live_order = $active_orders[$table_name] ?? null;
        $is_live_occupied = !empty($live_order);
        $display_status = $is_live_occupied ? 'occupied' : $table_status;
        $display_label = $display_status === 'occupied' ? 'Occupied' : ($display_status === 'reserved' ? 'Reserved' : 'Available');
        ?>
        <div draggable="true" class="table-card <?php echo $display_status; ?>" data-table-name="<?php echo htmlspecialchars($table_name); ?>">
            <div class="table-top">
                <span class="table-name"><?php echo htmlspecialchars($table_name); ?></span>
                <span class="status-pill <?php echo $display_status; ?>"><?php echo $display_label; ?></span>
            </div>

            <p>
                <?php if ($is_live_occupied): ?>
                    <strong>Live order:</strong> <?php echo htmlspecialchars($live_order['order_reference']); ?><br>
                    <strong>Status:</strong> <?php echo htmlspecialchars($live_order['status'] ?: 'Placed'); ?><br>
                    <strong>Guests:</strong> <?php echo (int) ($live_order['guest_count'] ?? 1); ?>
                <?php else: ?>
                    <strong>Seats:</strong> <?php echo $seat_count; ?><br>
                    <?php echo !empty($table['notes']) ? htmlspecialchars($table['notes']) : 'Ready for new seating.'; ?>
                <?php endif; ?>
            </p>

            <div class="table-form">
                <div class="row">
                    <input type="number" class="seat-count" min="1" max="20" value="<?php echo $seat_count; ?>" title="Seat count">
                    <select class="status-select">
                        <option value="available" <?php echo $table_status === 'available' ? 'selected' : ''; ?>>Available</option>
                        <option value="occupied" <?php echo $table_status === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                        <option value="reserved" <?php echo $table_status === 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                    </select>
                </div>

                <input type="text" class="notes-input" value="<?php echo htmlspecialchars($table['notes'] ?? ''); ?>" placeholder="Note / waiter / reservation">

                <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                    <button type="button" class="save-btn js-save-table">Save</button>
                    <button type="button" class="save-btn js-mark-clean" style="background:#10b981;">Mark Clean</button>
                </div>
            </div>

            <div style="margin-top:0.8rem;">
                <a href="take_order.php?table_number=<?php echo urlencode($table_name); ?>&order_type=dine_in" style="font-weight:800; color:#d97706; text-decoration:none;">
                    <?php echo $is_live_occupied ? 'Update order' : 'Open order'; ?>
                </a>
            </div>
        </div>
    <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>

<script>
// Drag-and-drop assignment and AJAX quick actions
const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

document.querySelectorAll('.draggable-order').forEach(item => {
    item.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', item.dataset.orderId);
        item.style.opacity = '0.6';
    });
    item.addEventListener('dragend', e => {
        item.style.opacity = '';
    });
});

document.querySelectorAll('.table-card').forEach(card => {
    card.addEventListener('dragover', e => {
        e.preventDefault();
        card.style.boxShadow = '0 8px 28px rgba(0,0,0,0.08)';
    });
    card.addEventListener('dragleave', e => {
        card.style.boxShadow = '';
    });
    card.addEventListener('drop', e => {
        e.preventDefault();
        card.style.boxShadow = '';
        const orderId = e.dataTransfer.getData('text/plain');
        const tableName = card.dataset.tableName;
        if (!orderId || !tableName) return;

        fetch('table_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'assign_order', csrf_token: csrfToken, order_id: orderId, table_name: tableName })
        }).then r => r.json()).then(json => {
            if (json.success) {
                showToast(json.message || 'Assigned', 'success');
                fetchBoard();
            } else {
                showToast(json.message || 'Failed to assign', 'error');
            }
        }).catch(err => showToast('Network error', 'error'));
    });
    // allow dragging table to unassign
    card.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', JSON.stringify({ table: card.dataset.tableName }));
        card.style.opacity = '0.6';
    });
    card.addEventListener('dragend', e => { card.style.opacity = ''; });
});

// Left column drop handler: dropping a table here will unassign the order and mark table available
const unassignedColumn = document.getElementById('unassignedColumn');
if (unassignedColumn) {
    unassignedColumn.addEventListener('dragover', e => { e.preventDefault(); unassignedColumn.style.borderColor = '#f97316'; });
    unassignedColumn.addEventListener('dragleave', e => { unassignedColumn.style.borderColor = '#edf2f7'; });
    unassignedColumn.addEventListener('drop', e => {
        e.preventDefault();
        unassignedColumn.style.borderColor = '#edf2f7';
        const raw = e.dataTransfer.getData('text/plain');
        if (!raw) return;
        try {
            const parsed = JSON.parse(raw);
            if (parsed.table) {
                const tableName = parsed.table;
                // non-blocking release with toast confirmation UI instead of native confirm
                if (!window.__release_confirmed) {
                    // show a toast that allows the user to click to confirm release
                    showToast(`Click to confirm release ${tableName}`, 'error', {
                        actionText: 'Release',
                        action: () => {
                            fetch('table_api.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({ action: 'unassign_order', csrf_token: csrfToken, table_name: tableName })
                            }).then(r => r.json()).then(json => {
                                if (json.success) { showToast(json.message || 'Released', 'success'); fetchBoard(); } else showToast(json.message || 'Failed to release', 'error');
                            }).catch(() => showToast('Network error', 'error'));
                        }
                    });
                } else {
                    // fallback immediate release
                    fetch('table_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'unassign_order', csrf_token: csrfToken, table_name: tableName })
                    }).then(r => r.json()).then(json => {
                        if (json.success) { showToast(json.message || 'Released', 'success'); fetchBoard(); } else showToast(json.message || 'Failed to release', 'error');
                    }).catch(() => showToast('Network error', 'error'));
                }
            }
        } catch (err) {
            // not a table drag; ignore
        }
    });
}

// Save table via AJAX
document.querySelectorAll('.js-save-table').forEach(btn => {
    btn.addEventListener('click', () => {
        const card = btn.closest('.table-card');
        const tableName = card.dataset.tableName;
        const seatCount = card.querySelector('.seat-count').value;
        const status = card.querySelector('.status-select').value;
        const notes = card.querySelector('.notes-input').value;

        fetch('table_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'update_table', csrf_token: csrfToken, table_name: tableName, seat_count: seatCount, status: status, notes: notes })
        }).then(r => r.json()).then(json => {
            if (json.success) { showToast(json.message || 'Saved', 'success'); fetchBoard(); } else showToast(json.message || 'Failed to save', 'error');
        }).catch(() => showToast('Network error', 'error'));
    });
});

// Mark cleaned/free
document.querySelectorAll('.js-mark-clean').forEach(btn => {
    btn.addEventListener('click', () => {
        const card = btn.closest('.table-card');
        const tableName = card.dataset.tableName;
        if (!confirm('Mark ' + tableName + ' as cleaned and available?')) return;
        fetch('table_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'mark_clean', csrf_token: csrfToken, table_name: tableName })
        }).then(r => r.json()).then(json => {
            if (json.success) { showToast(json.message || 'Marked clean', 'success'); fetchBoard(); } else showToast(json.message || 'Failed', 'error');
        }).catch(() => showToast('Network error', 'error'));
    });
});

// Polling: refresh board every N seconds
const POLL_INTERVAL = 10000; // 10s
let pollTimer = null;
function fetchBoard() {
    fetch('table_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'fetch_board', csrf_token: csrfToken })
    }).then(r => r.json()).then(json => {
        if (!json.success) return;
        renderBoard(json);
    }).catch(()=>{});
}

pollTimer = setInterval(fetchBoard, POLL_INTERVAL);
setTimeout(fetchBoard, 2000);
</script>

<script>
// Toast helper
function showToast(message, type = 'success', opts = {}) {
    const wrap = document.getElementById('toastWrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : 'success');
    el.textContent = message;
    if (opts.actionText && typeof opts.action === 'function') {
        const btn = document.createElement('button');
        btn.textContent = opts.actionText;
        btn.style.marginLeft = '12px';
        btn.style.background = 'transparent';
        btn.style.border = '1px solid rgba(255,255,255,0.2)';
        btn.style.color = 'white';
        btn.style.padding = '4px 8px';
        btn.style.borderRadius = '6px';
        btn.style.cursor = 'pointer';
        btn.addEventListener('click', () => { opts.action(); wrap.removeChild(el); });
        el.appendChild(btn);
    }
    wrap.appendChild(el);
    // auto remove
    setTimeout(() => { if (wrap.contains(el)) { el.style.opacity = '0'; setTimeout(()=>wrap.removeChild(el),250); } }, opts.ttl || 4500);
}
</script>

<script>
function renderBoard(json) {
    try {
        // Update unassigned list
        const unassignedEl = document.getElementById('unassignedColumn');
        if (unassignedEl) {
            if (json.unassigned && json.unassigned.length) {
                const listHtml = json.unassigned.map(u => `\
                    <li draggable="true" class="draggable-order" data-order-id="${u.id}" style="padding:0.6rem; border:1px solid #e6edf3; border-radius:8px; background:#fff; cursor:grab; display:flex; justify-content:space-between; align-items:center;">\
                        <div><strong>${escapeHtml(u.order_reference)}</strong><div style="font-size:0.85rem; color:#64748b;">${formatCurrency(u.total)}</div></div>\
                        <div style="font-size:0.85rem; color:#94a3b8;">Drag</div>\
                    </li>`).join('');
                unassignedEl.innerHTML = `<ul style="list-style:none; margin:0; padding:0; display:grid; gap:0.5rem;">${listHtml}</ul>`;
            } else {
                unassignedEl.innerHTML = '<div style="color:#64748b;">No unassigned dine-in orders.</div>';
            }
            // bind draggable events
            document.querySelectorAll('.draggable-order').forEach(item => {
                item.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', item.dataset.orderId); item.style.opacity = '0.6'; });
                item.addEventListener('dragend', e => { item.style.opacity = ''; });
            });
        }

        // Update tables
        if (json.tables && json.tables.length) {
            json.tables.forEach(server => {
                const card = document.querySelector(`.table-card[data-table-name="${server.table_name}"]`);
                if (!card) return;
                // update classes
                card.classList.remove('available','occupied','reserved');
                card.classList.add(server.status);
                const pill = card.querySelector('.status-pill');
                if (pill) {
                    pill.className = 'status-pill ' + server.status;
                    pill.textContent = server.status === 'occupied' ? 'Occupied' : (server.status === 'reserved' ? 'Reserved' : 'Available');
                }
                const seatInput = card.querySelector('.seat-count'); if (seatInput) seatInput.value = server.seat_count || 0;
                const notesInput = card.querySelector('.notes-input'); if (notesInput) notesInput.value = server.notes || '';
                const p = card.querySelector('p');
                if (p) {
                    if (server.order_reference) {
                        p.innerHTML = `<strong>Live order:</strong> ${escapeHtml(server.order_reference)}<br><strong>Status:</strong> ${escapeHtml(server.order_status || 'Placed')}<br><strong>Guests:</strong> ${parseInt(server.guest_count||1)}`;
                    } else {
                        p.innerHTML = `<strong>Seats:</strong> ${parseInt(server.seat_count||4)}<br>${server.notes?escapeHtml(server.notes):'Ready for new seating.'}`;
                    }
                }
            });
        }
    } catch (e) { console.error('renderBoard error', e); }
}

function escapeHtml(str) { if (!str) return ''; return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"})[s]); }
function formatCurrency(v){ return 'GH₵' + Number(v||0).toFixed(2); }
</script>

<?php
// Ensure required dine-in order columns exist
$columns = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
foreach (['order_type', 'table_number', 'room_number', 'guest_count'] as $column) {
    if (!in_array($column, $columns, true)) {
        $default = match ($column) {
            'order_type' => 'VARCHAR(20) NOT NULL DEFAULT "dine_in"',
            'table_number' => 'VARCHAR(50) NULL',
            'room_number' => 'VARCHAR(50) NULL',
            'guest_count' => 'INT NOT NULL DEFAULT 1',
            default => 'VARCHAR(50) NULL',
        };
        $conn->exec("ALTER TABLE orders ADD COLUMN $column $default");
    }
}
