<?php
/*
Plugin Name: VWE Auto Manager
Description: A plugin that advertises the cars from VWE. Includes integrated shortcodes: [vwe_latest_cars] for latest cars and [vwe_cheapest_cars] for cheapest cars.
Author: Anouar
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Increase execution time and memory limit
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '256M');

// Configuration - using admin settings only
define('VWE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VWE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DEBUG_MODE', false);
define('XML_CACHE_DURATION', 300); // 5 minutes cache for XML data

/**
 * Get FTP settings from admin
 */
function vwe_get_ftp_settings() {
    // Cache FTP settings to avoid repeated database calls
    static $cached_settings = null;

    if ($cached_settings !== null) {
        return $cached_settings;
    }

    try {
        $settings = get_option('vwe_ftp_settings', array());

        $cached_settings = array(
            'ftp_server' => $settings['ftp_server'] ?? '',
            'ftp_user' => $settings['ftp_user'] ?? '',
            'ftp_pass' => $settings['ftp_pass'] ?? '',
            'remote_images_path' => $settings['remote_images_path'] ?? '',
            'remote_image_http' => $settings['remote_image_http'] ?? '',
            'remote_xml_dir' => $settings['remote_xml_dir'] ?? ''
        );

        return $cached_settings;
    } catch (Exception $e) {
        error_log('Error in vwe_get_ftp_settings: ' . $e->getMessage());
        $cached_settings = array(
            'ftp_server' => '',
            'ftp_user' => '',
            'ftp_pass' => '',
            'remote_images_path' => '',
            'remote_image_http' => '',
            'remote_xml_dir' => ''
        );
        return $cached_settings;
    }
}

/**
 * Get remote XML directory from settings
 */
function vwe_get_remote_xml_dir() {
    $settings = vwe_get_ftp_settings();
    if (empty($settings['remote_xml_dir'])) {
        error_log('Remote XML directory not configured in admin settings');
        return false;
    }
    return $settings['remote_xml_dir'];
}

/**
 * Get remote images HTTP URL from settings
 */
