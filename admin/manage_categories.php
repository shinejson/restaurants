<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

require_once '../includes/permissions.php';

// Check permission
require_permission('manage_categories');


$errors = [];
$success = '';
$error = '';

// Check for success message from redirect
if (isset($_SESSION['category_success'])) {
    $success = $_SESSION['category_success'];
    unset($_SESSION['category_success']);
}

// Check for error message from redirect
if (isset($_SESSION['category_error'])) {
    $error = $_SESSION['category_error'];
    unset($_SESSION['category_error']);
}


// Handle actions for main categories
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] == $_SESSION['csrf_token']) {
    if (isset($_POST['main_action'])) {
        $name = clean_input($_POST['name']);
        if ($_POST['main_action'] == 'add') {
            try {
                $stmt = $conn->prepare("INSERT INTO main_categories (name) VALUES (?)");
                $stmt->execute([$name]);
                $_SESSION['category_success'] = 'Main category added successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $_SESSION['category_error'] = 'A category with this name already exists!';
                } else {
                    $_SESSION['category_error'] = 'Error adding category: ' . $e->getMessage();
                }
            }
            header('Location: manage_categories.php');
            exit;
        } elseif ($_POST['main_action'] == 'delete') {
            $id = (int) $_POST['id'];
            try {
                $stmt = $conn->prepare("DELETE FROM main_categories WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['category_success'] = 'Main category deleted successfully!';
            } catch (PDOException $e) {
                $_SESSION['category_error'] = 'Error deleting category: ' . $e->getMessage();
            }
            header('Location: manage_categories.php');
            exit;
        }
    } elseif (isset($_POST['sub_action'])) {
        $name = clean_input($_POST['name']);
        $main_id = (int) $_POST['main_id'];
        if ($_POST['sub_action'] == 'add') {
            try {
                $stmt = $conn->prepare("INSERT INTO sub_categories (main_category_id, name) VALUES (?, ?)");
                $stmt->execute([$main_id, $name]);
                $_SESSION['category_success'] = 'Sub category added successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $_SESSION['category_error'] = 'A sub-category with this name already exists!';
                } else {
                    $_SESSION['category_error'] = 'Error adding sub-category: ' . $e->getMessage();
                }
            }
            header('Location: manage_categories.php');
            exit;
        } elseif ($_POST['sub_action'] == 'delete') {
            $id = (int) $_POST['id'];
            try {
                $stmt = $conn->prepare("DELETE FROM sub_categories WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['category_success'] = 'Sub category deleted successfully!';
            } catch (PDOException $e) {
                $_SESSION['category_error'] = 'Error deleting sub-category: ' . $e->getMessage();
            }
            header('Location: manage_categories.php');
            exit;
        }
    }
}

// List main categories
$main_stmt = $conn->prepare("SELECT * FROM main_categories ORDER BY name");
$main_stmt->execute();
$mains = $main_stmt->fetchAll();

// List subs
$sub_stmt = $conn->prepare("SELECT s.*, m.name AS main_name FROM sub_categories s JOIN main_categories m ON s.main_category_id = m.id ORDER BY m.name, s.name");
$sub_stmt->execute();
$subs = $sub_stmt->fetchAll();

$admin_title = 'Manage Categories';
include 'includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-list"></i> Manage Categories</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Organize your menu by managing main and sub categories.
        </p>
    </div>
</div>

<?php if ($success): ?>
    <div id="successAlert" class="alert alert-success"
        style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c3e6cb; transition: opacity 0.5s ease-out;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
    <script>
        // Auto-dismiss success message after 5 seconds
        setTimeout(function () {
            var alert = document.getElementById('successAlert');
            if (alert) {
                // Fade out
                alert.style.opacity = '0';
                // Remove from DOM after fade completes
                setTimeout(function () {
                    alert.remove();
                }, 500);
            }
        }, 5000);
    </script>
<?php endif; ?>

<?php if ($error): ?>
    <div id="errorAlert" class="alert alert-danger"
        style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #f5c6cb; transition: opacity 0.5s ease-out;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <script>
        // Auto-dismiss error message after 5 seconds
        setTimeout(function () {
            var alert = document.getElementById('errorAlert');
            if (alert) {
                // Fade out
                alert.style.opacity = '0';
                // Remove from DOM after fade completes
                setTimeout(function () {
                    alert.remove();
                }, 500);
            }
        }, 5000);
    </script>
