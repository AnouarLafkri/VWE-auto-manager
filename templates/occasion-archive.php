<?php
/**
 * Archive template for occasion custom post type
 * Loaded via template_include filter in plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<div class="vwe-archive-container" style="max-width:1400px;margin:0 auto;padding:0 10px;">
    <?php
    if (function_exists('display_car_listing')) {
        display_car_listing();
    } else {
        echo do_shortcode('[vwe_auto_listing]');
    }
    ?>
</div>

<?php get_footer(); ?>