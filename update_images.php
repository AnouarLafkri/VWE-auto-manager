<?php
// Include the main file
require_once __DIR__ . '/VWE-auto-manager.php';

// Force update by setting last update time to 0
file_put_contents(LAST_UPDATE_FILE, 0);

// Run the update functions
fetch_images_from_ftp();
cleanup_unused_images();
echo 'Images updated successfully at ' . date('Y-m-d H:i:s') . "\n";
