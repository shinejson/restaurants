<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

require_once '../includes/permissions.php';

// Check permission
require_permission('manage_settings');

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] == $_SESSION['csrf_token']) {
    try {
        // Handle company logo upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/uploads/company/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                $filename = 'logo_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_dir . $filename)) {
                    $_POST['settings']['company_logo'] = 'assets/uploads/company/' . $filename;
                }
            }
        }

        $conn->beginTransaction();
        $stmt = $conn->prepare("INSERT INTO settings (category, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($_POST['settings'] as $key => $value) {
            // Infer the category from the key prefix (matches existing rows)
            if (strpos($key, 'smtp_') === 0) {
                $category = 'email';
            } elseif (strpos($key, 'contact_') === 0) {
                $category = 'contact';
            } else {
                $category = 'company';
            }
            $stmt->execute([$category, $key, $value]);
        }
        $conn->commit();
        $_SESSION['success_msg'] = "Settings updated successfully.";
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error_msg'] = "Error updating settings: " . $e->getMessage();
    }
    header("Location: settings.php" . (isset($_POST['active_tab']) ? "?tab=" . $_POST['active_tab'] : ""));
    exit();
}

// Fetch all settings
$stmt = $conn->query("SELECT * FROM settings");
$settings_raw = $stmt->fetchAll();
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['category']][$s['setting_key']] = $s['setting_value'];
}

$active_tab = $_GET['tab'] ?? 'company';
$admin_title = 'System Settings';
include 'includes/admin_header.php';
?>

<div style="margin-bottom: 2rem;">
    <h1 style="margin: 0;"><i class="fas fa-cog"></i> System Settings</h1>
    <p style="margin: 0.5rem 0 0; color: var(--text-muted);">Configure your restaurant information and system
        preferences.</p>
</div>

<?php if (isset($_SESSION['success_msg'])): ?>
    <div class="alert alert-success"
        style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i>
        <?php echo $_SESSION['success_msg'];
        unset($_SESSION['success_msg']); ?>
    </div>
<?php endif; ?>

