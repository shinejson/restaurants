<?php
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
$vegetarian = isset($_GET['vegetarian']) && $_GET['vegetarian'] == '1' ? true : false;
$spicy = isset($_GET['spicy']) && $_GET['spicy'] == '1' ? true : false;
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 10000;
$sort = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'name';

// Build query for food items
$query = "SELECT fi.*, sc.name AS sub_category, mc.name AS main_category 
          FROM food_items fi 
          JOIN sub_categories sc ON fi.sub_category_id = sc.id 
          JOIN main_categories mc ON sc.main_category_id = mc.id 
          WHERE (fi.inactive = 0 OR fi.inactive IS NULL)";

$params = [];
$param_types = '';

// Add filters
if ($search) {
    $query .= " AND (fi.item_name LIKE ? OR fi.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $param_types .= 'ss';
}

if ($category) {
    $query .= " AND mc.name = ?";
    $params[] = $category;
    $param_types .= 's';
}

if ($vegetarian) {
    $query .= " AND fi.is_vegetarian = 1";
}

if ($spicy) {
    $query .= " AND fi.is_spicy = 1";
}

if ($min_price > 0) {
    $query .= " AND fi.price >= ?";
    $params[] = $min_price;
    $param_types .= 'd';
}

if ($max_price > 0 && $max_price < 10000) {
    $query .= " AND fi.price <= ?";
    $params[] = $max_price;
    $param_types .= 'd';
}

// Add sorting
switch ($sort) {
    case 'price_low':
        $query .= " ORDER BY fi.price ASC";
        break;
    case 'price_high':
        $query .= " ORDER BY fi.price DESC";
        break;
    case 'name':
    default:
        $query .= " ORDER BY fi.item_name ASC";
        break;
}

// Get items
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}
$items = $stmt->fetchAll();

// Get unique main categories for filter
$cat_stmt = $conn->query("SELECT DISTINCT name FROM main_categories ORDER BY name");
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get price range for filter
$price_stmt = $conn->query("SELECT MIN(price) as min_price, MAX(price) as max_price FROM food_items WHERE inactive = 0 OR inactive IS NULL");
$price_range = $price_stmt->fetch();
$min_db_price = $price_range['min_price'] ?? 0;
$max_db_price = $price_range['max_price'] ?? 1000;

// Gather tenant-aware display metadata
$meta = tenant_meta();

// SEO Meta Tags
$page_title = "Full Menu | " . $meta['name'];
$page_description = "Explore our diverse menu featuring delicious options at " . $meta['name'] . ". Order online for fast delivery or dine-in.";

include 'includes/header.php';
?>

<!-- Structured Data (JSON-LD) -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Menu",
  "name": "<?php echo htmlspecialchars($meta['name']); ?> Menu",
  "url": "<?php echo BASE_URL; ?>/menu.php",
  "mainEntityOfPage": "<?php echo BASE_URL; ?>/menu.php",
  "inLanguage": "en",
  "hasMenuSection": [
    <?php
    // Generate menu sections for JSON-LD
    $json_cats = [];
    foreach ($categories as $cat) {
        $json_cats[] = '{
            "@type": "MenuSection",
            "name": "' . htmlspecialchars($cat) . '",
            "image": "' . BASE_URL . '/assets/images/logo.png"
        }';
    }
    echo implode(',', $json_cats);
    ?>
  ]
}
</script>

<!-- Hero Carousel (Static for Menu) -->
<div class="hero-header hero-small">
    <div class="container hero-text">
        <h1>Food Menu</h1>
        <p>HOME / MENU</p>
    </div>
</div>

