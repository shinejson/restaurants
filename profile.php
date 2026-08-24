<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit();
}

// Get user details
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get active tab
$tab = isset($_GET['tab']) ? clean_input($_GET['tab']) : 'profile';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $full_name = clean_input($_POST['full_name']);
    $phone = clean_input($_POST['phone']);
    $address = clean_input($_POST['address']);

    $errors = [];

    if (empty($full_name)) {
        $errors[] = 'Full name is required';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE customers SET full_name = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->execute([$full_name, $phone, $address, $_SESSION['user_id']]);

        $success = 'Profile updated successfully!';
        // Refresh user data
        $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $errors = [];

    // Fixed: Using 'password' instead of 'password_hash' to match shared schema
    if (!password_verify($current_password, $user['password'])) {
        $errors[] = 'Current password is incorrect';
    }

    if (strlen($new_password) < 8) {
        $errors[] = 'New password must be at least 8 characters long';
    }

    if ($new_password !== $confirm_password) {
        $errors[] = 'New passwords do not match';
    }

    if (empty($errors)) {
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        // Fixed: password_hash -> password
        $stmt = $conn->prepare("UPDATE customers SET password = ? WHERE id = ?");
        $stmt->execute([$new_password_hash, $_SESSION['user_id']]);

        $success_password = 'Password changed successfully!';
    }
}

// Handle Company Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    if ($_POST['action'] === 'add_company') {
        $name = clean_input($_POST['company_name']);
        $email = clean_input($_POST['company_email']);
        $phone = clean_input($_POST['company_phone']);
        $address = clean_input($_POST['company_address']);
        $location = clean_input($_POST['company_location']);

        $stmt = $conn->prepare("INSERT INTO companies (user_id, name, email, phone, address, location) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $name, $email, $phone, $address, $location]);
        $success_company = "Company added successfully!";
        $tab = 'companies';
    } elseif ($_POST['action'] === 'delete_company') {
        $id = (int) $_POST['company_id'];
        $stmt = $conn->prepare("DELETE FROM companies WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        $success_company = "Company deleted successfully!";
        $tab = 'companies';
    }
}

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $booking_id = (int) $_POST['booking_id'];
    $stmt = $conn->prepare("UPDATE event_bookings SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->execute([$booking_id, $_SESSION['user_id']]);
    $success_booking = "Booking cancelled successfully!";
    $tab = 'events';
}

// Fetch Companies
$stmt = $conn->prepare("SELECT * FROM companies WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$companies = $stmt->fetchAll();

// Get user orders
// Fixed: Using order_reference and total instead of order_ref and total_amount to match schema
$stmt = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$_SESSION['user_id']]);
$orders = $stmt->fetchAll();

