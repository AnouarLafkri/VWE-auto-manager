<?php
/**
 * Template for displaying individual occasion cards
 */

// Get the occasion data
$occasion_id = get_the_ID();
$title = get_the_title();
$content = get_the_content();
$thumbnail = get_the_post_thumbnail_url($occasion_id, 'large');
$price = get_post_meta($occasion_id, '_occasion_price', true);
$year = get_post_meta($occasion_id, '_occasion_year', true);
$mileage = get_post_meta($occasion_id, '_occasion_mileage', true);
$fuel = get_post_meta($occasion_id, '_occasion_fuel', true);
$transmission = get_post_meta($occasion_id, '_occasion_transmission', true);
$status = get_post_meta($occasion_id, '_occasion_status', true);

// Format the price
$formatted_price = number_format((float)$price, 0, ',', '.');
?>

<div class="occasion-card">
    <div class="occasion-image">
        <?php if ($thumbnail) : ?>
            <img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
        <?php else : ?>
            <img src="<?php echo esc_url(plugins_url('assets/images/placeholder.jpg', dirname(__FILE__))); ?>" alt="No image available" loading="lazy">
        <?php endif; ?>
        <div class="occasion-badges">
            <span class="status-badge <?php echo esc_attr($status); ?>">
                <?php echo esc_html(strtoupper($status)); ?>
            </span>
            <?php if ($year) : ?>
                <span class="year-badge"><?php echo esc_html($year); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="occasion-info">
        <h3 class="occasion-title"><?php echo esc_html($title); ?></h3>
        <?php if ($price) : ?>
            <div class="occasion-price">€ <?php echo esc_html($formatted_price); ?></div>
        <?php endif; ?>

        <div class="occasion-specs">
            <?php if ($mileage) : ?>
                <span class="spec-item">
                    <img src="<?php echo esc_url(plugins_url('assets/images/mileage.svg', dirname(__FILE__))); ?>" alt="Mileage" width="18">
                    <?php echo esc_html($mileage); ?> km
                </span>
            <?php endif; ?>

            <?php if ($fuel) : ?>
                <span class="spec-item">
                    <img src="<?php echo esc_url(plugins_url('assets/images/fuel.svg', dirname(__FILE__))); ?>" alt="Fuel" width="18">
                    <?php echo esc_html($fuel); ?>
                </span>
            <?php endif; ?>
        </div>

        <a href="<?php echo esc_url(get_permalink()); ?>" class="view-button">
            BEKIJKEN <span class="arrow">→</span>
        </a>
    </div>
</div>