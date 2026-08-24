<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_customers');

$admin_title = 'Manage Customers';

// Handle deletion
if (isset($_POST['delete_id'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token.";
    } else {
        $delete_id = (int) $_POST['delete_id'];
        try {
            // First check if customer has orders
            $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
            $stmt->execute([$delete_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Cannot delete customer because they have existing orders. Consider deactivating them instead.";
            } else {
                $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
                $stmt->execute([$delete_id]);
                $success = "Customer deleted successfully.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting customer: " . $e->getMessage();
        }
    }
}

// Fetch customers
$stmt = $conn->query("SELECT * FROM customers ORDER BY created_at DESC");
$customers = $stmt->fetchAll();

include '../includes/admin_header.php';
?>

<div class="content-header">
    <div>
        <h1>Manage Customers</h1>
        <p>View and manage registered customers.</p>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"
        style="background: #fed7d7; color: #c53030; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"
        style="background: #c6f6d5; color: #22543d; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Joined At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td style="font-weight: 600;">
                            <?php echo htmlspecialchars($customer['username']); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($customer['email']); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?>
                        </td>
                        <td>
                            <?php echo date('M d, Y', strtotime($customer['created_at'])); ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <form method="POST"
                                    onsubmit="return confirm('Are you sure you want to delete this customer?');"
                                    style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo $customer['id']; ?>">
                                    <button type="submit" class="btn-icon delete" title="Delete"><i
                                            class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: #888;">No customers found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>