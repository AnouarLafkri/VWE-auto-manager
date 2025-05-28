<?php
// ontvanger.php - XML verwerker en updater

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to log messages with timestamp and error checking
function logMessage($message) {
    $logFile = __DIR__ . "/debug.log";
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = $timestamp . " - " . $message . "\n";

    // Ensure directory is writable
    if (!is_writable(__DIR__)) {
        chmod(__DIR__, 0777);
    }

    // Create log file if it doesn't exist
    if (!file_exists($logFile)) {
        file_put_contents($logFile, "=== Debug Log Started ===\n");
        chmod($logFile, 0666);
    }

    // Ensure log file is writable
    if (!is_writable($logFile)) {
        chmod($logFile, 0666);
    }

    // Write to log file
    if (file_put_contents($logFile, $logMessage, FILE_APPEND) === false) {
        error_log("Failed to write to log file: " . $logFile);
        return false;
    }

    return true;
}

// Start new log entry with error checking
if (!logMessage("=== New Request Started ===")) {
    error_log("Failed to write initial log entry");
}

// Check if running from command line
$isCli = (php_sapi_name() === 'cli');

// Log server information
if (!$isCli) {
    logMessage("Server Software: " . $_SERVER['SERVER_SOFTWARE']);
    logMessage("Request Method: " . $_SERVER['REQUEST_METHOD']);
}

// Get XML data
$xmlData = '';
if ($isCli) {
    // Read from local_file.xml when running from command line
    $xmlData = file_get_contents('local_file.xml');
    if ($xmlData === false) {
        logMessage("ERROR: Could not read local_file.xml");
        die("Error: Could not read local_file.xml\n");
    }
} else {
    // Get XML data from POST request
    $xmlData = file_get_contents('php://input');
    if (empty($xmlData)) {
        logMessage("ERROR: No XML data received in POST request");
        header('HTTP/1.1 400 Bad Request');
        echo "No XML data received";
        exit;
    }
}

// Log data length and first 100 chars
logMessage("Data length: " . strlen($xmlData) . " bytes");
logMessage("First 100 chars: " . substr($xmlData, 0, 100));

// Fix XML entities in incoming data before parsing
$xmlData = fixXmlEntities($xmlData);
logMessage("First 200 chars after entity fix: " . substr($xmlData, 0, 200));

// Parse incoming XML with detailed error handling
libxml_use_internal_errors(true);
$libxml_options = LIBXML_NOWARNING | LIBXML_NOERROR;
if (defined('LIBXML_RECOVER')) {
    $libxml_options |= LIBXML_RECOVER;
}
$incomingXml = simplexml_load_string($xmlData, 'SimpleXMLElement', $libxml_options);
if ($incomingXml === false) {
    $errors = libxml_get_errors();
    $criticalErrors = [];

    foreach ($errors as $error) {
        if ($error->level === LIBXML_ERR_FATAL) {
            $criticalErrors[] = $error;
            logMessage("XML Critical Error: Line {$error->line}: {$error->message}");
        } else {
            logMessage("XML Warning: Line {$error->line}: {$error->message}");
        }
    }
    libxml_clear_errors();

    // Only fail if there are critical errors
    if (!empty($criticalErrors)) {
        // Log the problematic XML for debugging
        logMessage("Problematic XML content (first 500 chars):");
        logMessage(substr($xmlData, 0, 500));

        // Try to fix the XML structure and re-parse
        logMessage("Attempting to fix XML structure and re-parse...");
        $fixedXml = fixXmlStructure($xmlData);
        if ($fixedXml) {
            $incomingXml = simplexml_load_string($fixedXml, 'SimpleXMLElement', $libxml_options);
            if ($incomingXml !== false) {
                logMessage("Successfully fixed and loaded XML after initial parse failure.");
            } else {
                logMessage("Failed to fix and load XML after initial parse failure.");
                http_response_code(400);
                echo "Error loading XML data. Please check the error logs for more information.";
                exit;
            }
        } else {
            logMessage("Failed to fix XML structure after initial parse failure.");
            http_response_code(400);
            echo "Error loading XML data. Please check the error logs for more information.";
            exit;
        }
    } else {
        logMessage("XML loaded with warnings but no critical errors");
    }
}

// Validate XML structure (but don't fail if it's just entity warnings)
if (!validateXmlStructure($xmlData)) {
    logMessage("WARNING: XML structure validation failed, but attempting to continue");

    // Try to load with SimpleXML as a fallback test
    $libxml_options = LIBXML_NOWARNING | LIBXML_NOERROR;
    if (defined('LIBXML_RECOVER')) {
        $libxml_options |= LIBXML_RECOVER;
    }
    $testXml = simplexml_load_string($xmlData, 'SimpleXMLElement', $libxml_options);
    if ($testXml === false) {
        logMessage("ERROR: XML is completely invalid and cannot be loaded");
        http_response_code(400);
        echo "Invalid XML structure. Please check the error logs for more information.";
        exit;
    } else {
        logMessage("XML can be loaded despite validation warnings - continuing");
    }
}

