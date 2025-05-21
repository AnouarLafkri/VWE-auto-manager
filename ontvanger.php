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

// Log server information
logMessage("PHP Version: " . PHP_VERSION);
logMessage("Server Software: " . $_SERVER['SERVER_SOFTWARE']);
logMessage("Document Root: " . $_SERVER['DOCUMENT_ROOT']);
logMessage("Script Path: " . __DIR__);

// Accept both GET and POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    logMessage("ERROR: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo "0";
    exit;
}

// Get XML data from either POST or GET
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $xmlData = file_get_contents("php://input");
    logMessage("Received POST data");
    logMessage("Raw POST data: " . substr($xmlData, 0, 500)); // Log first 500 chars of raw data
} else {
    $xmlData = isset($_GET['xml']) ? $_GET['xml'] : '';
    logMessage("Received GET data");
    logMessage("Raw GET data: " . substr($xmlData, 0, 500)); // Log first 500 chars of raw data
}

// If no XML data is provided and it's a GET request, show usage instructions
if (empty($xmlData) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    logMessage("No XML data received, showing usage instructions");
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body>';
    echo '<h1>XML Receiver</h1>';
    echo '<p>This endpoint expects XML data to be sent via POST request or as a GET parameter.</p>';
    echo '<h2>Usage:</h2>';
    echo '<h3>POST Method:</h3>';
    echo '<pre>curl -X POST -H "Content-Type: text/xml" --data-binary "@your_file.xml" ' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '</pre>';
    echo '<h3>GET Method:</h3>';
    echo '<pre>' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '?xml=YOUR_XML_DATA</pre>';
    echo '</body></html>';
    exit;
}

// Log data details
logMessage("Data length: " . strlen($xmlData) . " bytes");
logMessage("First 100 chars: " . substr($xmlData, 0, 100));

if (empty($xmlData)) {
    logMessage("ERROR: No XML data received");
    http_response_code(400);
    echo "0";
    exit;
}

// Save incoming XML to log.xml
if (file_put_contents(__DIR__ . "/log.xml", $xmlData)) {
    logMessage("Successfully saved incoming XML to log.xml");
} else {
    logMessage("ERROR: Failed to save incoming XML to log.xml");
}

// Parse incoming XML
libxml_use_internal_errors(true);
$incomingXml = simplexml_load_string($xmlData);
if ($incomingXml === false) {
    $errors = libxml_get_errors();
    $errorMessage = "XML parsing failed:\n";
    foreach ($errors as $error) {
        $errorMessage .= "Line {$error->line}: {$error->message}\n";
    }
    logMessage("ERROR: " . $errorMessage);
    libxml_clear_errors();
    http_response_code(400);
    echo "0";
    exit;
}
logMessage("Successfully parsed incoming XML");
logMessage("Incoming XML root element: " . $incomingXml->getName());
logMessage("Number of voertuig elements in incoming XML: " . count($incomingXml->voertuig));

// Path to local file
$localPath = __DIR__ . "/local_file.xml";

// Load or create local file
if (!file_exists($localPath)) {
    logMessage("local_file.xml does not exist, creating new file");
    if (file_put_contents($localPath, $xmlData)) {
        chmod($localPath, 0666);
        logMessage("Successfully created new local_file.xml");
        logMessage("Initial file size: " . filesize($localPath) . " bytes");
    } else {
        logMessage("ERROR: Failed to create local_file.xml");
        error_log("Failed to create local_file.xml");
    }
    echo "1";
    exit;
}

// Load existing XML
$existingXml = simplexml_load_file($localPath);
if ($existingXml === false) {
    logMessage("ERROR: Failed to load local_file.xml, attempting to replace");
    if (file_put_contents($localPath, $xmlData)) {
        logMessage("Successfully replaced corrupt local_file.xml");
    } else {
        logMessage("ERROR: Failed to replace corrupt local_file.xml");
    }
    echo "1";
    exit;
}
logMessage("Successfully loaded existing local_file.xml");
logMessage("Existing XML root element: " . $existingXml->getName());
logMessage("Number of voertuig elements in existing XML: " . count($existingXml->voertuig));