<div class="dashboard-card" style="padding: 0;">
    <!-- Tab Navigation -->
    <div
        style="display: flex; border-bottom: 1px solid var(--border-color); background: #f8f9fa; border-radius: 12px 12px 0 0;">
        <a href="?tab=company" class="tab-btn <?php echo $active_tab == 'company' ? 'active' : ''; ?>"
            style="padding: 1.2rem 2rem; text-decoration: none; color: <?php echo $active_tab == 'company' ? 'var(--primary-color)' : '#666'; ?>; font-weight: 600; border-bottom: 3px solid <?php echo $active_tab == 'company' ? 'var(--primary-color)' : 'transparent'; ?>;">
            <i class="fas fa-building"></i> Company Info
        </a>
        <a href="?tab=contact" class="tab-btn <?php echo $active_tab == 'contact' ? 'active' : ''; ?>"
            style="padding: 1.2rem 2rem; text-decoration: none; color: <?php echo $active_tab == 'contact' ? 'var(--primary-color)' : '#666'; ?>; font-weight: 600; border-bottom: 3px solid <?php echo $active_tab == 'contact' ? 'var(--primary-color)' : 'transparent'; ?>;">
            <i class="fas fa-address-book"></i> Contact Details
        </a>
        <a href="?tab=email" class="tab-btn <?php echo $active_tab == 'email' ? 'active' : ''; ?>"
            style="padding: 1.2rem 2rem; text-decoration: none; color: <?php echo $active_tab == 'email' ? 'var(--primary-color)' : '#666'; ?>; font-weight: 600; border-bottom: 3px solid <?php echo $active_tab == 'email' ? 'var(--primary-color)' : 'transparent'; ?>;">
            <i class="fas fa-envelope"></i> Email Config
        </a>
    </div>

    <!-- Tab Content -->
    <div style="padding: 2.5rem;">
    <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="active_tab" value="<?php echo $active_tab; ?>">

            <?php if ($active_tab == 'company'): ?>
                <div style="display: grid; gap: 1.5rem; max-width: 700px;">

                    <!-- Company Logo -->
                    <div style="display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap; background: var(--light-bg); padding: 1.25rem; border-radius: 12px;">
                        <div
                            style="width: 90px; height: 90px; border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: white; flex-shrink: 0;">
                            <?php if (!empty($settings['company']['company_logo'])): ?>
                                <img src="../<?php echo htmlspecialchars($settings['company']['company_logo']); ?>"
                                    style="max-width: 100%; max-height: 100%; object-fit: contain;">
                            <?php else: ?>
                                <i class="fas fa-image fa-2x" style="color: #cbd5e1;"></i>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1; min-width: 250px;">
                            <label
                                style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Company
                                Logo</label>
                            <input type="file" name="company_logo" accept="image/*"
                                style="width: 100%; padding: 0.55rem; border: 1px solid var(--border-color); border-radius: 8px; background: white;">
                            <small style="color: #777; display: block; margin-top: 0.3rem;">Shown on receipts and printed
                                tickets. JPG, PNG, WebP or SVG.</small>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div>
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Restaurant
                                Name</label>
                            <input type="text" name="settings[company_name]"
                                value="<?php echo htmlspecialchars($settings['company']['company_name'] ?? ''); ?>"
                                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                        </div>
                        <div>
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">TIN Number
                                (Tax Identification)</label>
                            <input type="text" name="settings[company_tin]"
                                value="<?php echo htmlspecialchars($settings['company']['company_tin'] ?? ''); ?>"
                                placeholder="e.g. C0001234567-1"
                                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div>
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">VAT
                                Registration Number</label>
                            <input type="text" name="settings[company_vat_reg]"
                                value="<?php echo htmlspecialchars($settings['company']['company_vat_reg'] ?? ''); ?>"
                                placeholder="e.g. VAT-1234567"
                                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                        </div>
                        <div>
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Location
                                (Branch / Area)</label>
                            <input type="text" name="settings[company_location]"
                                value="<?php echo htmlspecialchars($settings['company']['company_location'] ?? ''); ?>"
                                placeholder="e.g. Osu - Accra, near Independence Square"
                                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Website</label>
                        <input type="url" name="settings[company_website]"
                            value="<?php echo htmlspecialchars($settings['company']['company_website'] ?? ''); ?>"
                            placeholder="e.g. https://www.yourrestaurant.com"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Company Bio /
                            Tagline</label>
                        <textarea name="settings[company_info]"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; min-height: 100px;"><?php echo htmlspecialchars($settings['company']['company_info'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Site Favicon
                            Path</label>
                        <div style="display: flex; gap: 1rem; align-items: center;">
                            <input type="text" name="settings[site_favicon]"
                                value="<?php echo htmlspecialchars($settings['company']['site_favicon'] ?? 'assets/images/favicon/favicon.ico'); ?>"
                                style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            <?php if (!empty($settings['company']['site_favicon'])): ?>
                                <div
                                    style="width: 40px; height: 40px; background: white; border-radius: 4px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                    <img src="../<?php echo htmlspecialchars($settings['company']['site_favicon']); ?>"
                                        style="max-width: 100%; max-height: 100%;"
                                        onerror="this.src='../assets/images/favicon/favicon.ico'">
                                </div>
                            <?php endif; ?>
                        </div>
                        <small style="color: #777; display: block; margin-top: 0.3rem;">Path relative to root (e.g.,
                            assets/images/favicon/favicon.ico)</small>
                    </div>
                </div>

            <?php elseif ($active_tab == 'contact'): ?>
                <div style="display: grid; gap: 1.5rem; max-width: 600px;">
                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Support
                            Email</label>
                        <input type="email" name="settings[contact_email]"
                            value="<?php echo htmlspecialchars($settings['contact']['contact_email'] ?? ''); ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Phone
                            Number</label>
                        <input type="text" name="settings[contact_phone]"
                            value="<?php echo htmlspecialchars($settings['contact']['contact_phone'] ?? ''); ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Physical
                            Address</label>
                        <textarea name="settings[contact_address]"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; min-height: 80px;"><?php echo htmlspecialchars($settings['contact']['contact_address'] ?? ''); ?></textarea>
                    </div>
                </div>

            <?php elseif ($active_tab == 'email'): ?>
                <div style="display: grid; gap: 1.5rem; max-width: 600px;">
                    <div
                        style="background: #fff3cd; color: #856404; padding: 1rem; border-radius: 8px; border: 1px solid #ffeeba; font-size: 0.9rem;">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> Configure your SMTP server
                        details below.
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">SMTP
                            Host</label>
                        <input type="text" name="settings[smtp_host]"
                            value="<?php echo htmlspecialchars($settings['email']['smtp_host'] ?? 'smtp.gmail.com'); ?>"
                            placeholder="e.g. smtp.gmail.com"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">SMTP
                                Port</label>
                            <input type="text" name="settings[smtp_port]"
                                value="<?php echo htmlspecialchars($settings['email']['smtp_port'] ?? '587'); ?>"
                                placeholder="e.g. 587"
                                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                        </div>
                        <div>
                            <label
                                style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Encryption</label>
                            <select name="settings[smtp_encryption]"
                                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                <option value="tls" <?php echo ($settings['email']['smtp_encryption'] ?? '') == 'tls' ? 'selected' : ''; ?>>TLS (Recommended)</option>
                                <option value="ssl" <?php echo ($settings['email']['smtp_encryption'] ?? '') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="none" <?php echo ($settings['email']['smtp_encryption'] ?? '') == 'none' ? 'selected' : ''; ?>>None</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">SMTP Username
                            (Email)</label>
                        <input type="email" name="settings[smtp_username]"
                            value="<?php echo htmlspecialchars($settings['email']['smtp_username'] ?? ''); ?>"
                            placeholder="e.g. your-email@gmail.com"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">SMTP
                            Password</label>
                        <input type="password" name="settings[smtp_password]"
                            value="<?php echo htmlspecialchars($settings['email']['smtp_password'] ?? ''); ?>"
                            placeholder="Enter App Password or SMTP Password"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px;">
                    </div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 2.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                <button type="submit" class="btn-submit"
                    style="background: var(--primary-color); color: white; border: none; padding: 1rem 2.5rem; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 1rem; box-shadow: 0 4px 12px rgba(255,107,53,0.3);">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>