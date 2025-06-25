<?php
/*
Plugin Name: VWE Cheapest Cars Widget
Description: Mini-plugin met shortcode [vwe_cheapest_cars] die de 3 goedkoopste occasions toont.
Version: 1.1
Author: Anouar
*/

// Voorkom directe toegang
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Laad de CSS voor de widget
 */
function vwe_cheapest_cars_enqueue_styles() {
    wp_enqueue_style(
        'vwe-cheapest-cars-styles',
        plugin_dir_url(__FILE__) . 'vwe-cheapest-cars.css',
        array(),
        '1.1.0'
    );
}
add_action('wp_enqueue_scripts', 'vwe_cheapest_cars_enqueue_styles');

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
    $debug_info = [];

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

            // Debug info voor de eerste 10 auto's
            if (count($debug_info) < 10) {
                $merk = isset($car->merk) ? (string)$car->merk : 'Onbekend';
                $model = isset($car->model) ? (string)$car->model : 'Onbekend';
                $debug_info[] = "$merk $model: €$price_numeric";
            }
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
    echo '<div class="vwe-cheapest-cars">';

    // Debug informatie (tijdelijk uitgeschakeld)
    /*
    if (function_exists('current_user_can') && current_user_can('administrator')) {
        echo '<div style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; font-size: 12px;">';
        echo '<strong>Debug info (alleen zichtbaar voor admins):</strong><br>';
        echo 'Totaal beschikbare auto\'s met geldige prijzen: ' . count($cars) . '<br>';
        echo 'Eerste 10 auto\'s gevonden:<br>';
        foreach ($debug_info as $info) {
            echo "- $info<br>";
        }
        echo '</div>';
    }
    */

    echo '<div class="cars-grid">';
    foreach ( $cars as $carNode ) {
        $car_arr = extract_car_data( $carNode, $image_base );
        display_car_card( $car_arr ); // gebruikt orig. card-markup
    }
    echo '</div></div>';
    return ob_get_clean();
}
add_shortcode( 'vwe_cheapest_cars', 'vwe_cheapest_cars_shortcode' );