function vwe_get_remote_images_url() {
    try {
        $settings = vwe_get_ftp_settings();
        if (empty($settings['remote_image_http'])) {
            error_log('Remote image HTTP URL not configured in admin settings');
            return false;
        }
        return $settings['remote_image_http'];
    } catch (Exception $e) {
        error_log('Error in vwe_get_remote_images_url: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get XML data using admin settings
 */
function get_xml_data() {
    try {
        // Check cache first
        $cached_xml = get_transient('vwe_xml_cache');
        if ($cached_xml !== false) {
            return $cached_xml;
        }

        // Get FTP settings
        $settings = vwe_get_ftp_settings();
        if (empty($settings['ftp_server']) || empty($settings['ftp_user']) || empty($settings['ftp_pass'])) {
            error_log('VWE: FTP settings incomplete - server: ' . ($settings['ftp_server'] ?: 'empty') . ', user: ' . ($settings['ftp_user'] ?: 'empty'));
            return false;
        }

        // Connect to FTP server
        $timeout = 30;
        $ftp_conn = @ftp_connect($settings['ftp_server'], 21, $timeout);
        if (!$ftp_conn) {
            error_log('VWE: Failed to connect to FTP server: ' . $settings['ftp_server']);
            return false;
        }

        if (!@ftp_login($ftp_conn, $settings['ftp_user'], $settings['ftp_pass'])) {
            error_log('VWE: Failed to login to FTP server with user: ' . $settings['ftp_user']);
            ftp_close($ftp_conn);
            return false;
        }

        ftp_pasv($ftp_conn, true);

        // Find XML file
        $remote_files = @ftp_nlist($ftp_conn, $settings['remote_xml_dir']);
        if (!$remote_files) {
            error_log('VWE: Failed to list files in directory: ' . $settings['remote_xml_dir']);
            ftp_close($ftp_conn);
            return false;
        }

        $xml_file_found = false;
        $remote_xml_path = '';

        foreach ($remote_files as $file) {
            $filename = basename($file);
            if (pathinfo($filename, PATHINFO_EXTENSION) === 'xml') {
                $remote_xml_path = $settings['remote_xml_dir'] . $filename;
                $xml_file_found = true;
                break;
            }
        }

        if (!$xml_file_found) {
            error_log('VWE: No XML file found in directory: ' . $settings['remote_xml_dir']);
            ftp_close($ftp_conn);
            return false;
        }

        // Download XML content directly to memory
        $xml_content = '';
        $temp_handle = fopen('php://temp', 'r+');

        if (@ftp_fget($ftp_conn, $temp_handle, $remote_xml_path, FTP_BINARY)) {
            rewind($temp_handle);
            $xml_content = stream_get_contents($temp_handle);
            fclose($temp_handle);
        } else {
            error_log('VWE: Failed to download XML file: ' . $remote_xml_path);
            fclose($temp_handle);
            ftp_close($ftp_conn);
            return false;
        }

        ftp_close($ftp_conn);

        if (empty($xml_content)) {
            error_log('VWE: Downloaded XML content is empty');
            return false;
        }

        // Parse XML content
        $xml = simplexml_load_string($xml_content);
        if (!$xml) {
            error_log('VWE: Failed to parse XML content');
            return false;
        }

        // Cache the XML data for 5 minutes
        set_transient('vwe_xml_cache', $xml, XML_CACHE_DURATION);

        return $xml;
    } catch (Exception $e) {
        error_log('VWE: Exception in get_xml_data: ' . $e->getMessage());
        return false;
    }
}

/**
 * Display car listing with improved performance
 */
function display_car_listing() {
    error_log('display_car_listing() called');

    $xml = get_xml_data();
    if (!$xml) {
        error_log('Failed to get XML data in display_car_listing');
        echo "<div class='error-message'>Error loading XML data. Please check the error logs for more information.</div>";
        return;
    }

    error_log('XML data loaded successfully, converting to array...');

    // Convert SimpleXMLElement to array
    $cars = [];
    foreach ($xml->voertuig as $car) {
        $cars[] = $car;
    }

    $total_items = count($cars);
    error_log('Total cars found: ' . $total_items);

    if ($total_items === 0) {
        error_log('No cars found in XML data');
        echo "<div class='error-message'>No cars found in the XML data.</div>";
        return;
    }

    error_log('Starting to display car listing with ' . $total_items . ' cars');

    $image_url_base = get_image_base_url();
    output_css_styles();

    // Add preloading for images
    output_image_preload($cars);

    echo '<div class="vwe-page-wrapper">';

    // Mobile filter toggle button
    echo '<button class="mobile-filter-toggle" id="mobileFilterToggle" type="button">
        <i class="fas fa-filter"></i>
        <span>Filters</span>
    </button>';

    // Filters overlay
    echo '<div class="filters-overlay" id="filtersOverlay"></div>';

    echo '<div class="main-content" style="display:flex; flex-direction:row; align-items:flex-start; gap:30px;">';

    // Filters panel links
    echo '<aside class="filters-panel" id="filtersPanel">';
    echo '<div class="filters-header">
        <h2>Filters & Sorteren</h2>
        <button class="close-filters" type="button">&times;</button>
    </div>';
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
    echo '<div class="result-count" id="resultCount">0 resultaten gevonden</div>';
    echo '<div class="dropdown-group">';
    echo '<div class="custom-select">
        <select id="sortSelect" class="filter-dropdown">
            <option value="">Sorteren op standaard</option>
            <option value="prijs-asc">Prijs (laag-hoog)</option>
            <option value="prijs-desc">Prijs (hoog-laag)</option>
            <option value="km-asc">Kilometerstand (laag-hoog)</option>
            <option value="km-desc">Kilometerstand (hoog-laag)</option>
            <option value="jaar-desc">Nieuwste eerst</option>
            <option value="jaar-asc">Oudste eerst</option>
        </select>
    </div>';
    echo '<div class="custom-select">
        <select id="showSelect" class="filter-dropdown">
            <option value="12">Toon 12</option>
            <option value="24">Toon 24</option>
            <option value="50">Toon 50</option>
            <option value="100">Toon 100</option>
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
    echo '</div>'; // Close cars-container

    // Add pagination controls - alleen tonen als er meer dan 9 auto's zijn
    if ($total_items > $cars_per_page) {
        echo '<div class="pagination-controls" id="paginationControls" style="display: none;">
            <button class="pagination-prev" onclick="changePage(-1)" disabled>Vorige</button>
            <div class="pagination-numbers">';

        // Show all page numbers
        for ($i = 1; $i <= $total_pages; $i++) {
            $active_class = $i === 1 ? ' active' : ''; // Mark first page as active initially
            echo '<button class="pagination-number' . $active_class . '" onclick="goToPage(' . $i . ')" data-page="' . $i . '">' . $i . '</button>';
        }

        echo '</div>
            <button class="pagination-next" onclick="changePage(1)">Volgende</button>
        </div>';
    }

    echo '</div></div>'; // Close main-content and vwe-page-wrapper

    // Add pagination JavaScript
    $allCarsJson = json_encode(array_map(function($car) use ($image_url_base) { return extract_car_data($car, $image_url_base); }, $cars));
    $js = <<<'JS'
<script>
let currentPage = 1;
let carsPerPage = 9; // Default value, will be updated by showSelect
let allCars = __ALL_CARS_JSON__;
let filteredCars = allCars.slice();
const totalItems = allCars.length;

// Utility functions
function safeParseFloat(value) {
    if (!value) return NaN;
    const cleaned = value.toString().replace(/[^0-9.,]/g, '').replace(',', '.');
    return parseFloat(cleaned);
}

function safeParseInt(value) {
    if (!value) return NaN;
    const cleaned = value.toString().replace(/[^0-9]/g, '');
    return parseInt(cleaned, 10);
}

function getElementSafely(id) {
    try {
        return document.getElementById(id);
    } catch (error) {
        console.error('Error getting element:', id, error);
        return null;
    }
}

function getElementValueSafely(id) {
    const element = getElementSafely(id);
    return element ? element.value : '';
}

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

    // Debug logging voor de eerste auto
    if (carData === allCars[0]) {
        console.log('checkFilters debug - first car:', carData.merk, carData.model);
        console.log('Filter values:', {
            brandFilter, modelFilter, fuelFilter, transmissionFilter,
            bodyFilter, doorsFilter, seatsFilter, priceMin, priceMax,
            kmMin, kmMax, powerMin, powerMax
        });
    }

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

    const result = (!brandFilter || carData.merk === brandFilter) &&
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

    // Debug logging voor de eerste auto
    if (carData === allCars[0]) {
        console.log('checkFilters result for first car:', result);
        console.log('Year match:', yearMatch, 'Status match:', statusMatch);
    }

    return result;
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
        // Bepaal SEO-vriendelijke slug op basis van titel of merk+model
        const slug = car.slug ? car.slug : (car.titel || `${car.merk} ${car.model}${car.cilinder_inhoud ? ' ' + car.cilinder_inhoud : ''}${car.transmissie ? ' ' + car.transmissie : ''}${car.brandstof ? ' ' + car.brandstof : ''}${car.deuren ? ' ' + car.deuren + ' Deurs' : ''}`)
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '') // verwijder speciale tekens
            .trim()
            .replace(/\s+/g, '-')        // spaties naar streepjes
            .replace(/-+/g, '-');         // dubbele streepjes samenvoegen
        card.innerHTML = `
            <div class="car-image">
                <img src="${car.eersteAfbeelding}" alt="${car.merk} ${car.model}" class="car-image" loading="lazy" decoding="async" style="cursor: pointer;" onclick="window.location.href='/occasions/${slug}/'">
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
                <a href="/occasions/${slug}/" class="view-button">BEKIJKEN <span class="arrow">→</span></a>
            </div>
        `;
        carsGrid.appendChild(card);
    });
}

function updatePagination() {
    const totalPages = Math.ceil(filteredCars.length / carsPerPage);
    const paginationControls = document.getElementById('paginationControls');

    // Hide pagination if there's only 1 page or no results or less than 10 cars
    if (totalPages <= 1 || filteredCars.length === 0 || filteredCars.length <= 9) {
        if (paginationControls) {
            paginationControls.style.display = 'none';
        }
        return;
    }

    // Show pagination if it was hidden and we have more than 9 cars
    if (paginationControls && filteredCars.length > 9) {
        paginationControls.style.display = 'flex';
    }

    // Update Previous/Next buttons
    const prevBtn = document.querySelector(".pagination-prev");
    const nextBtn = document.querySelector(".pagination-next");

    if (prevBtn) prevBtn.disabled = currentPage === 1;
    if (nextBtn) nextBtn.disabled = currentPage === totalPages || totalPages === 0;

    // Update page number buttons
    const pageButtons = document.querySelectorAll('.pagination-number');
    pageButtons.forEach(button => {
        const pageNum = parseInt(button.dataset.page);
        button.classList.toggle('active', pageNum === currentPage);
        button.disabled = pageNum === currentPage;
    });
}

