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

    echo '<div class="main-content" style="display:flex; flex-direction:row; align-items:flex-start; gap:30px;">';

    // Filters panel links
    echo '<aside class="filters-panel">';
    echo '<div class="filters-body">';
    echo '<div class="filter-group">
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
        </div></aside>';

    // Content rechts: filter-bar + cards
    echo '<div class="content-right" style="flex:1; display:flex; flex-direction:column; gap:24px;">';
    // Filter bar boven de cards
    echo '<div class="filter-bar">';
    echo '<div class="result-count" id="resultCount">0 results found</div>';
    echo '<div class="dropdown-group">';
    echo '<div class="custom-select">
        <select id="sortSelect" class="filter-dropdown">
            <option value="">Sort By Default</option>
            <option value="prijs-asc">Price (Low-High)</option>
            <option value="prijs-desc">Price (High-Low)</option>
            <option value="km-asc">Mileage (Low-High)</option>
            <option value="km-desc">Mileage (High-Low)</option>
            <option value="jaar-desc">Newest First</option>
            <option value="jaar-asc">Oldest First</option>
        </select>
    </div>';
    echo '<div class="custom-select">
        <select id="showSelect" class="filter-dropdown">
            <option value="12">Show 12</option>
            <option value="24">Show 24</option>
            <option value="50">Show 50</option>
            <option value="100">Show 100</option>
        </select>
    </div>';
    echo '</div>';
    echo '</div>';

    // Cards grid
    echo '<div class="cars-container">';
    echo '<div class="cars-grid" id="carsGrid">';

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
let carsPerPage = 9; // Default value, will be updated by showSelect
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
                <img src="${car.eersteAfbeelding}" alt="${car.merk} ${car.model}" class="car-image" loading="lazy" decoding="async" onclick="showCarDetails(this.closest('.car-card').querySelector('.view-button'))" style="cursor: pointer;">
                <div class="car-badges">
                    <span class="status-badge ${car.status}">${car.status === "beschikbaar" ? "AVAILABLE" : (car.status === "verkocht" ? "VERKOCHT" : "GERESERVEERD")}</span>
                    <span class="year-badge">${car.bouwjaar}</span>
                </div>
            </div>
            <div class="car-info">
                <div class="car-brand">${car.merk.toUpperCase()}</div>
                <h3 class="car-title">${car.titel || `${car.merk} ${car.model}${car.cilinder_inhoud ? ' / ' + car.cilinder_inhoud : ''}${car.transmissie ? ' / ' + car.transmissie : ''}${car.brandstof ? ' / ' + car.brandstof : ''}${car.deuren ? ' / ' + car.deuren + ' Deurs' : ''} / NL Auto`}</h3>
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
    const paginationNumbers = document.querySelector('.pagination-numbers');
    const prevBtn = document.querySelector('.pagination-prev');
    const nextBtn = document.querySelector('.pagination-next');

    // Update prev/next buttons
    if (prevBtn) {
        prevBtn.disabled = currentPage === 1;
    }
    if (nextBtn) {
        nextBtn.disabled = currentPage === totalPages;
    }

    // Update page numbers
    if (paginationNumbers) {
        paginationNumbers.innerHTML = '';

        // Calculate which page numbers to show
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + 4);

        // Adjust start page if we're near the end
        if (endPage - startPage < 4) {
            startPage = Math.max(1, endPage - 4);
        }

        // Add first page and ellipsis if needed
        if (startPage > 1) {
            const firstPageBtn = document.createElement('button');
            firstPageBtn.className = 'pagination-number';
            firstPageBtn.textContent = '1';
            firstPageBtn.onclick = () => goToPage(1);
            paginationNumbers.appendChild(firstPageBtn);

            if (startPage > 2) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'pagination-ellipsis';
                ellipsis.textContent = '...';
                paginationNumbers.appendChild(ellipsis);
            }
        }

        // Add page numbers
        for (let i = startPage; i <= endPage; i++) {
            const pageBtn = document.createElement('button');
            pageBtn.className = `pagination-number${i === currentPage ? ' active' : ''}`;
            pageBtn.textContent = i;
            pageBtn.onclick = () => goToPage(i);
            paginationNumbers.appendChild(pageBtn);
        }

        // Add last page and ellipsis if needed
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'pagination-ellipsis';
                ellipsis.textContent = '...';
                paginationNumbers.appendChild(ellipsis);
            }

            const lastPageBtn = document.createElement('button');
            lastPageBtn.className = 'pagination-number';
            lastPageBtn.textContent = totalPages;
            lastPageBtn.onclick = () => goToPage(totalPages);
            paginationNumbers.appendChild(lastPageBtn);
        }
    }
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
    currentPage = 1; // Reset to first page when filters change
    renderCars();
    updatePagination();
    updateResultsCount();
}

