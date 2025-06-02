<?php
/**
 * Template for displaying occasion details
 */

// Get car data from URL path
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
preg_match('#^/occasions/([^/]+)/?$#', $path, $matches);
$car_id = $matches[1] ?? '';

if (!$car_id) {
    header('Location: /occasions/');
    exit;
}

// Get XML data
$xml = get_xml_data();
if (!$xml) {
    header('Location: /occasions/');
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

    $current_id = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-'));
    if ($current_id === $car_id) {
        $car_data = extract_car_data($car, get_image_base_url());
        break;
    }
}

if (!$car_data) {
    header('Location: /occasions/');
    exit;
}
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($car_data['titel'] ?: ($car_data['merk'] . ' ' . $car_data['model'])); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="/styling.css">
</head>
<body>

<div class="occasion-detail-container">
    <div class="occasion-header">
        <div class="occasion-title">
            <h1><?php echo htmlspecialchars($car_data['titel'] ?: ($car_data['merk'] . ' ' . $car_data['model'])); ?></h1>
            <div class="car-status <?php echo htmlspecialchars($car_data['status']); ?>">
                <?php echo strtoupper(htmlspecialchars($car_data['status'])); ?>
            </div>
        </div>
        <div class="occasion-price">
            € <?php echo number_format((float)str_replace(',', '.', preg_replace('/[^0-9,]/i', '', $car_data['prijs'])), 0, ',', '.'); ?>
        </div>
    </div>

    <div class="occasion-gallery">
        <div class="gallery-main">
            <div class="gallery-slides">
                <?php foreach ($car_data['afbeeldingen'] as $index => $image): ?>
                    <div class="gallery-slide<?php echo $index === 0 ? ' active' : ''; ?>">
                        <img src="<?php echo htmlspecialchars($image); ?>"
                             alt="<?php echo htmlspecialchars($car_data['merk'] . ' ' . $car_data['model']); ?> - Image <?php echo $index + 1; ?>"
                             loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>"
                             decoding="async">
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="gallery-prev" onclick="prevSlide()">❮</button>
            <button class="gallery-next" onclick="nextSlide()">❯</button>
            <div class="gallery-counter">1 / <?php echo count($car_data['afbeeldingen']); ?></div>
        </div>
        <div class="gallery-thumbnails">
            <?php foreach ($car_data['afbeeldingen'] as $index => $image): ?>
                <div class="gallery-thumbnail<?php echo $index === 0 ? ' active' : ''; ?>"
                     onclick="goToSlide(<?php echo $index; ?>)">
                    <img src="<?php echo htmlspecialchars($image); ?>"
                         alt="Thumbnail <?php echo $index + 1; ?>"
                         loading="lazy"
                         decoding="async">
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="occasion-content">
        <div class="occasion-specs">
            <h2>Specificaties</h2>
            <div class="specs-grid">
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-calendar"></i> Bouwjaar</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['bouwjaar']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-tachometer-alt"></i> Kilometerstand</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['kilometerstand']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-gas-pump"></i> Brandstof</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['brandstof']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-cog"></i> Transmissie</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['transmissie']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-bolt"></i> Vermogen</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['vermogen']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-compress-arrows-alt"></i> Cilinder Inhoud</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['cilinder_inhoud']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-car-side"></i> Carrosserie</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['carrosserie']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-door-open"></i> Aantal Deuren</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['deuren']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-chair"></i> Aantal Zitplaatsen</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['aantal_zitplaatsen']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-palette"></i> Kleur</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['kleur']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-paint-brush"></i> Interieur Kleur</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['interieurkleur']); ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label"><i class="fas fa-couch"></i> Bekleding</span>
                    <span class="spec-value"><?php echo htmlspecialchars($car_data['bekleding']); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($car_data['opmerkingen'])): ?>
            <div class="occasion-description">
                <h2>Beschrijving</h2>
                <div class="description-content">
                    <?php echo htmlspecialchars($car_data['opmerkingen']); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="occasion-actions">
        <button class="action-btn share-btn" onclick="shareCar()">
            <i class="fas fa-share-alt"></i> Delen
        </button>
        <button class="action-btn contact-btn" onclick="contactDealer()">
            <i class="fas fa-envelope"></i> Contact Dealer
        </button>
    </div>
</div>

<script src="/script.js"></script>
</body>
</html>