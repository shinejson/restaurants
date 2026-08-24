<?php
require_once '../config/db.php';

echo "<h2>BASE_URL Debug Information</h2>";
echo "<p><strong>BASE_URL:</strong> " . BASE_URL . "</p>";
echo "<p><strong>Current Script:</strong> " . $_SERVER['SCRIPT_NAME'] . "</p>";
echo "<p><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p><strong>Project Root (calculated):</strong> " . dirname(__DIR__) . "</p>";
echo "<p><strong>HTTP Host:</strong> " . $_SERVER['HTTP_HOST'] . "</p>";

echo "<h3>Test Links:</h3>";
echo "<p><a href='" . BASE_URL . "/index.php'>Home Page</a></p>";
echo "<p><a href='" . BASE_URL . "/admin/login.php'>Admin Login</a></p>";
echo "<p><a href='" . BASE_URL . "/admin/dashboard.php'>Admin Dashboard</a></p>";
?>