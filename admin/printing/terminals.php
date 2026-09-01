<?php
// admin/printing/terminals.php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

require_permission('manage_settings');

// Ensure tables exist
$setup_check = $conn->query("SHOW TABLES LIKE 'terminals'");
if (!$setup_check->fetch()) {
    header('Location: ../setup_printing_tables.php');
    exit();
}

$alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_terminal') {
            $terminal_id = (int) ($_POST['terminal_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $terminal_code = trim($_POST['terminal_code'] ?? '');
            $receipt_printer_id = (int) ($_POST['receipt_printer_id'] ?? 0) ?: null;
            $kot_printer_id = (int) ($_POST['kot_printer_id'] ?? 0) ?: null;
            $bot_printer_id = (int) ($_POST['bot_printer_id'] ?? 0) ?: null;
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            if ($name === '') {
                throw new Exception('Terminal name is required.');
            }

            if ($terminal_id > 0) {
                $stmt = $conn->prepare("UPDATE terminals SET name=?, location=?, terminal_code=?, receipt_printer_id=?, kot_printer_id=?, bot_printer_id=?, status=? WHERE id=?");
                $stmt->execute([$name, $location, $terminal_code, $receipt_printer_id, $kot_printer_id, $bot_printer_id, $status, $terminal_id]);
                $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Terminal updated successfully.</div>';
            } else {
                $stmt = $conn->prepare("INSERT INTO terminals (name, location, terminal_code, receipt_printer_id, kot_printer_id, bot_printer_id, status) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$name, $location, $terminal_code, $receipt_printer_id, $kot_printer_id, $bot_printer_id, $status]);
                $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Terminal added successfully.</div>';
            }
        } elseif ($action === 'delete_terminal') {
            $terminal_id = (int) ($_POST['terminal_id'] ?? 0);
            $conn->prepare("DELETE FROM terminals WHERE id = ?")->execute([$terminal_id]);
            $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Terminal deleted.</div>';
        }
    } catch (Exception $e) {
        $alert = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Fetch terminals with printer names
$terminals = $conn->query("
    SELECT t.*,
        rp.name AS receipt_printer,
        kp.name AS kot_printer,
        bp.name AS bot_printer
    FROM terminals t
    LEFT JOIN printers rp ON t.receipt_printer_id = rp.id
    LEFT JOIN printers kp ON t.kot_printer_id = kp.id
    LEFT JOIN printers bp ON t.bot_printer_id = bp.id
    ORDER BY t.created_at ASC
")->fetchAll();

// Fetch printers for dropdowns
$printers = $conn->query("SELECT id, name, type FROM printers WHERE status = 'active' ORDER BY type, name")->fetchAll();

// Editing?
$edit_terminal = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM terminals WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $edit_terminal = $stmt->fetch();
}

$admin_title = 'Terminals & Workspaces';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    .terminal-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 2rem;
    }

    .terminal-table {
        width: 100%;
        border-collapse: collapse;
    }

    .terminal-table th,
    .terminal-table td {
        padding: 0.85rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.92rem;
        vertical-align: middle;
    }

    .terminal-table th {
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.05em;
    }

    .printer-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        background: var(--light-bg);
        border: 1px solid var(--border-color);
        margin: 2px;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
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

    .form-group input,
    .form-group select {
        width: 100%;
        padding: 0.75rem 0.9rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 0.95rem;
        background: var(--white);
        color: var(--text-main);
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
        margin-top: 1rem;
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
        <h1 style="margin:0; font-size:2rem; font-weight:800;"><i class="fas fa-desktop"></i> Terminals &
            Workspaces</h1>
        <p style="margin:0.45rem 0 0; color:var(--text-muted);">Register POS workstations and assign their receipt,
            kitchen and bar printers.</p>
    </div>
    <a href="<?php echo BASE_URL; ?>/admin/printing/index.php" class="btn-secondary">
        <i class="fas fa-print"></i> Printing Configuration
    </a>
</div>

<?php echo $alert; ?>

<div class="terminal-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
        <h2 style="margin:0; font-size:1.2rem;">Registered Terminals</h2>
        <a href="terminals.php" class="btn-secondary"><i class="fas fa-plus"></i> Add New Terminal</a>
    </div>

    <div style="overflow-x:auto;">
        <table class="terminal-table">
            <thead>
                <tr>
                    <th>Terminal</th>
                    <th>Code</th>
                    <th>Location</th>
                    <th>Assigned Printers</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($terminals)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:var(--text-muted); padding:2rem;">
                            No terminals registered yet. Add your first POS workstation below.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($terminals as $terminal): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($terminal['name']); ?></strong>
                            </td>
                            <td><code><?php echo htmlspecialchars($terminal['terminal_code'] ?: '—'); ?></code></td>
                            <td><?php echo htmlspecialchars($terminal['location'] ?: '—'); ?></td>
                            <td>
                                <?php if ($terminal['receipt_printer']): ?>
                                    <span class="printer-chip"><i class="fas fa-receipt"></i> <?php echo htmlspecialchars($terminal['receipt_printer']); ?></span>
                                <?php endif; ?>
                                <?php if ($terminal['kot_printer']): ?>
                                    <span class="printer-chip"><i class="fas fa-utensils"></i> <?php echo htmlspecialchars($terminal['kot_printer']); ?></span>
                                <?php endif; ?>
                                <?php if ($terminal['bot_printer']): ?>
                                    <span class="printer-chip"><i class="fas fa-wine-glass-alt"></i> <?php echo htmlspecialchars($terminal['bot_printer']); ?></span>
                                <?php endif; ?>
                                <?php if (!$terminal['receipt_printer'] && !$terminal['kot_printer'] && !$terminal['bot_printer']): ?>
                                    <span style="color:var(--text-muted);">No printers assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: <?php echo $terminal['status'] == 'active' ? '#16a34a' : '#94a3b8'; ?>;">
                                    <i class="fas fa-circle" style="font-size:0.6rem;"></i>
                                    <?php echo ucfirst($terminal['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex; gap:0.4rem;">
                                    <a href="?edit=<?php echo $terminal['id']; ?>" class="btn-secondary" style="padding:0.4rem 0.8rem;" title="Edit"><i class="fas fa-edit"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this terminal?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete_terminal">
                                        <input type="hidden" name="terminal_id" value="<?php echo $terminal['id']; ?>">
                                        <button type="submit" class="btn-danger-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Add / Edit Terminal Form -->
    <h3 style="margin:2rem 0 1rem; border-top:1px solid var(--border-color); padding-top:1.5rem;">
        <?php echo $edit_terminal ? 'Edit Terminal: ' . htmlspecialchars($edit_terminal['name']) : 'Add New Terminal / Workspace'; ?>
    </h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="save_terminal">
        <input type="hidden" name="terminal_id" value="<?php echo $edit_terminal['id'] ?? 0; ?>">

        <div class="form-row">
            <div class="form-group">
                <label>Terminal Name</label>
                <input type="text" name="name" required
                    value="<?php echo htmlspecialchars($edit_terminal['name'] ?? ''); ?>" placeholder="e.g. Bar Station 2">
            </div>
            <div class="form-group">
                <label>Terminal Code</label>
                <input type="text" name="terminal_code"
                    value="<?php echo htmlspecialchars($edit_terminal['terminal_code'] ?? ''); ?>" placeholder="e.g. TERM-02">
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location"
                    value="<?php echo htmlspecialchars($edit_terminal['location'] ?? ''); ?>" placeholder="e.g. Bar Counter">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Receipt Printer</label>
                <select name="receipt_printer_id">
                    <option value="0">— None —</option>
                    <?php foreach ($printers as $printer): ?>
                        <?php if ($printer['type'] == 'receipt'): ?>
                            <option value="<?php echo $printer['id']; ?>" <?php echo ($edit_terminal['receipt_printer_id'] ?? 0) == $printer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($printer['name']); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Kitchen (KOT) Printer</label>
                <select name="kot_printer_id">
                    <option value="0">— None —</option>
                    <?php foreach ($printers as $printer): ?>
                        <?php if ($printer['type'] == 'kot'): ?>
                            <option value="<?php echo $printer['id']; ?>" <?php echo ($edit_terminal['kot_printer_id'] ?? 0) == $printer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($printer['name']); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Bar (BOT) Printer</label>
                <select name="bot_printer_id">
                    <option value="0">— None —</option>
                    <?php foreach ($printers as $printer): ?>
                        <?php if ($printer['type'] == 'bot'): ?>
                            <option value="<?php echo $printer['id']; ?>" <?php echo ($edit_terminal['bot_printer_id'] ?? 0) == $printer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($printer['name']); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group" style="max-width:220px;">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?php echo ($edit_terminal['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($edit_terminal['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn-save">
            <i class="fas fa-save"></i> <?php echo $edit_terminal ? 'Update Terminal' : 'Add Terminal'; ?>
        </button>
        <?php if ($edit_terminal): ?>
            <a href="terminals.php" class="btn-secondary" style="margin-left:0.75rem; margin-top:1rem;">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>