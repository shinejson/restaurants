<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_menu');


// Handle delete
if (isset($_POST['delete']) && isset($_POST['item_id'])) {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    $item_id = (int) $_POST['item_id'];
    $stmt = $conn->prepare("UPDATE food_items SET inactive = 1 WHERE id = ?");
    $stmt->execute([$item_id]);
    $_SESSION['message'] = 'Item deleted successfully';
    header('Location: index.php');
    exit();
}

// Get filter parameters
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$category = isset($_GET['category']) ? (int) $_GET['category'] : '';
$min_price = isset($_GET['min_price']) ? (float) $_GET['min_price'] : '';
$max_price = isset($_GET['max_price']) ? (float) $_GET['max_price'] : '';
$status = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$dietary = isset($_GET['dietary']) ? clean_input($_GET['dietary']) : '';

// Build query
$query = "SELECT fi.*, sc.name as sub_category, mc.name as main_category, p.name AS printer_name, p.type AS printer_type
          FROM food_items fi 
          JOIN sub_categories sc ON fi.sub_category_id = sc.id 
          JOIN main_categories mc ON sc.main_category_id = mc.id 
          LEFT JOIN printers p ON fi.printer_id = p.id
          WHERE 1=1";

$params = [];

// Search filter
if (!empty($search)) {
    $query .= " AND (fi.item_name LIKE ? OR fi.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Category filter
if (!empty($category)) {
    $query .= " AND mc.id = ?";
    $params[] = $category;
}

// Price filters
if ($min_price !== '') {
    $query .= " AND fi.price >= ?";
    $params[] = $min_price;
}
if ($max_price !== '') {
    $query .= " AND fi.price <= ?";
    $params[] = $max_price;
}

// Status filter
if ($status === 'active') {
    $query .= " AND fi.inactive = 0";
} elseif ($status === 'inactive') {
    $query .= " AND fi.inactive = 1";
} else {
    // Default: show only active items
    $query .= " AND fi.inactive = 0";
}

// Dietary filters
if ($dietary === 'vegetarian') {
    $query .= " AND fi.is_vegetarian = 1";
} elseif ($dietary === 'spicy') {
    $query .= " AND fi.is_spicy = 1";
}

$query .= " ORDER BY fi.id DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Get categories for filter dropdown
$categories_stmt = $conn->query("SELECT id, name FROM main_categories ORDER BY name");
$categories = $categories_stmt->fetchAll();

$admin_title = 'Manage Menu Items';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-utensils"></i> Manage Menu Items</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);"><?php echo count($items); ?> items found
        </p>
    </div>
    <a href="add.php" class="btn-submit"
        style="background: var(--primary-color); color: white; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600;">
        <i class="fas fa-plus"></i> Add New Item
    </a>
</div>

<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-success"
        style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['message'];
        unset($_SESSION['message']); ?>
    </div>
<?php endif; ?>

<!-- Search and Filter Section -->
<div class="dashboard-card" style="margin-bottom: 2rem;">
    <form method="GET"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: end;">
        <!-- Search -->
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">Search</label>
            <input type="text" name="search" placeholder="Item name or description"
                value="<?php echo htmlspecialchars($search); ?>"
                style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
        </div>

        <!-- Category Filter -->
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">Category</label>
            <select name="category"
                style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Min Price -->
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">Min Price</label>
            <input type="number" name="min_price" placeholder="0.00" step="0.01"
                value="<?php echo htmlspecialchars($min_price); ?>"
                style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
        </div>

        <!-- Max Price -->
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">Max Price</label>
            <input type="number" name="max_price" placeholder="1000.00" step="0.01"
                value="<?php echo htmlspecialchars($max_price); ?>"
                style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
        </div>

        <!-- Status Filter -->
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">Status</label>
            <select name="status"
                style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                <option value="">Active Items</option>
                <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>

        <!-- Dietary Filter -->
        <div class="form-group">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">Dietary</label>
            <select name="dietary"
                style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
                <option value="">All Items</option>
                <option value="vegetarian" <?php echo $dietary == 'vegetarian' ? 'selected' : ''; ?>>Vegetarian</option>
                <option value="spicy" <?php echo $dietary == 'spicy' ? 'selected' : ''; ?>>Spicy</option>
            </select>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn-submit"
                style="background: var(--primary-color); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600; flex: 1;">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="index.php" class="btn-submit"
                style="background: var(--light-bg); color: var(--text-main); text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-color);">
                <i class="fas fa-redo"></i>
            </a>
        </div>
    </form>
</div>

<div class="dashboard-card">
    <?php if (empty($items)): ?>
        <div style="text-align: center; padding: 3rem;">
            <i class="fas fa-utensils fa-3x" style="color: var(--border-color); margin-bottom: 1rem;"></i>
            <h3>No menu items found</h3>
            <p style="color: var(--text-muted);">Start by adding your first menu item</p>
            <a href="add.php" class="btn-submit"
                style="background: var(--primary-color); color: white; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600; display: inline-block; margin-top: 1rem;">
                <i class="fas fa-plus"></i> Add Item
            </a>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Item Details</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Prints To</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <img src="<?php echo !empty($item['image_url']) ? BASE_URL . '/' . htmlspecialchars($item['image_url']) : BASE_URL . '/assets/images/food-placeholder.jpg'; ?>"
                                    alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                    style="width: 70px; height: 70px; object-fit: cover; border-radius: 12px; border: 1px solid var(--border-color);">
                            </td>
                            <td>
                                <div style="font-weight: 700; font-size: 1.05rem;">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                </div>
                                <div style="color: var(--text-muted); font-size: 0.85rem; max-width: 300px; margin: 0.2rem 0;">
                                    <?php echo (strlen($item['description']) > 80) ? substr(htmlspecialchars($item['description']), 0, 77) . '...' : htmlspecialchars($item['description']); ?>
                                </div>
                                <div style="display: flex; gap: 0.4rem; margin-top: 0.4rem;">
                                    <?php if ($item['is_vegetarian']): ?>
                                        <span
                                            style="background: rgba(40, 167, 69, 0.1); color: #28a745; padding: 0.1rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Veg</span>
                                    <?php endif; ?>
                                    <?php if ($item['is_spicy']): ?>
                                        <span
                                            style="background: rgba(220, 53, 69, 0.1); color: #dc3545; padding: 0.1rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Spicy</span>
                                    <?php endif; ?>
                                    <span
                                        style="background: var(--light-bg); color: var(--text-muted); padding: 0.1rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">ID:
                                        <?php echo $item['id']; ?></span>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($item['main_category']); ?>
                                </div>
                                <div style="color: var(--text-muted); font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($item['sub_category']); ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: var(--text-main);">
                                    <?php if (!empty($item['promo_price'])): ?>
                                        <div
                                            style="text-decoration: line-through; color: var(--text-muted); font-size: 0.8rem; font-weight: 400;">
                                            <?php echo format_currency($item['price']); ?></div>
                                        <div style="color: #2ecc71;"><?php echo format_currency($item['promo_price']); ?></div>
                                    <?php else: ?>
                                        <?php echo format_currency($item['price']); ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($item['printer_name'])): ?>
                                    <span
                                        style="background: rgba(249, 115, 22, 0.1); color: #c2410c; padding: 0.3rem 0.7rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                                        <i class="fas fa-print" style="font-size: 0.7rem;"></i>
                                        <?php echo htmlspecialchars($item['printer_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.8rem;">Default routing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span
                                    style="background: #d4edda; color: #155724; padding: 0.3rem 0.7rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">Active</span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.5rem;">
                                    <a href="edit.php?id=<?php echo $item['id']; ?>"
                                        style="background: var(--primary-color); color: white; padding: 0.5rem; border-radius: 6px; text-decoration: none;"
                                        title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" style="display: inline;"
                                        onsubmit="return confirm('Are you sure you want to delete this item?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" name="delete"
                                            style="background: #dc3545; color: white; border: none; padding: 0.5rem; border-radius: 6px; cursor: pointer;"
                                            title="Delete">
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