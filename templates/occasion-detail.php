<?php
/**
 * Template for displaying occasion details
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Get car ID from query var
$car_id = get_query_var('car_id');

if (!$car_id) {
    wp_redirect(home_url('/occasions/'));
    exit;
}

// Get XML data
$xml = get_xml_data();
if (!$xml) {
    wp_redirect(home_url('/occasions/'));
    exit;
}

// Find the matching car
$car_data = null;
foreach ($xml->voertuig as $car) {
    $current_id = sanitize_title($car->merk . '-' . $car->model . '-' . $car->kenteken);
    if ($current_id === $car_id) {
        $car_data = extract_car_data($car, get_image_base_url());
        break;
    }
}

if (!$car_data) {
    wp_redirect(home_url('/occasions/'));
    exit;
}
?>

<div class="occasion-detail-wrapper">
    <div class="occasion-detail-content">
        <div class="occasion-gallery">
            <div class="carousel-container">
                <div class="carousel-slides">
                    <?php foreach ($car_data['afbeeldingen'] as $index => $image): ?>
                        <div class="carousel-slide">
                            <img src="<?php echo esc_url($image); ?>"
                                 alt="<?php echo esc_attr($car_data['merk'] . ' ' . $car_data['model']); ?> - Image <?php echo $index + 1; ?>"
                                 loading="lazy"
                                 decoding="async">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="carousel-prev" onclick="prevSlide()">❮</button>
                <button class="carousel-next" onclick="nextSlide()">❯</button>
                <div class="carousel-counter">1 / <?php echo count($car_data['afbeeldingen']); ?></div>
            </div>
            <div class="carousel-dots">
                <?php foreach ($car_data['afbeeldingen'] as $index => $image): ?>
                    <div class="carousel-dot<?php echo $index === 0 ? ' active' : ''; ?>"
                         onclick="goToSlide(<?php echo $index; ?>)"></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="occasion-details">
            <div class="occasion-header">
                <h1><?php echo esc_html($car_data['titel'] ?: ($car_data['merk'] . ' ' . $car_data['model'])); ?></h1>
                <div class="car-status <?php echo esc_attr($car_data['status']); ?>">
                    <?php echo strtoupper(esc_html($car_data['status'])); ?>
                </div>
                <div class="price-tag">
                    € <?php echo number_format((float)str_replace(',', '.', preg_replace('/[^0-9,]/i', '', $car_data['prijs'])), 0, ',', '.'); ?>
                </div>
            </div>

            <div class="occasion-specs">
                <div class="specs-section">
                    <h2>Belangrijke Specificaties</h2>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-calendar"></i> Bouwjaar</span>
                            <span class="spec-value"><?php echo esc_html($car_data['bouwjaar']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-tachometer-alt"></i> Kilometerstand</span>
                            <span class="spec-value"><?php echo esc_html($car_data['kilometerstand']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-gas-pump"></i> Brandstof</span>
                            <span class="spec-value"><?php echo esc_html($car_data['brandstof']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-cog"></i> Transmissie</span>
                            <span class="spec-value"><?php echo esc_html($car_data['transmissie']); ?></span>
                        </div>
                    </div>
                </div>

                <div class="specs-section">
                    <h2>Technische Details</h2>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-bolt"></i> Vermogen</span>
                            <span class="spec-value"><?php echo esc_html($car_data['vermogen']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-compress-arrows-alt"></i> Cilinder Inhoud</span>
                            <span class="spec-value"><?php echo esc_html($car_data['cilinder_inhoud']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-cogs"></i> Aantal Cilinders</span>
                            <span class="spec-value"><?php echo esc_html($car_data['cilinders']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-weight"></i> Gewicht</span>
                            <span class="spec-value"><?php echo esc_html($car_data['gewicht']); ?></span>
                        </div>
                    </div>
                </div>

                <div class="specs-section">
                    <h2>Voertuig Kenmerken</h2>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-car-side"></i> Carrosserie</span>
                            <span class="spec-value"><?php echo esc_html($car_data['carrosserie']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-door-open"></i> Aantal Deuren</span>
                            <span class="spec-value"><?php echo esc_html($car_data['deuren']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-chair"></i> Aantal Zitplaatsen</span>
                            <span class="spec-value"><?php echo esc_html($car_data['aantal_zitplaatsen']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-palette"></i> Kleur</span>
                            <span class="spec-value"><?php echo esc_html($car_data['kleur']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-paint-brush"></i> Interieur Kleur</span>
                            <span class="spec-value"><?php echo esc_html($car_data['interieurkleur']); ?></span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-couch"></i> Bekleding</span>
                            <span class="spec-value"><?php echo esc_html($car_data['bekleding']); ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($car_data['opmerkingen'])): ?>
                    <div class="specs-section">
                        <h2>Beschrijving</h2>
                        <div class="description-content">
                            <?php echo wp_kses_post($car_data['opmerkingen']); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    window.currentSlide = 0;
    window.totalSlides = <?php echo count($car_data['afbeeldingen']); ?>;
});

function updateCarousel() {
    const slides = document.querySelector(".carousel-slides");
    if (!slides || !window.totalSlides) return;
    slides.style.transform = "translateX(-" + (window.currentSlide * 100) + "%)";
    const dots = document.querySelectorAll(".carousel-dot");
    dots.forEach((dot, index) => {
        dot.classList.toggle("active", index === window.currentSlide);
    });
    updateCarouselCounter();
}

function nextSlide() {
    if (!window.totalSlides) return;
    window.currentSlide = (window.currentSlide + 1) % window.totalSlides;
    updateCarousel();
}

function prevSlide() {
    if (!window.totalSlides) return;
    window.currentSlide = (window.currentSlide - 1 + window.totalSlides) % window.totalSlides;
    updateCarousel();
}

function goToSlide(index) {
    if (!window.totalSlides) return;
    window.currentSlide = index;
    updateCarousel();
}

function updateCarouselCounter() {
    const counter = document.querySelector(".carousel-counter");
    if (counter && window.totalSlides) {
        counter.textContent = `${window.currentSlide + 1} / ${window.totalSlides}`;
    }
}
</script>

<?php get_footer(); ?>