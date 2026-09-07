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

<style>
    /* Page-level table polish (admin.css has no responsive-table helpers) */
    .cust-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .cust-count {
        color: var(--text-muted);
        font-size: 0.9rem;
        font-weight: 600;
    }

    .cust-search {
        position: relative;
    }

    .cust-search i {
        position: absolute;
        left: 0.9rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: 0.85rem;
    }

    .cust-search input {
        padding: 0.65rem 0.9rem 0.65rem 2.4rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background: var(--white);
        color: var(--text-main);
        font-size: 0.9rem;
        min-width: 260px;
        outline: none;
        font-family: inherit;
    }

    .cust-search input:focus {
        border-color: var(--primary-color);
    }

    .cust-table {
        min-width: 720px;
    }

    .cust-table td {
        white-space: nowrap;
    }

    .cust-table td.cust-email {
        max-width: 280px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cust-table th.cust-actions,
    .cust-table td.cust-actions {
        text-align: center;
        width: 80px;
    }

    .cust-table tbody tr:hover td {
        background: rgba(0, 0, 0, 0.02);
    }

    .cust-name-cell {
        display: flex;
        align-items: center;
        gap: 0.7rem;
    }

    .cust-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        color: #fff;
        background: var(--primary-color);
        flex-shrink: 0;
        text-transform: uppercase;
    }

    .cust-muted {
        color: var(--text-muted);
    }
</style>

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

<div class="dashboard-card">
    <div class="cust-toolbar">
        <span class="cust-count" id="custCount"><?php echo count($customers); ?> customer(s)</span>
        <div class="cust-search">
            <i class="fas fa-search"></i>
            <input type="text" id="custSearchInput" placeholder="Search name, email or phone..." autocomplete="off">
        </div>
    </div>

    <?php if (empty($customers)): ?>
        <div style="text-align: center; padding: 3rem;">
            <i class="fas fa-users fa-3x" style="color: var(--border-color); margin-bottom: 1rem;"></i>
            <h3>No customers found</h3>
            <p style="color: var(--text-muted);">Registered customers will appear here.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table cust-table" id="custTable">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Joined</th>
                        <th class="cust-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <?php
                        $display_name = !empty($customer['full_name']) ? $customer['full_name'] : $customer['username'];
                        $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $display_name), 0, 2));
                        if ($initials === '') {
                            $initials = '?';
                        }
                        $search_haystack = strtolower($customer['username'] . ' ' . ($customer['full_name'] ?? '') . ' ' . ($customer['email'] ?? '') . ' ' . ($customer['phone'] ?? ''));
                        ?>
                        <tr data-search="<?php echo htmlspecialchars($search_haystack, ENT_QUOTES); ?>">
                            <td>
                                <div class="cust-name-cell">
                                    <span class="cust-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                    <div>
                                        <div style="font-weight: 700;">
                                            <?php echo htmlspecialchars($display_name); ?>
                                        </div>
                                        <div class="cust-muted" style="font-size: 0.8rem;">
                                            <?php echo htmlspecialchars($customer['username']); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="cust-email" title="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                                <?php echo !empty($customer['email']) ? htmlspecialchars($customer['email']) : '<span class="cust-muted">N/A</span>'; ?>
                            </td>
                            <td>
                                <?php echo !empty($customer['phone']) ? htmlspecialchars($customer['phone']) : '<span class="cust-muted">N/A</span>'; ?>
                            </td>
                            <td class="cust-muted">
                                <?php echo !empty($customer['created_at']) ? date('M d, Y', strtotime($customer['created_at'])) : 'N/A'; ?>
                            </td>
                            <td class="cust-actions">
                                <form method="POST"
                                    onsubmit="return confirm('Are you sure you want to delete this customer?');"
                                    style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo $customer['id']; ?>">
                                    <button type="submit" class="btn-icon delete" title="Delete"><i
                                            class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="custNoResults" style="display:none;">
                        <td colspan="5" style="text-align: center; padding: 2rem; color: #888;">No customers match your
                            search.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    // Live client-side customer search
    const custSearchInput = document.getElementById('custSearchInput');
    const custRows = document.querySelectorAll('#custTable tbody tr[data-search]');
    const custCountEl = document.getElementById('custCount');
    const custNoResults = document.getElementById('custNoResults');
    const custTotal = custRows.length;

    if (custSearchInput) {
        custSearchInput.addEventListener('input', () => {
            const query = custSearchInput.value.trim().toLowerCase();
            let visible = 0;

            custRows.forEach(row => {
                const haystack = row.dataset.search || '';
                const show = query === '' || haystack.includes(query);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (custNoResults) {
                custNoResults.style.display = visible === 0 ? '' : 'none';
            }
            custCountEl.textContent = visible + ' of ' + custTotal + ' customer(s)';
        });
    }
</script>

<?php include '../includes/admin_footer.php'; ?>