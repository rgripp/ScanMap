<?php
require_once '/var/www/src/functions.php';

// Load environment variables
$envFile = '/var/www/.env';
if (!loadEnvironmentVariables($envFile)) {
    die(json_encode(["message" => "Error: .env file not found.", "type" => "error"]));
}

// Connect to database
try {
    $pdo = connectToDatabase();
} catch (PDOException $e) {
    die(json_encode(["message" => "Error connecting to database: ".$e->getMessage(), "type" => "error"]));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = [];

    if (isset($_POST['resetDB'])) {
        $result = resetDatabase($pdo);
    } elseif (isset($_FILES['xmlFile'])) {
        if ($_FILES['xmlFile']['error'] === UPLOAD_ERR_OK) {
            $result = processXMLFile($pdo, $_FILES['xmlFile']['tmp_name']);
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

// Fetch scans data
$scans = [];
$stmt = $pdo->query("SELECT id, characterName, years, days, hours, minutes, seconds FROM scans ORDER BY id DESC");
if ($stmt) {
    $scans = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle AJAX request for scans data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetchScans'])) {
    header('Content-Type: application/json');
    echo json_encode($scans);
    exit;
}

// Handle AJAX request for scanned objects data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetchScannedObjects'])) {
    $scanID = (int)$_GET['scanID'];
    $stmt = $pdo->prepare("
        SELECT * FROM scannedObjects 
        WHERE scanID = :scanID 
        ORDER BY inParty DESC, partyLeaderUID ASC, x ASC, y ASC
    ");
    $stmt->execute([':scanID' => $scanID]);
    $scannedObjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($scannedObjects);
    exit;
}
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
<div class="main-container">
    <!-- Map Container -->
    <div class="map-container">
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
                    <div class="cell"><a href="?x=<?= $x ?>&y=<?= $y ?>" title="(<?= $x ?>, <?= $y ?>)"></a></div>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
    </div>

        <!-- Tabs Container -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tablinks active" onclick="openTab(event, 'ScannedObjects')">Scanned Objects</button>
                <button class="tablinks" onclick="openTab(event, 'Scans')">Scans</button>
            </div>

            <div id="ScannedObjects" class="tabcontent" style="display: block;">
    <!-- Table will be dynamically inserted here -->
            </div>

            <div id="Scans" class="tabcontent">
                <!-- Scans Table
                <table id="scansTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">Scan ID</th>
                            <th>Made By</th>
                            <th onclick="sortTable(2)">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scans as $scan): ?>
                        <tr>
                            <td>Scan <?= htmlspecialchars($scan['id']) ?></td>
                            <td><?= htmlspecialchars($scan['characterName']) ?></td>
                            <td>
                                Year <?= $scan['years'] ?>, Day <?= $scan['days'] ?>, 
                                <?= str_pad($scan['hours'], 2, '0', STR_PAD_LEFT) ?>:
                                <?= str_pad($scan['minutes'], 2, '0', STR_PAD_LEFT) ?>:
                                <?= str_pad($scan['seconds'], 2, '0', STR_PAD_LEFT) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table> -->
                <table id="scansTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">Scan ID</th>
                            <th>Made By</th>
                            <th onclick="sortTable(2)">Time</th>
                            <th>Action</th> <!-- Add this column for the View button -->
                        </tr>
                    </thead>
                    <tbody>
        <!-- Rows will be inserted here dynamically -->
                    </tbody>
                </table>
                <div class="button-container">
                    <!-- Combined Load/Upload Button -->
                    <div class="file-input-wrapper">
                        <input type="file" 
                               name="xmlFile" 
                               id="xmlFile" 
                               accept=".xml" 
                               onchange="handleFileUpload()"
                               style="display: none">
                        <button type="button" 
                                class="custom-file-input"
                                onclick="document.getElementById('xmlFile').click()">
                            Load File
                        </button>
                    </div>
                    
                    <!-- Reset DB Button -->
                    <button type="button" class="reset-button" onclick="handleResetDB()">Reset DB</button>
                </div>
                <div id="notificationContainer"></div>
            </div>
        </div>
    </div>

    <script>
    function openTab(evt, tabName) {
    // Hide all tab content
    document.querySelectorAll('.tabcontent').forEach(tab => {
        tab.style.display = 'none';
    });

    // Remove the "active" class from all tab buttons
    document.querySelectorAll('.tablinks').forEach(tab => {
        tab.classList.remove('active');
    });

    // Show the current tab
    const tabToShow = document.getElementById(tabName);
    if (tabToShow) {
        tabToShow.style.display = 'block';
    }

    // Add the "active" class to the button that opened the tab (if an event is provided)
    if (evt && evt.currentTarget) {
        evt.currentTarget.classList.add('active');
    }
}

        // Sort table by column
        function sortTable(n) {
            let table = document.getElementById("scansTable");
            let rows = Array.from(table.rows).slice(1);
            let asc = table.dataset.sortOrder !== "asc";
            table.dataset.sortOrder = asc ? "asc" : "desc";

            rows.sort((a, b) => {
                let x = a.cells[n].textContent.trim();
                let y = b.cells[n].textContent.trim();
                return asc ? x.localeCompare(y, undefined, { numeric: true }) : y.localeCompare(x, undefined, { numeric: true });
            });

            rows.forEach(row => table.appendChild(row));
        }

        // File upload handler
        function handleFileUpload() {
            const fileInput = document.getElementById('xmlFile');
            if (!fileInput.files.length) return;

            const formData = new FormData();
            formData.append('xmlFile', fileInput.files[0]);

            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => { showNotification(data); fileInput.value = ''; })
            .catch(error => showNotification({ message: 'Upload failed: ' + error.message, type: 'error' }));
        }

        // Database reset handler
        function handleResetDB() {
            const formData = new FormData();
            formData.append('resetDB', 'true');

            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => showNotification(data))
            .catch(error => showNotification({ message: 'Reset failed: ' + error.message, type: 'error' }));
        }

        // Notification system
        function showNotification(data) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${data.type}`;
            notification.innerHTML = `<span>${data.message}</span><button onclick="this.parentElement.remove()">&times;</button>`;
            container.prepend(notification);
        }

        // document.addEventListener('DOMContentLoaded', () => openTab(event, 'ScannedObjects'));
        document.addEventListener('DOMContentLoaded', () => {
    openTab(null, 'ScannedObjects'); // Open the default tab without an event
    fetchScansData(); // Fetch and populate the scans table
});
        // Function to fetch scans data from the server
function fetchScansData() {
    fetch('?fetchScans=true')
        .then(response => response.json())
        .then(data => updateScansTable(data))
        .catch(error => console.error('Error fetching scans data:', error));
}

// Function to update the scans table with new data
function updateScansTable(scans) {
    const tableBody = document.querySelector('#scansTable tbody');
    if (!tableBody) {
        console.error('Scans table body not found');
        return;
    }

    tableBody.innerHTML = ''; // Clear existing rows

    scans.forEach(scan => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>Scan ${scan.id}</td>
            <td>${scan.characterName}</td>
            <td>
                Year ${scan.years}, Day ${scan.days}, 
                ${String(scan.hours).padStart(2, '0')}:
                ${String(scan.minutes).padStart(2, '0')}:
                ${String(scan.seconds).padStart(2, '0')}
            </td>
            <td><button class="view-button" onclick="fetchScannedObjects(${scan.id}, event)">View</button></td>
        `;
        tableBody.appendChild(row);
    });
}

