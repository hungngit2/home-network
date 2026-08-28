<?php
require_once __DIR__ . '/../src/bootstrap.php';

$testConfig = __DIR__ . '/test_config.json';
if (file_exists($testConfig)) unlink($testConfig);
file_put_contents($testConfig, '{}');

$dm = new \OpenWrt\DeviceManager($testConfig);

echo "Adding device with region...\n";
$dm->addDevice('Test Router 1', '192.168.1.1', 'root', '', 22, 'Region-A');
$dm->addDevice('Test Router 2', '192.168.1.2', 'root', '', 22, 'Region-B');
$dm->addDevice('Test Router 3', '192.168.1.3', 'root', '', 22, 'Region-A');

// Verify file on disk is JSON dictionary grouped by region
$rawDiskData = json_decode(file_get_contents($testConfig), true);
if (isset($rawDiskData['Region-A']) && isset($rawDiskData['Region-B']) && count($rawDiskData['Region-A']) === 2) {
    echo "Verified disk JSON is natively grouped by region dictionary.\n";
} else {
    echo "FAILED: Disk format is not grouped dictionary.\n";
    exit(1);
}

$devices = $dm->getDevices();
if (count($devices) === 3 && $devices[0]['region'] === 'Region-A') {
    echo "Devices flattened successfully with regions.\n";
} else {
    echo "FAILED: Device add.\n";
    exit(1);
}

$regions = $dm->getRegions();
if (count($regions) === 2 && in_array('Region-A', $regions) && in_array('Region-B', $regions)) {
    echo "Regions retrieved successfully: " . implode(', ', $regions) . "\n";
} else {
    echo "FAILED: getRegions.\n";
    exit(1);
}

$grouped = $dm->getDevicesGroupedByRegion();
if (count($grouped['Region-A']) === 2 && count($grouped['Region-B']) === 1) {
    echo "Grouped devices verified successfully.\n";
} else {
    echo "FAILED: getDevicesGroupedByRegion.\n";
    exit(1);
}

echo "Updating device region...\n";
$dm->updateDeviceRegion('Test Router 2', 'Region-A');
$updatedGrouped = $dm->getDevicesGroupedByRegion();
if (count($updatedGrouped['Region-A']) === 3 && empty($updatedGrouped['Region-B'])) {
    echo "Device region updated successfully.\n";
} else {
    echo "FAILED: updateDeviceRegion.\n";
    exit(1);
}

echo "Testing single-region auto-default add...\n";
$dm->addDevice('Test Router 4', '192.168.1.4', 'root', '', 22); // No region specified
$devices = $dm->getDevices();
if (count($devices) === 4 && $devices[3]['region'] === 'Region-A') {
    echo "Auto-assigned to existing active region successfully.\n";
} else {
    echo "FAILED: Auto-assign region.\n";
    exit(1);
}

echo "Removing devices...\n";
$dm->removeDevice('Test Router 1');
$dm->removeDevice('Test Router 2');
$dm->removeDevice('Test Router 3');
$dm->removeDevice('Test Router 4');

$devices = $dm->getDevices();
if (count($devices) === 0) {
    echo "Devices removed successfully.\n";
} else {
    echo "FAILED: Device remove.\n";
    exit(1);
}

if (file_exists($testConfig)) unlink($testConfig);
echo "Configuration clean up.\n";
