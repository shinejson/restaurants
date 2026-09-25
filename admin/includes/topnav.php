<?php
// admin/includes/topnav.php
$notif_stmt = $conn->prepare("SELECT id, order_reference, status FROM orders ORDER BY created_at DESC LIMIT 5");
$notif_stmt->execute();
$notifications = $notif_stmt->fetchAll();

$pending_count_stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending'");
$pending_count = $pending_count_stmt->fetchColumn();

// Fetch current admin details for the profile modal
$admin_details = null;
if (isset($_SESSION['admin_id'])) {
    $stmt = $conn->prepare("SELECT username, email, role, created_at FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin_details = $stmt->fetch();
}
?>
<header class="admin-topnav">
    <div class="topnav-left">
        <button id="toggleSidebar" class="icon-btn" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="topnav-brand" title="Go to Dashboard">
            <span class="topnav-brand-icon"><i class="fas fa-utensils"></i></span>
            <span class="topnav-brand-name"><?php echo htmlspecialchars(get_setting('company_name', 'Restaurant Panel')); ?></span>
        </a>
        <form action="<?php echo BASE_URL; ?>/admin/search.php" method="GET" class="search-bar"
            style="display: flex; align-items: center; gap: 0.5rem; background: var(--white); padding: 0.5rem 1rem; border-radius: 12px; border: 1px solid var(--border-color); flex: 1; max-width: 500px;">
            <i class="fas fa-search" style="color: var(--text-muted);"></i>
            <input type="text" name="q" placeholder="Search orders, items, customers..."
                value="<?php echo isset($_GET['q']) ? htmlspecialchars($_GET['q']) : ''; ?>"
                style="border: none; background: none; flex: 1; padding: 0.25rem; outline: none; font-size: 0.95rem;">
        </form>
    </div>

    <div class="topnav-right">
        <button id="themeToggle" class="icon-btn" title="Toggle Dark Mode">
            <i class="fas fa-moon"></i>
        </button>

        <div class="notification-dropdown">
            <button class="icon-btn" id="notifBtn">
                <i class="fas fa-bell"></i>
                <?php if ($pending_count > 0): ?>
                    <span class="badge"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-content" id="notifDropdown">
                <div class="dropdown-header">Recent Orders</div>
                <?php if (empty($notifications)): ?>
                    <div style="padding: 1rem; text-align: center; color: #888;">No recent orders</div>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                        <?php
                        $notif_icon = 'fa-shopping-basket';
                        $notif_class = 'text-info';
                        if ($notif['status'] == 'Pending') {
                            $notif_class = 'text-warning';
                        } elseif ($notif['status'] == 'Completed') {
                            $notif_class = 'text-success';
                        }
                        ?>
                        <a href="<?php echo BASE_URL; ?>/admin/orders/view.php?id=<?php echo $notif['id']; ?>">
                            <i class="fas <?php echo $notif_icon; ?> <?php echo $notif_class; ?>"></i>
                            Order #<?php echo htmlspecialchars($notif['order_reference']); ?>
                            <small>(<?php echo $notif['status']; ?>)</small>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/admin/orders/" class="view-all">View All Orders</a>
            </div>
        </div>

        <?php
        $admin_display_name = $admin_details['username'] ?? ($_SESSION['user_name'] ?? 'Admin');
        $admin_role_label   = isset($admin_details['role']) ? ucfirst($admin_details['role']) : 'Staff';
        ?>
        <div class="user-profile-dropdown">
            <button class="profile-btn" id="profileBtn">
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin_display_name); ?>&background=ff6b35&color=fff" alt="Profile">
                <span class="profile-btn-text">
                    <span class="profile-btn-name"><?php echo htmlspecialchars($admin_display_name); ?></span>
                    <small class="profile-btn-role"><?php echo htmlspecialchars($admin_role_label); ?></small>
                </span>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="dropdown-content" id="profileDropdown">
                <div class="profile-dropdown-header">
                    <div class="profile-dropdown-avatar">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin_display_name); ?>&background=ff6b35&color=fff&size=128" alt="Profile">
                    </div>
                    <div class="profile-dropdown-meta">
                        <strong><?php echo htmlspecialchars($admin_display_name); ?></strong>
                        <span><?php echo htmlspecialchars($admin_role_label); ?></span>
                    </div>
                </div>
                <div class="dropdown-restaurant-label">
                    <i class="fas fa-store"></i>
                    <?php echo htmlspecialchars(get_setting('company_name', 'Restaurant Panel')); ?>
                </div>
                <div class="profile-dropdown-menu">
                    <a href="javascript:void(0)" id="openProfileModal"><i class="fas fa-user-circle"></i> <span>My Profile</span></a>
                    <a href="<?php echo BASE_URL; ?>/admin/settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a>
                    <a href="<?php echo BASE_URL; ?>/" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> <span>View Storefront</span></a>
                </div>
                <div class="profile-dropdown-footer">
                    <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
                </div>
            </div>
        </div>
    </div>

