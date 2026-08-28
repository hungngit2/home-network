<?php
require_once __DIR__ . '/src/bootstrap.php';

$configPath = file_exists(__DIR__ . '/configs/config.json') ? __DIR__ . '/configs/config.json' : __DIR__ . '/config.json';
$deviceManager = new \OpenWrt\DeviceManager($configPath);

$message = '';
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $newRegionName = trim($_POST['new_region_name'] ?? '');
        
        if ($region === '__new__' && !empty($newRegionName)) {
            $region = $newRegionName;
        } elseif ($region === '__new__') {
            $region = 'Default';
        }
        
        $username = trim($_POST['username'] ?? 'root') ?: 'root';
        $port = (int)($_POST['port'] ?? 22) ?: 22;
        
        // Clean hostname/IP
        $url = preg_replace('/^https?:\/\//i', '', $url);
        
        if ($name && $url) {
            $deviceManager->addDevice($name, $url, $username, '', $port, $region);
            $message = "Access Point '$name' added successfully to group '" . htmlspecialchars($region ?: 'Default') . "'.";
        } else {
            $message = "Please provide both an Access Point Name and an IP address.";
            $isError = true;
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $deviceManager->removeDevice($name);
            $message = "Access Point '$name' removed successfully.";
        }
    }
}

$devices = $deviceManager->getDevices();
$groupedDevices = $deviceManager->getDevicesGroupedByRegion();
$regions = $deviceManager->getRegions();
$hasMultipleRegions = count($regions) > 1;

// Selected region filter
$activeRegion = $_GET['region'] ?? 'all';
if ($activeRegion !== 'all' && !isset($groupedDevices[$activeRegion])) {
    $activeRegion = 'all';
}

// Displayed groups
$displayGroups = ($activeRegion !== 'all' && isset($groupedDevices[$activeRegion]))
    ? [$activeRegion => $groupedDevices[$activeRegion]]
    : $groupedDevices;

