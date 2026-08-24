<?php
session_start();
require '../config/db.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

// Get sub categories for dropdown
$sub_stmt = $conn->prepare("SELECT s.id, s.name, m.name AS main FROM sub_categories s JOIN main_categories m ON s.main_category_id = m.id");
$sub_stmt->execute();
$subs = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

$sub_map = [];
foreach ($subs as $s) {
    $sub_map[$s['id']] = $s['main'] . ' > ' . $s['name'];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf']) && $_POST['csrf'] == $_SESSION['csrf']) {
    $action = $_POST['action'];
    $item_name = filter_input(INPUT_POST, 'item_name', FILTER_SANITIZE_STRING);
    $price = filter_input(INPUT_POST, 'price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $sub_category_id = filter_input(INPUT_POST, 'sub_category_id', FILTER_SANITIZE_NUMBER_INT);
    // ... (add other fields as needed, sanitized)

    if ($action == 'add') {
        $stmt = $conn->prepare("INSERT INTO food_items (item_name, price, sub_category_id /* add other fields */) VALUES (?, ?, ? /* ... */)");
        $stmt->execute([$item_name, $price, $sub_category_id /* ... */]);
    } elseif ($action == 'edit') {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        $stmt = $conn->prepare("UPDATE food_items SET item_name = ?, price = ?, sub_category_id = ? /* ... */ WHERE id = ?");
        $stmt->execute([$item_name, $price, $sub_category_id /* ... */, $id]);
    } elseif ($action == 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        $stmt = $conn->prepare("DELETE FROM food_items WHERE id = ?");
        $stmt->execute([$id]);
    }
}

// List items
$stmt = $conn->prepare("SELECT * FROM food_items");
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<h1>Manage Food Items</h1>

<!-- Add Form -->
<h2>Add Item</h2>
<form method="POST">
    <input type="text" name="item_name" placeholder="Item Name" required>
    <input type="number" name="price" step="0.01" placeholder="Price" required>
    <select name="sub_category_id" required>
        <?php foreach ($sub_map as $id => $name): ?>
            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
        <?php endforeach; ?>
    </select>
    <!-- Add other fields as inputs -->
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
    <button type="submit">Add</button>
</form>

<!-- List with Edit/Delete -->
<table>
    <tr><th>ID</th><th>Name</th><th>Price</th><th>Category</th><th>Actions</th></tr>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><?php echo $item['id']; ?></td>
            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
            <td><?php echo number_format($item['price'], 2); ?></td>
            <td><?php echo htmlspecialchars($sub_map[$item['sub_category_id']] ?? ''); ?></td>
            <td>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                    <input type="text" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>">
                    <input type="number" name="price" value="<?php echo $item['price']; ?>">
                    <!-- other fields -->
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                    <button type="submit">Edit</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<?php include '../includes/footer.php'; ?>