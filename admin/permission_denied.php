<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';

$admin_title = 'Access Denied';
include 'includes/admin_header.php';
?>

<div style="min-height: 60vh; display: flex; align-items: center; justify-content: center;">
    <div style="text-align: center; max-width: 500px; padding: 2rem;">
        <div style="font-size: 5rem; color: var(--primary-color); margin-bottom: 1rem;">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h1 style="font-size: 2rem; margin-bottom: 1rem; color: var(--text-main);">Access Denied</h1>
        <p style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 2rem;">
            You don't have permission to access this feature. Please contact your system administrator if you believe
            this is an error.
        </p>
        <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
            <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="btn-primary">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
            <a href="javascript:history.back()" class="btn-secondary"
                style="background: var(--light-bg); color: var(--text-main); text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; border: 1px solid var(--border-color); display: inline-flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-arrow-left"></i> Go Back
            </a>
        </div>

        <div
            style="margin-top: 2rem; padding: 1rem; background: var(--light-bg); border-radius: 12px; border: 1px solid var(--border-color);">
            <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">
                <strong>Your Role:</strong>
                <?php
                require_once '../includes/permissions.php';
                echo get_role_name();
                ?>
            </p>
        </div>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>