function sortCars() {
    const sortValue = document.getElementById("sortSelect").value;
    if (!sortValue) return;

    const [sortBy, sortOrder] = sortValue.split("-");

    filteredCars.sort((a, b) => {
        let valA, valB;

        if (sortBy === "prijs") {
            valA = parseFloat((a.prijs || '').toString().replace(/[^0-9.]/g, ''));
            valB = parseFloat((b.prijs || '').toString().replace(/[^0-9.]/g, ''));
        } else if (sortBy === "km") {
            valA = parseFloat((a.kilometerstand || '').toString().replace(/[^0-9]/g, ""));
            valB = parseFloat((b.kilometerstand || '').toString().replace(/[^0-9]/g, ""));
        } else if (sortBy === "jaar") {
            valA = parseInt(a.bouwjaar);
            valB = parseInt(b.bouwjaar);
        } else {
            return 0; // No specific sort, maintain current order
        }

        if (isNaN(valA)) valA = sortOrder === 'asc' ? Infinity : -Infinity;
        if (isNaN(valB)) valB = sortOrder === 'asc' ? Infinity : -Infinity;

        if (valA < valB) return sortOrder === "asc" ? -1 : 1;
        if (valA > valB) return sortOrder === "asc" ? 1 : -1;
        return 0;
    });
}

