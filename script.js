jQuery(document).ready(function($) {
    // Initialize gallery
    let currentSlide = 0;
    const slides = document.querySelectorAll('.gallery-slide');
    const thumbnails = document.querySelectorAll('.gallery-thumbnail');
    const counter = document.querySelector('.gallery-counter');
    const totalSlides = slides.length;

    function updateGallery() {
        slides.forEach((slide, index) => {
            slide.classList.toggle('active', index === currentSlide);
        });

        thumbnails.forEach((thumb, index) => {
            thumb.classList.toggle('active', index === currentSlide);
        });

        if (counter) {
            counter.textContent = `${currentSlide + 1} / ${totalSlides}`;
        }
    }

    // Initialize first slide
    if (slides.length > 0) {
        updateGallery();
    }

    // Handle prev/next buttons
    document.querySelector('.gallery-prev')?.addEventListener('click', () => {
        currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
        updateGallery();
    });

    document.querySelector('.gallery-next')?.addEventListener('click', () => {
        currentSlide = (currentSlide + 1) % totalSlides;
        updateGallery();
    });

    // Handle thumbnail clicks
    thumbnails.forEach((thumb, index) => {
        thumb.addEventListener('click', () => {
            currentSlide = index;
            updateGallery();
        });
    });

    // Handle share button click
    $('.share-btn').on('click', function() {
        const shareData = {
            title: document.title,
            text: 'Bekijk deze auto op onze website!',
            url: window.location.href
        };

        if (navigator.share) {
            navigator.share(shareData)
                .then(() => showNotification('Succesvol gedeeld!'))
                .catch((error) => {
                    console.error('Error sharing:', error);
                    fallbackShare();
                });
        } else {
            fallbackShare();
        }
    });

    function fallbackShare() {
        const dummy = document.createElement('input');
        document.body.appendChild(dummy);
        dummy.value = window.location.href;
        dummy.select();
        document.execCommand('copy');
        document.body.removeChild(dummy);
        showNotification('Link gekopieerd naar klembord!');
    }

    function showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'notification';
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.classList.add('show');
        }, 100);

        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 3000);
    }

    // Handle contact button click
    $('.contact-btn').on('click', function() {
        const subject = 'Informatie over ' + document.title;
        window.location.href = 'mailto:info@example.com?subject=' + encodeURIComponent(subject);
    });
});