function goToPage(page) {
    const totalPages = Math.ceil(filteredCars.length / carsPerPage);
    if (page < 1 || page > totalPages || totalPages <= 1 || filteredCars.length <= 9) return;
    currentPage = page;
    renderCars();
    updatePagination();
    document.getElementById("carsGrid").scrollIntoView({ behavior: "smooth" });
}

function changePage(direction) {
    const totalPages = Math.ceil(filteredCars.length / carsPerPage);
    const newPage = currentPage + direction;
    if (newPage < 1 || newPage > totalPages || totalPages <= 1 || filteredCars.length <= 9) return;
    currentPage = newPage;
    renderCars();
    updatePagination();
    document.getElementById("carsGrid").scrollIntoView({ behavior: "smooth" });
}

function applyFilters() {
    console.log('applyFilters called');
    console.log('allCars length:', allCars.length);

    filteredCars = allCars.filter(checkFilters);
    console.log('After filter - filteredCars length:', filteredCars.length);

    sortCars(); // Apply current sorting after filtering
    currentPage = 1;
    renderCars();
    updatePagination();
    updateResultsCount();
    console.log(`Total cars after filtering: ${filteredCars.length}`);
}

function sortCars() {
    try {
        const sortSelect = getElementSafely("sortSelect");
        if (!sortSelect) return;

        const sortValue = sortSelect.value;

        // If no sort value or default, maintain original order
        if (!sortValue || sortValue === "") {
            // Restore original order from allCars
            const carIds = allCars.map(car => car.id || car.kenteken || car.merk + car.model);
            filteredCars.sort((a, b) => {
                const aId = a.id || a.kenteken || a.merk + a.model;
                const bId = b.id || b.kenteken || b.merk + b.model;
                return carIds.indexOf(aId) - carIds.indexOf(bId);
            });
            return;
        }

        // Apply smart sorting first
        if (sortValue.startsWith('smart-')) {
            filteredCars = smartSort(filteredCars);
            return;
        }

        // Apply regular sorting
        const [sortBy, sortOrder] = sortValue.split("-");

        if (!sortBy || !sortOrder) {
            console.warn('Invalid sort value:', sortValue);
            return;
        }

        filteredCars.sort((a, b) => {
            try {
                let valA, valB;

                if (sortBy === "prijs") {
                    valA = safeParseFloat(a.prijs);
                    valB = safeParseFloat(b.prijs);
                } else if (sortBy === "km") {
                    valA = safeParseFloat(a.kilometerstand);
                    valB = safeParseFloat(b.kilometerstand);
                } else if (sortBy === "jaar") {
                    valA = safeParseInt(a.bouwjaar);
                    valB = safeParseInt(b.bouwjaar);
                } else {
                    return 0;
                }

                // Handle NaN values
                if (isNaN(valA)) valA = sortOrder === 'asc' ? Infinity : -Infinity;
                if (isNaN(valB)) valB = sortOrder === 'asc' ? Infinity : -Infinity;

                if (valA < valB) return sortOrder === "asc" ? -1 : 1;
                if (valA > valB) return sortOrder === "asc" ? 1 : -1;
                return 0;
            } catch (error) {
                console.error('Error in sort comparison:', error);
                return 0;
            }
        });
    } catch (error) {
        console.error('Error in sortCars:', error);
    }
}

function updateResultsCount() {
    const resultCountElement = document.getElementById("resultCount");
    if (resultCountElement) {
        const count = filteredCars.length;
        resultCountElement.textContent = count + (count === 1 ? " resultaat gevonden" : " resultaten gevonden");
    }
}

function changePage(direction) {
    const totalPages = Math.ceil(filteredCars.length / carsPerPage);
    const newPage = currentPage + direction;
    if (newPage < 1 || newPage > totalPages || totalPages <= 1) return;
    currentPage = newPage;
    renderCars();
    updatePagination();
    document.getElementById("carsGrid").scrollIntoView({ behavior: "smooth" });
}

function handleCheckboxGroupChange(checkbox, groupName) {
    const allCheckboxes = document.querySelectorAll(`input[name="${groupName}"]`);
    const allCheckbox = document.querySelector(`input[name="${groupName}"][value="all"]`);

    if (checkbox.value === "all") {
        // If "Alle" checkbox is clicked, uncheck all other checkboxes
        allCheckboxes.forEach(cb => {
            if (cb.value !== "all") {
                cb.checked = false;
            }
        });
    } else {
        // If a specific checkbox is clicked, uncheck the "Alle" checkbox
        if (allCheckbox) {
            allCheckbox.checked = false;
        }

        // If no specific checkboxes are checked, check the "Alle" checkbox
        const specificCheckboxes = Array.from(allCheckboxes).filter(cb => cb.value !== "all");
        const anySpecificChecked = specificCheckboxes.some(cb => cb.checked);

        if (!anySpecificChecked && allCheckbox) {
            allCheckbox.checked = true;
        }
    }

    // Apply filters after checkbox changes
    applyFilters();
}

// Deze functie wordt vervangen door de verbeterde versie verderop in de code

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

    document.querySelectorAll("input[name='year']").forEach(cb => {
        cb.addEventListener("change", function() {
            handleCheckboxGroupChange(this, 'year');
        });
    });
    document.querySelectorAll("input[name='status']").forEach(cb => {
        cb.addEventListener("change", function() {
            handleCheckboxGroupChange(this, 'status');
        });
    });
    const resetButton = document.getElementById("resetFilters");
    if (resetButton) resetButton.addEventListener("click", resetAllFilters);
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
                     decoding="async">
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

