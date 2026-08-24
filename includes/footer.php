</main>

<!-- Footer -->
<footer class="main-footer" id="contact">
    <div class="container">
        <div class="footer-content">
            <!-- Company Info -->
            <div class="footer-section">
                <h3>Company</h3>
                <a href="index.php" class="logo footer-logo">
                    <i class="fas fa-utensils"></i> <span>Food</span><span>Express</span>
                </a>
                <p>Delivering delicious meals since 2024. Quality food, fast delivery, and excellent service straight to
                    your door.</p>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>

            <!-- Opening Hours -->
            <div class="footer-section">
                <h3>Opening</h3>
                <h5 class="opening-day">Monday - Saturday</h5>
                <p>09AM - 09PM</p>
                <h5 class="opening-day">Sunday</h5>
                <p>10AM - 08PM</p>
            </div>

            <!-- Quick Links -->
            <div class="footer-section footer-links">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="menu.php">Menu</a></li>
                    <li><a href="cart.php">Cart</a></li>
                    <li><a href="contact.php">Contact</a></li>
                </ul>
            </div>

            <!-- Newsletter/Contact -->
            <div class="footer-section">
                <h3>Contact</h3>
                <p><i class="fas fa-map-marker-alt"></i> 123 Street, Accra, Ghana</p>
                <p><i class="fas fa-phone-alt"></i> +233 244 123 456</p>
                <p><i class="fas fa-envelope"></i> info@example.com</p>

                <div class="newsletter-form">
                    <form action="#">
                        <div style="position: relative;">
                            <input type="text" placeholder="Your email">
                            <button type="button">SignUp</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> FoodExpress. All Rights Reserved.</p>
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