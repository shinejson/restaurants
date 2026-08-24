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
    $tax_group = clean_input($_POST['tax_group']);
    $inventory_count = (int) $_POST['inventory_count'];
    $sold_by_weight = isset($_POST['sold_by_weight']) ? 1 : 0;

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
            $stmt = $conn->prepare("UPDATE food_items SET item_name = ?, description = ?, price = ?, promo_price = ?, image_url = ?, sub_category_id = ?, is_vegetarian = ?, is_spicy = ?, calories = ?, cooking_time = ?, inactive = ?, cost = ?, tax_group = ?, inventory_count = ?, sold_by_weight = ? WHERE id = ?");
            $stmt->execute([$item_name, $description, $price, $promo_price, $image_url, $sub_category_id, $is_vegetarian, $is_spicy, $calories, $cooking_time, $is_inactive, $cost, $tax_group, $inventory_count, $sold_by_weight, $id]);
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

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
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

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Item Name *</label>
                <input type="text" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>" required
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            </div>
            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Original Price *</label>
                <input type="number" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($item['price']); ?>" required
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            </div>
            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Promotion Price (Optional)</label>
                <input type="number" name="promo_price" step="0.01" min="0" value="<?php echo htmlspecialchars($item['promo_price'] ?? ''); ?>"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);" placeholder="e.g. 45.00">
                <small style="color: var(--text-muted);">Leave empty for regular price</small>
            </div>
            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Cost Price (GH₵) *</label>
                <input type="number" name="cost" step="0.01" min="0" required
                    value="<?php echo htmlspecialchars($item['cost'] ?? 0); ?>"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            </div>
            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Inventory Count</label>
                <input type="number" name="inventory_count" min="0"
                    value="<?php echo htmlspecialchars($item['inventory_count'] ?? 0); ?>"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Tax Group</label>
                <select name="tax_group"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                    <option value="Standard" <?php echo ($item['tax_group'] ?? '') == 'Standard' ? 'selected' : ''; ?>>
                        Standard</option>
                    <option value="Zero Rated" <?php echo ($item['tax_group'] ?? '') == 'Zero Rated' ? 'selected' : ''; ?>>Zero Rated</option>
                    <option value="Exempt" <?php echo ($item['tax_group'] ?? '') == 'Exempt' ? 'selected' : ''; ?>>Exempt
                    </option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Main Category *</label>
                    <select id="main_category_id" name="main_category_id" required
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                        <?php foreach ($main_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $cat['id'] == $item['main_category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Sub Category *</label>
                    <select id="sub_category_id" name="sub_category_id" required
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                        <?php foreach ($sub_categories as $scat): ?>
                            <option value="<?php echo $scat['id']; ?>" <?php echo $scat['id'] == $item['sub_category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($scat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Description</label>
                <textarea name="description" rows="4"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main); font-family: inherit;"><?php echo htmlspecialchars($item['description']); ?></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start;">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Update Image</label>
                    <input type="file" id="imageInput" name="image" accept="image/*"
                        style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                    <div id="imagePreview"
                        style="margin-top: 1rem; width: 150px; height: 150px; border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                        <img src="<?php echo !empty($item['image_url']) ? BASE_URL . '/' . $item['image_url'] : BASE_URL . '/assets/images/food-placeholder.jpg'; ?>"
                            style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Calories</label>
                        <input type="number" name="calories" value="<?php echo htmlspecialchars($item['calories']); ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Cooking Time (m)</label>
                        <input type="number" name="cooking_time"
                            value="<?php echo htmlspecialchars($item['cooking_time']); ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                    </div>
                    <div
                        style="grid-column: span 2; display: flex; flex-direction: column; gap: 0.8rem; margin-top: 0.5rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="is_vegetarian" value="1" <?php echo $item['is_vegetarian'] ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color:
                        var(--primary-color);">
                            <span style="font-weight: 600;">Vegetarian</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="is_spicy" value="1" <?php echo $item['is_spicy'] ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: var(--primary-color);">
                            <span style="font-weight: 600;">Spicy Dish</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="sold_by_weight" value="1" <?php echo ($item['sold_by_weight'] ?? 0) ? 'checked' : ''; ?>
                                style="width: 18px; height: 18px; accent-color: var(--primary-color);">
                            <span style="font-weight: 600;">Sold By Weight</span>
                        </label>
                        <label
                            style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-top: 0.5rem; color: #dc3545;">
                            <input type="checkbox" name="is_inactive" value="1" <?php echo $item['inactive'] ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: #dc3545;">
                            <span style="font-weight: 600;">Mark as Inactive (Hidden from Menu)</span>
                        </label>
                    </div>
                </div>
            </div>

            <div style="margin-top: 1rem; display: flex; gap: 1rem;">
                <button type="submit" class="btn-submit"
                    style="background: var(--primary-color); color: white; border: none; padding: 1rem 2.5rem; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; flex: 1;">
                    <i class="fas fa-save"></i> Update Item
                </button>
                <a href="index.php"
                    style="background: var(--light-bg); color: var(--text-main); border: 1px solid var(--border-color); padding: 1rem 2rem; border-radius: 8px; text-decoration: none; font-weight: 600; text-align: center;">
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