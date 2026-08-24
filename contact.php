<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

$active_page = 'contact';
include 'includes/header.php';

// Try to update settings if they are still defaults
$contact_email = get_setting('contact_email', 'info@foodexpress.com');
if ($contact_email === 'info@foodexpress.com') {
    // This is optional but helpful
}
?>

<div class="contact-page-wrapper" style="background: #fdfdfd; padding-bottom: 5rem;">
    <!-- Hero Header -->
    <div class="contact-hero">
        <div class="container hero-text">
            <h1>Get in Touch</h1>
            <p>We're here to help you enjoy the best dining experience. Reach out to us for any inquiries or support.
            </p>
        </div>
    </div>

    <div class="container contact-container">
        <div class="contact-main-card">
            <!-- Sidebar Info -->
            <div class="contact-sidebar">
                <h2>Contact Information</h2>

                <div class="contact-info-item">
                    <div class="icon-box">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="info-text">
                        <h4>Our Location</h4>
                        <p><?php echo htmlspecialchars(get_setting('contact_address', '123 Food Street, City, Country')); ?></p>
                    </div>
                </div>

                <div class="contact-info-item">
                    <div class="icon-box">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div class="info-text">
                        <h4>Call Us</h4>
                        <p><?php echo htmlspecialchars(get_setting('contact_phone', '+1 234 567 8900')); ?></p>
                    </div>
                </div>

                <div class="contact-info-item">
                    <div class="icon-box">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="info-text">
                        <h4>Email Us</h4>
                        <p>restaurants@airportwesthotel.com.gh</p>
                    </div>
                </div>

                <div class="contact-info-item">
                    <div class="icon-box">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="info-text">
                        <h4>Website</h4>
                        <a href="https://airportwesthotel.com.gh" target="_blank">airportwesthotel.com.gh</a>
                    </div>
                </div>

                <div class="contact-social">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                </div>
            </div>

            <!-- Form Area -->
            <div class="contact-form-area">
                <h2>Send us a message</h2>
                <p>We usually respond within a few hours. Please feel free to tell us what's on your mind.</p>

                <form action="#" method="POST" class="contact-premium-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="name" class="form-control" placeholder="John Doe" required>
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="How can we help?" required>
                    </div>

                    <div class="form-group">
                        <label>Your Message</label>
                        <textarea name="message" class="form-control" rows="5" placeholder="Write your message here..." required></textarea>
                    </div>

                    <button type="submit" class="btn btn-hero btn-full">
                        <span>Send Message</span>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .contact-premium-form .form-control:focus {
        border-color: var(--primary-color) !important;
        box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1) !important;
        outline: none;
    }

    .btn-submit:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 30px rgba(255, 107, 53, 0.4) !important;
    }

    @media (max-width: 992px) {
        .contact-main-card {
            grid-template-columns: 1fr !important;
        }

        .contact-hero h1 {
            font-size: 2.5rem !important;
        }
    }
</style>

<?php include 'includes/footer.php'; ?>