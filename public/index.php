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
                    <div class="cell" data-x="<?= $x ?>" data-y="<?= $y ?>" title="(<?= $x ?>, <?= $y ?>)"></div>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
        <!-- The "Clear Filter" button will be appended here by JavaScript -->
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
            <table id="scansTable">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)">Scan ID</th>
                        <th>Made By</th>
                        <th onclick="sortTable(2)">Time</th>
                        <th>Action</th>
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
        .then(data => {
            showNotification(data);
            fileInput.value = '';
            fetchScansData(); // Refresh the scans table
        })
        .catch(error => showNotification({ message: 'Upload failed: ' + error.message, type: 'error' }));
}

// Database reset handler
function handleResetDB() {
    const formData = new FormData();
    formData.append('resetDB', 'true');

    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            showNotification(data);
            fetchScansData(); // Refresh the scans table
            clearScannedObjectsTable(); // Clear the Scanned Objects table
            clearGridColors(); // Clear the grid colors
        })
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

// Function to fetch scans data
function fetchScansData() {
    fetch('?fetchScans=true')
        .then(response => response.json())
        .then(data => updateScansTable(data))
        .catch(error => showNotification({ message: 'Error fetching scans data: ' + error.message, type: 'error' }));
}

// Function to update the scans table
function updateScansTable(scans) {
    const tableBody = document.querySelector('#scansTable tbody');
    if (!tableBody) {
        showNotification({ message: 'Scans table body not found', type: 'error' });
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

// Function to clear the Scanned Objects table
function clearScannedObjectsTable() {
    const scannedObjectsTab = document.getElementById('ScannedObjects');
    if (scannedObjectsTab) {
        scannedObjectsTab.innerHTML = '<h3>Scanned Objects</h3>';
    }
}

// Function to fetch scanned objects for a specific scanID
function fetchScannedObjects(scanID) {
    fetch(`?fetchScannedObjects=true&scanID=${scanID}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            updateScannedObjectsTable(data);
            openTab(null, 'ScannedObjects');
        })
        .catch(error => {
            showNotification({ message: 'Error fetching scanned objects: ' + error.message, type: 'error' });
        });
}

// Add a helper function to count non-wreck entities
function countNonWreckEntities(objects) {
    return objects.filter(obj => 
        obj.iffStatus && 
        obj.typeName && 
        !obj.typeName.toLowerCase().includes('wreck') &&
        !obj.typeName.toLowerCase().includes('debris') &&
        !obj.name.toLowerCase().includes('wreck') &&
        !obj.name.toLowerCase().includes('debris')
    ).length;
}

function clearGridColors() {
    const cells = document.querySelectorAll('.grid .cell');
    cells.forEach(cell => {
        // Remove all possible status classes
        cell.classList.remove('enemy-only', 'friend-only', 'neutral-only', 'mixed');
        // Reset to default background
        cell.style.backgroundColor = '#f0f0f0';
        
        // Clear any existing count
        const existingCount = cell.querySelector('.entity-count');
        if (existingCount) {
            existingCount.remove();
        }
    });
}

function colorGridCell(x, y, statuses, objectsInCell) {
    const cell = document.querySelector(`.grid .cell[data-x="${x}"][data-y="${y}"]`);
    if (!cell) {
        return;
    }

    // Remove any existing status classes
    cell.classList.remove('enemy-only', 'friend-only', 'neutral-only', 'mixed');

    // Determine cell color based on statuses
    if (statuses.length === 1) {
        const status = statuses[0];
        switch (status) {
            case 'Enemy':
                cell.style.backgroundColor = '#f5c6cb';
                cell.classList.add('enemy-only');
                break;
            case 'Friend':
                cell.style.backgroundColor = '#c3e6cb';
                cell.classList.add('friend-only');
                break;
            case 'Neutral':
                cell.style.backgroundColor = '#e2d3f5';
                cell.classList.add('neutral-only');
                break;
        }
    } else if (statuses.length > 1) {
        cell.style.backgroundColor = '#ffd580';
        cell.classList.add('mixed');
    }

    // Add entity count if non-zero
    const entityCount = countNonWreckEntities(objectsInCell);
    if (entityCount > 0) {
        const countSpan = document.createElement('span');
        countSpan.textContent = entityCount;
        countSpan.className = 'entity-count';
        
        // Dynamically adjust font size based on count
        if (entityCount > 9) {
            countSpan.style.fontSize = '8px';
        }
        if (entityCount > 99) {
            countSpan.style.fontSize = '6px';
        }
        
        cell.appendChild(countSpan);
    }
}

function updateScannedObjectsTable(scannedObjects) {
    // First, clear all grid colors
    clearGridColors();
    
    const scannedObjectsTab = document.getElementById('ScannedObjects');
    if (!scannedObjectsTab) return;

    // Create grid status map and object tracking
    const gridStatus = {};
    const gridObjects = {};
    
    // First pass: Collect all IFF statuses and objects for each coordinate
    scannedObjects.forEach(obj => {
        if (obj.iffStatus) {  // Only process objects with IFF status
            const key = `${obj.x},${obj.y}`;
            if (!gridStatus[key]) {
                gridStatus[key] = new Set();
                gridObjects[key] = [];
            }
            gridStatus[key].add(obj.iffStatus);
            gridObjects[key].push(obj);
        }
    });

    // Second pass: Color each cell based on collected statuses
    for (const [coords, statuses] of Object.entries(gridStatus)) {
        const [x, y] = coords.split(',').map(Number);
        colorGridCell(x, y, Array.from(statuses), gridObjects[coords]);
    }

    // Rest of the function remains the same...
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

    const tableBody = scannedObjectsTab.querySelector('tbody');

    // Group objects by party
    const groupedObjects = {};
    scannedObjects.forEach(obj => {
        const key = obj.partyLeaderUID || obj.entityUID;
        if (!groupedObjects[key]) groupedObjects[key] = [];
        groupedObjects[key].push(obj);
    });

    // Populate table with groups
    Object.values(groupedObjects).forEach(group => {
        const partyLeaderUID = group[0].partyLeaderUID || group[0].entityUID;
        
        // Sort group to ensure party leader is first
        group.sort((a, b) => {
            if (a.entityUID === partyLeaderUID) return -1;
            if (b.entityUID === partyLeaderUID) return 1;
            return 0;
        });

        const partyLeader = group[0];

        // Add party header
        const headerRow = document.createElement('tr');
        headerRow.className = `party-group ${getIffStatusClass(partyLeader.iffStatus, true)}`;
        headerRow.innerHTML = `<td colspan="9"><strong>Squad</strong></td>`;
        tableBody.appendChild(headerRow);

        // Add party members
        group.forEach(obj => {
            const isLeader = obj.entityUID === partyLeaderUID;
            const row = document.createElement('tr');
            row.className = getIffStatusClass(obj.iffStatus, isLeader);
            row.innerHTML = `
                <td><img src="${obj.image}" alt="${obj.name}" style="max-width: 50px;"></td>
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

// Function to get IFF status class
function getIffStatusClass(iffStatus, isLeader = false) {
    if (!iffStatus) return '';

    switch (iffStatus) {
        case 'Friend':
            return isLeader ? 'friend-leader' : 'friend';
        case 'Enemy':
            return isLeader ? 'enemy-leader' : 'enemy';
        case 'Neutral':
            return isLeader ? 'neutral-leader' : 'neutral';
        default:
            return '';
    }
}

// Updated filter function
function filterScannedObjectsByCoordinates(x, y) {
    const scannedObjectsTab = document.getElementById('ScannedObjects');
    const table = scannedObjectsTab.querySelector('table');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    let currentPartyHeader = null;
    let hasVisibleMembersInParty = false;

    rows.forEach(row => {
        if (row.classList.contains('party-group')) {
            currentPartyHeader = row;
            hasVisibleMembersInParty = false;
            row.style.display = 'none';
        } else if (currentPartyHeader) {
            const rowX = parseInt(row.cells[6].textContent, 10);
            const rowY = parseInt(row.cells[7].textContent, 10);

            if (rowX === x && rowY === y) {
                row.style.display = '';
                hasVisibleMembersInParty = true;
            } else {
                row.style.display = 'none';
            }

            if (hasVisibleMembersInParty) {
                currentPartyHeader.style.display = '';
            }
        }
    });
}

// Updated clear filter function
function clearFilter() {
    const scannedObjectsTab = document.getElementById('ScannedObjects');
    const table = scannedObjectsTab.querySelector('table');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        row.style.display = '';
    });
}

// Event listeners
document.addEventListener('DOMContentLoaded', () => {
    // Initialize default tab
    openTab(null, 'ScannedObjects');
    
    const cells = document.querySelectorAll('.grid .cell');
    cells.forEach(cell => {
        // Test that we can color each cell
        cell.style.backgroundColor = '#f0f0f0';
    });
    // Fetch initial scans data
    fetchScansData();

    // Add click handlers to grid cells
    const gridCells = document.querySelectorAll('.grid .cell');
    gridCells.forEach(cell => {
        cell.addEventListener('click', () => {
            const x = parseInt(cell.getAttribute('data-x'), 10);
            const y = parseInt(cell.getAttribute('data-y'), 10);
            filterScannedObjectsByCoordinates(x, y);
        });
    });
    
    // Add clear filter button
    const clearFilterButton = document.createElement('button');
    clearFilterButton.textContent = 'Clear Filter';
    clearFilterButton.classList.add('clear-filter-button');
    clearFilterButton.addEventListener('click', clearFilter);

    const mapContainer = document.querySelector('.map-container');
    if (mapContainer) {
        mapContainer.appendChild(clearFilterButton);
    }
});
</script>
</body>
</html>