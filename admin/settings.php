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

        // Handle hero image upload (gated by hero_customization)
        if (isset($_FILES['hero_image']) && $_FILES['hero_image']['error'] === UPLOAD_ERR_OK) {
            if (function_exists('feature_enabled') && feature_enabled('hero_customization')) {
                $upload_dir = '../assets/uploads/hero/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['hero_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                    $filename = 'hero_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['hero_image']['tmp_name'], $upload_dir . $filename)) {
                        $_POST['settings']['hero_image'] = 'assets/uploads/hero/' . $filename;
                    }
                }
            }
        }

        // Plan feature gates for settings keys
        $featureRequirements = [
            'theme_primary_color'      => 'theme_customization',
            'theme_secondary_color'    => 'theme_customization',
            'theme_card_radius'        => 'theme_customization',
            'menu_card_layout'         => 'menu_card_styles',
            'menu_show_dietary_badges' => 'menu_card_styles',
            'menu_show_calories'       => 'menu_card_styles',
            'hero_title'               => 'hero_customization',
            'hero_subtitle'            => 'hero_customization',
            'hero_cta_text'            => 'hero_customization',
            'hero_cta_link'            => 'hero_customization',
            'hero_image'               => 'hero_customization',
            'promo_banner_enabled'     => 'announcement_banner',
            'promo_banner_text'        => 'announcement_banner',
            'promo_banner_cta_text'    => 'announcement_banner',
            'promo_banner_cta_link'    => 'announcement_banner',
            'white_label_enabled'      => 'white_label',
            'custom_css_rules'         => 'custom_css',
        ];

        $conn->beginTransaction();
        $stmt = $conn->prepare("INSERT INTO settings (category, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($_POST['settings'] as $key => $value) {
            // Guard: If setting belongs to a premium feature, verify entitlement
            if (isset($featureRequirements[$key])) {
                $req = $featureRequirements[$key];
                if (!function_exists('feature_enabled') || !feature_enabled($req)) {
                    continue; // Skip unauthorized setting update
                }
            }

            // Infer the category from the key prefix (matches existing rows)
            if (strpos($key, 'smtp_') === 0) {
                $category = 'email';
            } elseif (strpos($key, 'contact_') === 0) {
                $category = 'contact';
            } elseif (
                strpos($key, 'google_') === 0 ||
                strpos($key, 'facebook_') === 0 ||
                strpos($key, 'apple_') === 0 ||
                strpos($key, 'twitter_') === 0 ||
                strpos($key, 'social_') === 0
            ) {
                $category = 'social';
            } elseif (
                strpos($key, 'theme_') === 0 ||
                strpos($key, 'menu_') === 0 ||
                strpos($key, 'hero_') === 0 ||
                strpos($key, 'section_') === 0 ||
                strpos($key, 'promo_') === 0 ||
                strpos($key, 'white_label') === 0 ||
                strpos($key, 'custom_css') === 0
            ) {
                $category = 'storefront';
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
        <a href="?tab=social" class="tab-btn <?php echo $active_tab == 'social' ? 'active' : ''; ?>"
            style="padding: 1.2rem 2rem; text-decoration: none; color: <?php echo $active_tab == 'social' ? 'var(--primary-color)' : '#666'; ?>; font-weight: 600; border-bottom: 3px solid <?php echo $active_tab == 'social' ? 'var(--primary-color)' : 'transparent'; ?>;">
            <i class="fas fa-share-alt"></i> Social Config
        </a>
        <a href="?tab=storefront" class="tab-btn <?php echo $active_tab == 'storefront' ? 'active' : ''; ?>"
            style="padding: 1.2rem 2rem; text-decoration: none; color: <?php echo $active_tab == 'storefront' ? 'var(--primary-color)' : '#666'; ?>; font-weight: 600; border-bottom: 3px solid <?php echo $active_tab == 'storefront' ? 'var(--primary-color)' : 'transparent'; ?>;">
            <i class="fas fa-palette"></i> Storefront &amp; Theme
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

                    <!-- Restaurant sign-in code -->
                    <div style="display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap; background: var(--light-bg); padding: 1.25rem; border-radius: 12px;">
                        <div style="flex: 1; min-width: 250px;">
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Restaurant
                                sign-in code</label>
                            <input type="text" readonly onclick="this.select()"
                                value="<?php echo htmlspecialchars(current_tenant()?->accessCode() ?? ''); ?>"
                                title="Click to select — copy this code to share it with your staff"
                                style="width: 100%; padding: 0.8rem; border: 1px dashed var(--border-color); border-radius: 8px; background: white; font-family: Consolas, monospace; font-size: 1.15rem; font-weight: 700; letter-spacing: 0.35em; text-align: center; text-transform: uppercase; cursor: pointer;">
                            <small style="color: #777; display: block; margin-top: 0.3rem;">Staff type this code together
                                with their username and password on the sign-in screen — it says which restaurant
                                they are signing into. Share it freely; it is not a password.</small>
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
            <?php elseif ($active_tab == 'social'): ?>
                <?php
                $googleEnabled   = get_setting('google_login_enabled', '0');
                $googleClientId  = get_setting('google_client_id', '');
                $googleSecret    = get_setting('google_client_secret', '');
                $googleCallback  = tenant_url('auth/google_callback.php');

                $facebookEnabled = get_setting('facebook_login_enabled', '0');
                $facebookAppId   = get_setting('facebook_app_id', '');
                $facebookSecret  = get_setting('facebook_app_secret', '');
                $facebookCallback= tenant_url('auth/facebook_callback.php');

                $appleEnabled    = get_setting('apple_login_enabled', '0');
                $appleClientId   = get_setting('apple_client_id', '');
                $appleTeamId     = get_setting('apple_team_id', '');
                $appleKeyId      = get_setting('apple_key_id', '');
                $appleSecret     = get_setting('apple_client_secret', '');
                $appleCallback   = tenant_url('auth/apple_callback.php');

                $twitterEnabled  = get_setting('twitter_login_enabled', '0');
                $twitterClientId = get_setting('twitter_client_id', '');
                $twitterSecret   = get_setting('twitter_client_secret', '');
                $twitterCallback = tenant_url('auth/twitter_callback.php');

                $socialFacebook  = get_setting('social_facebook', '#');
                $socialInstagram = get_setting('social_instagram', '#');
                $socialTwitter   = get_setting('social_twitter', '#');
                $socialLinkedin  = get_setting('social_linkedin', '#');
                ?>

                <div style="margin-bottom: 1.5rem; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 10px; padding: 1rem 1.25rem; color: #0369a1; font-size: 0.92rem; display: flex; align-items: flex-start; gap: 0.75rem;">
                    <i class="fas fa-info-circle" style="font-size: 1.25rem; margin-top: 0.15rem; color: #0284c7;"></i>
                    <div>
                        <strong>Social Login Controls:</strong> Configure OAuth sign-in credentials for your customers below. On the customer login page, buttons only appear for the social services that are <strong>Enabled</strong> and have valid credentials configured.
                    </div>
                </div>

                <div style="display: grid; gap: 2rem; max-width: 820px;">

                    <!-- 1. Google OAuth -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <img src="https://www.svgrepo.com/show/475656/google-color.svg" alt="Google" style="width: 24px; height: 24px;">
                                <div>
                                    <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;">Google Login (Gmail)</h3>
                                    <p style="margin: 0.2rem 0 0; color: #64748b; font-size: 0.85rem;">Allow diners to sign in quickly using their Google account.</p>
                                </div>
                            </div>
                            <div>
                                <?php if ($googleEnabled === '1' && !empty($googleClientId)): ?>
                                    <span style="background: #e6f7ec; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;"><i class="fas fa-check-circle"></i> Active on Login</span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;">Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: grid; gap: 1.25rem;">
                            <div style="max-width: 280px;">
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Google Sign-In Status</label>
                                <select name="settings[google_login_enabled]" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="0" <?php echo $googleEnabled !== '1' ? 'selected' : ''; ?>>Disabled</option>
                                    <option value="1" <?php echo $googleEnabled === '1' ? 'selected' : ''; ?>>Enabled</option>
                                </select>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Client ID</label>
                                    <input type="text" name="settings[google_client_id]" value="<?php echo htmlspecialchars($googleClientId); ?>" placeholder="e.g. 123456789-abc.apps.googleusercontent.com" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Client Secret</label>
                                    <input type="password" name="settings[google_client_secret]" value="<?php echo htmlspecialchars($googleSecret); ?>" placeholder="Enter Google Client Secret" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.35rem;">Authorized Redirect URI (Google Console)</label>
                                <div style="display: flex; gap: 0.5rem;">
                                    <input type="text" readonly value="<?php echo htmlspecialchars($googleCallback); ?>" style="flex: 1; padding: 0.65rem 0.8rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 6px; font-family: monospace; font-size: 0.85rem; color: #475569;">
                                    <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($googleCallback); ?>', this)" style="padding: 0.65rem 1rem; background: #f1f5f9; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">
                                        <i class="far fa-copy"></i> Copy
                                    </button>
                                </div>
                                <small style="color: #777; display: block; margin-top: 0.3rem;">Paste this in Google Cloud Console &rarr; Credentials &rarr; Authorized redirect URIs.</small>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Facebook OAuth -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #1877F2; border-radius: 6px; color: white;">
                                    <i class="fab fa-facebook-f" style="font-size: 1rem;"></i>
                                </span>
                                <div>
                                    <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;">Facebook Login</h3>
                                    <p style="margin: 0.2rem 0 0; color: #64748b; font-size: 0.85rem;">Let customers log in or register instantly using their Facebook profile.</p>
                                </div>
                            </div>
                            <div>
                                <?php if ($facebookEnabled === '1' && !empty($facebookAppId)): ?>
                                    <span style="background: #e6f7ec; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;"><i class="fas fa-check-circle"></i> Active on Login</span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;">Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: grid; gap: 1.25rem;">
                            <div style="max-width: 280px;">
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Facebook Sign-In Status</label>
                                <select name="settings[facebook_login_enabled]" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="0" <?php echo $facebookEnabled !== '1' ? 'selected' : ''; ?>>Disabled</option>
                                    <option value="1" <?php echo $facebookEnabled === '1' ? 'selected' : ''; ?>>Enabled</option>
                                </select>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Facebook App ID</label>
                                    <input type="text" name="settings[facebook_app_id]" value="<?php echo htmlspecialchars($facebookAppId); ?>" placeholder="e.g. 102938475610293" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Facebook App Secret</label>
                                    <input type="password" name="settings[facebook_app_secret]" value="<?php echo htmlspecialchars($facebookSecret); ?>" placeholder="Enter Facebook App Secret" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.35rem;">Valid OAuth Redirect URI (Meta for Developers)</label>
                                <div style="display: flex; gap: 0.5rem;">
                                    <input type="text" readonly value="<?php echo htmlspecialchars($facebookCallback); ?>" style="flex: 1; padding: 0.65rem 0.8rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 6px; font-family: monospace; font-size: 0.85rem; color: #475569;">
                                    <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($facebookCallback); ?>', this)" style="padding: 0.65rem 1rem; background: #f1f5f9; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">
                                        <i class="far fa-copy"></i> Copy
                                    </button>
                                </div>
                                <small style="color: #777; display: block; margin-top: 0.3rem;">Paste this in Meta for Developers &rarr; Facebook Login &rarr; Settings &rarr; Valid OAuth Redirect URIs.</small>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Apple Sign-In -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #000; border-radius: 6px; color: white;">
                                    <i class="fab fa-apple" style="font-size: 1.15rem;"></i>
                                </span>
                                <div>
                                    <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;">Sign in with Apple</h3>
                                    <p style="margin: 0.2rem 0 0; color: #64748b; font-size: 0.85rem;">One-tap sign in for iOS, iPadOS, macOS, and Safari users.</p>
                                </div>
                            </div>
                            <div>
                                <?php if ($appleEnabled === '1' && !empty($appleClientId)): ?>
                                    <span style="background: #e6f7ec; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;"><i class="fas fa-check-circle"></i> Active on Login</span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;">Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: grid; gap: 1.25rem;">
                            <div style="max-width: 280px;">
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Apple Sign-In Status</label>
                                <select name="settings[apple_login_enabled]" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="0" <?php echo $appleEnabled !== '1' ? 'selected' : ''; ?>>Disabled</option>
                                    <option value="1" <?php echo $appleEnabled === '1' ? 'selected' : ''; ?>>Enabled</option>
                                </select>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Services ID (Client ID)</label>
                                    <input type="text" name="settings[apple_client_id]" value="<?php echo htmlspecialchars($appleClientId); ?>" placeholder="e.g. com.yourrestaurant.service" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Team ID</label>
                                    <input type="text" name="settings[apple_team_id]" value="<?php echo htmlspecialchars($appleTeamId); ?>" placeholder="e.g. A1B2C3D4E5" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Key ID</label>
                                    <input type="text" name="settings[apple_key_id]" value="<?php echo htmlspecialchars($appleKeyId); ?>" placeholder="e.g. K123456789" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Private Key / Secret</label>
                                    <input type="password" name="settings[apple_client_secret]" value="<?php echo htmlspecialchars($appleSecret); ?>" placeholder="Enter Apple private key or client secret" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.35rem;">Return URL (Apple Developer Portal)</label>
                                <div style="display: flex; gap: 0.5rem;">
                                    <input type="text" readonly value="<?php echo htmlspecialchars($appleCallback); ?>" style="flex: 1; padding: 0.65rem 0.8rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 6px; font-family: monospace; font-size: 0.85rem; color: #475569;">
                                    <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($appleCallback); ?>', this)" style="padding: 0.65rem 1rem; background: #f1f5f9; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">
                                        <i class="far fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Twitter / X OAuth -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #0f1419; border-radius: 6px; color: white;">
                                    <i class="fab fa-x-twitter" style="font-size: 0.95rem;"></i>
                                </span>
                                <div>
                                    <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;">Twitter / X Login</h3>
                                    <p style="margin: 0.2rem 0 0; color: #64748b; font-size: 0.85rem;">Allow diners to authenticate with their Twitter / X credentials.</p>
                                </div>
                            </div>
                            <div>
                                <?php if ($twitterEnabled === '1' && !empty($twitterClientId)): ?>
                                    <span style="background: #e6f7ec; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;"><i class="fas fa-check-circle"></i> Active on Login</span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;">Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: grid; gap: 1.25rem;">
                            <div style="max-width: 280px;">
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">X Login Status</label>
                                <select name="settings[twitter_login_enabled]" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="0" <?php echo $twitterEnabled !== '1' ? 'selected' : ''; ?>>Disabled</option>
                                    <option value="1" <?php echo $twitterEnabled === '1' ? 'selected' : ''; ?>>Enabled</option>
                                </select>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Client ID (API Key)</label>
                                    <input type="text" name="settings[twitter_client_id]" value="<?php echo htmlspecialchars($twitterClientId); ?>" placeholder="Enter X Client ID" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Client Secret (API Secret)</label>
                                    <input type="password" name="settings[twitter_client_secret]" value="<?php echo htmlspecialchars($twitterSecret); ?>" placeholder="Enter X Client Secret" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.88rem;">
                                </div>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.35rem;">Callback URL (Twitter Developer Portal)</label>
                                <div style="display: flex; gap: 0.5rem;">
                                    <input type="text" readonly value="<?php echo htmlspecialchars($twitterCallback); ?>" style="flex: 1; padding: 0.65rem 0.8rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 6px; font-family: monospace; font-size: 0.85rem; color: #475569;">
                                    <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($twitterCallback); ?>', this)" style="padding: 0.65rem 1rem; background: #f1f5f9; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">
                                        <i class="far fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Social Media Profile Links (Footer) -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-globe" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Storefront Social Profiles</h3>
                            <p style="margin: 0.2rem 0 0; color: #64748b; font-size: 0.85rem;">Links displayed in your restaurant's public footer and contact sections.</p>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;"><i class="fab fa-facebook" style="color: #1877F2;"></i> Facebook Page</label>
                                <input type="url" name="settings[social_facebook]" value="<?php echo htmlspecialchars($socialFacebook); ?>" placeholder="https://facebook.com/yourrestaurant" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;"><i class="fab fa-instagram" style="color: #E1306C;"></i> Instagram Profile</label>
                                <input type="url" name="settings[social_instagram]" value="<?php echo htmlspecialchars($socialInstagram); ?>" placeholder="https://instagram.com/yourrestaurant" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;"><i class="fab fa-x-twitter"></i> X (Twitter) Handle</label>
                                <input type="url" name="settings[social_twitter]" value="<?php echo htmlspecialchars($socialTwitter); ?>" placeholder="https://twitter.com/yourrestaurant" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;"><i class="fab fa-linkedin" style="color: #0A66C2;"></i> LinkedIn Page</label>
                                <input type="url" name="settings[social_linkedin]" value="<?php echo htmlspecialchars($socialLinkedin); ?>" placeholder="https://linkedin.com/company/yourrestaurant" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            </div>
                        </div>
                    </div>

                </div>

                <script>
                function copyToClipboard(text, btn) {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            showCopiedFeedback(btn);
                        }).catch(function() {
                            fallbackCopy(text, btn);
                        });
                    } else {
                        fallbackCopy(text, btn);
                    }
                }
                function fallbackCopy(text, btn) {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showCopiedFeedback(btn);
                }
                function showCopiedFeedback(btn) {
                    var origHtml = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    btn.style.background = '#10b981';
                    btn.style.color = '#ffffff';
                    setTimeout(function() {
                        btn.innerHTML = origHtml;
                        btn.style.background = '';
                        btn.style.color = '';
                    }, 2000);
                }
                </script>
            <?php elseif ($active_tab == 'storefront'): ?>
                <?php
                if (!function_exists('render_feature_badge')) {
                    function render_feature_badge($enabled, $minPlanName = 'Growth') {
                        if ($enabled) {
                            return '<span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #e6f7ec; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;"><i class="fas fa-check-circle"></i> Unlocked</span>';
                        } else {
                            return '<span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #fef2f2; color: #991b1b; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;"><i class="fas fa-lock"></i> Requires ' . htmlspecialchars($minPlanName) . ' Plan</span> <a href="billing.php" style="font-size: 0.8rem; color: #2563eb; font-weight: 600; text-decoration: underline; margin-left: 0.4rem;">Upgrade &rarr;</a>';
                        }
                    }
                }

                $canTheme      = function_exists('feature_enabled') && feature_enabled('theme_customization');
                $canMenuCards  = function_exists('feature_enabled') && feature_enabled('menu_card_styles');
                $canHero       = function_exists('feature_enabled') && feature_enabled('hero_customization');
                $canBanner     = function_exists('feature_enabled') && feature_enabled('announcement_banner');
                $canWhiteLabel = function_exists('feature_enabled') && feature_enabled('white_label');
                $canCustomCss  = function_exists('feature_enabled') && feature_enabled('custom_css');

                $primaryColor = get_setting('theme_primary_color', '#ff6b35');
                $secondaryColor = get_setting('theme_secondary_color', '#0f172a');
                $cardRadius = get_setting('theme_card_radius', '16px');

                $menuLayout = get_setting('menu_card_layout', 'grid');
                $showDietary = get_setting('menu_show_dietary_badges', '1');
                $showCalories = get_setting('menu_show_calories', '0');

                $heroTitle = get_setting('hero_title', '');
                $heroSubtitle = get_setting('hero_subtitle', '');
                $heroCtaText = get_setting('hero_cta_text', '');
                $heroCtaLink = get_setting('hero_cta_link', '');
                $heroImage = get_setting('hero_image', '');

                $showServices = get_setting('section_show_services', '1');
                $showAbout = get_setting('section_show_about', '1');
                $showTestimonials = get_setting('section_show_testimonials', '1');

                $bannerEnabled = get_setting('promo_banner_enabled', '0');
                $bannerText = get_setting('promo_banner_text', '');
                $bannerCtaText = get_setting('promo_banner_cta_text', '');
                $bannerCtaLink = get_setting('promo_banner_cta_link', '');

                $whiteLabelEnabled = get_setting('white_label_enabled', '0');
                $customCssRules = get_setting('custom_css_rules', '');
                ?>

                <div style="display: grid; gap: 2rem; max-width: 820px;">

                    <!-- SECTION 1: Brand Colors & Theme -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-palette" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Brand Colors &amp; Styling</h3>
                                <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.85rem;">Customize primary and dark accents across the public storefront.</p>
                            </div>
                            <div>
                                <?php echo render_feature_badge($canTheme, 'Growth'); ?>
                            </div>
                        </div>

                        <?php if (!$canTheme): ?>
                            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; color: #64748b; font-size: 0.88rem;">
                                <i class="fas fa-info-circle" style="color: #6366f1;"></i> Color theming is a <strong>Growth</strong> feature. The storefront currently uses default platform branding (#ff6b35).
                            </div>
                        <?php endif; ?>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Primary Brand Color</label>
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <input type="color" id="primaryColorPicker" value="<?php echo htmlspecialchars($primaryColor ?: '#ff6b35'); ?>" <?php echo !$canTheme ? 'disabled' : ''; ?>
                                        oninput="document.getElementById('primaryColorText').value = this.value;"
                                        style="width: 48px; height: 42px; border: 1px solid var(--border-color); border-radius: 8px; cursor: <?php echo $canTheme ? 'pointer' : 'not-allowed'; ?>; padding: 2px; background: white;">
                                    <input type="text" id="primaryColorText" name="settings[theme_primary_color]" value="<?php echo htmlspecialchars($primaryColor); ?>" <?php echo !$canTheme ? 'disabled' : ''; ?>
                                        oninput="document.getElementById('primaryColorPicker').value = this.value;"
                                        placeholder="#ff6b35"
                                        style="flex: 1; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace;">
                                </div>
                                <small style="color: #777; display: block; margin-top: 0.3rem;">Buttons, badges, active tabs, and highlights.</small>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Secondary / Dark Accent</label>
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <input type="color" id="secondaryColorPicker" value="<?php echo htmlspecialchars($secondaryColor ?: '#0f172a'); ?>" <?php echo !$canTheme ? 'disabled' : ''; ?>
                                        oninput="document.getElementById('secondaryColorText').value = this.value;"
                                        style="width: 48px; height: 42px; border: 1px solid var(--border-color); border-radius: 8px; cursor: <?php echo $canTheme ? 'pointer' : 'not-allowed'; ?>; padding: 2px; background: white;">
                                    <input type="text" id="secondaryColorText" name="settings[theme_secondary_color]" value="<?php echo htmlspecialchars($secondaryColor); ?>" <?php echo !$canTheme ? 'disabled' : ''; ?>
                                        oninput="document.getElementById('secondaryColorPicker').value = this.value;"
                                        placeholder="#0f172a"
                                        style="flex: 1; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace;">
                                </div>
                                <small style="color: #777; display: block; margin-top: 0.3rem;">Headers, dark accents, and bold titles.</small>
                            </div>
                        </div>

                        <div style="margin-top: 1.25rem;">
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Card Corner Radius</label>
                            <select name="settings[theme_card_radius]" <?php echo !$canTheme ? 'disabled' : ''; ?>
                                style="width: 100%; max-width: 320px; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                <option value="8px" <?php echo $cardRadius === '8px' ? 'selected' : ''; ?>>8px - Modern / Subtle Corner</option>
                                <option value="16px" <?php echo ($cardRadius === '16px' || empty($cardRadius)) ? 'selected' : ''; ?>>16px - Balanced Rounded (Default)</option>
                                <option value="24px" <?php echo $cardRadius === '24px' ? 'selected' : ''; ?>>24px - Soft Pill Shape</option>
                            </select>
                        </div>
                    </div>

                    <!-- SECTION 2: Menu Card Layout & Dietary Tags -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-th-large" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Menu Card Layout &amp; Badges</h3>
                                <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.85rem;">Select your catalog display layout and dietary badge visibility.</p>
                            </div>
                            <div>
                                <?php echo render_feature_badge($canMenuCards, 'Growth'); ?>
                            </div>
                        </div>

                        <?php if (!$canMenuCards): ?>
                            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; color: #64748b; font-size: 0.88rem;">
                                <i class="fas fa-info-circle" style="color: #6366f1;"></i> Modular card layouts (Bistro &amp; Compact) and dietary tags are available on <strong>Growth</strong> and higher plans.
                            </div>
                        <?php endif; ?>

                        <div style="margin-bottom: 1.25rem;">
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Menu Card Style</label>
                            <select name="settings[menu_card_layout]" <?php echo !$canMenuCards ? 'disabled' : ''; ?>
                                style="width: 100%; max-width: 450px; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                <option value="grid" <?php echo $menuLayout === 'grid' ? 'selected' : ''; ?>>Visual Food Card Grid (Default)</option>
                                <option value="bistro" <?php echo $menuLayout === 'bistro' ? 'selected' : ''; ?>>Bistro Editorial Style (Thumbnails &amp; Dotted Price Leaders)</option>
                                <option value="compact" <?php echo $menuLayout === 'compact' ? 'selected' : ''; ?>>Compact List Row (Space Efficient Horizontal)</option>
                            </select>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Dietary Badges (Veg &amp; Spicy)</label>
                                <select name="settings[menu_show_dietary_badges]" <?php echo !$canMenuCards ? 'disabled' : ''; ?>
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="1" <?php echo $showDietary !== '0' ? 'selected' : ''; ?>>Enabled - Show Veg &amp; Spicy Pills</option>
                                    <option value="0" <?php echo $showDietary === '0' ? 'selected' : ''; ?>>Disabled - Hide Badges</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Calorie Information</label>
                                <select name="settings[menu_show_calories]" <?php echo !$canMenuCards ? 'disabled' : ''; ?>
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="0" <?php echo $showCalories !== '1' ? 'selected' : ''; ?>>Disabled - Hide Calorie Count</option>
                                    <option value="1" <?php echo $showCalories === '1' ? 'selected' : ''; ?>>Enabled - Show kcal Pill</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 3: Homepage Hero Section -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-bullhorn" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Homepage Hero Banner</h3>
                                <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.85rem;">Customize your homepage banner text, CTA button, and promotional image.</p>
                            </div>
                            <div>
                                <?php echo render_feature_badge($canHero, 'Growth'); ?>
                            </div>
                        </div>

                        <?php if (!$canHero): ?>
                            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; color: #64748b; font-size: 0.88rem;">
                                <i class="fas fa-info-circle" style="color: #6366f1;"></i> Hero banner customization is a <strong>Growth</strong> feature.
                            </div>
                        <?php endif; ?>

                        <div style="display: grid; gap: 1.25rem;">
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Hero Headline</label>
                                <input type="text" name="settings[hero_title]" value="<?php echo htmlspecialchars($heroTitle); ?>" <?php echo !$canHero ? 'disabled' : ''; ?>
                                    placeholder="e.g. Enjoy Our Delicious Meal"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            </div>

                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Hero Subtitle / Tagline</label>
                                <textarea name="settings[hero_subtitle]" <?php echo !$canHero ? 'disabled' : ''; ?>
                                    placeholder="e.g. Fresh organic ingredients sourced directly from local producers."
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; min-height: 70px;"><?php echo htmlspecialchars($heroSubtitle); ?></textarea>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">CTA Button Label</label>
                                    <input type="text" name="settings[hero_cta_text]" value="<?php echo htmlspecialchars($heroCtaText); ?>" <?php echo !$canHero ? 'disabled' : ''; ?>
                                        placeholder="e.g. Order Now or Book A Table"
                                        style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">CTA Button Link</label>
                                    <input type="text" name="settings[hero_cta_link]" value="<?php echo htmlspecialchars($heroCtaLink); ?>" <?php echo !$canHero ? 'disabled' : ''; ?>
                                        placeholder="e.g. menu.php or #contact"
                                        style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                </div>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Custom Hero Image</label>
                                <div style="display: flex; gap: 1rem; align-items: center;">
                                    <?php if (!empty($heroImage)): ?>
                                        <img src="../<?php echo htmlspecialchars(ltrim($heroImage, '/')); ?>" alt="Hero"
                                            style="width: 90px; height: 60px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color);">
                                    <?php endif; ?>
                                    <input type="file" name="hero_image" accept="image/*" <?php echo !$canHero ? 'disabled' : ''; ?>
                                        style="flex: 1; padding: 0.55rem; border: 1px solid var(--border-color); border-radius: 8px; background: white;">
                                </div>
                                <input type="hidden" name="settings[hero_image]" value="<?php echo htmlspecialchars($heroImage); ?>">
                                <small style="color: #777; display: block; margin-top: 0.3rem;">Recommended: PNG or WebP with transparent or solid background.</small>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 4: Top Announcement Bar -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-bullhorn" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Storefront Announcement Bar</h3>
                                <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.85rem;">Display a prominent notification or promo ribbon at the very top of your storefront.</p>
                            </div>
                            <div>
                                <?php echo render_feature_badge($canBanner, 'Growth'); ?>
                            </div>
                        </div>

                        <?php if (!$canBanner): ?>
                            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; color: #64748b; font-size: 0.88rem;">
                                <i class="fas fa-info-circle" style="color: #6366f1;"></i> Top announcement banner is available on <strong>Growth</strong> and higher plans.
                            </div>
                        <?php endif; ?>

                        <div style="display: grid; gap: 1.25rem;">
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Announcement Bar Status</label>
                                <select name="settings[promo_banner_enabled]" <?php echo !$canBanner ? 'disabled' : ''; ?>
                                    style="width: 100%; max-width: 300px; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="0" <?php echo $bannerEnabled !== '1' ? 'selected' : ''; ?>>Disabled</option>
                                    <option value="1" <?php echo $bannerEnabled === '1' ? 'selected' : ''; ?>>Enabled (Active at top of storefront)</option>
                                </select>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Banner Message</label>
                                <input type="text" name="settings[promo_banner_text]" value="<?php echo htmlspecialchars($bannerText); ?>" <?php echo !$canBanner ? 'disabled' : ''; ?>
                                    placeholder="e.g. 🎉 Get 20% off all orders this Friday with code FRIDAY20!"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Action Link Label (Optional)</label>
                                    <input type="text" name="settings[promo_banner_cta_text]" value="<?php echo htmlspecialchars($bannerCtaText); ?>" <?php echo !$canBanner ? 'disabled' : ''; ?>
                                        placeholder="e.g. Order Now"
                                        style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Action Link URL (Optional)</label>
                                    <input type="text" name="settings[promo_banner_cta_link]" value="<?php echo htmlspecialchars($bannerCtaLink); ?>" <?php echo !$canBanner ? 'disabled' : ''; ?>
                                        placeholder="e.g. menu.php?category=Specials"
                                        style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 5: Homepage Section Visibility -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-eye" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Homepage Section Toggles</h3>
                                <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.85rem;">Control which sections appear on your homepage.</p>
                            </div>
                            <div>
                                <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #e6f7ec; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px; text-transform: uppercase;"><i class="fas fa-check-circle"></i> Included</span>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem;">
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Master Chefs / Services</label>
                                <select name="settings[section_show_services]"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="1" <?php echo $showServices !== '0' ? 'selected' : ''; ?>>Visible</option>
                                    <option value="0" <?php echo $showServices === '0' ? 'selected' : ''; ?>>Hidden</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">About Us Block</label>
                                <select name="settings[section_show_about]"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="1" <?php echo $showAbout !== '0' ? 'selected' : ''; ?>>Visible</option>
                                    <option value="0" <?php echo $showAbout === '0' ? 'selected' : ''; ?>>Hidden</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Testimonials Block</label>
                                <select name="settings[section_show_testimonials]"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                    <option value="1" <?php echo $showTestimonials !== '0' ? 'selected' : ''; ?>>Visible</option>
                                    <option value="0" <?php echo $showTestimonials === '0' ? 'selected' : ''; ?>>Hidden</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 6: White-Label Branding -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-certificate" style="color: var(--primary-color); margin-right: 0.5rem;"></i> White-Label Branding</h3>
                                <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.85rem;">Remove platform branding for a 100% white-labeled dining experience.</p>
                            </div>
                            <div>
                                <?php echo render_feature_badge($canWhiteLabel, 'Pro'); ?>
                            </div>
                        </div>

                        <?php if (!$canWhiteLabel): ?>
                            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; color: #64748b; font-size: 0.88rem;">
                                <i class="fas fa-lock" style="color: #991b1b;"></i> White-labeling requires the <strong>Pro</strong> or <strong>Enterprise</strong> subscription plan.
                            </div>
                        <?php endif; ?>

                        <div>
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">Platform Watermark in Footer</label>
                            <select name="settings[white_label_enabled]" <?php echo !$canWhiteLabel ? 'disabled' : ''; ?>
                                style="width: 100%; max-width: 450px; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;">
                                <option value="0" <?php echo $whiteLabelEnabled !== '1' ? 'selected' : ''; ?>>Show "Powered by RestaurantOS" (Default)</option>
                                <option value="1" <?php echo $whiteLabelEnabled === '1' ? 'selected' : ''; ?>>Hide Watermark (100% White-Label)</option>
                            </select>
                        </div>
                    </div>

                    <!-- SECTION 7: Custom CSS -->
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.15rem; color: #1e293b;"><i class="fas fa-code" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Custom CSS Stylesheet</h3>
                                <p style="margin: 0.25rem 0 0; color: #64748b; font-size: 0.85rem;">Inject custom CSS stylesheets directly into your customer storefront head.</p>
                            </div>
                            <div>
                                <?php echo render_feature_badge($canCustomCss, 'Pro'); ?>
                            </div>
                        </div>

                        <?php if (!$canCustomCss): ?>
                            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; color: #64748b; font-size: 0.88rem;">
                                <i class="fas fa-lock" style="color: #991b1b;"></i> Custom CSS injection is a <strong>Pro</strong> tier feature.
                            </div>
                        <?php endif; ?>

                        <div>
                            <label style="display: block; font-weight: 700; color: #555; margin-bottom: 0.5rem;">CSS Rules</label>
                            <textarea name="settings[custom_css_rules]" rows="6" <?php echo !$canCustomCss ? 'disabled' : ''; ?>
                                placeholder="/* e.g. .main-header { box-shadow: 0 4px 20px rgba(0,0,0,0.1); } */"
                                style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; font-family: Consolas, monospace; font-size: 0.88rem; line-height: 1.4;"><?php echo htmlspecialchars($customCssRules); ?></textarea>
                            <small style="color: #777; display: block; margin-top: 0.3rem;">HTML tags are stripped for security. Pure CSS rules only.</small>
                        </div>
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