function updateResultsCount() {
    const resultCountElement = document.getElementById("resultCount");
    if (resultCountElement) {
        const count = filteredCars.length;
        resultCountElement.textContent = count + (count === 1 ? " result found" : " results found");
    }
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

    // Add event listener for brand filter to update model options
    const brandFilter = document.getElementById("brandFilter");
    const modelFilter = document.getElementById("modelFilter");

    if (brandFilter && modelFilter) {
        brandFilter.addEventListener("change", function() {
            const selectedBrand = this.value;
            const models = new Set();

            // Clear current model options except the first one
            modelFilter.innerHTML = '<option value="">Alle Modellen</option>';

            if (selectedBrand) {
                // Get all models for the selected brand
                allCars.forEach(car => {
                    if (car.merk === selectedBrand) {
                        models.add(car.model);
                    }
                });

                // Sort models alphabetically
                const sortedModels = Array.from(models).sort();

                // Add model options
                sortedModels.forEach(model => {
                    const option = document.createElement("option");
                    option.value = model;
                    option.textContent = model;
                    modelFilter.appendChild(option);
                });
            }

            // Reset model filter and apply filters
            modelFilter.value = "";
            applyFilters();
        });

        // Trigger change event on brand filter to populate models initially
        if (brandFilter.value) {
            brandFilter.dispatchEvent(new Event('change'));
        }
    }

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

    const sortSelect = document.getElementById("sortSelect");
    if (sortSelect) {
        sortSelect.addEventListener("change", function() {
            sortCars();
            currentPage = 1; // Reset to first page after sorting
            renderCars();
            updatePagination();
        });
    }

    const showSelect = document.getElementById("showSelect");
    if (showSelect) {
        showSelect.addEventListener("change", function() {
            carsPerPage = parseInt(this.value) || 9; // Update carsPerPage
            currentPage = 1; // Reset to first page
            renderCars();
            updatePagination();
        });
    }
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
    const carouselCounter = modal.querySelector(".carousel-counter");
    const details = modal.querySelector(".modal-details");

    // Generate URL-friendly title
    const carTitle = carData.titel || `${carData.merk} ${carData.model}${carData.cilinder_inhoud ? ' ' + carData.cilinder_inhoud : ''}${carData.transmissie ? ' ' + carData.transmissie : ''}${carData.brandstof ? ' ' + carData.brandstof : ''}${carData.deuren ? ' ' + carData.deuren + ' Deurs' : ''}`;
    const carId = carTitle.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '') // Remove special characters
        .replace(/\s+/g, '-') // Replace spaces with hyphens
        .replace(/-+/g, '-') // Replace multiple hyphens with single hyphen
        .replace(/^-|-$/g, ''); // Remove leading/trailing hyphens

    const carUrl = window.location.origin + window.location.pathname + '?car=' + carId;

    // Update browser URL without reloading the page
    window.history.pushState({car: carData}, '', carUrl);

    // Reset carousel
    carouselSlides.innerHTML = "";
    carouselDots.innerHTML = "";

    if (carData.afbeeldingen && carData.afbeeldingen.length > 0) {
        carData.afbeeldingen.forEach((image, index) => {
            const slide = document.createElement("div");
            slide.className = "carousel-slide";
            slide.innerHTML = `
                <img src="${image}"
                     alt="${carData.merk} ${carData.model} - Image ${index + 1}"
                     loading="lazy"
                     decoding="async"
                     onclick="openLightbox(this.src)">
            `;
            carouselSlides.appendChild(slide);

            const dot = document.createElement("div");
            dot.className = "carousel-dot" + (index === 0 ? " active" : "");
            dot.onclick = () => goToSlide(index);
            carouselDots.appendChild(dot);
        });

        window.totalSlides = carData.afbeeldingen.length;
        window.currentSlide = 0;
        updateCarousel();
        updateCarouselCounter();
    }

    // Update carousel counter
    function updateCarouselCounter() {
        carouselCounter.textContent = `${window.currentSlide + 1} / ${window.totalSlides}`;
    }

    // Add keyboard navigation
    document.addEventListener("keydown", function(e) {
        if (modal.style.display === "block") {
            if (e.key === "ArrowLeft") prevSlide();
            if (e.key === "ArrowRight") nextSlide();
        }
    });

    details.innerHTML = `
        <div class="modal-sections">
            <div class="modal-section title-section" data-car='${JSON.stringify(carData)}'>
                <div class="title-content">
                    <h3>${carData.titel || (carData.merk + ' ' + carData.model)}</h3>
                    <div class="car-status ${carData.status}">${carData.status.toUpperCase()}</div>
                </div>
                <div class="price-tag">€ ${Number(carData.prijs).toLocaleString("nl-NL")}</div>
            </div>

            <div class="modal-section">
                <h4><i class="fas fa-info-circle"></i> Belangrijke Specificaties</h4>
                <div class="specs-grid">
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-calendar"></i> Bouwjaar</span>
                        <span class="spec-value">${carData.bouwjaar || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-tachometer-alt"></i> Kilometerstand</span>
                        <span class="spec-value">${carData.kilometerstand || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-gas-pump"></i> Brandstof</span>
                        <span class="spec-value">${carData.brandstof || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-cog"></i> Transmissie</span>
                        <span class="spec-value">${carData.transmissie || "N/A"}</span>
                    </div>
                </div>
            </div>

            <div class="modal-section">
                <h4><i class="fas fa-tools"></i> Technische Details</h4>
                <div class="specs-grid">
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-bolt"></i> Vermogen</span>
                        <span class="spec-value">${carData.vermogen || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-compress-arrows-alt"></i> Cilinder Inhoud</span>
                        <span class="spec-value">${carData.cilinder_inhoud || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-cogs"></i> Aantal Cilinders</span>
                        <span class="spec-value">${carData.cilinders || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-weight"></i> Gewicht</span>
                        <span class="spec-value">${carData.gewicht || "N/A"}</span>
                    </div>
                </div>
            </div>

            <div class="modal-section">
                <h4><i class="fas fa-car"></i> Voertuig Kenmerken</h4>
                <div class="specs-grid">
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-car-side"></i> Carrosserie</span>
                        <span class="spec-value">${carData.carrosserie || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-door-open"></i> Aantal Deuren</span>
                        <span class="spec-value">${carData.deuren || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-chair"></i> Aantal Zitplaatsen</span>
                        <span class="spec-value">${carData.aantal_zitplaatsen || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-palette"></i> Kleur</span>
                        <span class="spec-value">${carData.kleur || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-paint-brush"></i> Interieur Kleur</span>
                        <span class="spec-value">${carData.interieurkleur || "N/A"}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label"><i class="fas fa-couch"></i> Bekleding</span>
                        <span class="spec-value">${carData.bekleding || "N/A"}</span>
                    </div>
                </div>
            </div>

            ${carData.opmerkingen ? `
                <div class="modal-section">
                    <h4><i class="fas fa-align-left"></i> Beschrijving</h4>
                    <div class="description-content">
                        ${carData.opmerkingen}
                    </div>
                </div>
            ` : ""}
        </div>
    `;

    modal.style.display = "block";
    document.body.style.overflow = "hidden"; // Prevent background scrolling
}

function closeModal() {
    const modal = document.getElementById("carModal");
    modal.style.display = "none";
    document.body.style.overflow = ""; // Restore scrolling
    // Reset URL when closing modal
    window.history.pushState({}, '', window.location.pathname);
}

function updateCarousel() {
    const slides = document.querySelector(".carousel-slides");
    if (!slides || !window.totalSlides) return;
    slides.style.transform = "translateX(-" + (window.currentSlide * 100) + "%)";
    const dots = document.querySelectorAll(".carousel-dot");
    dots.forEach((dot, index) => {
        dot.classList.toggle("active", index === window.currentSlide);
    });
}

