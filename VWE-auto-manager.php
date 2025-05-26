<?php
/*
Plugin Name: VWE Auto Manager
Description: A plugin that advertises the cars from VWE
Author: Anouar
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Increase execution time and memory limit
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '256M');

// Configuration
define('VWE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VWE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FTP_SERVER', '91.184.31.234');
define('FTP_USER', 'anmvs-auto');
define('FTP_PASS', 'f6t23U~8t');
define('REMOTE_IMAGES_PATH', '/staging.mvsautomotive.nl/wp-content/plugins/xml/images/');
define('LOCAL_IMAGES_PATH', VWE_PLUGIN_DIR . 'images/');
define('LOCAL_XML_PATH', VWE_PLUGIN_DIR . 'local_file.xml');
define('DEBUG_MODE', false);
define('LAST_UPDATE_FILE', VWE_PLUGIN_DIR . 'last_update.txt');
define('UPDATE_INTERVAL', 86400); // 24 hours in seconds
define('IMAGE_URL_BASE', 'https://staging.mvsautomotive.nl/wp-content/plugins/VWE-auto-manager/images/');

// Cronjob configuration
add_action('init', function() {
    if (!wp_next_scheduled('vwe_daily_update')) {
        wp_schedule_event(time(), 'daily', 'vwe_daily_update');
    }
});

add_action('vwe_daily_update', 'update_all_data');

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
            if (@ftp_get($ftp_conn, $local_file, $remote_file, FTP_BINARY)) {
                // Optimize the downloaded image
                optimize_downloaded_image($local_file);
            } else {
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
    $xml = get_xml_data();
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
    echo '<div class="main-content">';

    // Filters panel
    echo '<aside class="filters-panel">
        <div class="filters-body">
            <div class="filter-group">
                <div class="filters-title">FILTERS & SORTS</div>
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
                        <option value="">Aantal deuren</option>
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
                        <option value="">Aantal zitplaatsen</option>
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

    // Cars grid with pagination
    echo '<div class="cars-container">
        <div class="cars-grid" id="carsGrid">';

    // Debug information
    if (DEBUG_MODE) {
        echo '<div class="debug-info">';
        echo '<p>Total cars in XML: ' . $total_items . '</p>';
        echo '</div>';
    }

    // Display first 9 cars initially
    $cars_per_page = 9;
    $total_pages = ceil($total_items / $cars_per_page);

    echo '</div>';

    // Add pagination controls
    if ($total_pages > 1) {
        echo '<div class="pagination-controls">
            <button class="pagination-prev" onclick="changePage(-1)" disabled>Previous</button>
            <div class="pagination-numbers">';

        // Show all page numbers
        for ($i = 1; $i <= $total_pages; $i++) {
            $active_class = $i === 1 ? ' active' : ''; // Mark first page as active initially
            echo '<button class="pagination-number' . $active_class . '" onclick="goToPage(' . $i . ')" data-page="' . $i . '">' . $i . '</button>';
        }

        echo '</div>
            <button class="pagination-next" onclick="changePage(1)">Next</button>
        </div>';
    }

    echo '</div>'; // Close cars-container
    echo '</div></div>'; // Close main-content and page-wrapper

    // Add pagination JavaScript
    $allCarsJson = json_encode(array_map(function($car) use ($image_url_base) { return extract_car_data($car, $image_url_base); }, $cars));
    $js = <<<'JS'
<script>
let currentPage = 1;
const carsPerPage = 9;
let allCars = __ALL_CARS_JSON__;
let filteredCars = allCars.slice();
const totalItems = allCars.length;

function checkFilters(carData) {
    const brandFilter = document.getElementById("brandFilter").value;
    const modelFilter = document.getElementById("modelFilter").value;
    const fuelFilter = document.getElementById("fuelFilter").value;
    const transmissionFilter = document.getElementById("transmissionFilter").value;
    const bodyFilter = document.getElementById("bodyFilter").value;
    const doorsFilter = document.getElementById("doorsFilter").value;
    const seatsFilter = document.getElementById("seatsFilter").value;
    const priceMin = document.getElementById("priceMin").value;
    const priceMax = document.getElementById("priceMax").value;
    const kmMin = document.getElementById("kmMin").value;
    const kmMax = document.getElementById("kmMax").value;
    const powerMin = document.getElementById("powerMin").value;
    const powerMax = document.getElementById("powerMax").value;

    const yearCheckboxes = document.querySelectorAll("input[name='year']:checked");
    const selectedYears = Array.from(yearCheckboxes).map(cb => cb.value);
    const yearMatch = selectedYears.includes("all") || selectedYears.some(range => {
        const [start, end] = range.split("-").map(Number);
        return carData.bouwjaar >= start && carData.bouwjaar <= end;
    });

    const statusCheckboxes = document.querySelectorAll("input[name='status']:checked");
    const selectedStatuses = Array.from(statusCheckboxes).map(cb => cb.value);
    const statusMatch = selectedStatuses.includes("all") || selectedStatuses.includes(carData.status);

    const carPrice = parseFloat((carData.prijs||'').toString().replace(/[^0-9.]/g, ''));
    const carKm = parseFloat((carData.kilometerstand||'').toString().replace(/[^0-9]/g, ""));
    const carPower = parseFloat((carData.vermogen_pk||'').toString().replace(/[^0-9]/g, ""));

    return (!brandFilter || carData.merk === brandFilter) &&
           (!modelFilter || carData.model === modelFilter) &&
           (!fuelFilter || carData.brandstof === fuelFilter) &&
           (!transmissionFilter || carData.transmissie === transmissionFilter) &&
           (!bodyFilter || carData.carrosserie === bodyFilter) &&
           (!doorsFilter || carData.deuren === doorsFilter) &&
           (!seatsFilter || carData.aantal_zitplaatsen === seatsFilter) &&
           (!priceMin || carPrice >= parseFloat(priceMin)) &&
           (!priceMax || carPrice <= parseFloat(priceMax)) &&
           (!kmMin || carKm >= parseFloat(kmMin)) &&
           (!kmMax || carKm <= parseFloat(kmMax)) &&
           (!powerMin || carPower >= parseFloat(powerMin)) &&
           (!powerMax || carPower <= parseFloat(powerMax)) &&
           yearMatch &&
           statusMatch;
}

function renderCars() {
    const carsGrid = document.getElementById("carsGrid");
    carsGrid.innerHTML = "";
    const start = (currentPage - 1) * carsPerPage;
    const end = start + carsPerPage;
    const carsToShow = filteredCars.slice(start, end);

    // Log the number of cars being rendered
    console.log(`Rendering ${carsToShow.length} cars (${start + 1} to ${end} of ${filteredCars.length})`);

    carsToShow.forEach(car => {
        const card = document.createElement("div");
        card.className = "car-card";
        card.dataset.car = JSON.stringify(car);
        card.innerHTML = `
            <div class="car-image">
                <img src="${car.eersteAfbeelding}" alt="${car.merk} ${car.model}" class="car-image" loading="lazy" decoding="async">
                <div class="car-badges">
                    <span class="status-badge ${car.status}">${car.status === "beschikbaar" ? "AVAILABLE" : (car.status === "verkocht" ? "VERKOCHT" : "GERESERVEERD")}</span>
                    <span class="year-badge">${car.bouwjaar}</span>
                </div>
            </div>
            <div class="car-info">
                <div class="car-brand">${car.merk.toUpperCase()}</div>
                <h3 class="car-title">${car.merk} ${car.model}${car.cilinder_inhoud ? ' / ' + car.cilinder_inhoud : ''}${car.transmissie ? ' / ' + car.transmissie : ''}${car.brandstof ? ' / ' + car.brandstof : ''}${car.deuren ? ' / ' + car.deuren + ' Deurs' : ''} / NL Auto</h3>
                <div class="car-price">€ ${Number((car.prijs||'').toString().replace(/[^0-9.]/g, '')).toLocaleString("nl-NL")}</div>
                <div class="car-specs">
                    <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/Tank.svg" alt="Kilometerstand" width="18" style="vertical-align:middle;margin-right:4px;">${car.kilometerstand || '0 km'}</span>
                    <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/pK.svg" alt="Vermogen" width="18" style="vertical-align:middle;margin-right:4px;">${car.vermogen || '0 pk'}</span>
                </div>
                <button type="button" class="view-button" onclick="showCarDetails(this)">BEKIJKEN <span class="arrow">→</span></button>
            </div>
        `;
        carsGrid.appendChild(card);
    });
}

function updatePagination() {
    const totalPages = Math.ceil(filteredCars.length / carsPerPage);

    // Update Previous/Next buttons
    document.querySelector(".pagination-prev").disabled = currentPage === 1;
    document.querySelector(".pagination-next").disabled = currentPage === totalPages || totalPages === 0;

    // Update page number buttons
    const pageButtons = document.querySelectorAll('.pagination-number');
    pageButtons.forEach(button => {
        const pageNum = parseInt(button.dataset.page);
        button.classList.toggle('active', pageNum === currentPage);
        button.disabled = pageNum === currentPage;
    });
}

function goToPage(page) {
    if (page < 1 || page > Math.ceil(filteredCars.length / carsPerPage)) return;
    currentPage = page;
    renderCars();
    updatePagination();
    document.getElementById("carsGrid").scrollIntoView({ behavior: "smooth" });
}

function applyFilters() {
    filteredCars = allCars.filter(checkFilters);
    currentPage = 1;
    renderCars();
    updatePagination();
    console.log(`Total cars after filtering: ${filteredCars.length}`);
}

function changePage(direction) {
    const totalPages = Math.ceil(filteredCars.length / carsPerPage);
    const newPage = currentPage + direction;
    if (newPage < 1 || newPage > totalPages) return;
    currentPage = newPage;
    renderCars();
    updatePagination();
    document.getElementById("carsGrid").scrollIntoView({ behavior: "smooth" });
}

function resetAllFilters() {
    document.querySelectorAll("select").forEach(select => { select.value = ""; });
    document.querySelectorAll("input[type='number']").forEach(input => { input.value = ""; });
    document.querySelectorAll("input[type='checkbox']").forEach(checkbox => { checkbox.checked = checkbox.value === "all"; });
    applyFilters();
}

document.addEventListener("DOMContentLoaded", function() {
    const filterElements = [
        "brandFilter", "modelFilter", "fuelFilter",
        "transmissionFilter", "bodyFilter", "doorsFilter",
        "seatsFilter", "priceMin", "priceMax",
        "kmMin", "kmMax", "powerMin", "powerMax"
    ];
    filterElements.forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener("input", applyFilters);
        }
    });
    document.querySelectorAll("input[name='year']").forEach(cb => { cb.addEventListener("change", applyFilters); });
    document.querySelectorAll("input[name='status']").forEach(cb => { cb.addEventListener("change", applyFilters); });
    const resetButton = document.getElementById("resetFilters");
    if (resetButton) { resetButton.addEventListener("click", resetAllFilters); }
    const prevBtn = document.querySelector(".pagination-prev");
    const nextBtn = document.querySelector(".pagination-next");
    if (prevBtn) prevBtn.addEventListener("click", () => changePage(-1));
    if (nextBtn) nextBtn.addEventListener("click", () => changePage(1));

    // Initial render
    console.log(`Total cars loaded: ${allCars.length}`);
    applyFilters();
});

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
    carouselSlides.innerHTML = "";
    carouselDots.innerHTML = "";
    if (carData.afbeeldingen && carData.afbeeldingen.length > 0) {
        carData.afbeeldingen.forEach((image, index) => {
            const slide = document.createElement("div");
            slide.className = "carousel-slide";
            slide.innerHTML = `<img src="${image}" alt="${carData.merk} ${carData.model} - Image ${index + 1}">`;
            carouselSlides.appendChild(slide);
            const dot = document.createElement("div");
            dot.className = "carousel-dot" + (index === 0 ? " active" : "");
            dot.onclick = () => goToPage(index + 1);
            carouselDots.appendChild(dot);
        });
        totalSlides = carData.afbeeldingen.length;
        currentSlide = 0;
        updateCarousel();
    }
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

function closeModal() {
    const modal = document.getElementById("carModal");
    modal.style.display = "none";
}

window.showCarDetails = showCarDetails;
window.openModal = openModal;
window.closeModal = closeModal;
window.nextSlide = nextSlide;
window.prevSlide = prevSlide;
window.goToSlide = goToSlide;
window.addEventListener("click", function(event) {
    const modal = document.getElementById("carModal");
    if (event.target === modal) {
        closeModal();
    }
});
window.addEventListener("keydown", function(event) {
    if (event.key === "Escape") {
        closeModal();
    }
});
</script>
JS;
    echo str_replace('__ALL_CARS_JSON__', $allCarsJson, $js);

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
    return IMAGE_URL_BASE;
}

/**
 * Generate optimized image HTML with WebP support
 */
