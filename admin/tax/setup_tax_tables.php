<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

require_permission('manage_settings');

$messages = [];
$errors   = [];

try {
    // ── 1. tax_items ──────────────────────────────────────────────────────────
    $conn->exec("CREATE TABLE IF NOT EXISTS tax_items (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100) NOT NULL UNIQUE,
        rate        DECIMAL(7,4) NOT NULL DEFAULT 0.0000 COMMENT 'e.g. 0.1500 = 15%',
        description VARCHAR(255) NULL,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = '✔ Table <strong>tax_items</strong> ready.';

    // ── 2. tax_groups ─────────────────────────────────────────────────────────
    $conn->exec("CREATE TABLE IF NOT EXISTS tax_groups (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = '✔ Table <strong>tax_groups</strong> ready.';

    // ── 3. tax_group_items pivot ───────────────────────────────────────────────
    $conn->exec("CREATE TABLE IF NOT EXISTS tax_group_items (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        tax_group_id INT NOT NULL,
        tax_item_id  INT NOT NULL,
        UNIQUE KEY uq_group_item (tax_group_id, tax_item_id),
        FOREIGN KEY (tax_group_id) REFERENCES tax_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (tax_item_id)  REFERENCES tax_items(id)  ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $messages[] = '✔ Table <strong>tax_group_items</strong> ready.';

    // ── 4. Add tax_group_id FK column to food_items ───────────────────────────
    $cols = $conn->query("SHOW COLUMNS FROM food_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tax_group_id', $cols)) {
        $conn->exec("ALTER TABLE food_items ADD COLUMN tax_group_id INT NULL AFTER tax_group,
                     ADD CONSTRAINT fk_food_tax_group FOREIGN KEY (tax_group_id) REFERENCES tax_groups(id) ON DELETE SET NULL");
        $messages[] = '✔ Column <strong>tax_group_id</strong> added to food_items.';
    } else {
        $messages[] = '✔ Column <strong>tax_group_id</strong> already exists.';
    }

    // ── 5. Seed default Tax Items (Ghana standard) ────────────────────────────
    $defaultItems = [
        ['VAT',         0.1500, 'Value Added Tax (15%)'],
        ['NHIL',        0.0250, 'National Health Insurance Levy (2.5%)'],
        ['GETFL',       0.0100, 'Ghana Education Trust Fund Levy (1%)'],
        ['COVID Levy',  0.0100, 'COVID-19 Health Recovery Levy (1%)'],
    ];
    $insItem = $conn->prepare("INSERT IGNORE INTO tax_items (name, rate, description) VALUES (?, ?, ?)");
    foreach ($defaultItems as $ti) {
        $insItem->execute($ti);
    }
    $messages[] = '✔ Default tax items seeded (VAT, NHIL, GETFL, COVID Levy).';

    // ── 6. Seed default Tax Groups ────────────────────────────────────────────
    $defaultGroups = [
        ['Standard',   'Taxable items — VAT + NHIL + GETFL + COVID Levy'],
        ['Zero Rated', 'Taxable at 0% (exports, etc.)'],
        ['Exempt',     'Exempt from all taxes'],
    ];
    $insGroup = $conn->prepare("INSERT IGNORE INTO tax_groups (name, description) VALUES (?, ?)");
    foreach ($defaultGroups as $tg) {
        $insGroup->execute($tg);
    }
    $messages[] = '✔ Default tax groups seeded (Standard, Zero Rated, Exempt).';

    // ── 7. Link all 4 items to the "Standard" group ───────────────────────────
    $stdGroup = $conn->query("SELECT id FROM tax_groups WHERE name = 'Standard'")->fetchColumn();
    if ($stdGroup) {
        $allItems = $conn->query("SELECT id FROM tax_items")->fetchAll(PDO::FETCH_COLUMN);
        $insPivot = $conn->prepare("INSERT IGNORE INTO tax_group_items (tax_group_id, tax_item_id) VALUES (?, ?)");
        foreach ($allItems as $tiId) {
            $insPivot->execute([$stdGroup, $tiId]);
        }
        $messages[] = '✔ Linked all tax items to the <strong>Standard</strong> group.';
    }

    // ── 8. Migrate existing tax_group text → tax_group_id ────────────────────
    $conn->exec("UPDATE food_items fi
                 JOIN tax_groups tg ON fi.tax_group = tg.name
                 SET fi.tax_group_id = tg.id
                 WHERE fi.tax_group_id IS NULL AND fi.tax_group IS NOT NULL AND fi.tax_group != ''");
    $messages[] = '✔ Migrated existing food_items.tax_group values to tax_group_id.';

} catch (PDOException $e) {
    $errors[] = 'Database error: ' . $e->getMessage();
}

$admin_title = 'Setup: Tax Tables';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-database"></i> Tax Tables Setup</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">One-time migration to create and seed the tax system tables.</p>
    </div>
    <a href="index.php" class="btn-submit"
        style="background: var(--primary-color); color: white; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600;">
        <i class="fas fa-tags"></i> Manage Tax Items
    </a>
</div>

<div class="dashboard-card">
    <?php foreach ($messages as $msg): ?>
        <div style="padding: 0.7rem 1rem; margin-bottom: 0.5rem; background: #d4edda; color: #155724; border-radius: 6px; border: 1px solid #c3e6cb;">
            <?php echo $msg; ?>
        </div>
    <?php endforeach; ?>

    <?php foreach ($errors as $err): ?>
        <div style="padding: 0.7rem 1rem; margin-bottom: 0.5rem; background: #f8d7da; color: #721c24; border-radius: 6px; border: 1px solid #f5c6cb;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($err); ?>
        </div>
    <?php endforeach; ?>

    <?php if (empty($errors)): ?>
        <div style="margin-top: 1.5rem; padding: 1rem; background: var(--light-bg); border-radius: 8px;">
            <p style="margin: 0; font-weight: 600;">Setup complete! Next steps:</p>
            <ol style="margin: 0.5rem 0 0 1.2rem;">
                <li>Go to <a href="index.php">Tax Items</a> to review or add more tax components.</li>
                <li>Go to <a href="manage_groups.php">Tax Groups</a> to organise items into groups.</li>
                <li>Assign a Tax Group when <a href="<?php echo BASE_URL; ?>/admin/items/">adding or editing menu items</a>.</li>
            </ol>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
