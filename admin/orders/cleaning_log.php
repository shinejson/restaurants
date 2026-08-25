<?php
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } else {
        try {
            if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
                $id = (int) $_POST['id'];
                $stmt = $conn->prepare("DELETE FROM table_logs WHERE id = ?");
                $stmt->execute([$id]);
                $message = '<div class="alert success">Log entry deleted.</div>';
            }

            if (isset($_POST['action']) && $_POST['action'] === 'clear_all') {
                $conn->exec("TRUNCATE TABLE table_logs");
                $message = '<div class="alert success">All log entries cleared.</div>';
            }
        } catch (Exception $e) {
            $message = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

$logs = $conn->query("SELECT * FROM table_logs ORDER BY created_at DESC LIMIT 1000")->fetchAll();

$admin_title = 'Table Cleaning Log';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
    <div>
        <h1 style="margin:0; font-size:1.6rem;">Table Cleaning Log</h1>
        <p style="margin:0.4rem 0 0; color:var(--text-muted);">History of table cleaning, releases and related events.</p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <a href="table_map.php" class="btn-submit" style="text-decoration:none;">Open Table Board</a>
    </div>
</div>

<?php echo $message; ?>

<div style="background:#fff; border:1px solid #edf2f7; border-radius:12px; padding:1rem;">
    <form method="POST" style="margin-bottom:0.8rem;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="clear_all">
        <button type="submit" class="btn-submit" style="background:#ef4444;">Clear All Logs</button>
    </form>

    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="text-align:left; color:#64748b; font-weight:700;">
                <th style="padding:0.6rem; border-bottom:1px solid #edf2f7;">ID</th>
                <th style="padding:0.6rem; border-bottom:1px solid #edf2f7;">Table</th>
                <th style="padding:0.6rem; border-bottom:1px solid #edf2f7;">Event</th>
                <th style="padding:0.6rem; border-bottom:1px solid #edf2f7;">Message</th>
                <th style="padding:0.6rem; border-bottom:1px solid #edf2f7;">When</th>
                <th style="padding:0.6rem; border-bottom:1px solid #edf2f7;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="6" style="padding:0.8rem; color:#64748b;">No logs yet.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="padding:0.65rem; border-bottom:1px solid #f1f5f9;"><?php echo (int)$log['id']; ?></td>
                        <td style="padding:0.65rem; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars($log['table_name']); ?></td>
                        <td style="padding:0.65rem; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars($log['event_type']); ?></td>
                        <td style="padding:0.65rem; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars($log['message']); ?></td>
                        <td style="padding:0.65rem; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                        <td style="padding:0.65rem; border-bottom:1px solid #f1f5f9;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>">
                                <button type="submit" style="background:#fee2e2; color:#991b1b; border:none; padding:0.4rem 0.6rem; border-radius:6px; font-weight:700;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