function get_optimized_image_html($image_url, $alt_text, $class = '') {
    // Convert image URL to WebP version
    $webp_url = str_replace(['.jpg', '.jpeg', '.png'], '.webp', $image_url);

    // Generate srcset for different sizes
    $srcset = sprintf(
        '%s 400w, %s 800w, %s 1200w',
        str_replace('.webp', '-400.webp', $webp_url),
        str_replace('.webp', '-800.webp', $webp_url),
        str_replace('.webp', '-1200.webp', $webp_url)
    );

    // Generate fallback srcset for browsers that don't support WebP
    $fallback_srcset = sprintf(
        '%s 400w, %s 800w, %s 1200w',
        str_replace('.webp', '-400.jpg', $image_url),
        str_replace('.webp', '-800.jpg', $image_url),
        str_replace('.webp', '-1200.jpg', $image_url)
    );

    return sprintf(
        '<picture>
            <source type="image/webp" srcset="%s" sizes="(max-width: 400px) 400px, (max-width: 800px) 800px, 1200px">
            <source type="image/jpeg" srcset="%s" sizes="(max-width: 400px) 400px, (max-width: 800px) 800px, 1200px">
            <img src="%s"
                 alt="%s"
                 class="%s"
                 loading="lazy"
                 decoding="async"
                 width="1200"
                 height="800"
                 onerror="this.onerror=null; this.src=\'%splaceholder.jpg\';"
                 fetchpriority="low">
        </picture>',
        $srcset,
        $fallback_srcset,
        $image_url,
        htmlspecialchars($alt_text, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
        get_image_base_url()
    );
}

/**
 * Optimize images when they are fetched from FTP
 */
function optimize_downloaded_image($local_file) {
    if (!file_exists($local_file)) {
        return false;
    }

    $image_info = getimagesize($local_file);
    if (!$image_info) {
        return false;
    }

    // Create different sizes
    $sizes = [400, 800, 1200];
    $formats = ['jpg', 'webp'];

    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            $output_file = str_replace(
                ['.jpg', '.jpeg', '.png'],
                "-{$size}.{$format}",
                $local_file
            );

            // Use ImageMagick if available
            if (extension_loaded('imagick')) {
                $imagick = new Imagick($local_file);
                $imagick->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1);
                $imagick->setImageCompressionQuality(85);

                if ($format === 'webp') {
                    $imagick->setImageFormat('webp');
                    $imagick->setOption('webp:method', '6');
                    $imagick->setOption('webp:lossless', 'false');
                }

                $imagick->writeImage($output_file);
                $imagick->clear();
            }
            // Fallback to GD if ImageMagick is not available
            else if (extension_loaded('gd')) {
                $source_image = imagecreatefromstring(file_get_contents($local_file));
                $width = imagesx($source_image);
                $height = imagesy($source_image);

                $ratio = $width / $height;
                $new_width = $size;
                $new_height = $size / $ratio;

                $new_image = imagecreatetruecolor($new_width, $new_height);

                // Preserve transparency for PNG
                if ($image_info[2] === IMAGETYPE_PNG) {
                    imagealphablending($new_image, false);
                    imagesavealpha($new_image, true);
                }

                imagecopyresampled(
                    $new_image, $source_image,
                    0, 0, 0, 0,
                    $new_width, $new_height,
                    $width, $height
                );

                if ($format === 'webp') {
                    imagewebp($new_image, $output_file, 85);
                } else {
                    imagejpeg($new_image, $output_file, 85);
                }

                imagedestroy($source_image);
                imagedestroy($new_image);
            }
        }
    }

    return true;
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
    } else {
        $data['kilometerstand'] = '0 km';
    }

    if (is_numeric($data['vermogen_pk'])) {
        $data['vermogen'] = $data['vermogen_pk'] . ' pk';
    } else {
        $data['vermogen'] = '0 pk';
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

    // Create a detailed title with specifications
    $title_parts = [];
    $title_parts[] = $car['merk'] . ' ' . $car['model'];

    // Add engine info if available
    if (!empty($car['cilinder_inhoud'])) {
        $title_parts[] = $car['cilinder_inhoud'];
    }

    // Add transmission if available
    if (!empty($car['transmissie'])) {
        $title_parts[] = $car['transmissie'];
    }

    // Add fuel type if available
    if (!empty($car['brandstof'])) {
        $title_parts[] = $car['brandstof'];
    }

    // Add doors if available
    if (!empty($car['deuren'])) {
        $title_parts[] = $car['deuren'] . ' Deurs';
    }

    // Add NL Auto if it's a Dutch car
    $title_parts[] = 'NL Auto';

    // Join all parts with spaces
    $detailed_title = implode(' / ', $title_parts);

    echo '<div class="car-card" data-car=\'' . $jsonData . '\'>
        <div class="car-image">';

    // Use the new optimized image function
    echo get_optimized_image_html(
        $car['eersteAfbeelding'],
        $car['merk'] . ' ' . $car['model'],
        'car-image'
    );

    echo '<div class="car-badges">
                <span class="status-badge ' . $status_class . '">' . $status_label . '</span>
                <span class="year-badge">' . $car['bouwjaar'] . '</span>
            </div>
    </div>';

    echo '<div class="car-info">
            <div class="car-brand">' . strtoupper($car['merk']) . '</div>
            <h3 class="car-title">' . $detailed_title . '</h3>
            <div class="car-price">€ ' . number_format((float)$car['prijs'], 0, ',', '.') . '</div>
            <div class="car-specs">
                <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/Tank.svg" alt="Kilometerstand" width="18" style="vertical-align:middle;margin-right:4px;">' . $car['kilometerstand'] . '</span>
                <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/pK.svg" alt="Vermogen" width="18" style="vertical-align:middle;margin-right:4px;">' . $car['vermogen'] . '</span>
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
 * Download XML file from FTP server
 */
function download_xml_from_ftp() {
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
        return false;
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
        return false;
    }

    ftp_pasv($ftp_conn, true);

    // Enable SSL if available
    if ($is_ssl) {
        ftp_set_option($ftp_conn, FTP_USEPASVADDRESS, true);
    }

    // Define remote and local XML paths
    $remote_xml_path = '/staging.mvsautomotive.nl/wp-content/plugins/xml/voertuigen.xml';
    $local_xml_path = LOCAL_XML_PATH;
    $temp_xml_path = $local_xml_path . '.temp';

    // Download XML file to temporary location
    if (!@ftp_get($ftp_conn, $temp_xml_path, $remote_xml_path, FTP_BINARY)) {
        error_log('Failed to download XML file from: ' . $remote_xml_path);
        if ($is_ssl) {
            @ftp_close($ftp_conn);
        } else {
            ftp_close($ftp_conn);
        }
        return false;
    }

    // Verify the downloaded XML is valid
    $xml_content = file_get_contents($temp_xml_path);
    if (!$xml_content || !simplexml_load_string($xml_content)) {
        error_log('Downloaded XML file is invalid or empty');
        unlink($temp_xml_path);
        if ($is_ssl) {
            @ftp_close($ftp_conn);
        } else {
            ftp_close($ftp_conn);
        }
        return false;
    }

    // Replace old XML file with new one
    if (file_exists($local_xml_path)) {
        unlink($local_xml_path);
    }
    rename($temp_xml_path, $local_xml_path);

    // Properly close the connection
    if ($is_ssl) {
        @ftp_close($ftp_conn);
    } else {
        ftp_close($ftp_conn);
    }

    return true;
}

