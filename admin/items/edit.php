<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_menu');

$errors = [];
$success = '';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$id = (int) $_GET['id'];

// Get existing item
$stmt = $conn->prepare("SELECT fi.*, sc.main_category_id FROM food_items fi JOIN sub_categories sc ON fi.sub_category_id = sc.id WHERE fi.id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    header('Location: index.php');
    exit();
}

// Get categories for dropdowns
$main_categories = $conn->query("SELECT * FROM main_categories ORDER BY name")->fetchAll();
$stmt = $conn->prepare("SELECT * FROM sub_categories WHERE main_category_id = ? ORDER BY name");
$stmt->execute([$item['main_category_id']]);
$sub_categories = $stmt->fetchAll();

// Get tax groups for dropdown
$tax_groups = [];
try {
    $tax_groups = $conn->query("
        SELECT tg.*, COALESCE(SUM(ti.rate), 0) AS effective_rate
        FROM tax_groups tg
        LEFT JOIN tax_group_items tgi ON tg.id = tgi.tax_group_id
        LEFT JOIN tax_items ti ON tgi.tax_item_id = ti.id
        WHERE tg.is_active = 1
        GROUP BY tg.id
        ORDER BY tg.name
    ")->fetchAll();
} catch (Exception $e) {
    $tax_groups = [];
}

// Get printers grouped by type for per-item print routing
$printers = [];
$printers_grouped = ['kot' => [], 'bot' => [], 'receipt' => []];
try {
    $printers = $conn->query("SELECT id, name, type FROM printers WHERE status = 'active' ORDER BY name")->fetchAll();
    foreach ($printers as $pr) {
        $printers_grouped[$pr['type']][] = $pr;
    }
} catch (Exception $e) {
    $printers = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $item_name = clean_input($_POST['item_name']);
    $description = clean_input($_POST['description']);
    $price = (float) $_POST['price'];
    $sub_category_id = (int) $_POST['sub_category_id'];
    $is_vegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
    $is_spicy = isset($_POST['is_spicy']) ? 1 : 0;
    $calories = !empty($_POST['calories']) ? (int) $_POST['calories'] : null;
    $cooking_time = !empty($_POST['cooking_time']) ? (int) $_POST['cooking_time'] : null;
    $is_inactive = isset($_POST['is_inactive']) ? 1 : 0;
    $promo_price = !empty($_POST['promo_price']) ? (float) $_POST['promo_price'] : null;

    // New Reporting Fields
    $cost = !empty($_POST['cost']) ? (float) $_POST['cost'] : 0.00;
    $tax_group_id = !empty($_POST['tax_group_id']) ? (int) $_POST['tax_group_id'] : null;
    $tax_group_name = '';
    if ($tax_group_id) {
        foreach ($tax_groups as $tg) {
            if ($tg['id'] == $tax_group_id) {
                $tax_group_name = $tg['name'];
                break;
            }
        }
    }
    $tax_group = $tax_group_name ?: clean_input($_POST['tax_group'] ?? '');
    $inventory_count = (int) $_POST['inventory_count'];
    $sold_by_weight = isset($_POST['sold_by_weight']) ? 1 : 0;
    $printer_id = !empty($_POST['printer_id']) ? (int) $_POST['printer_id'] : null;

    if (empty($item_name))
        $errors[] = 'Item name is required';
    if ($price <= 0)
        $errors[] = 'Price must be greater than 0';
    if (!empty($promo_price) && $promo_price >= $price)
        $errors[] = 'Promotion price must be less than the original price';
    if (empty($sub_category_id))
        $errors[] = 'Please select a category';

    $image_url = $item['image_url'];
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../assets/uploads/food_items/';
        if (!file_exists($upload_dir))
            mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
            $image_url = 'assets/uploads/food_items/' . $filename;
        } else {
            $errors[] = 'Failed to upload image';
        }
    }

    if (empty($errors)) {
        try {
            $cols = $conn->query("SHOW COLUMNS FROM food_items")->fetchAll(PDO::FETCH_COLUMN);
            $has_printer_id = in_array('printer_id', $cols);
            if (in_array('tax_group_id', $cols) && $has_printer_id) {
                $stmt = $conn->prepare("UPDATE food_items SET item_name = ?, description = ?, price = ?, promo_price = ?, image_url = ?, sub_category_id = ?, is_vegetarian = ?, is_spicy = ?, calories = ?, cooking_time = ?, inactive = ?, cost = ?, tax_group = ?, tax_group_id = ?, inventory_count = ?, sold_by_weight = ?, printer_id = ? WHERE id = ?");
                $stmt->execute([$item_name, $description, $price, $promo_price, $image_url, $sub_category_id, $is_vegetarian, $is_spicy, $calories, $cooking_time, $is_inactive, $cost, $tax_group, $tax_group_id, $inventory_count, $sold_by_weight, $printer_id, $id]);
            } elseif (in_array('tax_group_id', $cols)) {
                $stmt = $conn->prepare("UPDATE food_items SET item_name = ?, description = ?, price = ?, promo_price = ?, image_url = ?, sub_category_id = ?, is_vegetarian = ?, is_spicy = ?, calories = ?, cooking_time = ?, inactive = ?, cost = ?, tax_group = ?, tax_group_id = ?, inventory_count = ?, sold_by_weight = ? WHERE id = ?");
                $stmt->execute([$item_name, $description, $price, $promo_price, $image_url, $sub_category_id, $is_vegetarian, $is_spicy, $calories, $cooking_time, $is_inactive, $cost, $tax_group, $tax_group_id, $inventory_count, $sold_by_weight, $id]);
            } else {
                $stmt = $conn->prepare("UPDATE food_items SET item_name = ?, description = ?, price = ?, promo_price = ?, image_url = ?, sub_category_id = ?, is_vegetarian = ?, is_spicy = ?, calories = ?, cooking_time = ?, inactive = ?, cost = ?, tax_group = ?, inventory_count = ?, sold_by_weight = ? WHERE id = ?");
                $stmt->execute([$item_name, $description, $price, $promo_price, $image_url, $sub_category_id, $is_vegetarian, $is_spicy, $calories, $cooking_time, $is_inactive, $cost, $tax_group, $inventory_count, $sold_by_weight, $id]);
            }
            $success = 'Menu item updated successfully!';

            // Refresh item data
            $stmt = $conn->prepare("SELECT fi.*, sc.main_category_id FROM food_items fi JOIN sub_categories sc ON fi.sub_category_id = sc.id WHERE fi.id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$admin_title = 'Edit Menu Item';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    .item-section {
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1.5rem;
        background: var(--white);
    }

    .item-section-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--primary-color);
        margin: 0 0 1.1rem;
    }

    .item-section-title i {
        font-size: 0.85rem;
    }

    .field label {
        display: block;
        margin-bottom: 0.45rem;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .field input,
    .field select,
    .field textarea {
        width: 100%;
        padding: 0.75rem 0.9rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--white);
        color: var(--text-main);
        font-family: inherit;
        font-size: 0.95rem;
    }

    .check-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem 1.5rem;
    }

    .check-row label {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        cursor: pointer;
        font-weight: 600;
    }

    .check-row input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: var(--primary-color);
        flex-shrink: 0;
        cursor: pointer;
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-edit"></i> Edit Menu Item</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Modify the details for "
            <?php echo htmlspecialchars($item['item_name']); ?>".
        </p>
    </div>
    <a href="index.php" class="btn-submit"
        style="background: var(--light-bg); color: var(--text-main); text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600; border: 1px solid var(--border-color);">
        <i class="fas fa-arrow-left"></i> Back to Items
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"
        style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #f5c6cb;">
        <ul style="margin: 0; padding-left: 1.5rem;">
            <?php foreach ($errors as $error): ?>
                <li>
                    <?php echo htmlspecialchars($error); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"
        style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<div class="dashboard-card">
    <form method="POST" enctype="multipart/form-data" style="display: grid; gap: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <!-- ===== Section 1: Pricing & Inventory ===== -->
        <div class="item-section">
            <h3 class="item-section-title"><i class="fas fa-money-bill-wave"></i> Pricing & Inventory</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1.1rem;">
                <div class="field">
                    <label>Item Name *</label>
                    <input type="text" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
                </div>
                <div class="field">
                    <label>Original Price *</label>
                    <input type="number" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($item['price']); ?>" required>
                </div>
                <div class="field">
                    <label>Promotion Price</label>
                    <input type="number" name="promo_price" step="0.01" min="0" value="<?php echo htmlspecialchars($item['promo_price'] ?? ''); ?>" placeholder="Optional discount">
                </div>
                <div class="field">
                    <label>Cost Price (GH₵) *</label>
                    <input type="number" name="cost" step="0.01" min="0" required value="<?php echo htmlspecialchars($item['cost'] ?? 0); ?>">
                </div>
                <div class="field">
                    <label>Inventory Count</label>
                    <input type="number" name="inventory_count" min="0" value="<?php echo htmlspecialchars($item['inventory_count'] ?? 0); ?>">
                </div>
            </div>
        </div>

        <!-- ===== Section 2: Classification & Printing ===== -->
        <div class="item-section">
            <h3 class="item-section-title"><i class="fas fa-sitemap"></i> Classification & Printing</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.1rem;">
                <div class="field">
                    <label>Main Category *</label>
                    <select id="main_category_id" name="main_category_id" required>
                        <?php foreach ($main_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $cat['id'] == $item['main_category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Sub Category *</label>
                    <select id="sub_category_id" name="sub_category_id" required>
                        <?php foreach ($sub_categories as $scat): ?>
                            <option value="<?php echo $scat['id']; ?>" <?php echo $scat['id'] == $item['sub_category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($scat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Tax Group</label>
                    <select name="tax_group_id">
                        <option value="">-- Select Tax Group (None) --</option>
                        <?php foreach ($tax_groups as $tg): ?>
                            <?php
                            $isSelected = (isset($item['tax_group_id']) && $item['tax_group_id'] == $tg['id']) ||
                                          (empty($item['tax_group_id']) && ($item['tax_group'] ?? '') === $tg['name']);
                            ?>
                            <option value="<?php echo $tg['id']; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tg['name']); ?> (<?php echo number_format($tg['effective_rate'] * 100, 2); ?>%)
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($tax_groups)): ?>
                            <option value="" disabled>No tax groups created yet</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="field">
                    <label><i class="fas fa-print" style="color: var(--primary-color); margin-right: 0.25rem;"></i> Print Through</label>
                    <select name="printer_id">
                        <option value="">-- Default (Kitchen/Bar routing) --</option>
                        <?php if (!empty($printers_grouped['kot'])): ?>
                            <optgroup label="Kitchen Printers (KOT)">
                                <?php foreach ($printers_grouped['kot'] as $printer): ?>
                                    <option value="<?php echo $printer['id']; ?>" <?php echo ($item['printer_id'] ?? null) == $printer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($printer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($printers_grouped['bot'])): ?>
                            <optgroup label="Bar Printers (BOT)">
                                <?php foreach ($printers_grouped['bot'] as $printer): ?>
                                    <option value="<?php echo $printer['id']; ?>" <?php echo ($item['printer_id'] ?? null) == $printer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($printer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($printers_grouped['receipt'])): ?>
                            <optgroup label="Receipt Printers">
                                <?php foreach ($printers_grouped['receipt'] as $printer): ?>
                                    <option value="<?php echo $printer['id']; ?>" <?php echo ($item['printer_id'] ?? null) == $printer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($printer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (empty($printers)): ?>
                            <option value="" disabled>No printers configured — add one under Printing & Printers</option>
                        <?php endif; ?>
                    </select>
                    <small style="color: var(--text-muted); display: block; margin-top: 0.35rem;">Where this item's
                        order ticket prints — Kitchen (KOT), Bar (BOT) or Receipt.</small>
                </div>
            </div>
        </div>

        <!-- ===== Section 3: Details & Media ===== -->
        <div class="item-section">
            <h3 class="item-section-title"><i class="fas fa-utensils"></i> Details & Media</h3>
            <div style="display: grid; grid-template-columns: 230px 1fr; gap: 1.5rem; align-items: start;">
                <!-- Image column -->
                <div class="field">
                    <label>Update Image</label>
                    <input type="file" id="imageInput" name="image" accept="image/*" style="padding: 0.45rem;">
                    <div id="imagePreview"
                        style="margin-top: 0.9rem; width: 100%; aspect-ratio: 1; border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden;">
                        <img src="<?php echo !empty($item['image_url']) ? BASE_URL . '/' . $item['image_url'] : BASE_URL . '/assets/images/food-placeholder.jpg'; ?>"
                            style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                </div>

                <!-- Details column -->
                <div style="display: grid; gap: 1.1rem;">
                    <div class="field">
                        <label>Description</label>
                        <textarea name="description" rows="3"><?php echo htmlspecialchars($item['description']); ?></textarea>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1.1rem;">
                        <div class="field">
                            <label>Calories</label>
                            <input type="number" name="calories" value="<?php echo htmlspecialchars($item['calories']); ?>">
                        </div>
                        <div class="field">
                            <label>Cooking Time (min)</label>
                            <input type="number" name="cooking_time" value="<?php echo htmlspecialchars($item['cooking_time']); ?>">
                        </div>
                    </div>
                    <div class="check-row" style="border-top: 1px dashed var(--border-color); padding-top: 1rem;">
                        <label>
                            <input type="checkbox" name="is_vegetarian" value="1" <?php echo $item['is_vegetarian'] ? 'checked' : ''; ?>>
                            <span>Vegetarian</span>
                        </label>
                        <label>
                            <input type="checkbox" name="is_spicy" value="1" <?php echo $item['is_spicy'] ? 'checked' : ''; ?>>
                            <span>Spicy Dish</span>
                        </label>
                        <label>
                            <input type="checkbox" name="sold_by_weight" value="1" <?php echo ($item['sold_by_weight'] ?? 0) ? 'checked' : ''; ?>>
                            <span>Sold By Weight</span>
                        </label>
                        <label style="color: #dc3545;">
                            <input type="checkbox" name="is_inactive" value="1" <?php echo $item['inactive'] ? 'checked' : ''; ?> style="accent-color: #dc3545;">
                            <span>Inactive (Hidden from Menu)</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== Actions ===== -->
        <div style="display: flex; gap: 0.9rem; align-items: center;">
            <button type="submit" class="btn-submit"
                style="background: var(--primary-color); color: white; border: none; padding: 0.9rem 2.2rem; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem;">
                <i class="fas fa-save"></i> Update Item
            </button>
            <a href="index.php"
                style="background: var(--light-bg); color: var(--text-main); border: 1px solid var(--border-color); padding: 0.9rem 2rem; border-radius: 8px; text-decoration: none; font-weight: 600;">
                Cancel
            </a>
        </div>
    </form>
</div>

<script>
    document.getElementById('main_category_id').addEventListener('change', function () {
        const mainCatId = this.value;
        const subCatSelect = document.getElementById('sub_category_id');
        subCatSelect.innerHTML = '<option value="">Loading...</option>';
        if (mainCatId) {
            fetch('../ajax/get_sub_categories.php?main_category_id=' + mainCatId)
                .then(r => r.json())
                .then(data => {
                    subCatSelect.innerHTML = '<option value="">Select Sub Category</option>';
                    data.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s.id; opt.textContent = s.name;
                        subCatSelect.appendChild(opt);
                    });
                });
        }
    });

    document.getElementById('imageInput').addEventListener('change', function (e) {
        const reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('imagePreview').innerHTML = `<img src="${e.target.result}" style="width: 100%; height: 100%; object-fit: cover;">`;
        };
        if (this.files[0]) reader.readAsDataURL(this.files[0]);
    });
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>