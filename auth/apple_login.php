<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// In a real implementation, you would redirect to Apple's OAuth endpoint.
// Link: https://appleid.apple.com/auth/authorize?...

$client_id = get_setting('apple_client_id', '');
$enabled = get_setting('apple_login_enabled', '0');
$redirect_uri = tenant_url('auth/apple_callback.php');

if ($enabled !== '1' || empty($client_id) || $client_id === 'YOUR_APPLE_CLIENT_ID') {
    die("Apple Login is not configured or disabled. Please contact admin.");
}

$state = bin2hex(random_bytes(16));
$_SESSION['apple_oauth_state'] = $state;

$params = [
    'response_type' => 'code',
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'scope' => 'name email',
    'response_mode' => 'form_post',
    'state' => $state,
];

$auth_url = 'https://appleid.apple.com/auth/authorize?' . http_build_query($params);

header('Location: ' . $auth_url);
exit();
?>