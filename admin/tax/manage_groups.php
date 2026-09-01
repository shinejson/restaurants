<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

require_permission('manage_settings');

$errors  = [];
$success = '';

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    $action = $_POST['action'] ?? '';

    // Add or Edit group
    if ($action === 'add' || $action === 'edit') {
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active   = isset($_POST['is_active']) ? 1 : 0;
        $item_ids    = array_map('intval', $_POST['tax_item_ids'] ?? []);

        if (empty($name)) $errors[] = 'Group name is required.';

        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $stmt = $conn->prepare("INSERT INTO tax_groups (name, description, is_active) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $description, $is_active]);
                    $group_id = (int) $conn->lastInsertId();
                    $_SESSION['tax_grp_success'] = "Tax group <strong>" . htmlspecialchars($name) . "</strong> created.";
                } else {
                    $group_id = (int) ($_POST['edit_id'] ?? 0);
                    $stmt = $conn->prepare("UPDATE tax_groups SET name = ?, description = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$name, $description, $is_active, $group_id]);
                    $_SESSION['tax_grp_success'] = "Tax group updated.";
                }
                // Sync pivot: delete old, insert new
                $conn->prepare("DELETE FROM tax_group_items WHERE tax_group_id = ?")->execute([$group_id]);
                $insPivot = $conn->prepare("INSERT IGNORE INTO tax_group_items (tax_group_id, tax_item_id) VALUES (?, ?)");
                foreach ($item_ids as $tiId) {
                    if ($tiId > 0) $insPivot->execute([$group_id, $tiId]);
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $errors[] = 'A group with this name already exists.';
                } else {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
            if (empty($errors)) {
                header('Location: manage_groups.php');
                exit;
            }
        }
    }

    if ($action === 'delete') {
        $del_id = (int) ($_POST['del_id'] ?? 0);
        try {
            $conn->prepare("DELETE FROM tax_groups WHERE id = ?")->execute([$del_id]);
            $_SESSION['tax_grp_success'] = 'Tax group deleted.';
        } catch (PDOException $e) {
            $_SESSION['tax_grp_error'] = 'Cannot delete — this group may be assigned to menu items.';
        }
        header('Location: manage_groups.php');
        exit;
    }

    if ($action === 'toggle') {
        $tog_id = (int) ($_POST['tog_id'] ?? 0);
        $conn->prepare("UPDATE tax_groups SET is_active = NOT is_active WHERE id = ?")->execute([$tog_id]);
        header('Location: manage_groups.php');
        exit;
    }
}

// ── Flash messages ─────────────────────────────────────────────────────────────
if (isset($_SESSION['tax_grp_success'])) { $success    = $_SESSION['tax_grp_success']; unset($_SESSION['tax_grp_success']); }
$flash_error = '';
if (isset($_SESSION['tax_grp_error']))   { $flash_error = $_SESSION['tax_grp_error'];   unset($_SESSION['tax_grp_error']); }

// ── Fetch active tax items for checkboxes ──────────────────────────────────────
$all_tax_items = $conn->query("SELECT * FROM tax_items WHERE is_active = 1 ORDER BY name")->fetchAll();

