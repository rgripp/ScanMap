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
    } elseif (isset($_POST['deleteScan'])) {
        $scanId = filter_input(INPUT_POST, 'scanId', FILTER_VALIDATE_INT);
        if ($scanId) {
            $result = deleteScan($pdo, $scanId);
        } else {
            $result = ["message" => "Invalid scan ID", "type" => "error"];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Fetch scans data
$scans = fetchScans($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="stylesheet" type="text/css" href="/assets/css/styles.css">
    <style>
        .admin-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background-color: #2d2d2d;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }
        
        .admin-header {
            text-align: center;
            color: #ddd;
            margin-bottom: 20px;
        }

        .return-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #0066cc;
            text-decoration: none;
            padding: 8px 16px;
            background-color: #333;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .return-link:hover {
            background-color: #444;
        }

        .delete-button {
            padding: 6px 12px;
            background-color: #8b4343;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .delete-button:hover {
            background-color: #a65353;
        }

        .controls-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="controls-container">
            <a href="index.php" class="return-link">← Return to Main Page</a>
            <button type="button" class="reset-button" onclick="handleResetDB()">Reset Database</button>
        </div>

        <h1 class="admin-header">Admin Panel</h1>
        
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
                <?php foreach ($scans as $scan): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($scan['id']); ?></td>
                        <td><?php echo htmlspecialchars($scan['galX'] . ', ' . $scan['galY']); ?></td>
                        <td><?php echo htmlspecialchars($scan['characterName']); ?></td>
                        <td>
                            Year <?php echo htmlspecialchars($scan['years']); ?>, 
                            Day <?php echo htmlspecialchars($scan['days']); ?>, 
                            <?php echo sprintf('%02d:%02d:%02d', 
                                $scan['hours'], 
                                $scan['minutes'], 
                                $scan['seconds']
                            ); ?>
                        </td>
                        <td>
                            <button class="delete-button" 
                                    onclick="handleDeleteScan(<?php echo htmlspecialchars($scan['id']); ?>)">
                                Remove
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div id="notificationContainer"></div>
    </div>

    <script>
        // Notification Controller
        const NotificationController = {
            show(data) {
                const notification = document.createElement('div');
                notification.className = `notification ${data.type}`;
                notification.innerHTML = `
                    <span>${data.message}</span>
                    <button onclick="this.parentElement.remove()">&times;</button>
                `;
                document.getElementById('notificationContainer').prepend(notification);
            }
        };

        // Handlers
        async function handleResetDB() {
            if (!confirm('Are you sure you want to reset the entire database? This cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('resetDB', 'true');

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                NotificationController.show(data);
                if (data.type === 'success') {
                    location.reload();
                }
            } catch (error) {
                NotificationController.show({ 
                    message: 'Reset failed: ' + error.message,
                    type: 'error'
                });
            }
        }

        async function handleDeleteScan(scanId) {
            if (!confirm('Are you sure you want to delete this scan? This cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('deleteScan', 'true');
            formData.append('scanId', scanId);

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                NotificationController.show(data);
                if (data.type === 'success') {
                    const row = document.querySelector(`button[onclick="handleDeleteScan(${scanId})"]`).closest('tr');
                    row.remove();
                }
            } catch (error) {
                NotificationController.show({ 
                    message: 'Delete failed: ' + error.message,
                    type: 'error'
                });
            }
        }

        function sortTable(n) {
            const table = document.getElementById('scansTable');
            const rows = Array.from(table.rows).slice(1);
            const sortOrder = table.getAttribute('data-sort-order') === 'asc' ? 'desc' : 'asc';
            
            rows.sort((a, b) => {
                const x = a.cells[n].textContent.trim();
                const y = b.cells[n].textContent.trim();
                return sortOrder === 'asc' 
                    ? x.localeCompare(y, undefined, { numeric: true })
                    : y.localeCompare(x, undefined, { numeric: true });
            });

            rows.forEach(row => table.appendChild(row));
            table.setAttribute('data-sort-order', sortOrder);
        }
    </script>
</body>
</html>