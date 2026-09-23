<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$app_id = get_setting('facebook_app_id', '');
$enabled = get_setting('facebook_login_enabled', '0');
$redirect_uri = tenant_url('auth/facebook_callback.php');

if ($enabled !== '1' || empty($app_id)) {
    die("Facebook Login is not configured or disabled. Please contact the administrator.");
}

$state = bin2hex(random_bytes(16));
$_SESSION['fb_oauth_state'] = $state;

$params = [
    'client_id' => $app_id,
    'redirect_uri' => $redirect_uri,
    'state' => $state,
    'scope' => 'email,public_profile',
    'response_type' => 'code'
];

$auth_url = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params);

header('Location: ' . $auth_url);
exit();
