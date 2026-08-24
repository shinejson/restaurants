<?php
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_check.php';
require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_events');

$admin_title = 'Manage Events';
$current_page = 'manage_events';

// Handle Actions (Delete)
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
    if ($stmt->execute([$id])) {
        $_SESSION['flash_success'] = "Event deleted successfully.";
    } else {
        $_SESSION['flash_error'] = "Failed to delete event.";
    }
    header("Location: manage_events.php");
    exit();
}

// Handle Form Submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = clean_input($_POST['title']);
    $description = clean_input($_POST['description']);
    $event_date = clean_input($_POST['event_date']);
    $time = clean_input($_POST['time']);
    $location = clean_input($_POST['location']);

    // Image Upload
    $image_url = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $upload_path = '../../assets/images/events/' . $new_filename;
            // Ensure directory exists
            if (!is_dir('../../assets/images/events/')) {
                mkdir('../../assets/images/events/', 0777, true);
            }
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                $image_url = 'assets/images/events/' . $new_filename;
            }
        }
    }

    if (isset($_POST['event_id']) && !empty($_POST['event_id'])) {
        // Edit
        $id = $_POST['event_id'];
        $sql = "UPDATE events SET title=?, description=?, event_date=?, start_time=?, location=?";
        $params = [$title, $description, $event_date, $time, $location];
        if ($image_url) {
            $sql .= ", image_url=?";
            $params[] = $image_url;
        }
        $sql .= " WHERE id=?";
        $params[] = $id;

        $stmt = $conn->prepare($sql);
        if ($stmt->execute($params)) {
            $_SESSION['flash_success'] = "Event updated successfully.";
        }
    } else {
        // Add
        $stmt = $conn->prepare("INSERT INTO events (title, description, event_date, start_time, location, image_url) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$title, $description, $event_date, $time, $location, $image_url])) {
            $_SESSION['flash_success'] = "Event added successfully.";
        }
    }
    header("Location: manage_events.php");
    exit();
}

include '../includes/admin_header.php';

// Fetch Events
$stmt = $conn->query("SELECT * FROM events ORDER BY event_date DESC");
$events = $stmt->fetchAll();

$edit_event = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_event = $stmt->fetch();
}
?>

<div class="dashboard-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1><i class="fas fa-calendar-star"></i> Manage Upcoming Events</h1>
        <?php if ($edit_event): ?>
            <a href="manage_events.php" class="btn-secondary"><i class="fas fa-plus"></i> Add New</a>
        <?php endif; ?>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
        <!-- Events List -->
        <div class="dashboard-card">
            <h3>Upcoming Events List</h3>
            <table class="table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Location</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $evt): ?>
                        <tr>
                            <td>
                                <?php if ($evt['image_url']): ?>
                                    <img src="<?php echo BASE_URL . '/' . $evt['image_url']; ?>"
                                        style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                                <?php else: ?>
                                    <div style="width: 50px; height: 50px; background: #eee; border-radius: 4px;"></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($evt['title']); ?>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($evt['event_date'])); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($evt['location']); ?>
                            </td>
                            <td>
                                <a href="?edit=<?php echo $evt['id']; ?>" class="btn-icon text-primary"><i
                                        class="fas fa-edit"></i></a>
                                <a href="?delete=<?php echo $evt['id']; ?>" class="btn-icon text-danger"
                                    onclick="return confirm('Are you sure?');"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Add/Edit Form -->
        <div class="dashboard-card">
            <h3>
                <?php echo $edit_event ? 'Edit Event' : 'Add New Event'; ?>
            </h3>
            <form method="POST" enctype="multipart/form-data">
                <?php if ($edit_event): ?>
                    <input type="hidden" name="event_id" value="<?php echo $edit_event['id']; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Event Title</label>
                    <input type="text" name="title" class="form-control" required
                        value="<?php echo $edit_event['title'] ?? ''; ?>">
                </div>

                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="event_date" class="form-control" required
                        value="<?php echo $edit_event['event_date'] ?? ''; ?>">
                </div>

                <div class="form-group">
                    <label>Time</label>
                    <input type="time" name="time" class="form-control"
                        value="<?php echo $edit_event['start_time'] ?? ''; ?>">
                </div>

                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control"
                        value="<?php echo $edit_event['location'] ?? ''; ?>">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control"
                        rows="4"><?php echo $edit_event['description'] ?? ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label>Image</label>
                    <input type="file" name="image" class="form-control">
                    <?php if ($edit_event && $edit_event['image_url']): ?>
                        <small>Current: <a href="<?php echo BASE_URL . '/' . $edit_event['image_url']; ?>"
                                target="_blank">View</a></small>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%;">
                    <?php echo $edit_event ? 'Update Event' : 'Create Event'; ?>
                </button>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>