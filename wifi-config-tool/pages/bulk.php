<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';

$configPath = __DIR__ . '/../config.json';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $selectedDevices = $_POST['devices'] ?? [];
        
        if (empty($selectedDevices)) {
            $_SESSION['flash_error'] = "Please select at least one device.";
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
        
        if ($_POST['action'] === 'bulk_update') {
            $ssid = $_POST['ssid'];
            $key = $_POST['key'];
            $network = $_POST['network'] ?? 'lan';
            $mobilityDomain = $_POST['mobility_domain'] ?? '';
            $mfp = $_POST['mfp'] ?? '1';
            
            $successCount = 0;
            $failedDevices = [];
            
            foreach ($selectedDevices as $deviceName) {
                $device = $deviceManager->getDevice($deviceName);
                if (!$device) continue;
                
                $client = new \OpenWrt\OpenWrtClient($device['url'], $device['username'] ?? 'root', $device['ssh_key'] ?? null, $device['port'] ?? 22);
                if (!$client->login()) {
                    $failedDevices[] = $deviceName . " (login failed)";
                    continue;
                }
                
                // Get wireless config to find matching SSID
                $wirelessConfig = $client->getWirelessConfig();
                $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
                
                $options = [
                    'key' => !empty($key) ? $key : '',
                    'encryption' => !empty($key) ? 'psk2+ccmp' : 'none',
                    'network' => $network,
                    'ieee80211w' => $mfp,
                    'wpa_disable_eapol_key_retries' => '1',
                    'multicast_to_unicast_all' => '1',
                    'mcast_rate' => '24000',
                    'basic_rate' => '12000 24000',
                    'ocv' => '0',
                    'time_advertisement' => '2',
                    'bss_transition' => '1'
                ];

                if ($roaming === '1') {
                    $options['ieee80211r'] = '1';
                    $options['ieee80211k'] = '1';
                    $options['ieee80211v'] = '1';
                    $options['ft_over_ds'] = '1';
                    $options['ft_psk_generate_local'] = '1';
                    $options['mobility_domain'] = $mobilityDomain;
                } else {
                    $options['ieee80211r'] = '0';
                    $options['ieee80211k'] = '0';
                    $options['ieee80211v'] = '0';
                    $options['mobility_domain'] = '';
                }

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
                    $failedDevices[] = $deviceName . " (SSID not found)";
                }
            }
            
            if ($successCount > 0) {
                $_SESSION['flash_success'] = "Updated SSID '$ssid' on $successCount device(s).";
                if (!empty($failedDevices)) {
                    $_SESSION['flash_success'] .= " Failed: " . implode(', ', $failedDevices);
                }
            } else {
                $_SESSION['flash_error'] = "Failed to update any devices. " . implode(', ', $failedDevices);
            }
            
        } elseif ($_POST['action'] === 'bulk_add') {
            $newSsid = $_POST['new_ssid'];
            $newKey = $_POST['new_key'];
            $newNetwork = $_POST['new_network'] ?? 'lan';
            $mobilityDomain = $_POST['new_mobility_domain'] ?? '';
            $roaming = !empty($mobilityDomain);
            $newMfp = $_POST['new_mfp'] ?? '1';
            
            $successCount = 0;
            $failedDevices = [];
            
            foreach ($selectedDevices as $deviceName) {
                $device = $deviceManager->getDevice($deviceName);
                if (!$device) continue;
                
                $client = new \OpenWrt\OpenWrtClient($device['url'], $device['username'] ?? 'root', $device['ssh_key'] ?? null, $device['port'] ?? 22);
                if (!$client->login()) {
                    $failedDevices[] = $deviceName . " (login failed)";
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
                    $failedDevices[] = $deviceName . " (no radios found)";
                }
            }
            
            if ($successCount > 0) {
                $_SESSION['flash_success'] = "Added SSID '$newSsid' to $successCount device(s).";
                if (!empty($failedDevices)) {
                    $_SESSION['flash_success'] .= " Failed: " . implode(', ', $failedDevices);
                }
            } else {
                $_SESSION['flash_error'] = "Failed to add SSID to any devices. " . implode(', ', $failedDevices);
            }
            
        } elseif ($_POST['action'] === 'bulk_remove') {
            $ssidToRemove = $_POST['remove_ssid'];
            
            $successCount = 0;
            $failedDevices = [];
            
            foreach ($selectedDevices as $deviceName) {
                $device = $deviceManager->getDevice($deviceName);
                if (!$device) continue;
                
                $client = new \OpenWrt\OpenWrtClient($device['url'], $device['username'] ?? 'root', $device['ssh_key'] ?? null, $device['port'] ?? 22);
                if (!$client->login()) {
                    $failedDevices[] = $deviceName . " (login failed)";
                    continue;
                }
                
                // Get wireless config to find matching SSID sections
                $wirelessConfig = $client->getWirelessConfig();
                $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
                
                $removedCount = 0;
                if (is_array($configData)) {
                    foreach ($configData as $section => $data) {
                        if (isset($data['.type']) && $data['.type'] === 'wifi-iface' && isset($data['ssid']) && $data['ssid'] === $ssidToRemove) {
                            // Delete this section
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
                $_SESSION['flash_success'] = "Removed SSID '$ssidToRemove' from $successCount device(s).";
                if (!empty($failedDevices)) {
                    $_SESSION['flash_success'] .= " Failed: " . implode(', ', $failedDevices);
                }
            } else {
                $_SESSION['flash_error'] = "Failed to remove SSID from any devices. " . implode(', ', $failedDevices);
            }
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Fetch available SSIDs from the first device with their settings
$availableSSIDs = [];
$ssidSettings = [];
if (!empty($devices)) {
    $firstDevice = $devices[0];
    $client = new \OpenWrt\OpenWrtClient($firstDevice['url'], $firstDevice['username'] ?? 'root', $firstDevice['ssh_key'] ?? null, $firstDevice['port'] ?? 22);
    if ($client->login()) {
        $wirelessConfig = $client->getWirelessConfig();
        $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
        
        if (is_array($configData)) {
            foreach ($configData as $section => $data) {
                if (isset($data['.type']) && $data['.type'] === 'wifi-iface' && ($data['mode'] ?? 'ap') !== 'mesh' && isset($data['ssid'])) {
                    $ssid = $data['ssid'];
                    if (!in_array($ssid, $availableSSIDs)) {
                        $availableSSIDs[] = $ssid;
                        // Store settings for this SSID
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
    }
}

// Get available networks from the first device
$availableNetworks = [];
if (!empty($devices)) {
    $firstDevice = $devices[0];
    $client = new \OpenWrt\OpenWrtClient($firstDevice['url'], $firstDevice['username'] ?? 'root', $firstDevice['ssh_key'] ?? null, $firstDevice['port'] ?? 22);
    if ($client->login()) {
        $availableNetworks = $client->getNetworkInterfaces();
    }
}
if (empty($availableNetworks)) {
    // Fallback to common defaults
    $availableNetworks = ['lan', 'guest', 'wan'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Device Management</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="container">
        <a href="../index.php" class="btn">Back to Dashboard</a>
        <h1>Bulk Device Management</h1>
        <p>Update or add SSIDs across multiple devices simultaneously.</p>

        <?php if ($errorMessage): ?>
            <div class="card" style="background-color: #f2dede; border-color: #ebccd1; color: #a94442;">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="card" style="background-color: #dff0d8; border-color: #d6e9c6; color: #3c763d;">
                <?= htmlspecialchars($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($devices)): ?>
            <div class="card">
                <p>No devices available. <a href="index.php">Add a device</a> first.</p>
            </div>
        <?php else: ?>
            
            <!-- Device Selection -->
            <div class="card">
                <h3>Select Devices</h3>
                <p>Choose which devices to apply changes to:</p>
                <div id="device-selection" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-top: 10px;">
                    <?php foreach ($devices as $device): ?>
                        <label style="display: flex; align-items: center; gap: 5px; padding: 8px; background: #f5f5f5; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" class="device-checkbox" value="<?= htmlspecialchars($device['name']) ?>" checked>
                            <strong><?= htmlspecialchars($device['name']) ?></strong>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top: 10px;">
                    <button onclick="selectAll()" class="btn" style="background-color: #5bc0de; margin-right: 5px;">Select All</button>
                    <button onclick="selectNone()" class="btn" style="background-color: #777;">Deselect All</button>
                </div>
            </div>

            <!-- Bulk Update Existing SSID -->
            <div class="card" style="border-left: 5px solid #f0ad4e;">
                <h3>Update Existing SSID</h3>
                <p>Update settings for an existing SSID across selected devices.</p>
                <form method="post">
                    <input type="hidden" name="action" value="bulk_update">
                    
                    <div class="form-group">
                        <label>SSID to Update</label>
                        <?php if (!empty($availableSSIDs)): ?>
                            <select name="ssid" required style="width: 100%; padding: 8px;">
                                <?php foreach ($availableSSIDs as $ssid): ?>
                                    <option value="<?= htmlspecialchars($ssid) ?>"><?= htmlspecialchars($ssid) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:#777;">SSIDs from: <?= htmlspecialchars($devices[0]['name']) ?></small>
                        <?php else: ?>
                            <input type="text" name="ssid" required placeholder="Enter exact SSID name">
                            <small style="color:#777;">Could not load SSIDs. Enter manually.</small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>New WiFi Password (Key)</label>
                        <input type="text" name="key" placeholder="Leave empty for open network">
                    </div>

                    <div class="form-group">
                        <label>Network</label>
                        <select name="network" style="width: 100%; padding: 8px;">
                            <?php foreach ($availableNetworks as $net): ?>
                                <option value="<?= htmlspecialchars($net) ?>"><?= htmlspecialchars($net) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                        <strong>Fast Roaming (802.11r)</strong>
                        <label style="display: block; margin-top: 5px;">
                            Mobility Domain (4 hex digits):
                            <input type="text" name="mobility_domain" placeholder="e.g. AABB">
                            <small style="display:block; color:#777;">Enter a 4-digit hex code to enable Fast Roaming. Leave empty to disable.</small>
                        </label>
                    </div>

                    <div class="form-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                        <strong>Security Enhancements</strong>
                        <label style="display: block; margin-top: 5px;">
                            Management Frame Protection (802.11w):
                            <select name="mfp">
                                <option value="0">Disabled</option>
                                <option value="1" selected>Optional (Preferred)</option>
                                <option value="2">Required</option>
                            </select>
                        </label>
                    </div>

                    <button type="submit" class="btn" style="background-color: #f0ad4e; margin-top: 15px;">Update SSID on Selected Devices</button>
                </form>
            </div>

            <!-- Bulk Remove SSID -->
            <div class="card" style="border-left: 5px solid #d9534f;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;">Remove SSID</h3>
                    <button type="button" onclick="toggleBulkRemoveSSID()" class="btn" style="background-color: #777; padding: 5px 15px;">
                        <span id="toggle-bulk-remove-icon">▼</span>
                    </button>
                </div>
                <p style="margin-top: 10px;">Remove an SSID from all radios of selected devices.</p>
                <div id="bulk-remove-ssid-form" style="display: none; margin-top: 15px;">
                    <form method="post">
                        <input type="hidden" name="action" value="bulk_remove">
                        
                        <div class="form-group">
                            <label>SSID to Remove</label>
                            <?php if (!empty($availableSSIDs)): ?>
                                <select name="remove_ssid" required style="width: 100%; padding: 8px;">
                                    <?php foreach ($availableSSIDs as $ssid): ?>
                                        <option value="<?= htmlspecialchars($ssid) ?>"><?= htmlspecialchars($ssid) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color:#777;">SSIDs from: <?= htmlspecialchars($devices[0]['name']) ?></small>
                            <?php else: ?>
                                <input type="text" name="remove_ssid" required placeholder="Enter exact SSID name">
                                <small style="color:#777;">Could not load SSIDs. Enter manually.</small>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn" style="background-color: #d9534f; margin-top: 15px;" onclick="return confirm('Are you sure you want to remove this SSID from all selected devices?');">Remove SSID from Selected Devices</button>
                    </form>
                </div>
            </div>

            <!-- Bulk Add New SSID -->
            <div class="card" style="border-left: 5px solid #5cb85c;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;">Add New SSID</h3>
                    <button type="button" onclick="toggleBulkAddSSID()" class="btn" style="background-color: #777; padding: 5px 15px;">
                        <span id="toggle-bulk-add-icon">▼</span>
                    </button>
                </div>
                <p style="margin-top: 10px;">Create a new SSID on all radios of selected devices.</p>
                <div id="bulk-add-ssid-form" style="display: none; margin-top: 15px;">
                <form method="post">
                    <input type="hidden" name="action" value="bulk_add">
                    
                    <div class="form-group">
                        <label>SSID (Network Name)</label>
                        <input type="text" name="new_ssid" required placeholder="MyNewWiFi">
                    </div>

                    <div class="form-group">
                        <label>WiFi Password (Key)</label>
                        <input type="text" name="new_key" required placeholder="Min 8 characters">
                    </div>

                    <div class="form-group">
                        <label>Network</label>
                        <select name="new_network" style="width: 100%; padding: 8px;">
                            <?php foreach ($availableNetworks as $net): ?>
                                <option value="<?= htmlspecialchars($net) ?>"><?= htmlspecialchars(strtoupper($net)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                        <strong>Fast Roaming (802.11r)</strong>
                        <label style="display: block; margin-top: 5px;">
                            Mobility Domain (4 hex digits):
                            <input type="text" name="new_mobility_domain" placeholder="e.g. AABB">
                            <small style="display:block; color:#777;">Enter a 4-digit hex code to enable Fast Roaming. Leave empty to disable.</small>
                        </label>
                    </div>

                    <div class="form-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                        <strong>Security Enhancements</strong>
                        <label style="display: block; margin-top: 5px;">
                            Management Frame Protection (802.11w):
                            <select name="new_mfp">
                                <option value="0">Disabled</option>
                                <option value="1" selected>Optional (Preferred)</option>
                                <option value="2">Required</option>
                            </select>
                        </label>
                    </div>

                    <button type="submit" class="btn" style="background-color: #5cb85c; margin-top: 15px;">Add SSID to Selected Devices</button>
                </form>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        // SSID settings data from PHP
        const ssidSettings = <?= json_encode($ssidSettings) ?>;

        // Auto-populate form when SSID is selected
        const ssidSelect = document.querySelector('select[name="ssid"]');
        if (ssidSelect) {
            ssidSelect.addEventListener('change', function() {
                const selectedSSID = this.value;
                const settings = ssidSettings[selectedSSID];
                
                if (settings) {
                    // Populate form fields
                    const keyInput = document.querySelector('input[name="key"]');
                    const networkSelect = document.querySelector('select[name="network"]');
                    const mobilityDomainInput = document.querySelector('input[name="mobility_domain"]');
                    const mfpSelect = document.querySelector('select[name="mfp"]');
                    
                    if (keyInput) keyInput.value = settings.key || '';
                    if (networkSelect) networkSelect.value = settings.network || 'lan';
                    if (mobilityDomainInput) mobilityDomainInput.value = settings.mobility_domain || '';
                    if (mfpSelect) mfpSelect.value = settings.ieee80211w || '1';
                }
            });
            
            // Trigger change event on page load to populate initial values
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

        // Add hidden inputs for selected devices on form submit
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                // Remove any existing device inputs
                form.querySelectorAll('input[name="devices[]"]').forEach(input => input.remove());
                
                // Add current selection
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

        // Toggle Bulk Add SSID form
        function toggleBulkAddSSID() {
            const form = document.getElementById('bulk-add-ssid-form');
            const icon = document.getElementById('toggle-bulk-add-icon');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                icon.textContent = '▲';
            } else {
                form.style.display = 'none';
                icon.textContent = '▼';
            }
        }

        // Toggle Bulk Remove SSID form
        function toggleBulkRemoveSSID() {
            const form = document.getElementById('bulk-remove-ssid-form');
            const icon = document.getElementById('toggle-bulk-remove-icon');
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