// ── Fetch all groups with their items and effective rate ───────────────────────
$groups = $conn->query("
    SELECT tg.*,
           GROUP_CONCAT(ti.name    ORDER BY ti.name SEPARATOR '|||') AS item_names,
           GROUP_CONCAT(ti.id      ORDER BY ti.name SEPARATOR ',')   AS item_ids_csv,
           COALESCE(SUM(ti.rate), 0) AS effective_rate
    FROM tax_groups tg
    LEFT JOIN tax_group_items tgi ON tg.id = tgi.tax_group_id
    LEFT JOIN tax_items ti        ON tgi.tax_item_id = ti.id
    GROUP BY tg.id
    ORDER BY tg.name
")->fetchAll();

// Pre-fill edit form
$edit_group      = null;
$edit_item_ids   = [];
if (isset($_GET['edit'])) {
    $eg = $conn->prepare("SELECT * FROM tax_groups WHERE id = ?");
    $eg->execute([(int) $_GET['edit']]);
    $edit_group = $eg->fetch();
    if ($edit_group) {
        $eitems = $conn->prepare("SELECT tax_item_id FROM tax_group_items WHERE tax_group_id = ?");
        $eitems->execute([$edit_group['id']]);
        $edit_item_ids = $eitems->fetchAll(PDO::FETCH_COLUMN);
    }
}

$admin_title = 'Manage Tax Groups';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-layer-group"></i> Tax Groups</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Bundle tax items into named groups and assign them to menu items.</p>
    </div>
    <a href="index.php"
        style="background:var(--light-bg);color:var(--text-main);border:1px solid var(--border-color);text-decoration:none;padding:.8rem 1.5rem;border-radius:8px;font-weight:600;">
        <i class="fas fa-percentage"></i> Tax Items
    </a>
</div>

<?php if ($success): ?>
    <div id="successAlert" style="background:#d4edda;color:#155724;padding:1rem;border-radius:8px;margin-bottom:1.5rem;border:1px solid #c3e6cb;transition:opacity .5s ease-out;">
        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    </div>
    <script>setTimeout(function(){var a=document.getElementById('successAlert');if(a){a.style.opacity='0';setTimeout(function(){a.remove();},500);}},5000);</script>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:8px;margin-bottom:1.5rem;border:1px solid #f5c6cb;">
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

<?php if (empty($all_tax_items)): ?>
    <div class="dashboard-card" style="text-align:center;padding:2rem;">
        <i class="fas fa-percentage fa-2x" style="color:var(--text-muted);margin-bottom:1rem;opacity:.4;"></i>
        <p>No active tax items found. <a href="index.php">Add tax items first</a> before creating groups.</p>
    </div>
<?php else: ?>

<!-- ── Add / Edit Form ── -->
<div class="dashboard-card" style="margin-bottom:2rem;">
    <h2 style="margin-bottom:1.2rem;font-size:1.1rem;border-bottom:2px solid var(--border-color);padding-bottom:.7rem;">
        <i class="fas fa-<?php echo $edit_group ? 'edit' : 'plus-circle'; ?>"></i>
        <?php echo $edit_group ? 'Edit Tax Group' : 'Create New Tax Group'; ?>
    </h2>
    <form method="POST" style="display:grid;gap:1.2rem;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action"     value="<?php echo $edit_group ? 'edit' : 'add'; ?>">
        <?php if ($edit_group): ?>
            <input type="hidden" name="edit_id" value="<?php echo $edit_group['id']; ?>">
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 2fr auto;gap:1rem;align-items:start;">
            <div class="form-group">
                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Group Name *</label>
                <input type="text" name="name" required placeholder="e.g. Standard"
                    value="<?php echo htmlspecialchars($edit_group['name'] ?? ''); ?>"
                    style="width:100%;padding:.75rem;border:1px solid var(--border-color);border-radius:8px;background:var(--white);color:var(--text-main);">
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Description</label>
                <input type="text" name="description" placeholder="Optional note"
                    value="<?php echo htmlspecialchars($edit_group['description'] ?? ''); ?>"
                    style="width:100%;padding:.75rem;border:1px solid var(--border-color);border-radius:8px;background:var(--white);color:var(--text-main);">
            </div>
            <div class="form-group" style="text-align:center;">
                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Active</label>
                <input type="checkbox" name="is_active" style="width:20px;height:20px;accent-color:var(--primary-color);"
                    <?php echo (!$edit_group || $edit_group['is_active']) ? 'checked' : ''; ?>>
            </div>
        </div>

        <!-- Tax Items Checkboxes -->
        <div>
            <label style="display:block;margin-bottom:.7rem;font-weight:600;">
                Include Tax Items
                <span id="ratePreview" style="font-weight:400;color:var(--text-muted);margin-left:.5rem;"></span>
            </label>
            <div style="display:flex;flex-wrap:wrap;gap:.7rem;">
                <?php foreach ($all_tax_items as $ti): ?>
                    <label style="display:flex;align-items:center;gap:.4rem;background:var(--light-bg);padding:.5rem 1rem;border-radius:8px;cursor:pointer;border:2px solid transparent;transition:border-color .2s;"
                        class="tax-check-label"
                        data-rate="<?php echo $ti['rate']; ?>">
                        <input type="checkbox" name="tax_item_ids[]" value="<?php echo $ti['id']; ?>"
                            class="tax-item-cb"
                            style="accent-color:var(--primary-color);"
                            <?php echo in_array($ti['id'], $edit_item_ids) ? 'checked' : ''; ?>>
                        <span style="font-weight:600;"><?php echo htmlspecialchars($ti['name']); ?></span>
                        <span style="color:var(--text-muted);font-size:.85rem;"><?php echo number_format($ti['rate'] * 100, 2); ?>%</span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:flex;gap:.7rem;">
            <button type="submit" class="btn-submit"
                style="background:var(--primary-color);color:white;border:none;padding:.8rem 2rem;border-radius:8px;cursor:pointer;font-weight:600;">
                <i class="fas fa-<?php echo $edit_group ? 'save' : 'plus'; ?>"></i>
                <?php echo $edit_group ? 'Update Group' : 'Create Group'; ?>
            </button>
            <?php if ($edit_group): ?>
                <a href="manage_groups.php"
                    style="background:var(--light-bg);color:var(--text-main);border:1px solid var(--border-color);padding:.8rem 1.5rem;border-radius:8px;font-weight:600;text-decoration:none;">
                    <i class="fas fa-times"></i> Cancel
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php endif; ?>

<!-- ── Groups Table ── -->
<div class="dashboard-card">
    <h2 style="margin-bottom:1.2rem;font-size:1.1rem;border-bottom:2px solid var(--border-color);padding-bottom:.7rem;">
        <i class="fas fa-list"></i> All Tax Groups
        <span style="font-weight:400;color:var(--text-muted);font-size:.9rem;">(<?php echo count($groups); ?> groups)</span>
    </h2>

    <?php if (empty($groups)): ?>
        <div style="text-align:center;padding:3rem;color:var(--text-muted);">
            <i class="fas fa-layer-group fa-3x" style="margin-bottom:1rem;opacity:.3;"></i>
            <p>No tax groups yet. Create your first one above.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Group Name</th>
                        <th>Tax Items</th>
                        <th>Effective Rate</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $grp): ?>
                        <?php
                        $names = $grp['item_names'] ? explode('|||', $grp['item_names']) : [];
                        ?>
                        <tr>
                            <td><?php echo $grp['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($grp['name']); ?></strong>
                                <?php if ($grp['description']): ?>
                                    <div style="color:var(--text-muted);font-size:.82rem;"><?php echo htmlspecialchars($grp['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($names)): ?>
                                    <span style="color:var(--text-muted);font-size:.85rem;">No items</span>
                                <?php else: ?>
                                    <?php foreach ($names as $n): ?>
                                        <span style="background:rgba(52,152,219,.12);color:#2980b9;padding:.15rem .6rem;border-radius:20px;font-size:.8rem;font-weight:600;display:inline-block;margin:.1rem;">
                                            <?php echo htmlspecialchars($n); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight:700;font-size:1rem;color:var(--text-main);">
                                    <?php echo number_format($grp['effective_rate'] * 100, 2); ?>%
                                </span>
                            </td>
                            <td>
                                <?php if ($grp['is_active']): ?>
                                    <span style="background:#d4edda;color:#155724;padding:.2rem .7rem;border-radius:6px;font-size:.8rem;font-weight:600;">Active</span>
                                <?php else: ?>
                                    <span style="background:#f8d7da;color:#721c24;padding:.2rem .7rem;border-radius:6px;font-size:.8rem;font-weight:600;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:.4rem;">
                                    <a href="?edit=<?php echo $grp['id']; ?>"
                                        style="background:var(--primary-color);color:white;padding:.4rem .8rem;border-radius:6px;text-decoration:none;font-size:.85rem;" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action"  value="toggle">
                                        <input type="hidden" name="tog_id" value="<?php echo $grp['id']; ?>">
                                        <button type="submit" title="<?php echo $grp['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                            style="background:<?php echo $grp['is_active'] ? '#ffc107' : '#28a745'; ?>;color:white;border:none;padding:.4rem .8rem;border-radius:6px;cursor:pointer;font-size:.85rem;">
                                            <i class="fas fa-<?php echo $grp['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;"
                                        onsubmit="return confirm('Delete this tax group? Menu items using it will lose their tax assignment.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action"  value="delete">
                                        <input type="hidden" name="del_id" value="<?php echo $grp['id']; ?>">
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

<script>
// Live effective-rate preview in the Add/Edit form
function updateRatePreview() {
    var total = 0;
    document.querySelectorAll('.tax-item-cb:checked').forEach(function(cb) {
        var rate = parseFloat(cb.closest('.tax-check-label').dataset.rate) || 0;
        total += rate;
    });
    var preview = document.getElementById('ratePreview');
    if (preview) {
        preview.textContent = total > 0
            ? '— Effective rate: ' + (total * 100).toFixed(2) + '%'
            : '';
    }
    // Highlight checked labels
    document.querySelectorAll('.tax-check-label').forEach(function(lbl) {
        var cb = lbl.querySelector('.tax-item-cb');
        lbl.style.borderColor = cb.checked ? 'var(--primary-color)' : 'transparent';
    });
}
document.querySelectorAll('.tax-item-cb').forEach(function(cb) {
    cb.addEventListener('change', updateRatePreview);
});
updateRatePreview(); // run on load
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
