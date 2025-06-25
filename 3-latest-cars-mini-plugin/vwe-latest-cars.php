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
 * Laad de CSS voor de widget
 */
function vwe_latest_cars_enqueue_styles() {
    wp_enqueue_style(
        'vwe-latest-cars-styles',
        plugin_dir_url(__FILE__) . 'vwe-latest-cars.css',
        array(),
        '1.0.0'
    );
}
add_action('wp_enqueue_scripts', 'vwe_latest_cars_enqueue_styles');

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
    // Header met titel en knop
    $occasions_url = htmlspecialchars('/occasions');
    echo '<div class="vwe-cards-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;">';
    echo '<h2 class="vwe-cards-title" style="margin:0;font-size:2.7rem;font-weight:800;line-height:1.1;">Laatste occasions</h2>';
    echo '<a class="vwe-cheapest-cars-btn" href="' . $occasions_url . '" style="color: #7a2d19; font-weight: 700; text-decoration: none; font-size: 1.35rem; padding: 10px 22px; border-radius: 6px;">Bekijk alle occasions <span style="font-size: 1.3em; vertical-align: middle; font-weight: 900;">&rarr;</span></a>';
    echo '</div>';
    echo '<div class="vwe-latest-cars">';
    echo '<div class="cars-grid">';
    foreach ( $cars as $carNode ) {
        $car_arr = extract_car_data( $carNode, $image_base );
        display_car_card( $car_arr ); // gebruikt orig. card-markup
    }
    echo '</div></div>';
    return ob_get_clean();
}
add_shortcode( 'vwe_latest_cars', 'vwe_latest_cars_shortcode' );