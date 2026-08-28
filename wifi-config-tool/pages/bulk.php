<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';

$configPath = file_exists(__DIR__ . '/../configs/config.json') ? __DIR__ . '/../configs/config.json' : __DIR__ . '/../config.json';
$deviceManager = new \OpenWrt\DeviceManager($configPath);

$errorMessage = '';
$successMessage = '';

if (isset($_SESSION['flash_success'])) {
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $errorMessage = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$devices = $deviceManager->getDevices();
$groupedDevices = $deviceManager->getDevicesGroupedByRegion();
$regions = $deviceManager->getRegions();
$hasMultipleRegions = count($regions) > 1;

// Default region selection: If multiple regions exist, default to first region unless specified
$presetRegion = $_GET['region'] ?? ($hasMultipleRegions ? $regions[0] : 'all');
if ($presetRegion !== 'all' && !isset($groupedDevices[$presetRegion])) {
    $presetRegion = $hasMultipleRegions ? $regions[0] : 'all';
}

// Target devices based on selected group
$targetDevices = ($presetRegion !== 'all' && isset($groupedDevices[$presetRegion]))
    ? $groupedDevices[$presetRegion]
    : $devices;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $selectedDevices = $_POST['devices'] ?? [];
        
        if (empty($selectedDevices)) {
            $_SESSION['flash_error'] = "Please select at least one device.";
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
        
        if ($_POST['action'] === 'bulk_update') {
            $ssid = trim($_POST['ssid'] ?? '');
            $key = trim($_POST['key'] ?? '');
            $network = $_POST['network'] ?? 'lan';
            $mobilityDomain = trim($_POST['mobility_domain'] ?? '');
            $roaming = isset($_POST['roaming']) ? ($_POST['roaming'] === '1') : null;
            $mfp = !empty($_POST['mfp']) ? $_POST['mfp'] : null;
            
            $successCount = 0;
            $failedDevices = [];
            
            foreach ($selectedDevices as $deviceName) {
                $device = $deviceManager->getDevice($deviceName);
                if (!$device) continue;
                
                $client = new \OpenWrt\OpenWrtClient($device['url'], $device['username'] ?? 'root', $device['ssh_key'] ?? null, $device['port'] ?? 22);
                if (!$client->login()) {
                    $failedDevices[] = $deviceName . " (SSH connection failed)";
                    continue;
                }
                
                // Get wireless config to find matching SSID
                $wirelessConfig = $client->getWirelessConfig();
                $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
                
                $options = \OpenWrt\Standards::buildInterfaceOptions($ssid, $key, $network, $mfp, $roaming, $mobilityDomain);

                $updated = false;
                if (is_array($configData)) {
                    foreach ($configData as $section => $data) {
                        if (isset($data['.type']) && $data['.type'] === 'wifi-iface' && ($data['mode'] ?? 'ap') !== 'mesh' && isset($data['ssid']) && $data['ssid'] === $ssid) {
                            $client->updateWirelessInterfaceOptions($section, $options);
                            $updated = true;
                        }
                    }
                }
                
                if ($updated) {
                    $client->commit('wireless');
                    $client->applyWifi();
                    $successCount++;
                } else {
                    $failedDevices[] = $deviceName . " (SSID '$ssid' not found)";
                }
            }
            
            if ($successCount > 0) {
                $_SESSION['flash_success'] = "Successfully updated Wi-Fi '$ssid' on $successCount device(s)!";
                if (!empty($failedDevices)) {
                    $_SESSION['flash_success'] .= " (Note: Failed on " . implode(', ', $failedDevices) . ")";
                }
            } else {
                $_SESSION['flash_error'] = "Failed to update devices: " . implode(', ', $failedDevices);
            }
            
        } elseif ($_POST['action'] === 'bulk_add') {
            $newSsid = trim($_POST['new_ssid'] ?? '');
            $newKey = trim($_POST['new_key'] ?? '');
            $newNetwork = $_POST['new_network'] ?? 'lan';
            $mobilityDomain = trim($_POST['new_mobility_domain'] ?? '');
            $roaming = isset($_POST['new_roaming']) ? ($_POST['new_roaming'] === '1') : null;
            $newMfp = !empty($_POST['new_mfp']) ? $_POST['new_mfp'] : null;
            
            $successCount = 0;
            $failedDevices = [];
            
            foreach ($selectedDevices as $deviceName) {
                $device = $deviceManager->getDevice($deviceName);
                if (!$device) continue;
                
                $client = new \OpenWrt\OpenWrtClient($device['url'], $device['username'] ?? 'root', $device['ssh_key'] ?? null, $device['port'] ?? 22);
                if (!$client->login()) {
                    $failedDevices[] = $deviceName . " (SSH connection failed)";
                    continue;
                }
                
                // Find all radios
                $wirelessConfig = $client->getWirelessConfig();
                $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
                
                $radios = [];
                if (is_array($configData)) {
                    foreach ($configData as $key => $section) {
                        if (isset($section['.type']) && $section['.type'] === 'wifi-device') {
                            $radios[] = $key;
                        }
                    }
                }
                
                $addedCount = 0;
                foreach ($radios as $radio) {
                    $res = $client->addWirelessInterface($radio, $newSsid, $newKey, $newNetwork, 'psk2+ccmp', $roaming, $mobilityDomain, $newMfp);
                    if ($res) $addedCount++;
                }
                
                if ($addedCount > 0) {
                    $client->commit('wireless');
                    $client->applyWifi();
                    $successCount++;
                } else {
                    $failedDevices[] = $deviceName . " (no Wi-Fi radios found)";
                }
            }
            
            if ($successCount > 0) {
                $_SESSION['flash_success'] = "Successfully added new Wi-Fi '$newSsid' to $successCount device(s)!";
                if (!empty($failedDevices)) {
                    $_SESSION['flash_success'] .= " (Failed: " . implode(', ', $failedDevices) . ")";
                }
            } else {
                $_SESSION['flash_error'] = "Failed to add Wi-Fi to devices: " . implode(', ', $failedDevices);
            }
            
        } elseif ($_POST['action'] === 'bulk_remove') {
            $ssidToRemove = trim($_POST['remove_ssid'] ?? '');
            
            $successCount = 0;
            $failedDevices = [];
            
            foreach ($selectedDevices as $deviceName) {
                $device = $deviceManager->getDevice($deviceName);
                if (!$device) continue;
                
                $client = new \OpenWrt\OpenWrtClient($device['url'], $device['username'] ?? 'root', $device['ssh_key'] ?? null, $device['port'] ?? 22);
                if (!$client->login()) {
                    $failedDevices[] = $deviceName . " (SSH connection failed)";
                    continue;
                }
                
                // Get wireless config to find matching SSID sections
                $wirelessConfig = $client->getWirelessConfig();
                $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
                
                $removedCount = 0;
                if (is_array($configData)) {
                    foreach ($configData as $section => $data) {
                        if (isset($data['.type']) && $data['.type'] === 'wifi-iface' && isset($data['ssid']) && $data['ssid'] === $ssidToRemove) {
                            $client->deleteWirelessInterface($section);
                            $removedCount++;
                        }
                    }
                }
                
                if ($removedCount > 0) {
                    $client->commit('wireless');
                    $client->applyWifi();
                    $successCount++;
                } else {
                    $failedDevices[] = $deviceName . " (SSID not found)";
                }
            }
            
            if ($successCount > 0) {
                $_SESSION['flash_success'] = "Removed Wi-Fi '$ssidToRemove' from $successCount device(s).";
                if (!empty($failedDevices)) {
                    $_SESSION['flash_success'] .= " (Failed: " . implode(', ', $failedDevices) . ")";
                }
            } else {
                $_SESSION['flash_error'] = "Failed to remove Wi-Fi from devices: " . implode(', ', $failedDevices);
            }
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Fetch default SSIDs and settings from the FIRST DEVICE OF THE SELECTED GROUP
$availableSSIDs = [];
$ssidSettings = [];
$availableNetworks = [];
$referenceDevice = !empty($targetDevices) ? $targetDevices[0] : null;

if ($referenceDevice) {
    $client = new \OpenWrt\OpenWrtClient($referenceDevice['url'], $referenceDevice['username'] ?? 'root', $referenceDevice['ssh_key'] ?? null, $referenceDevice['port'] ?? 22);
    if ($client->login()) {
        $wirelessConfig = $client->getWirelessConfig();
        $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
        
        if (is_array($configData)) {
            foreach ($configData as $section => $data) {
                if (isset($data['.type']) && $data['.type'] === 'wifi-iface' && ($data['mode'] ?? 'ap') !== 'mesh' && isset($data['ssid'])) {
                    $ssid = $data['ssid'];
                    if (!in_array($ssid, $availableSSIDs)) {
                        $availableSSIDs[] = $ssid;
                        $ssidSettings[$ssid] = [
                            'key' => $data['key'] ?? '',
                            'network' => $data['network'] ?? 'lan',
                            'mobility_domain' => $data['mobility_domain'] ?? '',
                            'ieee80211w' => $data['ieee80211w'] ?? '1'
                        ];
                    }
                }
            }
        }
        $availableNetworks = $client->getNetworkInterfaces();
    }
}

if (empty($availableNetworks)) {
    $availableNetworks = ['lan', 'guest', 'iot'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Bulk Wi-Fi Update - Fleet</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .help-text {
            font-size: 0.85em;
            color: #64748b;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="btn" style="background-color: #64748b; margin-bottom: 12px; font-size: 0.9rem;">← Back to Fleet</a>
        
        <h1 style="margin-top: 5px;">Bulk Wi-Fi Update</h1>
        <p style="color: #64748b; margin-top: 0; font-size: 0.95rem;">Sync Wi-Fi credentials across multiple access points simultaneously.</p>

        <?php if ($errorMessage): ?>
            <div class="card" style="background-color: #f2dede; border-color: #ebccd1; color: #a94442; font-weight: 500;">
                ⚠️ <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="card" style="background-color: #dff0d8; border-color: #d6e9c6; color: #3c763d; font-weight: 500;">
                ✅ <?= htmlspecialchars($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($devices)): ?>
            <div class="card">
                <p>No devices configured yet. <a href="../index.php">Add an Access Point</a> first.</p>
            </div>
        <?php else: ?>
            
            <?php if ($hasMultipleRegions): ?>
                <!-- Group Switcher Tabs -->
                <div class="group-switcher">
                    <span style="font-weight: 600; color: #444;">Select Site / Group:</span>
                    <?php foreach ($groupedDevices as $r => $devs): ?>
                        <a href="bulk.php?region=<?= urlencode($r) ?>" class="group-btn <?= $presetRegion === $r ? 'active' : '' ?>">
                            📍 <?= htmlspecialchars($r) ?> (<?= count($devs) ?> APs)
                        </a>
                    <?php endforeach; ?>
                    <a href="bulk.php?region=all" class="group-btn <?= $presetRegion === 'all' ? 'active' : '' ?>">
                        🌐 All Groups (<?= count($devices) ?> APs)
                    </a>
                </div>
            <?php endif; ?>

            <!-- Target Devices Selection Card -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <h3 style="margin: 0;">
                            Target Access Points
                            <?php if ($presetRegion !== 'all'): ?>
                                <span style="color: #337ab7; font-weight: normal;">(in Group: <strong><?= htmlspecialchars($presetRegion) ?></strong>)</span>
                            <?php endif; ?>
                        </h3>
                        <p class="help-text" style="margin-bottom: 0;">Checked devices will receive your Wi-Fi changes:</p>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" onclick="selectAll()" class="btn" style="background-color: #5bc0de; padding: 6px 14px; font-size: 0.9em;">Select All</button>
                        <button type="button" onclick="selectNone()" class="btn" style="background-color: #777; padding: 6px 14px; font-size: 0.9em;">Deselect All</button>
                    </div>
                </div>

                <!-- Display ONLY devices in the selected group (or grouped if 'all') -->
                <?php if ($presetRegion !== 'all'): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;">
                        <?php foreach ($targetDevices as $device): ?>
                            <label class="device-card">
                                <input type="checkbox" class="device-checkbox" value="<?= htmlspecialchars($device['name']) ?>" checked>
                                <div>
                                    <strong style="font-size: 1.05em;"><?= htmlspecialchars($device['name']) ?></strong>
                                    <div style="font-size: 0.85em; color: #666;"><code><?= htmlspecialchars($device['url']) ?></code></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedDevices as $regName => $devList): ?>
                        <div style="background: #fafafa; border: 1px solid #e5e5e5; border-radius: 6px; padding: 12px; margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                                <strong style="color: #337ab7; font-size: 1.05em;">Group: <?= htmlspecialchars($regName) ?> (<?= count($devList) ?> APs)</strong>
                                <div>
                                    <button type="button" onclick="toggleRegion('<?= htmlspecialchars($regName, ENT_QUOTES) ?>', true)" class="btn" style="padding: 2px 8px; font-size: 0.8em; background-color: #5cb85c;">Check All</button>
                                    <button type="button" onclick="toggleRegion('<?= htmlspecialchars($regName, ENT_QUOTES) ?>', false)" class="btn" style="padding: 2px 8px; font-size: 0.8em; background-color: #999;">Uncheck</button>
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 8px;">
                                <?php foreach ($devList as $device): ?>
                                    <label class="device-card">
                                        <input type="checkbox" class="device-checkbox" data-region="<?= htmlspecialchars($regName) ?>" value="<?= htmlspecialchars($device['name']) ?>" checked>
                                        <div>
                                            <strong><?= htmlspecialchars($device['name']) ?></strong>
                                            <div style="font-size: 0.85em; color: #666;"><code><?= htmlspecialchars($device['url']) ?></code></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Card 1: Bulk Update Existing Wi-Fi (Most Common) -->
            <div class="card" style="border-left: 5px solid #337ab7;">
                <h2 style="margin-top: 0; color: #337ab7;">1. Update Wi-Fi Password / Settings</h2>
                <p style="color: #666;">Select an existing Wi-Fi network below to change its password or settings across all checked access points.</p>
                
                <?php if (empty($availableSSIDs)): ?>
                    <p style="color: #a94442;">Could not load existing Wi-Fi networks from the reference access point (<code><?= htmlspecialchars($referenceDevice['url'] ?? '') ?></code>). Please ensure the device is online.</p>
                <?php else: ?>
                    <form method="post" onsubmit="return confirmAction('Update Wi-Fi network across selected access points?');">
                        <input type="hidden" name="action" value="bulk_update">
                        
                        <div class="form-group">
                            <label for="ssid"><strong>Wi-Fi Name (SSID) to Update</strong></label>
                            <select id="ssid" name="ssid" required style="font-size: 1.05em; padding: 8px; font-weight: bold;">
                                <?php foreach ($availableSSIDs as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text">Selecting a Wi-Fi name automatically pre-fills its current password and settings below.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="key"><strong>Wi-Fi Password</strong></label>
                            <div style="position: relative; max-width: 450px;">
                                <input type="password" id="key" name="key" required placeholder="Enter new Wi-Fi password (at least 8 chars)" style="padding-right: 75px;">
                                <button type="button" class="btn-toggle-pwd" onclick="togglePassword('key', this)">👁️ Show</button>
                            </div>
                            <div class="help-text">WPA2-PSK Pre-Shared Key (minimum 8 characters).</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="network"><strong>Network Type (VLAN)</strong></label>
                            <select id="network" name="network" style="max-width: 300px;">
                                <?php foreach ($availableNetworks as $net): ?>
                                    <option value="<?= htmlspecialchars($net) ?>" <?= $net === 'lan' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(strtoupper($net)) ?> (<?= $net === 'lan' ? 'Main Home Network' : ($net === 'guest' ? 'Guest Network' : 'Network') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn" style="background-color: #337ab7; padding: 10px 24px; font-size: 1.05em; font-weight: bold;">
                                💾 Save & Apply Wi-Fi to Selected APs
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Card 2: Bulk Add New Wi-Fi (Collapsible) -->
            <div class="card" style="border-left: 5px solid #5cb85c;">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="toggleSection('bulk-add-ssid-form', 'toggle-bulk-add-icon')">
                    <div>
                        <h3 style="margin: 0; color: #2e7d32;">➕ Create a New Wi-Fi Network</h3>
                        <p class="help-text" style="margin: 3px 0 0 0;">Broadcast a new Wi-Fi SSID (e.g. Guest Network or IoT) on both 2.4GHz & 5GHz bands across selected APs.</p>
                    </div>
                    <button type="button" class="btn" style="background-color: #5cb85c; padding: 4px 10px;">
                        <span id="toggle-bulk-add-icon">▼ Expand</span>
                    </button>
                </div>
                
                <div id="bulk-add-ssid-form" style="display: none; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                    <form method="post" onsubmit="return confirmAction('Add new Wi-Fi network to selected access points?');">
                        <input type="hidden" name="action" value="bulk_add">
                        
                        <div class="form-group">
                            <label for="new_ssid"><strong>New Wi-Fi Name (SSID)</strong></label>
                            <input type="text" id="new_ssid" name="new_ssid" required placeholder="e.g. MyHome-Guest" style="max-width: 450px;">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_key"><strong>Wi-Fi Password</strong></label>
                            <div style="position: relative; max-width: 450px;">
                                <input type="password" id="new_key" name="new_key" required placeholder="Enter password (at least 8 characters)" style="padding-right: 75px;">
                                <button type="button" class="btn-toggle-pwd" onclick="togglePassword('new_key', this)">👁️ Show</button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_network"><strong>Assign to Network</strong></label>
                            <select id="new_network" name="new_network" style="max-width: 300px;">
                                <?php foreach ($availableNetworks as $net): ?>
                                    <option value="<?= htmlspecialchars($net) ?>" <?= $net === 'lan' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(strtoupper($net)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn" style="background-color: #5cb85c; padding: 10px 20px; font-weight: bold;">
                            🚀 Add Wi-Fi Network to Selected APs
                        </button>
                    </form>
                </div>
            </div>

            <!-- Card 3: Bulk Remove Wi-Fi (Collapsible Danger Zone) -->
            <div class="card" style="border-left: 5px solid #d9534f;">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="toggleSection('bulk-remove-ssid-form', 'toggle-bulk-remove-icon')">
                    <div>
                        <h3 style="margin: 0; color: #a94442;">🗑️ Remove a Wi-Fi Network</h3>
                        <p class="help-text" style="margin: 3px 0 0 0;">Permanently delete an SSID across all selected access points.</p>
                    </div>
                    <button type="button" class="btn btn-danger" style="padding: 4px 10px;">
                        <span id="toggle-bulk-remove-icon">▼ Expand</span>
                    </button>
                </div>
                
                <div id="bulk-remove-ssid-form" style="display: none; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                    <form method="post" onsubmit="return confirm('⚠️ Are you SURE you want to permanently delete this Wi-Fi network from all selected access points?');">
                        <input type="hidden" name="action" value="bulk_remove">
                        
                        <div class="form-group">
                            <label for="remove_ssid"><strong>Select Wi-Fi Name to Delete</strong></label>
                            <select id="remove_ssid" name="remove_ssid" required style="max-width: 450px;">
                                <?php foreach ($availableSSIDs as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-danger" style="padding: 10px 20px; font-weight: bold;">
                            ❌ Delete Wi-Fi Network
                        </button>
                    </form>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        const ssidSettings = <?= json_encode($ssidSettings) ?>;
        const ssidSelect = document.getElementById('ssid');
        
        if (ssidSelect) {
            ssidSelect.addEventListener('change', function() {
                const selectedSSID = this.value;
                if (ssidSettings[selectedSSID]) {
                    const settings = ssidSettings[selectedSSID];
                    const keyInput = document.getElementById('key');
                    const networkSelect = document.getElementById('network');
                    
                    if (keyInput) keyInput.value = settings.key || '';
                    if (networkSelect) networkSelect.value = settings.network || 'lan';
                }
            });
            
            // Populate initial values on page load
            if (ssidSelect.value) {
                ssidSelect.dispatchEvent(new Event('change'));
            }
        }

        function getSelectedDevices() {
            const checkboxes = document.querySelectorAll('.device-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }

        function selectAll() {
            document.querySelectorAll('.device-checkbox').forEach(cb => cb.checked = true);
        }

        function selectNone() {
            document.querySelectorAll('.device-checkbox').forEach(cb => cb.checked = false);
        }

        function toggleRegion(regName, isChecked) {
            document.querySelectorAll('.device-checkbox').forEach(cb => {
                if (cb.dataset.region === regName) {
                    cb.checked = isChecked;
                }
            });
        }

        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🙈 Hide';
            } else {
                input.type = 'password';
                btn.textContent = '👁️ Show';
            }
        }

        function toggleSection(sectionId, iconId) {
            const el = document.getElementById(sectionId);
            const icon = document.getElementById(iconId);
            if (el.style.display === 'none') {
                el.style.display = 'block';
                icon.textContent = '▲ Collapse';
            } else {
                el.style.display = 'none';
                icon.textContent = '▼ Expand';
            }
        }

        function confirmAction(message) {
            const selected = getSelectedDevices();
            if (selected.length === 0) {
                alert('Please check at least one access point to apply changes.');
                return false;
            }
            return confirm(message + ' (' + selected.length + ' APs selected)');
        }

        // Attach selected devices to forms on submit
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                form.querySelectorAll('input[name="devices[]"]').forEach(input => input.remove());
                const selected = getSelectedDevices();
                selected.forEach(deviceName => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'devices[]';
                    input.value = deviceName;
                    form.appendChild(input);
                });
            });
        });
    </script>
</body>
</html>