// Handle both single vehicle and multiple vehicles XML structure
$vehiclesToProcess = [];
if ($incomingXml !== false && $incomingXml instanceof SimpleXMLElement) {
    if ($incomingXml->getName() === 'voertuig' || $incomingXml->getName() === 'autotelex') {
        // Single vehicle XML
        $vehiclesToProcess[] = $incomingXml;
        logMessage("Processing single vehicle XML (" . $incomingXml->getName() . ")");
    } else if ($incomingXml->getName() === 'voorraad') {
        // Multiple vehicles XML
        foreach ($incomingXml->children() as $vehicle) {
            if ($vehicle->getName() === 'voertuig' || $vehicle->getName() === 'autotelex') {
                $vehiclesToProcess[] = $vehicle;
            }
        }
        logMessage("Processing multiple vehicles XML");
    } else {
        logMessage("WARNING: Unknown XML root element: " . $incomingXml->getName());
    }
} else {
    logMessage("ERROR: Invalid incoming XML - cannot process vehicles");
}

logMessage("Number of vehicles to process: " . count($vehiclesToProcess));

// Path to local file
$localPath = __DIR__ . "/local_file.xml";

// Increase memory limit for large XML files
ini_set('memory_limit', '256M');

// Load existing XML
$existingXml = null;
if (file_exists($localPath)) {
    logMessage("Attempting to load local_file.xml from: " . $localPath);
    logMessage("File size: " . filesize($localPath) . " bytes");
    logMessage("File permissions: " . substr(sprintf('%o', fileperms($localPath)), -4));

    try {
        // Read the file content
        $xmlContent = file_get_contents($localPath);
        if ($xmlContent === false) {
            throw new Exception("Failed to read file contents");
        }

        // Fix common XML entity issues before validation
        $xmlContent = fixXmlEntities($xmlContent);
        logMessage("First 200 chars of local XML after entity fix: " . substr($xmlContent, 0, 200));

        // Validate the XML structure
        $validation = validateXmlStructure($xmlContent);
        if (!$validation) {
            logMessage("XML validation failed - attempting to fix structure");

            // Try to fix the structure
            $fixedXml = fixXmlStructure($xmlContent);
            if ($fixedXml) {
                logMessage("Successfully fixed XML structure");
                $xmlContent = $fixedXml;

                // Re-validate after fixing
                if (!validateXmlStructure($xmlContent)) {
                    logMessage("XML still invalid after fixing - creating new structure");
                    $dom = createNewXmlStructure();
                    $xmlContent = $dom->saveXML();
                }
            } else {
                // Create new XML structure if fixing fails
                logMessage("Creating new XML structure after failed fix");
                $dom = createNewXmlStructure();
                $xmlContent = $dom->saveXML();
            }
        } else {
            logMessage("XML validation passed successfully");
        }

        // Create SimpleXMLElement from the validated/fixed XML
        $existingXml = simplexml_load_string($xmlContent);
        if ($existingXml === false) {
            throw new Exception("Failed to create SimpleXMLElement");
        }

        logMessage("Successfully loaded and validated XML content");

        // Count vehicles
        $vehicleCount = 0;
        foreach ($existingXml->children() as $car) {
            if ($car->getName() === 'voertuig' || $car->getName() === 'autotelex') {
                $vehicleCount++;
            }
        }
        logMessage("Found " . $vehicleCount . " vehicles in XML file");

    } catch (Exception $e) {
        logMessage("ERROR loading XML: " . $e->getMessage());
        error_log("Exception while loading XML: " . $e->getMessage());

        // Create new XML structure if loading fails
        try {
            logMessage("Creating new XML structure");
            $dom = createNewXmlStructure();
            $xmlContent = $dom->saveXML();
            $existingXml = simplexml_load_string($xmlContent);
            logMessage("Successfully created new XML structure");

            // Save the new structure immediately
            if (file_put_contents($localPath, $xmlContent)) {
                chmod($localPath, 0666);
                logMessage("Successfully saved new XML structure to file");
            } else {
                logMessage("ERROR: Failed to save new XML structure to file");
            }
        } catch (Exception $e) {
            logMessage("CRITICAL ERROR: Failed to create new XML structure: " . $e->getMessage());
            error_log("Failed to create new XML structure: " . $e->getMessage());
            die("Critical error: Unable to create XML structure. Please check error logs.");
        }
    }
} else {
    logMessage("File does not exist, creating new XML structure");
    try {
        $dom = createNewXmlStructure();
        $xmlContent = $dom->saveXML();
        $existingXml = simplexml_load_string($xmlContent);
        logMessage("Successfully created new XML structure");

        // Save the new structure immediately
        if (file_put_contents($localPath, $xmlContent)) {
            chmod($localPath, 0666);
            logMessage("Successfully saved new XML structure to file");
        } else {
            logMessage("ERROR: Failed to save new XML structure to file");
        }
    } catch (Exception $e) {
        logMessage("CRITICAL ERROR: Failed to create new XML structure: " . $e->getMessage());
        error_log("Failed to create new XML structure: " . $e->getMessage());
        die("Critical error: Unable to create XML structure. Please check error logs.");
    }
}