/**
 * Ensure all images from XML exist locally
 */
function ensure_all_images_exist() {
    if (!file_exists(LOCAL_XML_PATH)) {
        error_log('XML file not found');
        return false;
    }

    $xml_content = file_get_contents(LOCAL_XML_PATH);
    if (!$xml_content) {
        error_log('Could not read XML file');
        return false;
    }

    $xml = new SimpleXMLElement($xml_content);
    if (!$xml) {
        error_log('Error parsing XML content');
        return false;
    }

    $missing_images = [];
    $max_retries = 3;
    $retry_count = 0;

    // Collect all image filenames from XML
    foreach ($xml->voertuig as $car) {
        if (isset($car->afbeeldingen)) {
            foreach ($car->afbeeldingen->children() as $afbeelding) {
                if ($afbeelding->getName() === 'afbeelding') {
                    if (isset($afbeelding->bestandsnaam)) {
                        $filename = trim((string)$afbeelding->bestandsnaam);
                        if ($filename !== '') {
                            $local_file = LOCAL_IMAGES_PATH . $filename;
                            if (!file_exists($local_file)) {
                                $missing_images[] = $filename;
                            }
                        }
                    }
                }
            }
        }
    }

    // If there are missing images, try to download them
    while (!empty($missing_images) && $retry_count < $max_retries) {
        $retry_count++;
        error_log("Attempt $retry_count to download missing images. Missing count: " . count($missing_images));

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
            continue;
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
            continue;
        }

        ftp_pasv($ftp_conn, true);

        // Enable SSL if available
        if ($is_ssl) {
            ftp_set_option($ftp_conn, FTP_USEPASVADDRESS, true);
        }

        $still_missing = [];
        foreach ($missing_images as $filename) {
            $remote_file = REMOTE_IMAGES_PATH . $filename;
            $local_file = LOCAL_IMAGES_PATH . $filename;

            if (@ftp_get($ftp_conn, $local_file, $remote_file, FTP_BINARY)) {
                // Optimize the downloaded image
                optimize_downloaded_image($local_file);
                error_log("Successfully downloaded and optimized: $filename");
            } else {
                $still_missing[] = $filename;
                error_log("Failed to download: $filename");
            }
        }

        // Properly close the connection
        if ($is_ssl) {
            @ftp_close($ftp_conn);
        } else {
            ftp_close($ftp_conn);
        }

        // Update missing images list
        $missing_images = $still_missing;

        // If we still have missing images, wait before retrying
        if (!empty($missing_images)) {
            sleep(5); // Wait 5 seconds before retrying
        }
    }

    // Log final status
    if (empty($missing_images)) {
        error_log("All images successfully downloaded after $retry_count attempts");
        return true;
    } else {
        error_log("Failed to download " . count($missing_images) . " images after $max_retries attempts");
        return false;
    }
}

