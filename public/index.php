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
$stmt = $pdo->query("SELECT id, characterName, galX, galY, years, days, hours, minutes, seconds FROM scans ORDER BY id DESC");
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
                    <th onclick="sortTable(0)">ID</th>
                    <th>Sys Coords</th>
                    <th>Made By</th>
                    <th onclick="sortTable(3)">Time</th>
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
// State management
const AppState = {
    currentScanId: null,
    currentCoords: null,
    sortOrder: 'asc'
};

// UI Controller
const UIController = {
    elements: {
        scansTable: document.getElementById('scansTable'),
        scannedObjectsTab: document.getElementById('ScannedObjects'),
        notificationContainer: document.getElementById('notificationContainer'),
        scanLocationHeader: document.getElementById('scanLocationHeader'),
        fileInput: document.getElementById('xmlFile'),
        mapContainer: document.querySelector('.map-container'),
        grid: document.querySelector('.grid')
    },

    init() {
        this.setupEventListeners();
        this.createClearFilterButton();
        TabController.openTab(null, 'ScannedObjects');
        GridController.initializeGrid();
        DataController.fetchScansData();
    },

    setupEventListeners() {
        this.elements.fileInput.addEventListener('change', DataController.handleFileUpload);
        this.elements.grid.querySelectorAll('.cell').forEach(cell => {
            cell.addEventListener('click', () => {
                const x = parseInt(cell.dataset.x, 10);
                const y = parseInt(cell.dataset.y, 10);
                TableController.filterScannedObjectsByCoordinates(x, y);
            });
        });
    },

    createClearFilterButton() {
        const button = document.createElement('button');
        button.textContent = 'Clear Filter';
        button.classList.add('clear-filter-button');
        button.addEventListener('click', TableController.clearFilter);
        this.elements.mapContainer.appendChild(button);
    }
};

// Tab Controller
const TabController = {
    openTab(evt, tabName) {
        document.querySelectorAll('.tabcontent').forEach(tab => tab.style.display = 'none');
        document.querySelectorAll('.tablinks').forEach(tab => tab.classList.remove('active'));

        const tabToShow = document.getElementById(tabName);
        if (tabToShow) tabToShow.style.display = 'block';
        if (evt?.currentTarget) evt.currentTarget.classList.add('active');
    }
};

// Grid Controller
const GridController = {
    initializeGrid() {
        const cells = document.querySelectorAll('.grid .cell');
        cells.forEach(cell => cell.classList.add('cell'));
    },

    clearGridColors() {
        const cells = document.querySelectorAll('.grid .cell');
        cells.forEach(cell => {
            cell.classList.remove('enemy-only', 'friend-only', 'neutral-only', 'mixed');
            cell.querySelector('.entity-count')?.remove();
        });
    },

    colorGridCell(x, y, statuses, objectsInCell) {
        const cell = document.querySelector(`.grid .cell[data-x="${x}"][data-y="${y}"]`);
        if (!cell) return;

        // Clear any existing status classes
        cell.classList.remove('enemy-only', 'friend-only', 'neutral-only', 'mixed');

        // Add appropriate class based on status
        if (statuses.length === 1) {
            const status = statuses[0];
            cell.classList.add(`${status.toLowerCase()}-only`);
        } else if (statuses.length > 1) {
            cell.classList.add('mixed');
        }

        // Remove any existing entity count display
        cell.querySelector('.entity-count')?.remove();

        // Add entity count if there are non-wreck entities
        const entityCount = this.countNonWreckEntities(objectsInCell);
        if (entityCount > 0) {
            const countSpan = document.createElement('span');
            countSpan.textContent = entityCount;
            countSpan.className = `entity-count ${
                entityCount > 99 ? 'small' : 
                entityCount > 9 ? 'medium' : 
                'large'
            }`;
            cell.appendChild(countSpan);
        }
    },

    countNonWreckEntities(objects) {
        return objects.filter(obj => 
            obj.iffStatus && 
            obj.typeName && 
            !obj.typeName.toLowerCase().includes('wreck') &&
            !obj.typeName.toLowerCase().includes('debris') &&
            !obj.name.toLowerCase().includes('wreck') &&
            !obj.name.toLowerCase().includes('debris')
        ).length;
    }
};