<!-- Menu Filters -->
<section class="container filter-wrapper">
    <div class="filter-card">
        <div class="filter-header">
            <h2><i class="fas fa-sliders-h"></i> Filter Menu</h2>
            <p>Find your perfect meal</p>
        </div>

        <form method="GET" class="filter-form" id="menuFilterForm">
            <div class="filter-grid">
                <!-- Search -->
                <div class="form-group search-box">
                    <label for="search">Search</label>
                    <div class="input-icon-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" id="search" name="search" placeholder="Search dishes..."
                            value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                    </div>
                </div>

                <!-- Category -->
                <div class="form-group">
                    <label for="category">Category</label>
                    <div class="select-wrapper">
                        <select id="category" name="category" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($cat == $category) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Sort -->
                <div class="form-group">
                    <label for="sort">Sort By</label>
                    <div class="select-wrapper">
                        <select id="sort" name="sort" class="form-control">
                            <option value="name" <?php echo $sort == 'name' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="price_low" <?php echo $sort == 'price_low' ? 'selected' : ''; ?>>Price: Low to
                                High</option>
                            <option value="price_high" <?php echo $sort == 'price_high' ? 'selected' : ''; ?>>Price: High
                                to Low</option>
                        </select>
                    </div>
                </div>

                <!-- Price Range -->
                <div class="form-group price-group">
                    <label>Price Range</label>
                    <div class="price-inputs">
                        <div class="input-symbol">
                            <span>₵</span>
                            <input type="number" name="min_price" placeholder="Min"
                                value="<?php echo $min_price > 0 ? $min_price : ''; ?>" class="form-control">
                        </div>
                        <span class="separator">-</span>
                        <div class="input-symbol">
                            <span>₵</span>
                            <input type="number" name="max_price" placeholder="Max"
                                value="<?php echo $max_price < 10000 ? $max_price : ''; ?>" class="form-control">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dietary Options -->
            <div class="filter-options">
                <label class="checkbox-btn">
                    <input type="checkbox" name="vegetarian" value="1" <?php echo $vegetarian ? 'checked' : ''; ?>>
                    <span class="btn-label">
                        <i class="fas fa-leaf"></i> Vegetarian
                    </span>
                </label>
                <label class="checkbox-btn">
                    <input type="checkbox" name="spicy" value="1" <?php echo $spicy ? 'checked' : ''; ?>>
                    <span class="btn-label">
                        <i class="fas fa-pepper-hot"></i> Spicy
                    </span>
                </label>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-filter-apply">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <a href="menu.php" class="btn btn-filter-reset">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </form>
    </div>
</section>

<!-- Menu Statistics -->
<section class="container stats-section">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo count($items); ?></div>
            <div class="stat-label">Total Items</div>
        </div>

        <div class="stat-card">
            <div class="stat-value"><?php echo count($categories); ?></div>
            <div class="stat-label">Categories</div>
        </div>

        <div class="stat-card">
            <div class="stat-value"><?php echo format_currency($min_db_price); ?></div>
            <div class="stat-label">Starting From</div>
        </div>
    </div>
</section>

<!-- Menu Items -->
<section class="container menu-items-section">
    <?php if (empty($items)): ?>
        <div class="empty-state-card">
            <i class="fas fa-search"></i>
            <h3>No items found</h3>
            <p>Try adjusting your search or filter criteria</p>
            <a href="menu.php" class="btn btn-hero">Clear All Filters</a>
        </div>
    <?php else: ?>
        <div class="menu-categories">
            <?php
            // Group items by main category
            $grouped_items = [];
            foreach ($items as $item) {
                $category_name = $item['main_category'];
                if (!isset($grouped_items[$category_name])) {
                    $grouped_items[$category_name] = [];
                }
                $grouped_items[$category_name][] = $item;
            }

            foreach ($grouped_items as $category_name => $category_items):
                ?>
                <div class="category-block">
                    <div class="section-header">
                        <h5 class="section-subtitle">Food Menu</h5>
                        <h1 class="section-title"><?php echo htmlspecialchars($category_name); ?></h1>
                    </div>

                    <?php
                    $menuLayout = (function_exists('feature_enabled') && feature_enabled('menu_card_styles')) ? get_setting('menu_card_layout', 'grid') : 'grid';
                    $layoutClass = 'menu-grid';
                    if ($menuLayout === 'bistro') {
                        $layoutClass .= ' layout-bistro';
                    } elseif ($menuLayout === 'compact') {
                        $layoutClass .= ' layout-compact';
                    }
                    ?>
                    <div class="<?php echo $layoutClass; ?>">
                        <?php foreach ($category_items as $item): ?>
                            <?php include 'includes/menu_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Quick Navigation -->
<section class="container quick-nav-section">
    <div class="quick-nav-card">
        <h2>Quick Categories</h2>
        <div class="pill-group">
            <?php foreach ($categories as $cat): ?>
                <a href="menu.php?category=<?php echo urlencode($cat); ?>" class="pill">
                    <?php echo htmlspecialchars($cat); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>