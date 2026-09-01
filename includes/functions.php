<?php
/**
 * Sanitize and clean user input strings
 * 
 * @param string $data The input string to clean
 * @return string The cleaned string
 */
function clean_input($data)
{
    if ($data === null)
        return '';
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Format currency for display (Ghana Cedis)
 * 
 * @param float $amount The amount to format
 * @return string Formatted currency string
 */
function format_currency($amount)
{
    return 'GH₵' . number_format($amount, 2);
}

/**
 * Get setting value from database
 * 
 * @param string $key The setting key
 * @param string $default Default value if key not found
 * @return string The setting value
 */
function get_setting($key, $default = '')
{
    global $conn;
    static $settings_cache = null;

    if ($settings_cache === null) {
        try {
            $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
            $settings_cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $settings_cache = [];
        }
    }

    return $settings_cache[$key] ?? $default;
}

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send HTML Email using PHPMailer
 */
function send_email($to, $subject, $message)
{
    $mail = new PHPMailer(true);

    try {
        // Fetch Settings
        $smtp_host = get_setting('smtp_host', 'smtp.gmail.com');
        $smtp_username = get_setting('smtp_username', '');
        $smtp_password = get_setting('smtp_password', '');
        $smtp_port = get_setting('smtp_port', '587');
        $smtp_encryption = get_setting('smtp_encryption', 'tls');

        $company_name = get_setting('company_name', 'Food Ordering System');
        $from_email = get_setting('contact_email', 'no-reply@airportwesthotel.com.gh');

        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = !empty($smtp_username);
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;

        if ($smtp_encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtp_encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPAuth = false;
            $mail->SMTPSecure = false;
        }

        $mail->Port = $smtp_port;

        // Recipients
        $mail->setFrom($from_email, $company_name);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log error (optional)
        return false;
    }
}

/**
 * Send Order Notifications (Customer Receipt & Admin Alert)
 */
function send_order_notifications($order_id)
{
    global $conn;

    // Fetch order details
    $stmt = $conn->prepare("SELECT o.*, u.username, u.email, u.phone FROM orders o JOIN customers u ON o.user_id = u.id WHERE o.id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order)
        return false;

    // Fetch items (with tax configuration fields)
    $stmt = $conn->prepare("SELECT oi.*, fi.item_name, fi.tax_group_id, fi.tax_group, fi.is_rate_inclusive FROM order_items oi JOIN food_items fi ON oi.food_item_id = fi.id WHERE oi.order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll();

    // Build HTML Content
    $items_html = '<table style="width:100%; border-collapse: collapse; margin-top: 10px;">';
    foreach ($items as $item) {
        $base_total = $item['price'] * $item['quantity'];
        $request_price = $item['request_price'] ?? 0;
        $item_total = $base_total + $request_price;

        $price_display = 'GH₵' . number_format($item_total, 2);
        if ($request_price > 0) {
            $price_display = 'GH₵' . number_format($base_total, 2) . ' + GH₵' . number_format($request_price, 2) . ' (Extra) = <br><strong>GH₵' . number_format($item_total, 2) . '</strong>';
        }

        $items_html .= '<tr>
            <td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($item['item_name']) . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #ddd;">x' . $item['quantity'] . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #ddd; text-align: right;">' . $price_display . '</td>
        </tr>';
    }
    $items_html .= '</table>';

    // Tax summary — taxes are INCLUDED in the item prices, so we extract them
    $tax_grouped = group_items_by_tax($items);
    $total_tax_included = 0;
    foreach ($tax_grouped as $tg) {
        $total_tax_included += $tg['total_tax'];
    }

    $tax_html = '';
    if ($total_tax_included > 0) {
        $tax_html = "<div style='background:#fdf3ec; padding:12px 15px; margin:15px 0; border:1px solid #f5ddca; border-radius:5px;'>";
        $tax_html .= "<h3 style='margin:0 0 8px; color:#555;'>Tax Summary <span style='font-weight:400; font-size:0.85em;'>(already included in item prices)</span></h3>";
        foreach ($tax_grouped as $tg) {
            if (!$tg['config'] || empty($tg['components'])) {
                continue;
            }
            $tax_html .= "<div style='font-weight:bold; margin:8px 0 3px;'>" . htmlspecialchars($tg['name'])
                . " &mdash; " . number_format($tg['total_rate'] * 100, 2) . "% combined</div>";
            foreach ($tg['components'] as $c) {
                $tax_html .= "<div style='display:flex; justify-content:space-between; padding:2px 0 2px 12px;'>"
                    . "<span>" . htmlspecialchars($c['name']) . " (" . number_format($c['rate'] * 100, 2) . "%)</span>"
                    . "<span>GH₵" . number_format($c['amount'], 2) . "</span></div>";
            }
        }
        $tax_html .= "<div style='display:flex; justify-content:space-between; font-weight:bold; border-top:1px solid #e8d5c5; margin-top:8px; padding-top:6px;'>"
            . "<span>Total Tax Included</span><span>GH₵" . number_format($total_tax_included, 2) . "</span></div>";
        $tax_html .= "</div>";
    }

    $company_name = get_setting('company_name', 'Airport West Hotel');
    $admin_email = get_setting('contact_email', 'restaurants@airportwesthotel.com.gh'); // Default to provided email

    // 1. Customer Email (Receipt)
    $subject_customer = "Order Notification - " . $order['order_ref'];
    $msg_customer = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 5px;'>
            <h2 style='color: #d35400;'>Thank You for Your Order!</h2>
            <p>Hi " . htmlspecialchars($order['username']) . ",</p>
            <p>We received your order. We are preparing it now!</p>
            
            <div style='background: #f9f9f9; padding: 15px; margin: 20px 0;'>
                <h3>Order Ref: " . $order['order_ref'] . "</h3>
                <p><strong>Date:</strong> " . date('M d, Y h:i A', strtotime($order['created_at'])) . "</p>
                <p><strong>Total:</strong> GH₵" . number_format($order['total'], 2) . "</p>
            </div>

            <div style='margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px;'>
                <h3 style='margin-bottom: 10px; color: #555;'>Delivery Details</h3>
                <p style='margin: 5px 0;'><strong>Name:</strong> " . htmlspecialchars($order['username']) . "</p>
                <p style='margin: 5px 0;'><strong>Address:</strong> " . htmlspecialchars($order['delivery_address']) . "</p>
                <p style='margin: 5px 0;'><strong>Phone:</strong> " . htmlspecialchars($order['phone']) . "</p>
                " . (!empty($order['special_instructions']) ? "<p style='margin: 5px 0;'><strong>Note:</strong> " . htmlspecialchars($order['special_instructions']) . "</p>" : "") . "
            </div>
            
            <h3>Order Details</h3>
            $items_html
            $tax_html
            
            <p style='margin-top: 20px;'>We hope you enjoy your meal!</p>
            <p><strong>$company_name</strong></p>
        </div>
    </body>
    </html>";

    send_email($order['email'], $subject_customer, $msg_customer);

    // 2. Admin Email (New Order Alert)
    $subject_admin = "New Order Alert! [Ref: " . $order['order_ref'] . "]";
    $msg_admin = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <h2>New Order Received</h2>
        <p><strong>Customer:</strong> " . htmlspecialchars($order['username']) . " (" . $order['phone'] . ")</p>
        <p><strong>Address:</strong> " . htmlspecialchars($order['delivery_address']) . "</p>
        <p><strong>Total:</strong> GH₵" . number_format($order['total'], 2) . "</p>
        <p><strong>Special Instructions:</strong> " . htmlspecialchars($order['special_instructions']) . "</p>
        
        <h3>Items Ordered:</h3>
        $items_html
        $tax_html
        
        <p><a href='" . BASE_URL . "/admin/orders/view.php?id=" . $order['id'] . "' style='background: #3498db; color: white; padding: 10px 15px; text-decoration: none;'>View in Admin Panel</a></p>
    </body>
    </html>";

    // Send to admin email (and maybe store owner if different)
    send_email($admin_email, $subject_admin, $msg_admin);

    return true;
}

/**
 * Ensure order schema contains dine-in columns
 * 
 * @param PDO|null $conn
 * @return void
 */
function ensure_order_schema($conn = null)
{
    static $ensured = false;
    if ($ensured) return;

    if ($conn === null) {
        global $conn;
    }
    if (!$conn) return;

    try {
        $columns = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($columns)) {
            $required_columns = [
                'order_type' => 'VARCHAR(20) NOT NULL DEFAULT "dine_in"',
                'table_number' => 'VARCHAR(50) NULL',
                'room_number' => 'VARCHAR(50) NULL',
                'guest_count' => 'INT NOT NULL DEFAULT 1'
            ];
            foreach ($required_columns as $column => $definition) {
                if (!in_array($column, $columns, true)) {
                    $conn->exec("ALTER TABLE orders ADD COLUMN `$column` $definition");
                }
            }
        }
        $ensured = true;
    } catch (Exception $e) {
        // Silently skip if database errors occur
    }
}

function ensure_table_logs_schema($conn = null)
{
    static $ensured = false;
    if ($ensured) return;

    if ($conn === null) {
        global $conn;
    }
    if (!$conn) return;

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS table_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(20) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            message VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $ensured = true;
    } catch (Exception $e) {
        // Silently skip if database errors occur
    }
}

function ensure_restaurant_tables_schema($conn = null)
{
    static $ensured = false;
    if ($ensured) return;

    if ($conn === null) {
        global $conn;
    }
    if (!$conn) return;

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS restaurant_tables (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(20) NOT NULL UNIQUE,
            seat_count INT NOT NULL DEFAULT 4,
            status ENUM('available', 'occupied', 'reserved') NOT NULL DEFAULT 'available',
            notes VARCHAR(255) NULL,
            qr_code VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS restaurant_seats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_id INT NOT NULL,
            seat_name VARCHAR(50) NOT NULL,
            seat_number INT NOT NULL,
            status ENUM('available', 'occupied') NOT NULL DEFAULT 'available',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE,
            UNIQUE KEY unique_seat (table_id, seat_number)
        )");

        // Populate default seats if missing
        $tables = $conn->query("SELECT id, table_name, seat_count FROM restaurant_tables")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tables as $tbl) {
            $seat_check = $conn->prepare("SELECT COUNT(*) FROM restaurant_seats WHERE table_id = ?");
            $seat_check->execute([$tbl['id']]);
            $count = (int)$seat_check->fetchColumn();
            if ($count == 0) {
                $count_to_create = max(1, (int)$tbl['seat_count']);
                for ($i = 1; $i <= $count_to_create; $i++) {
                    $stmt = $conn->prepare("INSERT INTO restaurant_seats (table_id, seat_name, seat_number) VALUES (?, ?, ?)");
                    $stmt->execute([$tbl['id'], 'Seat ' . $i, $i]);
                }
            }
        }

        $ensured = true;
    } catch (Exception $e) {
        // Silently skip if database errors occur
    }
}