// Verbeterde resetAllFilters functie
function resetAllFilters() {
    console.log('resetAllFilters called');

    // Reset alle select dropdowns
    document.querySelectorAll("select").forEach(select => {
        select.value = "";
        console.log('Reset select:', select.id, 'to empty');
    });

    // Reset alle number inputs
    document.querySelectorAll("input[type='number']").forEach(input => {
        input.value = "";
        console.log('Reset number input:', input.id, 'to empty');
    });

    // Reset alle checkboxes naar "all" status
    document.querySelectorAll("input[type='checkbox']").forEach(checkbox => {
        checkbox.checked = checkbox.value === "all";
        console.log('Reset checkbox:', checkbox.name, checkbox.value, 'to checked:', checkbox.checked);
    });

    // Reset sort en show selects naar standaard waarden
    const sortSelect = document.getElementById("sortSelect");
    const showSelect = document.getElementById("showSelect");
    if (sortSelect) {
        sortSelect.value = "";
        console.log('Reset sortSelect to empty');
    }
    if (showSelect) {
        showSelect.value = "12";
        console.log('Reset showSelect to 12');
    }

    // Reset model filter opties
    const modelFilter = document.getElementById("modelFilter");
    if (modelFilter) {
        modelFilter.innerHTML = '<option value="">Alle Modellen</option>';
        console.log('Reset modelFilter options');
    }

    console.log('Before applyFilters - allCars length:', allCars.length);
    console.log('Before applyFilters - filteredCars length:', filteredCars.length);

    // Apply filters using the correct function
    applyFilters();

    console.log('After applyFilters - filteredCars length:', filteredCars.length);
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
                <img src="${car.eersteAfbeelding}" alt="${car.merk} ${car.model}" class="car-image" loading="lazy" decoding="async" style="cursor: pointer;" onclick="window.location.href='/occasions/${slug}/'">
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
                <a href="/occasions/${slug}/" class="view-button">BEKIJKEN <span class="arrow">→</span></a>
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
    // First check if we have an override
    // Check if images exist in the correct xml/images directory
    $xml_images_path = WP_CONTENT_DIR . '/plugins/VWE-auto-manager/xml/images/';
    if (is_dir($xml_images_path)) {
        return content_url('plugins/VWE-auto-manager/xml/images/');
    }

    // Check if images exist in plugin directory
    $plugin_images_path = VWE_PLUGIN_DIR . 'images/';
    if (is_dir($plugin_images_path)) {
        return VWE_PLUGIN_URL . 'images/';
    }

    // Fallback to remote images URL from settings
    $settings = vwe_get_ftp_settings();
    return $settings['remote_image_http'] ?? VWE_PLUGIN_URL . 'images/';
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
        'prijs' => (function($car) use ($clean_value) {
            // 1️⃣ Newest structure: nested bedrag in eerste <prijs>
            if (isset($car->verkoopprijs_particulier->prijzen->prijs[0]->bedrag) &&
                (string)$car->verkoopprijs_particulier->prijzen->prijs[0]->bedrag !== '') {
                return $clean_value($car->verkoopprijs_particulier->prijzen->prijs[0]->bedrag, 'Prijs op aanvraag');
            }

            // 2️⃣ Legacy: scalar inhoud zonder children
            if (isset($car->verkoopprijs_particulier) && !isset($car->verkoopprijs_particulier->prijzen)) {
                $scalar = trim((string)$car->verkoopprijs_particulier);
                if ($scalar !== '') {
                    return $clean_value($scalar, 'Prijs op aanvraag');
                }
            }

            // 3️⃣ Fallback via xpath (dekt onverwachte structuren)
            $xpathResult = $car->xpath('verkoopprijs_particulier//bedrag');
            if ($xpathResult && isset($xpathResult[0]) && (string)$xpathResult[0] !== '') {
                return $clean_value($xpathResult[0], 'Prijs op aanvraag');
            }

            return 'Prijs op aanvraag';
        })($car),
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
        'opties' => $get_xml_value($car, 'opties', ''),
        'afbeeldingen' => [],
        'carrosserie' => $get_xml_value($car, 'carrosserie', 'Onbekend')
    ];

    // Bouw titel exact zoals create_occasion_posts doet
    $slug_title = $data['merk'] . ' ' . $data['model'];
    if ($data['cilinder_inhoud'] !== 'Onbekend') $slug_title .= ' ' . $data['cilinder_inhoud'];
    if ($data['transmissie'] !== 'Onbekend') $slug_title .= ' ' . $data['transmissie'];
    if ($data['brandstof'] !== 'Onbekend') $slug_title .= ' ' . $data['brandstof'];
    if ($data['deuren'] !== 'Onbekend') $slug_title .= ' ' . $data['deuren'] . ' Deurs';
    $slug_title .= ' NL Auto';

    if (function_exists('sanitize_title')) {
        $data['slug'] = sanitize_title($slug_title);
    } else {
        $data['slug'] = strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', $slug_title)));
    }

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

    // Collect images (robust: try all case/ext variants)
    if (isset($car->afbeeldingen) && isset($car->afbeeldingen->afbeelding)) {
        foreach ($car->afbeeldingen->afbeelding as $afbeelding) {
            $url = isset($afbeelding->url) ? trim((string)$afbeelding->url) : '';
            $bestandsnaam = isset($afbeelding->bestandsnaam) ? trim((string)$afbeelding->bestandsnaam) : '';

            if ($url !== '') {
                $data['afbeeldingen'][] = $url;
            } elseif ($bestandsnaam !== '') {
                // Try all case and extension combinations
                $name_no_ext = preg_replace('/\.[a-zA-Z0-9]+$/', '', $bestandsnaam);
                $extensions = ['jpg', 'jpeg', 'png', 'gif', 'JPG', 'JPEG', 'PNG', 'GIF'];
                $variants = [];
                foreach ($extensions as $ext) {
                    $variants[] = $name_no_ext . '.' . $ext;
                    $variants[] = strtolower($name_no_ext) . '.' . $ext;
                    $variants[] = strtoupper($name_no_ext) . '.' . $ext;
                    $variants[] = ucfirst(strtolower($name_no_ext)) . '.' . $ext;
                }
                $variants[] = $bestandsnaam;
                $variants = array_unique($variants);

                // Use remote image URL directly
                $settings = vwe_get_ftp_settings();
                $data['afbeeldingen'][] = $settings['remote_image_http'] . strtolower($name_no_ext) . '.jpg';
        }
    }
    }
    $data['eersteAfbeelding'] = empty($data['afbeeldingen']) ? $image_url_base . 'placeholder.jpg' : $data['afbeeldingen'][0];

    return $data;
}

/**
 * Display a single car card
 */