// Get user event bookings
$stmt = $conn->prepare("SELECT eb.*, ep.name as package_name, ep.base_price_per_head 
                        FROM event_bookings eb 
                        LEFT JOIN event_packages ep ON eb.package_id = ep.id 
                        WHERE eb.user_id = ? 
                        ORDER BY eb.created_at DESC LIMIT 10");
$stmt->execute([$_SESSION['user_id']]);
$event_bookings = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="profile-container">
    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-avatar">
            <i class="fas fa-user"></i>
        </div>
        <div class="profile-info">
            <h1><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></h1>
            <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
            <p><i class="fas fa-user-tag"></i> Customer Account</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="profile-tabs">
        <button class="tab-button <?php echo $tab === 'profile' ? 'active' : ''; ?>" data-tab="profile">
            <i class="fas fa-user"></i> Profile
        </button>
        <button class="tab-button <?php echo $tab === 'orders' ? 'active' : ''; ?>" data-tab="orders">
            <i class="fas fa-shopping-cart"></i> My Orders
        </button>
        <button class="tab-button <?php echo $tab === 'events' ? 'active' : ''; ?>" data-tab="events">
            <i class="fas fa-calendar-alt"></i> Event Bookings
        </button>
        <button class="tab-button <?php echo $tab === 'password' ? 'active' : ''; ?>" data-tab="password">
            <i class="fas fa-key"></i> Change Password
        </button>
        <button class="tab-button <?php echo $tab === 'companies' ? 'active' : ''; ?>" data-tab="companies">
            <i class="fas fa-building"></i> My Companies
        </button>
        <button class="tab-button <?php echo $tab === 'logout' ? 'active' : ''; ?>" data-tab="logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </div>

    <!-- Profile Tab -->
    <div class="tab-content <?php echo $tab === 'profile' ? 'active' : ''; ?>" id="profileTab">
        <div class="profile-card">
            <h2><i class="fas fa-user-edit"></i> Edit Profile</h2>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($errors) && !empty($errors) && isset($_POST['update_profile'])): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="update_profile" value="1">

                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" class="form-control"
                            value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" class="form-control"
                            value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" class="form-control"
                            value="<?php echo htmlspecialchars($user['full_name'] ?: ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control"
                            value="<?php echo htmlspecialchars($user['phone'] ?: ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Delivery Address</label>
                    <textarea id="address" name="address" class="form-control"
                        rows="3"><?php echo htmlspecialchars($user['address'] ?: ''); ?></textarea>
                </div>

                <button type="submit" class="btn btn-hero">
                    <i class="fas fa-save"></i> Update Profile
                </button>
            </form>
        </div>
    </div>

    <!-- Orders Tab -->
    <div class="tab-content <?php echo $tab === 'orders' ? 'active' : ''; ?>" id="ordersTab">
        <div class="profile-card">
            <h2><i class="fas fa-history"></i> Order History</h2>

            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <i class="fas fa-shopping-cart"></i>
                    <h3>No orders yet</h3>
                    <p>Your order history will appear here</p>
                    <a href="index.php" class="btn btn-hero">
                        <i class="fas fa-utensils"></i> Browse Menu
                    </a>
                </div>
            <?php else: ?>
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['order_reference']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                <td><?php echo format_currency($order['total']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="orders/order_confirmation.php?ref=<?php echo $order['order_reference']; ?>"
                                            class="btn-action btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <button class="btn-action btn-reorder reorder-btn"
                                            data-order-id="<?php echo $order['id']; ?>">
                                            <i class="fas fa-redo"></i> Reorder
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Event Bookings Tab -->
    <div class="tab-content <?php echo $tab === 'events' ? 'active' : ''; ?>" id="eventsTab">
        <div class="profile-card">
            <h2><i class="fas fa-calendar-alt"></i> Event Booking History</h2>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'booking_success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Your event booking has been submitted successfully!
                </div>
            <?php endif; ?>

            <?php if (isset($success_booking)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_booking; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($event_bookings)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <h3>No event bookings yet</h3>
                    <p>Your event booking history will appear here</p>
                    <a href="events.php" class="btn btn-hero">
                        <i class="fas fa-calendar-plus"></i> Book an Event
                    </a>
                </div>
            <?php else: ?>
                <div class="events-bookings-grid">
                    <?php foreach ($event_bookings as $booking): ?>
                        <div class="booking-card">
                            <div class="booking-header">
                                <h3><?php echo htmlspecialchars($booking['package_name'] ?: 'Custom Event'); ?></h3>
                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </div>
                            <div class="booking-details">
                                <div class="detail-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><strong>Date:</strong>
                                        <?php echo date('M d, Y', strtotime($booking['event_date'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-users"></i>
                                    <span><strong>Guests:</strong> <?php echo $booking['guest_count']; ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-money-bill"></i>
                                    <span><strong>Total:</strong>
                                        <?php echo format_currency($booking['total_amount']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-clock"></i>
                                    <span><strong>Booked:</strong>
                                        <?php echo date('M d, Y', strtotime($booking['created_at'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-credit-card"></i>
                                    <span><strong>Payment:</strong>
                                        <span class="payment-status payment-<?php echo $booking['payment_status']; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div class="booking-actions">
                                <button class="btn-action btn-view"
                                    onclick="toggleBookingDetails(<?php echo $booking['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                                <?php if ($booking['package_id']): ?>
                                    <a href="events.php?package_id=<?php echo $booking['package_id']; ?>"
                                        class="btn-action btn-reorder">
                                        <i class="fas fa-redo"></i> Book Again
                                    </a>
                                <?php endif; ?>
                                <?php if ($booking['status'] === 'pending'): ?>
                                    <button class="btn-action btn-cancel"
                                        onclick="confirmCancelBooking(<?php echo $booking['id']; ?>)">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div id="booking-details-<?php echo $booking['id']; ?>" class="booking-extra-details"
                                style="display: none;">
                                <?php
                                // Get materials for this booking
                                $stmt = $conn->prepare("SELECT * FROM event_materials WHERE booking_id = ?");
                                $stmt->execute([$booking['id']]);
                                $materials = $stmt->fetchAll();
                                ?>
                                <?php if (!empty($materials)): ?>
                                    <h4>Additional Materials:</h4>
                                    <ul class="materials-list">
                                        <?php foreach ($materials as $material): ?>
                                            <li>
                                                <?php echo htmlspecialchars($material['name']); ?>
                                                (<?php echo $material['quantity']; ?> ×
                                                <?php echo format_currency($material['unit_price']); ?> =
                                                <?php echo format_currency($material['total_price']); ?>)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Password Tab -->
    <div class="tab-content <?php echo $tab === 'password' ? 'active' : ''; ?>" id="passwordTab">
        <div class="profile-card">
            <h2><i class="fas fa-lock"></i> Change Password</h2>

            <?php if (isset($success_password)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_password; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($errors) && !empty($errors) && isset($_POST['change_password'])): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="change_password" value="1">

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required>
                        <small class="form-text text-muted">At least 8 characters long</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                            required>
                    </div>
                </div>

                <button type="submit" class="btn btn-hero">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </form>
        </div>
    </div>

    <!-- Companies Tab -->
    <div class="tab-content <?php echo $tab === 'companies' ? 'active' : ''; ?>" id="companiesTab">
        <div class="profile-card">
            <div class="profile-card-header">
                <h2><i class="fas fa-building"></i> My Companies</h2>
                <button onclick="document.getElementById('addCompanyForm').style.display='block'"
                    class="btn btn-hero btn-sm">
                    <i class="fas fa-plus"></i> Add Company
                </button>
            </div>

            <?php if (isset($success_company)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_company; ?>
                </div>
            <?php endif; ?>

            <div id="addCompanyForm" class="form-expand-box"
                style="<?php echo empty($companies) ? 'display: block;' : 'display: none;'; ?>">
                <h3><i class="fas fa-plus-circle"></i> Register New Company</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="add_company">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Company Name *</label>
                            <input type="text" name="company_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Contact Email</label>
                            <input type="email" name="company_email" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="company_phone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Location/City</label>
                            <input type="text" name="company_location" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Delivery Address</label>
                        <textarea name="company_address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-hero">Save Company</button>
                        <button type="button" onclick="document.getElementById('addCompanyForm').style.display='none'"
                            class="btn btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>

            <?php if (empty($companies)): ?>
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <h3>No companies saved</h3>
                    <p>Add a company to simplify group orders at checkout.</p>
                </div>
            <?php else: ?>
                <div class="company-grid">
                    <?php foreach ($companies as $comp): ?>
                        <div class="company-card">
                            <h3><?php echo htmlspecialchars($comp['name']); ?></h3>
                            <div class="company-info-small">
                                <span><i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($comp['location'] ?: 'N/A'); ?></span>
                                <span><i class="fas fa-phone"></i>
                                    <?php echo htmlspecialchars($comp['phone'] ?: 'N/A'); ?></span>
                            </div>
                            <form method="POST" class="company-delete-form" onsubmit="return confirm('Delete this company?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete_company">
                                <input type="hidden" name="company_id" value="<?php echo $comp['id']; ?>">
                                <button type="submit" class="btn-text-delete">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="tab-content <?php echo $tab === 'logout' ? 'active' : ''; ?>" id="logoutTab">
        <div class="profile-card logout-confirm-card">
            <div class="logout-icon">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h2>Ready to leave?</h2>
            <p>Are you sure you want to log out of your account?</p>
            <div class="logout-actions">
                <a href="auth/logout.php" class="btn btn-hero btn-danger">
                    Yes, Logout
                </a>
                <button class="btn btn-secondary" onclick="document.querySelector('[data-tab=profile]').click()">
                    Stay Logged In
                </button>
            </div>
        </div>
    </div>
</div>


<script>
    document.querySelectorAll('.tab-button').forEach(button => {
        button.addEventListener('click', function () {
            const tab = this.dataset.tab;
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById(tab + 'Tab').classList.add('active');
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);
        });
    });

    function toggleBookingDetails(bookingId) {
        const detailsDiv = document.getElementById('booking-details-' + bookingId);
        detailsDiv.style.display = detailsDiv.style.display === 'none' ? 'block' : 'none';
    }

    function confirmCancelBooking(bookingId) {
        if (confirm('Are you sure you want to cancel this booking?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="cancel_booking" value="1">
                <input type="hidden" name="booking_id" value="${bookingId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Reorder Functionality
    document.querySelectorAll('.reorder-btn').forEach(btn => {
        btn.addEventListener('click', async function () {
            const orderId = this.dataset.orderId;
            const originalHTML = this.innerHTML;

            try {
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

                const response = await fetch(`${BASE_URL}/ajax/get_order_items.php?order_id=${orderId}`);
                const data = await response.json();

                if (data.success && data.items.length > 0) {
                    // Add items to cart one by one
                    for (const item of data.items) {
                        if (window.cart) {
                            await window.cart.addItem({
                                id: item.id.toString(),
                                name: item.name,
                                price: item.price,
                                image: item.image,
                                quantity: parseInt(item.quantity)
                            });
                        }
                    }

                    // Show success notification and redirect to cart
                    if (window.cart) {
                        window.cart.showNotification('All items from previous order added to cart!', 'success');
                        setTimeout(() => {
                            window.location.href = `${BASE_URL}/cart.php`;
                        }, 1500);
                    }
                } else {
                    alert('Error: ' + (data.message || 'Could not fetch original items.'));
                }
            } catch (error) {
                console.error('Reorder error:', error);
                alert('Something went wrong during reordering.');
            } finally {
                this.disabled = false;
                this.innerHTML = originalHTML;
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>