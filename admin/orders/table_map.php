<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensure_order_schema($conn);
ensure_table_logs_schema($conn);
ensure_restaurant_tables_schema($conn);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_table') {
        $table_name = trim($_POST['table_name'] ?? '');
        $seat_count = max(1, (int) ($_POST['seat_count'] ?? 4));
        $status = in_array($_POST['status'] ?? '', ['available', 'occupied', 'reserved'], true) ? $_POST['status'] : 'available';
        $notes = trim($_POST['notes'] ?? '');

        if (!$table_name) {
            $message = '<div class="alert error">Table name is required.</div>';
        } else {
            try {
                $check = $conn->prepare("SELECT id FROM restaurant_tables WHERE table_name = ?");
                $check->execute([$table_name]);
                if ($check->fetch()) {
                    $message = '<div class="alert error">Table name already exists.</div>';
                } else {
                    $qr_url = BASE_URL . '/customer_order.php?table_name=' . urlencode($table_name);
                    $insert = $conn->prepare("INSERT INTO restaurant_tables (table_name, seat_count, status, notes, qr_code) VALUES (?, ?, ?, ?, ?)");
                    $insert->execute([$table_name, $seat_count, $status, $notes, $qr_url]);
                    $table_id = $conn->lastInsertId();

                    for ($i = 1; $i <= $seat_count; $i++) {
                        $seat_stmt = $conn->prepare("INSERT INTO restaurant_seats (table_id, seat_name, seat_number) VALUES (?, ?, ?)");
                        $seat_stmt->execute([$table_id, 'Seat ' . $i, $i]);
                    }

                    $message = '<div class="alert success">Table ' . htmlspecialchars($table_name) . ' created with ' . $seat_count . ' seats.</div>';
                }
            } catch (Exception $e) {
                $message = '<div class="alert error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    } elseif ($action === 'edit_table') {
        $table_id = (int) ($_POST['table_id'] ?? 0);
        $table_name = trim($_POST['table_name'] ?? '');
        $seat_count = max(1, (int) ($_POST['seat_count'] ?? 4));
        $status = in_array($_POST['status'] ?? '', ['available', 'occupied', 'reserved'], true) ? $_POST['status'] : 'available';
        $notes = trim($_POST['notes'] ?? '');

        if ($table_id > 0 && $table_name !== '') {
            try {
                $qr_url = BASE_URL . '/customer_order.php?table_name=' . urlencode($table_name);
                $stmt = $conn->prepare("UPDATE restaurant_tables SET table_name = ?, seat_count = ?, status = ?, notes = ?, qr_code = ? WHERE id = ?");
                $stmt->execute([$table_name, $seat_count, $status, $notes, $qr_url, $table_id]);

                // Sync seat count if increased
                $existing_seats = $conn->prepare("SELECT COUNT(*) FROM restaurant_seats WHERE table_id = ?");
                $existing_seats->execute([$table_id]);
                $current_count = (int) $existing_seats->fetchColumn();

                if ($current_count < $seat_count) {
                    for ($i = $current_count + 1; $i <= $seat_count; $i++) {
                        $seat_stmt = $conn->prepare("INSERT INTO restaurant_seats (table_id, seat_name, seat_number) VALUES (?, ?, ?)");
                        $seat_stmt->execute([$table_id, 'Seat ' . $i, $i]);
                    }
                }
                $message = '<div class="alert success">Table details updated successfully.</div>';
            } catch (Exception $e) {
                $message = '<div class="alert error">Error updating table: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    } elseif ($action === 'delete_table') {
        $table_id = (int) ($_POST['table_id'] ?? 0);
        if ($table_id > 0) {
            try {
                $conn->prepare("DELETE FROM restaurant_tables WHERE id = ?")->execute([$table_id]);
                $message = '<div class="alert success">Table deleted successfully.</div>';
            } catch (Exception $e) {
                $message = '<div class="alert error">Error deleting table.</div>';
            }
        }
    } elseif ($action === 'update_seats') {
        $table_id = (int) ($_POST['table_id'] ?? 0);
        $seat_names = isset($_POST['seat_names']) && is_array($_POST['seat_names']) ? $_POST['seat_names'] : [];

        if ($table_id > 0) {
            try {
                $conn->prepare("DELETE FROM restaurant_seats WHERE table_id = ?")->execute([$table_id]);
                $seat_insert = $conn->prepare("INSERT INTO restaurant_seats (table_id, seat_name, seat_number) VALUES (?, ?, ?)");

                foreach ($seat_names as $index => $seat_name) {
                    $name = trim($seat_name) !== '' ? trim($seat_name) : 'Seat ' . ($index + 1);
                    $seat_insert->execute([$table_id, $name, ($index + 1)]);
                }

                $new_count = count($seat_names);
                $conn->prepare("UPDATE restaurant_tables SET seat_count = ? WHERE id = ?")->execute([$new_count, $table_id]);
                $message = '<div class="alert success">Seats updated successfully.</div>';
            } catch (Exception $e) {
                $message = '<div class="alert error">Error updating seats.</div>';
            }
        }
    }
}

// Fetch active orders mapped to tables
$active_orders = [];
$orders_stmt = $conn->query("SELECT table_number, order_reference, status, guest_count FROM orders WHERE order_type = 'dine_in' AND table_number IS NOT NULL AND table_number <> '' AND status IN ('Placed','Preparing','On the Way') ORDER BY created_at DESC");
foreach ($orders_stmt as $order) {
    $active_orders[$order['table_number']] = $order;
}

// Fetch tables with seats
$tables = $conn->query("SELECT * FROM restaurant_tables ORDER BY table_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Map seats per table
$all_seats = [];
$seat_stmt = $conn->query("SELECT * FROM restaurant_seats ORDER BY table_id ASC, seat_number ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($seat_stmt as $seat) {
    $all_seats[$seat['table_id']][] = $seat;
}

$admin_title = 'Table Map & QR Management';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    .table-map-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }

    .table-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1.25rem;
        margin-top: 1.5rem;
    }

    .table-card {
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        padding: 1.2rem;
        min-height: 260px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: #fff;
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.04);
        position: relative;
        transition: all 0.2s ease;
    }

    .table-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 30px rgba(15, 23, 42, 0.08);
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

    .status-pill.available { background: #dcfce7; color: #166534; }
    .status-pill.occupied { background: #fff7ed; color: #9a4d00; }
    .status-pill.reserved { background: #dbeafe; color: #1d4ed8; }

    .seat-tags-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        margin: 0.6rem 0;
    }

    .seat-tag {
        font-size: 0.72rem;
        font-weight: 700;
        background: #f1f5f9;
        color: #475569;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        border: 1px solid #e2e8f0;
    }

    .table-actions-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem;
        margin-top: 0.8rem;
    }

    .action-btn {
        padding: 0.5rem 0.4rem;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.78rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        transition: all 0.15s ease;
    }

    .action-btn.edit { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
    .action-btn.edit:hover { background: #e2e8f0; }

    .action-btn.seats { background: #0f172a; color: white; }
    .action-btn.seats:hover { background: #1e293b; }

    .action-btn.qr { background: #2563eb; color: white; }
    .action-btn.qr:hover { background: #1d4ed8; }

    .action-btn.delete { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
    .action-btn.delete:hover { background: #fca5a5; }

    /* Modals */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100vw; height: 100vh;
        background: rgba(15, 23, 42, 0.5);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.show { display: flex; }

    .modal-card {
        background: #ffffff;
        border-radius: 16px;
        width: 480px;
        max-width: 92%;
        padding: 1.5rem;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-card h3 {
        margin: 0 0 1rem;
        font-size: 1.15rem;
        color: #0f172a;
    }

    .modal-input {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.9rem;
        margin-bottom: 0.75rem;
    }

    .alert {
        padding: 0.85rem 1rem;
        border-radius: 10px;
        margin-bottom: 1rem;
        font-weight: 600;
    }
    .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
</style>

<div class="table-map-header">
    <div>
        <h1 style="margin:0; font-size:1.8rem; font-weight:800;">Table Map & QR Management</h1>
        <p style="margin:0.4rem 0 0; color:var(--text-muted);">Manage restaurant tables, seats, live statuses, and generate QR code ordering scanners.</p>
    </div>
    <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
        <button type="button" class="btn-submit" onclick="openAddTableModal()" style="width:auto; display:inline-flex; align-items:center; gap:0.4rem; background:#2563eb;">
            <i class="fas fa-plus"></i> Add New Table
        </button>
        <a href="cleaning_log.php" class="btn-submit" style="width:auto; text-decoration:none; display:inline-flex; align-items:center; gap:0.4rem; background:#64748b;">
            <i class="fas fa-broom"></i> Cleaning Log
        </a>
        <a href="take_order.php" class="btn-submit" style="width:auto; text-decoration:none; display:inline-flex; align-items:center; gap:0.4rem;">
            <i class="fas fa-cash-register"></i> New Table Order
        </a>
    </div>
</div>

<?php echo $message; ?>

<div style="display:flex; gap:1.25rem; flex-wrap:wrap;">
    <!-- Unassigned Dine-in Orders Sidebar -->
    <div style="flex: 1 1 260px; min-width:260px;">
        <h3 style="margin:0 0 0.75rem; font-size:1.05rem;">Unassigned Dine-In Orders</h3>
        <div id="unassignedColumn" style="background:#fff; border:1px solid #edf2f7; border-radius:12px; padding:0.9rem; min-height:140px;">
            <?php
            $unassigned = $conn->query("SELECT id, order_reference, total, created_at FROM orders WHERE order_type='dine_in' AND (table_number IS NULL OR table_number = '') AND status IN ('Placed','Preparing') ORDER BY created_at ASC")->fetchAll();
            if (empty($unassigned)) {
                echo '<div style="color:#64748b; font-size:0.85rem;">No unassigned dine-in orders.</div>';
            } else {
                echo '<ul style="list-style:none; margin:0; padding:0; display:grid; gap:0.5rem;">';
                foreach ($unassigned as $u) {
                    echo '<li draggable="true" class="draggable-order" data-order-id="' . (int)$u['id'] . '" style="padding:0.65rem; border:1px solid #e6edf3; border-radius:8px; background:#fff; cursor:grab; display:flex; justify-content:space-between; align-items:center;">';
                    echo '<div><strong>' . htmlspecialchars($u['order_reference']) . '</strong><div style="font-size:0.85rem; color:#64748b;">' . format_currency($u['total']) . '</div></div>';
                    echo '<div style="font-size:0.8rem; color:#2563eb; font-weight:700;">Drag to Table</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            ?>
        </div>
    </div>

    <!-- Tables Grid -->
    <div style="flex: 3 1 640px; min-width:320px;">
        <div class="table-grid">
            <?php foreach ($tables as $table): ?>
                <?php
                $table_id = (int)$table['id'];
                $table_name = $table['table_name'];
                $seat_count = (int)($table['seat_count'] ?? 4);
                $table_status = $table['status'];
                $notes = $table['notes'] ?? '';
                $qr_url = BASE_URL . '/customer_order.php?table_name=' . urlencode($table_name);
                
                $live_order = $active_orders[$table_name] ?? null;
                $is_live_occupied = !empty($live_order);
                $display_status = $is_live_occupied ? 'occupied' : $table_status;
                $display_label = $display_status === 'occupied' ? 'Occupied' : ($display_status === 'reserved' ? 'Reserved' : 'Available');

                $table_seats = $all_seats[$table_id] ?? [];
                ?>
                <div draggable="true" class="table-card <?php echo $display_status; ?>" data-table-name="<?php echo htmlspecialchars($table_name); ?>" data-table-id="<?php echo $table_id; ?>">
                    <div>
                        <div class="table-top">
                            <span class="table-name"><?php echo htmlspecialchars($table_name); ?></span>
                            <span class="status-pill <?php echo $display_status; ?>"><?php echo $display_label; ?></span>
                        </div>

                        <!-- Live Order or Table Info -->
                        <div style="margin: 0.6rem 0; font-size: 0.85rem; color: #475569;">
                            <?php if ($is_live_occupied): ?>
                                <div style="color: #9a4d00; font-weight: 700;">
                                    <i class="fas fa-utensils"></i> Live Order: <?php echo htmlspecialchars($live_order['order_reference']); ?>
                                </div>
                                <div>Status: <strong><?php echo htmlspecialchars($live_order['status'] ?: 'Placed'); ?></strong></div>
                            <?php else: ?>
                                <div><strong>Available Seats:</strong> <?php echo $seat_count; ?></div>
                                <div style="font-size:0.8rem; color:#64748b; margin-top:0.2rem;"><?php echo !empty($notes) ? htmlspecialchars($notes) : 'Ready for seating'; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Seat Name Tags -->
                        <div class="seat-tags-wrap">
                            <?php if (!empty($table_seats)): ?>
                                <?php foreach ($table_seats as $seat): ?>
                                    <span class="seat-tag"><i class="fas fa-chair" style="font-size:0.65rem;"></i> <?php echo htmlspecialchars($seat['seat_name']); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="seat-tag">No custom seats defined</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <!-- Quick Actions Grid -->
                        <div class="table-actions-grid">
                            <button type="button" class="action-btn edit" onclick="openEditTableModal(<?php echo $table_id; ?>, '<?php echo htmlspecialchars(addslashes($table_name)); ?>', <?php echo $seat_count; ?>, '<?php echo $table_status; ?>', '<?php echo htmlspecialchars(addslashes($notes)); ?>')">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="action-btn seats" onclick="openSeatsModal(<?php echo $table_id; ?>, '<?php echo htmlspecialchars(addslashes($table_name)); ?>')">
                                <i class="fas fa-chair"></i> Seats
                            </button>
                            <button type="button" class="action-btn qr" onclick="openQrModal('<?php echo htmlspecialchars(addslashes($table_name)); ?>', '<?php echo htmlspecialchars(addslashes($qr_url)); ?>')">
                                <i class="fas fa-qrcode"></i> QR Scanner
                            </button>
                            <button type="button" class="action-btn delete" onclick="confirmDeleteTable(<?php echo $table_id; ?>, '<?php echo htmlspecialchars(addslashes($table_name)); ?>')">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        </div>

                        <div style="margin-top:0.6rem; display:flex; justify-content:space-between; align-items:center;">
                            <a href="take_order.php?table_number=<?php echo urlencode($table_name); ?>&order_type=dine_in" style="font-weight:700; color:#d97706; text-decoration:none; font-size:0.85rem;">
                                <?php echo $is_live_occupied ? 'Update Order' : 'Take Order'; ?> <i class="fas fa-arrow-right"></i>
                            </a>
                            <?php if ($is_live_occupied): ?>
                                <button type="button" onclick="markTableClean('<?php echo htmlspecialchars(addslashes($table_name)); ?>')" style="background:none; border:none; color:#16a34a; font-weight:700; font-size:0.8rem; cursor:pointer;">
                                    Mark Clean
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal 1: Add New Table -->
<div class="modal-overlay" id="addTableModal">
    <div class="modal-card">
        <h3><i class="fas fa-plus-circle" style="color:#2563eb;"></i> Add New Restaurant Table</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="create_table">

            <label class="form-label">Table Name / Number</label>
            <input type="text" name="table_name" class="modal-input" placeholder="e.g. A5, VIP-1, T10" required>

            <label class="form-label">Available Seat Count</label>
            <input type="number" name="seat_count" class="modal-input" min="1" max="50" value="4" required>

            <label class="form-label">Table Status</label>
            <select name="status" class="modal-input">
                <option value="available">Available</option>
                <option value="occupied">Occupied</option>
                <option value="reserved">Reserved</option>
            </select>

            <label class="form-label">Notes / Location Info</label>
            <input type="text" name="notes" class="modal-input" placeholder="e.g. Patio section, window view">

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn-submit" onclick="closeModal('addTableModal')" style="background:#64748b;">Cancel</button>
                <button type="submit" class="btn-submit" style="background:#2563eb;">Create Table</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Edit Table -->
<div class="modal-overlay" id="editTableModal">
    <div class="modal-card">
        <h3><i class="fas fa-edit" style="color:#0f172a;"></i> Edit Table Details</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="edit_table">
            <input type="hidden" name="table_id" id="editTableId">

            <label class="form-label">Table Name</label>
            <input type="text" name="table_name" id="editTableName" class="modal-input" required>

            <label class="form-label">Seat Count</label>
            <input type="number" name="seat_count" id="editSeatCount" class="modal-input" min="1" max="50" required>

            <label class="form-label">Status</label>
            <select name="status" id="editStatus" class="modal-input">
                <option value="available">Available</option>
                <option value="occupied">Occupied</option>
                <option value="reserved">Reserved</option>
            </select>

            <label class="form-label">Notes</label>
            <input type="text" name="notes" id="editNotes" class="modal-input">

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn-submit" onclick="closeModal('editTableModal')" style="background:#64748b;">Cancel</button>
                <button type="submit" class="btn-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Manage Seats for Table -->
<div class="modal-overlay" id="manageSeatsModal">
    <div class="modal-card">
        <h3><i class="fas fa-chair" style="color:#0f172a;"></i> Manage Seats - <span id="seatsModalTableName">Table A1</span></h3>
        <form method="POST" id="seatsForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="update_seats">
            <input type="hidden" name="table_id" id="seatsModalTableId">

            <p style="font-size:0.85rem; color:#64748b; margin-top:0;">Customize each available seat name for guests ordering at this table.</p>

            <div id="seatsInputsContainer" style="display:grid; gap:0.5rem; max-height:280px; overflow-y:auto; margin-bottom:1rem;">
                <!-- Seat Inputs Loaded Dynamically -->
            </div>

            <button type="button" class="btn-submit" onclick="addNewSeatInput()" style="background:#f1f5f9; color:#0f172a; border:1px solid #cbd5e1; width:100%; margin-bottom:1rem;">
                <i class="fas fa-plus"></i> Add Another Seat
            </button>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn-submit" onclick="closeModal('manageSeatsModal')" style="background:#64748b;">Cancel</button>
                <button type="submit" class="btn-submit">Save Seats</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 4: View / Print QR Code -->
<div class="modal-overlay" id="qrCodeModal">
    <div class="modal-card" style="text-align:center;">
        <h3><i class="fas fa-qrcode" style="color:#2563eb;"></i> Table QR Scanner - <span id="qrModalTableName">A1</span></h3>

        <div id="printableQrArea" style="background:#ffffff; padding:1.5rem; border:2px solid #e2e8f0; border-radius:12px; display:inline-block; margin-bottom:1rem;">
            <div style="font-weight:900; font-size:1.4rem; color:#0f172a; margin-bottom:0.4rem;">SCAN TO ORDER</div>
            <div style="font-size:0.85rem; color:#64748b; margin-bottom:1rem;">Airport West Hotel • Table <span id="qrCardTableTitle">A1</span></div>
            <img id="qrCodeImg" src="" alt="Table QR Code" style="width:200px; height:200px; border-radius:8px;">
            <div style="font-size:0.75rem; color:#94a3b8; margin-top:0.8rem;">Point phone camera to view menu & order from your seat</div>
        </div>

        <div>
            <input type="text" id="qrCodeUrlInput" class="modal-input" readonly style="text-align:center; font-family:monospace; font-size:0.8rem;">
        </div>

        <div style="display:flex; justify-content:center; gap:0.5rem; margin-top:0.5rem;">
            <button type="button" class="btn-submit" onclick="copyQrLink()" style="background:#64748b;"><i class="fas fa-copy"></i> Copy Link</button>
            <button type="button" class="btn-submit" onclick="printQrCard()" style="background:#2563eb;"><i class="fas fa-print"></i> Print Table Card</button>
            <button type="button" class="btn-submit" onclick="closeModal('qrCodeModal')" style="background:#0f172a;">Close</button>
        </div>
    </div>
</div>

<!-- Modal 5: Delete Table Confirmation -->
<div class="modal-overlay" id="deleteTableModal">
    <div class="modal-card">
        <h3 style="color:#dc2626;"><i class="fas fa-exclamation-triangle"></i> Delete Table Confirmation</h3>
        <p>Are you sure you want to delete <strong id="deleteTableName">Table A1</strong>? All associated seat configurations will also be permanently deleted.</p>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="delete_table">
            <input type="hidden" name="table_id" id="deleteTableId">

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" class="btn-submit" onclick="closeModal('deleteTableModal')" style="background:#64748b;">Cancel</button>
                <button type="submit" class="btn-submit" style="background:#dc2626;">Delete Table</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

function openAddTableModal() {
    document.getElementById('addTableModal').classList.add('show');
}

function openEditTableModal(id, name, seats, status, notes) {
    document.getElementById('editTableId').value = id;
    document.getElementById('editTableName').value = name;
    document.getElementById('editSeatCount').value = seats;
    document.getElementById('editStatus').value = status;
    document.getElementById('editNotes').value = notes;
    document.getElementById('editTableModal').classList.add('show');
}

function confirmDeleteTable(id, name) {
    document.getElementById('deleteTableId').value = id;
    document.getElementById('deleteTableName').textContent = 'Table ' + name;
    document.getElementById('deleteTableModal').classList.add('show');
}

function openSeatsModal(tableId, tableName) {
    document.getElementById('seatsModalTableId').value = tableId;
    document.getElementById('seatsModalTableName').textContent = 'Table ' + tableName;
    const container = document.getElementById('seatsInputsContainer');
    container.innerHTML = '<div style="color:#64748b; font-size:0.85rem;">Loading seats...</div>';

    fetch('table_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_seats', csrf_token: csrfToken, table_id: tableId })
    })
    .then(r => r.json())
    .then(json => {
        if (json.success && json.seats && json.seats.length) {
            container.innerHTML = json.seats.map((s, idx) => `
                <div style="display:flex; gap:0.4rem; align-items:center;">
                    <span style="font-weight:700; font-size:0.85rem; width:65px;">Seat ${idx + 1}:</span>
                    <input type="text" name="seat_names[]" value="${escapeHtml(s.seat_name)}" class="modal-input" style="margin-bottom:0;" placeholder="e.g. Window Seat, Seat 1">
                    <button type="button" onclick="this.parentElement.remove()" style="background:#fee2e2; color:#dc2626; border:none; border-radius:6px; padding:0.5rem; cursor:pointer;">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `).join('');
        } else {
            container.innerHTML = `
                <div style="display:flex; gap:0.4rem; align-items:center;">
                    <span style="font-weight:700; font-size:0.85rem; width:65px;">Seat 1:</span>
                    <input type="text" name="seat_names[]" value="Seat 1" class="modal-input" style="margin-bottom:0;">
                </div>
                <div style="display:flex; gap:0.4rem; align-items:center;">
                    <span style="font-weight:700; font-size:0.85rem; width:65px;">Seat 2:</span>
                    <input type="text" name="seat_names[]" value="Seat 2" class="modal-input" style="margin-bottom:0;">
                </div>
            `;
        }
        document.getElementById('manageSeatsModal').classList.add('show');
    })
    .catch(() => {
        alert('Failed to load seats.');
    });
}

function addNewSeatInput() {
    const container = document.getElementById('seatsInputsContainer');
    const idx = container.children.length + 1;
    const div = document.createElement('div');
    div.style.display = 'flex';
    div.style.gap = '0.4rem';
    div.style.alignItems = 'center';
    div.innerHTML = `
        <span style="font-weight:700; font-size:0.85rem; width:65px;">Seat ${idx}:</span>
        <input type="text" name="seat_names[]" value="Seat ${idx}" class="modal-input" style="margin-bottom:0;" placeholder="Seat Name">
        <button type="button" onclick="this.parentElement.remove()" style="background:#fee2e2; color:#dc2626; border:none; border-radius:6px; padding:0.5rem; cursor:pointer;">
            <i class="fas fa-trash"></i>
        </button>
    `;
    container.appendChild(div);
}

function openQrModal(tableName, qrUrl) {
    document.getElementById('qrModalTableName').textContent = tableName;
    document.getElementById('qrCardTableTitle').textContent = tableName;
    document.getElementById('qrCodeUrlInput').value = qrUrl;

    const qrImgSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(qrUrl);
    document.getElementById('qrCodeImg').src = qrImgSrc;
    document.getElementById('qrCodeModal').classList.add('show');
}

function copyQrLink() {
    const urlInput = document.getElementById('qrCodeUrlInput');
    urlInput.select();
    document.execCommand('copy');
    alert('Table QR Order Link copied to clipboard!');
}

function printQrCard() {
    const area = document.getElementById('printableQrArea').innerHTML;
    const win = window.open('', '_blank', 'width=600,height=600');
    win.document.write(`
        <html>
        <head>
            <title>Table QR Card</title>
            <style>
                body { font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
            </style>
        </head>
        <body>
            <div style="border: 3px solid #0f172a; padding: 2rem; border-radius: 16px; width: 300px;">
                ${area}
            </div>
            <script>window.onload = function() { window.print(); window.close(); }<\/script>
        </body>
        </html>
    `);
    win.document.close();
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function markTableClean(tableName) {
    if (!confirm('Mark table ' + tableName + ' as cleaned and available?')) return;
    fetch('table_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'mark_clean', csrf_token: csrfToken, table_name: tableName })
    })
    .then(r => r.json())
    .then(json => {
        if (json.success) location.reload();
        else alert(json.message || 'Error');
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"})[s]);
}
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
