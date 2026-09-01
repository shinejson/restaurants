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

ensure_table_logs_schema($conn);
$logs = $conn->query("SELECT * FROM table_logs ORDER BY created_at DESC LIMIT 1000")->fetchAll();

$admin_title = 'Table Cleaning Log';
include dirname(dirname(__FILE__)) . '/includes/admin_header.php';
?>

<style>
    :root {
        --cl-card-bg: var(--white, #ffffff);
        --cl-border: var(--border-color, #edf2f7);
        --cl-text-main: var(--text-main, #111827);
        --cl-text-muted: var(--text-muted, #64748b);
        --cl-danger: var(--danger-color, #ef4444);
        --cl-danger-strong: var(--danger-color, #dc2626);
    }
    html[data-theme="dark"] {
        --cl-card-bg: var(--white);
        --cl-border: var(--border-color);
        --cl-text-main: var(--text-main);
        --cl-text-muted: var(--text-muted);
    }
    .cl-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .cl-panel { background: var(--cl-card-bg); border:1px solid var(--cl-border); border-radius:12px; padding:1rem; }
    .cl-title { margin:0; font-size:1.6rem; color:var(--cl-text-main;); }
    .muted-note { color:var(--cl-text-muted); margin:0.4rem 0 0; }
    .btn-submit { padding:0.6rem 0.9rem; border-radius:8px; border:none; font-weight:800; cursor:pointer; }
    .btn-neutral { background:var(--cl-card-bg); color:var(--cl-text-main); border:1px solid var(--cl-border); }
    .btn-danger { background:var(--cl-danger); color:var(--cl-card-bg); }
    table.cl-table { width:100%; border-collapse:collapse; }
    table.cl-table th { text-align:left; color:var(--cl-text-muted); font-weight:700; padding:0.6rem; border-bottom:1px solid var(--cl-border); }
    .cl-cell { padding:0.65rem; border-bottom:1px solid var(--cl-border); color:var(--cl-text-main); }
    .cl-empty { padding:0.8rem; color:var(--cl-text-muted); }
    .danger-btn { background: rgba(254,226,226,0.9); color: var(--cl-danger-strong); border:none; padding:0.4rem 0.6rem; border-radius:6px; font-weight:700; cursor:pointer; }
</style>

<div class="cl-header">
    <div>
        <h1 class="cl-title">Table Cleaning Log</h1>
        <p class="muted-note">History of table cleaning, releases and related events.</p>
    </div>
    <div style="display:flex; gap:0.5rem;">
        <a href="table_map.php" class="btn-submit btn-neutral" style="text-decoration:none;">Open Table Board</a>
    </div>
</div>

<?php echo $message; ?>

<div class="cl-panel">
    <form method="POST" style="margin-bottom:0.8rem;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="clear_all">
        <button type="submit" class="btn-submit btn-danger">Clear All Logs</button>
    </form>

    <table class="cl-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Table</th>
                <th>Event</th>
                <th>Message</th>
                <th>When</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="cl-empty">No logs yet.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="cl-cell"><?php echo (int)$log['id']; ?></td>
                        <td class="cl-cell"><?php echo htmlspecialchars($log['table_name']); ?></td>
                        <td class="cl-cell"><?php echo htmlspecialchars($log['event_type']); ?></td>
                        <td class="cl-cell"><?php echo htmlspecialchars($log['message']); ?></td>
                        <td class="cl-cell"><?php echo htmlspecialchars($log['created_at']); ?></td>
                        <td class="cl-cell">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>">
                                <button type="submit" class="danger-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include dirname(dirname(__FILE__)) . '/includes/admin_footer.php'; ?>
