<?php
// Increase execution time and memory limit
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '256M');

// Configuration
define('FTP_SERVER', '91.184.31.234');
define('FTP_USER', 'anmvs-auto');
define('FTP_PASS', 'f6t23U~8t');
define('REMOTE_IMAGES_PATH', '/staging.mvsautomotive.nl/wp-content/plugins/xml/images/');
define('LOCAL_IMAGES_PATH', __DIR__ . '/images/');
define('LOCAL_XML_PATH', __DIR__ . '/local_file.xml');
define('XML_CACHE_TIME', 86400); // 24 hours
define('ENABLE_XML_CACHE', true);
define('XML_CACHE_FILE', __DIR__ . '/xml_cache.json');
define('DEBUG_MODE', false);
define('LAST_UPDATE_FILE', __DIR__ . '/last_update.txt');
define('UPDATE_INTERVAL', 86400); // 24 hours in seconds

/**
 * Check if update is needed
 */
function needs_update() {
    if (!file_exists(LAST_UPDATE_FILE)) {
        return true;
    }
    $last_update = file_get_contents(LAST_UPDATE_FILE);
    return (time() - (int)$last_update) > UPDATE_INTERVAL;
}

/**
 * Update last update timestamp
 */
function update_timestamp() {
    file_put_contents(LAST_UPDATE_FILE, time());
}

/**
 * Fetch images from FTP server
 */
function fetch_images_from_ftp() {
    if (!is_dir(LOCAL_IMAGES_PATH)) {
        if (!mkdir(LOCAL_IMAGES_PATH, 0777, true)) {
            error_log('Failed to create images directory: ' . LOCAL_IMAGES_PATH);
            return;
        }
    }

    // Set FTP timeout
    $timeout = 30;
    $is_ssl = false;

    // Try SSL connection first
    if (function_exists('ftp_ssl_connect')) {
        $ftp_conn = @ftp_ssl_connect(FTP_SERVER, 21, $timeout);
        if ($ftp_conn) {
            $is_ssl = true;
        }
    }

    // Fallback to regular FTP if SSL fails
    if (!$ftp_conn) {
        $ftp_conn = @ftp_connect(FTP_SERVER, 21, $timeout);
    }

    if (!$ftp_conn) {
        error_log('Could not connect to FTP server: ' . FTP_SERVER);
        return;
    }

    // Set additional FTP options
    ftp_set_option($ftp_conn, FTP_TIMEOUT_SEC, $timeout);
    ftp_set_option($ftp_conn, FTP_AUTOSEEK, true);

    if (!@ftp_login($ftp_conn, FTP_USER, FTP_PASS)) {
        if ($is_ssl) {
            @ftp_close($ftp_conn);
        } else {
        ftp_close($ftp_conn);
        }
        error_log('FTP login failed for user: ' . FTP_USER);
        return;
    }

    ftp_pasv($ftp_conn, true);

    // Enable SSL if available
    if ($is_ssl) {
        ftp_set_option($ftp_conn, FTP_USEPASVADDRESS, true);
    }

    $files = @ftp_nlist($ftp_conn, REMOTE_IMAGES_PATH);
    if ($files === false) {
        if ($is_ssl) {
            @ftp_close($ftp_conn);
        } else {
        ftp_close($ftp_conn);
        }
        error_log('Could not retrieve file list from FTP path: ' . REMOTE_IMAGES_PATH);
        return;
    }

    foreach ($files as $file) {
        $remote_file = REMOTE_IMAGES_PATH . basename($file);
        $local_file = LOCAL_IMAGES_PATH . basename($file);

        if (!file_exists($local_file)) {
            if (!@ftp_get($ftp_conn, $local_file, $remote_file, FTP_BINARY)) {
                error_log('Failed to download file: ' . $remote_file);
            }
        }
    }

    // Properly close the connection based on connection type
    if ($is_ssl) {
        @ftp_close($ftp_conn);
    } else {
    ftp_close($ftp_conn);
    }
}

/**
 * Clean up unused images
 */