// Function to handle file upload and update the table
function handleFileUpload() {
    const fileInput = document.getElementById('xmlFile');
    if (!fileInput.files.length) return;

    const formData = new FormData();
    formData.append('xmlFile', fileInput.files[0]);

    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            showNotification(data);
            fileInput.value = '';
            fetchScansData(); // Fetch and update the table after upload
        })
        .catch(error => showNotification({ message: 'Upload failed: ' + error.message, type: 'error' }));
}

// Function to handle database reset and update the table
function handleResetDB() {
    const formData = new FormData();
    formData.append('resetDB', 'true');

    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            showNotification(data); // Show success/error message
            fetchScansData(); // Refresh the Scans table
            clearScannedObjectsTable(); // Clear the Scanned Objects table
        })
        .catch(error => {
            console.error('Reset failed:', error);
            showNotification({ message: 'Reset failed: ' + error.message, type: 'error' });
        });
}

// Function to clear the Scanned Objects table
function clearScannedObjectsTable() {
    const scannedObjectsTab = document.getElementById('ScannedObjects');
    if (scannedObjectsTab) {
        scannedObjectsTab.innerHTML = '<h3>Scanned Objects</h3>'; // Reset to default state
    }
}

// Fetch scans data when the page loads
document.addEventListener('DOMContentLoaded', () => {
    openTab(event, 'ScannedObjects');
    fetchScansData(); // Fetch and populate the table initially
});

// Fetch scans data when the page loads
document.addEventListener('DOMContentLoaded', () => {
    openTab(event, 'ScannedObjects'); // Open the default tab
    fetchScansData(); // Fetch and populate the scans table
});

// Function to fetch scans data from the server
function fetchScansData() {
    fetch('?fetchScans=true')
        .then(response => response.json())
        .then(data => updateScansTable(data))
        .catch(error => console.error('Error fetching scans data:', error));
}

