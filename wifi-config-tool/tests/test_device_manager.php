<?php
require_once __DIR__ . '/../src/bootstrap.php';

$testConfig = __DIR__ . '/test_config.json';
if (file_exists($testConfig)) unlink($testConfig);
file_put_contents($testConfig, '[]');

$dm = new \OpenWrt\DeviceManager($testConfig);

echo "Adding device...\n";
$dm->addDevice('Test Router', '192.168.1.1', 'root', '', 22);

$devices = $dm->getDevices();
if (count($devices) === 1 && $devices[0]['name'] === 'Test Router' && $devices[0]['url'] === '192.168.1.1') {
    echo "Device added successfully.\n";
} else {
    echo "FAILED: Device add.\n";
    exit(1);
}

$name = $devices[0]['name'];
echo "Removing device...\n";
$dm->removeDevice($name);

$devices = $dm->getDevices();
if (count($devices) === 0) {
    echo "Device removed successfully.\n";
} else {
    echo "FAILED: Device remove.\n";
    exit(1);
}

if (file_exists($testConfig)) unlink($testConfig);
echo "Configuration clean up.\n";