$displayedCount = 0;
foreach ($displayGroups as $list) {
    $displayedCount += count($list);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenWrt WiFi Fleet Manager</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .group-switcher {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 15px 0 20px 0;
            padding: 12px;
            background: #eef2f7;
            border-radius: 8px;
            align-items: center;
        }
        .group-btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            background: #fff;
            color: #333;
            font-weight: 600;
            border: 1px solid #ccd0d5;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .group-btn:hover {
            background: #f8f9fa;
            border-color: #337ab7;
        }
        .group-btn.active {
            background: #337ab7;
            color: #fff;
            border-color: #2e6da4;
            box-shadow: 0 2px 4px rgba(51, 122, 183, 0.3);
        }
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
        .region-header {
            margin-top: 20px;
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 2px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h1 style="margin: 0;">OpenWrt WiFi Fleet Manager</h1>
                <p style="color: #666; margin: 4px 0 0 0;">Unified management for mesh & wired access points across all locations.</p>
            </div>
            <div>
                <a href="pages/bulk.php<?= $activeRegion !== 'all' ? '?region=' . urlencode($activeRegion) : '' ?>" class="btn" style="background-color: #5cb85c; padding: 10px 18px; font-size: 1em; font-weight: bold; text-decoration: none;">
                    ⚡ Bulk Wi-Fi Update <?= $activeRegion !== 'all' ? '(' . htmlspecialchars($activeRegion) . ')' : '' ?>
                </a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="card" style="background-color: <?= $isError ? '#f2dede' : '#dff0d8' ?>; border-color: <?= $isError ? '#ebccd1' : '#d6e9c6' ?>; color: <?= $isError ? '#a94442' : '#3c763d' ?>; font-weight: 500; margin-top: 15px;">
                <?= $isError ? '⚠️ ' : '✅ ' ?><?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($hasMultipleRegions): ?>
            <!-- Group Switcher (Filter by Group) -->
            <div class="group-switcher">
                <span style="font-weight: 600; color: #444;">Select Site / Group:</span>
                <a href="index.php?region=all" class="group-btn <?= $activeRegion === 'all' ? 'active' : '' ?>">
                    🌐 All Groups (<?= count($devices) ?>)
                </a>
                <?php foreach ($groupedDevices as $r => $devs): ?>
                    <a href="index.php?region=<?= urlencode($r) ?>" class="group-btn <?= $activeRegion === $r ? 'active' : '' ?>">
                        📍 <?= htmlspecialchars($r) ?> (<?= count($devs) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Add Device Card (Context-Aware UX) -->
        <div class="card" style="border-left: 5px solid #337ab7;">
            <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="toggleAddDevice()">
                <div>
                    <h3 style="margin: 0; color: #337ab7;">
                        ➕ Add Access Point
                        <?php if ($activeRegion !== 'all'): ?>
                            to <span class="region-badge"><?= htmlspecialchars($activeRegion) ?></span>
                        <?php endif; ?>
                    </h3>
                    <p style="margin: 3px 0 0 0; color: #666; font-size: 0.9em;">
                        <?= $activeRegion !== 'all' ? 'Connect a new router/AP to group <strong>' . htmlspecialchars($activeRegion) . '</strong>' : 'Connect a new router/AP to your network fleet' ?>
                    </p>
                </div>
                <button type="button" class="btn" style="background-color: #6c757d; padding: 5px 12px; font-size: 0.9em;">
                    <span id="toggle-icon">▼ Expand Form</span>
                </button>
            </div>

            <div id="add-device-form" style="display: none; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                <form method="post">
                    <input type="hidden" name="action" value="add">

                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="name"><strong>Access Point Name</strong></label>
                            <input type="text" id="name" name="name" placeholder="e.g. jcg-q20-f1" required style="width: 100%;">
                        </div>

                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="url"><strong>IP Address / Hostname</strong></label>
                            <input type="text" id="url" name="url" placeholder="10.0.0.201" required style="width: 100%;">
                        </div>
                    </div>

                    <?php if ($activeRegion !== 'all'): ?>
                        <!-- Locked Target Group for selected region -->
                        <input type="hidden" name="region" value="<?= htmlspecialchars($activeRegion) ?>">
                        <div style="margin: 8px 0 12px 0; font-size: 0.9em; color: #555;">
                            📍 Target Group: <strong><?= htmlspecialchars($activeRegion) ?></strong> 
                            <span style="color: #999;">(To add to a different group, select it from the top switcher or click <a href="index.php?region=all" style="color: #337ab7;">All Groups</a>)</span>
                        </div>
                    <?php elseif ($hasMultipleRegions): ?>
                        <!-- Group Selection for 'All Groups' view -->
                        <div class="form-group" style="margin: 10px 0;">
                            <label for="group_selector"><strong>Target Group / Location</strong></label>
                            <select id="group_selector" name="region" style="width: 100%; max-width: 320px; padding: 7px;" onchange="handleGroupChange(this.value)">
                                <?php foreach ($regions as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>">📍 <?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                                <option value="__new__">➕ Create New Group...</option>
                            </select>
                        </div>
                        <div id="new_group_container" style="display: none; margin: 8px 0 12px 0;">
                            <label for="new_region_name"><strong>New Group Name</strong></label>
                            <input type="text" id="new_region_name" name="new_region_name" placeholder="e.g. office, warehouse" style="max-width: 320px; width: 100%;">
                        </div>
                    <?php endif; ?>

                    <div>
                        <button type="submit" class="btn" style="background-color: #337ab7; padding: 9px 22px; font-weight: bold; font-size: 1em;">
                            💾 Add Access Point
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Managed Devices Card -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h2 style="margin: 0;">
                    <?= $activeRegion !== 'all' ? 'Access Points in Group: ' . htmlspecialchars($activeRegion) : 'All Managed Access Points' ?>
                    <span style="font-size: 0.75em; color: #777; font-weight: normal;">(<?= $displayedCount ?> Total)</span>
                </h2>
                <?php if ($activeRegion !== 'all'): ?>
                    <a href="pages/bulk.php?region=<?= urlencode($activeRegion) ?>" class="btn" style="background-color: #5cb85c; padding: 5px 12px; font-size: 0.9em;">
                        ⚡ Manage All <?= htmlspecialchars($activeRegion) ?> APs
                    </a>
                <?php endif; ?>
            </div>
            
            <?php if (empty($devices)): ?>
                <p>No devices managed yet. Click "Add Access Point" above.</p>
            <?php else: ?>

                <?php foreach ($displayGroups as $regName => $devList): ?>
                    <?php if ($activeRegion === 'all' && $hasMultipleRegions): ?>
                        <div class="region-header">
                            <h3 style="margin: 0; color: #337ab7;">
                                📍 Group: <?= htmlspecialchars($regName) ?>
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
                                <?php if ($activeRegion === 'all' && $hasMultipleRegions): ?>
                                    <th>Group</th>
                                <?php endif; ?>
                                <th>IP Address</th>
                                <th>Auth</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devList as $device): ?>
                                <tr>
                                    <td>
                                        <a href="pages/device.php?name=<?= urlencode($device['name']) ?>" style="text-decoration: none; color: #337ab7;">
                                            <strong><?= htmlspecialchars($device['name']) ?></strong>
                                        </a>
                                    </td>
                                    <?php if ($activeRegion === 'all' && $hasMultipleRegions): ?>
                                        <td>
                                            <span class="region-badge"><?= htmlspecialchars($device['region'] ?? 'Default') ?></span>
                                        </td>
                                    <?php endif; ?>
                                    <td><code><?= htmlspecialchars($device['url']) ?></code></td>
                                    <td><span style="color: #2e7d32; font-weight: 500;">SSH Key</span></td>
                                    <td>
                                        <div style="display: flex; gap: 6px; align-items: center;">
                                            <a href="pages/device.php?name=<?= urlencode($device['name']) ?>">
                                                <button class="btn" style="padding: 4px 10px; font-size: 0.85em;">Manage</button>
                                            </a>
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Remove access point \'<?= htmlspecialchars($device['name']) ?>\'?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="name" value="<?= htmlspecialchars($device['name']) ?>">
                                                <button type="submit" class="btn btn-danger" style="padding: 4px 10px; font-size: 0.85em;">Remove</button>
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
                icon.textContent = '▲ Collapse Form';
            } else {
                form.style.display = 'none';
                icon.textContent = '▼ Expand Form';
            }
        }

        function handleGroupChange(val) {
            const container = document.getElementById('new_group_container');
            if (!container) return;
            if (val === '__new__') {
                container.style.display = 'block';
                const input = document.getElementById('new_region_name');
                if (input) input.focus();
            } else {
                container.style.display = 'none';
            }
        }
    </script>
</body>
</html>