// Verify that we have a valid XML structure before proceeding
if (!$existingXml || $existingXml->getName() !== 'voorraad') {
    logMessage("ERROR: No valid XML structure available");
    try {
        $existingXml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><voorraad></voorraad>');
        logMessage("Successfully created new XML structure after verification");

        // Save the new structure immediately
        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($existingXml->asXML());
        $xmlString = $dom->saveXML();

        if (file_put_contents($localPath, $xmlString)) {
            chmod($localPath, 0666);
            logMessage("Successfully saved new XML structure to file after verification");
        } else {
            logMessage("ERROR: Failed to save new XML structure to file after verification");
        }
    } catch (Exception $e) {
        logMessage("CRITICAL ERROR: Failed to create new XML structure after verification: " . $e->getMessage());
        error_log("Failed to create new XML structure after verification: " . $e->getMessage());
        die("Critical error: Unable to create XML structure. Please check error logs.");
    }
}

// Create index of existing vehicles
$existingCars = [];
$existingCount = 0;
if ($existingXml) {
    foreach ($existingXml->children() as $car) {
        if ($car->getName() === 'voertuig' || $car->getName() === 'autotelex') {
            $kenteken = (string)$car->kenteken;
            if ($kenteken) {
                $existingCars[$kenteken] = true;
                $existingCount++;
                logMessage("Found existing vehicle with kenteken: " . $kenteken . " (" . $car->getName() . ")");
            }
        }
    }
    logMessage("Found " . $existingCount . " existing vehicles in local_file.xml");
} else {
    logMessage("ERROR: No valid XML structure available");
    die("Critical error: No valid XML structure available. Please check error logs.");
}

// Process new vehicles
$newCount = 0;
$addedCount = 0;
$skippedCount = 0;

// Add version mapping and validation
function getVersionMapping($version) {
    $mappings = [
        '2.23' => [
            'required_fields' => [
                'btw_marge', 'nieuw_voertuig', 'prijstype', 'bieden_toestaan', 'oldtimer',
                'merk', 'model', 'kenteken', 'bouwjaar', 'brandstof', 'transmissie'
            ],
            'field_types' => [
                'bouwjaar' => 'integer',
                'tellerstand' => 'integer',
                'verkoopprijs_particulier' => 'decimal',
                'vermogen_motor_pk' => 'integer',
                'cilinder_inhoud' => 'integer'
            ]
        ],
        '2.16' => [
            'required_fields' => [
                'btw_marge', 'nieuw_voertuig', 'prijstype', 'bieden_toestaan', 'oldtimer',
                'merk', 'model', 'kenteken', 'bouwjaar'
            ],
            'field_types' => [
                'bouwjaar' => 'integer',
                'tellerstand' => 'integer',
                'verkoopprijs_particulier' => 'decimal'
            ]
        ]
    ];
    return $mappings[$version] ?? $mappings['2.23'];
}

function validateFieldType($value, $type) {
    switch ($type) {
        case 'integer':
            return is_numeric($value) && ctype_digit((string)$value);
        case 'decimal':
            return is_numeric($value);
        case 'boolean':
            return in_array(strtolower($value), ['j', 'n', 'true', 'false', '1', '0']);
        default:
            return true;
    }
}

function validateVehicle($vehicle, $version) {
    $mapping = getVersionMapping($version);
    $errors = [];

    // Check required fields
    foreach ($mapping['required_fields'] as $field) {
        if (!isset($vehicle->$field) || trim((string)$vehicle->$field) === '') {
            $errors[] = "Missing required field: $field";
        }
    }

    // Validate field types
    foreach ($mapping['field_types'] as $field => $type) {
        if (isset($vehicle->$field)) {
            $value = (string)$vehicle->$field;
            if (!validateFieldType($value, $type)) {
                $errors[] = "Invalid type for field $field: expected $type";
            }
        }
    }

    return $errors;
}

function createBackup($filePath) {
    $backupPath = $filePath . '.backup.' . date('Y-m-d-H-i-s');
    if (file_exists($filePath)) {
        if (!copy($filePath, $backupPath)) {
            throw new Exception("Failed to create backup of $filePath");
        }
        logMessage("Created backup: $backupPath");
    }
    return $backupPath;
}

function restoreBackup($backupPath, $filePath) {
    if (file_exists($backupPath)) {
        if (!copy($backupPath, $filePath)) {
            throw new Exception("Failed to restore from backup: $backupPath");
        }
        logMessage("Restored from backup: $backupPath");
    }
}

