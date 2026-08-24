<?php
require_once '../config/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || php_sapi_name() === 'cli') {
    try {
        // No transactions for DDL in MySQL (implicit commit)

        // 1. Rename users table to users_backup if it hasn't been done yet
        $check = $conn->query("SHOW TABLES LIKE 'users_backup'");
        if ($check->rowCount() == 0) {
            $conn->exec("RENAME TABLE users TO users_backup");
            $message .= "Renamed 'users' table to 'users_backup'.<br>";
        } else {
            $message .= "'users_backup' table already exists.<br>";
        }

        // 2. Create customers table
        $conn->exec("CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $message .= "Created 'customers' table.<br>";

        // 3. Create admins table
        $conn->exec("CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            role VARCHAR(20) DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $message .= "Created 'admins' table.<br>";

        // 4. Migrate Customers
        // Check if customers table is empty to avoid duplicates on re-run
        $check_customers = $conn->query("SELECT COUNT(*) FROM customers");
        if ($check_customers->fetchColumn() == 0) {
            // We need to check if users_backup has these columns before selecting
            // Assuming standard schema based on previous exploration
            $sql = "INSERT INTO customers (username, email, password, full_name, phone, address, created_at)
                    SELECT username, email, password, full_name, phone, address, created_at 
                    FROM users_backup WHERE role = 'customer'";
            $conn->exec($sql);
            $message .= "Migrated customers data.<br>";
        } else {
            $message .= "Customers table already has data. Skipping migration.<br>";
        }

        // 5. Migrate Admins
        $check_admins = $conn->query("SELECT COUNT(*) FROM admins");
        if ($check_admins->fetchColumn() == 0) {
            $sql = "INSERT INTO admins (username, password, email, role, created_at)
                    SELECT username, password, email, role, created_at 
                    FROM users_backup WHERE role = 'admin'";
            $conn->exec($sql);
            $message .= "Migrated admins data.<br>";
        } else {
            $message .= "Admins table already has data. Skipping migration.<br>";
        }

        $message .= "<strong>Database split completed successfully!</strong>";
    } catch (PDOException $e) {
        $error = "Error updating database: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Split Users Table</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 2rem;
            line-height: 1.6;
        }

        .btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>

<body>
    <h1>Split Users Table</h1>
    <p>This script will:</p>
    <ol>
        <li>Rename <code>users</code> to <code>users_backup</code></li>
        <li>Create <code>customers</code> table</li>
        <li>Create <code>admins</code> table</li>
        <li>Migrate data accordingly</li>
    </ol>

    <?php if ($message): ?>
        <div class="alert success">
            <?php echo $message; ?>
        </div>
    <?php elseif ($error): ?>
        <div class="alert error">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <button type="submit" class="btn">Execute Split</button>
    </form>
</body>

</html>