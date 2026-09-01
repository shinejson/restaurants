<?php
// admin/printing/index.php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

require_permission('manage_settings');

// Ensure tables/settings exist (runs silently, idempotent)
$setup_check = $conn->query("SHOW TABLES LIKE 'printers'");
if (!$setup_check->fetch()) {
    header('Location: ../setup_printing_tables.php');
    exit();
}

// Handle form submissions
$alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_settings') {
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO settings (category, setting_key, setting_value) VALUES ('printing', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($_POST['settings'] as $key => $value) {
                $stmt->execute([$key, is_array($value) ? implode(',', $value) : $value]);
            }
            $conn->commit();
            $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Printing settings saved successfully.</div>';
        } elseif ($action === 'save_printer') {
            $printer_id = (int) ($_POST['printer_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $type = in_array($_POST['type'] ?? '', ['receipt', 'kot', 'bot']) ? $_POST['type'] : 'receipt';
            $connection_type = in_array($_POST['connection_type'] ?? '', ['network', 'usb', 'windows', 'cloud']) ? $_POST['connection_type'] : 'network';
            $ip_address = trim($_POST['ip_address'] ?? '');
            $port = max(1, (int) ($_POST['port'] ?? 9100));
            $paper_width = ($_POST['paper_width'] ?? '80mm') === '58mm' ? '58mm' : '80mm';
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $is_default = isset($_POST['is_default']) ? 1 : 0;

            if ($name === '') {
                throw new Exception('Printer name is required.');
            }

            // Only one default per type
            if ($is_default) {
                $conn->prepare("UPDATE printers SET is_default = 0 WHERE type = ?")->execute([$type]);
            }

            if ($printer_id > 0) {
                $stmt = $conn->prepare("UPDATE printers SET name=?, type=?, connection_type=?, ip_address=?, port=?, paper_width=?, is_default=?, status=? WHERE id=?");
                $stmt->execute([$name, $type, $connection_type, $ip_address, $port, $paper_width, $is_default, $status, $printer_id]);
                $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Printer updated successfully.</div>';
            } else {
                $stmt = $conn->prepare("INSERT INTO printers (name, type, connection_type, ip_address, port, paper_width, is_default, status) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$name, $type, $connection_type, $ip_address, $port, $paper_width, $is_default, $status]);
                $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Printer added successfully.</div>';
            }
        } elseif ($action === 'delete_printer') {
            $printer_id = (int) ($_POST['printer_id'] ?? 0);
            $conn->prepare("DELETE FROM printers WHERE id = ?")->execute([$printer_id]);
            $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Printer deleted.</div>';
        } elseif ($action === 'test_print') {
            $printer_id = (int) ($_POST['printer_id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM printers WHERE id = ?");
            $stmt->execute([$printer_id]);
            $test_printer = $stmt->fetch();

            if (!$test_printer) {
                throw new Exception('Printer not found.');
            }

            if ($test_printer['connection_type'] === 'network' && !empty($test_printer['ip_address'])) {
                $socket = @fsockopen($test_printer['ip_address'], (int) $test_printer['port'], $errno, $errstr, 3);
                if ($socket) {
                    // ESC @ (init) + test text + cut
                    $test_data = "\x1B@PRINTER TEST - " . $test_printer['name'] . "\n";
                    $test_data .= "Time: " . date('Y-m-d H:i:s') . "\n";
                    $test_data .= "If you can read this, the printer works!\n\n\n\n";
                    fwrite($socket, $test_data);
                    fclose($socket);
                    $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Test page sent to ' . htmlspecialchars($test_printer['name']) . '.</div>';
                } else {
                    $alert = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Could not connect to ' . htmlspecialchars($test_printer['name']) . ' (' . htmlspecialchars($errstr) . ').</div>';
                }
            } else {
                $alert = '<div class="alert alert-success"><i class="fas fa-info-circle"></i> Test queued for ' . htmlspecialchars($test_printer['connection_type']) . ' printer. Print from the connected workstation.</div>';
            }
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $alert = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Fetch printing settings
$settings_stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE category = 'printing'");
$p = [];
foreach ($settings_stmt->fetchAll() as $row) {
    $p[$row['setting_key']] = $row['setting_value'];
}

// Fetch printers
$printers = $conn->query("SELECT * FROM printers ORDER BY type ASC, name ASC")->fetchAll();

// Editing an existing printer?
$edit_printer = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM printers WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $edit_printer = $stmt->fetch();
}

$active_tab = in_array($_GET['tab'] ?? '', ['receipt', 'kot', 'bot', 'printers']) ? $_GET['tab'] : 'receipt';
$admin_title = 'Printing Configuration';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    .print-tabs {
        display: flex;
        gap: 0.25rem;
        border-bottom: 2px solid var(--border-color);
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .print-tab {
        padding: 0.9rem 1.5rem;
        text-decoration: none;
        color: var(--text-muted);
        font-weight: 700;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: var(--transition);
    }

    .print-tab:hover {
        color: var(--primary-color);
    }

    .print-tab.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
    }

    .print-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 2rem;
        max-width: 720px;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.1rem;
    }

    .form-group {
        margin-bottom: 1.1rem;
    }

    .form-group label {
        display: block;
        font-weight: 700;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin-bottom: 0.45rem;
    }

    .form-group input[type="text"],
    .form-group input[type="number"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.75rem 0.9rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 0.95rem;
        background: var(--white);
        color: var(--text-main);
    }

    .toggle-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.9rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .toggle-row:last-of-type {
        border-bottom: none;
    }

    .toggle-label {
        font-weight: 600;
    }

    .toggle-label small {
        display: block;
        color: var(--text-muted);
        font-weight: 400;
    }

    .switch {
        position: relative;
        width: 46px;
        height: 26px;
        flex-shrink: 0;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #cbd5e1;
        border-radius: 26px;
        transition: 0.3s;
    }

    .slider:before {
        content: "";
        position: absolute;
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background: white;
        border-radius: 50%;
        transition: 0.3s;
    }

    .switch input:checked+.slider {
        background: var(--primary-color);
    }

    .switch input:checked+.slider:before {
        transform: translateX(20px);
    }

    .btn-save {
        background: linear-gradient(135deg, var(--primary-color), #ea580c);
        color: white;
        border: none;
        padding: 0.9rem 2.2rem;
        border-radius: 10px;
        font-weight: 800;
        font-size: 1rem;
        cursor: pointer;
        margin-top: 1.5rem;
    }

    .btn-secondary {
        background: var(--light-bg);
        color: var(--text-main);
        border: 1px solid var(--border-color);
        padding: 0.6rem 1.2rem;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .btn-danger-sm {
        background: rgba(254, 226, 226, 0.9);
        color: #991b1b;
        border: none;
        padding: 0.5rem 0.9rem;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
    }

    .printer-table {
        width: 100%;
        border-collapse: collapse;
    }

    .printer-table th,
    .printer-table td {
        padding: 0.85rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.92rem;
    }

    .printer-table th {
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.05em;
    }

    .type-badge {
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .type-receipt {
        background: rgba(59, 130, 246, 0.12);
        color: #1d4ed8;
    }

    .type-kot {
        background: rgba(249, 115, 22, 0.12);
        color: #c2410c;
    }

    .type-bot {
        background: rgba(139, 92, 246, 0.12);
        color: #6d28d9;
    }

    .alert {
        padding: 0.9rem 1.2rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        font-weight: 600;
    }

    .alert-success {
        background: rgba(220, 252, 231, 0.9);
        color: #166534;
        border: 1px solid rgba(187, 247, 208, 0.9);
    }

    .alert-danger {
        background: rgba(254, 226, 226, 0.9);
        color: #991b1b;
        border: 1px solid rgba(254, 202, 202, 0.9);
    }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;">
    <div>
        <h1 style="margin:0; font-size:2rem; font-weight:800;"><i class="fas fa-print"></i> Printing Configuration</h1>
        <p style="margin:0.45rem 0 0; color:var(--text-muted);">Manage receipt, kitchen (KOT) and bar (BOT) ticket
            printing.</p>
    </div>
    <a href="<?php echo BASE_URL; ?>/admin/printing/terminals.php" class="btn-secondary">
        <i class="fas fa-desktop"></i> Terminals & Workspaces
    </a>
</div>

<?php echo $alert; ?>

<div class="print-tabs">
    <a href="?tab=receipt" class="print-tab <?php echo $active_tab == 'receipt' ? 'active' : ''; ?>">
        <i class="fas fa-receipt"></i> Receipt Printer
    </a>
    <a href="?tab=kot" class="print-tab <?php echo $active_tab == 'kot' ? 'active' : ''; ?>">
        <i class="fas fa-utensils"></i> Kitchen (KOT)
    </a>
    <a href="?tab=bot" class="print-tab <?php echo $active_tab == 'bot' ? 'active' : ''; ?>">
        <i class="fas fa-wine-glass-alt"></i> Bar (BOT)
    </a>
    <a href="?tab=printers" class="print-tab <?php echo $active_tab == 'printers' ? 'active' : ''; ?>">
        <i class="fas fa-print"></i> Printers
    </a>
    <a href="templates.php" class="print-tab">
        <i class="fas fa-file-invoice"></i> Templates
    </a>
</div>

<?php if ($active_tab !== 'printers'): ?>
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<?php endif; ?>

    <?php if ($active_tab === 'receipt'): ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="print-card">
            <div class="toggle-row">
                <div class="toggle-label">Enable Receipt Printing
                    <small>Print a customer receipt when an order is completed.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[receipt_enabled]" value="1"
                        <?php echo ($p['receipt_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-label">Auto-print on Order Save
                    <small>Send the receipt to the printer automatically.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[receipt_auto_print]" value="1"
                        <?php echo ($p['receipt_auto_print'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-label">Show Tax Summary
                    <small>Print the tax breakdown section on receipts.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[receipt_show_tax_summary]" value="1"
                        <?php echo ($p['receipt_show_tax_summary'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-label">Show Logo
                    <small>Print the restaurant logo at the top.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[receipt_show_logo]" value="1"
                        <?php echo ($p['receipt_show_logo'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>

            <div class="form-row" style="margin-top:1.5rem;">
                <div class="form-group">
                    <label>Copies per Receipt</label>
                    <input type="number" name="settings[receipt_copies]" min="1" max="5"
                        value="<?php echo htmlspecialchars($p['receipt_copies'] ?? '1'); ?>">
                </div>
                <div class="form-group">
                    <label>Paper Width</label>
                    <select name="settings[receipt_paper_width]">
                        <option value="80mm" <?php echo ($p['receipt_paper_width'] ?? '80mm') == '80mm' ? 'selected' : ''; ?>>80mm (Standard)</option>
                        <option value="58mm" <?php echo ($p['receipt_paper_width'] ?? '') == '58mm' ? 'selected' : ''; ?>>58mm (Compact)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Header Note</label>
                <input type="text" name="settings[receipt_header_note]"
                    value="<?php echo htmlspecialchars($p['receipt_header_note'] ?? ''); ?>"
                    placeholder="e.g. Thank you for dining with us!">
            </div>
            <div class="form-group">
                <label>Footer Note</label>
                <input type="text" name="settings[receipt_footer_note]"
                    value="<?php echo htmlspecialchars($p['receipt_footer_note'] ?? ''); ?>"
                    placeholder="e.g. Visit us again soon">
            </div>
        </div>

    <?php elseif ($active_tab === 'kot'): ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="print-card">
            <div class="toggle-row">
                <div class="toggle-label">Enable Kitchen Order Tickets (KOT)
                    <small>Send food orders to the kitchen printer.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[kot_enabled]" value="1"
                        <?php echo ($p['kot_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-label">Auto-print KOT
                    <small>Print automatically when an order is placed.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[kot_auto_print]" value="1"
                        <?php echo ($p['kot_auto_print'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-label">Show Special Requests
                    <small>Include customer notes on the ticket.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[kot_show_special_requests]" value="1"
                        <?php echo ($p['kot_show_special_requests'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>

            <div class="form-group" style="margin-top:1.5rem;">
                <label>Paper Width</label>
                <select name="settings[kot_paper_width]">
                    <option value="80mm" <?php echo ($p['kot_paper_width'] ?? '80mm') == '80mm' ? 'selected' : ''; ?>>80mm (Standard)</option>
                    <option value="58mm" <?php echo ($p['kot_paper_width'] ?? '') == '58mm' ? 'selected' : ''; ?>>58mm (Compact)</option>
                </select>
            </div>
            <div class="form-group">
                <label>KOT Header Note</label>
                <input type="text" name="settings[kot_header_note]"
                    value="<?php echo htmlspecialchars($p['kot_header_note'] ?? '*** KITCHEN ORDER ***'); ?>">
            </div>
        </div>

    <?php elseif ($active_tab === 'bot'): ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="print-card">
            <div class="toggle-row">
                <div class="toggle-label">Enable Bar Order Tickets (BOT)
                    <small>Send drink orders to the bar printer.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[bot_enabled]" value="1"
                        <?php echo ($p['bot_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-label">Auto-print BOT
                    <small>Print automatically when an order is placed.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" name="settings[bot_auto_print]" value="1"
                        <?php echo ($p['bot_auto_print'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>

            <div class="form-group" style="margin-top:1.5rem;">
                <label>Paper Width</label>
                <select name="settings[bot_paper_width]">
                    <option value="80mm" <?php echo ($p['bot_paper_width'] ?? '80mm') == '80mm' ? 'selected' : ''; ?>>80mm (Standard)</option>
                    <option value="58mm" <?php echo ($p['bot_paper_width'] ?? '') == '58mm' ? 'selected' : ''; ?>>58mm (Compact)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Bar Categories
                    <small style="display:block; font-weight:400; text-transform:none; letter-spacing:0; color:var(--text-muted);">Comma-separated
                        category names routed to the bar printer.</small>
                </label>
                <input type="text" name="settings[bot_categories]"
                    value="<?php echo htmlspecialchars($p['bot_categories'] ?? 'Drinks,Beverages'); ?>"
                    placeholder="Drinks,Beverages,Juices">
            </div>
            <div class="form-group">
                <label>BOT Header Note</label>
                <input type="text" name="settings[bot_header_note]"
                    value="<?php echo htmlspecialchars($p['bot_header_note'] ?? '*** BAR ORDER ***'); ?>">
            </div>
        </div>

    <?php elseif ($active_tab === 'printers'): ?>
        <div class="print-card" style="max-width:100%;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin:0; font-size:1.2rem;">Registered Printers</h2>
                <a href="?tab=printers" class="btn-secondary"><i class="fas fa-plus"></i> Add New Printer</a>
            </div>

            <div style="overflow-x:auto;">
                <table class="printer-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Connection</th>
                            <th>Address</th>
                            <th>Paper</th>
                            <th>Default</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($printers)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; color:var(--text-muted); padding:2rem;">
                                    No printers configured yet. Add your first printer below.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($printers as $printer): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($printer['name']); ?></strong></td>
                                    <td><span class="type-badge type-<?php echo $printer['type']; ?>"><?php echo strtoupper($printer['type']); ?></span></td>
                                    <td><?php echo ucfirst($printer['connection_type']); ?></td>
                                    <td><?php echo $printer['ip_address'] ? htmlspecialchars($printer['ip_address'] . ':' . $printer['port']) : '—'; ?></td>
                                    <td><?php echo $printer['paper_width']; ?></td>
                                    <td><?php echo $printer['is_default'] ? '<i class="fas fa-star" style="color:#f59e0b;"></i>' : '—'; ?></td>
                                    <td>
                                        <span style="color: <?php echo $printer['status'] == 'active' ? '#16a34a' : '#94a3b8'; ?>;">
                                            <i class="fas fa-circle" style="font-size:0.6rem;"></i>
                                            <?php echo ucfirst($printer['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:0.4rem;">
                                            <a href="?tab=printers&edit=<?php echo $printer['id']; ?>" class="btn-secondary" style="padding:0.4rem 0.8rem;" title="Edit"><i class="fas fa-edit"></i></a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this printer?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_printer">
                                                <input type="hidden" name="printer_id" value="<?php echo $printer['id']; ?>">
                                                <button type="submit" class="btn-danger-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="test_print">
                                                <input type="hidden" name="printer_id" value="<?php echo $printer['id']; ?>">
                                                <button type="submit" class="btn-secondary" style="padding:0.4rem 0.8rem;" title="Test Print"><i class="fas fa-vial"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add / Edit Printer Form -->
            <h3 style="margin:2rem 0 1rem; border-top:1px solid var(--border-color); padding-top:1.5rem;">
                <?php echo $edit_printer ? 'Edit Printer: ' . htmlspecialchars($edit_printer['name']) : 'Add New Printer'; ?>
            </h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="save_printer">
                <input type="hidden" name="printer_id" value="<?php echo $edit_printer['id'] ?? 0; ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Printer Name</label>
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <input type="text" name="name" id="printerNameText" required
                                value="<?php echo htmlspecialchars($edit_printer['name'] ?? ''); ?>"
                                placeholder="e.g. Kitchen EPSON" style="flex:1;">
                            <select id="printerNameSelect" style="display:none; flex:1; padding:0.75rem 0.9rem; border:1px solid var(--border-color); border-radius:10px; font-size:0.95rem; background:var(--white); color:var(--text-main);"></select>
                            <button type="button" id="detectPrintersBtn" class="btn-secondary" style="display:none; padding:0.65rem 0.9rem;" title="Re-scan installed Windows printers">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                        <small id="printerDetectNote" style="display:none; color:var(--text-muted); margin-top:0.3rem; display:none;">
                            Printers installed on the server (Windows) are listed automatically.
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type">
                            <option value="receipt" <?php echo ($edit_printer['type'] ?? '') == 'receipt' ? 'selected' : ''; ?>>Receipt</option>
                            <option value="kot" <?php echo ($edit_printer['type'] ?? '') == 'kot' ? 'selected' : ''; ?>>Kitchen (KOT)</option>
                            <option value="bot" <?php echo ($edit_printer['type'] ?? '') == 'bot' ? 'selected' : ''; ?>>Bar (BOT)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Connection</label>
                        <select name="connection_type" onchange="document.getElementById('netFields').style.display = this.value==='network' ? 'grid' : 'none';">
                            <?php foreach (['network' => 'Network (Ethernet/WiFi)', 'usb' => 'USB', 'windows' => 'Windows Printer Share', 'cloud' => 'Cloud Printer'] as $val => $label): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($edit_printer['connection_type'] ?? '') == $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row" id="netFields" style="<?php echo ($edit_printer['connection_type'] ?? 'network') === 'network' ? '' : 'display:none;'; ?>">
                    <div class="form-group">
                        <label>IP Address</label>
                        <input type="text" name="ip_address" value="<?php echo htmlspecialchars($edit_printer['ip_address'] ?? ''); ?>" placeholder="192.168.1.100">
                    </div>
                    <div class="form-group">
                        <label>Port</label>
                        <input type="number" name="port" value="<?php echo htmlspecialchars($edit_printer['port'] ?? '9100'); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Paper Width</label>
                        <select name="paper_width">
                            <option value="80mm" <?php echo ($edit_printer['paper_width'] ?? '80mm') == '80mm' ? 'selected' : ''; ?>>80mm</option>
                            <option value="58mm" <?php echo ($edit_printer['paper_width'] ?? '') == '58mm' ? 'selected' : ''; ?>>58mm</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="active" <?php echo ($edit_printer['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($edit_printer['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <label class="toggle-label" style="display:flex; align-items:center; gap:0.5rem; text-transform:none; font-size:0.95rem;">
                            <input type="checkbox" name="is_default" <?php echo ($edit_printer['is_default'] ?? 0) ? 'checked' : ''; ?>>
                            Set as default for its type
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> <?php echo $edit_printer ? 'Update Printer' : 'Add Printer'; ?>
                </button>
                <?php if ($edit_printer): ?>
                    <a href="?tab=printers" class="btn-secondary" style="margin-left:0.75rem;">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($active_tab !== 'printers'): ?>
        <button type="submit" class="btn-save">
            <i class="fas fa-save"></i> Save <?php echo strtoupper($active_tab); ?> Settings
        </button>
    </form>
    <?php endif; ?>

    <?php if ($active_tab === 'printers'): ?>
        <script>
            (function () {
                var connSelect = document.querySelector('select[name="connection_type"]');
                var textInput = document.getElementById('printerNameText');
                var selectEl = document.getElementById('printerNameSelect');
                var refreshBtn = document.getElementById('detectPrintersBtn');
                var note = document.getElementById('printerDetectNote');
                if (!connSelect || !textInput || !selectEl) return;

                var loaded = false;

                function setMode(mode) {
                    if (mode === 'windows') {
                        // Swap the text field for the Windows printer dropdown
                        textInput.style.display = 'none';
                        textInput.removeAttribute('name');
                        textInput.removeAttribute('required');
                        selectEl.style.display = '';
                        selectEl.setAttribute('name', 'name');
                        selectEl.setAttribute('required', 'required');
                        refreshBtn.style.display = '';
                        note.style.display = 'block';
                        loadPrinters(false);
                    } else {
                        selectEl.style.display = 'none';
                        selectEl.removeAttribute('name');
                        selectEl.removeAttribute('required');
                        textInput.style.display = '';
                        textInput.setAttribute('name', 'name');
                        textInput.setAttribute('required', 'required');
                        refreshBtn.style.display = 'none';
                        note.style.display = 'none';
                    }
                }

                function loadPrinters(force) {
                    if (loaded && !force) {
                        if (!selectEl.value && textInput.value) selectEl.value = textInput.value;
                        return;
                    }
                    selectEl.innerHTML = '<option value="">— Detecting installed printers… —</option>';
                    fetch('<?php echo BASE_URL; ?>/admin/printing/get_windows_printers.php')
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            selectEl.innerHTML = '';
                            if (data.printers && data.printers.length) {
                                var placeholder = document.createElement('option');
                                placeholder.value = '';
                                placeholder.textContent = '— Select a Windows printer (' + data.printers.length + ' found) —';
                                selectEl.appendChild(placeholder);
                                data.printers.forEach(function (p) {
                                    var opt = document.createElement('option');
                                    opt.value = p;
                                    opt.textContent = p;
                                    if (p === textInput.value) opt.selected = true;
                                    selectEl.appendChild(opt);
                                });
                            } else {
                                var empty = document.createElement('option');
                                empty.value = '';
                                empty.textContent = '— No printers detected on server —';
                                selectEl.appendChild(empty);
                            }
                            loaded = true;
                        })
                        .catch(function () {
                            selectEl.innerHTML = '<option value="">— Detection failed. Pick another connection or type the name manually. —</option>';
                        });
                }

                refreshBtn.addEventListener('click', function () { loadPrinters(true); });
                setMode(connSelect.value);
                connSelect.addEventListener('change', function () { setMode(this.value); });
            })();
        </script>
    <?php endif; ?>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