function ensure_tax_schema($conn = null)
{
    static $ensured = false;
    if ($ensured) return;

    if ($conn === null) {
        global $conn;
    }
    if (!$conn) return;

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tax_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->exec("CREATE TABLE IF NOT EXISTS tax_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->exec("CREATE TABLE IF NOT EXISTS tax_group_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tax_group_id INT NOT NULL,
            tax_item_id INT NOT NULL,
            UNIQUE KEY uq_group_item (tax_group_id, tax_item_id),
            FOREIGN KEY (tax_group_id) REFERENCES tax_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (tax_item_id) REFERENCES tax_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = $conn->query("SHOW COLUMNS FROM food_items")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($cols) && !in_array('tax_group_id', $cols, true)) {
            $conn->exec("ALTER TABLE food_items ADD COLUMN tax_group_id INT NULL");
        }

        $ensured = true;
    } catch (Exception $e) {
        // Silently skip if database errors occur
    }
}

/* ==========================================================================
   TAX HELPERS
   Taxes are INCLUSIVE: the displayed/paid price already contains the tax.
   The breakdown below EXTRACTS the tax portion out of each amount.
   ========================================================================== */

/**
 * Fetch all active tax groups with their component tax items.
 * Cached per request. Keyed by group name, e.g.:
 * ['Standard' => ['id'=>1, 'name'=>'Standard',
 *   'taxes'=>[['name'=>'VAT','rate'=>0.125], ...], 'total_rate'=>0.175]]
 */
