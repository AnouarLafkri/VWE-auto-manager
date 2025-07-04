<?php
// @phpstan-ignore-file
/**
 * Template for displaying occasion details
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Suppress linter errors for WordPress functions
// @phpstan-ignore-next-line
$car_id = get_query_var('occasion');

if (!$car_id) {
    // @phpstan-ignore-next-line
    wp_redirect(home_url('/auto/'));
    exit;
}

// Get XML data
$xml = get_xml_data();
if (!$xml) {
    // @phpstan-ignore-next-line
    wp_redirect(home_url('/auto/'));
    exit;
}

// Find the matching car
$car_data = null;
foreach ($xml->voertuig as $car) {
    $title = (string)$car->merk . ' ' . (string)$car->model;
    if ((string)$car->cilinder_inhoud) $title .= ' ' . (string)$car->cilinder_inhoud;
    if ((string)$car->transmissie) $title .= ' ' . (string)$car->transmissie;
    if ((string)$car->brandstof) $title .= ' ' . (string)$car->brandstof;
    if ((string)$car->aantal_deuren) $title .= ' ' . (string)$car->aantal_deuren . ' Deurs';
    $title .= ' NL Auto';

    // @phpstan-ignore-next-line
    $current_id = sanitize_title($title);
    if ($current_id === $car_id) {
        $car_data = extract_car_data($car, get_image_base_url());
        break;
    }
}

if (!$car_data) {
    // @phpstan-ignore-next-line
    wp_redirect(home_url('/auto/'));
    exit;
}

// SEO: dynamische title, description, canonical, structured data
// @phpstan-ignore-next-line
$car_title = esc_html($car_data['titel'] ?: ($car_data['merk'] . ' ' . $car_data['model']));
// @phpstan-ignore-next-line
$car_desc = esc_html($car_data['merk'] . ' ' . $car_data['model'] . ' uit ' . $car_data['bouwjaar'] . ' met ' . $car_data['kilometerstand'] . ' km, ' . $car_data['brandstof'] . ', ' . $car_data['transmissie'] . '. Prijs: € ' . $car_data['prijs']);
$car_img = !empty($car_data['afbeeldingen'][0]) ? esc_url($car_data['afbeeldingen'][0]) : '';
$car_url = home_url('/auto/' . $car_id . '/');
$canonical = $car_url;
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Vehicle',
    'name' => $car_title,
    'description' => $car_desc,
    'image' => $car_img,
    'url' => $car_url,
    'brand' => [
        '@type' => 'Brand',
        'name' => $car_data['merk']
    ],
    'model' => $car_data['model'],
    'modelDate' => $car_data['bouwjaar'],
    'mileageFromOdometer' => [
        '@type' => 'QuantitativeValue',
        'value' => preg_replace('/[^0-9]/', '', $car_data['kilometerstand']),
        'unitCode' => 'SMI'
    ],
    'fuelType' => $car_data['brandstof'],
    'transmission' => $car_data['transmissie'],
    'numberOfDoors' => $car_data['deuren'],
    'color' => $car_data['kleur'],
    'vehicleEngine' => [
        '@type' => 'EngineSpecification',
        'engineDisplacement' => [
            '@type' => 'QuantitativeValue',
            'value' => preg_replace('/[^0-9]/', '', $car_data['cilinder_inhoud']),
            'unitCode' => 'CMQ'
        ]
    ],
    'offers' => [
        '@type' => 'Offer',
        'price' => preg_replace('/[^0-9]/', '', $car_data['prijs']),
        'priceCurrency' => 'EUR',
        'availability' => $car_data['status'] === 'beschikbaar' ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'
    ]
];

get_header();
?>

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo VWE_PLUGIN_URL . 'templates/occasion-detail.css?v=' . filemtime(plugin_dir_path(__FILE__) . 'occasion-detail.css'); ?>">

<title><?php echo $car_title; ?> | <?php bloginfo('name'); ?></title>
<meta name="description" content="<?php echo $car_desc; ?>" />
<link rel="canonical" href="<?php echo esc_url($canonical); ?>" />
<meta property="og:title" content="<?php echo $car_title; ?>" />
<meta property="og:description" content="<?php echo $car_desc; ?>" />
<meta property="og:image" content="<?php echo $car_img; ?>" />
<meta property="og:url" content="<?php echo $car_url; ?>" />
<meta property="og:type" content="website" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?php echo $car_title; ?>" />
<meta name="twitter:description" content="<?php echo $car_desc; ?>" />
<meta name="twitter:image" content="<?php echo $car_img; ?>" />
<script type="application/ld+json">
<?php echo json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); ?>
</script>

<div class="occasion-container">
    <div class="top-nav">
        <a href="<?php echo esc_url(home_url('/occasions/')); ?>" class="back-link">&#8592; Terug naar overzicht</a>
    </div>
    <div class="detail-header">
        <div class="title-block">
            <h1 class="car-title"><?php echo esc_html($car_title); ?></h1>
            <?php if (!empty($car_data['titel'])) : ?>
                <p class="car-subtitle"><?php echo esc_html($car_data['titel']); ?></p>
            <?php endif; ?>
        </div>
        <div>
            € <?php echo number_format((float)str_replace(',', '.', preg_replace('/[^0-9,]/', '', $car_data['prijs'])), 0, ',', '.'); ?>,-
        </div>
    </div>
    <div class="detail-wrapper">
        <div class="occasion-carousel">
            <div class="carousel" id="carCarousel">
                <?php foreach ($car_data['afbeeldingen'] as $i => $img) : ?>
                    <div class="carousel-slide<?php echo $i === 0 ? ' active' : ''; ?>">
                        <img src="<?php echo esc_url($img); ?>"
                             alt="<?php echo esc_attr($car_title . ' foto ' . ($i+1)); ?>"
                             loading="lazy"
                             class="clickable-image"
                             data-image-index="<?php echo $i; ?>">
                    </div>
                <?php endforeach; ?>
                <button class="carousel-arrow left" id="carouselPrev" aria-label="Vorige foto">&#10094;</button>
                <button class="carousel-arrow right" id="carouselNext" aria-label="Volgende foto">&#10095;</button>
            </div>
        </div>
        <aside class="sidebar">
            <div class="overview-box">
                <h3 class="overview-title">Overzicht:</h3>
                <table class="overview-table">
                    <tbody>
                        <tr><td>Merk</td><td><?php echo esc_html($car_data['merk']); ?></td></tr>
                        <tr><td>Model</td><td><?php echo esc_html($car_data['model']); ?></td></tr>
                        <tr><td>Kilometerstand</td><td><?php echo esc_html($car_data['kilometerstand']); ?></td></tr>
                        <tr><td>Carrosserie</td><td><?php echo esc_html($car_data['carrosserie']); ?></td></tr>
                        <tr><td>Brandstof</td><td><?php echo esc_html($car_data['brandstof']); ?></td></tr>
                        <tr><td>Bouwjaar</td><td><?php echo esc_html($car_data['bouwjaar']); ?></td></tr>
                        <tr><td>Kleur</td><td><?php echo esc_html($car_data['kleur']); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="cta-buttons">
                <a href="tel:<?php echo esc_attr(get_theme_mod('contact_phone', '')); ?>" class="cta-btn phone">
                    <i class="fas fa-phone"></i>
                    Bel ons
                </a>
                <a href="mailto:<?php echo esc_attr(get_theme_mod('contact_email', '')); ?>" class="cta-btn email">
                    <i class="fas fa-envelope"></i>
                    Stuur e-mail
                </a>
                <button onclick="shareCar()" class="cta-btn share">
                    <i class="fas fa-share-alt"></i>
                    Delen
                </button>
            </div>
        </aside>
    </div>
    <div class="tabs-container">
        <div class="tabs">
            <button class="tab-btn active" data-tab="beschrijving">Beschrijving</button>
            <button class="tab-btn" data-tab="specificaties">Specificaties</button>
            <button class="tab-btn" data-tab="fotos">Foto's</button>
        </div>

        <!-- Beschrijving Tab -->
        <div class="tab-content active" id="beschrijving">
            <div class="description-text">
                <?php echo wp_kses_post(nl2br($car_data['opmerkingen'])); ?>
            </div>
        </div>

        <!-- Specificaties Tab -->
        <div class="tab-content" id="specificaties">
            <table class="spec-table">
                <tbody>
                    <tr><td>Kenteken</td><td><?php echo esc_html($car_data['kenteken'] ?? '-'); ?></td><td>Aantal zitplaatsen</td><td><?php echo esc_html($car_data['aantal_zitplaatsen'] ?? '-'); ?></td></tr>
                    <tr><td>Carrosserie</td><td><?php echo esc_html($car_data['carrosserie'] ?? '-'); ?></td><td>Bekleding</td><td><?php echo esc_html($car_data['bekleding'] ?? '-'); ?></td></tr>
                    <tr><td>Kleur</td><td><?php echo esc_html($car_data['kleur']); ?></td><td>Interieurkleur</td><td><?php echo esc_html($car_data['interieurkleur'] ?? '-'); ?></td></tr>
                    <tr><td>Tellerstand</td><td><?php echo esc_html($car_data['kilometerstand']); ?></td><td>Cilinder aantal</td><td><?php echo esc_html($car_data['cilinders'] ?? '-'); ?></td></tr>
                    <tr><td>Bouwjaar</td><td><?php echo esc_html($car_data['bouwjaar']); ?></td><td>Cilinder inhoud</td><td><?php echo esc_html($car_data['cilinder_inhoud']); ?></td></tr>
                    <tr><td>Vermogen</td><td><?php echo esc_html($car_data['vermogen']); ?></td><td>Massa</td><td><?php echo esc_html($car_data['gewicht']); ?></td></tr>
                    <tr><td>Brandstof</td><td><?php echo esc_html($car_data['brandstof']); ?></td><td>Transmissie</td><td><?php echo esc_html($car_data['transmissie']); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Foto's Tab -->
        <div class="tab-content" id="fotos">
            <div class="photo-grid">
                <?php foreach ($car_data['afbeeldingen'] as $i => $img) : ?>
                    <div class="photo-item">
                        <img src="<?php echo esc_url($img); ?>"
                             alt="<?php echo esc_attr($car_title); ?>"
                             loading="lazy"
                             class="clickable-image"
                             data-image-index="<?php echo $i; ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Store images array globally for lightbox
const lightboxImages = <?php echo json_encode($car_data['afbeeldingen']); ?>;
let currentLightboxIndex = 0;

// Share car function
function shareCar() {
    try {
        if (navigator.share) {
            navigator.share({
                title: '<?php echo esc_js($car_title); ?>',
                text: 'Bekijk deze occasion: <?php echo esc_js($car_title); ?>',
                url: window.location.href
            }).catch(error => console.log('Error sharing:', error));
        } else {
            // Fallback: copy URL to clipboard
            navigator.clipboard.writeText(window.location.href).then(() => {
                alert('Link gekopieerd naar klembord!');
            }).catch(() => {
                // Fallback for older browsers
                const dummy = document.createElement('input');
                document.body.appendChild(dummy);
                dummy.value = window.location.href;
                dummy.select();
                document.execCommand('copy');
                document.body.removeChild(dummy);
                alert('Link gekopieerd naar klembord!');
            });
        }
    } catch (error) {
        console.error('Error sharing car:', error);
        alert('Er is een fout opgetreden bij het delen.');
    }
}

// Main initialization
document.addEventListener('DOMContentLoaded', function() {
    // Carousel functionality
    const slides = document.querySelectorAll('.carousel-slide');
    const prevBtn = document.getElementById('carouselPrev');
    const nextBtn = document.getElementById('carouselNext');
    const carousel = document.getElementById('carCarousel');

    let current = 0;
    let startX = 0;
    let isDragging = false;
    let autoPlayInterval = null;
    let isAutoPlaying = true;

    // Add counter to carousel
    const counter = document.createElement('div');
    counter.className = 'carousel-counter';
    counter.innerHTML = `<span id="currentSlide">1</span> / <span id="totalSlides">${slides.length}</span>`;
    carousel.appendChild(counter);

    function showSlide(idx) {
        if (slides.length === 0) return;

        // Remove active class from all slides
        slides.forEach((slide, i) => {
            slide.classList.toggle('active', i === idx);
            slide.style.zIndex = i === idx ? '2' : '1';
        });

        // Update counter
        const currentSlideElement = document.getElementById('currentSlide');
        if (currentSlideElement) {
            currentSlideElement.textContent = idx + 1;
        }

        current = idx;

        // Reset auto-play timer
        if (isAutoPlaying) {
            resetAutoPlay();
        }
    }

    function nextSlide() {
        showSlide((current + 1) % slides.length);
    }

    function prevSlide() {
        showSlide((current - 1 + slides.length) % slides.length);
    }

    function resetAutoPlay() {
        if (autoPlayInterval) {
            clearInterval(autoPlayInterval);
        }
        if (isAutoPlaying && slides.length > 1) {
            autoPlayInterval = setInterval(nextSlide, 5000); // 5 seconds
        }
    }

    function toggleAutoPlay() {
        isAutoPlaying = !isAutoPlaying;
        if (isAutoPlaying) {
            resetAutoPlay();
        } else if (autoPlayInterval) {
            clearInterval(autoPlayInterval);
        }
    }

    // Event listeners for navigation buttons
    if (prevBtn && nextBtn) {
        prevBtn.addEventListener('click', (e) => {
            e.preventDefault();
            prevSlide();
        });

        nextBtn.addEventListener('click', (e) => {
            e.preventDefault();
            nextSlide();
        });
    }

    // Improved touch/swipe support
    if (carousel) {
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;

        carousel.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
            touchStartY = e.changedTouches[0].screenY;
            isDragging = true;

            // Pause auto-play on touch
            if (autoPlayInterval) {
                clearInterval(autoPlayInterval);
            }
        }, { passive: true });

        carousel.addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            e.preventDefault();
        }, { passive: false });

        carousel.addEventListener('touchend', (e) => {
            if (!isDragging) return;

            touchEndX = e.changedTouches[0].screenX;
            touchEndY = e.changedTouches[0].screenY;

            const diffX = touchStartX - touchEndX;
            const diffY = touchStartY - touchEndY;

            // Check if horizontal swipe is more significant than vertical
            if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50) {
                if (diffX > 0) {
                    nextSlide(); // Swipe left = next
                } else {
                    prevSlide(); // Swipe right = previous
                }
            }

            isDragging = false;

            // Resume auto-play
            if (isAutoPlaying) {
                resetAutoPlay();
            }
        }, { passive: true });
    }

    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        switch(e.key) {
            case 'ArrowLeft':
                e.preventDefault();
                prevSlide();
                break;
            case 'ArrowRight':
                e.preventDefault();
                nextSlide();
                break;
            case ' ':
                e.preventDefault();
                toggleAutoPlay();
                break;
        }
    });

    // Pause auto-play on hover
    carousel.addEventListener('mouseenter', () => {
        if (autoPlayInterval) {
            clearInterval(autoPlayInterval);
        }
    });

    carousel.addEventListener('mouseleave', () => {
        if (isAutoPlaying) {
            resetAutoPlay();
        }
    });

    // Initialize auto-play
    if (slides.length > 1) {
        resetAutoPlay();
    }

    // Preload next image for smoother transitions
    function preloadNextImage() {
        const nextIndex = (current + 1) % slides.length;
        const nextSlide = slides[nextIndex];
        const img = nextSlide.querySelector('img');
        if (img && !img.complete) {
            const preloadImg = new Image();
            preloadImg.src = img.src;
        }
    }

    // Preload next image when slide changes
    const originalShowSlide = showSlide;
    showSlide = function(idx) {
        originalShowSlide(idx);
        preloadNextImage();
    };

    // Initial preload
    preloadNextImage();

    // Tab switching
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.getAttribute('data-tab');
            tabButtons.forEach(b => b.classList.toggle('active', b === btn));
            tabContents.forEach(c => c.classList.toggle('active', c.id === target));
        });
    });
});
</script>

<?php get_footer(); ?>
