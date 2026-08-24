<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get search and filter parameters
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$category = isset($_GET['category']) ? clean_input($_GET['category']) : '';

try {
    // Build query for food items
    $query = "SELECT fi.*, sc.name AS sub_category, mc.name AS main_category 
              FROM food_items fi 
              JOIN sub_categories sc ON fi.sub_category_id = sc.id 
              JOIN main_categories mc ON sc.main_category_id = mc.id 
              WHERE fi.inactive = 0";

    $params = [];

    if ($search) {
        $query .= " AND (fi.item_name LIKE ? OR fi.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($category) {
        $query .= " AND mc.name = ?";
        $params[] = $category;
    }

    // Fix: Removed non-existent display_order columns
    $query .= " ORDER BY mc.name, sc.name, fi.item_name";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    // Get unique main categories for filter
    // Fix: Removed non-existent display_order
    $cat_stmt = $conn->query("SELECT DISTINCT name FROM main_categories ORDER BY name");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// SEO Meta Tags
$page_title = "Home | Airport West Hotel - Delicious Food Delivered";
$page_description = "Order the best food from Airport West Hotel. We offer a wide variety of local and continental dishes delivered straight to your door.";

include 'includes/header.php';
?>

<!-- Structured Data (JSON-LD) -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebSite",
      "@id": "<?php echo BASE_URL; ?>/#website",
      "url": "<?php echo BASE_URL; ?>",
      "name": "Airport West Hotel Food Ordering",
      "description": "<?php echo $page_description; ?>",
      "potentialAction": [
        {
          "@type": "SearchAction",
          "target": {
            "@type": "EntryPoint",
            "urlTemplate": "<?php echo BASE_URL; ?>/index.php?search={search_term_string}"
          },
          "query-input": "required name=search_term_string"
        }
      ]
    },
    {
      "@type": "Restaurant",
      "@id": "<?php echo BASE_URL; ?>/#restaurant",
      "name": "Airport West Hotel",
      "image": "<?php echo BASE_URL; ?>/assets/images/logo.png",
      "url": "<?php echo BASE_URL; ?>",
      "telephone": "+233244123456", 
      "priceRange": "GH₵₵",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Airport West",
        "addressLocality": "Accra",
        "addressCountry": "GH"
      },
      "geo": {
        "@type": "GeoCoordinates",
        "latitude": 5.6037,
        "longitude": -0.1870
      },
      "servesCuisine": "Local, Continental"
    }
  ]
}
</script>

<!-- Hero Carousel -->
<div class="hero-header">
    <div class="hero-carousel-container container">
        <div class="hero-slide active">
            <div class="hero-text">
                <h1>Enjoy Our<br>Delicious Meal</h1>
                <p>Tempor erat elitr rebum at clita. Diam dolor diam ipsum sit. Aliqu diam amet diam et eos. Clita erat
                    ipsum et lorem et sit, sed stet lorem sit clita duo justo magna dolore erat amet</p>
                <a href="menu.php" class="btn btn-hero">Book A Table</a>
            </div>
            <div class="hero-img">
                <img src="assets/images/hero-burger.png" alt="Hero Burger">
            </div>
        </div>
        <div class="hero-slide">
            <div class="hero-text">
                <h1>Fresh &<br>Quality Food</h1>
                <p>Tempor erat elitr rebum at clita. Diam dolor diam ipsum sit. Aliqu diam amet diam et eos. Clita erat
                    ipsum et lorem et sit, sed stet lorem sit clita duo justo magna dolore erat amet</p>
                <a href="menu.php" class="btn btn-hero">View Menu</a>
            </div>
            <div class="hero-img">
                <img src="assets/images/hero-burger.png" alt="Hero Burger">
            </div>
        </div>
    </div>
</div>

<!-- Service Section -->
<section class="container service-section">
    <div class="service-grid">
        <div class="service-item">
            <i class="fas fa-user-tie"></i>
            <h5>Master Chefs</h5>
            <p>Diam elitr kasd sed at elitr sed ipsum justo dolor sed clita amet diam</p>
        </div>
        <div class="service-item">
            <i class="fas fa-utensils"></i>
            <h5>Quality Food</h5>
            <p>Diam elitr kasd sed at elitr sed ipsum justo dolor sed clita amet diam</p>
        </div>
        <div class="service-item">
            <i class="fas fa-cart-plus"></i>
            <h5>Online Order</h5>
            <p>Diam elitr kasd sed at elitr sed ipsum justo dolor sed clita amet diam</p>
        </div>
        <div class="service-item">
            <i class="fas fa-headset"></i>
            <h5>24/7 Service</h5>
            <p>Diam elitr kasd sed at elitr sed ipsum justo dolor sed clita amet diam</p>
        </div>
    </div>
</section>

<!-- About Section -->
<section class="container about-section">
    <div class="about-grid">
        <div class="about-img">
            <div class="about-img-grid">
                <img src="assets/images/about-grid.png" alt="About Grid">
            </div>
        </div>
        <div class="about-content">
            <h5 class="section-subtitle">About Us</h5>
            <h1 class="section-title">Welcome to <i class="fas fa-utensils"></i> Restoran</h1>
            <p>Tempor erat elitr rebum at clita. Diam dolor diam ipsum sit.
                Aliqu diam amet diam et eos erat ipsum et lorem et sit, sed stet lorem sit.</p>
            <p>Tempor erat elitr rebum at clita. Diam dolor diam ipsum sit.
                Aliqu diam amet diam et eos. Clita erat ipsum et lorem et sit, sed stet lorem sit clita duo justo magna
                dolore erat amet</p>

            <div class="about-stats">
                <div class="stat-item">
                    <h1>15</h1>
                    <p>Years of<br>EXPERIENCE</p>
                </div>
                <div class="stat-item">
                    <h1>50</h1>
                    <p>Popular<br>MASTER CHEFS</p>
                </div>
            </div>

            <a href="menu.php" class="btn btn-hero">Read More</a>
        </div>
    </div>
