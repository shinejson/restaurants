<?php
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_check.php';

require_once '../../includes/permissions.php';

// Check permission
require_permission('manage_events');

$admin_title = 'Event Management';
$current_page = 'events';

include '../includes/admin_header.php';

// Calendar Logic
$month = isset($_GET['month']) ? (int) $_GET['month'] : date('n');
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');

// Month navigation
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}
$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

// Fetch events for this month
$start_date = "$year-$month-01";
$end_date = date("Y-m-t", strtotime($start_date));

$stmt = $conn->prepare("SELECT b.*, p.name as package_name, u.full_name
    FROM event_bookings b
    JOIN event_packages p ON b.package_id = p.id
    JOIN customers u ON b.user_id = u.id
    WHERE b.event_date BETWEEN ? AND ?
    ORDER BY b.event_date ASC");
$stmt->execute([$start_date, $end_date]);
$month_events = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
// Group by event_date if fetchAll supports it, or handle manually.
// Actually PDO::FETCH_GROUP might group by first column 'id' if 'b.*' is first. Let's select event_date first to group key.
$stmt = $conn->prepare("SELECT b.event_date, b.*, p.name as package_name, u.full_name
    FROM event_bookings b
    JOIN event_packages p ON b.package_id = p.id
    JOIN customers u ON b.user_id = u.id
    WHERE b.event_date BETWEEN ? AND ?
    ORDER BY b.event_date ASC");
$stmt->execute([$start_date, $end_date]);
$raw_events = $stmt->fetchAll();
$calendar_events = [];
foreach ($raw_events as $evt) {
    $calendar_events[$evt['event_date']][] = $evt;
}

// Fetch recently added bookings for list view
$stmt = $conn->query("SELECT b.*, p.name as package_name, u.full_name
    FROM event_bookings b
    JOIN event_packages p ON b.package_id = p.id
    JOIN customers u ON b.user_id = u.id
    ORDER BY b.created_at DESC LIMIT 10");
$recent_bookings = $stmt->fetchAll();

// Calendar Building
$first_day_timestamp = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day_timestamp);
$first_day_of_week = date('w', $first_day_timestamp); // 0 (Sun) - 6 (Sat)
?>

<div class="dashboard-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1><i class="fas fa-calendar-alt"></i> Event Management</h1>
        <div class="btn-group">
            <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="btn-submit"
                style="margin-right: 0.5rem;"><i class="fas fa-chevron-left"></i> Prev</a>
            <span style="font-size: 1.5rem; font-weight: bold; margin: 0 1rem;">
                <?php echo date('F Y', $first_day_timestamp); ?>
            </span>
            <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="btn-submit">Next <i
                    class="fas fa-chevron-right"></i></a>
        </div>
    </div>

    <!-- Calendar -->
    <div class="calendar-wrapper"
        style="background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 3rem;">
        <div class="calendar-grid">
            <div class="cal-head">Sun</div>
            <div class="cal-head">Mon</div>
            <div class="cal-head">Tue</div>
            <div class="cal-head">Wed</div>
            <div class="cal-head">Thu</div>
            <div class="cal-head">Fri</div>
            <div class="cal-head">Sat</div>

            <?php
            // Empty slots for previous month
            for ($i = 0; $i < $first_day_of_week; $i++) {
                echo '<div class="cal-cell empty"></div>';
            }

            // Days
            for ($day = 1; $day <= $days_in_month; $day++) {
                $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $is_today = ($current_date == date('Y-m-d'));
                $has_events = isset($calendar_events[$current_date]);

                echo '<div class="cal-cell ' . ($is_today ? 'today' : '') . '">';
                echo '<div class="day-num">' . $day . '</div>';

                if ($has_events) {
                    foreach ($calendar_events[$current_date] as $event) {
                        $status_color = match ($event['status']) {
                            'confirmed' => '#27ae60',
                            'pending' => '#f39c12',
                            'cancelled' => '#e74c3c',
                            'completed' => '#2980b9',
                            default => '#95a5a6'
                        };
                        echo '<a href="view.php?id=' . $event['id'] . '" class="event-pill" style="background: ' . $status_color . '">';
                        echo htmlspecialchars($event['full_name']);
                        echo '</a>';
                    }
                }
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <!-- Recent Bookings List -->
    <div class="dashboard-card">
        <h3><i class="fas fa-clock"></i> Recent Bookings</h3>
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="padding: 1rem; text-align: left;">ID</th>
                    <th style="padding: 1rem; text-align: left;">Customer</th>
                    <th style="padding: 1rem; text-align: left;">Package</th>
                    <th style="padding: 1rem; text-align: left;">Event Date</th>
                    <th style="padding: 1rem; text-align: left;">Status</th>
                    <th style="padding: 1rem; text-align: left;">Total</th>
                    <th style="padding: 1rem; text-align: left;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_bookings as $booking): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 1rem;">#
                            <?php echo $booking['id']; ?>
                        </td>
                        <td style="padding: 1rem;">
                            <?php echo htmlspecialchars($booking['full_name']); ?>
                        </td>
                        <td style="padding: 1rem;">
                            <?php echo htmlspecialchars($booking['package_name']); ?>
                        </td>
                        <td style="padding: 1rem;">
                            <?php echo date('M d, Y', strtotime($booking['event_date'])); ?>
                        </td>
                        <td style="padding: 1rem;">
                            <span class="badge badge-<?php
                            echo match ($booking['status']) {
                                'confirmed' => 'success',
                                'pending' => 'warning',
                                'cancelled' => 'danger',
                                'completed' => 'info',
                                default => 'secondary'
                            };
                            ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </td>
                        <td style="padding: 1rem;">
                            <?php echo format_currency($booking['total_amount']); ?>
                        </td>
                        <td style="padding: 1rem;">
                            <a href="view.php?id=<?php echo $booking['id']; ?>" class="btn-icon" title="View Details"
                                style="color: var(--primary-color);">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background: #eee;
        border: 1px solid #ddd;
    }

    .cal-head {
        background: #f8f9fa;
        padding: 1rem;
        text-align: center;
        font-weight: 600;
        color: #666;
    }

    .cal-cell {
        background: white;
        min-height: 120px;
        padding: 0.5rem;
        position: relative;
    }

    .cal-cell.today {
        background: #fff8f0;
    }

    .day-num {
        font-weight: 700;
        margin-bottom: 0.5rem;
        color: #333;
    }

    .event-pill {
        display: block;
        padding: 0.2rem 0.5rem;
        margin-bottom: 0.2rem;
        color: white;
        font-size: 0.75rem;
        border-radius: 4px;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: opacity 0.2s;
    }

    .event-pill:hover {
        opacity: 0.8;
    }
</style>

<?php include '../includes/admin_footer.php'; ?>