function cleanup_unused_images() {
    if (!file_exists(LOCAL_XML_PATH)) {
        error_log('XML file not found');
        return;
    }

    $xml_content = file_get_contents(LOCAL_XML_PATH);
    if (!$xml_content) {
        error_log('Could not read XML file');
        return;
    }

    $xml = new SimpleXMLElement($xml_content);
    if (!$xml) {
        error_log('Error parsing XML content');
        return;
    }

    // Collect used images from XML
    $usedImages = [];
    foreach ($xml->voertuig as $car) {
        if (isset($car->afbeeldingen)) {
            foreach ($car->afbeeldingen->children() as $afbeelding) {
                if ($afbeelding->getName() === 'afbeelding') {
                    if (isset($afbeelding->bestandsnaam)) {
                        $filename = trim((string)$afbeelding->bestandsnaam);
                        if ($filename !== '') {
                            $usedImages[] = $filename;
                        }
                    }
                }
            }
        }
    }

    // Remove unused files
    if (!is_dir(LOCAL_IMAGES_PATH)) {
        error_log('Images directory not found');
        return;
    }

    $allLocalImages = glob(LOCAL_IMAGES_PATH . '*.{jpg,jpeg,png,gif,JPG,JPEG,PNG,GIF}', GLOB_BRACE);
    if ($allLocalImages === false) {
        error_log('Error reading images directory');
        return;
    }

    foreach ($allLocalImages as $filePath) {
        if (!is_file($filePath)) {
            continue;
        }
        $fileName = basename($filePath);
        if (!in_array($fileName, $usedImages, true)) {
            if (!unlink($filePath)) {
                error_log("Failed to delete unused file: $filePath");
            }
        }
    }
}

/**
 * Display car listing with improved performance
 */
