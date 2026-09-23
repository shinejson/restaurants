<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

// If this is a request to the root platform without a tenant context, serve the SaaS Landing Page
if (empty($_GET['__tenant']) && !\Resto\Tenancy\Links::usesHostRouting()) {
    require_once __DIR__ . '/landing.php';
    exit;
}

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

// Gather tenant-aware display metadata (name, phone, address, currency, ...)
$meta = tenant_meta();

// SEO Meta Tags
$page_title = "Home | " . $meta['name'];
$page_description = $meta['description'];

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
      "name": "<?php echo htmlspecialchars($meta['name'] . ' Food Ordering'); ?>",
      "description": "<?php echo htmlspecialchars($meta['description']); ?>",
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
      "name": "<?php echo htmlspecialchars($meta['name']); ?>",
      "image": "<?php echo BASE_URL; ?>/assets/images/logo.png",
      "url": "<?php echo BASE_URL; ?>",
      "telephone": "<?php echo htmlspecialchars($meta['phone']); ?>",
      "priceRange": "<?php echo htmlspecialchars($meta['currency_symbol'] . $meta['currency_symbol']); ?>",
      "address": {
        "@type": "PostalAddress",
        <?php
        // Split the stored "City Country" address back into parts for JSON-LD
        $addrParts = array_map('trim', explode(' ', $meta['address'], 2));
        $streetAddress = $addrParts[0] ?? '';
        $locality      = $addrParts[0] ?? '';
        $country       = $addrParts[1] ?? '';
        ?>
        "streetAddress": "<?php echo htmlspecialchars($streetAddress); ?>",
        "addressLocality": "<?php echo htmlspecialchars($locality); ?>",
        "addressCountry": "<?php echo htmlspecialchars($country); ?>"
      },
      "geo": {
        "@type": "GeoCoordinates",
        "latitude": <?php echo $meta['latitude']; ?>,
        "longitude": <?php echo $meta['longitude']; ?>
      },
      "servesCuisine": "<?php echo htmlspecialchars($meta['cuisine']); ?>"
    }
  ]
}
</script>

<?php
$hasHeroCustomization = function_exists('feature_enabled') && feature_enabled('hero_customization');
$customHeroTitle = $hasHeroCustomization ? get_setting('hero_title') : '';
$customHeroSubtitle = $hasHeroCustomization ? get_setting('hero_subtitle') : '';
$customHeroCtaText = $hasHeroCustomization ? get_setting('hero_cta_text') : '';
$customHeroCtaLink = $hasHeroCustomization ? get_setting('hero_cta_link') : '';
$customHeroImage = $hasHeroCustomization ? get_setting('hero_image') : '';

$isCustomHero = !empty($customHeroTitle) || !empty($customHeroImage);
?>
<!-- Hero Carousel -->
<div class="hero-header">
    <div class="hero-carousel-container container">
        <?php if ($isCustomHero):
            $heroImgSrc = !empty($customHeroImage)
                ? (strpos($customHeroImage, 'http') === 0 ? $customHeroImage : BASE_URL . '/' . ltrim($customHeroImage, '/'))
                : 'assets/images/hero-burger.png';
            $ctaHref = !empty($customHeroCtaLink)
                ? ((strpos($customHeroCtaLink, 'http') === 0 || strpos($customHeroCtaLink, '#') === 0) ? $customHeroCtaLink : tenant_url($customHeroCtaLink))
                : 'menu.php';
            $ctaLabel = !empty($customHeroCtaText) ? $customHeroCtaText : 'Book A Table';
        ?>
        <div class="hero-slide active">
            <div class="hero-text">
                <h1><?php echo nl2br(htmlspecialchars($customHeroTitle ?: 'Enjoy Our<br>Delicious Meal')); ?></h1>
                <?php if (!empty($customHeroSubtitle)): ?>
                    <p><?php echo htmlspecialchars($customHeroSubtitle); ?></p>
                <?php else: ?>
                    <p><?php echo htmlspecialchars($meta['tagline']); ?></p>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($ctaHref); ?>" class="btn btn-hero"><?php echo htmlspecialchars($ctaLabel); ?></a>
            </div>
            <div class="hero-img">
                <img src="<?php echo htmlspecialchars($heroImgSrc); ?>" alt="<?php echo htmlspecialchars($meta['name']); ?>">
            </div>
        </div>
        <?php else: ?>
        <div class="hero-slide active">
            <div class="hero-text">
                <h1>Enjoy Our<br>Delicious Meal</h1>
                <p><?php echo htmlspecialchars($meta['tagline']); ?></p>
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
        <?php endif; ?>
    </div>
</div>

<?php if (get_setting('section_show_services', '1') !== '0'): ?>
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
<?php endif; ?>

