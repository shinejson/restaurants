<?php
session_start();
require '../config/db.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

// Update status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf']) && $_POST['csrf'] == $_SESSION['csrf']) {
    $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
}

// List orders
$stmt = $conn->prepare("SELECT o.*, u.username FROM orders o JOIN customers u ON o.user_id = u.id ORDER BY created_at DESC");
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<h1>View Orders</h1>
<table>
    <tr>
        <th>ID</th>
        <th>Reference</th>
        <th>User</th>
        <th>Total</th>
        <th>Status</th>
        <th>Date</th>
        <th>Action</th>
    </tr>
    <?php foreach ($orders as $order): ?>
        <tr>
            <td><?php echo $order['id']; ?></td>
            <td><?php echo htmlspecialchars($order['order_reference']); ?></td>
            <td><?php echo htmlspecialchars($order['username']); ?></td>
            <td><?php echo number_format($order['total'], 2); ?></td>
            <td><?php echo $order['status']; ?></td>
            <td><?php echo $order['created_at']; ?></td>
            <td>
                <form method="POST">
                    <select name="status">
                        <option value="Pending" <?php if ($order['status'] == 'Pending')
                            echo 'selected'; ?>>Pending</option>
                        <option value="Preparing" <?php if ($order['status'] == 'Preparing')
                            echo 'selected'; ?>>Preparing
                        </option>
                        <option value="Completed" <?php if ($order['status'] == 'Completed')
                            echo 'selected'; ?>>Completed
                        </option>
                        <option value="Cancelled" <?php if ($order['status'] == 'Cancelled')
                            echo 'selected'; ?>>Cancelled
                        </option>
                    </select>
                    <input type="hidden" name="id" value="<?php echo $order['id']; ?>">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                    <button type="submit">Update</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<?php include '../includes/footer.php'; ?>