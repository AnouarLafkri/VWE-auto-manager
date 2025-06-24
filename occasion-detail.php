<?php
/**
 * Template for displaying occasion details
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get car ID from query var
$car_id = get_query_var('occasion');

if (!$car_id) {
    wp_redirect(home_url('/auto/'));
    exit;
}

// Get XML data
$xml = get_xml_data();
if (!$xml) {
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

    $current_id = sanitize_title($title);
    if ($current_id === $car_id) {
        $car_data = extract_car_data($car, get_image_base_url());
        break;
    }
}

if (!$car_data) {
    wp_redirect(home_url('/auto/'));
    exit;
}

// SEO: dynamische title, description, canonical, structured data
$car_title = esc_html($car_data['titel'] ?: ($car_data['merk'] . ' ' . $car_data['model']));
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
<style>
body {
    background: #f5f6f8;
}
.occasion-container {
    background: #fff !important;
    margin: 24px auto !important;
    border-radius: 8px;
    box-shadow: none !important;
    padding: 18px 16px 16px 16px;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0;
}
.car-title-bar {
    margin: 0 0 10px 0 !important;
    padding: 0;
}
.car-title {
    font-size: 2rem !important;
    font-weight: 700;
    margin: 0 0 10px 0 !important;
    color: #222;
    letter-spacing: -0.5px;
}
.carousel-wrapper {
    margin: 0 0 12px 0 !important;
    max-width: 100%;
    border-radius: 6px;
    box-shadow: none;
}
.detail-wrapper {
    display: grid;
    grid-template-columns: 1fr 260px;
    gap: 16px;
    background: none;
    box-shadow: none;
    border-radius: 0;
    margin: 0;
    padding: 0;
    max-width: 100%;
}
.sidebar {
    padding: 0;
    margin: 0;
    background: none;
    box-shadow: none;
}
.price-box, .quick-specs {
    background: #f7f7fa;
    border-radius: 6px;
    box-shadow: none;
    padding: 12px 10px;
    margin-bottom: 10px !important;
}
.tabs-container {
    background: none;
    box-shadow: none;
    border-radius: 0;
    margin: 0;
    padding: 0;
    max-width: 100%;
}
.tabs {
    margin-bottom: 6px;
    gap: 2px;
}
.tab-content {
    padding: 12px 0 0 0 !important;
    background: none;
    box-shadow: none;
}
@media (max-width: 900px) {
    .occasion-container {
        max-width: 99vw !important;
        padding: 8px 2vw 8px 2vw;
    }
    .detail-wrapper {
        grid-template-columns: 1fr;
        gap: 8px;
    }
}
@media (max-width: 600px) {
    .car-title {
        font-size: 1.2rem !important;
    }
    .carousel-wrapper {
        margin-bottom: 6px !important;
    }
    .price-box, .quick-specs {
        padding: 8px 4px;
    }
}
</style>

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
    <div class="car-title-bar">
        <h1 class="car-title"><?php echo $car_title; ?></h1>
    </div>
    <div class="detail-wrapper">
        <div class="occasion-carousel">
            <div class="carousel" id="carCarousel">
                <?php foreach ($car_data['afbeeldingen'] as $i => $img) : ?>
                    <div class="carousel-slide<?php echo $i === 0 ? ' active' : ''; ?>">
                        <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($car_title . ' foto ' . ($i+1)); ?>" loading="lazy">
                    </div>
                <?php endforeach; ?>
                <button class="carousel-arrow left" id="carouselPrev" aria-label="Vorige foto">&#10094;</button>
                <button class="carousel-arrow right" id="carouselNext" aria-label="Volgende foto">&#10095;</button>
                <div class="carousel-dots" id="carouselDots">
                    <?php foreach ($car_data['afbeeldingen'] as $i => $img) : ?>
                        <button class="carousel-dot<?php echo $i === 0 ? ' active' : ''; ?>" data-slide="<?php echo $i; ?>" aria-label="Ga naar foto <?php echo $i+1; ?>"></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const slides = document.querySelectorAll('.carousel-slide');
            const dots = document.querySelectorAll('.carousel-dot');
            const prevBtn = document.getElementById('carouselPrev');
            const nextBtn = document.getElementById('carouselNext');
            let current = 0;
            let startX = 0;
            let isDragging = false;
            function showSlide(idx) {
                slides.forEach((slide, i) => {
                    slide.classList.toggle('active', i === idx);
                });
                dots.forEach((dot, i) => {
                    dot.classList.toggle('active', i === idx);
                });
                current = idx;
            }
            if (prevBtn && nextBtn) {
                prevBtn.addEventListener('click', () => {
                    showSlide((current - 1 + slides.length) % slides.length);
                });
                nextBtn.addEventListener('click', () => {
                    showSlide((current + 1) % slides.length);
                });
            }
            dots.forEach((dot, i) => {
                dot.addEventListener('click', () => showSlide(i));
            });
            // Touch/swipe support
            const carousel = document.getElementById('carCarousel');
            if (carousel) {
                carousel.addEventListener('touchstart', e => {
                    isDragging = true;
                    startX = e.touches[0].clientX;
                });
                carousel.addEventListener('touchmove', e => {
                    if (!isDragging) return;
                    let diff = e.touches[0].clientX - startX;
                    if (Math.abs(diff) > 50) {
                        if (diff > 0) {
                            prevBtn.click();
                        } else {
                            nextBtn.click();
                        }
                        isDragging = false;
                    }
                });
                carousel.addEventListener('touchend', () => {
                    isDragging = false;
                });
            }
        });
        </script>
        <aside class="sidebar">
            <div class="car-header">
                <h1><?php echo esc_html($car_data['titel'] ?: ($car_data['merk'] . ' ' . $car_data['model'])); ?></h1>
                <div class="car-status <?php echo esc_attr($car_data['status']); ?>">
                    <?php echo strtoupper(esc_html($car_data['status'])); ?>
                </div>
            </div>


            <div class="quick-specs">
                <div class="spec-item">
                    <i class="fas fa-calendar"></i>
                    <span class="label">Bouwjaar</span>
                    <span class="value"><?php echo esc_html($car_data['bouwjaar']); ?></span>
                </div>
                <div class="spec-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="label">Kilometerstand</span>
                    <span class="value"><?php echo esc_html($car_data['kilometerstand']); ?></span>
                </div>
                <div class="spec-item">
                    <i class="fas fa-gas-pump"></i>
                    <span class="label">Brandstof</span>
                    <span class="value"><?php echo esc_html($car_data['brandstof']); ?></span>
                </div>
                <div class="spec-item">
                    <i class="fas fa-cog"></i>
                    <span class="label">Transmissie</span>
                    <span class="value"><?php echo esc_html($car_data['transmissie']); ?></span>
                </div>
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
            <button class="tab-btn active" data-tab="info">Informatie</button>
        </div>
        <div class="tab-content active" id="info">
            <table class="info-table">
                <tbody>
                    <tr><th>Merk</th><td><?php echo esc_html($car_data['merk']); ?></td></tr>
                    <tr><th>Model</th><td><?php echo esc_html($car_data['model']); ?></td></tr>
                    <tr><th>Bouwjaar</th><td><?php echo esc_html($car_data['bouwjaar']); ?></td></tr>
                    <tr><th>Kilometerstand</th><td><?php echo esc_html($car_data['kilometerstand']); ?></td></tr>
                    <tr><th>Brandstof</th><td><?php echo esc_html($car_data['brandstof']); ?></td></tr>
                    <tr><th>Transmissie</th><td><?php echo esc_html($car_data['transmissie']); ?></td></tr>
                    <tr><th>Prijs</th><td>€ <?php echo esc_html($car_data['prijs']); ?></td></tr>
                    <tr><th>Kleur</th><td><?php echo esc_html($car_data['kleur']); ?></td></tr>
                    <tr><th>Deuren</th><td><?php echo esc_html($car_data['deuren']); ?></td></tr>
                    <tr><th>Vermogen</th><td><?php echo esc_html($car_data['vermogen']); ?></td></tr>
                    <tr><th>Cilinderinhoud</th><td><?php echo esc_html($car_data['cilinder_inhoud']); ?></td></tr>
                    <tr><th>Gewicht</th><td><?php echo esc_html($car_data['gewicht']); ?></td></tr>
                    <tr><th>APK</th><td><?php echo esc_html($car_data['apk']); ?></td></tr>
                    <tr><th>Opties</th><td><?php echo esc_html($car_data['opties'] ?? '-'); ?></td></tr>
                    <tr><th>Opmerkingen</th><td><?php echo esc_html($car_data['opmerkingen']); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.occasion-carousel {
    width: 100%;
    max-width: 2000px;
    height: 1100px;
    margin: 0 auto 18px auto;
    position: relative;
    background: #fff;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.carousel {
    width: 100%;
    height: 100%;
    position: relative;
}
.carousel-slide {
    display: none;
    width: 100%;
    height: 100%;
    position: absolute;
    top: 0; left: 0;
    transition: opacity 0.4s;
    opacity: 0;
    z-index: 1;
}
.carousel-slide.active {
    display: block;
    opacity: 1;
    z-index: 2;
}
.carousel-slide img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    user-select: none;
    pointer-events: none;
}
.carousel-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: #222;
    color: #fff;
    border: none;
    border-radius: 50%;
    width: 38px;
    height: 38px;
    font-size: 22px;
    cursor: pointer;
    z-index: 10;
    opacity: 0.85;
}
.carousel-arrow.left { left: 12px; }
.carousel-arrow.right { right: 12px; }
.carousel-arrow:hover { background: #0077cc; }
.carousel-dots {
    position: absolute;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 8px;
    z-index: 10;
}
.carousel-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #e0e0e0;
    border: none;
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
    outline: none;
}
.carousel-dot.active {
    background: #0077cc;
    transform: scale(1.2);
}
.occasion-tabs {
    width: 100%;
    margin: 0 auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: none;
    padding: 0;
}
.tabs {
    display: flex;
    border-bottom: 1px solid #e0e0e0;
    margin-bottom: 0;
}
.tab-btn {
    flex: 1;
    padding: 14px 0;
    border: none;
    background: none;
    font-size: 1rem;
    font-weight: 600;
    color: #555;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: border 0.2s, color 0.2s;
}
.tab-btn.active {
    color: #0077cc;
    border-bottom: 2px solid #0077cc;
    background: #f7f7fa;
}
.tab-content {
    padding: 18px 0 0 0;
}
.info-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 0;
}
.info-table th, .info-table td {
    text-align: left;
    padding: 14px 16px;
    font-size: 1.05rem;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
}
.info-table th {
    color: #1a237e;
    font-weight: 700;
    background: #f5f7fa;
    width: 200px;
    letter-spacing: 0.01em;
}
.info-table tr:nth-child(even) td {
    background: #fafbfc;
}
.info-table tr:last-child th, .info-table tr:last-child td {
    border-bottom: none;
}
.info-table tr:hover td {
    background: #e3f2fd;
    transition: background 0.2s;
}
@media (max-width: 700px) {
    .info-table, .info-table tbody, .info-table tr, .info-table th, .info-table td {
        display: block;
        width: 100%;
    }
    .info-table tr {
        margin-bottom: 12px;
        border-radius: 6px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.03);
        background: #fff;
    }
    .info-table th {
        background: #f5f7fa;
        color: #1a237e;
        font-size: 1rem;
        padding: 10px 12px 2px 12px;
        border-bottom: none;
        border-radius: 6px 6px 0 0;
    }
    .info-table td {
        padding: 2px 12px 10px 12px;
        font-size: 1.05rem;
        background: #fafbfc;
        border-bottom: none;
        border-radius: 0 0 6px 6px;
    }
    .info-table tr:nth-child(even) td {
        background: #f5f7fa;
    }
}
</style>

<?php get_footer(); ?>
