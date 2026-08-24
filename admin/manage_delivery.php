<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/permissions.php';

// Check permission (reusing manage_settings or manage_categories logic)
require_permission('manage_settings');

$errors = [];
$success = '';

// Check for messages from redirect
if (isset($_SESSION['delivery_success'])) {
    $success = $_SESSION['delivery_success'];
    unset($_SESSION['delivery_success']);
}
if (isset($_SESSION['delivery_errors'])) {
    $errors = $_SESSION['delivery_errors'];
    unset($_SESSION['delivery_errors']);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] == $_SESSION['csrf_token']) {
    $action = $_POST['action'] ?? '';

    if ($action == 'add') {
        $zone_name = clean_input($_POST['zone_name']);
        $delivery_fee = (float) $_POST['delivery_fee'];

        if (empty($zone_name)) {
            $_SESSION['delivery_errors'] = ["Zone name is required"];
        } else {
            $stmt = $conn->prepare("INSERT INTO delivery_zones (zone_name, delivery_fee) VALUES (?, ?)");
            $stmt->execute([$zone_name, $delivery_fee]);
            $_SESSION['delivery_success'] = "Delivery zone added successfully!";
        }
        header('Location: manage_delivery.php');
        exit;
    } elseif ($action == 'edit') {
        $id = (int) $_POST['id'];
        $zone_name = clean_input($_POST['zone_name']);
        $delivery_fee = (float) $_POST['delivery_fee'];

        if (empty($zone_name)) {
            $_SESSION['delivery_errors'] = ["Zone name is required"];
        } else {
            $stmt = $conn->prepare("UPDATE delivery_zones SET zone_name = ?, delivery_fee = ? WHERE id = ?");
            $stmt->execute([$zone_name, $delivery_fee, $id]);
            $_SESSION['delivery_success'] = "Delivery zone updated successfully!";
        }
        header('Location: manage_delivery.php');
        exit;
    } elseif ($action == 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $conn->prepare("DELETE FROM delivery_zones WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['delivery_success'] = "Delivery zone deleted successfully!";
        header('Location: manage_delivery.php');
        exit;
    }
}

// Fetch all zones
$zones = $conn->query("SELECT * FROM delivery_zones ORDER BY zone_name ASC")->fetchAll();

$admin_title = 'Manage Delivery Zones';
include 'includes/admin_header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="margin: 0;"><i class="fas fa-truck-loading"></i> Delivery Zones</h1>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Manage delivery locations and their respective fees.
        </p>
    </div>
</div>

<?php if ($success): ?>
    <div id="successAlert" class="alert alert-success"
        style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c3e6cb; transition: opacity 0.5s ease-out;">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <script>
        setTimeout(function () {
            var alert = document.getElementById('successAlert');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(function () { alert.remove(); }, 500);
            }
        }, 5000);
    </script>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div id="errorAlert" class="alert alert-danger"
        style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #f5c6cb; transition: opacity 0.5s ease-out;">
        <ul style="margin:0; padding-left:1.5rem;">
            <?php foreach ($errors as $err): ?>
                <li>
                    <?php echo htmlspecialchars($err); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <script>
        setTimeout(function () {
            var alert = document.getElementById('errorAlert');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(function () { alert.remove(); }, 500);
            }
        }, 5000);
    </script>
<?php endif; ?>

<div class="grid" style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
    <!-- Add/Edit Form -->
    <div class="dashboard-card" style="height: fit-content;">
        <h3 id="formTitle"
            style="margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color); padding-bottom: 0.8rem;">
            <i class="fas fa-plus-circle"></i> Add New Zone
        </h3>
        <form method="POST" id="zoneForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="zoneId" value="">

            <div style="margin-bottom: 1.2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Zone Name *</label>
                <input type="text" name="zone_name" id="zoneName" required placeholder="e.g. Airport West"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Delivery Fee (₵) *</label>
                <input type="number" name="delivery_fee" id="deliveryFee" step="0.01" min="0" required
                    placeholder="0.00"
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--white); color: var(--text-main);">
            </div>

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-submit"
                    style="background: var(--primary-color); color: white; border: none; padding: 0.8rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600; flex: 1;">
                    <i class="fas fa-save"></i> <span id="submitBtnText">Add Zone</span>
                </button>
                <button type="button" onclick="resetForm()" id="cancelBtn"
                    style="display: none; background: #6c757d; color: white; border: none; padding: 0.8rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    <!-- Zones List -->
    <div class="dashboard-card">
        <h3 style="margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color); padding-bottom: 0.8rem;">
            <i class="fas fa-list"></i> Existing Zones
        </h3>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Zone Name</th>
                        <th>Delivery Fee</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($zones)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 2rem; color: var(--text-muted);">No zones
                                defined. Add your first zone to enable delivery fees.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($zones as $zone): ?>
                            <tr>
                                <td style="font-weight: 600;">
                                    <?php echo htmlspecialchars($zone['zone_name']); ?>
                                </td>
                                <td style="font-weight: 700; color: #2ecc71;">
                                    <?php echo format_currency($zone['delivery_fee']); ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <button onclick="editZone(<?php echo htmlspecialchars(json_encode($zone)); ?>)"
                                            style="background: #3498db; color: white; border: none; padding: 0.5rem; border-radius: 6px; cursor: pointer;"
                                            title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;"
                                            onsubmit="return confirm('Are you sure you want to delete this zone? Clients will no longer be able to select it at checkout.');">
                                            <input type="hidden" name="csrf_token"
                                                value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $zone['id']; ?>">
                                            <button type="submit"
                                                style="background: #dc3545; color: white; border: none; padding: 0.5rem; border-radius: 6px; cursor: pointer;"
                                                title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function editZone(zone) {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Zone';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('submitBtnText').innerText = 'Update Zone';
        document.getElementById('cancelBtn').style.display = 'inline-block';

        document.getElementById('zoneId').value = zone.id;
        document.getElementById('zoneName').value = zone.zone_name;
        document.getElementById('deliveryFee').value = zone.delivery_fee;

        // Smooth scroll to form
        document.getElementById('zoneForm').scrollIntoView({ behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Zone';
        document.getElementById('formAction').value = 'add';
        document.getElementById('submitBtnText').innerText = 'Add Zone';
        document.getElementById('cancelBtn').style.display = 'none';

        document.getElementById('zoneId').value = '';
        document.getElementById('zoneName').value = '';
        document.getElementById('deliveryFee').value = '';
    }
</script>

<?php include 'includes/admin_footer.php'; ?>