/**
 * Update all data (XML and images)
 */
function update_all_data() {
    if (needs_update()) {
        if (DEBUG_MODE) {
            error_log('Performing daily update');
        }

        // Download and update XML file
        if (!download_xml_from_ftp()) {
            error_log('Failed to update XML file');
            return false;
        }

        // Update images and ensure all images exist
        fetch_images_from_ftp();
        ensure_all_images_exist();
        cleanup_unused_images();
        update_timestamp();

        if (DEBUG_MODE) {
            error_log('Update completed successfully');
        }
    }
    return true;
}

// Update the get_xml_data function to use the new update mechanism
function get_xml_data() {
    // Check if we need to update and perform update if needed
    update_all_data();

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

    return $xml;
}

/**
 * Preload images for better performance
 */
function output_image_preload($cars) {
    // Preload placeholder image with high priority
    echo '<link rel="preload" as="image" href="' . get_image_base_url() . 'placeholder.jpg" fetchpriority="high">';

    // Track already preloaded images to avoid duplicates
    $preloaded_images = array();

    // Only preload the first 9 images (initial viewport)
    $count = 0;
    foreach ($cars as $car) {
        if ($count >= 9) break; // Only preload first 9 images (initial viewport)

        if (isset($car->afbeeldingen) && isset($car->afbeeldingen->afbeelding)) {
            // Get the first image
            $first_image = is_array($car->afbeeldingen->afbeelding)
                ? $car->afbeeldingen->afbeelding[0]
                : $car->afbeeldingen->afbeelding;

            if (isset($first_image->bestandsnaam)) {
                $bestandsnaam = (string)$first_image->bestandsnaam;
                if ($bestandsnaam !== '' && !in_array($bestandsnaam, $preloaded_images)) {
                    // Add fetchpriority="high" for the first 3 images
                    $priority = $count < 3 ? 'high' : 'low';
                    echo '<link rel="preload" as="image" href="' . get_image_base_url() . $bestandsnaam . '" fetchpriority="' . $priority . '">';
                    $preloaded_images[] = $bestandsnaam;
                    $count++;
                }
            }
        }
    }
}