function get_tax_groups_map()
{
    global $conn;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $map = [];
    try {
        ensure_tax_schema($conn);
        $sql = "SELECT tg.id AS group_id, tg.name AS group_name,
                       ti.name AS tax_name, ti.rate AS tax_rate
                FROM tax_groups tg
                LEFT JOIN tax_group_items tgi ON tgi.tax_group_id = tg.id
                LEFT JOIN tax_items ti ON ti.id = tgi.tax_item_id AND ti.is_active = 1
                WHERE tg.is_active = 1
                ORDER BY tg.name ASC, ti.name ASC";
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $gname = $r['group_name'];
            if (!isset($map[$gname])) {
                $map[$gname] = [
                    'id'         => (int) $r['group_id'],
                    'name'       => $gname,
                    'taxes'      => [],
                    'total_rate' => 0.0,
                ];
            }
            if ($r['tax_name'] !== null) {
                $rate = (float) $r['tax_rate'];
                $map[$gname]['taxes'][] = ['name' => $r['tax_name'], 'rate' => $rate];
                $map[$gname]['total_rate'] += $rate;
            }
        }
    } catch (Exception $e) {
        $map = [];
    }

    $cache = $map;
    return $cache;
}

/**
 * Resolve the tax group configuration for a food item row.
 * Prefers tax_group_id, falls back to the tax_group name (imported data).
 * Returns the group array or null when the item has no (active) group.
 */
