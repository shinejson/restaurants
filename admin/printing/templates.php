<?php
// admin/printing/templates.php
// Receipt template configuration with live preview for KOT, BOT, Order & Payment receipts.
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once __DIR__ . '/ticket_renderer.php';

require_permission('manage_settings');

// Ensure tables/settings exist
$setup_check = $conn->query("SHOW TABLES LIKE 'printers'");
if (!$setup_check->fetch()) {
    header('Location: ../setup_printing_tables.php');
    exit();
}

$templates = [
    'kot'     => ['label' => 'Kitchen Ticket (KOT)', 'icon' => 'fa-utensils'],
    'bot'     => ['label' => 'Bar Ticket (BOT)', 'label_short' => 'Bar (BOT)', 'icon' => 'fa-wine-glass-alt'],
    'order'   => ['label' => 'Order Receipt (Before Payment)', 'label_short' => 'Order Receipt', 'icon' => 'fa-concierge-bell'],
    'payment' => ['label' => 'Payment Receipt (After Payment)', 'label_short' => 'Payment Receipt', 'icon' => 'fa-money-check-alt'],
];

$alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']) && ($_POST['action'] ?? '') === 'save_template') {
    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("INSERT INTO settings (category, setting_key, setting_value) VALUES ('printing', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($_POST['settings'] as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        $conn->commit();
        $alert = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Template saved successfully.</div>';
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $alert = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Fetch printing settings
$p = [];
foreach ($conn->query("SELECT setting_key, setting_value FROM settings WHERE category = 'printing'")->fetchAll() as $row) {
    $p[$row['setting_key']] = $row['setting_value'];
}

// Company info for the ticket header
$company = ['name' => '', 'address' => '', 'phone' => '', 'logo' => ''];
try {
    foreach ($conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name','contact_address','contact_phone','company_logo')")->fetchAll() as $row) {
        if ($row['setting_key'] === 'company_name') {
            $company['name'] = $row['setting_value'];
        } elseif ($row['setting_key'] === 'contact_address') {
            $company['address'] = $row['setting_value'];
        } elseif ($row['setting_key'] === 'contact_phone') {
            $company['phone'] = $row['setting_value'];
        } elseif ($row['setting_key'] === 'company_logo') {
            // Path is relative to site root; this page lives in /admin/printing/
            $company['logo'] = '../../' . ltrim($row['setting_value'], '/');
        }
    }
} catch (Exception $e) {
    // ignore
}

// Which template is being edited?
$tpl = in_array($_GET['tpl'] ?? '', ['kot', 'bot', 'order', 'payment']) ? $_GET['tpl'] : 'kot';
$tpl_labels = [
    'kot'     => 'Kitchen Ticket (KOT)',
    'bot'     => 'Bar Ticket (BOT)',
    'order'   => 'Order Receipt (Before Payment)',
    'payment' => 'Payment Receipt (After Payment)',
];

// Toggle field definitions: key => [label, description]
$toggles = [
    'show_logo'      => ['Show Logo', 'Print the restaurant logo at the top.'],
    'show_restaurant' => ['Restaurant Name', 'Print the business name.'],
    'show_address'   => ['Address', 'Print the business address.'],
    'show_phone'     => ['Phone Number', 'Print the contact phone number.'],
    'show_ref'       => ['Reference / Ticket No.', 'Print the order or ticket reference.'],
    'show_datetime'  => ['Date & Time', 'Print the order date and time.'],
    'show_server'    => ['Served By / Station', 'Print the cashier or station name.'],
    'show_table'     => ['Table / Room / Guests', 'Print the dine-in location details.'],
    'show_customer'  => ['Customer Name', 'Print the customer name.'],
    'show_prices'    => ['Item Prices', 'Show the amount column on item lines.'],
    'show_requests'  => ['Special Requests', 'Print customer notes under items.'],
    'show_totals'    => ['Subtotal & Total', 'Print the totals block.'],
    'show_tax'       => ['Tax Summary', 'Print the tax breakdown.'],
];

$admin_title = 'Receipt Templates';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    .tpl-tabs {
        display: flex;
        gap: 0.25rem;
        border-bottom: 2px solid var(--border-color);
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .tpl-tab {
        padding: 0.9rem 1.4rem;
        text-decoration: none;
        color: var(--text-muted);
        font-weight: 700;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
    }

    .tpl-tab:hover {
        color: var(--primary-color);
    }

    .tpl-tab.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
    }

    .tpl-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 1100px) {
        .tpl-layout {
            grid-template-columns: 1fr;
        }
    }

    .tpl-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.75rem;
    }

    .toggle-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
        gap: 1rem;
    }

    .toggle-row:last-of-type {
        border-bottom: none;
    }

    .toggle-label {
        font-weight: 600;
    }

    .toggle-label small {
        display: block;
        color: var(--text-muted);
        font-weight: 400;
    }

    .switch {
        position: relative;
        width: 46px;
        height: 26px;
        flex-shrink: 0;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #cbd5e1;
        border-radius: 26px;
        transition: 0.3s;
    }

    .slider:before {
        content: "";
        position: absolute;
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background: white;
        border-radius: 50%;
        transition: 0.3s;
    }

    .switch input:checked+.slider {
        background: var(--primary-color);
    }

    .switch input:checked+.slider:before {
        transform: translateX(20px);
    }

    .tpl-input {
        width: 100%;
        padding: 0.75rem 0.9rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 0.95rem;
        background: var(--white);
        color: var(--text-main);
    }

    .btn-save {
        background: linear-gradient(135deg, var(--primary-color), #ea580c);
        color: white;
        border: none;
        padding: 0.9rem 2.2rem;
        border-radius: 10px;
        font-weight: 800;
        font-size: 1rem;
        cursor: pointer;
        margin-top: 1.25rem;
    }

    .btn-secondary {
        background: var(--light-bg);
        color: var(--text-main);
        border: 1px solid var(--border-color);
        padding: 0.6rem 1.2rem;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .alert {
        padding: 0.9rem 1.2rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        font-weight: 600;
    }

    .alert-success {
        background: rgba(220, 252, 231, 0.9);
        color: #166534;
        border: 1px solid rgba(187, 247, 208, 0.9);
    }

    .alert-danger {
        background: rgba(254, 226, 226, 0.9);
        color: #991b1b;
        border: 1px solid rgba(254, 202, 202, 0.9);
    }

    /* Ticket preview shared styles */
    .t-center {
        text-align: center;
    }

    .t-bold {
        font-weight: 700;
    }

    .t-small {
        font-size: 11px;
    }

    .t-large {
        font-size: 14px;
    }

    .t-note {
        font-style: italic;
        font-size: 11px;
    }

    .t-sep {
        border-top: 1px dashed #999;
        margin: 8px 0;
    }

    .t-sep.t-dashed {
        border-top-style: dotted;
    }

    .t-meta,
    .t-items {
        width: 100%;
        border-collapse: collapse;
    }

    .t-meta td,
    .t-items td {
        padding: 2px 0;
        vertical-align: top;
    }

    .t-row {
        display: flex;
        justify-content: space-between;
        padding: 2px 0;
    }

    .preview-sticky {
        position: sticky;
        top: 20px;
    }

    .preview-panel {
        background: repeating-linear-gradient(45deg, #f1f5f9, #f1f5f9 12px, #e9eef5 12px, #e9eef5 24px);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.5rem 1rem;
    }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;">
    <div>
        <h1 style="margin:0; font-size:2rem; font-weight:800;"><i class="fas fa-file-invoice"></i> Receipt Templates</h1>
        <p style="margin:0.45rem 0 0; color:var(--text-muted);">Configure what prints on kitchen, bar, order and
            payment tickets — with live preview.</p>
    </div>
    <a href="<?php echo BASE_URL; ?>/admin/printing/index.php" class="btn-secondary">
        <i class="fas fa-print"></i> Printing Configuration
    </a>
</div>

<?php echo $alert; ?>

<div class="tpl-tabs">
    <?php foreach ($templates as $key => $meta): ?>
        <a href="?tpl=<?php echo $key; ?>" class="tpl-tab <?php echo $tpl === $key ? 'active' : ''; ?>">
            <i class="fas <?php echo $meta['icon'] ?? 'fa-receipt'; ?>"></i>
            <?php echo htmlspecialchars($meta['label_short'] ?? $meta['label']); ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="tpl-layout">
    <!-- ===== Left: Configuration ===== -->
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="save_template">
        <input type="hidden" name="tpl" value="<?php echo $tpl; ?>">

        <div class="tpl-card">
            <h2 style="margin:0 0 1rem; font-size:1.15rem;">
                <i class="fas fa-file-invoice"></i>
                <?php echo htmlspecialchars($templates[$tpl]['label']); ?> Template
            </h2>

            <h3 style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); margin:0 0 0.5rem;">Information to print</h3>
            <?php foreach ($toggles as $key => $meta): ?>
                <div class="toggle-row">
                    <div class="toggle-label">
                        <?php echo $meta[0]; ?>
                        <small><?php echo $meta[1]; ?></small>
                    </div>
                    <label class="switch">
                        <input type="hidden" name="settings[<?php echo $tpl; ?>_<?php echo $key; ?>]" value="0">
                        <input type="checkbox" data-toggle="<?php echo $key; ?>" name="settings[<?php echo $tpl; ?>_<?php echo $key; ?>]" value="1"
                            <?php echo ($p[$tpl . '_' . $key] ?? '1') == '1' ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            <?php endforeach; ?>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-top:1.25rem;">
                <div>
                    <label style="display:block; font-weight:700; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.4rem;">Header Note</label>
                    <input type="text" class="tpl-input" data-text="header_note" name="settings[<?php echo $tpl; ?>_header_note]"
                        value="<?php echo htmlspecialchars($p[$tpl . '_header_note'] ?? ''); ?>">
                </div>
                <div>
                    <label style="display:block; font-weight:700; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.4rem;">Footer Note</label>
                    <input type="text" class="tpl-input" data-text="footer_note" name="settings[<?php echo $tpl; ?>_footer_note]"
                        value="<?php echo htmlspecialchars($p[$tpl . '_footer_note'] ?? ''); ?>">
                </div>
                <div>
                    <label style="display:block; font-weight:700; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.4rem;">Paper Width</label>
                    <select class="tpl-input" id="paperWidthSelect" name="settings[<?php echo $tpl; ?>_paper_width]">
                        <option value="80mm" <?php echo ($p[$tpl . '_paper_width'] ?? '80mm') == '80mm' ? 'selected' : ''; ?>>80mm (Standard)</option>
                        <option value="58mm" <?php echo ($p[$tpl . '_paper_width'] ?? '') == '58mm' ? 'selected' : ''; ?>>58mm (Compact)</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save Template
                </button>
                <a href="preview.php?tpl=<?php echo $tpl; ?>" target="_blank" rel="noopener" class="btn-secondary" style="margin-top:1.25rem;">
                    <i class="fas fa-external-link-alt"></i> Open Printable Preview
                </a>
            </div>
        </div>
    </form>

    <!-- ===== Right: Live Preview ===== -->
    <div class="preview-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.9rem;">
            <strong style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">
                <i class="fas fa-eye"></i> Live Preview
            </strong>
            <small style="color:var(--text-muted);">Updates as you edit</small>
        </div>
        <div id="ticketPreview">
            <?php echo render_ticket($tpl, $p, $company, true); ?>
        </div>
    </div>
</div>

<script>
    (function () {
        // Toggle preview elements when switches change
        document.querySelectorAll('[data-toggle]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                document.querySelectorAll('[data-field="' + this.dataset.toggle + '"]').forEach(function (el) {
                    el.style.display = cb.checked ? '' : 'none';
                });
            });
        });

        // Live-update header / footer notes
        document.querySelectorAll('input[data-text]').forEach(function (inp) {
            inp.addEventListener('input', function () {
                document.querySelectorAll('[data-field="' + this.dataset.text + '"]').forEach(function (el) {
                    el.textContent = inp.value;
                });
            });
        });

        // Live paper width change
        var paperSelect = document.getElementById('paperWidthSelect');
        if (paperSelect) {
            paperSelect.addEventListener('change', function () {
                var ticket = document.querySelector('.ticket');
                if (ticket) {
                    ticket.style.width = this.value === '58mm' ? '219px' : '302px';
                }
            });
        }
    })();
</script>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>