<?php if (get_setting('section_show_about', '1') !== '0'): ?>
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
            <h1 class="section-title">Welcome to <i class="fas fa-utensils"></i> <?php echo htmlspecialchars($meta['name']); ?></h1>
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
<?php endif; ?>

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

    <!-- Category Tabs -->
    <div class="category-tabs" id="categoryTabs">
        <a href="index.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>"
            data-category=""
            class="category-tab <?php echo empty($category) ? 'active' : ''; ?>">
            <i class="fas fa-border-all"></i> All Items
        </a>
        <?php foreach ($categories as $cat): ?>
            <a href="index.php?category=<?php echo urlencode($cat); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                data-category="<?php echo htmlspecialchars($cat); ?>"
                class="category-tab <?php echo ($cat == $category) ? 'active' : ''; ?>">
                <i class="fas fa-utensils"></i> <?php echo htmlspecialchars($cat); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div id="menuItemsWrapper">
    <?php if (empty($items)): ?>
        <div class="empty-state-card">
            <i class="fas fa-search"></i>
            <h3>No items found</h3>
            <p>Try a different search or choose another category above.</p>
        </div>
    <?php else: ?>
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
            <?php foreach ($items as $item): ?>
                <?php include 'includes/menu_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>

    <div class="section-footer">
        <a href="menu.php" class="btn btn-outline">View Full Menu</a>
    </div>
</section>

<?php if (get_setting('section_show_testimonials', '1') !== '0'): ?>
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
<?php endif; ?>

