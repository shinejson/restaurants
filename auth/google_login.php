<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// In a real implementation, you would redirect to Google's OAuth 2.0 endpoint
// using CLIENT_ID and REDIRECT_URI.
// Link: https://accounts.google.com/o/oauth2/v2/auth?...

$client_id = get_setting('google_client_id', 'YOUR_GOOGLE_CLIENT_ID');
$redirect_uri = BASE_URL . '/auth/google_callback.php';

if ($client_id === 'YOUR_GOOGLE_CLIENT_ID') {
    die("Google Login is not configured. Please contact admin.");
}

$params = [
    'response_type' => 'code',
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'scope' => 'email profile',
    'access_type' => 'online',
    'prompt' => 'select_account'
];

$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

header('Location: ' . $auth_url);
exit();
?>