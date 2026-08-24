<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

$active_page = 'events';
include 'includes/header.php';

// Fetch all event packages
$stmt = $conn->query("SELECT * FROM event_packages ORDER BY base_price_per_head ASC");
$packages = $stmt->fetchAll();

// Fetch upcoming events
$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT * FROM events WHERE event_date >= ? ORDER BY event_date ASC");
$stmt->execute([$today]);
$events = $stmt->fetchAll();
?>

<!-- Hero Section -->
<div class="events-hero">
    <div class="container hero-text">
        <h1>Celebrate With Us</h1>
        <p>From intimate gatherings to grand celebrations, we provide the perfect setting and service for your special
            moments.</p>
    </div>
</div>

<!-- Tabs Section -->
<div class="container" style="padding: 4rem 1rem;">
    <!-- Tab Navigation -->
    <div class="events-tabs">
        <button class="tab-btn active" onclick="openTab(event, 'upcomingEvents')">Upcoming Events</button>
        <button class="tab-btn" onclick="openTab(event, 'eventPackages')">Event Packages</button>
    </div>

    <!-- Upcoming Events Tab -->
    <div id="upcomingEvents" class="tab-content" style="display: block;">
        <div class="section-header">
            <h2 class="section-title">Upcoming Events</h2>
            <p class="section-desc">Join us for our special nights and parties.</p>
        </div>

        <?php if (empty($events)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-alt"></i>
                <h3>No upcoming events scheduled</h3>
                <p>Stay tuned for our future announcements!</p>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($events as $evt): ?>
                    <div class="event-card">
                        <div class="event-img">
                            <?php if (!empty($evt['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($evt['image_url']); ?>"
                                    alt="<?php echo htmlspecialchars($evt['title']); ?>">
                            <?php else: ?>
                                <div class="placeholder"><i class="fas fa-calendar-day"></i></div>
                            <?php endif; ?>
                            <div class="event-date">
                                <b><?php echo date('d', strtotime($evt['event_date'])); ?></b>
                                <span><?php echo date('M', strtotime($evt['event_date'])); ?></span>
                            </div>
                        </div>
                        <div class="event-details">
                            <h3><?php echo htmlspecialchars($evt['title']); ?></h3>
                            <div class="event-meta">
                                <span><i class="far fa-clock"></i>
                                    <?php echo date('h:i A', strtotime($evt['start_time'])); ?></span>
                                <span><i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($evt['location']); ?></span>
                            </div>
                            <p><?php echo htmlspecialchars($evt['description']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Event Packages Tab -->
    <div id="eventPackages" class="tab-content" style="display: none;">
        <div class="section-header">
            <h2 class="section-title">Our Packages</h2>
            <p class="section-desc">Choose a package that suits your needs.</p>
        </div>

        <?php if (empty($packages)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No packages available</h3>
                <p>Contact us for custom arrangements.</p>
            </div>
        <?php else: ?>
            <div class="packages-grid">
                <?php foreach ($packages as $pkg): ?>
                    <div class="package-card">
                        <div class="package-img">
                            <?php if (!empty($pkg['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($pkg['image_url']); ?>"
                                    alt="<?php echo htmlspecialchars($pkg['name']); ?>">
                            <?php else: ?>
                                <div class="placeholder"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                            <div class="price-tag">
                                <?php echo format_currency($pkg['base_price_per_head']); ?> / guest
                            </div>
                        </div>
                        <div class="package-content">
                            <h3><?php echo htmlspecialchars($pkg['name']); ?></h3>
                            <p><?php echo htmlspecialchars($pkg['description']); ?></p>
                            <a href="event_booking.php?package_id=<?php echo $pkg['id']; ?>" class="btn-book">
                                Book Now <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    /* Tabs Styles */
    .events-tabs {
        display: flex;
        justify-content: center;
        margin-bottom: 3rem;
        border-bottom: 2px solid #eee;
    }

    .tab-btn {
        background: none;
        border: none;
        padding: 1rem 2rem;
        font-size: 1.2rem;
        font-weight: 600;
        color: #666;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.3s;
    }

    .tab-btn:hover {
        color: var(--primary-color);
    }

    .tab-btn.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
    }

    .tab-content {
        animation: fadeIn 0.5s;
    }

    /* Events Grid */
    .events-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 2rem;
    }

    .event-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s;
    }

    .event-card:hover {
        transform: translateY(-5px);
    }

    .event-img {
        height: 200px;
        position: relative;
        background: #eee;
    }

    .event-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .event-date {
        position: absolute;
        top: 1rem;
        left: 1rem;
        background: white;
        padding: 0.5rem 1rem;
        border-radius: 8px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .event-date b {
        display: block;
        font-size: 1.5rem;
        color: var(--primary-color);
        line-height: 1;
    }

    .event-date span {
        font-size: 0.8rem;
        text-transform: uppercase;
        color: #333;
    }

    .event-details {
        padding: 1.5rem;
    }

    .event-details h3 {
        margin-bottom: 0.5rem;
        font-size: 1.3rem;
    }

    .event-meta {
        display: flex;
        gap: 1rem;
        color: #888;
        font-size: 0.9rem;
        margin-bottom: 1rem;
    }

    /* Packages Grid (Reuse logic via class updates) */
    .packages-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 2rem;
    }

    .package-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        transition: transform 0.3s;
    }

    .package-card:hover {
        transform: translateY(-5px);
    }

    .package-img {
        height: 250px;
        position: relative;
        background: #eee;
    }

    .package-img .placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        color: #aaa;
    }

    .package-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .price-tag {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background: rgba(255, 255, 255, 0.95);
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-weight: 700;
        color: var(--primary-color);
    }

    .package-content {
        padding: 2rem;
    }

    .btn-book {
        display: block;
        text-align: center;
        background: var(--primary-color);
        color: white;
        padding: 0.8rem;
        border-radius: 8px;
        text-decoration: none;
        margin-top: 1.5rem;
        font-weight: bold;
        transition: 0.3s;
    }

    .btn-book:hover {
        background: var(--dark-color);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem;
        color: #999;
    }

    .empty-state i {
        font-size: 4rem;
        margin-bottom: 1rem;
        color: #ddd;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }
</style>

<script>
    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].style.display = "none";
        }
        tablinks = document.getElementsByClassName("tab-btn");
        for (i = 0; i < tablinks.length; i++) {
            tablinks[i].className = tablinks[i].className.replace(" active", "");
        }
        document.getElementById(tabName).style.display = "block";
        evt.currentTarget.className += " active";
    }
</script>

<style>
    .package-card:hover {
        transform: translateY(-5px);
    }
</style>

<?php include 'includes/footer.php'; ?>