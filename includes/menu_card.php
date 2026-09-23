<?php
/**
 * RestaurantOS — Modular Menu Card Component
 *
 * Supports 3 layout styles:
 *  - 'grid'    : Standard modern card with hero cover photo and floating cart button
 *  - 'bistro'  : Elegant horizontal row with compact thumbnail and dotted leader line
 *  - 'compact' : Space-efficient fast-food card with specs
 *
 * Premium gating:
 *  - Layout switching and dietary badges are gated by 'menu_card_styles' feature.
 *  - If tenant plan does not have 'menu_card_styles', gracefully falls back to 'grid'.
 */

if (!isset($item)) return;

$can_custom_cards = function_exists('feature_enabled') && feature_enabled('menu_card_styles');
$card_layout      = $can_custom_cards ? get_setting('menu_card_layout', 'grid') : 'grid';
$show_dietary     = $can_custom_cards ? (get_setting('menu_show_dietary_badges', '1') === '1') : false;
$show_calories    = $can_custom_cards ? (get_setting('menu_show_calories', '1') === '1') : false;

$image_src = !empty($item['image_url']) ? htmlspecialchars($item['image_url']) : BASE_URL . '/assets/images/food-placeholder.jpg';
$has_promo = !empty($item['promo_price']) && (float)$item['promo_price'] < (float)$item['price'];
$effective_price = $has_promo ? $item['promo_price'] : $item['price'];

// Discount percentage calculation
$discount_pct = 0;
if ($has_promo && (float)$item['price'] > 0) {
    $discount_pct = (int) round((((float)$item['price'] - (float)$item['promo_price']) / (float)$item['price']) * 100);
}
?>

<?php if ($card_layout === 'bistro'): ?>
    <!-- ================= 2. BISTRO LIST CARD ================= -->
    <div class="food-card food-card-bistro" data-category="<?= htmlspecialchars($item['main_category'] ?? '') ?>">
        <div class="bistro-thumb">
            <img src="<?= $image_src ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" loading="lazy">
        </div>
        <div class="bistro-content">
            <div class="bistro-header">
                <h5 class="bistro-title" title="<?= htmlspecialchars($item['item_name']) ?>">
                    <?= htmlspecialchars($item['item_name']) ?>
                </h5>
                <span class="bistro-leader"></span>
                <span class="bistro-price">
                    <?php if ($has_promo): ?>
                        <span class="original-price"><?= format_currency($item['price']) ?></span>
                        <?= format_currency($item['promo_price']) ?>
                    <?php else: ?>
                        <?= format_currency($item['price']) ?>
                    <?php endif; ?>
                </span>
            </div>

            <?php if (!empty($item['description'])): ?>
                <p class="bistro-description"><?= htmlspecialchars($item['description']) ?></p>
            <?php endif; ?>

            <div class="bistro-action-bar">
                <div class="dietary-badges-row">
                    <?php if ($show_dietary && !empty($item['is_vegetarian'])): ?>
                        <span class="badge-diet veg" title="Vegetarian"><i class="fas fa-leaf"></i> Veg</span>
                    <?php endif; ?>
                    <?php if ($show_dietary && !empty($item['is_spicy'])): ?>
                        <span class="badge-diet spicy" title="Spicy"><i class="fas fa-pepper-hot"></i> Hot</span>
                    <?php endif; ?>
                    <?php if ($discount_pct > 0): ?>
                        <span class="badge-promo-tag"><?= $discount_pct ?>% OFF</span>
                    <?php endif; ?>
                    <?php if ($show_calories && !empty($item['calories'])): ?>
                        <span class="badge-meta"><?= (int)$item['calories'] ?> kcal</span>
                    <?php endif; ?>
                </div>

                <button class="btn-bistro-add add-to-cart-btn"
                    data-id="<?= $item['id'] ?>"
                    data-name="<?= htmlspecialchars($item['item_name']) ?>"
                    data-price="<?= $effective_price ?>"
                    data-image="<?= $image_src ?>"
                    aria-label="Add <?= htmlspecialchars($item['item_name']) ?> to order">
                    <i class="fas fa-plus"></i> Add
                </button>
            </div>
        </div>
    </div>