function display_car_listing() {
    $xml = get_cached_xml();
    if (!$xml) {
        echo "<div class='error-message'>Error loading XML data. Please check the error logs for more information.</div>";
        return;
    }

    // Convert SimpleXMLElement to array
    $cars = [];
    foreach ($xml->voertuig as $car) {
        $cars[] = $car;
    }

    $total_items = count($cars);
    if ($total_items === 0) {
        echo "<div class='error-message'>No cars found in the XML data.</div>";
        return;
    }

    $image_url_base = get_image_base_url();
    output_css_styles();

    // Add preloading for images
    output_image_preload($cars);

    echo '<div class="page-wrapper">';

    // Add top controls
    echo '<div class="top-controls">
        <div class="results-count">' . $total_items . ' USED RESULT FOUND</div>
        <div class="view-controls">
            <div class="sort-dropdown">
                <select id="sortSelect">
                    <option value="default">Sort By Default</option>
                    <option value="price-asc">Price: Low to High</option>
                    <option value="price-desc">Price: High to Low</option>
                    <option value="year-desc">Year: Newest First</option>
                    <option value="year-asc">Year: Oldest First</option>
                    <option value="km-asc">Kilometers: Low to High</option>
                    <option value="km-desc">Kilometers: High to Low</option>
                </select>
            </div>
            <div class="show-dropdown">
                <select id="showSelect">
                    <option value="all" selected>Show All</option>
                    <option value="50">Show 50</option>
                    <option value="100">Show 100</option>
                </select>
            </div>
            <button class="view-toggle" id="viewToggle">
                <svg viewBox="0 0 24 24" width="24" height="24">
                    <path fill="currentColor" d="M4 5h16v2H4V5zm0 6h16v2H4v-2zm0 6h16v2H4v-2z"/>
                </svg>
            </button>
        </div>
    </div>';

    echo '<div class="main-content">';

    // Filters panel
    echo '<aside class="filters-panel">
        <div class="filters-header">
            <h2>FILTERS & SORTS</h2>
        </div>
        <div class="filters-body">
            <div class="filter-group">
                <label>MERK</label>
                <div class="custom-select">
                    <select id="brandFilter">
                        <option value="">Alle Merken</option>';

    // Add brand options dynamically
    $brands = [];
    foreach ($cars as $car) {
        $brand = (string)$car->merk;
        if (!in_array($brand, $brands)) {
            $brands[] = $brand;
            echo '<option value="' . htmlspecialchars($brand) . '">' . htmlspecialchars($brand) . '</option>';
        }
    }

    echo '</select>
                </div>
            </div>
            <div class="filter-group">
                <label>MODEL</label>
                <div class="custom-select">
                    <select id="modelFilter">
                        <option value="">Alle Modellen</option>
                    </select>
                </div>
            </div>
            <div class="filter-group">
                <label>BRANDSTOF</label>
                <div class="custom-select">
                    <select id="fuelFilter">
                        <option value="">Alle Brandstof</option>
                        <option value="Benzine">Benzine</option>
                        <option value="Diesel">Diesel</option>
                        <option value="Elektrisch">Elektrisch</option>
                        <option value="Hybride">Hybride</option>
                    </select>
                </div>
            </div>
            <div class="filter-group">
                <label>BOUWJAAR</label>
                <div class="checkbox-group">
                    <label class="custom-checkbox">
                        <input type="checkbox" name="year" value="all" checked>
                        <span class="checkmark"></span>
                        <span>Alle Bouwjaren</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="year" value="2020-2024">
                        <span class="checkmark"></span>
                        <span>2020 - 2024</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="year" value="2015-2019">
                        <span class="checkmark"></span>
                        <span>2015 - 2019</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="year" value="2010-2014">
                        <span class="checkmark"></span>
                        <span>2010 - 2014</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="year" value="2005-2009">
                        <span class="checkmark"></span>
                        <span>2005 - 2009</span>
                    </label>
                </div>
            </div>
            <div class="filter-group">
                <label>PRIJSBEREIK</label>
                <div class="range-filter">
                    <div class="range-inputs">
                        <input type="number" id="priceMin" placeholder="Min" min="0" step="1000">
                        <span>-</span>
                        <input type="number" id="priceMax" placeholder="Max" min="0" step="1000">
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label>KILOMETERSTAND</label>
                <div class="range-filter">
                    <div class="range-inputs">
                        <input type="number" id="kmMin" placeholder="Min" min="0" step="10000">
                        <span>-</span>
                        <input type="number" id="kmMax" placeholder="Max" min="0" step="10000">
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label>TRANSMISSIE</label>
                <div class="custom-select">
                    <select id="transmissionFilter">
                        <option value="">Alle Transmissies</option>
                        <option value="Automatisch">Automatisch</option>
                        <option value="Handgeschakeld">Handgeschakeld</option>
                    </select>
                </div>
            </div>
            <div class="filter-group">
                <label>CARROSSERIE</label>
                <div class="custom-select">
                    <select id="bodyFilter">
                        <option value="">Alle carrosserieën</option>';

    // Add body type options dynamically
    $body_types = [];
    foreach ($cars as $car) {
        $body_type = (string)$car->carrosserie;
        if (!in_array($body_type, $body_types)) {
            $body_types[] = $body_type;
            echo '<option value="' . htmlspecialchars($body_type) . '">' . htmlspecialchars($body_type) . '</option>';
        }
    }

    echo '</select>
                </div>
            </div>
            <div class="filter-group">
                <label>AANTAL DEUREN</label>
                <div class="custom-select">
                    <select id="doorsFilter">
                        <option value="">Alle</option>
                        <option value="2">2 deuren</option>
                        <option value="3">3 deuren</option>
                        <option value="4">4 deuren</option>
                        <option value="5">5 deuren</option>
                    </select>
                </div>
            </div>
            <div class="filter-group">
                <label>AANTAL ZITPLAATSEN</label>
                <div class="custom-select">
                    <select id="seatsFilter">
                        <option value="">Alle</option>
                        <option value="2">2 zitplaatsen</option>
                        <option value="3">3 zitplaatsen</option>
                        <option value="4">4 zitplaatsen</option>
                        <option value="5">5 zitplaatsen</option>
                        <option value="6">6 zitplaatsen</option>
                        <option value="7">7 zitplaatsen</option>
                    </select>
                </div>
            </div>
            <div class="filter-group">
                <label>VERMOGEN (PK)</label>
                <div class="range-filter">
                    <div class="range-inputs">
                        <input type="number" id="powerMin" placeholder="Min" min="0" step="10">
                        <span>-</span>
                        <input type="number" id="powerMax" placeholder="Max" min="0" step="10">
                    </div>
                </div>
            </div>
            <div class="filter-group">
                <label>STATUS</label>
                <div class="checkbox-group">
                    <label class="custom-checkbox">
                        <input type="checkbox" name="status" value="all" checked>
                        <span class="checkmark"></span>
                        <span>Alle statussen</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="status" value="beschikbaar">
                        <span class="checkmark"></span>
                        <span>Beschikbaar</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="status" value="gereserveerd">
                        <span class="checkmark"></span>
                        <span>Gereserveerd</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="status" value="verkocht">
                        <span class="checkmark"></span>
                        <span>Verkocht</span>
                    </label>
                </div>
            </div>
            <button class="filters-reset" id="resetFilters">FILTERS WISSEN</button>
        </div>
    </aside>';

    // Cars grid
    echo '<div class="cars-grid" id="carsGrid">';

    // Debug information
    if (DEBUG_MODE) {
        echo '<div class="debug-info">';
        echo '<p>Total cars in XML: ' . $total_items . '</p>';
        echo '</div>';
    }

    echo '<div class="description-content">' .
        '<h4>Beschrijving</h4>' .
        '<p>Hier vindt u een overzicht van onze beschikbare auto\'s. Bekijk de details en specificaties van elke auto en neem contact met ons op voor meer informatie.</p>' .
    '</div>';

    foreach ($cars as $car) {
        $car_data = extract_car_data($car, $image_url_base);
        if ($car_data) {
        display_car_card($car_data);
        }
    }
    echo '</div>';

    echo '</div></div>';

    // Add filter functionality script
    output_javascript();

    // Render modals at the end of the page
    render_modals();
}

function generate_options($items) {
    $options = "";
    foreach ($items as $item) {
        if ($item && $item !== "Onbekend") {
            $options .= "<option value=\"" . htmlspecialchars($item) . "\">" . htmlspecialchars($item) . "</option>";
        }
    }
    return $options;
}

/**
 * Helper function to get base URL for images
 */
function get_image_base_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    return rtrim($protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/') . '/images/';
}

