<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $client_id = get_setting('twitter_client_id', '');
    $client_secret = get_setting('twitter_client_secret', '');
    $redirect_uri = tenant_url('auth/twitter_callback.php');
    $code_verifier = $_SESSION['twitter_code_verifier'] ?? '';

    if (empty($client_id)) {
        die("Integration pending: Need valid Twitter Client ID in Admin Settings.");
    }

    $token_url = 'https://api.twitter.com/2/oauth2/token';
    $post_fields = [
        'code' => $code,
        'grant_type' => 'authorization_code',
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'code_verifier' => $code_verifier
    ];

    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if (!empty($client_secret)) {
        $headers[] = 'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret);
    }

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $token_data = json_decode($response, true);
    curl_close($ch);

    if (!empty($token_data['access_token'])) {
        $user_url = 'https://api.twitter.com/2/users/me?user.fields=id,name,username';
        $ch = curl_init($user_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token_data['access_token']]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $user_res = curl_exec($ch);
        $user_info = json_decode($user_res, true);
        curl_close($ch);

        if (!empty($user_info['data']['id'])) {
            $tw_id = $user_info['data']['id'];
            $name  = $user_info['data']['name'] ?? 'X User';
            $uname = $user_info['data']['username'] ?? ('x_' . substr($tw_id, 0, 8));
            $email = $tw_id . '@x.user';

            $stmt = $conn->prepare("SELECT * FROM customers WHERE twitter_id = ? OR email = ?");
            $stmt->execute([$tw_id, $email]);
            $customer = $stmt->fetch();

            if ($customer) {
                if (empty($customer['twitter_id'])) {
                    $uStmt = $conn->prepare("UPDATE customers SET twitter_id = ? WHERE id = ?");
                    $uStmt->execute([$tw_id, $customer['id']]);
                }
                $_SESSION['user_id'] = $customer['id'];
                $_SESSION['user_name'] = $customer['full_name'] ?? $customer['username'];
                $_SESSION['user_email'] = $customer['email'];
                $_SESSION['user_role'] = 'customer';
                header("Location: " . tenant_url('index.php'));
                exit();
            } else {
                $stmt = $conn->prepare("INSERT INTO customers (username, email, password, full_name, twitter_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $dummy_pw = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $stmt->execute([$uname, $email, $dummy_pw, $name, $tw_id]);
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

    die("Twitter / X Authentication failed. Please try again or sign in with your email.");
} elseif (isset($_GET['error'])) {
    die("Twitter / X Login Error: " . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
} else {
    header("Location: " . tenant_url('auth/login.php'));
    exit();
}
