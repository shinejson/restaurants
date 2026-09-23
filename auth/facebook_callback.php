<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $app_id = get_setting('facebook_app_id', '');
    $app_secret = get_setting('facebook_app_secret', '');
    $redirect_uri = tenant_url('auth/facebook_callback.php');

    if (empty($app_id) || empty($app_secret)) {
        die("Integration pending: Need valid Facebook App ID & App Secret in Admin Settings to complete authentication.");
    }

    $token_url = 'https://graph.facebook.com/v18.0/oauth/access_token?' . http_build_query([
        'client_id' => $app_id,
        'client_secret' => $app_secret,
        'redirect_uri' => $redirect_uri,
        'code' => $code
    ]);

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $token_data = json_decode($response, true);
    curl_close($ch);

    if (!empty($token_data['access_token'])) {
        $user_url = 'https://graph.facebook.com/me?fields=id,name,email&access_token=' . urlencode($token_data['access_token']);
        $ch = curl_init($user_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $user_res = curl_exec($ch);
        $user_info = json_decode($user_res, true);
        curl_close($ch);

        if (!empty($user_info['id'])) {
            $fb_id = $user_info['id'];
            $email = $user_info['email'] ?? ($fb_id . '@facebook.user');
            $name  = $user_info['name'] ?? 'Facebook User';

            $stmt = $conn->prepare("SELECT * FROM customers WHERE facebook_id = ? OR email = ?");
            $stmt->execute([$fb_id, $email]);
            $customer = $stmt->fetch();

            if ($customer) {
                if (empty($customer['facebook_id'])) {
                    $uStmt = $conn->prepare("UPDATE customers SET facebook_id = ? WHERE id = ?");
                    $uStmt->execute([$fb_id, $customer['id']]);
                }
                $_SESSION['user_id'] = $customer['id'];
                $_SESSION['user_name'] = $customer['full_name'] ?? $customer['username'];
                $_SESSION['user_email'] = $customer['email'];
                $_SESSION['user_role'] = 'customer';
                header("Location: " . tenant_url('index.php'));
                exit();
            } else {
                $username = 'fb_' . substr($fb_id, 0, 8);
                $stmt = $conn->prepare("INSERT INTO customers (username, email, password, full_name, facebook_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $dummy_pw = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $stmt->execute([$username, $email, $dummy_pw, $name, $fb_id]);
                $newId = $conn->lastInsertId();

                $_SESSION['user_id'] = $newId;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = 'customer';
                header("Location: " . tenant_url('index.php'));
                exit();
            }
        }
    }

    die("Facebook Authentication failed. Please try again or sign in with your email.");
} elseif (isset($_GET['error'])) {
    die("Facebook Login Error: " . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
} else {
    header("Location: " . tenant_url('auth/login.php'));
    exit();
}
