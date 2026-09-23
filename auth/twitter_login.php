<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$client_id = get_setting('twitter_client_id', '');
$enabled = get_setting('twitter_login_enabled', '0');
$redirect_uri = tenant_url('auth/twitter_callback.php');

if ($enabled !== '1' || empty($client_id)) {
    die("Twitter / X Login is not configured or disabled. Please contact admin.");
}

$state = bin2hex(random_bytes(16));
$code_verifier = bin2hex(random_bytes(32));
$code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');

$_SESSION['twitter_oauth_state'] = $state;
$_SESSION['twitter_code_verifier'] = $code_verifier;

$params = [
    'response_type' => 'code',
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'scope' => 'tweet.read users.read offline.access',
    'state' => $state,
    'code_challenge' => $code_challenge,
    'code_challenge_method' => 'S256'
];

$auth_url = 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params);

header('Location: ' . $auth_url);
exit();
