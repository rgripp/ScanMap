<?php
require_once '/var/www/src/functions.php';

// Load environment variables
$envFile = '/var/www/.env';
if (!loadEnvironmentVariables($envFile)) {
    die(json_encode(["message" => "Error: .env file not found.", "type" => "error"]));
}

// Get database instance
try {
    $db = Database::getInstance();
} catch (PDOException $e) {
    die(json_encode(["message" => "Error connecting to database: ".$e->getMessage(), "type" => "error"]));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = [];

    if (isset($_POST['resetDB'])) {
        $result = resetDatabase($db->getConnection());
    } elseif (isset($_FILES['xmlFile'])) {
        if ($_FILES['xmlFile']['error'] === UPLOAD_ERR_OK) {
            $result = processXMLFile($db->getConnection(), $_FILES['xmlFile']['tmp_name']);
        } else {
            $result = [
                "message" => "Error: ".$_FILES['xmlFile']['error'],
                "type" => "error"
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Handle AJAX request for scans data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetchScans'])) {
    header('Content-Type: application/json');
    $scans = $db->query(
        "SELECT id, characterName, galX, galY, years, days, hours, minutes, seconds 
         FROM scans 
         ORDER BY id DESC",
        [],
        true // Use cache
    );
    echo json_encode($scans);
    exit;
}

// Handle AJAX request for scanned objects data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetchScannedObjects'])) {
    $scanID = (int)$_GET['scanID'];
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = ($page - 1) * $limit;

    $scannedObjects = $db->query(
        "SELECT * FROM scannedObjects 
         WHERE scanID = :scanID 
         ORDER BY inParty DESC, partyLeaderUID ASC, x ASC, y ASC
         LIMIT :limit OFFSET :offset",
        [
            ':scanID' => $scanID,
            ':limit' => $limit,
            ':offset' => $offset
        ],
        false // Don't cache this query as it's dynamic
    );

    header('Content-Type: application/json');
    echo json_encode($scannedObjects);
    exit;
}

// Initial page load - get scans for initial display
$scans = $db->query(
    "SELECT id, characterName, galX, galY, years, days, hours, minutes, seconds 
     FROM scans 
     ORDER BY id DESC",
    [],
    true // Use cache
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinate Grid Map</title>
    <link rel="stylesheet" type="text/css" href="/assets/css/styles.css">
</head>
<body>
    <!-- Map Container -->
    <div class="map-container">
        <h3 id="scanLocationHeader" class="scan-location-header">Scan Location: None Selected</h3>
        <div class="grid">
            <!-- Top row: Column headers (0 to 19) -->
            <div class="header"></div> <!-- Empty top-left corner -->
            <?php for ($x = 0; $x < 20; $x++): ?>
                <div class="header"><?= $x ?></div>
            <?php endfor; ?>

            <!-- Rows -->
            <?php for ($y = 0; $y < 20; $y++): ?>
                <!-- Left column: Row header -->
                <div class="header"><?= $y ?></div>

                <!-- Cells -->
                <?php for ($x = 0; $x < 20; $x++): ?>
                    <div class="cell" data-x="<?= $x ?>" data-y="<?= $y ?>" title="(<?= $x ?>, <?= $y ?>)"></div>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
        <!-- Clear Filter button will be added by JavaScript -->
    </div>

    <!-- Tabs Container -->
    <div class="tabs-container">
        <div class="tabs">
            <button class="tablinks active" data-tab="ScannedObjects">Scanned Objects</button>
            <button class="tablinks" data-tab="Scans">Scans</button>
        </div>

        <div id="ScannedObjects" class="tabcontent" style="display: block;">
            <!-- Table will be dynamically inserted here -->
        </div>

        <div id="Scans" class="tabcontent">
            <table id="scansTable">
                <thead>
                    <tr>
                        <th data-sort="id">ID</th>
                        <th>Sys Coords</th>
                        <th>Made By</th>
                        <th data-sort="time">Time</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Rows will be inserted here dynamically -->
                    <?php foreach ($scans as $scan): ?>
                        <tr>
                            <td><?= htmlspecialchars($scan['id']) ?></td>
                            <td><?= htmlspecialchars($scan['galX'] . ', ' . $scan['galY']) ?></td>
                            <td><?= htmlspecialchars($scan['characterName']) ?></td>
                            <td>
                                Year <?= htmlspecialchars($scan['years']) ?>, 
                                Day <?= htmlspecialchars($scan['days']) ?>, 
                                <?= sprintf('%02d:%02d:%02d', 
                                    $scan['hours'], 
                                    $scan['minutes'], 
                                    $scan['seconds']
                                ) ?>
                            </td>
                            <td>
                                <button class="view-button" 
                                        data-scan-id="<?= htmlspecialchars($scan['id']) ?>"
                                        data-coords="<?= htmlspecialchars($scan['galX'] . ', ' . $scan['galY']) ?>">
                                    View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="button-container">
                <div class="file-input-wrapper">
                    <input type="file" 
                           name="xmlFile" 
                           id="xmlFile" 
                           accept=".xml"
                           style="display: none">
                    <button type="button" 
                            class="custom-file-input"
                            onclick="document.getElementById('xmlFile').click()">
                        Load File
                    </button>
                </div>
            </div>
            <div id="notificationContainer"></div>
        </div>
    </div>

    <!-- Templates for dynamic content -->
    <template id="squadTemplate">
        <tr class="party-group">
            <td colspan="9">
                <span class="squad-toggle" data-state="collapsed">
                    <img src="/assets/images/plus.png" alt="Expand" class="toggle-icon">
                </span>
                <strong>Squad: <span class="squad-count"></span></strong>
            </td>
        </tr>
    </template>

    <template id="shipTemplate">
        <tr>
            <td><img alt="Ship"></td>
            <td class="entity-id"></td>
            <td class="ship-name"></td>
            <td class="ship-type"></td>
            <td class="owner-name"></td>
            <td class="coord-x"></td>
            <td class="coord-y"></td>
            <td class="travel-direction"></td>
            <td>
                <button class="copy-button">
                    <img src="/assets/images/copy.png" alt="Copy ID" width="16" height="16">
                </button>
            </td>
        </tr>
    </template>

    <!-- Load the optimized JavaScript -->
    <script src="/assets/js/app.js"></script>
</body>
</html>