<?php endif; ?>

<!-- Main Categories -->
<div class="dashboard-card" style="margin-bottom: 2.5rem;">
    <h2
        style="margin-bottom: 1.5rem; font-size: 1.3rem; border-bottom: 2px solid var(--border-color); padding-bottom: 0.8rem;">
        <i class="fas fa-folder"></i> Main Categories
    </h2>

    <div style="background: var(--light-bg); padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
        <form method="POST" style="display: flex; gap: 0.8rem; align-items: center;">
            <input type="text" name="name" placeholder="Enter custom Main Category name" required
                onkeydown="if(event.key === 'Enter') { event.preventDefault(); this.form.querySelector('button[type=submit]').click(); }"
                style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            <input type="hidden" name="main_action" value="add">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button type="submit" class="btn-submit"
                style="background: var(--primary-color); color: white; border: none; padding: 0.8rem 2rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                <i class="fas fa-plus"></i> Add Main Category
            </button>
        </form>
    </div>

    <div
        style="max-height: 400px; overflow-y: auto; overflow-x: auto; border: 1px solid var(--border-color); border-radius: 8px;">
        <table class="data-table">
            <thead style="position: sticky; top: 0; background: var(--white); z-index: 10;">
                <tr>
                    <th>ID</th>
                    <th>Category Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mains as $main): ?>
                    <tr>
                        <td><?php echo $main['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($main['name']); ?></strong></td>
                        <td>
                            <form method="POST" style="display:inline;"
                                onsubmit="return confirm('Are you sure you want to delete this category? All related subcategories will be affected.');">
                                <input type="hidden" name="main_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $main['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit"
                                    style="background: #dc3545; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-size: 0.85rem;">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($mains)): ?>
                    <tr>
                        <td colspan="3" style="text-align: center; padding: 2rem;">No main categories found. Start by adding
                            one!</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Sub Categories -->
<div class="dashboard-card">
    <h2
        style="margin-bottom: 1.5rem; font-size: 1.3rem; border-bottom: 2px solid var(--border-color); padding-bottom: 0.8rem;">
        <i class="fas fa-folder-open"></i> Sub Categories
    </h2>

    <div style="background: var(--light-bg); padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
        <form method="POST" style="display: flex; gap: 0.8rem; align-items: center; flex-wrap: wrap;">
            <select name="main_id" required onkeydown="if(event.key === 'Enter') { event.preventDefault(); }"
                style="padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main); min-width: 200px;">
                <option value="">Select Main Category</option>
                <?php foreach ($mains as $main): ?>
                    <option value="<?php echo $main['id']; ?>"><?php echo htmlspecialchars($main['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="name" placeholder="Enter custom Sub Category name" required
                onkeydown="if(event.key === 'Enter') { event.preventDefault(); this.form.querySelector('button[type=submit]').click(); }"
                style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main); min-width: 250px;">
            <input type="hidden" name="sub_action" value="add">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button type="submit" class="btn-submit"
                style="background: var(--primary-color); color: white; border: none; padding: 0.8rem 2rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                <i class="fas fa-plus"></i> Add Sub Category
            </button>
        </form>
    </div>

    <div
        style="max-height: 400px; overflow-y: auto; overflow-x: auto; border: 1px solid var(--border-color); border-radius: 8px;">
        <table class="data-table">
            <thead style="position: sticky; top: 0; background: var(--white); z-index: 10;">
                <tr>
                    <th>ID</th>
                    <th>Main Category</th>
                    <th>Sub Category Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subs as $sub): ?>
                    <tr>
                        <td><?php echo $sub['id']; ?></td>
                        <td><span
                                style="background: #e9ecef; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.85rem; color: #495057;"><?php echo htmlspecialchars($sub['main_name']); ?></span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($sub['name']); ?></strong></td>
                        <td>
                            <form method="POST" style="display:inline;"
                                onsubmit="return confirm('Are you sure you want to delete this sub category?');">
                                <input type="hidden" name="sub_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit"
                                    style="background: #dc3545; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-size: 0.85rem;">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($subs)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem;">No sub categories found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>