/**
 * Extract car data from XML node
 */
function extract_car_data($car, $image_url_base) {
    // Helper function to clean and format values
    $clean_value = function($value, $default = 'Onbekend') {
        if ($value === null || $value === '') {
            return $default;
        }
        $value = trim((string)$value);
        return $value !== '' ? $value : $default;
    };

    // Helper function to get XML value safely
    $get_xml_value = function($node, $path, $default = 'Onbekend') use ($clean_value) {
        if (!isset($node->$path)) {
            return $default;
        }
        return $clean_value($node->$path, $default);
    };

    $data = [
        'merk' => $get_xml_value($car, 'merk', 'Onbekend merk'),
        'model' => $get_xml_value($car, 'model', 'Onbekend model'),
        'bouwjaar' => $get_xml_value($car, 'bouwjaar', 'Onbekend bouwjaar'),
        'prijs' => $get_xml_value($car, 'verkoopprijs_particulier', 'Prijs op aanvraag'),
        'kilometerstand' => $get_xml_value($car, 'tellerstand', '0'),
        'brandstof' => $get_xml_value($car, 'brandstof', 'Onbekend'),
        'kleur' => $get_xml_value($car, 'basiskleur', 'Onbekend'),
        'transmissie' => $get_xml_value($car, 'transmissie', 'Onbekend'),
        'deuren' => $get_xml_value($car, 'aantal_deuren', 'Onbekend'),
        'cilinders' => $get_xml_value($car, 'cilinder_aantal', 'Onbekend'),
        'vermogen' => $get_xml_value($car, 'vermogen_motor_kw', 'Onbekend'),
        'vermogen_pk' => $get_xml_value($car, 'vermogen_motor_pk', 'Onbekend'),
        'kenteken' => $get_xml_value($car, 'kenteken', 'Onbekend'),
        'gewicht' => $get_xml_value($car, 'massa', 'Onbekend'),
        'cilinder_inhoud' => $get_xml_value($car, 'cilinder_inhoud', 'Onbekend'),
        'aantal_zitplaatsen' => $get_xml_value($car, 'aantal_zitplaatsen', 'Onbekend'),
        'interieurkleur' => $get_xml_value($car, 'interieurkleur', 'Onbekend'),
        'bekleding' => $get_xml_value($car, 'bekleding', 'Onbekend'),
        'opmerkingen' => $get_xml_value($car, 'opmerkingen', 'Geen aanvullende opmerkingen beschikbaar.'),
        'afbeeldingen' => [],
        'afleverpakketten' => [],
        'carrosserie' => $get_xml_value($car, 'carrosserie', 'Onbekend')
    ];

    // Map brandstof codes to full names
    $brandstof_map = [
        'B' => 'Benzine',
        'D' => 'Diesel',
        'E' => 'Elektrisch',
        'H' => 'Hybride',
        'L' => 'LPG',
        'P' => 'Plug-in Hybride'
    ];
    if (isset($brandstof_map[$data['brandstof']])) {
        $data['brandstof'] = $brandstof_map[$data['brandstof']];
    }

    // Map transmissie codes to full names
    $transmissie_map = [
        'H' => 'Handgeschakeld',
        'A' => 'Automatisch'
    ];
    if (isset($transmissie_map[$data['transmissie']])) {
        $data['transmissie'] = $transmissie_map[$data['transmissie']];
    }

    // Format values with units
    if (is_numeric($data['kilometerstand'])) {
        $data['kilometerstand'] = number_format($data['kilometerstand'], 0, ',', '.') . ' km';
    }

    if (is_numeric($data['vermogen'])) {
        $data['vermogen'] = $data['vermogen'] . ' kW (' . $data['vermogen_pk'] . ' pk)';
    }

    if (is_numeric($data['gewicht'])) {
        $data['gewicht'] = number_format($data['gewicht'], 0, ',', '.') . ' kg';
    }

    if (is_numeric($data['cilinder_inhoud'])) {
        $data['cilinder_inhoud'] = number_format($data['cilinder_inhoud'], 0, ',', '.') . ' cc';
    }

    // Determine status
    $data['status'] = (string)$car->verkocht === 'j' ? 'verkocht' :
                     ((string)$car->gereserveerd === 'j' ? 'gereserveerd' : 'beschikbaar');

    // Collect images
    if (isset($car->afbeeldingen) && isset($car->afbeeldingen->afbeelding)) {
    foreach ($car->afbeeldingen->afbeelding as $afbeelding) {
            if (isset($afbeelding->bestandsnaam)) {
        $bestandsnaam = (string)$afbeelding->bestandsnaam;
                if ($bestandsnaam !== '') {
        $data['afbeeldingen'][] = $image_url_base . $bestandsnaam;
                }
            }
        }
    }

    $data['eersteAfbeelding'] = empty($data['afbeeldingen']) ?
        $image_url_base . 'placeholder.jpg' : $data['afbeeldingen'][0];

    // Collect packages
    if (isset($car->afleverpakketten) && isset($car->afleverpakketten->afleverpakket)) {
        foreach ($car->afleverpakketten->afleverpakket as $pakket) {
            if (isset($pakket->naam) && isset($pakket->omschrijving) && isset($pakket->prijs_in)) {
            $data['afleverpakketten'][] = [
                'naam' => (string)$pakket->naam,
                'omschrijving' => strip_tags((string)$pakket->omschrijving),
                'prijs' => (string)$pakket->prijs_in
            ];
            }
        }
    }

    return $data;
}

