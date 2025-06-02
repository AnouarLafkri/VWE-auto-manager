<?php
/**
 * Template for displaying the occasions archive
 */

// Include header
include_once(dirname(__FILE__) . '/../header.php');
?>

<div class="page-wrapper">
    <div class="main-content">
        <?php
        // Display the car listing
        display_car_listing();
        ?>
    </div>
</div>

<?php
// Include footer
include_once(dirname(__FILE__) . '/../footer.php');
?>