// Create index of existing vehicles
$existingCars = [];
$existingCount = 0;
foreach ($existingXml->voertuig as $car) {
    $kenteken = (string)$car->kenteken;
    if ($kenteken) {
        $existingCars[$kenteken] = true;
        $existingCount++;
        logMessage("Found existing vehicle with kenteken: " . $kenteken);
    }
}
logMessage("Found " . $existingCount . " existing vehicles in local_file.xml");

// Process new vehicles
$newCount = 0;
$addedCount = 0;
$skippedCount = 0;
foreach ($incomingXml->voertuig as $newCar) {
    $newCount++;
    $newKenteken = (string)$newCar->kenteken;
    $newMerk = (string)$newCar->merk;
    $newModel = (string)$newCar->model;
    logMessage("Processing vehicle " . $newCount . " with kenteken: " . $newKenteken . " (" . $newMerk . " " . $newModel . ")");

    if ($newKenteken && !isset($existingCars[$newKenteken])) {
        $newNode = $existingXml->addChild('voertuig');
        foreach ($newCar->children() as $child) {
            $newNode->addChild($child->getName(), (string)$child);
        }
        $addedCount++;
        logMessage("Added new vehicle: " . $newKenteken . " (" . $newMerk . " " . $newModel . ")");

        // Log vehicle details
        logMessage("Vehicle details:");
        logMessage("- Merk: " . $newMerk);
        logMessage("- Model: " . $newModel);
        logMessage("- Type: " . (string)$newCar->type);
        logMessage("- Bouwjaar: " . (string)$newCar->bouwjaar);
        logMessage("- Kilometerstand: " . (string)$newCar->tellerstand);
        logMessage("- Prijs: €" . (string)$newCar->verkoopprijs_particulier->prijzen->prijs->bedrag);
    } else if ($newKenteken) {
        $skippedCount++;
        logMessage("Skipped existing vehicle: " . $newKenteken . " (" . $newMerk . " " . $newModel . ")");

        // Log why it was skipped
        if (isset($existingCars[$newKenteken])) {
            logMessage("Reason: Vehicle with kenteken " . $newKenteken . " already exists in local_file.xml");
        } else {
            logMessage("Reason: Invalid or missing kenteken");
        }
    }
}

// Log merge summary with more details
logMessage("=== Merge Summary ===");
logMessage("Processed " . $newCount . " vehicles from incoming XML");
logMessage("Added " . $addedCount . " new vehicles");
logMessage("Skipped " . $skippedCount . " existing vehicles");
logMessage("Total vehicles in local_file.xml: " . ($existingCount + $addedCount));
logMessage("Merge completed at: " . date('Y-m-d H:i:s'));

// Save the result
$dom = new DOMDocument('1.0');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
$dom->loadXML($existingXml->asXML());
$xmlString = $dom->saveXML();

if (file_put_contents($localPath, $xmlString)) {
    chmod($localPath, 0666);
    logMessage("Successfully saved updated local_file.xml");
    logMessage("New file size: " . filesize($localPath) . " bytes");
    logMessage("Number of vehicles in file: " . count($existingXml->voertuig));
} else {
    logMessage("ERROR: Failed to save local_file.xml");
    error_log("Failed to save local_file.xml");
}

// Function to compare vehicles between files
function compareVehicles($logXml, $localXml) {
    $logVehicles = [];
    $localVehicles = [];
    $comparison = [];

    // Get vehicles from log.xml
    foreach ($logXml->voertuig as $car) {
        $kenteken = (string)$car->kenteken;
        if ($kenteken) {
            $logVehicles[$kenteken] = [
                'merk' => (string)$car->merk,
                'model' => (string)$car->model,
                'type' => (string)$car->type,
                'bouwjaar' => (string)$car->bouwjaar,
                'prijs' => (string)$car->verkoopprijs_particulier->prijzen->prijs->bedrag
            ];
        }
    }

    // Get vehicles from local_file.xml
    foreach ($localXml->voertuig as $car) {
        $kenteken = (string)$car->kenteken;
        if ($kenteken) {
            $localVehicles[$kenteken] = [
                'merk' => (string)$car->merk,
                'model' => (string)$car->model,
                'type' => (string)$car->type,
                'bouwjaar' => (string)$car->bouwjaar,
                'prijs' => (string)$car->verkoopprijs_particulier->prijzen->prijs->bedrag
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
