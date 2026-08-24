<?php
require_once '../includes/admin_check.php';
require_once '../config/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check if columns exist before adding
        $columns = [
            'cost' => "DECIMAL(10,2) DEFAULT 0.00 AFTER price",
            'tax_group' => "VARCHAR(50) DEFAULT 'Standard' AFTER cost",
            'inventory_count' => "INT DEFAULT 0 AFTER tax_group",
            'sold_by_weight' => "BOOLEAN DEFAULT 0 AFTER inventory_count"
        ];

        $check = $conn->query("SHOW COLUMNS FROM food_items");
        $existing_cols = $check->fetchAll(PDO::FETCH_COLUMN);

        foreach ($columns as $col => $def) {
            if (!in_array($col, $existing_cols)) {
                $conn->exec("ALTER TABLE food_items ADD COLUMN $col $def");
                $message .= "Added column '$col'.<br>";
            } else {
                $message .= "Column '$col' already exists.<br>";
            }
        }
        $message .= "<strong>Database upgrade completed successfully!</strong>";
    } catch (PDOException $e) {
        $error = "Error updating database: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Upgrade Database</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 2rem;
            line-height: 1.6;
        }

        .btn {
            background: #28a745;
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
    <h1>Database Upgrade</h1>
    <p>This script will add the following columns to your <code>food_items</code> table to support the new Reporting
        Module:</p>
    <ul>
        <li><strong>cost</strong> (for profit calculations)</li>
        <li><strong>tax_group</strong> (for tax reports)</li>
        <li><strong>inventory_count</strong> (for stock tracking)</li>
        <li><strong>sold_by_weight</strong> (for specific item analysis)</li>
    </ul>

    <?php if ($message): ?>
        <div class="alert success">
            <?php echo $message; ?>
        </div>
        <p><a href="index.php">Return to Dashboard</a></p>
    <?php elseif ($error): ?>
        <div class="alert error">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <button type="submit" class="btn">Click here to Upgrade Database</button>
    </form>
</body>

</html>