// Function to update the scans table with new data
function updateScansTable(scans) {
    const tableBody = document.querySelector('#scansTable tbody');
    if (!tableBody) {
        console.error('Scans table body not found');
        return;
    }

    tableBody.innerHTML = ''; // Clear existing rows

    scans.forEach(scan => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>Scan ${scan.id}</td>
            <td>${scan.characterName}</td>
            <td>
                Year ${scan.years}, Day ${scan.days}, 
                ${String(scan.hours).padStart(2, '0')}:
                ${String(scan.minutes).padStart(2, '0')}:
                ${String(scan.seconds).padStart(2, '0')}
            </td>
            <td><button class="view-button" onclick="fetchScannedObjects(${scan.id})">View</button></td>
        `;
        tableBody.appendChild(row);
    });
}

// Function to fetch scanned objects for a specific scanID
function fetchScannedObjects(scanID, event) {
    console.log(`Fetching scanned objects for scanID: ${scanID}`);
    fetch(`?fetchScannedObjects=true&scanID=${scanID}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Scanned Objects Data:', data);
            updateScannedObjectsTable(data); // Update the Scanned Objects tab
            openTab(event, 'ScannedObjects'); // Switch to the Scanned Objects tab
        })
        .catch(error => {
            console.error('Error fetching scanned objects:', error);
            showNotification({ message: 'Error fetching scanned objects: ' + error.message, type: 'error' });
        });
}
function updateScannedObjectsTable(scannedObjects) {
    const scannedObjectsTab = document.getElementById('ScannedObjects');
    if (!scannedObjectsTab) {
        console.error('Scanned Objects tab not found');
        return;
    }

    // Check if the table already exists
    let table = scannedObjectsTab.querySelector('table');
    if (!table) {
        // Create a new table if it doesn't exist
        scannedObjectsTab.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Entity ID</th>
                        <th>Name</th>
                        <th>Type Name</th>
                        <th>Owner Name</th>
                        <th>IFF Status</th>
                        <th>X</th>
                        <th>Y</th>
                        <th>Travel Direction</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        `;
        table = scannedObjectsTab.querySelector('table');
    }

    const tableBody = table.querySelector('tbody');
    if (!tableBody) {
        console.error('Table body not found');
        return;
    }

    tableBody.innerHTML = ''; // Clear existing rows

    // Group entries by partyLeaderUID
    const groupedObjects = {};
    scannedObjects.forEach(obj => {
        const key = obj.partyLeaderUID || obj.entityUID; // Use partyLeaderUID if available, otherwise use entityUID
        if (!groupedObjects[key]) {
            groupedObjects[key] = [];
        }
        groupedObjects[key].push(obj);
    });

    console.log('Grouped Objects:', groupedObjects); // Debugging

    // Ensure the party leader is first in each group
    Object.values(groupedObjects).forEach(group => {
        // Find the party leader in the group
        const partyLeader = group.find(obj => obj.entityUID === (group[0].partyLeaderUID || group[0].entityUID));
        console.log('Party Leader:', partyLeader); // Debugging
        if (partyLeader) {
            // Remove the party leader from the group
            const leaderIndex = group.indexOf(partyLeader);
            if (leaderIndex !== -1) {
                group.splice(leaderIndex, 1);
            }
            // Add the party leader to the beginning of the group
            group.unshift(partyLeader);
        }
        console.log('Group After Reordering:', group); // Debugging
    });

    // Add rows to the table
    Object.values(groupedObjects).forEach(group => {
        // Find the party leader in the group
        const partyLeader = group.find(obj => obj.entityUID === (group[0].partyLeaderUID || group[0].entityUID));

        // Add a group header for parties
        if (partyLeader) {
            const headerRow = document.createElement('tr');
            headerRow.className = `party-group ${getIffStatusClass(partyLeader.iffStatus, true)}`;
            headerRow.innerHTML = `
                <td colspan="9"><strong>Squad</strong></td>
            `;
            tableBody.appendChild(headerRow);
        }

        // Add each object in the group
        group.forEach(obj => {
            const isLeader = obj.entityUID === (partyLeader ? partyLeader.entityUID : null);
            const rowClass = getIffStatusClass(obj.iffStatus, isLeader);
            const row = document.createElement('tr');
            row.className = rowClass;
            row.innerHTML = `
                <td><img src="${obj.image}" alt="${obj.name}" style="max-width: 50px; max-height: 50px;"></td>
                <td>${obj.entityUID}</td>
                <td>${obj.name}</td>
                <td>${obj.typeName}</td>
                <td>${obj.ownerName}</td>
                <td>${obj.iffStatus}</td>
                <td>${obj.x}</td>
                <td>${obj.y}</td>
                <td>${obj.travelDirection}</td>
            `;
            tableBody.appendChild(row);
        });
    });
}

function getIffStatusClass(iffStatus, isLeader = false) {
    if (!iffStatus) return ''; // Handle undefined/null values

    // Match exact values (case-sensitive)
    switch (iffStatus) {
        case 'Friend':
            return isLeader ? 'friend-leader' : 'friend';
        case 'Enemy':
            return isLeader ? 'enemy-leader' : 'enemy';
        case 'Neutral':
            return isLeader ? 'neutral-leader' : 'neutral';
        default:
            return ''; // Ignore other IFF statuses
    }
}
    </script>
</body>
</html>
