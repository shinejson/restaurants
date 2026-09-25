<?php
// admin/includes/admin_header.php
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $admin_title ?? 'Admin Panel'; ?> - <?php echo htmlspecialchars(get_setting('company_name', 'Restaurant Panel')); ?>
    </title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/images/favicon.png">
    <!-- Charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
</head>

<body>
    <div class="admin-container">
        <?php
        // Pages can set $hide_sidebar = true to render full-width (e.g. Reports opened in a new tab)
        if (empty($hide_sidebar)) {
            include dirname(__FILE__) . '/sidebar.php';
        }
        ?>

        <div class="admin-main">
            <?php include dirname(__FILE__) . '/../../platform/partials/impersonation_banner.php'; ?>
            <?php include dirname(__FILE__) . '/topnav.php'; ?>

            <div class="admin-content-padding">