function nextSlide() {
    if (!window.totalSlides) return;
    window.currentSlide = (window.currentSlide + 1) % window.totalSlides;
    updateCarousel();
    updateCarouselCounter();
}

function prevSlide() {
    if (!window.totalSlides) return;
    window.currentSlide = (window.currentSlide - 1 + window.totalSlides) % window.totalSlides;
    updateCarousel();
    updateCarouselCounter();
}

function goToSlide(index) {
    if (!window.totalSlides) return;
    window.currentSlide = index;
    updateCarousel();
    updateCarouselCounter();
}

function updateCarouselCounter() {
    const counter = document.querySelector(".carousel-counter");
    if (counter && window.totalSlides) {
        counter.textContent = `${window.currentSlide + 1} / ${window.totalSlides}`;
    }
}

function openLightbox(imageSrc) {
    const lightbox = document.createElement("div");
    lightbox.className = "lightbox";
    lightbox.innerHTML = `
        <img src="${imageSrc}" alt="Full size image">
        <button class="lightbox-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    document.body.appendChild(lightbox);
}

function shareCar() {
    const carData = JSON.parse(document.querySelector(".modal-section.title-section").dataset.car);

    // Generate URL-friendly title
    const carTitle = carData.titel || `${carData.merk} ${carData.model}${carData.cilinder_inhoud ? ' ' + carData.cilinder_inhoud : ''}${carData.transmissie ? ' ' + carData.transmissie : ''}${carData.brandstof ? ' ' + carData.brandstof : ''}${carData.deuren ? ' ' + carData.deuren + ' Deurs' : ''}`;
    const carId = carTitle.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '') // Remove special characters
        .replace(/\s+/g, '-') // Replace spaces with hyphens
        .replace(/-+/g, '-') // Replace multiple hyphens with single hyphen
        .replace(/^-|-$/g, ''); // Remove leading/trailing hyphens

    const shareUrl = window.location.origin + window.location.pathname + '?car=' + carId;

    const shareData = {
        title: carTitle,
        text: `Bekijk deze ${carTitle} op onze website!`,
        url: shareUrl
    };

    if (navigator.share) {
        navigator.share(shareData)
            .then(() => {
                showNotification('Succesvol gedeeld!');
            })
            .catch((error) => {
                console.error('Error sharing:', error);
                fallbackShare(shareUrl);
            });
                    } else {
        fallbackShare(shareUrl);
    }
}

function fallbackShare(url) {
    const dummy = document.createElement("input");
    document.body.appendChild(dummy);
    dummy.value = url;
    dummy.select();
    document.execCommand("copy");
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

function printCarDetails() {
    window.print();
}

// Voeg event listeners toe voor topbar filters
["brandFilterBar", "modelFilterBar", "fuelFilterBar", "transmissionFilterBar"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener("change", filterCars);
});

// Pas filterCars aan om getFilterValue te gebruiken
function filterCars() {
    carCards.forEach(card => {
        try {
            const carData = JSON.parse(card.dataset.car);
            const criteria = {
                brandMatch: !getFilterValue("brandFilterBar", "brandFilter") || carData.merk === getFilterValue("brandFilterBar", "brandFilter"),
                modelMatch: !getFilterValue("modelFilterBar", "modelFilter") || carData.model === getFilterValue("modelFilterBar", "modelFilter"),
                fuelMatch: !getFilterValue("fuelFilterBar", "fuelFilter") || carData.brandstof === getFilterValue("fuelFilterBar", "fuelFilter"),
                transmissionMatch: !getFilterValue("transmissionFilterBar", "transmissionFilter") || carData.transmissie === getFilterValue("transmissionFilterBar", "transmissionFilter"),
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
            card.style.display = "none";
        }
    });
    updateResultsCount();
}

// Reset beide bars in resetAllFilters
function resetAllFilters() {
    ["brandFilter", "modelFilter", "fuelFilter", "transmissionFilter", "brandFilterBar", "modelFilterBar", "fuelFilterBar", "transmissionFilterBar"].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = "";
    });
    document.querySelectorAll("input[name='year'], input[name='status']").forEach(cb => cb.checked = cb.value === "all");
    sortSelect.value = "default";
    showSelect.value = "all";
    filterCars();
}

// updateResultsCount blijft hetzelfde
</script>
JS;
    echo str_replace('__ALL_CARS_JSON__', $allCarsJson, $js);

    // Render modals at the end of the page
    render_modals();

    // Add JavaScript for favorites functionality
    $js = <<<'JS'
<script>
// ... existing JavaScript code ...

// Function to update favorites display
function updateFavoritesDisplay() {
    const favorites = JSON.parse(localStorage.getItem('favorites') || '[]');
    const favoritesGrid = document.querySelector('.favorites-grid');
    const favoritesCount = document.querySelector('.favorites-count');

    // Update count
    favoritesCount.textContent = `${favorites.length} auto${favorites.length !== 1 ? "'s" : ""} in favorieten`;

    // Clear current display
    favoritesGrid.innerHTML = '';

    if (favorites.length === 0) {
        favoritesGrid.innerHTML = '<div class="no-favorites">Nog geen favorieten toegevoegd</div>';
        return;
    }

    // Display each favorite
    favorites.forEach(car => {
        const card = document.createElement("div");
        card.className = "car-card";
        card.dataset.car = JSON.stringify(car);
        card.innerHTML = `
            <div class="car-image">
                <img src="${car.eersteAfbeelding}" alt="${car.merk} ${car.model}" class="car-image" loading="lazy" decoding="async" onclick="showCarDetails(this.closest('.car-card').querySelector('.view-button'))" style="cursor: pointer;">
                <div class="car-badges">
                    <span class="status-badge ${car.status}">${car.status === "beschikbaar" ? "AVAILABLE" : (car.status === "verkocht" ? "VERKOCHT" : "GERESERVEERD")}</span>
                    <span class="year-badge">${car.bouwjaar}</span>
                </div>
            </div>
            <div class="car-info">
                <div class="car-brand">${car.merk.toUpperCase()}</div>
                <h3 class="car-title">${car.titel || `${car.merk} ${car.model}${car.cilinder_inhoud ? ' / ' + car.cilinder_inhoud : ''}${car.transmissie ? ' / ' + car.transmissie : ''}${car.brandstof ? ' / ' + car.brandstof : ''}${car.deuren ? ' / ' + car.deuren + ' Deurs' : ''} / NL Auto`}</h3>
                <div class="car-price">€ ${Number((car.prijs||'').toString().replace(/[^0-9.]/g, '')).toLocaleString("nl-NL")}</div>
                <div class="car-specs">
                    <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/Tank.svg" alt="Kilometerstand" width="18" style="vertical-align:middle;margin-right:4px;">${car.kilometerstand || '0 km'}</span>
                    <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/pK.svg" alt="Vermogen" width="18" style="vertical-align:middle;margin-right:4px;">${car.vermogen || '0 pk'}</span>
                </div>
                <button type="button" class="view-button" onclick="showCarDetails(this)">BEKIJKEN <span class="arrow">→</span></button>
            </div>
        `;
        favoritesGrid.appendChild(card);
    });
}

// Update toggleFavorite function to also update the favorites display
const originalToggleFavorite = toggleFavorite;
toggleFavorite = function() {
    originalToggleFavorite();
    updateFavoritesDisplay();
};

// Initialize favorites display
document.addEventListener('DOMContentLoaded', function() {
    updateFavoritesDisplay();
});
</script>
JS;
    echo $js;
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
            if (extension_loaded('imagick') && class_exists('Imagick')) {
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
        'titel' => $get_xml_value($car, 'titel', 'Onbekende titel'),
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

    return $data;
}

/**
 * Display a single car card
 */
function display_car_card($car) {
    // Generate a unique identifier for the car
    $car_id = strtolower(str_replace(' ', '-', $car['merk'] . '-' . $car['model'] . '-' . $car['kenteken']));

    // Ensure proper JSON encoding of the car data
    $jsonData = htmlspecialchars(json_encode($car, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES);

    $status_label = strtoupper($car['status'] === "beschikbaar" ? "AVAILABLE" :
                   ($car['status'] === "verkocht" ? "VERKOCHT" : "GERESERVEERD"));
    $status_class = $car['status'];

    // Use the titel field from XML for the car card title
    $detailed_title = $car['titel'];

    echo '<div class="car-card" data-car=\'' . $jsonData . '\' data-car-id="' . $car_id . '">
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
            <h3 class="car-title">' . htmlspecialchars($detailed_title) . '</h3>
            <div class="car-price">€ ' . number_format((float)str_replace(',', '.', preg_replace('/[^0-9,]/i', '', $car['prijs'])), 0, ',', '.') . '</div>
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
                        <div class="carousel-counter"></div>
                    </div>
                    <div class="carousel-dots"></div>
                </div>
                <div class="modal-details"></div>
                <div class="modal-actions">
                    <button class="modal-action-btn share-btn" onclick="shareCar()">
                        <i class="fas fa-share-alt"></i> Delen
                    </button>
                </div>
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
 * Create a new XML file with the correct structure
 */
function create_new_xml_file($data) {
    // Create the XML structure
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><voertuigen></voertuigen>');

    // Add each vehicle to the XML
    foreach ($data as $vehicle) {
        $voertuig = $xml->addChild('voertuig');

        // Add basic vehicle information
        $voertuig->addChild('merk', htmlspecialchars($vehicle['merk']));
        $voertuig->addChild('model', htmlspecialchars($vehicle['model']));
        $voertuig->addChild('titel', htmlspecialchars($vehicle['titel']));
        $voertuig->addChild('bouwjaar', htmlspecialchars($vehicle['bouwjaar']));
        $voertuig->addChild('verkoopprijs_particulier', htmlspecialchars($vehicle['prijs']));
        $voertuig->addChild('tellerstand', htmlspecialchars($vehicle['kilometerstand']));
        $voertuig->addChild('brandstof', htmlspecialchars($vehicle['brandstof']));
        $voertuig->addChild('transmissie', htmlspecialchars($vehicle['transmissie']));
        $voertuig->addChild('carrosserie', htmlspecialchars($vehicle['carrosserie']));
        $voertuig->addChild('aantal_deuren', htmlspecialchars($vehicle['deuren']));
        $voertuig->addChild('cilinder_inhoud', htmlspecialchars($vehicle['cilinder_inhoud']));
        $voertuig->addChild('vermogen_motor_pk', htmlspecialchars($vehicle['vermogen_pk']));
        $voertuig->addChild('kenteken', htmlspecialchars($vehicle['kenteken']));
        $voertuig->addChild('massa', htmlspecialchars($vehicle['gewicht']));
        $voertuig->addChild('aantal_zitplaatsen', htmlspecialchars($vehicle['aantal_zitplaatsen']));
        $voertuig->addChild('interieurkleur', htmlspecialchars($vehicle['interieurkleur']));
        $voertuig->addChild('bekleding', htmlspecialchars($vehicle['bekleding']));
        $voertuig->addChild('opmerkingen', htmlspecialchars($vehicle['opmerkingen']));

        // Add status information
        $voertuig->addChild('verkocht', $vehicle['status'] === 'verkocht' ? 'j' : 'n');
        $voertuig->addChild('gereserveerd', $vehicle['status'] === 'gereserveerd' ? 'j' : 'n');

        // Add images
        if (!empty($vehicle['afbeeldingen'])) {
            $afbeeldingen = $voertuig->addChild('afbeeldingen');
            foreach ($vehicle['afbeeldingen'] as $image) {
                $afbeelding = $afbeeldingen->addChild('afbeelding');
                $afbeelding->addChild('bestandsnaam', basename($image));
            }
        }
    }

    // Format the XML with proper indentation
    $dom = new DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    // Save the XML file
    $xml_content = $dom->saveXML();
    $temp_file = LOCAL_XML_PATH . '.new';

    if (file_put_contents($temp_file, $xml_content)) {
        // If successful, replace the old file with the new one
        if (file_exists(LOCAL_XML_PATH)) {
            unlink(LOCAL_XML_PATH);
        }
        rename($temp_file, LOCAL_XML_PATH);
        return true;
    }

    return false;
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

        // Get the current XML data
        $xml = get_xml_data();
        if (!$xml) {
            error_log('Failed to get XML data');
            return false;
        }

        // Convert XML to array format
        $cars_data = [];
        foreach ($xml->voertuig as $car) {
            $cars_data[] = extract_car_data($car, get_image_base_url());
        }

        // Create new XML file with the data
        if (!create_new_xml_file($cars_data)) {
            error_log('Failed to create new XML file');
            return false;
        }

        // Update images and ensure all images exist
        fetch_images_from_ftp();
        ensure_all_images_exist();
        cleanup_unused_images();
        vwe_create_occasion_posts(); // Add this line
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
    if (needs_update()) {
        error_log('Update needed, running update_all_data()');
    update_all_data();
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

    // Log the number of vehicles found in XML
    $vehicle_count = count($xml->voertuig);
    error_log('Found ' . $vehicle_count . ' vehicles in XML data');

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

    // Add optimized CSS
    wp_add_inline_style('vwe-styles', '
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .modal-content {
            position: relative;
            background: #fff;
            margin: 20px auto;
            padding: 20px;
            width: 90%;
            max-width: 1200px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            animation: modalFadeIn 0.3s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-carousel {
            position: relative;
            margin-bottom: 20px;
        }

        .carousel-container {
            position: relative;
            overflow: hidden;
            border-radius: 4px;
            background: #f5f5f5;
        }

        .carousel-slides {
            display: flex;
            transition: transform 0.3s ease-out;
            will-change: transform;
        }

        .carousel-slide {
            flex: 0 0 100%;
            position: relative;
        }

        .carousel-slide img {
            width: 100%;
            height: auto;
            display: block;
            object-fit: cover;
            aspect-ratio: 16/9;
        }

        .carousel-prev,
        .carousel-next {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            padding: 10px 15px;
            cursor: pointer;
            font-size: 18px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .carousel-prev { left: 10px; }
        .carousel-next { right: 10px; }

        .carousel-prev:hover,
        .carousel-next:hover {
            background: rgba(0, 0, 0, 0.7);
        }

        .carousel-dots {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }

        .carousel-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ccc;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .carousel-dot.active {
            background: #333;
        }

        .modal-details {
            padding: 20px 0;
        }

        .modal-section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
        }

        .title-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border-bottom: 2px solid #eee;
            padding-bottom: 15px;
        }

        .title-content h3 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }

        .car-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-top: 5px;
        }

        .car-status.beschikbaar { background: #e6f4ea; color: #1e7e34; }
        .car-status.verkocht { background: #fbe9e7; color: #d32f2f; }
        .car-status.reserve { background: #fff3e0; color: #f57c00; }

        .price-tag {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }

        .specs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .spec-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .spec-label {
            color: #666;
            font-size: 14px;
        }

        .spec-value {
            font-weight: 500;
            color: #333;
        }

        .description-content {
            line-height: 1.6;
            color: #444;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .modal-action-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }

        .share-btn {
            background: #2196f3;
            color: white;
        }

        .share-btn:hover {
            background: #1976d2;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 10px auto;
                padding: 15px;
            }

            .title-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .specs-grid {
                grid-template-columns: 1fr;
            }

            .carousel-prev,
            .carousel-next {
                padding: 8px 12px;
                font-size: 16px;
            }
        }
    ');
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

// Add this at the end of the file, before the closing PHP tag
function handle_direct_links() {
    if (!isset($_GET['car'])) {
        return;
    }

    $car_id = sanitize_text_field($_GET['car']);
    error_log('Processing direct link for car ID: ' . $car_id);

    // Get XML data with error handling
    $xml = get_xml_data();
    if (!$xml) {
        error_log('Failed to load XML data for car details');
        return;
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
        error_log('Car not found: ' . $car_id);
        return;
    }

    // Output minimal HTML and JavaScript
    ?>
    <div id="carModal" class="modal" style="display: block;">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal()">&times;</button>
            <div class="modal-carousel">
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
            <div class="modal-details">
                <div class="modal-sections">
                    <div class="modal-section title-section">
                        <div class="title-content">
                            <h3><?php echo esc_html($car_data['titel'] ?: ($car_data['merk'] . ' ' . $car_data['model'])); ?></h3>
                            <div class="car-status <?php echo esc_attr($car_data['status']); ?>">
                                <?php echo strtoupper(esc_html($car_data['status'])); ?>
                            </div>
                        </div>
                        <div class="price-tag">
                            € <?php echo number_format((float)str_replace(',', '.', preg_replace('/[^0-9,]/i', '', $car_data['prijs'])), 0, ',', '.'); ?>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h4><i class="fas fa-info-circle"></i> Belangrijke Specificaties</h4>
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

                    <?php if (!empty($car_data['opmerkingen'])): ?>
                        <div class="modal-section">
                            <h4><i class="fas fa-align-left"></i> Beschrijving</h4>
                            <div class="description-content">
                                <?php echo wp_kses_post($car_data['opmerkingen']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-actions">
                <button class="modal-action-btn share-btn" onclick="shareCar()">
                    <i class="fas fa-share-alt"></i> Delen
                </button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        window.currentSlide = 0;
        window.totalSlides = <?php echo count($car_data['afbeeldingen']); ?>;
        document.body.style.overflow = 'hidden';
    });
    </script>
    <?php
}

// ... existing code ...

// Add rewrite rules for occasion detail pages
function vwe_add_rewrite_rules() {
    add_rewrite_rule(
        'occasions/([^/]+)/?$',
        'index.php?occasion=$matches[1]',
        'top'
    );
    add_rewrite_tag('%occasion%', '([^/]+)');
}
add_action('init', 'vwe_add_rewrite_rules');

// Register query vars
function vwe_query_vars($vars) {
    $vars[] = 'occasion';
    return $vars;
}
add_filter('query_vars', 'vwe_query_vars');

// ... existing code ...

/**
 * Create or update WordPress posts for each occasion
 */
function vwe_create_occasion_posts() {
    $xml = get_xml_data();
    if (!$xml) {
        error_log('Failed to get XML data for creating occasion posts');
        return false;
    }

    foreach ($xml->voertuig as $car) {
        $title = (string)$car->merk . ' ' . (string)$car->model;
        if ((string)$car->cilinder_inhoud) $title .= ' ' . (string)$car->cilinder_inhoud;
        if ((string)$car->transmissie) $title .= ' ' . (string)$car->transmissie;
        if ((string)$car->brandstof) $title .= ' ' . (string)$car->brandstof;
        if ((string)$car->aantal_deuren) $title .= ' ' . (string)$car->aantal_deuren . ' Deurs';
        $title .= ' NL Auto';

        $post_name = sanitize_title($title);

        // Check if post already exists
        $existing_post = get_page_by_path($post_name, OBJECT, 'occasion');

        if ($existing_post) {
            // Update existing post
            $post_id = $existing_post->ID;
            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $title,
                'post_content' => (string)$car->opmerkingen,
                'post_status' => 'publish'
            ));
        } else {
            // Create new post
            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_name' => $post_name,
                'post_content' => (string)$car->opmerkingen,
                'post_status' => 'publish',
                'post_type' => 'occasion'
            ));
        }

        if ($post_id) {
            // Update post meta
            update_post_meta($post_id, '_occasion_price', (string)$car->prijs);
            update_post_meta($post_id, '_occasion_year', (string)$car->bouwjaar);
            update_post_meta($post_id, '_occasion_mileage', (string)$car->kilometerstand);
            update_post_meta($post_id, '_occasion_fuel', (string)$car->brandstof);
            update_post_meta($post_id, '_occasion_transmission', (string)$car->transmissie);
            update_post_meta($post_id, '_occasion_power', (string)$car->vermogen);
            update_post_meta($post_id, '_occasion_doors', (string)$car->aantal_deuren);
            update_post_meta($post_id, '_occasion_color', (string)$car->kleur);
            update_post_meta($post_id, '_occasion_status', (string)$car->status);

            // Update gallery
            $gallery = array();
            if (isset($car->afbeeldingen) && isset($car->afbeeldingen->afbeelding)) {
                foreach ($car->afbeeldingen->afbeelding as $image) {
                    $gallery[] = get_image_base_url() . (string)$image;
                }
            }
            update_post_meta($post_id, '_occasion_gallery', $gallery);
        }
    }

    return true;
}

// ... existing code ...

/**
 * Register custom post type for occasions
 */
function vwe_register_post_types() {
    register_post_type('occasion', array(
        'labels' => array(
            'name' => 'Occasions',
            'singular_name' => 'Occasion',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Occasion',
            'edit_item' => 'Edit Occasion',
            'new_item' => 'New Occasion',
            'view_item' => 'View Occasion',
            'search_items' => 'Search Occasions',
            'not_found' => 'No occasions found',
            'not_found_in_trash' => 'No occasions found in Trash',
            'all_items' => 'All Occasions',
            'archives' => 'Occasion Archives',
            'insert_into_item' => 'Insert into occasion',
            'uploaded_to_this_item' => 'Uploaded to this occasion',
            'featured_image' => 'Featured Image',
            'set_featured_image' => 'Set featured image',
            'remove_featured_image' => 'Remove featured image',
            'use_featured_image' => 'Use as featured image',
            'menu_name' => 'Occasions',
            'filter_items_list' => 'Filter occasions list',
            'items_list_navigation' => 'Occasions list navigation',
            'items_list' => 'Occasions list',
        ),
        'public' => true,
        'has_archive' => true,
        'rewrite' => array('slug' => 'occasions'),
        'supports' => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
        'menu_icon' => 'dashicons-car',
        'show_in_rest' => true,
        'capability_type' => 'post',
        'hierarchical' => false,
        'menu_position' => 5,
        'can_export' => true,
        'delete_with_user' => false,
    ));

    // Flush rewrite rules on plugin activation
    if (get_option('vwe_flush_rewrite_rules', false)) {
        flush_rewrite_rules();
        delete_option('vwe_flush_rewrite_rules');
    }
}
add_action('init', 'vwe_register_post_types');

/**
 * Plugin activation hook
 */
function vwe_plugin_activation() {
    // Set flag to flush rewrite rules on next init
    update_option('vwe_flush_rewrite_rules', true);
}
register_activation_hook(__FILE__, 'vwe_plugin_activation');

/**
 * Template redirect for occasion detail pages
 */
function vwe_template_redirect() {
    global $wp_query;

    if (get_query_var('occasion')) {
        $car_id = get_query_var('occasion');

        // Get XML data
        $xml = get_xml_data();
        if (!$xml) {
            wp_redirect(home_url('/occasions/'));
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
            wp_redirect(home_url('/occasions/'));
            exit;
        }

        include(plugin_dir_path(__FILE__) . 'templates/occasion-detail.php');
        exit;
    }
}
add_action('template_redirect', 'vwe_template_redirect');

// ... existing code ...