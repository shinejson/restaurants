<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

require_once '../includes/permissions.php';

// Check permission
require_permission('import_data');

$errors = [];
$success = '';
$import_results = [];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid CSRF token';
    } else {
        $import_type = $_POST['import_type'] ?? '';
        if (empty($import_type))
            $errors[] = 'Please select import type';
        if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK)
            $errors[] = 'File upload error';
        else {
            $file_ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($file_ext, ['csv', 'xlsx', 'xls']))
                $errors[] = 'Only CSV and Excel files are allowed';
        }

        if (empty($errors)) {
            try {
                if ($file_ext === 'csv') {
                    $rows = [];
                    if (($handle = fopen($_FILES['import_file']['tmp_name'], 'r')) !== FALSE) {
                        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE)
                            $rows[] = $data;
                        fclose($handle);
                    }
                    if (!empty($rows)) {
                        array_shift($rows);
                        if ($import_type === 'categories') {
                            $imported = 0;
                            $skipped = 0;
                            foreach ($rows as $row) {
                                if (empty($row[0]))
                                    continue;
                                $main_category = trim($row[0]);
                                $sub_category = isset($row[1]) ? trim($row[1]) : '';
                                $stmt = $conn->prepare("INSERT IGNORE INTO main_categories (name) VALUES (?)");
                                $stmt->execute([$main_category]);
                                if ($stmt->rowCount() > 0)
                                    $imported++;
                                $stmt = $conn->prepare("SELECT id FROM main_categories WHERE name = ?");
                                $stmt->execute([$main_category]);
                                $main_id = $stmt->fetchColumn();
                                if (!empty($sub_category) && $main_id) {
                                    $stmt = $conn->prepare("INSERT IGNORE INTO sub_categories (main_category_id, name) VALUES (?, ?)");
                                    $stmt->execute([$main_id, $sub_category]);
                                    if ($stmt->rowCount() > 0)
                                        $imported++;
                                    else
                                        $skipped++;
                                }
                            }
                            $success = "Categories imported! Imported: $imported, Skipped: $skipped";
                        } elseif ($import_type === 'items') {
                            $imported = 0;
                            $skipped = 0;
                            foreach ($rows as $row) {
                                if (empty($row[0]))
                                    continue;
                                $item_name = trim($row[0]);
                                $description = isset($row[1]) ? trim($row[1]) : '';
                                $price = isset($row[2]) ? floatval($row[2]) : 0;
                                $main = isset($row[3]) ? trim($row[3]) : '';
                                $sub = isset($row[4]) ? trim($row[4]) : '';
                                // New columns for Reporting Module
                                $cost = isset($row[5]) ? floatval($row[5]) : 0.00;
                                $inventory_count = isset($row[6]) ? intval($row[6]) : 0;
                                $tax_group = isset($row[7]) ? trim($row[7]) : 'Standard';
                                $sold_by_weight = isset($row[8]) && (strtolower($row[8]) == 'yes' || $row[8] == '1') ? 1 : 0;

                                $stmt = $conn->prepare("SELECT s.id FROM sub_categories s JOIN main_categories m ON s.main_category_id = m.id WHERE m.name = ? AND s.name = ?");
                                $stmt->execute([$main, $sub]);
                                $sub_id = $stmt->fetchColumn();
                                if ($sub_id) {
                                    $stmt = $conn->prepare("SELECT id FROM food_items WHERE item_name = ?");
                                    $stmt->execute([$item_name]);
                                    if ($stmt->fetchColumn())
                                        $skipped++;
                                    else {
                                        $stmt = $conn->prepare("INSERT INTO food_items (item_name, description, price, sub_category_id, inactive, cost, inventory_count, tax_group, sold_by_weight) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)");
                                        $stmt->execute([$item_name, $description, $price, $sub_id, $cost, $inventory_count, $tax_group, $sold_by_weight]);
                                        $imported++;
                                    }
                                } else {
                                    $skipped++;
                                    $import_results[] = "Skipped '$item_name' - category '$main > $sub' not found";
                                }
                            }
                            $success = "Items imported! Imported: $imported, Skipped: $skipped";
                        }
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'Import error: ' . $e->getMessage();
            }
        }
    }
}

$admin_title = 'Import Data';
include 'includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-file-import"></i> Import Data</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Import your categories and menu items from CSV files.
        </p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"
        style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #f5c6cb;">
        <ul style="margin: 0; padding-left: 1.5rem;">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"
        style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        <?php if (!empty($import_results)): ?>
            <details style="margin-top: 0.5rem;">
                <summary style="cursor: pointer; font-weight: 600;">View Details</summary>
                <ul style="margin-top: 0.5rem; max-height: 150px; overflow-y: auto;">
                    <?php foreach ($import_results as $result): ?>
                        <li><?php echo htmlspecialchars($result); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
    <div class="dashboard-card">
        <h2 style="margin-bottom: 1.5rem; font-size: 1.3rem;"><i class="fas fa-download"></i> Download Templates</h2>
        <p style="margin-bottom: 1.5rem;">Download sample CSV templates to see the correct format before uploading:</p>
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <a href="<?php echo BASE_URL; ?>/admin/templates/categories_template.csv" download
                style="background: #28a745; color: white; padding: 1rem; border-radius: 8px; text-decoration: none; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
                <span><i class="fas fa-file-csv"></i> Categories Template</span>
                <i class="fas fa-download"></i>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/templates/items_template.csv" download
                style="background: #17a2b8; color: white; padding: 1rem; border-radius: 8px; text-decoration: none; display: flex; align-items: center; justify-content: space-between; font-weight: 600;">
                <span><i class="fas fa-file-csv"></i> Items Template</span>
                <i class="fas fa-download"></i>
            </a>
        </div>
    </div>

    <div class="dashboard-card">
        <h2 style="margin-bottom: 1.5rem; font-size: 1.3rem;"><i class="fas fa-upload"></i> Upload CSV</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group" style="margin-bottom: 1.2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Import Type *</label>
                <select name="import_type" required id="importTypeSelect"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                    <option value="">Select Import Type</option>
                    <option value="categories">Categories</option>
                    <option value="items">Food Items</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">CSV File *</label>
                <input type="file" name="import_file" accept=".csv" required
                    style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            </div>
            <button type="submit" class="btn-submit"
                style="width: 100%; background: var(--primary-color); color: white; border: none; padding: 1rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                <i class="fas fa-file-import"></i> Start Import
            </button>
        </form>
    </div>
</div>

<script>
    document.getElementById('importTypeSelect')?.addEventListener('change', function () {
        const type = this.value;
        const guides = { categories: 'Categories: Main Category, Sub Category', items: 'Items: Item Name, Description, Price, Main Category, Sub Category, Cost, Inventory, Tax Group, Sold By Weight (Yes/No)' };
        console.log(guides[type] || 'Select a type');
    });
</script>

<?php include 'includes/admin_footer.php'; ?>