function display_car_card($car, $extra_class = '') {
    // Generate unique identifier for the car
    $car_id = strtolower(str_replace(' ', '-', $car['merk'] . '-' . $car['model'] . '-' . $car['kenteken']));

    // Calculate formatted price once
    $price_raw = isset($car['prijs']) ? $car['prijs'] : '';
    $price_numeric = preg_replace('/[^0-9]/', '', $price_raw);
    $formatted_price = ($price_numeric === '' || !is_numeric($price_numeric))
        ? 'Op aanvraag'
        : '€ ' . number_format((int)$price_numeric, 0, ',', '.');

    // Ensure proper JSON encoding
    $jsonData = json_encode($car, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

    // Use the titel field from XML for the car card title
    $detailed_title = $car['titel'];

    // Gebruik vooraf berekende slug uit de array (gelijk aan sanitize_title)
    $url_title = $car['slug'];

    $class_attr = 'car-card' . ($extra_class ? ' ' . $extra_class : '');

    $image_html = '';
    if (strpos($extra_class, 'big-card') !== false) {
        // For mini plugins: make image a link
        $image_html = '<a href="/occasions/' . $url_title . '/"><img src="' . esc_url($car['eersteAfbeelding']) . '" alt="' . esc_attr($car['merk'] . ' ' . $car['model']) . '" loading="lazy" decoding="async"></a>';
    } else {
        // For normal cards: just the image
        $image_html = '<img src="' . esc_url($car['eersteAfbeelding']) . '" alt="' . esc_attr($car['merk'] . ' ' . $car['model']) . '" loading="lazy" decoding="async">';
    }

    echo '<div class="' . $class_attr . '" data-car=\'' . $jsonData . '\' data-car-id="' . $car_id . '">
        <div class="car-image">
            ' . $image_html . '
            <div class="car-badges">
                <span class="status-badge ' . esc_attr($car['status']) . '">' .
                    ($car['status'] === "beschikbaar" ? "AVAILABLE" : ($car['status'] === "verkocht" ? "VERKOCHT" : "GERESERVEERD")) .
                '</span>
                <span class="year-badge">' . esc_html($car['bouwjaar']) . '</span>
            </div>
        </div>
        <div class="car-info">
            <div class="car-brand">' . strtoupper($car['merk']) . '</div>
            <h3 class="car-title">' . htmlspecialchars($detailed_title) . '</h3>
            <div class="car-price">' . $formatted_price . '</div>
            <div class="car-specs">
                <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/Tank.svg" alt="Kilometerstand" width="18" style="vertical-align:middle;margin-right:4px;">' . $car['kilometerstand'] . '</span>
                <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/pK.svg" alt="Vermogen" width="18" style="vertical-align:middle;margin-right:4px;">' . $car['vermogen'] . '</span>
            </div>
            <button type="button" class="view-button" onclick="window.location.href=\'/occasions/' . $url_title . '/\'">
                BEKIJKEN <span class="arrow">→</span>
            </button>
        </div>
    </div>';
}

/**
 * Output CSS styles
 */
function output_css_styles() {
    // Stylesheet wordt via wp_enqueue_style geladen; alleen kleine globale offset hier.
    echo '<style>.vwe-page-wrapper{margin-top:160px!important}.top-nav{margin-top:160px!important}#brx-footer,#brx-footer .brxe-section{padding-left:0!important;padding-right:0!important}</style>';
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











// Check if fixXmlEntities is not available (this file may be standalone)
if (!function_exists('fixXmlEntities')) {
    function fixXmlEntities($xmlContent) {
        $map = [
            '&euro;' => '€',
            '&pound;' => '£',
            '&dollar;' => '$',
            '&yen;' => '¥',
            '&cent;' => '¢',
            '&copy;' => '©',
            '&reg;' => '®',
            '&trade;' => '™',
            '&nbsp;' => ' ',
            '&ndash;' => '–',
            '&mdash;' => '—',
            '&lsquo;' => "'",
            '&rsquo;' => "'",
            '&ldquo;' => '"',
            '&rdquo;' => '"',
            '&hellip;' => '…'
        ];
        return str_replace(array_keys($map), array_values($map), $xmlContent);
    }
}

/**
 * Preload images for better performance
 */
function output_image_preload($cars) {
    echo '<link rel="preload" as="image" href="' . get_image_base_url() . 'placeholder.jpg" fetchpriority="high">';
    $preloaded = [];
    $count = 0;
    foreach ($cars as $car) {
        if ($count >= 30) break; // only current batch
        $img = isset($car['eersteAfbeelding']) ? $car['eersteAfbeelding'] : '';
        if ($img && !in_array($img, $preloaded)) {
                    $priority = $count < 3 ? 'high' : 'low';
            echo '<link rel="preload" as="image" href="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" fetchpriority="' . $priority . '">';
            $preloaded[] = $img;
                    $count++;
        }
    }
}

// Voeg WordPress hooks toe
add_action('init', 'vwe_init');
add_action('wp_enqueue_scripts', 'vwe_enqueue_scripts');
add_shortcode('vwe_auto_listing', 'vwe_display_car_listing');
add_shortcode('vwe_debug_xml', 'vwe_debug_xml_data');
add_shortcode('vwe_debug_images', 'vwe_debug_images');

/**
 * Initialize plugin
 */
function vwe_init() {
    // Plugin initialization - no longer creating local directories
    // All data is now fetched from remote FTP server via admin settings
}

/**
 * Enqueue scripts and styles
 */
function vwe_enqueue_scripts() {
    // Laad alleen wanneer nodig.
    if ( ! vwe_should_load_assets() ) {
        return;
    }

    wp_enqueue_style(
        'vwe-styles',
        plugins_url('styling.css', __FILE__),
        array(),
        filemtime(VWE_PLUGIN_DIR . 'styling.css')
    );

    wp_enqueue_script(
        'vwe-scripts',
        plugins_url('js/scripts.js', __FILE__),
        array('jquery'),
        filemtime(VWE_PLUGIN_DIR . 'js/scripts.js'),
        true
    );

    // Mobile filters script
    wp_enqueue_script(
        'vwe-mobile-filters',
        plugins_url('assets/js/vwe-mobile-filters.js', __FILE__),
        array(),
        filemtime(VWE_PLUGIN_DIR . 'assets/js/vwe-mobile-filters.js'),
        true
    );

    // Geen inline CSS meer om conflicts met thema's/builders te voorkomen.
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

add_filter('template_include', function($template) {
    if (is_post_type_archive('occasion')) {
        $archive_tpl = VWE_PLUGIN_DIR . 'templates/occasion-archive.php';
        if (file_exists($archive_tpl)) {
            return $archive_tpl;
        }
    }
    return $template;
});
// ... existing code ...

/**
 * Bepaal of de plugin assets op de huidige request geladen moeten worden.
 * – Laadt niet in wp-admin (behalve AJAX)
 * – Laadt alleen op occasion archive / single of wanneer de shortcode in de content staat.
 */
function vwe_should_load_assets() {
    // Niet in wp-admin / Bricks builder iframe.
    if (is_admin() && !wp_doing_ajax()) {
        return false;
    }

    // Skip wanneer Bricks builder preview open staat (?bricks=preview)
    if (isset($_GET['bricks']) && $_GET['bricks'] === 'preview') {
        return false;
    }

    // Occasion archive of single.
    if (is_post_type_archive('occasion') || is_singular('occasion')) {
        return true;
    }

    // Pagina/post met de shortcode
    if (is_singular()) {
        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'vwe_auto_listing')) {
            return true;
        }
    }

    return false;
}

/**
 * Debug function to test XML data loading
 */
function vwe_debug_xml_data($atts) {
    error_log('vwe_debug_xml_data() called');

    $xml = get_xml_data();
    if (!$xml) {
        return '<div style="color: red; padding: 20px; border: 1px solid red;">Failed to load XML data</div>';
    }

    $cars = [];
    foreach ($xml->voertuig as $car) {
        $cars[] = $car;
    }

    $output = '<div style="padding: 20px; border: 1px solid #ccc; margin: 20px;">';
    $output .= '<h3>XML Debug Info</h3>';
    $output .= '<p><strong>Total cars found:</strong> ' . count($cars) . '</p>';

    if (count($cars) > 0) {
        $output .= '<h4>First 3 cars:</h4>';
        $output .= '<ul>';
        for ($i = 0; $i < min(3, count($cars)); $i++) {
            $car = $cars[$i];
            $output .= '<li>';
            $output .= '<strong>Merk:</strong> ' . htmlspecialchars((string)$car->merk) . '<br>';
            $output .= '<strong>Model:</strong> ' . htmlspecialchars((string)$car->model) . '<br>';
            $output .= '<strong>Bouwjaar:</strong> ' . htmlspecialchars((string)$car->bouwjaar) . '<br>';
            $output .= '<strong>Prijs:</strong> €' . htmlspecialchars((string)$car->prijs) . '<br>';
            $output .= '</li>';
        }
        $output .= '</ul>';
    }

    $output .= '</div>';

    return $output;
}

/**
 * Debug function to test image locations
 */
function vwe_debug_images($atts) {
    $output = '<div style="padding: 20px; border: 1px solid #ccc; margin: 20px;">';
    $output .= '<h3>Image Debug Info</h3>';

    // Test different image locations
    $locations = [
        'Plugin Directory' => VWE_PLUGIN_DIR . 'images/',
        'Image URL Base' => get_image_base_url(),
        'Plugin URL' => VWE_PLUGIN_URL . 'images/'
    ];

    $output .= '<h4>Image Locations:</h4>';
    $output .= '<ul>';
    foreach ($locations as $name => $path) {
        $exists = is_dir($path) ? 'EXISTS' : 'NOT FOUND';
        $output .= '<li><strong>' . $name . ':</strong> ' . htmlspecialchars($path) . ' - <span style="color: ' . (is_dir($path) ? 'green' : 'red') . ';">' . $exists . '</span></li>';
    }
    $output .= '</ul>';

    // Test if we can find any images
    $plugin_images = glob(VWE_PLUGIN_DIR . 'images/*.{jpg,jpeg,png,gif,JPG,JPEG,PNG,GIF}', GLOB_BRACE);

    $output .= '<h4>Found Images:</h4>';
    $output .= '<p><strong>Plugin directory:</strong> ' . count($plugin_images) . ' images</p>';

    if (count($plugin_images) > 0) {
        $output .= '<p><strong>First 3 plugin images:</strong></p><ul>';
        for ($i = 0; $i < min(3, count($plugin_images)); $i++) {
            $output .= '<li>' . htmlspecialchars(basename($plugin_images[$i])) . '</li>';
        }
        $output .= '</ul>';
    }

    $output .= '</div>';

    return $output;
}

// ============================================================================
// INTEGRATED MINI PLUGINS FUNCTIONALITY
// ============================================================================

/**
 * Shortcode om drie nieuwste occasions te tonen.
 * Gebruik: [vwe_latest_cars]
 */
function vwe_latest_cars_shortcode() {
    // Controleer of de hoofdplugin functies beschikbaar zijn
    if ( ! function_exists( 'get_xml_data' ) || ! function_exists( 'extract_car_data' ) || ! function_exists( 'get_image_base_url' ) ) {
        return '<p>VWE Auto Manager plugin is niet geactiveerd.</p>';
    }

    $xml = get_xml_data();
    if ( ! $xml ) {
        return '<p>Geen voertuigen beschikbaar.</p>';
    }

    // Zet voertuigen in array en sorteer op bouwjaar (nieuwste eerst)
    $cars = [];
    foreach ( $xml->voertuig as $car ) {
        $cars[] = $car;
    }

    usort( $cars, function ( $a, $b ) {
        return intval( $b->bouwjaar ) <=> intval( $a->bouwjaar );
    } );

    $cars = array_slice( $cars, 0, 3 );
    $image_base = get_image_base_url();

    ob_start();

    // Laad CSS direct in de output
    $css_url = plugin_dir_url(__FILE__) . 'styling.css';
    echo '<link rel="stylesheet" href="' . $css_url . '" type="text/css" media="all" />';

    // Header met titel en knop
    $occasions_url = htmlspecialchars('/occasions');
    echo '<div class="vwe-cards-header">';
    echo '<h2 class="vwe-cards-title">Laatste occasions</h2>';
    echo '<a class="vwe-cheapest-cars-btn" href="' . $occasions_url . '">Bekijk alle occasions <span>&rarr;</span></a>';
    echo '</div>';
    echo '<div class="vwe-latest-cars">';
    echo '<div class="cars-grid">';
    foreach ( $cars as $carNode ) {
        $car_arr = extract_car_data( $carNode, $image_base );
        display_car_card( $car_arr, 'big-card' );
    }
    echo '</div></div>';
    return ob_get_clean();
}
add_shortcode( 'vwe_latest_cars', 'vwe_latest_cars_shortcode' );

/**
 * Shortcode om drie goedkoopste occasions te tonen.
 * Gebruik: [vwe_cheapest_cars]
 */
function vwe_cheapest_cars_shortcode() {
    // Controleer of de hoofdplugin functies beschikbaar zijn
    if ( ! function_exists( 'get_xml_data' ) || ! function_exists( 'extract_car_data' ) || ! function_exists( 'get_image_base_url' ) ) {
        return '<p>VWE Auto Manager plugin is niet geactiveerd.</p>';
    }

    $xml = get_xml_data();
    if ( ! $xml ) {
        return '<p>Geen voertuigen beschikbaar.</p>';
    }

    // Zet voertuigen in array en filter op beschikbare auto's met geldige prijzen
    $cars = [];

    foreach ( $xml->voertuig as $car ) {
        // Controleer of auto beschikbaar is (niet verkocht of gereserveerd)
        $verkocht = (string)$car->verkocht === 'j';
        $gereserveerd = (string)$car->gereserveerd === 'j';

        if ($verkocht || $gereserveerd) {
            continue; // Skip verkochte of gereserveerde auto's
        }

        // Haal prijs op
        $price = '';

        // Probeer verschillende prijsvelden
        if (isset($car->verkoopprijs_particulier->prijzen->prijs[0]->bedrag) &&
            (string)$car->verkoopprijs_particulier->prijzen->prijs[0]->bedrag !== '') {
            $price = (string)$car->verkoopprijs_particulier->prijzen->prijs[0]->bedrag;
        } elseif (isset($car->verkoopprijs_particulier) && !isset($car->verkoopprijs_particulier->prijzen)) {
            $price = trim((string)$car->verkoopprijs_particulier);
        } else {
            $xpathResult = $car->xpath('verkoopprijs_particulier//bedrag');
            if ($xpathResult && isset($xpathResult[0]) && (string)$xpathResult[0] !== '') {
                $price = (string)$xpathResult[0];
            }
        }

        // Controleer of prijs geldig is (numeriek en groter dan 0)
        $price_numeric = intval(preg_replace('/[^0-9]/', '', $price));

        if ($price_numeric > 0) {
            $cars[] = $car;
        }
    }

    // Sorteer op prijs (goedkoopste eerst)
    usort( $cars, function ( $a, $b ) {
        $price_a = intval(preg_replace('/[^0-9]/', '', (string)$a->verkoopprijs_particulier));
        $price_b = intval(preg_replace('/[^0-9]/', '', (string)$b->verkoopprijs_particulier));
        return $price_a <=> $price_b;
    } );

    // Neem de 3 goedkoopste
    $cars = array_slice( $cars, 0, 3 );
    $image_base = get_image_base_url();

    ob_start();

    // Laad CSS direct in de output
    $css_url = plugin_dir_url(__FILE__) . 'styling.css';
    echo '<link rel="stylesheet" href="' . $css_url . '" type="text/css" media="all" />';

    echo '<div class="vwe-cheapest-cars">';

    // Titel en knop boven de cards
    $occasions_url = '/occasions';
    echo '<div class="vwe-cheapest-cars-header">';
    echo '<h2 class="vwe-cheapest-cars-title">Scherp geprijsd</h2>';
    echo '<a class="vwe-cheapest-cars-btn" href="' . htmlspecialchars($occasions_url) . '">Bekijk alle occasions <span>&rarr;</span></a>';
    echo '</div>';

    echo '<div class="cars-grid">';
    foreach ( $cars as $carNode ) {
        $car_arr = extract_car_data( $carNode, $image_base );
        display_car_card( $car_arr, 'big-card' );
    }
    echo '</div></div>';
    return ob_get_clean();
}
add_shortcode( 'vwe_cheapest_cars', 'vwe_cheapest_cars_shortcode' );

/**
 * Debug shortcode om te testen of de plugin correct werkt
 * Gebruik: [vwe_debug_test]
 */
function vwe_debug_test_shortcode() {
    try {
        $output = '<div style="background: #f0f0f0; padding: 20px; margin: 20px; border: 1px solid #ccc;">';
        $output .= '<h3>VWE Plugin Debug Test</h3>';

        // Test basis functies
        $output .= '<h4>Basis functies:</h4>';
        $output .= '<ul>';
        $output .= '<li>get_xml_data(): ' . (function_exists('get_xml_data') ? '✅ Beschikbaar' : '❌ Niet beschikbaar') . '</li>';
        $output .= '<li>extract_car_data(): ' . (function_exists('extract_car_data') ? '✅ Beschikbaar' : '❌ Niet beschikbaar') . '</li>';
        $output .= '<li>get_image_base_url(): ' . (function_exists('get_image_base_url') ? '✅ Beschikbaar' : '❌ Niet beschikbaar') . '</li>';
        $output .= '<li>display_car_card(): ' . (function_exists('display_car_card') ? '✅ Beschikbaar' : '❌ Niet beschikbaar') . '</li>';
        $output .= '</ul>';

        // Test XML data
        if (function_exists('get_xml_data')) {
            $xml = get_xml_data();
            if ($xml) {
                $car_count = count($xml->voertuig);
                $output .= '<h4>XML Data:</h4>';
                $output .= '<p>✅ XML geladen - ' . $car_count . ' voertuigen gevonden</p>';

                if ($car_count > 0) {
                    $first_car = $xml->voertuig[0];
                    $output .= '<p><strong>Eerste voertuig:</strong></p>';
                    $output .= '<ul>';
                    $output .= '<li>Merk: ' . htmlspecialchars((string)$first_car->merk) . '</li>';
                    $output .= '<li>Model: ' . htmlspecialchars((string)$first_car->model) . '</li>';
                    $output .= '<li>Bouwjaar: ' . htmlspecialchars((string)$first_car->bouwjaar) . '</li>';
                    $output .= '</ul>';
                }
            } else {
                $output .= '<h4>XML Data:</h4>';
                $output .= '<p>❌ Kon XML data niet laden</p>';
            }
        }

        // Test image base URL
        if (function_exists('get_image_base_url')) {
            $image_base = get_image_base_url();
            $output .= '<h4>Image Base URL:</h4>';
            $output .= '<p>' . htmlspecialchars($image_base) . '</p>';
        }

        $output .= '</div>';
        return $output;
    } catch (Exception $e) {
        $error_output = '<div style="background: #ffebee; padding: 20px; margin: 20px; border: 1px solid #f44336;">';
        $error_output .= '<h3>❌ Debug Test Error</h3>';
        $error_output .= '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        $error_output .= '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
        $error_output .= '<p><strong>Line:</strong> ' . htmlspecialchars($e->getLine()) . '</p>';
        $error_output .= '</div>';
        return $error_output;
    }
}
add_shortcode( 'vwe_debug_test', 'vwe_debug_test_shortcode' );

// Admin menu en instellingen
add_action('admin_menu', 'vwe_admin_menu');
add_action('admin_init', 'vwe_settings_init');

/**
 * Voeg admin menu toe
 */
function vwe_admin_menu() {
    add_menu_page(
        'VWE Auto Manager',
        'VWE Auto Manager',
        'manage_options',
        'vwe-auto-manager',
        'vwe_settings_page',
        'dashicons-car',
        30
    );
}

/**
 * Initialiseer instellingen
 */
function vwe_settings_init() {
    register_setting('vwe_settings', 'vwe_ftp_settings');
}

/**
 * Settings page callback
 */
function vwe_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['submit'])) {
        vwe_save_ftp_settings();
    }

    $settings = get_option('vwe_ftp_settings', array());
    ?>
    <div class="wrap">
        <h1>VWE Auto Manager - FTP Instellingen</h1>

        <form method="post" action="">
            <?php wp_nonce_field('vwe_ftp_settings', 'vwe_ftp_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">FTP Server</th>
                    <td>
                        <input type="text" name="vwe_ftp_settings[ftp_server]"
                               value="<?php echo esc_attr($settings['ftp_server'] ?? ''); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">FTP Gebruikersnaam</th>
                    <td>
                        <input type="text" name="vwe_ftp_settings[ftp_user]"
                               value="<?php echo esc_attr($settings['ftp_user'] ?? ''); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">FTP Wachtwoord</th>
                    <td>
                        <input type="password" name="vwe_ftp_settings[ftp_pass]"
                               value="<?php echo esc_attr($settings['ftp_pass'] ?? ''); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Remote Images Pad</th>
                    <td>
                        <input type="text" name="vwe_ftp_settings[remote_images_path]"
                               value="<?php echo esc_attr($settings['remote_images_path'] ?? ''); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                                <tr>
                    <th scope="row">Remote Image HTTP URL</th>
                    <td>
                        <input type="text" name="vwe_ftp_settings[remote_image_http]"
                               value="<?php echo esc_attr($settings['remote_image_http'] ?? ''); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Remote XML Directory</th>
                    <td>
                        <input type="text" name="vwe_ftp_settings[remote_xml_dir]"
                               value="<?php echo esc_attr($settings['remote_xml_dir'] ?? ''); ?>"
                               class="regular-text" />
                        <p class="description">Directory containing the XML file (e.g., /path/to/xml/)</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="Instellingen Opslaan">
            </p>
        </form>

        <hr>

        <h2>Test FTP Verbinding</h2>
        <p>Klik op de knop hieronder om de FTP verbinding te testen:</p>
        <button type="button" id="test-ftp" class="button button-secondary">Test FTP Verbinding</button>
        <div id="ftp-test-result"></div>

        <hr>

        <h2>Handmatige Update</h2>
        <p>Klik op de knop hieronder om handmatig alle data bij te werken:</p>
        <button type="button" id="manual-update" class="button button-secondary">Handmatige Update</button>
        <div id="update-result"></div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#test-ftp').click(function() {
            var button = $(this);
            var resultDiv = $('#ftp-test-result');

            button.prop('disabled', true).text('Testen...');
            resultDiv.html('<p>FTP verbinding testen...</p>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'vwe_test_ftp',
                    nonce: '<?php echo wp_create_nonce('vwe_test_ftp'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<p style="color: green;">✓ FTP verbinding succesvol!</p>');
                    } else {
                        resultDiv.html('<p style="color: red;">✗ FTP verbinding mislukt: ' + response.data + '</p>');
                    }
                },
                error: function() {
                    resultDiv.html('<p style="color: red;">✗ Er is een fout opgetreden bij het testen van de FTP verbinding.</p>');
                },
                complete: function() {
                    button.prop('disabled', false).text('Test FTP Verbinding');
                }
            });
        });

        $('#manual-update').click(function() {
            var button = $(this);
            var resultDiv = $('#update-result');

            button.prop('disabled', true).text('Updaten...');
            resultDiv.html('<p>Data bijwerken...</p>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'vwe_manual_update',
                    nonce: '<?php echo wp_create_nonce('vwe_manual_update'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<p style="color: green;">✓ Update succesvol voltooid!</p>');
                    } else {
                        resultDiv.html('<p style="color: red;">✗ Update mislukt: ' + response.data + '</p>');
                    }
                },
                error: function() {
                    resultDiv.html('<p style="color: red;">✗ Er is een fout opgetreden bij het updaten.</p>');
                },
                complete: function() {
                    button.prop('disabled', false).text('Handmatige Update');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Sla FTP instellingen op
 */
function vwe_save_ftp_settings() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!wp_verify_nonce($_POST['vwe_ftp_nonce'], 'vwe_ftp_settings')) {
        wp_die('Security check failed');
    }

    if (isset($_POST['vwe_ftp_settings'])) {
        $settings = array_map('sanitize_text_field', $_POST['vwe_ftp_settings']);
        update_option('vwe_ftp_settings', $settings);

        // Toon succes bericht
        echo '<div class="notice notice-success is-dismissible"><p>FTP instellingen succesvol opgeslagen!</p></div>';
    }
}