function resolve_item_tax_group($item)
{
    $groups = get_tax_groups_map();
    if (empty($groups)) {
        return null;
    }

    if (!empty($item['tax_group_id'])) {
        foreach ($groups as $g) {
            if ($g['id'] == $item['tax_group_id']) {
                return $g;
            }
        }
    }

    if (!empty($item['tax_group'])) {
        return $groups[$item['tax_group']] ?? null;
    }

    return null;
}

/**
 * Split a gross amount into net + per-tax components.
 * When $is_rate_inclusive is true the tax is EXTRACTED from the gross
 * (price already contains tax):  net = gross / (1 + total_rate).
 * Otherwise the tax is computed on top of the gross.
 */
function calculate_tax_breakdown($gross, $group, $is_rate_inclusive = true)
{
    $gross = (float) $gross;
    $result = [
        'gross'      => $gross,
        'net'        => $gross,
        'total_tax'  => 0.0,
        'total_rate' => 0.0,
        'components' => [],
        'group'      => $group,
    ];

    if (!$group || empty($group['taxes']) || $group['total_rate'] <= 0) {
        return $result;
    }

    $total_rate = (float) $group['total_rate'];
    $result['total_rate'] = $total_rate;

    if ($is_rate_inclusive) {
        $net       = $gross / (1 + $total_rate);
        $tax_total = $gross - $net;
    } else {
        $net       = $gross;
        $tax_total = $gross * $total_rate;
    }

    $components = [];
    $sum        = 0.0;
    foreach ($group['taxes'] as $tax) {
        $share  = $total_rate > 0 ? ($tax['rate'] / $total_rate) : 0;
        $amount = round($is_rate_inclusive ? ($tax_total * $share) : ($gross * $tax['rate']), 2);
        $components[] = [
            'name'   => $tax['name'],
            'rate'   => (float) $tax['rate'],
            'amount' => $amount,
        ];
        $sum += $amount;
    }

    // Fix rounding drift so components always add up to the total tax
    // (compared in whole cents to avoid floating-point noise)
    $total_tax = round($tax_total, 2);
    $diff_cents = (int) round(($total_tax - $sum) * 100);
    if (!empty($components) && $diff_cents !== 0) {
        $max_idx = 0;
        for ($i = 1; $i < count($components); $i++) {
            if ($components[$i]['amount'] > $components[$max_idx]['amount']) {
                $max_idx = $i;
            }
        }
        $components[$max_idx]['amount'] = round($components[$max_idx]['amount'] + ($diff_cents / 100), 2);
    }

    $result['net']        = round($gross - $total_tax, 2);
    $result['total_tax']  = $total_tax;
    $result['components'] = $components;
    return $result;
}

