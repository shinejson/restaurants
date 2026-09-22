<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
include 'includes/header.php';
?>

<div class="container" style="max-width: 900px; margin: 3rem auto; padding: 2rem 1.5rem; background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
    <h1 style="margin-top: 0; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 1rem;">Privacy Policy</h1>
    <p style="color: #64748b; font-size: 0.95rem;">Last updated: <?php echo date('F j, Y'); ?></p>

    <div style="line-height: 1.8; color: #334155; margin-top: 2rem;">
        <h3 style="color: #0f172a;">1. Information We Collect</h3>
        <p>When you create an account, place an order, or make an event reservation at <strong><?php echo htmlspecialchars(get_setting('company_name', 'our restaurant')); ?></strong>, we collect your name, email, phone number, and delivery address to fulfill your orders and keep you updated.</p>

        <h3 style="color: #0f172a; margin-top: 1.5rem;">2. How We Use Your Information</h3>
        <p>Your information is used strictly to process orders, communicate preparation and delivery statuses, and personalize your ordering experience. We never sell your personal information to third parties.</p>

        <h3 style="color: #0f172a; margin-top: 1.5rem;">3. Data Protection</h3>
        <p>Customer accounts and credentials are encrypted using industry-standard password hashing (bcrypt). Customer data is isolated within secure databases.</p>

        <h3 style="color: #0f172a; margin-top: 1.5rem;">4. Your Rights</h3>
        <p>You may view and update your personal information at any time from your <a href="<?php echo tenant_url('profile.php'); ?>" style="color: var(--primary-color); font-weight: 600;">Customer Profile</a>.</p>
    </div>

    <div style="margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #f1f5f9;">
        <a href="<?php echo tenant_url('index.php'); ?>" class="btn" style="background: var(--primary-color); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 600;">
            &larr; Back to Home
        </a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
