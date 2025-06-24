// Gallery functionality
document.addEventListener('DOMContentLoaded', function() {
    const gallery = document.querySelector('.gallery');
    if (!gallery) return;

    const mainImage = gallery.querySelector('.gallery-main img');
    const thumbnails = gallery.querySelectorAll('.thumbnail');
    let currentIndex = 0;

    // Update main image
    function updateMainImage(index) {
        const newSrc = thumbnails[index].querySelector('img').src;
        mainImage.src = newSrc;

        // Update active thumbnail
        thumbnails.forEach(thumb => thumb.classList.remove('active'));
        thumbnails[index].classList.add('active');

        currentIndex = index;
    }

    // Thumbnail click handler
    thumbnails.forEach((thumb, index) => {
        thumb.addEventListener('click', () => updateMainImage(index));
    });

    // Tab functionality
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-tab');

            // Update active tab button
            tabButtons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');

            // Show target content
            tabContents.forEach(content => {
                content.classList.remove('active');
                if (content.getAttribute('data-tab') === target) {
                    content.classList.add('active');
                }
            });
        });
    });

    // Share functionality
    const shareButton = document.querySelector('.share-button');
    if (shareButton) {
        shareButton.addEventListener('click', async () => {
            try {
                await navigator.share({
                    title: document.querySelector('.car-title').textContent,
                    url: window.location.href
                });
            } catch (err) {
                console.log('Error sharing:', err);
            }
        });
    }

    // Contact button functionality
    const contactButton = document.querySelector('.contact-button');
    if (contactButton) {
        contactButton.addEventListener('click', () => {
            // Scroll to contact form or open contact modal
            const contactForm = document.querySelector('#contact-form');
            if (contactForm) {
                contactForm.scrollIntoView({ behavior: 'smooth' });
            }
        });
    }
});