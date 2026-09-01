<?php
// admin/printing/preview.php
// Printable preview of a receipt template with sample data.
// Usage: preview.php?tpl=kot|bot|order|payment[&print=1]
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/ticket_renderer.php';

require_permission('manage_settings');

$tpl = in_array($_GET['tpl'] ?? '', ['kot', 'bot', 'order', 'payment']) ? $_GET['tpl'] : 'kot';
$auto_print = isset($_GET['print']);

// Fetch printing settings
$p = [];
foreach ($conn->query("SELECT setting_key, setting_value FROM settings WHERE category = 'printing'")->fetchAll() as $row) {
    $p[$row['setting_key']] = $row['setting_value'];
}

// Company info
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

$tpl_names = [
    'kot'     => 'Kitchen Ticket (KOT)',
    'bot'     => 'Bar Ticket (BOT)',
    'order'   => 'Order Receipt (Before Payment)',
    'payment' => 'Payment Receipt (After Payment)',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Preview - <?php echo htmlspecialchars($tpl_names[$tpl]); ?></title>
    <style>
        body {
            background: #e2e8f0;
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 2rem 1rem;
        }

        .toolbar {
            max-width: 480px;
            margin: 0 auto 1.25rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .toolbar a,
        .toolbar button {
            padding: 0.55rem 1.1rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
        }

        .toolbar a.active {
            background: #ff6b35;
            border-color: #ff6b35;
            color: #fff;
        }

        .toolbar .print-btn {
            background: #1e293b;
            border-color: #1e293b;
            color: #fff;
        }

        /* Ticket shared styles (mirror of templates.php preview) */
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

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            .ticket {
                box-shadow: none !important;
                border-radius: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>

<body <?php echo $auto_print ? 'onload="window.print();"' : ''; ?>>
    <div class="toolbar no-print">
        <?php foreach ($tpl_names as $key => $name): ?>
            <a href="?tpl=<?php echo $key; ?>" class="<?php echo $tpl === $key ? 'active' : ''; ?>"><?php echo htmlspecialchars($name); ?></a>
        <?php endforeach; ?>
        <button type="button" class="print-btn" onclick="window.print();"><i class="fas fa-print"></i> Print</button>
    </div>

    <?php echo render_ticket($tpl, $p, $company, false); ?>
</body>

</html>