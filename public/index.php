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
        <!-- Grid Container -->
        <div class="map-container">
            <div class="grid">
                <?php
                $size = 20;
                echo '<div class="header"></div>';
                for ($x = 0; $x < $size; $x++) echo '<div class="header">'.$x.'</div>';
                for ($y = 0; $y < $size; $y++) {
                    echo '<div class="header">'.$y.'</div>';
                    for ($x = 0; $x < $size; $x++) {
                        echo '<div class="cell"><a href="?x='.$x.'&y='.$y.'" title="('.$x.', '.$y.')"></a></div>';
                    }
                }
                ?>
            </div>
        </div>

        <!-- Tabs Container -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tablinks active" onclick="openTab(event, 'ScannedObjects')">Scanned Objects</button>
                <button class="tablinks" onclick="openTab(event, 'Scans')">Scans</button>
            </div>

            <div id="ScannedObjects" class="tabcontent" style="display: block;">
                <h3>Scanned Objects</h3>
                <p>Content goes here</p>
            </div>

            <div id="Scans" class="tabcontent">
                <h3>Scans</h3>
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
        // Tab functionality
        function openTab(evt, tabName) {
            // Hide all tab content
            document.querySelectorAll('.tabcontent').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tablinks').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab and mark as active
            document.getElementById(tabName).style.display = 'block';
            evt.currentTarget.classList.add('active');
        }

        // File upload handler
        function handleFileUpload() {
            const fileInput = document.getElementById('xmlFile');
            if (!fileInput.files.length) return;

            const formData = new FormData();
            formData.append('xmlFile', fileInput.files[0]);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showNotification(data);
                fileInput.value = ''; // Clear input
            })
            .catch(error => showNotification({
                message: 'Upload failed: ' + error.message,
                type: 'error'
            }));
        }

        // Database reset handler
        function handleResetDB() {
            const formData = new FormData();
            formData.append('resetDB', 'true');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => showNotification(data))
            .catch(error => showNotification({
                message: 'Reset failed: ' + error.message,
                type: 'error'
            }));
        }

        // Notification system
        function showNotification(data) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${data.type}`;
            notification.innerHTML = `
                <span>${data.message}</span>
                <button onclick="this.parentElement.remove()">&times;</button>
            `;
            container.prepend(notification);
        }

        // Initialize first tab
        document.addEventListener('DOMContentLoaded', () => {
            openTab(event, 'ScannedObjects');
        });
    </script>
</body>
</html>