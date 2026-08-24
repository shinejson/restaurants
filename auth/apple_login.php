<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// In a real implementation, you would redirect to Apple's OAuth endpoint.
// Link: https://appleid.apple.com/auth/authorize?...

$client_id = get_setting('apple_client_id', 'YOUR_APPLE_CLIENT_ID');
$redirect_uri = BASE_URL . '/auth/apple_callback.php';

if ($client_id === 'YOUR_APPLE_CLIENT_ID') {
    die("Apple Login is not configured. Please contact admin.");
}

$params = [
    'response_type' => 'code',
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'scope' => 'name email',
    'response_mode' => 'form_post'
];

$auth_url = 'https://appleid.apple.com/auth/authorize?' . http_build_query($params);

header('Location: ' . $auth_url);
exit();
?>