// Table Controller
const TableController = {
    updateScansTable(scans) {
        const tableBody = UIController.elements.scansTable.querySelector('tbody');
        if (!tableBody) {
            NotificationController.show({ message: 'Scans table body not found', type: 'error' });
            return;
        }

        tableBody.innerHTML = scans.map(scan => `
            <tr>
                <td>${scan.id}</td>
                <td>${scan.galX || 0}, ${scan.galY || 0}</td>
                <td>${scan.characterName}</td>
                <td>
                    Year ${scan.years}, Day ${scan.days}, 
                    ${String(scan.hours).padStart(2, '0')}:
                    ${String(scan.minutes).padStart(2, '0')}:
                    ${String(scan.seconds).padStart(2, '0')}
                </td>
                <td>
                    <button class="view-button" 
                            onclick="DataController.fetchScannedObjects(${scan.id}, '${scan.galX || 0}, ${scan.galY || 0}')"
                    >View</button>
                </td>
            </tr>
        `).join('');
    },

    updateScannedObjectsTable(scannedObjects) {
        GridController.clearGridColors();
        
        // Add ship counts summary first
        const summaryHtml = this.createShipCountSummary(scannedObjects);
        UIController.elements.scannedObjectsTab.innerHTML = summaryHtml;
        
        // Add search box
        UIController.elements.scannedObjectsTab.innerHTML += `
            <div class="search-container">
                <input type="text" id="shipSearch" class="ship-search" placeholder="Search ships by name, type, owner or ID...">
            </div>`;
        
        const gridStatus = {};
        const gridObjects = {};
        
        scannedObjects.forEach(obj => {
            if (obj.iffStatus) {
                const key = `${obj.x},${obj.y}`;
                if (!gridStatus[key]) {
                    gridStatus[key] = new Set();
                    gridObjects[key] = [];
                }
                gridStatus[key].add(obj.iffStatus);
                gridObjects[key].push(obj);
            }
        });

        Object.entries(gridStatus).forEach(([coords, statuses]) => {
            const [x, y] = coords.split(',').map(Number);
            GridController.colorGridCell(x, y, Array.from(statuses), gridObjects[coords]);
        });

        // Add table after summary
        UIController.elements.scannedObjectsTab.innerHTML += this.createTableHeader();
        const tableBody = UIController.elements.scannedObjectsTab.querySelector('tbody');
        
        const groupedObjects = this.groupObjectsByParty(scannedObjects);
        let squadCounter = 0;

        Object.values(groupedObjects).forEach(group => {
            squadCounter++;
            const squadId = `squad-${squadCounter}`;
            this.renderSquad(tableBody, group, squadId);
        });

        this.setupSquadToggles();
        this.setupSearchHandler();
    },

    createShipCountSummary(objects) {
        let enemyCount = 0;
        let friendCount = 0;
        let neutralCount = 0;
        let wreckCount = 0;

        objects.forEach(obj => {
            const typeName = (obj.typeName || '').toLowerCase();
            const name = (obj.name || '').toLowerCase();
            
            if (typeName.includes('wreck') || 
                typeName.includes('debris') ||
                name.includes('wreck') ||
                name.includes('debris')) {
                wreckCount++;
            } else if (obj.iffStatus === 'Enemy') {
                enemyCount++;
            } else if (obj.iffStatus === 'Friend') {
                friendCount++;
            } else if (obj.iffStatus === 'Neutral') {
                neutralCount++;
            }
        });

        return `
            <div class="ship-count-summary">
                Enemy Ships: <span class="enemy-count">${enemyCount}</span> | 
                Friendly Ships: <span class="friend-count">${friendCount}</span> | 
                Neutral Ships: <span class="neutral-count">${neutralCount}</span> | 
                Wrecks: <span class="wreck-count">${wreckCount}</span>
            </div>
        `;
    },

    createTableHeader() {
        return `
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
    },

    groupObjectsByParty(objects) {
        const groups = {};
        objects.forEach(obj => {
            const key = obj.partyLeaderUID || obj.entityUID;
            if (!groups[key]) groups[key] = [];
            groups[key].push(obj);
        });
        return groups;
    },

    renderSquad(tableBody, group, squadId) {
        const partyLeaderUID = group[0].partyLeaderUID || group[0].entityUID;
        group.sort((a, b) => a.entityUID === partyLeaderUID ? -1 : b.entityUID === partyLeaderUID ? 1 : 0);

        const headerRow = this.createSquadHeader(group, squadId);
        tableBody.appendChild(headerRow);

        group.forEach((obj, index) => {
            const row = this.createSquadMemberRow(obj, index === 0, squadId);
            tableBody.appendChild(row);
        });
    },

    createSquadHeader(group, squadId) {
        const headerRow = document.createElement('tr');
        headerRow.className = `party-group ${this.getIffStatusClass(group[0].iffStatus, true)}`;
        headerRow.innerHTML = `
            <td colspan="9">
                <span class="squad-toggle" data-target="${squadId}" data-state="collapsed">
                    <img src="/assets/images/plus.png" alt="Expand" class="toggle-icon">
                </span>
                <strong>Squad: ${group.length} Ship${group.length !== 1 ? 's' : ''}</strong>
            </td>
        `;
        return headerRow;
    },

    createSquadMemberRow(obj, isLeader, squadId) {
        const row = document.createElement('tr');
        row.className = this.getIffStatusClass(obj.iffStatus, isLeader);
        
        if (!isLeader) {
            row.classList.add(squadId);
            row.classList.add('hidden');
        }
        
        row.innerHTML = `
            <td><img src="${obj.image}" alt="${obj.name}"></td>
            <td>${obj.entityUID}</td>
            <td>${obj.name}</td>
            <td>${obj.typeName}</td>
            <td>${obj.ownerName}</td>
            <td>${obj.iffStatus}</td>
            <td>${obj.x}</td>
            <td>${obj.y}</td>
            <td>${obj.travelDirection}</td>
        `;
        return row;
    },

    setupSquadToggles() {
        document.querySelectorAll('.squad-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const targetRows = document.querySelectorAll(`.${targetId}`);
                const currentState = this.dataset.state;
                const toggleIcon = this.querySelector('.toggle-icon');
                
                const newState = currentState === 'collapsed' ? 'expanded' : 'collapsed';
                targetRows.forEach(row => {
                    if (newState === 'expanded') {
                        row.classList.remove('hidden');
                    } else {
                        row.classList.add('hidden');
                    }
                });
                
                toggleIcon.src = `/assets/images/${newState === 'expanded' ? 'minus' : 'plus'}.png`;
                toggleIcon.alt = newState === 'expanded' ? 'Collapse' : 'Expand';
                this.dataset.state = newState;
            });
        });
    },

    setupSearchHandler() {
        const searchInput = document.getElementById('shipSearch');
        if (!searchInput) return;

        searchInput.addEventListener('input', (e) => {
            this.handleShipSearch(e.target.value.trim());
        });
    },

    handleShipSearch(searchText) {
        const table = UIController.elements.scannedObjectsTab.querySelector('table');
        if (!table) return;

        const searchTerms = searchText.toLowerCase().split(' ').filter(term => term.length > 0);
        const rows = table.querySelectorAll('tbody tr');
        
        // Track visible objects for summary update
        const visibleObjects = [];
        
        // Keep track of which squads have visible members
        const squadsWithVisibleMembers = new Set();
        
        // First pass: check which squad members are visible
        rows.forEach(row => {
            if (!row.classList.contains('party-group')) {
                const searchableContent = [
                    row.cells[2].textContent, // name
                    row.cells[3].textContent, // type name
                    row.cells[4].textContent, // owner name
                    row.cells[1].textContent  // entity ID
                ].join(' ').toLowerCase();

                const isMatch = searchText === '' || searchTerms.every(term => searchableContent.includes(term));

                if (isMatch) {
                    // Find which squad this row belongs to
                    const squadClass = Array.from(row.classList).find(cls => cls.startsWith('squad-'));
                    if (squadClass) {
                        squadsWithVisibleMembers.add(squadClass);
                    }
                }
            }
        });

        // Second pass: show/hide rows based on search and squad visibility
        rows.forEach(row => {
            if (row.classList.contains('party-group')) {
                // Check if this squad header has any visible members
                const squadId = row.querySelector('.squad-toggle')?.dataset.target;
                if (squadId && squadsWithVisibleMembers.has(squadId)) {
                    row.classList.remove('hidden');
                    // Expand the squad if there's a search
                    if (searchText) {
                        const toggleIcon = row.querySelector('.toggle-icon');
                        if (toggleIcon) {
                            toggleIcon.src = '/assets/images/minus.png';
                            toggleIcon.alt = 'Collapse';
                            row.querySelector('.squad-toggle').dataset.state = 'expanded';
                        }
                    }
                } else {
                    row.classList.add('hidden');
                }
            } else {
                const searchableContent = [
                    row.cells[2].textContent, // name
                    row.cells[3].textContent, // type name
                    row.cells[4].textContent, // owner name
                    row.cells[1].textContent  // entity ID
                ].join(' ').toLowerCase();

                const isMatch = searchText === '' || searchTerms.every(term => searchableContent.includes(term));

                if (isMatch) {
                    row.classList.remove('hidden');
                    visibleObjects.push({
                        typeName: row.cells[3].textContent,
                        name: row.cells[2].textContent,
                        iffStatus: row.cells[5].textContent
                    });
                } else {
                    row.classList.add('hidden');
                }
            }
        });

        // Update summary counts with visible objects
        const summaryDiv = document.querySelector('.ship-count-summary');
        if (summaryDiv) {
            summaryDiv.innerHTML = this.createShipCountSummary(visibleObjects);
        }
    },

    getIffStatusClass(iffStatus, isLeader = false) {
        if (!iffStatus) return '';
        const statusMap = {
            Friend: isLeader ? 'friend-leader' : 'friend',
            Enemy: isLeader ? 'enemy-leader' : 'enemy',
            Neutral: isLeader ? 'neutral-leader' : 'neutral'
        };
        return statusMap[iffStatus] || '';
    },

    filterScannedObjectsByCoordinates(x, y) {
        const table = UIController.elements.scannedObjectsTab.querySelector('table');
        if (!table) return;

        // Clear any previous cell highlighting
        document.querySelectorAll('.grid .cell.highlighted').forEach(cell => {
            cell.classList.remove('highlighted');
        });

        // Highlight the selected cell
        const selectedCell = document.querySelector(`.grid .cell[data-x="${x}"][data-y="${y}"]`);
        if (selectedCell) {
            selectedCell.classList.add('highlighted');
        }

        const filteredObjects = [];
        const rows = table.querySelectorAll('tbody tr');
        let currentPartyHeader = null;
        let hasVisibleMembersInParty = false;

        rows.forEach(row => {
            if (row.classList.contains('party-group')) {
                currentPartyHeader = row;
                hasVisibleMembersInParty = false;
                currentPartyHeader.classList.add('hidden');
            } else if (currentPartyHeader) {
                const rowX = parseInt(row.cells[6].textContent, 10);
                const rowY = parseInt(row.cells[7].textContent, 10);
                const isVisible = rowX === x && rowY === y;

                if (isVisible) {
                    row.classList.remove('hidden');
                    hasVisibleMembersInParty = true;
                    // Add to filtered objects for counting
                    filteredObjects.push({
                        typeName: row.cells[3].textContent,
                        name: row.cells[2].textContent,
                        iffStatus: row.cells[5].textContent
                    });
                } else {
                    row.classList.add('hidden');
                }

                if (hasVisibleMembersInParty) {
                    currentPartyHeader.classList.remove('hidden');
                }
            }
        });

        // Update summary counts with filtered objects
        const summaryDiv = document.querySelector('.ship-count-summary');
        if (summaryDiv) {
            summaryDiv.innerHTML = this.createShipCountSummary(filteredObjects);
        }
    },

    clearFilter() {
        const searchInput = document.getElementById('shipSearch');
        if (searchInput) {
            searchInput.value = '';
        }
        
        const table = UIController.elements.scannedObjectsTab.querySelector('table');
        if (!table) return;

        // Clear cell highlighting
        document.querySelectorAll('.grid .cell.highlighted').forEach(cell => {
            cell.classList.remove('highlighted');
        });

        // Reset squad toggles to collapsed state
        document.querySelectorAll('.squad-toggle').forEach(toggle => {
            const toggleIcon = toggle.querySelector('.toggle-icon');
            toggleIcon.src = '/assets/images/plus.png';
            toggleIcon.alt = 'Expand';
            toggle.dataset.state = 'collapsed';
        });

        // Show all rows
        table.querySelectorAll('tbody tr').forEach(row => {
            if (!row.classList.contains('party-group') && row.classList.contains(row.classList[0])) {
                row.classList.add('hidden');
            } else {
                row.classList.remove('hidden');
            }
        });

        // Reset summary counts to show all objects
        const rows = table.querySelectorAll('tbody tr:not(.party-group)');
        const allObjects = Array.from(rows).map(row => ({
            typeName: row.cells[3].textContent,
            name: row.cells[2].textContent,
            iffStatus: row.cells[5].textContent
        }));

        const summaryDiv = document.querySelector('.ship-count-summary');
        if (summaryDiv) {
            summaryDiv.innerHTML = this.createShipCountSummary(allObjects);
        }
    },

    sortTable(n) {
        const rows = Array.from(UIController.elements.scansTable.rows).slice(1);
        AppState.sortOrder = AppState.sortOrder === 'asc' ? 'desc' : 'asc';
        
        rows.sort((a, b) => {
            const x = a.cells[n].textContent.trim();
            const y = b.cells[n].textContent.trim();
            return AppState.sortOrder === 'asc' 
                ? x.localeCompare(y, undefined, { numeric: true })
                : y.localeCompare(x, undefined, { numeric: true });
        });

        rows.forEach(row => UIController.elements.scansTable.appendChild(row));
    },

    clearScannedObjectsTable() {
        UIController.elements.scannedObjectsTab.innerHTML = '';
    },

    filterObjectsByXY(x, y) {
        return function(obj) {
            return obj.x === x && obj.y === y;
        };
    },

    countObjectTypes(objects) {
        return objects.reduce((counts, obj) => {
            const type = obj.iffStatus || 'Unknown';
            counts[type] = (counts[type] || 0) + 1;
            return counts;
        }, {});
    },

    showErrorMessage(message) {
        NotificationController.show({
            message: `Error: ${message}`,
            type: 'error'
        });
    }
};

// Data Controller
const DataController = {
    async handleFileUpload() {
        if (!UIController.elements.fileInput.files.length) return;

        const formData = new FormData();
        formData.append('xmlFile', UIController.elements.fileInput.files[0]);

        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            NotificationController.show(data);
            UIController.elements.fileInput.value = '';
            await DataController.fetchScansData();
        } catch (error) {
            NotificationController.show({ 
                message: 'Upload failed: ' + error.message, 
                type: 'error' 
            });
        }
    },

    async handleResetDB() {
        const formData = new FormData();
        formData.append('resetDB', 'true');

        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            NotificationController.show(data);
            await DataController.fetchScansData();
            TableController.clearScannedObjectsTable();
            GridController.clearGridColors();
        } catch (error) {
            NotificationController.show({ 
                message: 'Reset failed: ' + error.message,
                type: 'error'
            });
        }
    },

    async fetchScansData() {
        try {
            const response = await fetch('?fetchScans=true');
            const data = await response.json();
            TableController.updateScansTable(data);
        } catch (error) {
            NotificationController.show({
                message: 'Error fetching scans data: ' + error.message,
                type: 'error'
            });
        }
    },

    async fetchScannedObjects(scanId, coords) {
        try {
            const response = await fetch(`?fetchScannedObjects=true&scanID=${scanId}`);
            if (!response.ok) throw new Error('Network response was not ok');
            
            const data = await response.json();
            TableController.updateScannedObjectsTable(data);
            UIController.elements.scanLocationHeader.textContent = `Scan for ${coords}`;
            TabController.openTab(null, 'ScannedObjects');
        } catch (error) {
            NotificationController.show({
                message: 'Error fetching scanned objects: ' + error.message,
                type: 'error'
            });
        }
    }
};

// Notification Controller
const NotificationController = {
    show(data) {
        const notification = document.createElement('div');
        notification.className = `notification ${data.type}`;
        notification.innerHTML = `
            <span>${data.message}</span>
            <button onclick="this.parentElement.remove()">&times;</button>
        `;
        UIController.elements.notificationContainer.prepend(notification);
    }
};

// Initialize the application
document.addEventListener('DOMContentLoaded', () => UIController.init());

// Expose necessary functions to global scope for onclick handlers
window.handleResetDB = DataController.handleResetDB;
window.openTab = TabController.openTab;
window.sortTable = TableController.sortTable;
</script>
</body>
</html>