/**
 * Display a single car card
 */
function display_car_card($car) {
    // Ensure proper JSON encoding of the car data
    $jsonData = htmlspecialchars(json_encode($car, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES);

    $status_label = strtoupper($car['status'] === "beschikbaar" ? "AVAILABLE" :
                   ($car['status'] === "verkocht" ? "VERKOCHT" : "GERESERVEERD"));
    $status_class = $car['status'];

    echo '<div class="car-card" data-car=\'' . $jsonData . '\'>
        <div class="car-image">
            <img src="' . $car['eersteAfbeelding'] . '" alt="' . $car['merk'] . ' ' . $car['model'] . '">
            <div class="car-badges">
                <span class="status-badge ' . $status_class . '">' . $status_label . '</span>
                <span class="year-badge">' . $car['bouwjaar'] . '</span>
            </div>
        </div>
        <div class="car-info">
            <div class="car-brand">' . strtoupper($car['merk']) . '</div>
            <h3 class="car-title">' . $car['model'] . '</h3>
            <div class="car-price">€ ' . number_format((float)$car['prijs'], 0, ',', '.') . '</div>
            <div class="car-specs">
                <span>€ ' . number_format(2.065, 3, ',', '.') . ' p/m</span>
                <span>' . $car['kilometerstand'] . '</span>
            </div>
            <button type="button" class="view-button" onclick="showCarDetails(this)">
                BEKIJKEN <span class="arrow">→</span>
            </button>
        </div>
    </div>';
}

/**
 * Output CSS styles
 */
function output_css_styles() {
    $base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    echo '<link rel="stylesheet" href="' . $base_url . '/styling.css?v=' . filemtime(__DIR__ . '/styling.css') . '">';
}

/**
 * Render modal templates
 */
function render_modals() {
    echo '
        <div id="carModal" class="modal">
            <div class="modal-content">
            <button class="modal-close" onclick="closeModal()">&times;</button>
            <div class="modal-carousel">
                <div class="carousel-container">
                    <div class="carousel-slides"></div>
                    <button class="carousel-prev" onclick="prevSlide()">❮</button>
                    <button class="carousel-next" onclick="nextSlide()">❯</button>
                </div>
                <div class="carousel-dots"></div>
            </div>
            <div class="modal-details"></div>
            </div>
    </div>';
}

/**
 * Output JavaScript functionality
 */
function output_javascript() {
    echo '<script>
        let currentSlide = 0;
        let totalSlides = 0;

        function showCarDetails(button) {
            const carCard = button.closest(".car-card");
            if (!carCard) return;

            try {
                const carData = JSON.parse(carCard.dataset.car);
                openModal(carData);
            } catch (e) {
                console.error("Error parsing car data:", e);
            }
        }

        function openModal(carData) {
            const modal = document.getElementById("carModal");
            const carouselSlides = modal.querySelector(".carousel-slides");
            const carouselDots = modal.querySelector(".carousel-dots");
            const details = modal.querySelector(".modal-details");

            // Reset carousel
            carouselSlides.innerHTML = "";
            carouselDots.innerHTML = "";

            // Setup carousel with images
            if (carData.afbeeldingen && carData.afbeeldingen.length > 0) {
                carData.afbeeldingen.forEach((image, index) => {
                    const slide = document.createElement("div");
                    slide.className = "carousel-slide";
                    slide.innerHTML = `<img src="${image}" alt="${carData.merk} ${carData.model} - Image ${index + 1}">`;
                    carouselSlides.appendChild(slide);

                    const dot = document.createElement("div");
                    dot.className = "carousel-dot" + (index === 0 ? " active" : "");
                    dot.onclick = () => goToSlide(index);
                    carouselDots.appendChild(dot);
                });
                totalSlides = carData.afbeeldingen.length;
                currentSlide = 0;
                updateCarousel();
            }

            // Create detailed content
            details.innerHTML = `
                <div class="modal-sections">
                    <div class="modal-section">
                        <h3>${carData.merk} ${carData.model}</h3>
                        <div class="price-tag">€ ${Number(carData.prijs).toLocaleString("nl-NL")}</div>
                        </div>
                    <div class="modal-section">
                        <h4>Belangrijke Specificaties</h4>
                        <div class="specs-grid">
                            <div class="spec-item"><span class="spec-label">Bouwjaar</span><span class="spec-value">${carData.bouwjaar || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Kilometerstand</span><span class="spec-value">${carData.kilometerstand || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Brandstof</span><span class="spec-value">${carData.brandstof || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Transmissie</span><span class="spec-value">${carData.transmissie || "N/A"}</span></div>
                    </div>
                    </div>
                    <div class="modal-section">
                        <h4>Technische Details</h4>
                        <div class="specs-grid">
                            <div class="spec-item"><span class="spec-label">Vermogen</span><span class="spec-value">${carData.vermogen || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Cilinder Inhoud</span><span class="spec-value">${carData.cilinder_inhoud || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Aantal Cilinders</span><span class="spec-value">${carData.cilinders || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Gewicht</span><span class="spec-value">${carData.gewicht || "N/A"}</span></div>
                        </div>
                    </div>
                    <div class="modal-section">
                        <h4>Voertuig Kenmerken</h4>
                        <div class="specs-grid">
                            <div class="spec-item"><span class="spec-label">Carrosserie</span><span class="spec-value">${carData.carrosserie || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Aantal Deuren</span><span class="spec-value">${carData.deuren || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Aantal Zitplaatsen</span><span class="spec-value">${carData.aantal_zitplaatsen || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Kleur</span><span class="spec-value">${carData.kleur || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Interieur Kleur</span><span class="spec-value">${carData.interieurkleur || "N/A"}</span></div>
                            <div class="spec-item"><span class="spec-label">Bekleding</span><span class="spec-value">${carData.bekleding || "N/A"}</span></div>
                        </div>
                    </div>
                    ${carData.opmerkingen ? `
                        <div class="modal-section">
                            <h4>Beschrijving</h4>
                            <div class="description-content">
                                ${carData.opmerkingen}
                            </div>
                        </div>
                    ` : ""}
                    <!-- Delivery Packages -->
                    <div class="delivery-packages">
                        <h4>Afleverpakketten</h4>
                        <div class="packages-grid">
                            <div class="package-card basic">
                                <div class="package-header">
                                    <h5 class="package-title">Basis Pakket</h5>
                                    <div class="package-price">€ 0,-</div>
                                </div>
                                <div class="package-features">
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">APK (minimaal 6 maanden)</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Technische controlebeurt</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Reinigen interieur & exterieur</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Tenaamstellen nieuwe auto</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Vrijwaren inruilauto</span>
                                    </div>
                                </div>
                                <button class="package-select-btn" onclick="selectPackage(1, \'${carData.merk} ${carData.model}\')">
                                    Selecteer Pakket
                                </button>
                            </div>

                            <div class="package-card premium">
                                <div class="package-header">
                                    <h5 class="package-title">Premium Pakket</h5>
                                    <div class="package-price">€ 795,-</div>
                                </div>
                                <div class="package-features">
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">APK (minimaal 6 maanden)</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Onderhoudsbeurt conform fabrieksopgave</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">12 Maanden BOVAG garantie</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Reinigen interieur & exterieur</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Tenaamstellen nieuwe auto</span>
                                    </div>
                                    <div class="package-feature">
                                        <span class="feature-icon">✓</span>
                                        <span class="feature-text">Vrijwaren inruilauto</span>
                                    </div>
                                </div>
                                <button class="package-select-btn" onclick="selectPackage(2, \'${carData.merk} ${carData.model}\')">
                                    Selecteer Pakket
                                </button>
                            </div>
                        </div>
                    </div>
                            </div>
                        `;

            modal.style.display = "block";
            }

        function updateCarousel() {
            const slides = document.querySelector(".carousel-slides");
            if (!slides || totalSlides === 0) return;

            slides.style.transform = "translateX(-" + (currentSlide * 100) + "%)";
            const dots = document.querySelectorAll(".carousel-dot");
            dots.forEach((dot, index) => {
                dot.classList.toggle("active", index === currentSlide);
            });
        }

        function nextSlide() {
            if (totalSlides > 0) {
            currentSlide = (currentSlide + 1) % totalSlides;
            updateCarousel();
            }
        }

        function prevSlide() {
            if (totalSlides > 0) {
            currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
            updateCarousel();
            }
        }

        function goToSlide(index) {
            if (index >= 0 && index < totalSlides) {
            currentSlide = index;
            updateCarousel();
        }
        }

        function closeModal() {
            const modal = document.getElementById("carModal");
            modal.style.display = "none";
        }

        function selectPackage(packageNumber, carName) {
            const packageNames = {
                1: "Basis Pakket",
                2: "Comfort Pakket",
                3: "Premium Pakket"
            };
            const packagePrices = {
                1: "€ 0,-",
                2: "€ 495,-",
                3: "€ 795,-"
            };

            if (confirm("Wilt u het " + packageNames[packageNumber] + " selecteren voor " + carName + " voor " + packagePrices[packageNumber] + "?")) {
                alert("Bedankt voor het selecteren van het " + packageNames[packageNumber] + " voor " + carName + ". Ons team neemt contact met u op voor de volgende stappen.");
            }
        }

        // Make functions globally available
        window.showCarDetails = showCarDetails;
        window.openModal = openModal;
        window.closeModal = closeModal;
        window.nextSlide = nextSlide;
        window.prevSlide = prevSlide;
        window.goToSlide = goToSlide;
        window.selectPackage = selectPackage;

        // Close modal on click outside
        window.addEventListener("click", function(event) {
            const modal = document.getElementById("carModal");
            if (event.target === modal) {
                closeModal();
            }
        });

        // Close modal on Escape key
        window.addEventListener("keydown", function(event) {
            if (event.key === "Escape") {
                closeModal();
            }
        });

        document.addEventListener("DOMContentLoaded", function() {
            const carCards = document.querySelectorAll(".car-card");
            const carsGrid = document.getElementById("carsGrid");
            const resetFilters = document.getElementById("resetFilters");
            const sortSelect = document.getElementById("sortSelect");
            const showSelect = document.getElementById("showSelect");

            // Handle filter events
            const filterElements = [
                "brandFilter", "modelFilter", "fuelFilter",
                "transmissionFilter", "bodyFilter", "doorsFilter",
                "seatsFilter", "priceMin", "priceMax",
                "kmMin", "kmMax", "powerMin", "powerMax"
            ];

            filterElements.forEach(id => {
                document.getElementById(id).addEventListener("input", filterCars);
            });

            document.querySelectorAll("input[name=\'year\']").forEach(cb => {
                cb.addEventListener("change", filterCars);
            });

            document.querySelectorAll("input[name=\'status\']").forEach(cb => {
                cb.addEventListener("change", filterCars);
            });

            resetFilters.addEventListener("click", resetAllFilters);
            sortSelect.addEventListener("change", sortCars);
            showSelect.addEventListener("change", showSelectedCars);

            function filterCars() {
                carCards.forEach(card => {
                    try {
                    const carData = JSON.parse(card.dataset.car);

                        // Collect all matching criteria
                        const criteria = {
                            brandMatch: !brandFilter.value || carData.merk === brandFilter.value,
                            modelMatch: !modelFilter.value || carData.model === modelFilter.value,
                            fuelMatch: !fuelFilter.value || carData.brandstof === fuelFilter.value,
                            transmissionMatch: !transmissionFilter.value || carData.transmissie === transmissionFilter.value,
                            bodyMatch: !bodyFilter.value || carData.carrosserie === bodyFilter.value,
                            doorsMatch: !doorsFilter.value || carData.deuren === doorsFilter.value,
                            seatsMatch: !seatsFilter.value || carData.aantal_zitplaatsen === seatsFilter.value,
                            priceMatch: (isInRange(parseFloat(carData.prijs), priceMin.value, priceMax.value)),
                            kmMatch: (isInRange(parseFloat(carData.kilometerstand), kmMin.value, kmMax.value)),
                            powerMatch: (isInRange(parseFloat(carData.vermogen_pk), powerMin.value, powerMax.value)),
                            yearMatch: getYearMatch(carData.bouwjaar),
                            statusMatch: getStatusMatch(carData.status)
                        };

                        const shouldShow = Object.values(criteria).every(match => match);
                        card.style.display = shouldShow ? "block" : "none";

                    } catch (e) {
                        console.error("Error filtering car:", e);
                        card.style.display = "none";
                    }
                });
                updateResultsCount();
            }

            function isInRange(value, min, max) {
                return (value >= (parseFloat(min) || 0)) && (value <= (parseFloat(max) || Infinity));
            }

            function getYearMatch(year) {
                const selectedYears = Array.from(document.querySelectorAll("input[name=\'year\']:checked"))
                    .map(cb => cb.value);
                return selectedYears.includes("all") || selectedYears.some(range => {
                    if (range === "all") return true;
                    const [start, end] = range.split("-").map(Number);
                    return year >= start && year <= end;
                });
            }

            function getStatusMatch(carStatus) {
                const selectedStatuses = Array.from(document.querySelectorAll("input[name=\'status\']:checked"))
                    .map(cb => cb.value);
                return selectedStatuses.includes("all") || selectedStatuses.includes(carStatus);
            }

            function updateResultsCount() {
                const visibleCards = document.querySelectorAll(".car-card[style*=\'display: block\']").length;
                const resultsCount = document.querySelector(".results-count");
                if (resultsCount) {
                    resultsCount.textContent = `${visibleCards} USED RESULT${visibleCards !== 1 ? "S" : ""} FOUND`;
                }
            }

            function sortCars() {
                const sortValue = sortSelect.value;
                const cards = Array.from(carCards);

                cards.sort((a, b) => {
                    const carA = JSON.parse(a.dataset.car);
                    const carB = JSON.parse(b.dataset.car);
                    return compareCars(carA, carB, sortValue);
                });

                cards.forEach(card => carsGrid.appendChild(card));
            }

            function compareCars(carA, carB, criteria) {
                switch (criteria) {
                        case "price-asc":
                            return parseFloat(carA.prijs) - parseFloat(carB.prijs);
                        case "price-desc":
                            return parseFloat(carB.prijs) - parseFloat(carA.prijs);
                        case "year-desc":
                            return parseInt(carB.bouwjaar) - parseInt(carA.bouwjaar);
                        case "year-asc":
                            return parseInt(carA.bouwjaar) - parseInt(carB.bouwjaar);
                        case "km-asc":
                            return parseFloat(carA.kilometerstand) - parseFloat(carB.kilometerstand);
                        case "km-desc":
                            return parseFloat(carB.kilometerstand) - parseFloat(carA.kilometerstand);
                        default:
                            return 0;
                    }
            }

            function showSelectedCars() {
                const showValue = parseInt(showSelect.value);
                carCards.forEach((card, index) => {
                    card.style.display = (showValue === 0 || index < showValue) ? "block" : "none";
                });
                updateResultsCount();
            }

            function resetAllFilters() {
                filterElements.forEach(id => document.getElementById(id).value = "");
                document.querySelectorAll("input[name=\'year\'], input[name=\'status\']").forEach(cb => cb.checked = cb.value === "all");
                sortSelect.value = "default";
                showSelect.value = "all";
                filterCars();
            }

            // Initialize
            updateModelOptions();
            filterCars();
        });
    </script>';
}

/**
 * Get cached XML data with improved performance
 */
function get_cached_xml() {
    // Check if we need to update
    if (needs_update()) {
        if (DEBUG_MODE) {
            error_log('Performing daily update');
        }
        fetch_images_from_ftp();
        cleanup_unused_images();
        update_timestamp();
    }

    if (ENABLE_XML_CACHE && file_exists(XML_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(XML_CACHE_FILE), true);
        if ($cache && isset($cache['timestamp']) && isset($cache['data'])) {
            if (time() - $cache['timestamp'] < XML_CACHE_TIME) {
                if (DEBUG_MODE) {
                    error_log('Loading XML from cache');
                }
                return simplexml_load_string($cache['data']);
            }
        }
    }

    if (!file_exists(LOCAL_XML_PATH)) {
        error_log('XML file not found: ' . LOCAL_XML_PATH);
        return false;
    }

    // Read XML file in chunks to handle large files
    $xml_content = '';
    $handle = fopen(LOCAL_XML_PATH, 'r');
    if ($handle) {
        while (!feof($handle)) {
            $xml_content .= fread($handle, 8192); // Read in 8KB chunks
        }
        fclose($handle);
    } else {
        error_log('Could not open XML file: ' . LOCAL_XML_PATH);
        return false;
    }

    if (empty($xml_content)) {
        error_log('XML file is empty: ' . LOCAL_XML_PATH);
        return false;
    }

    $xml = simplexml_load_string($xml_content);
    if (!$xml) {
        error_log('Error parsing XML content from: ' . LOCAL_XML_PATH);
        return false;
    }

    // Cache the XML data
    if (ENABLE_XML_CACHE) {
        $cache_data = [
            'timestamp' => time(),
            'data' => $xml_content
        ];
        file_put_contents(XML_CACHE_FILE, json_encode($cache_data));
    }

    return $xml;
}

/**
 * Create cronjob script if it doesn't exist
 */
function create_cronjob_script() {
    $cron_script = __DIR__ . '/update_cars.php';
    if (!file_exists($cron_script)) {
        $script_content = '<?php
require_once __DIR__ . "/VWE-auto-manager.php";
fetch_images_from_ftp();
cleanup_unused_images();
?>';
        file_put_contents($cron_script, $script_content);
    }
}

/**
 * Preload images for better performance
 */
function output_image_preload($cars) {
    echo '<link rel="preload" as="image" href="' . get_image_base_url() . 'placeholder.jpg">';
    foreach ($cars as $car) {
        if (isset($car->afbeeldingen) && isset($car->afbeeldingen->afbeelding)) {
            foreach ($car->afbeeldingen->afbeelding as $afbeelding) {
                if (isset($afbeelding->bestandsnaam)) {
                    $bestandsnaam = (string)$afbeelding->bestandsnaam;
                    if ($bestandsnaam !== '') {
                        echo '<link rel="preload" as="image" href="' . get_image_base_url() . $bestandsnaam . '">';
                    }
                }
            }
        }
    }
}

// Execute only the display function
display_car_listing();