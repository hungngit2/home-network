<?php
require_once __DIR__ . '/../src/bootstrap.php';

use OpenWrt\Standards;

echo "=== 1. Testing Default Regular SSID (home) ===\n";
$opts1 = Standards::buildInterfaceOptions('home', 'MySecretPass123', 'lan');
assert($opts1['ieee80211w'] === '1', "Default ieee80211w must be '1'");
assert($opts1['ieee80211r'] === '1', "Default ieee80211r must be '1'");
assert($opts1['ieee80211k'] === '1', "Default ieee80211k must be '1'");
assert($opts1['ieee80211v'] === '1', "Default ieee80211v must be '1'");
assert($opts1['mobility_domain'] === '1076', "Default mobility_domain must be '1076'");
echo "Regular SSID: ieee80211w={$opts1['ieee80211w']}, ieee80211r={$opts1['ieee80211r']}, mobility_domain={$opts1['mobility_domain']} -> OK\n";

echo "\n=== 2. Testing SSID Pattern Override (home IoT) ===\n";
$opts2 = Standards::buildInterfaceOptions('home IoT', 'IoTSecretPass123', 'lan');
assert($opts2['ieee80211w'] === '0', "IoT SSID ieee80211w must be '0'");
assert($opts2['ieee80211r'] === '0', "IoT SSID has 802.11r off (Mi Box 3 / Android TV 9 can't join FT-PSK)");
assert($opts2['ieee80211k'] === '1' && $opts2['ieee80211v'] === '1', "IoT SSID keeps 802.11k/v");
assert($opts2['mobility_domain'] === '', "IoT SSID has no mobility_domain");
echo "home IoT SSID: ieee80211w={$opts2['ieee80211w']}, ieee80211r={$opts2['ieee80211r']}, mobility_domain='{$opts2['mobility_domain']}' -> OK\n";

echo "\n=== 3. Testing Explicit User Override ===\n";
$opts3 = Standards::buildInterfaceOptions('home', 'MySecretPass123', 'lan', '2', false);
assert($opts3['ieee80211w'] === '2', "User override ieee80211w must be '2'");
assert($opts3['ieee80211r'] === '0', "User override ieee80211r must be '0'");
assert($opts3['mobility_domain'] === '', "Disabled roaming has empty mobility_domain");
echo "Explicit user override: ieee80211w={$opts3['ieee80211w']}, ieee80211r={$opts3['ieee80211r']} -> OK\n";

echo "\nALL STANDARDS TESTS PASSED!\n";
