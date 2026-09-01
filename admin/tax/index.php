<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

require_permission('manage_settings');

$errors  = [];
$success = '';

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name        = trim($_POST['name'] ?? '');
        $rate        = (float) ($_POST['rate'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name))      $errors[] = 'Tax name is required.';
        if ($rate < 0 || $rate > 100) $errors[] = 'Rate must be between 0 and 100.';

        if (empty($errors)) {
            $rateDecimal = $rate / 100; // store as decimal, e.g. 15 → 0.1500
            try {
                if ($action === 'add') {
                    $stmt = $conn->prepare("INSERT INTO tax_items (name, rate, description, is_active) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $rateDecimal, $description, $is_active]);
                    $_SESSION['tax_success'] = "Tax item <strong>" . htmlspecialchars($name) . "</strong> added successfully.";
                } else {
                    $edit_id = (int) ($_POST['edit_id'] ?? 0);
                    $stmt = $conn->prepare("UPDATE tax_items SET name = ?, rate = ?, description = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$name, $rateDecimal, $description, $is_active, $edit_id]);
                    $_SESSION['tax_success'] = "Tax item updated successfully.";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $errors[] = 'A tax item with this name already exists.';
                } else {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
            if (empty($errors)) {
                header('Location: index.php');
                exit;
            }
        }
    }

    if ($action === 'delete') {
        $del_id = (int) ($_POST['del_id'] ?? 0);
        try {
            $conn->prepare("DELETE FROM tax_items WHERE id = ?")->execute([$del_id]);
            $_SESSION['tax_success'] = 'Tax item deleted.';
        } catch (PDOException $e) {
            $_SESSION['tax_error'] = 'Cannot delete — this tax item may be in use by a tax group.';
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'toggle') {
        $tog_id = (int) ($_POST['tog_id'] ?? 0);
        $conn->prepare("UPDATE tax_items SET is_active = NOT is_active WHERE id = ?")->execute([$tog_id]);
        header('Location: index.php');
        exit;
    }
}

// ── Flash messages ─────────────────────────────────────────────────────────────
if (isset($_SESSION['tax_success'])) { $success = $_SESSION['tax_success']; unset($_SESSION['tax_success']); }
$flash_error = '';
if (isset($_SESSION['tax_error']))   { $flash_error = $_SESSION['tax_error'];  unset($_SESSION['tax_error']); }

// ── Fetch all tax items ────────────────────────────────────────────────────────
$items = $conn->query("
    SELECT ti.*,
           COUNT(tgi.id) AS group_count
    FROM tax_items ti
    LEFT JOIN tax_group_items tgi ON ti.id = tgi.tax_item_id
    GROUP BY ti.id
    ORDER BY ti.name
")->fetchAll();

// Pre-fill edit form
$edit_item = null;
if (isset($_GET['edit'])) {
    $edit_stmt = $conn->prepare("SELECT * FROM tax_items WHERE id = ?");
    $edit_stmt->execute([(int) $_GET['edit']]);
    $edit_item = $edit_stmt->fetch();
}

$admin_title = 'Manage Tax Items';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-percentage"></i> Tax Items</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Define individual tax components (VAT, NHIL, etc.) before grouping them.</p>
    </div>
    <a href="manage_groups.php" class="btn-submit"
        style="background: var(--primary-color); color: white; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600;">
        <i class="fas fa-layer-group"></i> Manage Tax Groups
    </a>
</div>

<?php if ($success): ?>
    <div id="successAlert" style="background:#d4edda;color:#155724;padding:1rem;border-radius:8px;margin-bottom:1.5rem;border:1px solid #c3e6cb;transition:opacity .5s ease-out;">
        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    </div>
    <script>setTimeout(function(){var a=document.getElementById('successAlert');if(a){a.style.opacity='0';setTimeout(function(){a.remove();},500);}},5000);</script>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div id="errorAlert" style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:8px;margin-bottom:1.5rem;border:1px solid #f5c6cb;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_error); ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:8px;margin-bottom:1.5rem;border:1px solid #f5c6cb;">
        <ul style="margin:0;padding-left:1.5rem;">
            <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ── Add / Edit Form ── -->
<div class="dashboard-card" style="margin-bottom: 2rem;">
    <h2 style="margin-bottom:1.2rem;font-size:1.1rem;border-bottom:2px solid var(--border-color);padding-bottom:.7rem;">
        <i class="fas fa-<?php echo $edit_item ? 'edit' : 'plus-circle'; ?>"></i>
        <?php echo $edit_item ? 'Edit Tax Item' : 'Add New Tax Item'; ?>
    </h2>
    <form method="POST" style="display:grid;grid-template-columns:1fr 1fr 2fr auto auto;gap:1rem;align-items:end;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action"     value="<?php echo $edit_item ? 'edit' : 'add'; ?>">
        <?php if ($edit_item): ?>
            <input type="hidden" name="edit_id" value="<?php echo $edit_item['id']; ?>">
        <?php endif; ?>

        <div class="form-group">
            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Name *</label>
            <input type="text" name="name" required placeholder="e.g. VAT"
                value="<?php echo htmlspecialchars($edit_item['name'] ?? ''); ?>"
                style="width:100%;padding:.75rem;border:1px solid var(--border-color);border-radius:8px;background:var(--white);color:var(--text-main);">
        </div>

        <div class="form-group">
            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Rate (%) *</label>
            <input type="number" name="rate" step="0.01" min="0" max="100" required placeholder="e.g. 15"
                value="<?php echo $edit_item ? number_format($edit_item['rate'] * 100, 2) : ''; ?>"
                style="width:100%;padding:.75rem;border:1px solid var(--border-color);border-radius:8px;background:var(--white);color:var(--text-main);">
        </div>

        <div class="form-group">
            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Description</label>
            <input type="text" name="description" placeholder="Optional note"
                value="<?php echo htmlspecialchars($edit_item['description'] ?? ''); ?>"
                style="width:100%;padding:.75rem;border:1px solid var(--border-color);border-radius:8px;background:var(--white);color:var(--text-main);">
        </div>

        <div class="form-group" style="text-align:center;">
            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Active</label>
            <input type="checkbox" name="is_active" style="width:20px;height:20px;accent-color:var(--primary-color);"
                <?php echo (!$edit_item || $edit_item['is_active']) ? 'checked' : ''; ?>>
        </div>

        <div style="display:flex;gap:.5rem;">
            <button type="submit" class="btn-submit"
                style="background:var(--primary-color);color:white;border:none;padding:.75rem 1.5rem;border-radius:8px;cursor:pointer;font-weight:600;white-space:nowrap;">
                <i class="fas fa-<?php echo $edit_item ? 'save' : 'plus'; ?>"></i> <?php echo $edit_item ? 'Update' : 'Add'; ?>
            </button>
            <?php if ($edit_item): ?>
                <a href="index.php"
                    style="background:var(--light-bg);color:var(--text-main);border:1px solid var(--border-color);padding:.75rem 1rem;border-radius:8px;cursor:pointer;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Tax Items Table ── -->
<div class="dashboard-card">
    <h2 style="margin-bottom:1.2rem;font-size:1.1rem;border-bottom:2px solid var(--border-color);padding-bottom:.7rem;">
        <i class="fas fa-list"></i> All Tax Items
        <span style="font-weight:400;color:var(--text-muted);font-size:.9rem;">(<?php echo count($items); ?> items)</span>
    </h2>

    <?php if (empty($items)): ?>
        <div style="text-align:center;padding:3rem;color:var(--text-muted);">
            <i class="fas fa-percentage fa-3x" style="margin-bottom:1rem;opacity:.3;"></i>
            <p>No tax items yet. Add your first one above.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Rate</th>
                        <th>Description</th>
                        <th>Used in Groups</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $ti): ?>
                        <tr>
                            <td><?php echo $ti['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($ti['name']); ?></strong></td>
                            <td>
                                <span style="background:rgba(52,152,219,.1);color:#2980b9;padding:.2rem .7rem;border-radius:20px;font-weight:700;font-size:.95rem;">
                                    <?php echo number_format($ti['rate'] * 100, 2); ?>%
                                </span>
                            </td>
                            <td style="color:var(--text-muted);font-size:.9rem;"><?php echo htmlspecialchars($ti['description'] ?? '—'); ?></td>
                            <td>
                                <span style="background:var(--light-bg);padding:.2rem .7rem;border-radius:20px;font-size:.85rem;">
                                    <?php echo $ti['group_count']; ?> group<?php echo $ti['group_count'] != 1 ? 's' : ''; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($ti['is_active']): ?>
                                    <span style="background:#d4edda;color:#155724;padding:.2rem .7rem;border-radius:6px;font-size:.8rem;font-weight:600;">Active</span>
                                <?php else: ?>
                                    <span style="background:#f8d7da;color:#721c24;padding:.2rem .7rem;border-radius:6px;font-size:.8rem;font-weight:600;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:.4rem;">
                                    <a href="?edit=<?php echo $ti['id']; ?>"
                                        style="background:var(--primary-color);color:white;padding:.4rem .8rem;border-radius:6px;text-decoration:none;font-size:.85rem;" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <!-- Toggle active -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action"  value="toggle">
                                        <input type="hidden" name="tog_id" value="<?php echo $ti['id']; ?>">
                                        <button type="submit" title="<?php echo $ti['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                            style="background:<?php echo $ti['is_active'] ? '#ffc107' : '#28a745'; ?>;color:white;border:none;padding:.4rem .8rem;border-radius:6px;cursor:pointer;font-size:.85rem;">
                                            <i class="fas fa-<?php echo $ti['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                    </form>
                                    <!-- Delete -->
                                    <form method="POST" style="display:inline;"
                                        onsubmit="return confirm('Delete this tax item? It will be removed from all tax groups.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action"  value="delete">
                                        <input type="hidden" name="del_id" value="<?php echo $ti['id']; ?>">
                                        <button type="submit"
                                            style="background:#dc3545;color:white;border:none;padding:.4rem .8rem;border-radius:6px;cursor:pointer;font-size:.85rem;" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
