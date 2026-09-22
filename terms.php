<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
include 'includes/header.php';
?>

<div class="container" style="max-width: 900px; margin: 3rem auto; padding: 2rem 1.5rem; background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
    <h1 style="margin-top: 0; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 1rem;">Terms of Service</h1>
    <p style="color: #64748b; font-size: 0.95rem;">Last updated: <?php echo date('F j, Y'); ?></p>

    <div style="line-height: 1.8; color: #334155; margin-top: 2rem;">
        <h3 style="color: #0f172a;">1. Acceptance of Terms</h3>
        <p>By creating an account, placing an order, or using the services provided by <strong><?php echo htmlspecialchars(get_setting('company_name', 'this Restaurant')); ?></strong>, you agree to be bound by these Terms of Service.</p>

        <h3 style="color: #0f172a; margin-top: 1.5rem;">2. Orders and Payments</h3>
        <p>All orders placed through our storefront or in-restaurant ordering systems are subject to availability and acceptance. Prices include applicable taxes as indicated. Payment must be completed using authorized methods.</p>

        <h3 style="color: #0f172a; margin-top: 1.5rem;">3. Cancellations & Refunds</h3>
        <p>Order cancellations may be requested before food preparation has commenced. Event bookings may be subject to specific deposit or advance notice terms as specified at booking time.</p>

        <h3 style="color: #0f172a; margin-top: 1.5rem;">4. User Accounts</h3>
        <p>You are responsible for maintaining the confidentiality of your account credentials and for all activities conducted through your customer account.</p>

        <h3 style="color: #0f172a; margin-top: 1.5rem;">5. Contact Us</h3>
        <p>If you have any questions regarding these terms, please <a href="<?php echo tenant_url('contact.php'); ?>" style="color: var(--primary-color); font-weight: 600;">contact our team</a>.</p>
    </div>

    <div style="margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #f1f5f9;">
        <a href="<?php echo tenant_url('index.php'); ?>" class="btn" style="background: var(--primary-color); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 600;">
            &larr; Back to Home
        </a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