// Functie om te controleren of een voertuig volledig is
function isVehicleComplete($vehicle) {
    // First check for verkoopprijs_particulier before other checks
    if (!isset($vehicle->verkoopprijs_particulier) ||
        !isset($vehicle->verkoopprijs_particulier->prijzen) ||
        !isset($vehicle->verkoopprijs_particulier->prijzen->prijs) ||
        !isset($vehicle->verkoopprijs_particulier->prijzen->prijs->bedrag) ||
        trim((string)$vehicle->verkoopprijs_particulier->prijzen->prijs->bedrag) === '') {
        logMessage("Missing required field 'verkoopprijs_particulier' for vehicle: " .
                  ((string)$vehicle->merk ?: 'Unknown') . " " .
                  ((string)$vehicle->model ?: 'Unknown'));
        return false;
    }

    // Essentiële velden voor een auto advertentie
    $requiredFields = [
        'merk',
        'model',
        'bouwjaar',
        'tellerstand',
        'brandstof',
        'transmissie',
        'carrosserie'
    ];

    // Controleer of alle verplichte velden aanwezig zijn en niet leeg zijn
    foreach ($requiredFields as $field) {
        if (!isset($vehicle->$field) || trim((string)$vehicle->$field) === '') {
            logMessage("Missing required field '$field' for vehicle: " .
                      ((string)$vehicle->merk ?: 'Unknown') . " " .
                      ((string)$vehicle->model ?: 'Unknown'));
            return false;
        }
    }

    // Controleer of er minimaal één uniek identificatienummer is
    $hasUniqueId = !empty(trim((string)$vehicle->kenteken)) ||
                  !empty(trim((string)$vehicle->chassisnummer)) ||
                  !empty(trim((string)$vehicle->voertuignr)) ||
                  !empty(trim((string)$vehicle->voertuignr_hexon));

    if (!$hasUniqueId) {
        logMessage("No unique identifier found for vehicle: " .
                  ((string)$vehicle->merk ?: 'Unknown') . " " .
                  ((string)$vehicle->model ?: 'Unknown'));
        return false;
    }

    // Controleer of er minimaal één afbeelding is
    $hasImages = isset($vehicle->afbeeldingen) &&
                isset($vehicle->afbeeldingen->afbeelding) &&
                count($vehicle->afbeeldingen->afbeelding) > 0;

    if (!$hasImages) {
        logMessage("No images found for vehicle: " .
                  ((string)$vehicle->merk ?: 'Unknown') . " " .
                  ((string)$vehicle->model ?: 'Unknown'));
        return false;
    }

    return true;
}

// REMOVED: Function to remove incomplete vehicles - no longer needed
// All vehicles are now added regardless of missing fields, only duplicates are prevented
/*
function removeIncompleteVehicles($xml) {
    // This function has been disabled to allow all vehicles to be added
    // regardless of missing fields. Only duplicate vehicles are prevented.
    logMessage("removeIncompleteVehicles function disabled - all vehicles will be added");
    return 0;
}
*/

function saveXmlFile($xml, $filePath) {
    // Create backup before saving
    $backupPath = $filePath . '.backup.' . date('Y-m-d-H-i-s');
    if (file_exists($filePath)) {
        if (!copy($filePath, $backupPath)) {
            logMessage("WARNING: Failed to create backup before saving");
        } else {
            logMessage("Created backup: " . $backupPath);
        }
    }

    // Convert SimpleXML to DOMDocument for better formatting
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = true;

    // Import the SimpleXML object
    $dom->loadXML($xml->asXML());

    // Verify XML content before saving
    $vehicleCount = 0;
    foreach ($dom->getElementsByTagName('voertuig') as $vehicle) {
        $vehicleCount++;
    }
    foreach ($dom->getElementsByTagName('autotelex') as $vehicle) {
        $vehicleCount++;
    }

    if ($vehicleCount === 0) {
        logMessage("ERROR: Attempting to save empty XML file! Aborting save operation.");
        return false;
    }

    // Save the XML
    if (file_put_contents($filePath, $dom->saveXML())) {
        chmod($filePath, 0666);

        // Verify the saved file
        $savedContent = file_get_contents($filePath);
        if (empty($savedContent)) {
            logMessage("ERROR: Saved file is empty! Restoring from backup.");
            if (file_exists($backupPath)) {
                copy($backupPath, $filePath);
                chmod($filePath, 0666);
            }
            return false;
        }

        // Verify XML structure after saving
        $testXml = simplexml_load_string($savedContent);
        if ($testXml === false) {
            logMessage("ERROR: Saved file contains invalid XML! Restoring from backup.");
            if (file_exists($backupPath)) {
                copy($backupPath, $filePath);
                chmod($filePath, 0666);
            }
            return false;
        }

        logMessage("Successfully saved XML file with $vehicleCount vehicles");
        return true;
    } else {
        logMessage("ERROR: Failed to save XML file");
        return false;
    }
}

