<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/permissions.php';

$admin_title = 'Search Results';

$query = isset($_GET['q']) ? clean_input($_GET['q']) : '';
$results = [];

if (!empty($query) && strlen($query) >= 2) {
    // Search Orders
    if (has_permission('manage_orders')) {
        $stmt = $conn->prepare("
            SELECT o.id, o.order_reference, o.total, o.status, o.created_at, c.username as customer_name,
                   'order' as type
            FROM orders o
            JOIN customers c ON o.user_id = c.id
            WHERE o.order_reference LIKE ? OR c.username LIKE ? OR c.email LIKE ?
            LIMIT 10
        ");
        $search_term = "%$query%";
        $stmt->execute([$search_term, $search_term, $search_term]);
        $results['orders'] = $stmt->fetchAll();
    }

    // Search Menu Items
    if (has_permission('manage_menu')) {
        $stmt = $conn->prepare("
            SELECT id, item_name, price, inactive, 'menu_item' as type
            FROM food_items
            WHERE item_name LIKE ? OR description LIKE ?
            LIMIT 10
        ");
        $stmt->execute([$search_term, $search_term]);
        $results['menu_items'] = $stmt->fetchAll();
    }

    // Search Customers
    if (has_permission('manage_customers')) {
        $stmt = $conn->prepare("
            SELECT id, username, email, phone, created_at, 'customer' as type
            FROM customers
            WHERE username LIKE ? OR email LIKE ? OR phone LIKE ?
            LIMIT 10
        ");
        $stmt->execute([$search_term, $search_term, $search_term]);
        $results['customers'] = $stmt->fetchAll();
    }

    // Search Events
    if (has_permission('manage_events')) {
        $stmt = $conn->prepare("
            SELECT b.id, p.name as event_name, b.event_date, b.status, 'event' as type
            FROM event_bookings b
            JOIN event_packages p ON b.package_id = p.id
            JOIN customers c ON b.user_id = c.id
            WHERE p.name LIKE ? OR c.full_name LIKE ? OR c.email LIKE ?
            LIMIT 10
        ");
        $stmt->execute([$search_term, $search_term, $search_term]);
        $results['events'] = $stmt->fetchAll();
    }

    // Search Admins
    if (has_permission('manage_admins')) {
        $stmt = $conn->prepare("
            SELECT id, username, email, role, 'admin' as type
            FROM admins
            WHERE username LIKE ? OR email LIKE ?
            LIMIT 10
        ");
        $stmt->execute([$search_term, $search_term]);
        $results['admins'] = $stmt->fetchAll();
    }
}

$total_results = 0;
foreach ($results as $category) {
    $total_results += count($category);
}

include 'includes/admin_header.php';
?>

<div class="content-header">
    <div>
        <h1><i class="fas fa-search"></i> Search Results</h1>
        <p style="color: var(--text-muted);">
            <?php if (!empty($query)): ?>
                Found <strong>
                    <?php echo $total_results; ?>
                </strong> results for "
                <?php echo htmlspecialchars($query); ?>"
            <?php else: ?>
                Enter a search term to find orders, menu items, customers, and more
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (empty($query)): ?>
    <div class="dashboard-card" style="text-align: center; padding: 3rem;">
        <i class="fas fa-search fa-3x" style="color: var(--border-color); margin-bottom: 1rem;"></i>
        <h3>Start Searching</h3>
        <p style="color: var(--text-muted);">Use the search bar above to find orders, menu items, customers, events, and
            more.</p>
    </div>
<?php elseif ($total_results === 0): ?>
    <div class="dashboard-card" style="text-align: center; padding: 3rem;">
        <i class="fas fa-inbox fa-3x" style="color: var(--border-color); margin-bottom: 1rem;"></i>
        <h3>No Results Found</h3>
        <p style="color: var(--text-muted);">Try adjusting your search terms or check the spelling.</p>
    </div>
<?php else: ?>
    <!-- Orders Results -->
    <?php if (!empty($results['orders'])): ?>
        <div class="dashboard-card" style="margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem;"><i class="fas fa-shopping-bag"></i> Orders (
                <?php echo count($results['orders']); ?>)
            </h3>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order Reference</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['orders'] as $order): ?>
                            <tr>
                                <td><strong>
                                        <?php echo htmlspecialchars($order['order_reference']); ?>
                                    </strong></td>
                                <td>
                                    <?php echo htmlspecialchars($order['customer_name']); ?>
                                </td>
                                <td>
                                    <?php echo format_currency($order['total']); ?>
                                </td>
                                <td>
                                    <span
                                        class="badge badge-<?php echo $order['status'] == 'Completed' ? 'success' : 'warning'; ?>">
                                        <?php echo htmlspecialchars($order['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/orders/view.php?id=<?php echo $order['id']; ?>"
                                        class="btn-icon" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Menu Items Results -->
    <?php if (!empty($results['menu_items'])): ?>
        <div class="dashboard-card" style="margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem;"><i class="fas fa-hamburger"></i> Menu Items (
                <?php echo count($results['menu_items']); ?>)
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem;">
                <?php foreach ($results['menu_items'] as $item): ?>
                    <div
                        style="padding: 1rem; border: 1px solid var(--border-color); border-radius: 12px; background: var(--white);">
                        <h4 style="margin: 0 0 0.5rem 0;">
                            <?php echo htmlspecialchars($item['item_name']); ?>
                        </h4>
                        <p style="color: var(--primary-color); font-weight: 700; margin-bottom: 0.5rem;">
                            <?php echo format_currency($item['price']); ?>
                        </p>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <?php if ($item['inactive']): ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php else: ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>/admin/items/edit.php?id=<?php echo $item['id']; ?>"
                                style="margin-left: auto; color: var(--primary-color); text-decoration: none;">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Customers Results -->
    <?php if (!empty($results['customers'])): ?>
        <div class="dashboard-card" style="margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem;"><i class="fas fa-users"></i> Customers (
                <?php echo count($results['customers']); ?>)
            </h3>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['customers'] as $customer): ?>
                            <tr>
                                <td><strong>
                                        <?php echo htmlspecialchars($customer['username']); ?>
                                    </strong></td>
                                <td>
                                    <?php echo htmlspecialchars($customer['email']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($customer['created_at'])); ?>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/customers/index.php" class="btn-icon" title="View All">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Events Results -->
    <?php if (!empty($results['events'])): ?>
        <div class="dashboard-card" style="margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem;"><i class="fas fa-calendar-alt"></i> Events (
                <?php echo count($results['events']); ?>)
            </h3>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['events'] as $event): ?>
                            <tr>
                                <td><strong>
                                        <?php echo htmlspecialchars($event['event_name']); ?>
                                    </strong></td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($event['event_date'])); ?>
                                </td>
                                <td>
                                    <span
                                        class="badge badge-<?php echo $event['status'] == 'confirmed' ? 'success' : 'warning'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($event['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/events/view.php?id=<?php echo $event['id']; ?>"
                                        class="btn-icon" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Admins Results -->
    <?php if (!empty($results['admins'])): ?>
        <div class="dashboard-card" style="margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem;"><i class="fas fa-users-cog"></i> Admins (
                <?php echo count($results['admins']); ?>)
            </h3>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['admins'] as $admin): ?>
                            <tr>
                                <td><strong>
                                        <?php echo htmlspecialchars($admin['username']); ?>
                                    </strong></td>
                                <td>
                                    <?php echo htmlspecialchars($admin['email']); ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $admin['role'] == 'admin' ? 'danger' : 'secondary'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($admin['role'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/admins/edit.php?id=<?php echo $admin['id']; ?>"
                                        class="btn-icon" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>