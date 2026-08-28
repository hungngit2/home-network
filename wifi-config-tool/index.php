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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>OpenWrt WiFi Fleet Manager</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .region-header {
            margin-top: 20px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #edf2f7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <div>
                <h1 style="margin: 0;">OpenWrt WiFi Fleet</h1>
                <p style="color: #64748b; margin: 4px 0 0 0; font-size: 0.95rem;">Unified management for mesh & wired access points.</p>
            </div>
            <div class="mobile-full-btn">
                <a href="pages/bulk.php<?= $activeRegion !== 'all' ? '?region=' . urlencode($activeRegion) : '' ?>" class="btn mobile-full-btn" style="background-color: #38a169; text-decoration: none;">
                    ⚡ Bulk Wi-Fi Update <?= $activeRegion !== 'all' ? '(' . htmlspecialchars($activeRegion) . ')' : '' ?>
                </a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="card" style="background-color: <?= $isError ? '#fff5f5' : '#f0fff4' ?>; border-color: <?= $isError ? '#feb2b2' : '#9ae6b4' ?>; color: <?= $isError ? '#c53030' : '#276749' ?>; font-weight: 500;">
                <?= $isError ? '⚠️ ' : '✅ ' ?><?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($hasMultipleRegions): ?>
            <!-- Group Switcher (Filter by Group) -->
            <div class="group-switcher">
                <span style="font-weight: 600; color: #475569; width: 100%; font-size: 0.88rem;">Select Site / Group:</span>
                <a href="index.php?region=all" class="group-btn <?= $activeRegion === 'all' ? 'active' : '' ?>">
                    🌐 All (<?= count($devices) ?>)
                </a>
                <?php foreach ($groupedDevices as $r => $devs): ?>
                    <a href="index.php?region=<?= urlencode($r) ?>" class="group-btn <?= $activeRegion === $r ? 'active' : '' ?>">
                        📍 <?= htmlspecialchars($r) ?> (<?= count($devs) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Add Device Card (Context-Aware UX) -->
        <div class="card" style="border-left: 4px solid #3182ce;">
            <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="toggleAddDevice()">
                <div>
                    <h3 style="margin: 0; color: #2b6cb0;">
                        ➕ Add Access Point
                        <?php if ($activeRegion !== 'all'): ?>
                            to <span class="region-badge"><?= htmlspecialchars($activeRegion) ?></span>
                        <?php endif; ?>
                    </h3>
                    <p style="margin: 3px 0 0 0; color: #64748b; font-size: 0.88rem;">
                        <?= $activeRegion !== 'all' ? 'Connect a new AP to group <strong>' . htmlspecialchars($activeRegion) . '</strong>' : 'Connect a new AP to your network' ?>
                    </p>
                </div>
                <button type="button" class="btn" style="background-color: #64748b; padding: 4px 10px; font-size: 0.85rem; min-height: 32px;">
                    <span id="toggle-icon">▼ Expand</span>
                </button>
            </div>

            <div id="add-device-form" style="display: none; margin-top: 15px; border-top: 1px solid #edf2f7; padding-top: 15px;">
                <form method="post">
                    <input type="hidden" name="action" value="add">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Access Point Name</label>
                            <input type="text" id="name" name="name" placeholder="e.g. jcg-q20-f1" required>
                        </div>

                        <div class="form-group">
                            <label for="url">IP Address</label>
                            <input type="text" id="url" name="url" placeholder="10.0.0.201" required>
                        </div>
                    </div>

                    <?php if ($activeRegion !== 'all'): ?>
                        <!-- Locked Target Group for selected region -->
                        <input type="hidden" name="region" value="<?= htmlspecialchars($activeRegion) ?>">
                        <div style="margin: 4px 0 12px 0; font-size: 0.88rem; color: #64748b;">
                            📍 Target Group: <strong><?= htmlspecialchars($activeRegion) ?></strong> 
                            <span>(Switch to <a href="index.php?region=all" style="color: #3182ce;">All Groups</a> to change)</span>
                        </div>
                    <?php elseif ($hasMultipleRegions): ?>
                        <!-- Group Selection for 'All Groups' view -->
                        <div class="form-group" style="margin: 8px 0 14px 0;">
                            <label for="group_selector">Target Group / Location</label>
                            <select id="group_selector" name="region" onchange="handleGroupChange(this.value)">
                                <?php foreach ($regions as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>">📍 <?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                                <option value="__new__">➕ Create New Group...</option>
                            </select>
                        </div>
                        <div id="new_group_container" style="display: none; margin: 8px 0 14px 0;">
                            <label for="new_region_name">New Group Name</label>
                            <input type="text" id="new_region_name" name="new_region_name" placeholder="e.g. office, warehouse">
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 8px;">
                        <button type="submit" class="btn mobile-full-btn" style="background-color: #3182ce; padding: 10px 20px; font-weight: bold;">
                            💾 Add Access Point
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Managed Devices Card -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                <h2 style="margin: 0;">
                    <?= $activeRegion !== 'all' ? 'Group: ' . htmlspecialchars($activeRegion) : 'Managed Access Points' ?>
                    <span style="font-size: 0.75em; color: #64748b; font-weight: normal;">(<?= $displayedCount ?>)</span>
                </h2>
                <?php if ($activeRegion !== 'all'): ?>
                    <a href="pages/bulk.php?region=<?= urlencode($activeRegion) ?>" class="btn" style="background-color: #38a169; padding: 5px 12px; font-size: 0.85rem; min-height: 32px;">
                        ⚡ Manage All <?= htmlspecialchars($activeRegion) ?>
                    </a>
                <?php endif; ?>
            </div>
            
            <?php if (empty($devices)): ?>
                <p style="color: #64748b;">No devices managed yet. Click "Add Access Point" above.</p>
            <?php else: ?>

                <?php foreach ($displayGroups as $regName => $devList): ?>
                    <?php if ($activeRegion === 'all' && $hasMultipleRegions): ?>
                        <div class="region-header">
                            <h3 style="margin: 0; color: #2b6cb0;">
                                📍 <?= htmlspecialchars($regName) ?>
                                <span style="font-size: 0.8em; font-weight: normal; color: #64748b;">(<?= count($devList) ?> APs)</span>
                            </h3>
                            <a href="pages/bulk.php?region=<?= urlencode($regName) ?>" class="btn" style="background-color: #38a169; padding: 4px 10px; font-size: 0.82rem; min-height: 30px;">
                                Bulk Manage
                            </a>
                        </div>
                    <?php endif; ?>

                    <table>
                        <thead>
                            <tr>
                                <th>Access Point</th>
                                <?php if ($activeRegion === 'all' && $hasMultipleRegions): ?>
                                    <th>Group</th>
                                <?php endif; ?>
                                <th style="text-align: right; width: 90px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devList as $device): ?>
                                <tr>
                                    <td>
                                        <a href="pages/device.php?name=<?= urlencode($device['name']) ?>" class="ap-table-link" title="IP Address: <?= htmlspecialchars($device['url']) ?>">
                                            📡 <?= htmlspecialchars($device['name']) ?>
                                        </a>
                                    </td>
                                    <?php if ($activeRegion === 'all' && $hasMultipleRegions): ?>
                                        <td>
                                            <span class="region-badge">📍 <?= htmlspecialchars($device['region'] ?? 'Default') ?></span>
                                        </td>
                                    <?php endif; ?>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: flex-end;">
                                            <a href="pages/device.php?name=<?= urlencode($device['name']) ?>" class="btn-icon btn-icon-manage" title="Manage Wi-Fi (<?= htmlspecialchars($device['name']) ?>)">
                                                ⚙️
                                            </a>
                                            <form method="post" style="margin: 0; display: inline;" onsubmit="return confirm('Remove access point \'<?= htmlspecialchars($device['name']) ?>\'?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="name" value="<?= htmlspecialchars($device['name']) ?>">
                                                <button type="submit" class="btn-icon btn-icon-danger" title="Remove Access Point">🗑️</button>
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
                icon.textContent = '▲ Collapse';
            } else {
                form.style.display = 'none';
                icon.textContent = '▼ Expand';
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
