<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';

$configPath = file_exists(__DIR__ . '/../configs/config.json') ? __DIR__ . '/../configs/config.json' : __DIR__ . '/../config.json';
$deviceManager = new \OpenWrt\DeviceManager($configPath);

$name = $_GET['name'] ?? null;
$device = $deviceManager->getDevice($name);

if (!$device) {
    die("Device not found. <a href='index.php'>Go back</a>");
}

$client = new \OpenWrt\OpenWrtClient($device['url'], $device['username'] ?? 'root', $device['ssh_key'] ?? null, $device['port'] ?? 22);
$loginSuccess = $client->login();
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

if ($loginSuccess) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'update_wifi') {
                $sections = $_POST['sections'] ?? []; 
                if (!is_array($sections) && isset($_POST['section'])) {
                    $sections = [$_POST['section']];
                }
                
                $ssid = $_POST['ssid'];
                $key = $_POST['key'];
                $network = $_POST['network'] ?? 'lan';
                $mobilityDomain = $_POST['mobility_domain'] ?? '';
                $roaming = !empty($mobilityDomain) ? '1' : '0';
                $mfp = $_POST['mfp'] ?? '1'; // Default: Optional

                $count = 0;
                $options = \OpenWrt\Standards::buildInterfaceOptions($ssid, $key, $network, $mfp, $roaming === '1', $mobilityDomain);

                foreach ($sections as $section) {
                    $client->updateWirelessInterfaceOptions($section, $options);
                    $count++;
                }
                
                if ($count > 0) {
                    $client->commit('wireless');
                    $client->applyWifi();
                    $_SESSION['flash_success'] = "Settings saved for $count interface(s) and applied.";
                } else {
                    $_SESSION['flash_error'] = "No interfaces selected for update.";
                }
            } elseif ($_POST['action'] === 'add_ssid') {
                $newSsid = $_POST['new_ssid'];
                $newKey = $_POST['new_key'];
                $newNetwork = $_POST['new_network'] ?? 'lan';
                $mobilityDomain = $_POST['new_mobility_domain'] ?? '';
                $roaming = !empty($mobilityDomain);
                $newMfp = $_POST['new_mfp'] ?? '1';
                
                // We need to add this SSID to ALL radios
                // First, find all devices (radios)
                $radios = [];
                $wirelessConfig = $client->getWirelessConfig(); // Refresh config
                $configData = $wirelessConfig['values'] ?? $wirelessConfig['result'] ?? $wirelessConfig;
                
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
                    $_SESSION['flash_success'] = "Added new SSID '$newSsid' to $addedCount radios and applied.";
                } else {
                    $_SESSION['flash_error'] = "Failed to add SSID. Could not find any radios or RPC failed.";
                }
            } elseif ($_POST['action'] === 'remove_ssid') {
                $sections = $_POST['sections'] ?? [];
                
                $count = 0;
                foreach ($sections as $section) {
                    $res = $client->deleteWirelessInterface($section);
                    if ($res) $count++;
                }
                
                if ($count > 0) {
                    $client->commit('wireless');
                    $client->applyWifi();
                    $_SESSION['flash_success'] = "Removed $count interface(s) and applied.";
                } else {
                     $_SESSION['flash_error'] = "No interfaces selected for removal.";
                }
            }
            // Redirect to self to prevent resubmission
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    if ($loginSuccess) {
        $sysInfo = $client->getSystemInfo();
        $networkConfig = $client->getNetworkConfig();
    }
    
    $wirelessConfig = $client->getWirelessConfig();
} else {
    $errorMessage = "Failed to login to OpenWrt device. Error: " . htmlspecialchars($client->getLastError());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Device - <?= htmlspecialchars($device['name']) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="container">
        <a href="../index.php" class="btn">Back to Dashboard</a>
        <h1>Manage: <?= htmlspecialchars($device['name']) ?></h1>
        <p>URL: <a href="<?= htmlspecialchars($device['url']) ?>" target="_blank"><?= htmlspecialchars($device['url']) ?></a></p>

        <?php if (isset($sysInfo['result'])): ?>
            <div class="card">
                <strong>System Info:</strong> <?= htmlspecialchars($sysInfo['result']['hostname'] ?? 'Unknown Host') ?> 
                (<?= htmlspecialchars($sysInfo['result']['model'] ?? 'Unknown Model') ?>)
            </div>
        <?php endif; ?>

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

        <?php if ($loginSuccess): ?>
        <?php if ($loginSuccess): ?>
            <?php 
            // Get available networks dynamically
            $availableNetworks = $client->getNetworkInterfaces();
            if (empty($availableNetworks)) {
                // Fallback to common defaults if fetch fails
                $availableNetworks = ['lan', 'guest', 'iot'];
            }

            // Normalizing config data
            $configData = $wirelessConfig['values'] ?? []; 
            if (empty($configData) && isset($wirelessConfig['result'])) {
                 $configData = $wirelessConfig['result'];
            }
            // Fallback if it's directly the array
            if (empty($configData) && is_array($wirelessConfig)) {
                 $configData = $wirelessConfig;
            }

            $ssidGroups = [];
            
            if (is_array($configData)) {
                foreach ($configData as $key => $section) {
                    if (isset($section['.type']) && $section['.type'] === 'wifi-iface' && ($section['mode'] ?? 'ap') !== 'mesh') {
                        $section['.name'] = $key;
                        $ssid = $section['ssid'] ?? '(No SSID)';
                        if (!isset($ssidGroups[$ssid])) {
                            $ssidGroups[$ssid] = [];
                        }
                        $ssidGroups[$ssid][] = $section;
                    }
                }
            }
            ?>

            <?php if (empty($ssidGroups)): ?>
                <div class="card">
                    <p>No WiFi interfaces found in wireless config.</p>
                </div>
            <?php else: ?>
                <!-- Single Update Form with SSID Selector -->
                <div class="card">
                    <h3>Manage WiFi Networks</h3>
                    <form method="post" id="updateForm">
                        <input type="hidden" name="action" value="update_wifi">
                        
                        <div class="form-group">
                            <label>Select SSID to Manage</label>
                            <select name="ssid_selector" id="ssid_selector" required style="width: 100%; padding: 8px;">
                                <?php foreach ($ssidGroups as $ssid => $interfaces): ?>
                                    <option value="<?= htmlspecialchars($ssid) ?>"><?= htmlspecialchars($ssid) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:#777;">Interfaces: <span id="interface_list"></span></small>
                        </div>

                        <!-- Hidden inputs for sections will be added by JavaScript -->
                        <div id="section_inputs"></div>

                        <div class="form-group">
                            <label>SSID (Network Name)</label>
                            <input type="text" name="ssid" id="ssid_input" required>
                        </div>

                        <div class="form-group">
                            <label>WiFi Password (Key)</label>
                            <input type="text" name="key" id="key_input">
                            <small>Encryption: <span id="encryption_display">None</span></small>
                        </div>

                        <div class="form-group">
                            <label>Network</label>
                            <select name="network" id="network_select" style="width: 100%; padding: 8px;">
                                <?php 
                                foreach ($availableNetworks as $net): 
                                ?>
                                    <option value="<?= htmlspecialchars($net) ?>">
                                        <?= htmlspecialchars($net) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                            <strong>Fast Roaming (802.11r)</strong>
                            <label style="display: block; margin-top: 5px;">
                                Mobility Domain (4 hex digits):
                                <input type="text" name="mobility_domain" id="mobility_domain_input" placeholder="e.g. AABB">
                                <small style="display:block; color:#777;">Enter a 4-digit hex code to enable Fast Roaming. Leave empty to disable.</small>
                            </label>
                        </div>

                        <div class="form-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                            <strong>Security Enhancements</strong>
                            <label style="display: block; margin-top: 5px;">
                                Management Frame Protection (802.11w):
                                <select name="mfp" id="mfp_select">
                                    <option value="0">Disabled</option>
                                    <option value="1" selected>Optional (Preferred)</option>
                                    <option value="2">Required</option>
                                </select>
                            </label>
                        </div>

                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="submit" name="action" value="update_wifi" class="btn">Update SSID</button>
                            <button type="submit" name="action" value="remove_ssid" class="btn" style="background-color: #d9534f; border-color: #d43f3a;" onclick="return confirm('Are you sure you want to delete this network? This will remove the SSID from all radios.');">Delete Network</button>
                        </div>
                    </form>
                </div>

                <!-- Add New SSID Form -->
                <div class="card" style="border-left: 5px solid #5bc0de;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0;">Add New WiFi Network (All Bands)</h3>
                        <button type="button" onclick="toggleAddSSID()" class="btn" style="background-color: #777; padding: 5px 15px;">
                            <span id="toggle-add-ssid-icon">▼</span>
                        </button>
                    </div>
                    <div id="add-ssid-form" style="display: none; margin-top: 15px;">
                    <form method="post">
                        <input type="hidden" name="action" value="add_ssid">
                        
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
                                    <option value="<?= htmlspecialchars($net) ?>">
                                        <?= htmlspecialchars(strtoupper($net)) ?>
                                    </option>
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

                            <button type="submit" class="btn" style="background-color: #5bc0de; margin-top: 15px;">Create WiFi Network</button>
                    </form>
                    </div>
                </div>

            <?php endif; ?>

        <?php endif; ?>

        <?php elseif ($loginSuccess): ?>
            <div class="card">
                <p>Could not retrieve wireless configuration.</p>
                <pre><?= htmlspecialchars(print_r($wirelessConfig, true)) ?></pre>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Prepare SSID data for JavaScript
        const ssidData = <?= json_encode($ssidGroups) ?>;
        
        // Auto-populate form when SSID is selected
        const ssidSelector = document.getElementById('ssid_selector');
        if (ssidSelector) {
            ssidSelector.addEventListener('change', function() {
                const selectedSSID = this.value;
                const interfaces = ssidData[selectedSSID];
                
                if (interfaces && interfaces.length > 0) {
                    const firstIface = interfaces[0];
                    
                    // Update interface list display
                    const interfaceList = interfaces.map(iface => 
                        `${iface['.name']} (${iface.device || '?'})`
                    ).join(', ');
                    document.getElementById('interface_list').textContent = interfaceList;
                    
                    // Update hidden section inputs
                    const sectionInputsDiv = document.getElementById('section_inputs');
                    sectionInputsDiv.innerHTML = '';
                    interfaces.forEach(iface => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'sections[]';
                        input.value = iface['.name'];
                        sectionInputsDiv.appendChild(input);
                    });
                    
                    // Populate form fields
                    document.getElementById('ssid_input').value = selectedSSID;
                    document.getElementById('key_input').value = firstIface.key || '';
                    document.getElementById('encryption_display').textContent = firstIface.encryption || 'None';
                    document.getElementById('network_select').value = firstIface.network || 'lan';
                    document.getElementById('mobility_domain_input').value = firstIface.mobility_domain || '';
                    document.getElementById('mfp_select').value = firstIface.ieee80211w || '1';
                }
            });
            
            // Trigger change event on page load to populate initial values
            if (ssidSelector.value) {
                ssidSelector.dispatchEvent(new Event('change'));
            }
        }

        // Toggle Add SSID form
        function toggleAddSSID() {
            const form = document.getElementById('add-ssid-form');
            const icon = document.getElementById('toggle-add-ssid-icon');
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