// Add this function to check file integrity
function restoreFromKnownGoodBackup($filePath) {
    $knownGoodBackup = __DIR__ . "/local_file.xml.known_good";

    // If we have a known good backup, use it
    if (file_exists($knownGoodBackup)) {
        if (copy($knownGoodBackup, $filePath)) {
            chmod($filePath, 0666);
            logMessage("Successfully restored from known good backup");
            return true;
        }
    }

    // If no known good backup exists, create one from the current file if it's valid
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        if (!empty($content)) {
            $xml = simplexml_load_string($content);
            if ($xml !== false) {
                $vehicleCount = 0;
                foreach ($xml->children() as $vehicle) {
                    if ($vehicle->getName() === 'voertuig' || $vehicle->getName() === 'autotelex') {
                        $vehicleCount++;
                    }
                }
                if ($vehicleCount > 0) {
                    if (copy($filePath, $knownGoodBackup)) {
                        chmod($knownGoodBackup, 0666);
                        logMessage("Created new known good backup with $vehicleCount vehicles");
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

// Modify the checkFileIntegrity function to use the known good backup
function checkFileIntegrity($filePath) {
    if (!file_exists($filePath)) {
        logMessage("ERROR: File does not exist: $filePath");
        return restoreFromKnownGoodBackup($filePath);
    }

    $content = file_get_contents($filePath);
    if (empty($content)) {
        logMessage("ERROR: File is empty: $filePath");
        return restoreFromKnownGoodBackup($filePath);
    }

    // Check if content is valid XML
    $xml = simplexml_load_string($content);
    if ($xml === false) {
        logMessage("ERROR: File contains invalid XML: $filePath");
        return restoreFromKnownGoodBackup($filePath);
    }

    // Count vehicles
    $vehicleCount = 0;
    foreach ($xml->children() as $vehicle) {
        if ($vehicle->getName() === 'voertuig' || $vehicle->getName() === 'autotelex') {
            $vehicleCount++;
        }
    }

    if ($vehicleCount === 0) {
        logMessage("ERROR: No vehicles found in XML file: $filePath");
        return restoreFromKnownGoodBackup($filePath);
    }

    logMessage("File integrity check passed: $vehicleCount vehicles found");
    return true;
}

// Add this function to restore from latest backup
function restoreFromLatestBackup($filePath) {
    $backups = glob($filePath . '.backup.*');
    if (empty($backups)) {
        logMessage("ERROR: No backups found for: $filePath");
        return false;
    }

    // Sort backups by date (newest first)
    usort($backups, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $latestBackup = $backups[0];
    if (copy($latestBackup, $filePath)) {
        chmod($filePath, 0666);
        logMessage("Successfully restored from backup: $latestBackup");
        return true;
    }

    logMessage("ERROR: Failed to restore from backup: $latestBackup");
    return false;
}

// Add this check before processing vehicles
$localPath = __DIR__ . "/local_file.xml";
if (file_exists($localPath)) {
    if (!checkFileIntegrity($localPath)) {
        logMessage("WARNING: File integrity check failed, attempting to restore from known good backup");
        if (!restoreFromKnownGoodBackup($localPath)) {
            logMessage("ERROR: Failed to restore from known good backup, creating new XML structure");
            $dom = createNewXmlStructure();
            $xmlContent = $dom->saveXML();
            $existingXml = simplexml_load_string($xmlContent);
        }
    }
}

foreach ($vehiclesToProcess as $newCar) {
    $newCount++;
    // Unieke ID bepalen
    $newKenteken = trim((string)$newCar->kenteken);
    $chassisnummer = isset($newCar->chassisnummer) ? trim((string)$newCar->chassisnummer) : '';
    $voertuignr = isset($newCar->voertuignr) ? trim((string)$newCar->voertuignr) : '';
    $voertuignr_hexon = isset($newCar->voertuignr_hexon) ? trim((string)$newCar->voertuignr_hexon) : '';
    $uniqueId = $newKenteken ?: ($chassisnummer ?: ($voertuignr ?: $voertuignr_hexon));
    $newMerk = (string)$newCar->merk;
    $newModel = (string)$newCar->model;
    $vehicleType = $newCar->getName();

    if (empty($uniqueId)) {
        logMessage("SKIP: Geen uniek ID (kenteken/chassisnummer/voertuignr) voor voertuig $newCount ($newMerk $newModel) - Cannot add vehicle without unique identifier");
        $skippedCount++;
        continue;
    }

    logMessage("Processing $vehicleType $newCount met uniek ID: $uniqueId ($newMerk $newModel)");

    // NO VALIDATION - ADD ALL VEHICLES REGARDLESS OF MISSING FIELDS
    // Only skip if no unique ID is available (already checked above)

    // Check if vehicle already exists (prevent duplicates)
    if (isset($existingCars[$uniqueId])) {
        try {
            foreach ($existingXml->children() as $existingCar) {
                $id = (string)$existingCar->kenteken ?: ((string)$existingCar->chassisnummer ?: ((string)$existingCar->voertuignr ?: (string)$existingCar->voertuignr_hexon));
                if ($id === $uniqueId) {
                    foreach ($newCar->children() as $field => $value) {
                        if ($field !== 'kenteken' && $field !== 'chassisnummer' && $field !== 'voertuignr' && $field !== 'voertuignr_hexon') {
                            $existingCar->$field = (string)$value;
                        }
                    }
                    // Update timestamp voor bestaande auto
                    if (!isset($existingCar->timestamp)) {
                        $existingCar->addChild('timestamp', time());
                    } else {
                        $existingCar->timestamp = time();
                    }
                    logMessage("DUPLICATE FOUND: Updated existing vehicle instead of adding duplicate: $uniqueId ($newMerk $newModel)");

                    // Save after each update
                    saveXmlFile($existingXml, __DIR__ . "/local_file.xml");
                    break;
                }
            }
        } catch (Exception $e) {
            logMessage("ERROR updating vehicle $uniqueId: " . $e->getMessage());
            $skippedCount++;
        }
    } else {
        try {
            $newVehicle = $existingXml->addChild($vehicleType);
            foreach ($newCar->children() as $field => $value) {
                $newVehicle->addChild($field, (string)$value);
            }
            // Voeg timestamp toe voor nieuwe auto
            $newVehicle->addChild('timestamp', time());

            // Voeg unieke ID toe als die nog niet bestaat
            if (!isset($newVehicle->kenteken) && $newKenteken) $newVehicle->addChild('kenteken', $newKenteken);
            if (!isset($newVehicle->chassisnummer) && $chassisnummer) $newVehicle->addChild('chassisnummer', $chassisnummer);
            if (!isset($newVehicle->voertuignr) && $voertuignr) $newVehicle->addChild('voertuignr', $voertuignr);
            if (!isset($newVehicle->voertuignr_hexon) && $voertuignr_hexon) $newVehicle->addChild('voertuignr_hexon', $voertuignr_hexon);

            // Add required fields if they don't exist
            $requiredFields = [
                'btw_marge' => 'j',
                'nieuw_voertuig' => 'n',
                'prijstype' => 'vast',
                'bieden_toestaan' => 'n',
                'oldtimer' => 'n'
            ];
            foreach ($requiredFields as $field => $defaultValue) {
                if (!isset($newVehicle->$field)) {
                    $newVehicle->addChild($field, $defaultValue);
                }
            }
            $addedCount++;
            logMessage("Successfully added new $vehicleType: $uniqueId ($newMerk $newModel) - Added regardless of missing fields");

            // Save after each addition
            saveXmlFile($existingXml, __DIR__ . "/local_file.xml");
        } catch (Exception $e) {
            logMessage("ERROR adding vehicle $uniqueId: " . $e->getMessage());
            $skippedCount++;
        }
    }
}

// Log final results
logMessage("=== Final Results ===");
logMessage("Total vehicles processed: " . $newCount);
logMessage("New vehicles added: " . $addedCount);
logMessage("Vehicles skipped (no unique ID or duplicates): " . $skippedCount);
logMessage("NOTE: All vehicles are now added regardless of missing fields - only duplicates and vehicles without unique IDs are skipped");

// Sort vehicles by timestamp (newest first) - only if there are vehicles
$vehicles = [];
foreach ($existingXml->children() as $vehicle) {
    if ($vehicle->getName() === 'voertuig' || $vehicle->getName() === 'autotelex') {
        $vehicles[] = $vehicle;
    }
}

if (count($vehicles) > 0) {
    // Sort vehicles by timestamp
    usort($vehicles, function($a, $b) {
        $timestampA = isset($a->timestamp) ? (int)$a->timestamp : 0;
        $timestampB = isset($b->timestamp) ? (int)$b->timestamp : 0;
        return $timestampB - $timestampA; // Sort descending (newest first)
    });

    // Create new XML with sorted vehicles
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = true;

    $root = $dom->createElement('voorraad');
    $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $root->setAttribute('xsi:noNamespaceSchemaLocation', 'voertuigen.xsd');
    $root->setAttribute('versie', '2.23');
    $root->setAttribute('datum', date('Y-m-d'));
    $root->setAttribute('tijd', date('H:i:s'));
    $dom->appendChild($root);

    // Add sorted vehicles to new XML
    foreach ($vehicles as $vehicle) {
        $vehicleNode = $dom->importNode(dom_import_simplexml($vehicle), true);
        $root->appendChild($vehicleNode);
    }

    // Save the sorted XML
    $xmlString = $dom->saveXML();
    if (file_put_contents($localPath, $xmlString)) {
        chmod($localPath, 0666);
        logMessage("Successfully saved sorted XML file with " . count($vehicles) . " vehicles");
    } else {
        logMessage("ERROR: Failed to save sorted XML file");
    }
} else {
    logMessage("No vehicles to sort - skipping sorting step");
}

// Function to compare vehicles between files
function compareVehicles($logXml, $localXml) {
    $logVehicles = [];
    $localVehicles = [];
    $comparison = [];

    // Get vehicles from log.xml
    foreach ($logXml->children() as $car) {
        $kenteken = (string)$car->kenteken;
        if ($kenteken) {
            $logVehicles[$kenteken] = [
                'merk' => (string)$car->merk,
                'model' => (string)$car->model,
                'type' => (string)$car->type,
                'bouwjaar' => (string)$car->bouwjaar,
                'prijs' => isset($car->verkoopprijs_particulier->prijzen->prijs->bedrag) ?
                    (string)$car->verkoopprijs_particulier->prijzen->prijs->bedrag :
                    'Niet beschikbaar'
            ];
        }
    }

    // Get vehicles from local_file.xml
    foreach ($localXml->children() as $car) {
        $kenteken = (string)$car->kenteken;
        if ($kenteken) {
            $localVehicles[$kenteken] = [
                'merk' => (string)$car->merk,
                'model' => (string)$car->model,
                'type' => (string)$car->type,
                'bouwjaar' => (string)$car->bouwjaar,
                'prijs' => isset($car->verkoopprijs_particulier->prijzen->prijs->bedrag) ?
                    (string)$car->verkoopprijs_particulier->prijzen->prijs->bedrag :
                    'Niet beschikbaar'
            ];
        }
    }

    // Compare vehicles
    foreach ($logVehicles as $kenteken => $logCar) {
        if (isset($localVehicles[$kenteken])) {
            $comparison[$kenteken] = [
                'status' => 'exists',
                'log' => $logCar,
                'local' => $localVehicles[$kenteken]
            ];
        } else {
            $comparison[$kenteken] = [
                'status' => 'new',
                'log' => $logCar,
                'local' => null
            ];
        }
    }

    return $comparison;
}

// After loading both XML files, add this:
$comparison = compareVehicles($incomingXml, $existingXml);
logMessage("=== Vehicle Comparison ===");
foreach ($comparison as $kenteken => $data) {
    if ($data['status'] === 'new') {
        logMessage("New vehicle found: " . $kenteken);
        logMessage("- Merk: " . $data['log']['merk']);
        logMessage("- Model: " . $data['log']['model']);
        logMessage("- Type: " . $data['log']['type']);
        logMessage("- Bouwjaar: " . $data['log']['bouwjaar']);
        logMessage("- Prijs: €" . $data['log']['prijs']);
    } else {
        logMessage("Existing vehicle: " . $kenteken);
        logMessage("- Log XML: " . $data['log']['merk'] . " " . $data['log']['model']);
        logMessage("- Local XML: " . $data['local']['merk'] . " " . $data['local']['model']);
    }
}

logMessage("=== Request Completed ===\n");
echo "1";
exit;

/**
 * Download and save an image from a URL
 * @param string $url The URL of the image to download
 * @param string $filename The filename to save the image as
 * @return string|false The path to the saved image or false if download failed
 */
function downloadAndSaveImage($url, $filename) {
    $images_dir = __DIR__ . '/images/';

    // Create images directory if it doesn't exist
    if (!file_exists($images_dir)) {
        mkdir($images_dir, 0777, true);
    }

    // Clean the filename
    $filename = preg_replace('/[^a-zA-Z0-9.-]/', '_', $filename);
    $filepath = $images_dir . $filename;

    // Download the image
    $image_data = @file_get_contents($url);
    if ($image_data === false) {
        error_log("Failed to download image from URL: " . $url);
        return false;
    }

    // Save the image
    if (file_put_contents($filepath, $image_data) === false) {
        error_log("Failed to save image to: " . $filepath);
        return false;
    }

    return 'images/' . $filename;
}

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
        'titel' => $get_xml_value($car, 'titel', ''),
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
        'carrosserie' => $get_xml_value($car, 'carrosserie', 'Onbekend'),
        'afbeeldingen_local' => [] // New array for local image paths
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
                    $image_url = $image_url_base . $bestandsnaam;
                    $data['afbeeldingen'][] = $image_url;

                    // Download and save the image
                    $local_path = downloadAndSaveImage($image_url, $bestandsnaam);
                    if ($local_path !== false) {
                        $data['afbeeldingen_local'][] = $local_path;
                    }
                }
            }
        }
    }

    // Set default image if no images are found
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

    // Log the number of vehicles found
    $vehicle_count = count($xml->voertuig);
    error_log("Found {$vehicle_count} vehicles in XML file");

    // Log details about each vehicle
    foreach ($xml->voertuig as $index => $car) {
        $kenteken = (string)$car->kenteken;
        $merk = (string)$car->merk;
        $model = (string)$car->model;
        error_log("Vehicle {$index}: {$merk} {$model} ({$kenteken})");
    }

    return $xml;
}

// Function to create a new XML structure
function createNewXmlStructure() {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = true;

    // Create root element with proper namespace and schema
    $root = $dom->createElement('voorraad');
    $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $root->setAttribute('xsi:noNamespaceSchemaLocation', 'voertuigen.xsd');
    $root->setAttribute('versie', '2.23');
    $root->setAttribute('datum', date('Y-m-d'));
    $root->setAttribute('tijd', date('H:i:s'));
    $dom->appendChild($root);

    // Create initial structure
    $xmlString = $dom->saveXML();

    // Ensure proper XML declaration
    if (strpos($xmlString, '<?xml') === false) {
        $xmlString = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlString;
    }

    // Save the initial structure
    $dom->loadXML($xmlString);

    // Save to file
    $localPath = __DIR__ . "/local_file.xml";
    if (file_put_contents($localPath, $xmlString)) {
        chmod($localPath, 0666);
        logMessage("Successfully created and saved new XML structure");
    } else {
        logMessage("ERROR: Failed to save new XML structure to file");
        throw new Exception("Failed to save new XML structure to file");
    }

    return $dom;
}

// Function to fix XML entities
function fixXmlEntities($xmlContent) {
    $entityReplacements = [
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
        '&hellip;' => '…',
        '&euml;' => 'ë',
        '&uuml;' => 'ü',
        '&ouml;' => 'ö',
        '&auml;' => 'ä',
        '&iuml;' => 'ï',
        '&Euml;' => 'Ë',
        '&Uuml;' => 'Ü',
        '&Ouml;' => 'Ö',
        '&Auml;' => 'Ä',
        '&Iuml;' => 'Ï',
        '&aacute;' => 'á',
        '&eacute;' => 'é',
        '&iacute;' => 'í',
        '&oacute;' => 'ó',
        '&uacute;' => 'ú',
        '&agrave;' => 'à',
        '&egrave;' => 'è',
        '&igrave;' => 'ì',
        '&ograve;' => 'ò',
        '&ugrave;' => 'ù',
        '&acirc;' => 'â',
        '&ecirc;' => 'ê',
        '&icirc;' => 'î',
        '&ocirc;' => 'ô',
        '&ucirc;' => 'û',
        '&ccedil;' => 'ç',
        '&ntilde;' => 'ñ',
        '&szlig;' => 'ß'
    ];

    foreach ($entityReplacements as $entity => $replacement) {
        $xmlContent = str_replace($entity, $replacement, $xmlContent);
    }

    return $xmlContent;
}

// Function to validate XML structure
function validateXmlStructure($xmlContent) {
    // Enable error handling
    libxml_use_internal_errors(true);

    // Create a new DOMDocument
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = true;

    // Try to load the XML with more lenient settings
    $libxml_options = LIBXML_NOWARNING | LIBXML_NOERROR;
    if (defined('LIBXML_RECOVER')) {
        $libxml_options |= LIBXML_RECOVER;
    }
    if (!$dom->loadXML($xmlContent, $libxml_options)) {
        $errors = libxml_get_errors();
        $criticalErrors = [];

        // Only log critical errors, ignore entity warnings
        foreach ($errors as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                $criticalErrors[] = $error;
                logMessage("XML Critical Error: Line {$error->line}: {$error->message}");
            } else {
                // Log non-critical errors as warnings only
                logMessage("XML Warning: Line {$error->line}: {$error->message}");
            }
        }

        libxml_clear_errors();

        // Only fail if there are critical errors
        if (!empty($criticalErrors)) {
            return false;
        }
    }

    // Check for root element
    $root = $dom->documentElement;
    if (!$root) {
        logMessage("ERROR: No root element found in XML");
        return false;
    }

    // Check if root element is 'voertuig', 'autotelex', or 'voorraad'
    $validRoots = ['voertuig', 'autotelex', 'voorraad'];
    if (!in_array($root->nodeName, $validRoots)) {
        logMessage("ERROR: Invalid root element: " . $root->nodeName);
        return false;
    }

    // For voorraad elements, check for required attributes (but don't fail if missing)
    if ($root->nodeName === 'voorraad') {
        $requiredAttrs = ['versie', 'datum', 'tijd'];
        foreach ($requiredAttrs as $attr) {
            if (!$root->hasAttribute($attr)) {
                logMessage("WARNING: Missing recommended attribute '$attr' in voorraad element");
                // Don't fail validation for missing attributes
            }
        }
    }

    // Don't require vehicles to exist - allow empty voorraad
    logMessage("XML structure validation passed");
    return true;
}

// Function to fix XML structure
function fixXmlStructure($xmlContent) {
    // Create a new DOMDocument
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = true;

        // Fix common XML entity issues
    $xmlContent = fixXmlEntities($xmlContent);

    // Add XML declaration if missing
    if (strpos($xmlContent, '<?xml') === false) {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlContent;
    }

    // Add root element if missing
    if (strpos($xmlContent, '<voorraad') === false) {
        $xmlContent = str_replace('<?xml version="1.0" encoding="UTF-8"?>',
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<voorraad xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="voertuigen.xsd" versie="2.23" datum="' . date('Y-m-d') . '" tijd="' . date('H:i:s') . '">',
            $xmlContent);
        $xmlContent .= "\n</voorraad>";
    }

    // Load the XML
    if (!$dom->loadXML($xmlContent, LIBXML_NOWARNING | LIBXML_NOERROR)) {
        logMessage("ERROR: Failed to load XML after fixing structure");
        return false;
    }

    return $dom->saveXML();
}

