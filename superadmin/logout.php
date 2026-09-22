<?php
/**
 * Direct platform logout fallback.
 * Signs the user out of the platform console and redirects to the login screen.
 */
require_once __DIR__ . '/../platform/bootstrap.php';

use Resto\Platform\Auth;
use Resto\Tenancy\Links;

Auth::logout();

$target = Links::origin() . '/restaurants/superadmin/';
header('Location: ' . $target);
exit;