</section>

<!-- Featured Items (Menu) -->
<section class="container food-menu-section">
    <div class="section-header">
        <h5 class="section-subtitle">Food Menu</h5>
        <h1 class="section-title">Most Popular Items</h1>
    </div>

    <!-- Search/Filter (Redesigned) -->
    <div class="filter-section">
        <form method="GET" class="filter-form" id="filterForm">
            <div class="search-group">
                <input type="text" id="search" name="search" placeholder="Search for food..."
                    value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="category-group">
                <select id="category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($cat == $category) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Search</button>
        </form>
    </div>

    <?php if (empty($items)): ?>
        <p style="text-align: center;">No items found.</p>
    <?php else: ?>
        <div class="menu-grid">
            <?php foreach ($items as $item): ?>
                <div class="food-card">
                    <div class="food-img-container">
                        <?php $image = !empty($item['image_url']) ? htmlspecialchars($item['image_url']) : BASE_URL . '/assets/images/food-placeholder.jpg'; ?>
                        <img src="<?php echo $image; ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                            class="food-img">
                    </div>
                    <div class="food-content">
                        <h5 class="food-title"><?php echo htmlspecialchars($item['item_name']); ?></h5>
                        <p class="food-description">
                            <?php
                            $desc = isset($item['description']) ? $item['description'] : '';
                            echo strlen($desc) > 50 ? substr(htmlspecialchars($desc), 0, 50) . '...' : htmlspecialchars($desc);
                            ?>
                        </p>
                        <div class="food-price">
                            <?php if (!empty($item['promo_price'])): ?>
                                <span class="original-price"
                                    style="text-decoration: line-through; color: var(--text-muted); font-size: 0.9rem; margin-right: 0.5rem;"><?php echo format_currency($item['price']); ?></span>
                                <span class="promo-price"
                                    style="color: var(--primary-color); font-weight: 800;"><?php echo format_currency($item['promo_price']); ?></span>
                            <?php else: ?>
                                <?php echo format_currency($item['price']); ?>
                            <?php endif; ?>
                        </div>
                        <button class="add-to-cart-btn" data-id="<?php echo $item['id']; ?>"
                            data-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                            data-price="<?php echo !empty($item['promo_price']) ? $item['promo_price'] : $item['price']; ?>"
                            data-image="<?php echo $image; ?>">
                            <i class="fas fa-cart-plus"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section-footer">
        <a href="menu.php" class="btn btn-outline">View Full Menu</a>
    </div>
</section>

<!-- Testimonials -->
<section class="container" style="margin-bottom: 6rem;">
    <div style="text-align: center; margin-bottom: 3rem;">
        <h5 style="color: var(--primary-color); font-weight: bold; text-transform: uppercase;">Testimonial</h5>
        <h1 style="color: var(--dark-color); font-size: 2.5rem;">Our Clients Say!!!</h1>
    </div>

    <div class="testimonial-carousel">
        <div class="testimonial-track">
            <div class="testimonial-item">
                <div class="testimonial-content">
                    <i class="fas fa-quote-left"></i>
                    <p>Dolor et eos labore, stet justo sed est sed. Diam sed sed dolor stet amet eirmod eos labore diam
                    </p>
                </div>
                <div class="client-info">
                    <img src="assets/images/testimonials-ref.png" alt="Client" class="client-img"> <!-- Placeholder -->
                    <div style="text-align: left;">
                        <h5 style="margin: 0; color: var(--dark-color);">Client Name</h5>
                        <small style="color: #666;">Profession</small>
                    </div>
                </div>
            </div>
            <div class="testimonial-item">
                <div class="testimonial-content" style="background: var(--warning-color);">
                    <i class="fas fa-quote-left"></i>
                    <p>Dolor et eos labore, stet justo sed est sed. Diam sed sed dolor stet amet eirmod eos labore diam
                    </p>
                </div>
                <div class="client-info">
                    <img src="assets/images/testimonials-ref1.png" alt="Client" class="client-img">
                    <div style="text-align: left;">
                        <h5 style="margin: 0; color: var(--dark-color);">Client Name</h5>
                        <small style="color: #666;">Profession</small>
                    </div>
                </div>
            </div>
            <div class="testimonial-item">
                <div class="testimonial-content">
                    <i class="fas fa-quote-left"></i>
                    <p>Dolor et eos labore, stet justo sed est sed. Diam sed sed dolor stet amet eirmod eos labore diam
                    </p>
                </div>
                <div class="client-info">
                    <img src="assets/images/testimonials-ref2.png" alt="Client" class="client-img">
                    <div style="text-align: left;">
                        <h5 style="margin: 0; color: var(--dark-color);">Client Name</h5>
                        <small style="color: #666;">Profession</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="carousel-dots">
            <span class="dot active" onclick="currentSlide(1)"></span>
            <span class="dot" onclick="currentSlide(2)"></span>
            <span class="dot" onclick="currentSlide(3)"></span>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>