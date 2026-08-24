<?php
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_check.php';
require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_events');

$admin_title = 'Manage Packages';
$current_page = 'manage_packages';

// Handle Actions
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM event_packages WHERE id = ?");
    if ($stmt->execute([$id])) {
        $_SESSION['flash_success'] = "Package deleted successfully.";
    } else {
        $_SESSION['flash_error'] = "Failed to delete package.";
    }
    header("Location: manage_packages.php");
    exit();
}

// Handle Form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = clean_input($_POST['name']);
    $description = clean_input($_POST['description']);
    $price = floatval($_POST['price']);

    $image_url = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $upload_path = '../../assets/images/packages/' . $new_filename;
            if (!is_dir('../../assets/images/packages/')) {
                mkdir('../../assets/images/packages/', 0777, true);
            }
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                $image_url = 'assets/images/packages/' . $new_filename;
            }
        }
    }

    if (isset($_POST['pkg_id']) && !empty($_POST['pkg_id'])) {
        $id = $_POST['pkg_id'];
        $sql = "UPDATE event_packages SET name=?, description=?, base_price_per_head=?";
        $params = [$name, $description, $price];
        if ($image_url) {
            $sql .= ", image_url=?";
            $params[] = $image_url;
        }
        $sql .= " WHERE id=?";
        $params[] = $id;
        $stmt = $conn->prepare($sql);
        if ($stmt->execute($params))
            $_SESSION['flash_success'] = "Package updated.";
    } else {
        $stmt = $conn->prepare("INSERT INTO event_packages (name, description, base_price_per_head, image_url) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$name, $description, $price, $image_url]))
            $_SESSION['flash_success'] = "Package added.";
    }
    header("Location: manage_packages.php");
    exit();
}

include '../includes/admin_header.php';

$stmt = $conn->query("SELECT * FROM event_packages ORDER BY base_price_per_head ASC");
$packages = $stmt->fetchAll();

$edit_pkg = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM event_packages WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_pkg = $stmt->fetch();
}
?>

<div class="dashboard-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1><i class="fas fa-box-open"></i> Manage Event Packages</h1>
        <?php if ($edit_pkg): ?>
            <a href="manage_packages.php" class="btn-secondary"><i class="fas fa-plus"></i> Add New</a>
        <?php endif; ?>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
        <div class="dashboard-card">
            <h3>Packages List</h3>
            <table class="table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Price/Head</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $pkg): ?>
                        <tr>
                            <td>
                                <?php if ($pkg['image_url']): ?>
                                    <img src="<?php echo BASE_URL . '/' . $pkg['image_url']; ?>"
                                        style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                                <?php else: ?>
                                    <div style="width: 50px; height: 50px; background: #eee; border-radius: 4px;"></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($pkg['name']); ?>
                            </td>
                            <td>
                                <?php echo format_currency($pkg['base_price_per_head']); ?>
                            </td>
                            <td>
                                <a href="?edit=<?php echo $pkg['id']; ?>" class="btn-icon text-primary"><i
                                        class="fas fa-edit"></i></a>
                                <a href="?delete=<?php echo $pkg['id']; ?>" class="btn-icon text-danger"
                                    onclick="return confirm('Are you sure?');"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="dashboard-card">
            <h3>
                <?php echo $edit_pkg ? 'Edit Package' : 'Add New Package'; ?>
            </h3>
            <form method="POST" enctype="multipart/form-data">
                <?php if ($edit_pkg): ?>
                    <input type="hidden" name="pkg_id" value="<?php echo $edit_pkg['id']; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Package Name</label>
                    <input type="text" name="name" class="form-control" required
                        value="<?php echo $edit_pkg['name'] ?? ''; ?>">
                </div>

                <div class="form-group">
                    <label>Price Per Head</label>
                    <input type="number" step="0.01" name="price" class="form-control" required
                        value="<?php echo $edit_pkg['base_price_per_head'] ?? ''; ?>">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control"
                        rows="4"><?php echo $edit_pkg['description'] ?? ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label>Image</label>
                    <input type="file" name="image" class="form-control">
                    <?php if ($edit_pkg && $edit_pkg['image_url']): ?>
                        <small>Current: <a href="<?php echo BASE_URL . '/' . $edit_pkg['image_url']; ?>"
                                target="_blank">View</a></small>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%;">
                    <?php echo $edit_pkg ? 'Update Package' : 'Create Package'; ?>
                </button>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>