// Voeg WordPress hooks toe
add_action('init', 'vwe_init');
add_action('wp_enqueue_scripts', 'vwe_enqueue_scripts');
add_shortcode('vwe_auto_listing', 'vwe_display_car_listing');

/**
 * Initialize plugin
 */
function vwe_init() {
    // Maak benodigde directories aan
    if (!file_exists(LOCAL_IMAGES_PATH)) {
        wp_mkdir_p(LOCAL_IMAGES_PATH);
    }
}

/**
 * Enqueue scripts and styles
 */
function vwe_enqueue_scripts() {
    wp_enqueue_style('vwe-styles', VWE_PLUGIN_URL . 'styling.css', array(), filemtime(VWE_PLUGIN_DIR . 'styling.css'));
    wp_enqueue_script('vwe-scripts', VWE_PLUGIN_URL . 'scripts.js', array('jquery'), filemtime(VWE_PLUGIN_DIR . 'scripts.js'), true);
}

/**
 * Shortcode function to display car listing
 */
function vwe_display_car_listing($atts) {
    ob_start();
    display_car_listing();
    return ob_get_clean();
}

function check_last_update() {
    if (file_exists(LAST_UPDATE_FILE)) {
        $last_update = file_get_contents(LAST_UPDATE_FILE);
        $next_update = (int)$last_update + UPDATE_INTERVAL;
        $time_until_next = $next_update - time();

        error_log("Last update: " . date('Y-m-d H:i:s', (int)$last_update));
        error_log("Next update in: " . round($time_until_next / 3600, 2) . " hours");
    } else {
        error_log("No last update found - update needed");
    }
}