</header>

<!-- Admin Profile Modal -->
<div id="adminProfileModal" class="admin-modal">
    <div class="modal-content glass-card">
        <div class="modal-header">
            <h2><i class="fas fa-user-shield"></i> Admin Profile</h2>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($admin_details): ?>
                <div class="profile-info-grid">
                    <div class="profile-avatar-large">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin_details['username']); ?>&background=ff6b35&color=fff&size=128"
                            alt="Admin">
                    </div>
                    <div class="profile-details">
                        <div class="info-group">
                            <label>Username</label>
                            <p><?php echo htmlspecialchars($admin_details['username']); ?></p>
                        </div>
                        <div class="info-group">
                            <label>Email Address</label>
                            <p><?php echo htmlspecialchars($admin_details['email'] ?: 'Not set'); ?></p>
                        </div>
                        <div class="info-group">
                            <label>System Role</label>
                            <p><span class="badge-role"><?php
                                echo htmlspecialchars(function_exists('get_role_name')
                                    ? get_role_name($admin_details['role'])
                                    : ucfirst($admin_details['role']));
                            ?></span></p>
                        </div>
                        <div class="info-group">
                            <label>Account Created</label>
                            <p><?php echo date('M d, Y', strtotime($admin_details['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-error">Error loading profile details.</p>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <a href="<?php echo BASE_URL; ?>/admin/admins/edit.php?id=<?php echo $_SESSION['admin_id']; ?>"
                class="btn-edit-profile">
                <i class="fas fa-edit"></i> Edit Account
            </a>
        </div>
    </div>
</div>

<style>
    .admin-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        align-items: center;
        /* Vertical centering */
        justify-content: center;
        /* Horizontal centering */
        overflow-y: auto;
        /* Allow scroll if content is too large */
        padding: 40px 0;
        /* Space at top/bottom when scrolling */
    }

    .admin-modal.active {
        display: flex;
    }

    .modal-content.glass-card {
        background: var(--white);
        /* Use variable-based background */
        border: 1px solid var(--glass-border);
        width: 90%;
        max-width: 500px;
        border-radius: 20px;
        padding: 2rem;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        animation: modalFadeIn 0.3s ease-out;
        margin: auto;
        /* Auto margin for flex centering */
        position: relative;
    }

    @keyframes modalFadeIn {
        from {
            transform: translateY(-30px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 1rem;
    }

    .modal-header h2 {
        margin: 0;
        font-size: 1.5rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .close-modal {
        background: none;
        border: none;
        font-size: 2rem;
        cursor: pointer;
        color: var(--text-muted);
    }

    .profile-info-grid {
        display: grid;
        grid-template-columns: 120px 1fr;
        gap: 2rem;
        align-items: start;
    }

    .profile-avatar-large img {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        border: 4px solid var(--primary-color);
        padding: 4px;
    }

    .info-group {
        margin-bottom: 1.25rem;
    }

    .info-group label {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.05em;
        margin-bottom: 4px;
    }

    .info-group p {
        margin: 0;
        font-weight: 600;
        font-size: 1rem;
    }

    .badge-role {
        background: var(--primary-color);
        color: white;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
    }

    .modal-footer {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-color);
        text-align: right;
    }

    .btn-edit-profile {
        background: var(--primary-color);
        color: white;
        text-decoration: none;
        padding: 0.75rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: var(--transition);
    }

    .btn-edit-profile:hover {
        opacity: 0.9;
        transform: translateY(-2px);
    }

    .profile-dropdown-header {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 1.1rem 1.2rem 0.9rem;
        background: linear-gradient(135deg, rgba(255,107,53,0.12), rgba(255,107,53,0.04));
        border-bottom: 1px solid var(--border-color);
    }

    .profile-dropdown-avatar {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        overflow: hidden;
        border: 2px solid rgba(255,255,255,0.8);
        box-shadow: 0 12px 20px -14px rgba(255,107,53,0.7);
        background: var(--white);
        flex-shrink: 0;
    }

    .profile-dropdown-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-dropdown-meta {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .profile-dropdown-meta strong {
        color: var(--text-main);
        font-size: 0.98rem;
        line-height: 1.25;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .profile-dropdown-meta span {
        color: var(--text-muted);
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .profile-dropdown-menu {
        padding: 0.7rem 0.7rem 0.3rem;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .profile-dropdown-menu a,
    .profile-dropdown-footer a {
        padding: 0.8rem 0.9rem;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 0.8rem;
        font-size: 0.96rem;
        font-weight: 600;
        color: var(--text-main);
        text-decoration: none;
        transition: var(--transition);
    }

    .profile-dropdown-menu a i,
    .profile-dropdown-footer a i {
        width: 18px;
        text-align: center;
        color: var(--text-muted);
        font-size: 0.96rem;
    }

    .profile-dropdown-menu a:hover,
    .profile-dropdown-footer a:hover {
        background: var(--light-bg);
        color: var(--primary-color);
        transform: translateX(2px);
    }

    .profile-dropdown-menu a:hover i,
    .profile-dropdown-footer a:hover i {
        color: var(--primary-color);
    }

    .profile-dropdown-footer {
        padding: 0.35rem 0.7rem 0.7rem;
        border-top: 1px solid var(--border-color);
        margin-top: 0.35rem;
    }

    .profile-dropdown-footer a {
        color: var(--text-main);
    }

    .profile-dropdown-footer .text-danger {
        color: #d14343;
    }

    .profile-dropdown-footer .text-danger:hover {
        color: #c21b1b;
        background: rgba(209, 67, 67, 0.06);
    }

    .profile-dropdown-footer .text-danger i {
        color: #d14343;
    }

    .dropdown-content {
        border-radius: 18px;
        box-shadow: 0 24px 60px -28px rgba(15, 23, 42, 0.35);
    }

    .dropdown-restaurant-label {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.9rem 1.15rem;
        font-weight: 700;
        font-size: 0.8rem;
        color: var(--primary-color);
        background: linear-gradient(135deg, rgba(255,107,53,0.08), rgba(255,107,53,0.03));
        border-bottom: 1px solid var(--border-color);
        letter-spacing: 0.02em;
    }

    .dropdown-restaurant-label i {
        font-size: 0.82rem;
    }

    @media (max-width: 576px) {
        .profile-info-grid {
            grid-template-columns: 1fr;
            text-align: center;
        }

        .profile-avatar-large {
            margin: 0 auto;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('adminProfileModal');
        const openBtn = document.getElementById('openProfileModal');
        const closeBtn = document.querySelector('.close-modal');

        if (openBtn) {
            openBtn.onclick = function (e) {
                e.preventDefault();
                modal.classList.add('active');
                document.getElementById('profileDropdown').classList.remove('show');
            }
        }

        if (closeBtn) {
            closeBtn.onclick = function () {
                modal.classList.remove('active');
            }
        }

        window.onclick = function (event) {
            if (event.target == modal) {
                modal.classList.remove('active');
            }
        }
    });
</script>