// AJAX handlers
add_action('wp_ajax_vwe_test_ftp', 'vwe_test_ftp_ajax');
add_action('wp_ajax_vwe_manual_update', 'vwe_manual_update_ajax');

/**
 * Test FTP verbinding via AJAX
 */
function vwe_test_ftp_ajax() {
    check_ajax_referer('vwe_test_ftp', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_die('Geen toegang');
    }

    $settings = vwe_get_ftp_settings();

    // Test FTP verbinding
    $timeout = 30;
    $ftp_conn = @ftp_connect($settings['ftp_server'], 21, $timeout);

    if (!$ftp_conn) {
        wp_send_json_error('Kan geen verbinding maken met FTP server: ' . $settings['ftp_server']);
        return;
    }

    if (!@ftp_login($ftp_conn, $settings['ftp_user'], $settings['ftp_pass'])) {
        ftp_close($ftp_conn);
        wp_send_json_error('FTP login mislukt voor gebruiker: ' . $settings['ftp_user']);
        return;
    }

    ftp_close($ftp_conn);
    wp_send_json_success('FTP verbinding succesvol!');
}

/**
 * Handmatige update via AJAX
 */
function vwe_manual_update_ajax() {
    check_ajax_referer('vwe_manual_update', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_die('Geen toegang');
    }

    try {
        update_all_data();
        wp_send_json_success('Update succesvol voltooid!');
    } catch (Exception $e) {
        wp_send_json_error('Update mislukt: ' . $e->getMessage());
    }
}