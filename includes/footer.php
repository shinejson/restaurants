<?php
$company_name = htmlspecialchars(get_setting('company_name', 'Restaurant'));
$site_tagline = htmlspecialchars(get_setting('site_tagline', 'Order online, pick up in store or dine in.'));
$site_logo = get_setting('site_logo', '');
$address = htmlspecialchars(get_setting('contact_address', ''));
$phone = htmlspecialchars(get_setting('contact_phone', ''));
$email = htmlspecialchars(get_setting('contact_email', ''));
$opening_mon_sat = htmlspecialchars(get_setting('opening_hours_weekday', '09:00 AM - 09:00 PM'));
$opening_sun = htmlspecialchars(get_setting('opening_hours_weekend', '10:00 AM - 08:00 PM'));
$facebook = htmlspecialchars(get_setting('social_facebook', '#'));
$twitter = htmlspecialchars(get_setting('social_twitter', '#'));
$instagram = htmlspecialchars(get_setting('social_instagram', '#'));
$linkedin = htmlspecialchars(get_setting('social_linkedin', '#'));
?>
</main>

<!-- Footer -->
<footer class="main-footer" id="contact">
    <div class="container">
        <div class="footer-content">
            <!-- 1. Company Info & Brand -->
            <div class="footer-section footer-brand">
                <a href="<?php echo tenant_url(''); ?>" class="footer-logo">
                    <?php if (!empty($site_logo)): ?>
                        <img src="<?php echo htmlspecialchars(BASE_URL . '/' . ltrim($site_logo, '/')); ?>" alt="<?php echo $company_name; ?>" class="footer-logo-img">
                    <?php else: ?>
                        <span class="footer-logo-icon"><i class="fas fa-utensils"></i></span>
                    <?php endif; ?>
                    <span class="footer-brand-title"><?php echo $company_name; ?></span>
                </a>
                <p class="footer-tagline"><?php echo $site_tagline; ?></p>
                <div class="social-links">
                    <a href="<?php echo $facebook; ?>" title="Facebook" aria-label="Facebook" target="_blank" rel="noopener"><i class="fab fa-facebook-f"></i></a>
                    <a href="<?php echo $twitter; ?>" title="Twitter" aria-label="Twitter" target="_blank" rel="noopener"><i class="fab fa-twitter"></i></a>
                    <a href="<?php echo $instagram; ?>" title="Instagram" aria-label="Instagram" target="_blank" rel="noopener"><i class="fab fa-instagram"></i></a>
                    <a href="<?php echo $linkedin; ?>" title="LinkedIn" aria-label="LinkedIn" target="_blank" rel="noopener"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>

            <!-- 2. Opening Hours -->
            <div class="footer-section footer-hours">
                <h3>Opening Hours</h3>
                <div class="opening-card">
                    <div class="opening-item">
                        <span class="day">Monday - Saturday</span>
                        <span class="time"><?php echo $opening_mon_sat; ?></span>
                    </div>
                    <div class="opening-item">
                        <span class="day">Sunday</span>
                        <span class="time"><?php echo $opening_sun; ?></span>
                    </div>
                </div>
                <div class="service-pill">
                    <span class="service-dot"></span> Dine-In &bull; Takeaway &bull; Delivery
                </div>
            </div>

            <!-- 3. Quick Links -->
            <div class="footer-section footer-links">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="<?php echo tenant_url(''); ?>"><i class="fas fa-angle-right"></i> Home</a></li>
                    <li><a href="<?php echo tenant_url('menu.php'); ?>"><i class="fas fa-angle-right"></i> Our Menu</a></li>
                    <li><a href="<?php echo tenant_url('events.php'); ?>"><i class="fas fa-angle-right"></i> Events & Catering</a></li>
                    <li><a href="<?php echo tenant_url('cart.php'); ?>"><i class="fas fa-angle-right"></i> Shopping Cart</a></li>
                    <li><a href="<?php echo tenant_url('contact.php'); ?>"><i class="fas fa-angle-right"></i> Contact Us</a></li>
                    <li><a href="<?php echo tenant_url('admin/login.php'); ?>"><i class="fas fa-shield-alt"></i> Staff Portal</a></li>
                </ul>
            </div>

            <!-- 4. Contact & Newsletter -->
            <div class="footer-section footer-contact">
                <h3>Contact Info</h3>
                <div class="contact-list">
                    <?php if (!empty($address)): ?>
                        <div class="contact-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?php echo $address; ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($phone)): ?>
                        <a href="tel:<?php echo preg_replace('/[^\d+]/', '', $phone); ?>" class="contact-item">
                            <i class="fas fa-phone-alt"></i>
                            <span><?php echo $phone; ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($email)): ?>
                        <a href="mailto:<?php echo $email; ?>" class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo $email; ?></span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="newsletter-wrap">
                    <form class="newsletter-form" onsubmit="event.preventDefault(); alert('Thank you for subscribing!');">
                        <div class="newsletter-box">
                            <input type="email" placeholder="Your email address" required aria-label="Email address">
                            <button type="submit">Subscribe</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> <strong><?php echo $company_name; ?></strong>. All Rights Reserved.</p>
            <div class="footer-bottom-links">
                <a href="<?php echo tenant_url('contact.php'); ?>">Support</a>
                <?php
                $hideWatermark = function_exists('feature_enabled') && feature_enabled('white_label') && (get_setting('white_label_enabled', '0') === '1');
                if (!$hideWatermark):
                ?>
                <span class="sep">&bull;</span>
                <span class="powered-badge">Powered by <span>RestaurantOS</span></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>

<!-- Back to Top -->
<a href="#" class="back-to-top"><i class="fas fa-arrow-up"></i></a>

<!-- JavaScript -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/cart.js"></script>
<script>
    // Initialize cart functionality
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof initCart === 'function') {
            initCart();
        }

        // Back to Top Button
        const backToTop = document.querySelector('.back-to-top');
        if (backToTop) {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    backToTop.classList.add('show');
                } else {
                    backToTop.classList.remove('show');
                }
            });

            backToTop.addEventListener('click', (e) => {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    });
</script>
</body>

</html>