<?php elseif ($card_layout === 'compact'): ?>
    <!-- ================= 3. COMPACT FAST-ORDER CARD ================= -->
    <div class="food-card food-card-compact" data-category="<?= htmlspecialchars($item['main_category'] ?? '') ?>">
        <div class="compact-img-wrap">
            <img src="<?= $image_src ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" loading="lazy">
            <span class="food-badge"><?= htmlspecialchars($item['main_category'] ?? '') ?></span>
            <?php if ($discount_pct > 0): ?>
                <span class="badge-promo-tag" style="position:absolute; top:10px; right:10px; z-index:2;"><?= $discount_pct ?>% OFF</span>
            <?php endif; ?>
        </div>
        <div class="compact-body">
            <h5 class="compact-title"><?= htmlspecialchars($item['item_name']) ?></h5>
            
            <div class="dietary-badges-row" style="margin: 0.15rem 0 0.35rem;">
                <?php if ($show_dietary && !empty($item['is_vegetarian'])): ?>
                    <span class="badge-diet veg"><i class="fas fa-leaf"></i></span>
                <?php endif; ?>
                <?php if ($show_dietary && !empty($item['is_spicy'])): ?>
                    <span class="badge-diet spicy"><i class="fas fa-pepper-hot"></i></span>
                <?php endif; ?>
                <?php if ($show_calories && !empty($item['calories'])): ?>
                    <span class="badge-meta"><?= (int)$item['calories'] ?> kcal</span>
                <?php endif; ?>
                <?php if (!empty($item['cooking_time'])): ?>
                    <span class="badge-meta"><i class="far fa-clock"></i> <?= (int)$item['cooking_time'] ?>m</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($item['description'])): ?>
                <p class="compact-desc"><?= htmlspecialchars($item['description']) ?></p>
            <?php endif; ?>

            <div class="compact-foot">
                <div class="compact-price">
                    <?php if ($has_promo): ?>
                        <span class="original-price" style="font-size:0.82rem; text-decoration:line-through; color:#94a3b8;"><?= format_currency($item['price']) ?></span>
                        <?= format_currency($item['promo_price']) ?>
                    <?php else: ?>
                        <?= format_currency($item['price']) ?>
                    <?php endif; ?>
                </div>

                <button class="add-to-cart-btn"
                    data-id="<?= $item['id'] ?>"
                    data-name="<?= htmlspecialchars($item['item_name']) ?>"
                    data-price="<?= $effective_price ?>"
                    data-image="<?= $image_src ?>"
                    aria-label="Add to cart">
                    <i class="fas fa-cart-plus"></i>
                </button>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ================= 1. MODERN GRID CARD (STANDARD) ================= -->
    <div class="food-card" data-category="<?= htmlspecialchars($item['main_category'] ?? '') ?>">
        <div class="food-img-container">
            <img src="<?= $image_src ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" class="food-img" loading="lazy">
            <span class="food-badge"><?= htmlspecialchars($item['main_category'] ?? '') ?></span>
            <?php if ($discount_pct > 0): ?>
                <span class="badge-promo-tag" style="position:absolute; top:12px; right:12px; z-index:2;"><?= $discount_pct ?>% OFF</span>
            <?php endif; ?>
        </div>
        <div class="food-content">
            <h5 class="food-title"><?= htmlspecialchars($item['item_name']) ?></h5>

            <?php if ($show_dietary || $show_calories): ?>
                <div class="dietary-badges-row" style="justify-content: center;">
                    <?php if ($show_dietary && !empty($item['is_vegetarian'])): ?>
                        <span class="badge-diet veg" title="Vegetarian"><i class="fas fa-leaf"></i> Vegetarian</span>
                    <?php endif; ?>
                    <?php if ($show_dietary && !empty($item['is_spicy'])): ?>
                        <span class="badge-diet spicy" title="Spicy"><i class="fas fa-pepper-hot"></i> Spicy</span>
                    <?php endif; ?>
                    <?php if ($show_calories && !empty($item['calories'])): ?>
                        <span class="badge-meta"><?= (int)$item['calories'] ?> kcal</span>
                    <?php endif; ?>
                    <?php if (!empty($item['cooking_time'])): ?>
                        <span class="badge-meta"><i class="far fa-clock"></i> <?= (int)$item['cooking_time'] ?>m</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="food-description">
                <?php
                $desc = $item['description'] ?? '';
                echo strlen($desc) > 65 ? substr(htmlspecialchars($desc), 0, 65) . '...' : htmlspecialchars($desc);
                ?>
            </p>
            <div class="food-price">
                <?php if ($has_promo): ?>
                    <span class="original-price"><?= format_currency($item['price']) ?></span>
                    <span class="promo-price"><?= format_currency($item['promo_price']) ?></span>
                <?php else: ?>
                    <?= format_currency($item['price']) ?>
                <?php endif; ?>
            </div>
            <button class="add-to-cart-btn"
                data-id="<?= $item['id'] ?>"
                data-name="<?= htmlspecialchars($item['item_name']) ?>"
                data-price="<?= $effective_price ?>"
                data-image="<?= $image_src ?>"
                aria-label="Add to cart">
                <i class="fas fa-cart-plus"></i>
            </button>
        </div>
    </div>
<?php endif; ?>
