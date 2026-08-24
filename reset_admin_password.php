<?php
// Script to update admin password
require_once 'config/db.php';

$new_password = 'admin123';
$password_hash = password_hash($new_password, PASSWORD_DEFAULT);

echo "New password hash for 'admin123': " . $password_hash . "\n\n";

// Update the admin user's password
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
$stmt->execute([$password_hash]);

echo "Admin password has been updated successfully!\n";
echo "You can now login with:\n";
echo "Username: admin\n";
echo "Password: admin123\n";
?>