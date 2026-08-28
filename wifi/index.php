<?php
require_once __DIR__ . '/src/bootstrap.php';

$configPath = __DIR__ . '/config.json';
$deviceManager = new \OpenWrt\DeviceManager($configPath);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $name = $_POST['name'] ?? '';
        $url = $_POST['url'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // Auto-prepend http:// if not present
        if ($url && !preg_match('/^https?:\/\//i', $url)) {
            $url = 'http://' . $url;
        }
        
        if ($name && $url && $username) {
            $deviceManager->addDevice($name, $url, $username, $password);
            $message = "Device added successfully.";
        } else {
            $message = "Please fill in all required fields.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $name = $_POST['name'] ?? '';
        if ($name) {
            $deviceManager->removeDevice($name);
            $message = "Device removed successfully.";
        }
    }
}

$devices = $deviceManager->getDevices();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenWrt WiFi Manager</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <h1>OpenWrt WiFi Manager</h1>
        <div style="margin-bottom: 15px;">
            <a href="pages/bulk.php" class="btn" style="background-color: #5cb85c;">Bulk Device Management</a>
        </div>
        
        <?php if ($message): ?>
            <div class="card" style="background-color: #dff0d8; border-color: #d6e9c6; color: #3c763d;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0;">Add New Device</h2>
                <button onclick="toggleAddDevice()" class="btn" style="background-color: #777; padding: 5px 15px;">
                    <span id="toggle-icon">▼</span>
                </button>
            </div>
            <div id="add-device-form" style="display: none; margin-top: 15px;">
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="name">Device Name</label>
                        <input type="text" id="name" name="name" placeholder="e.g. Living Room AP" required>
                    </div>
                    <div class="form-group">
                        <label for="url">Device IP Address</label>
                        <input type="text" id="url" name="url" placeholder="192.168.1.1" required pattern="^([0-9]{1,3}\.){3}[0-9]{1,3}(:[0-9]+)?$|^[a-zA-Z0-9.-]+(:[0-9]+)?$">
                        <small style="color:#777;">Enter IP address or hostname (http:// will be added automatically)</small>
                    </div>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="root" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Leave empty if none">
                    </div>
                    <button type="submit" class="btn">Add Device</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>Managed Devices</h2>
            <?php if (empty($devices)): ?>
                <p>No devices managed yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $device): ?>
                            <tr>
                                <td><a title="<?= htmlspecialchars($device['url']) ?>" href="pages/device.php?name=<?= urlencode($device['name']) ?>"><strong><?= htmlspecialchars($device['name']) ?></strong></a></td>
                                <td>
                                    <div style="display: flex; gap: 5px; align-items: center;">
                                        <a href="pages/device.php?name=<?= urlencode($device['name']) ?>" style="display: inline-flex; align-items: center; text-decoration: none;">
                                            <button class="btn">Manage</button>
                                        </a>
                                        <form method="post" style="margin: 0;" onsubmit="return confirm('Are you sure?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="name" value="<?= htmlspecialchars($device['name']) ?>">
                                            <button type="submit" class="btn btn-danger" style="display: inline-flex; align-items: center;">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleAddDevice() {
            const form = document.getElementById('add-device-form');
            const icon = document.getElementById('toggle-icon');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                icon.textContent = '▲';
            } else {
                form.style.display = 'none';
                icon.textContent = '▼';
            }
        }
    </script>
</body>
</html>
