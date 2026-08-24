document.addEventListener('DOMContentLoaded', function() {
    // Mobile Menu Toggle
    const menuToggle = document.querySelector('.menu-toggle');
    const mainNav = document.querySelector('.main-nav');
    
    if (menuToggle && mainNav) {
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation(); // Prevent document click from immediately closing it
            mainNav.classList.toggle('active');
            
            // Toggle icon
            const icon = menuToggle.querySelector('i');
            if (mainNav.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        // Prevent clicks inside the menu from closing it
        mainNav.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
    
    // Close menu when clicking anywhere else
    document.addEventListener('click', function(event) {
        if (mainNav && mainNav.classList.contains('active')) {
            // If click target is NOT the menu toggle (already handled)
            if (!menuToggle.contains(event.target)) {
                mainNav.classList.remove('active');
                const icon = menuToggle.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            }
        }
    });

    // Close menu when clicking a link
    const navLinks = mainNav ? mainNav.querySelectorAll('a') : [];
    navLinks.forEach(link => {
        link.addEventListener('click', () => {
            mainNav.classList.remove('active');
            const icon = menuToggle.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });
    });


    // Hero Carousel
    let heroSlideIndex = 0;
    const heroSlides = document.querySelectorAll('.hero-slide');
    
    if (heroSlides.length > 0) {
        function showHeroSlides() {
            heroSlides.forEach(slide => {
                slide.classList.remove('active');
                // Optional: ensure display property handles fade out
                slide.style.display = "none"; 
            });
            
            heroSlideIndex++;
            if (heroSlideIndex > heroSlides.length) {heroSlideIndex = 1}
            
            const currentSlide = heroSlides[heroSlideIndex - 1];
            currentSlide.style.display = "grid"; // Match CSS grid layout
            // Small delay to allow display change to render before adding active class for opacity transition
            setTimeout(() => {
                currentSlide.classList.add('active');
            }, 10);
            
            setTimeout(showHeroSlides, 5000); // Change image every 5 seconds
        }
        showHeroSlides();
    }


    // Testimonial Carousel
    const track = document.querySelector('.testimonial-track');
    if (track) {
        const slides = Array.from(track.children);
        const dotsNav = document.querySelector('.carousel-dots');
        const dots = Array.from(dotsNav.children);
        
        // Arrange slides next to each other
        // Update: CSS flex handles this, we just need to translate the track
        
        let currentTestimonialIndex = 0;
        
        function updateTestimonial(index) {
            const amountToMove = -100 * index; 
            track.style.transform = `translateX(${amountToMove}%)`;
            
            // Update dots
            dots.forEach(dot => dot.classList.remove('active'));
            dots[index].classList.add('active');
            
            currentTestimonialIndex = index;
        }

        // Auto play for testimonials
        function autoPlayTestimonials() {
            let nextIndex = currentTestimonialIndex + 1;
            if (nextIndex >= slides.length) {
                nextIndex = 0;
            }
            updateTestimonial(nextIndex);
        }
        
        let testimonialInterval = setInterval(autoPlayTestimonials, 5000);
        
        // Dot click handlers
        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                 clearInterval(testimonialInterval); // Stop auto-play on interaction
                 updateTestimonial(index);
                 testimonialInterval = setInterval(autoPlayTestimonials, 5000); // Restart
            });
        });

        // Make functions global if needed for onclick in HTML (though we attached event listeners above)
        window.currentSlide = function(n) {
             clearInterval(testimonialInterval);
             // Logic to find index based on 1-based "n" if using onclick="currentSlide(1)"
             updateTestimonial(n - 1); 
             testimonialInterval = setInterval(autoPlayTestimonials, 5000);
        };
    }

});
