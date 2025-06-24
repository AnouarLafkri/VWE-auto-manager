<?php
/*
Plugin Name: VWE Latest Cars Widget
Description: Mini-plugin met shortcode [vwe_latest_cars] die de 3 nieuwste occasions toont.
Version: 1.0
Author: Anouar
*/

// Voorkom directe toegang
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
    echo '<div class="vwe-latest-cars" style="display:flex;gap:20px;flex-wrap:wrap;">';
    // Zelfde grid-structuur & card-functies als hoofdplugin
    echo '<div class="cars-grid">';
    foreach ( $cars as $carNode ) {
        $car_arr = extract_car_data( $carNode, $image_base );
        display_car_card( $car_arr ); // gebruikt orig. card-markup
    }
    echo '</div></div>';
    return ob_get_clean();
}
add_shortcode( 'vwe_latest_cars', 'vwe_latest_cars_shortcode' );