/**
 * Group cart/order items by their tax group and compute the included-tax
 * breakdown for each group.
 *
 * Each item row needs: price (unit price actually charged), quantity (or qty),
 * and optionally tax_group_id / tax_group / is_rate_inclusive / request_price.
 * Returns an ordered list of groups:
 * [ ['key','name','config','items','gross','net','total_tax','components'=>[...]], ... ]
 * Items without a resolvable tax group are collected under key '__no_tax__'.
 */
function group_items_by_tax($items)
{
    $groups = [];
    $order  = [];

    foreach ($items as $it) {
        $group     = resolve_item_tax_group($it);
        $inclusive = !empty($it['is_rate_inclusive']);
        $key       = $group ? $group['name'] : '__no_tax__';

        if (!isset($groups[$key])) {
            $order[] = $key;
            $groups[$key] = [
                'key'        => $key,
                'name'       => $group ? $group['name'] : 'No Tax Group',
                'config'     => $group,
                'total_rate' => $group ? (float) $group['total_rate'] : 0.0,
                'items'      => [],
                'gross'      => 0.0,
                'total_tax'  => 0.0,
                'components' => [],
            ];
        }

        $qty        = isset($it['quantity']) ? (int) $it['quantity'] : (isset($it['qty']) ? (int) $it['qty'] : 1);
        $line_gross = ((float) $it['price']) * $qty;
        if (isset($it['request_price'])) {
            $line_gross += (float) $it['request_price'];
        }

        $groups[$key]['items'][] = $it;
        $groups[$key]['gross']  += $line_gross;

        // Only extract tax for tax-inclusive pricing (tax is inside the price)
        if ($group && $inclusive) {
            $bd = calculate_tax_breakdown($line_gross, $group, true);
            $groups[$key]['total_tax'] += $bd['total_tax'];
            foreach ($bd['components'] as $c) {
                if (!isset($groups[$key]['components'][$c['name']])) {
                    $groups[$key]['components'][$c['name']] = [
                        'name'   => $c['name'],
                        'rate'   => $c['rate'],
                        'amount' => 0.0,
                    ];
                }
                $groups[$key]['components'][$c['name']]['amount'] += $c['amount'];
            }
        }
    }

    foreach ($groups as $k => $grp) {
        $groups[$k]['gross'] = round($grp['gross'], 2);
        $groups[$k]['total_tax'] = round($grp['total_tax'], 2);
        $groups[$k]['net'] = round($grp['gross'] - $grp['total_tax'], 2);
        foreach ($groups[$k]['components'] as $ck => $c) {
            $groups[$k]['components'][$ck]['amount'] = round($c['amount'], 2);
        }

        // Reconcile: make the components sum EXACTLY to the group's total tax
        if (!empty($groups[$k]['components'])) {
            $comp_sum = 0.0;
            $max_ck   = null;
            foreach ($groups[$k]['components'] as $ck => $c) {
                $comp_sum += $c['amount'];
                if ($max_ck === null || $c['amount'] > $groups[$k]['components'][$max_ck]['amount']) {
                    $max_ck = $ck;
                }
            }
            $diff_cents = (int) round(($groups[$k]['total_tax'] - $comp_sum) * 100);
            if ($diff_cents !== 0) {
                $groups[$k]['components'][$max_ck]['amount'] = round($groups[$k]['components'][$max_ck]['amount'] + ($diff_cents / 100), 2);
            }
        }
    }

    $ordered = [];
    foreach ($order as $k) {
        $ordered[$k] = $groups[$k];
    }
    return $ordered;
}

if (isset($conn) && $conn instanceof PDO) {
    ensure_order_schema($conn);
    ensure_table_logs_schema($conn);
    ensure_restaurant_tables_schema($conn);
    ensure_tax_schema($conn);
}
?>