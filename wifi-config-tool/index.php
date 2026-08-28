<?php
require_once __DIR__ . '/src/bootstrap.php';

$configPath = file_exists(__DIR__ . '/configs/config.json') ? __DIR__ . '/configs/config.json' : __DIR__ . '/config.json';
$deviceManager = new \OpenWrt\DeviceManager($configPath);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $name = $_POST['name'] ?? '';
        $url = $_POST['url'] ?? '';
        $region = $_POST['region'] ?? null;
        $username = $_POST['username'] ?? 'root';
        $sshKey = $_POST['ssh_key'] ?? '';
        $port = $_POST['port'] ?? 22;
        
        // Clean hostname/IP
        $url = preg_replace('/^https?:\/\//i', '', trim($url));
        
        if ($name && $url && $username) {
            $deviceManager->addDevice($name, $url, $username, $sshKey, $port, $region);
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
$groupedDevices = $deviceManager->getDevicesGroupedByRegion();
$regions = $deviceManager->getRegions();
$hasMultipleRegions = count($regions) > 1;
$activeRegion = $_GET['region'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenWrt WiFi Manager (SSH)</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .region-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: bold;
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #bbdefb;
        }
        .region-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .region-tab {
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            background: #f0f0f0;
            color: #333;
            font-weight: 500;
            border: 1px solid #ccc;
        }
        .region-tab.active {
            background: #337ab7;
            color: #fff;
            border-color: #2e6da4;
        }
        .region-header {
            margin-top: 20px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>OpenWrt WiFi Manager</h1>
        <div style="margin-bottom: 15px; display: flex; gap: 10px;">
            <a href="pages/bulk.php" class="btn" style="background-color: #5cb85c;">Bulk Fleet Management</a>
        </div>
        
        <?php if ($message): ?>
            <div class="card" style="background-color: #dff0d8; border-color: #d6e9c6; color: #3c763d;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0;">Add New Device (SSH Key Auth)</h2>
                <button onclick="toggleAddDevice()" class="btn" style="background-color: #777; padding: 5px 15px;">
                    <span id="toggle-icon">▼</span>
                </button>
            </div>
            <div id="add-device-form" style="display: none; margin-top: 15px;">
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="name">Device Name</label>
                        <input type="text" id="name" name="name" placeholder="e.g. redmi-rm2100-f0" required>
                    </div>

                    <?php if ($hasMultipleRegions): ?>
                        <div class="form-group">
                            <label for="region">Region / Group</label>
                            <input type="text" id="region" name="region" list="region-list" placeholder="e.g. Home, Office, etc." value="<?= htmlspecialchars($regions[0] ?? '') ?>">
                            <datalist id="region-list">
                                <?php foreach ($regions as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="url">Device IP Address / Hostname</label>
                        <input type="text" id="url" name="url" placeholder="10.0.0.200" required>
                    </div>
                    <div class="form-group">
                        <label for="username">SSH Username</label>
                        <input type="text" id="username" name="username" value="root" required>
                    </div>
                    <div class="form-group">
                        <label for="port">SSH Port</label>
                        <input type="number" id="port" name="port" value="22" required>
                    </div>
                    <div class="form-group">
                        <label for="ssh_key">Custom SSH Key Path (optional)</label>
                        <input type="text" id="ssh_key" name="ssh_key" placeholder="Leave blank to use default SSH key">
                    </div>
                    <button type="submit" class="btn">Add Device</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>Managed Devices (<?= count($devices) ?> Total)</h2>
            
            <?php if (empty($devices)): ?>
                <p>No devices managed yet.</p>
            <?php else: ?>
                <?php if ($hasMultipleRegions): ?>
                    <!-- Region Filter Tabs (Only shown when multiple regions exist) -->
                    <div class="region-tabs">
                        <a href="index.php" class="region-tab <?= $activeRegion === 'all' ? 'active' : '' ?>">All Regions (<?= count($devices) ?>)</a>
                        <?php foreach ($groupedDevices as $regName => $devList): ?>
                            <a href="index.php?region=<?= urlencode($regName) ?>" class="region-tab <?= $activeRegion === $regName ? 'active' : '' ?>">
                                <?= htmlspecialchars($regName) ?> (<?= count($devList) ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php 
                $displayGroups = ($hasMultipleRegions && $activeRegion !== 'all' && isset($groupedDevices[$activeRegion])) 
                    ? [$activeRegion => $groupedDevices[$activeRegion]] 
                    : $groupedDevices;
                ?>

                <?php foreach ($displayGroups as $regName => $devList): ?>
                    <?php if ($hasMultipleRegions): ?>
                        <div class="region-header">
                            <h3 style="margin: 0; color: #337ab7;">
                                Region: <?= htmlspecialchars($regName) ?>
                                <span style="font-size: 0.8em; font-weight: normal; color: #777;">(<?= count($devList) ?> APs)</span>
                            </h3>
                            <a href="pages/bulk.php?region=<?= urlencode($regName) ?>" class="btn" style="background-color: #5cb85c; padding: 4px 10px; font-size: 0.85em;">
                                Bulk Manage <?= htmlspecialchars($regName) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <?php if ($hasMultipleRegions): ?>
                                    <th>Region</th>
                                <?php endif; ?>
                                <th>Host / IP</th>
                                <th>Auth</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devList as $device): ?>
                                <tr>
                                    <td>
                                        <a href="pages/device.php?name=<?= urlencode($device['name']) ?>">
                                            <strong><?= htmlspecialchars($device['name']) ?></strong>
                                        </a>
                                    </td>
                                    <?php if ($hasMultipleRegions): ?>
                                        <td>
                                            <span class="region-badge"><?= htmlspecialchars($device['region'] ?? 'Default') ?></span>
                                        </td>
                                    <?php endif; ?>
                                    <td><code><?= htmlspecialchars($device['url']) ?></code></td>
                                    <td><span style="color: #2e7d32; font-weight: 500;">SSH Key</span></td>
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
                <?php endforeach; ?>
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