<script>
    // AJAX filtering for category tabs & search (prevents page jump to top on filter)
    document.addEventListener('DOMContentLoaded', function () {
        const tabsContainer = document.getElementById('categoryTabs');
        const wrapper = document.getElementById('menuItemsWrapper');
        const searchForm = document.getElementById('filterForm');
        const categorySelect = document.getElementById('category');
        const searchInput = document.getElementById('search');

        if (!tabsContainer || !wrapper) return;

        wrapper.style.transition = 'opacity .2s ease';

        function escapeHtml(str) {
            const amp = String.fromCharCode(38);
            return String(str == null ? '' : str)
                .replace(/&/g, amp)
                .replace(/</g, amp + 'lt;')
                .replace(/>/g, amp + 'gt;')
                .replace(/"/g, amp + 'quot;')
                .replace(/'/g, amp + '#039;');
        }

        function buildUrl(category, search) {
            const params = new URLSearchParams();
            if (search) params.set('search', search);
            if (category) params.set('category', category);
            const qs = params.toString();
            return 'index.php' + (qs ? '?' + qs : '');
        }

        const MENU_LAYOUT = '<?php echo (function_exists('feature_enabled') && feature_enabled('menu_card_styles')) ? get_setting('menu_card_layout', 'grid') : 'grid'; ?>';
        const SHOW_DIETARY = <?php echo (function_exists('feature_enabled') && feature_enabled('menu_card_styles') && get_setting('menu_show_dietary_badges', '1') !== '0') ? 'true' : 'false'; ?>;
        const SHOW_CALORIES = <?php echo (function_exists('feature_enabled') && feature_enabled('menu_card_styles') && get_setting('menu_show_calories', '0') === '1') ? 'true' : 'false'; ?>;

        function renderItems(items) {
            if (!items.length) {
                wrapper.innerHTML = `
                    <div class="empty-state-card">
                        <i class="fas fa-search"></i>
                        <h3>No items found</h3>
                        <p>Try a different search or choose another category above.</p>
                    </div>`;
                return;
            }

            const placeholder = BASE_URL + '/assets/images/food-placeholder.jpg';

            const cards = items.map(function (item) {
                const image = item.image_url ? item.image_url : placeholder;
                const desc = item.description
                    ? (item.description.length > 50 ? escapeHtml(item.description.substring(0, 50)) + '...' : escapeHtml(item.description))
                    : '';
                const price = item.promo_formatted
                    ? `<span class="original-price">${escapeHtml(item.price_formatted)}</span><span class="promo-price">${escapeHtml(item.promo_formatted)}</span>`
                    : escapeHtml(item.price_formatted);
                const activePrice = item.promo_price ? item.promo_price : item.price;

                let badgesHtml = '';
                if (SHOW_DIETARY) {
                    let badges = [];
                    if (item.is_vegetarian == 1) badges.push('<span class="badge-diet veg"><i class="fas fa-leaf"></i> Veg</span>');
                    if (item.is_spicy == 1) badges.push('<span class="badge-diet spicy"><i class="fas fa-pepper-hot"></i> Spicy</span>');
                    if (SHOW_CALORIES && item.calories > 0) badges.push('<span class="badge-diet cal">' + escapeHtml(item.calories) + ' kcal</span>');
                    if (badges.length) badgesHtml = '<div class="dietary-badges-row">' + badges.join('') + '</div>';
                }

                if (MENU_LAYOUT === 'bistro') {
                    return `
                    <div class="food-card-bistro">
                        <img src="${escapeHtml(image)}" alt="${escapeHtml(item.item_name)}" class="bistro-thumb">
                        <div class="bistro-details">
                            <div class="bistro-header">
                                <h5 class="bistro-title">${escapeHtml(item.item_name)}</h5>
                                <span class="bistro-dots"></span>
                                <span class="bistro-price">${price}</span>
                            </div>
                            <p class="bistro-desc">${desc}</p>
                            ${badgesHtml}
                        </div>
                        <button class="add-to-cart-btn" data-id="${escapeHtml(item.id)}"
                            data-name="${escapeHtml(item.item_name)}"
                            data-price="${escapeHtml(activePrice)}"
                            data-image="${escapeHtml(image)}" title="Add to Cart">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>`;
                }

                if (MENU_LAYOUT === 'compact') {
                    return `
                    <div class="food-card-compact">
                        <img src="${escapeHtml(image)}" alt="${escapeHtml(item.item_name)}" class="compact-thumb">
                        <div class="compact-body">
                            <h5 class="compact-title">${escapeHtml(item.item_name)}</h5>
                            <p class="compact-desc">${desc}</p>
                            ${badgesHtml}
                            <div class="compact-foot">
                                <div class="compact-price">${price}</div>
                                <button class="add-to-cart-btn" data-id="${escapeHtml(item.id)}"
                                    data-name="${escapeHtml(item.item_name)}"
                                    data-price="${escapeHtml(activePrice)}"
                                    data-image="${escapeHtml(image)}" title="Add to Cart">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>`;
                }

                // Default standard grid
                let promoBadge = '';
                if (item.promo_price && item.price > item.promo_price) {
                    const pct = Math.round(((item.price - item.promo_price) / item.price) * 100);
                    promoBadge = `<span class="badge-promo-tag">${pct}% OFF</span>`;
                }

                return `
                <div class="food-card">
                    <div class="food-img-container">
                        <img src="${escapeHtml(image)}" alt="${escapeHtml(item.item_name)}" class="food-img">
                        <span class="food-badge">${escapeHtml(item.main_category)}</span>
                        ${promoBadge}
                    </div>
                    <div class="food-content">
                        <h5 class="food-title">${escapeHtml(item.item_name)}</h5>
                        <p class="food-description">${desc}</p>
                        ${badgesHtml}
                        <div class="food-price">${price}</div>
                        <button class="add-to-cart-btn" data-id="${escapeHtml(item.id)}"
                            data-name="${escapeHtml(item.item_name)}"
                            data-price="${escapeHtml(activePrice)}"
                            data-image="${escapeHtml(image)}">
                            <i class="fas fa-cart-plus"></i>
                        </button>
                    </div>
                </div>`;
            });

            let gridClass = 'menu-grid';
            if (MENU_LAYOUT === 'bistro') gridClass += ' layout-bistro';
            else if (MENU_LAYOUT === 'compact') gridClass += ' layout-compact';

            wrapper.innerHTML = `<div class="${gridClass}">${cards.join('')}</div>`;
        }

        function setActiveTab(category) {
            tabsContainer.querySelectorAll('.category-tab').forEach(function (tab) {
                tab.classList.toggle('active', (tab.dataset.category || '') === category);
            });
        }

        function loadItems(category, search, pushUrl) {
            const apiUrl = BASE_URL + '/ajax/get_items.php?category=' + encodeURIComponent(category) +
                '&search=' + encodeURIComponent(search);

            wrapper.style.opacity = '0.5';

            fetch(apiUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        renderItems(data.items);
                        setActiveTab(category);
                        if (categorySelect) categorySelect.value = category;
                        // Update the URL without reloading/scrolling
                        if (pushUrl) history.pushState({ category: category, search: search }, '', buildUrl(category, search));
                    }
                })
                .catch(function () {
                    // Fallback to normal page load if AJAX fails
                    window.location.href = buildUrl(category, search);
                })
                .finally(function () {
                    wrapper.style.opacity = '1';
                });
        }

        // Tab clicks -> filter in place, no page reload
        tabsContainer.querySelectorAll('.category-tab').forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                loadItems(this.dataset.category || '', searchInput ? searchInput.value.trim() : '', true);
            });
        });

        // Search submit also filters in place (keeps scroll position)
        if (searchForm) {
            searchForm.addEventListener('submit', function (e) {
                e.preventDefault();
                loadItems(categorySelect ? categorySelect.value : '', searchInput ? searchInput.value.trim() : '', true);
            });
        }

        // Browser back/forward support
        window.addEventListener('popstate', function () {
            const params = new URLSearchParams(window.location.search);
            const category = params.get('category') || '';
            const search = params.get('search') || '';
            if (searchInput) searchInput.value = search;
            if (categorySelect) categorySelect.value = category;
            loadItems(category, search, false);
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
