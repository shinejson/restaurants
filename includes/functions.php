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

    // Fetch items
    $stmt = $conn->prepare("SELECT oi.*, fi.item_name FROM order_items oi JOIN food_items fi ON oi.food_item_id = fi.id WHERE oi.order_id = ?");
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

if (isset($conn) && $conn instanceof PDO) {
    ensure_order_schema($conn);
}
?>