<?php
require_once __DIR__ . '/../src/bootstrap.php';

use OpenWrt\ConfigCloner;

function check(bool $ok, string $what) {
    if (!$ok) {
        echo "FAILED: $what\n";
        exit(1);
    }
    echo "OK: $what\n";
}

function hasCommand(array $commands, string $needle): bool {
    foreach ($commands as $c) {
        if (strpos($c, $needle) !== false) return true;
    }
    return false;
}

$roaming = ['ieee80211r' => '1', 'ieee80211k' => '1', 'ieee80211v' => '1', 'mobility_domain' => '1076', 'encryption' => 'psk2+ccmp'];

$source = [
    'radio0' => ['.type' => 'wifi-device', '.anonymous' => false, 'band' => '2g', 'channel' => '6', 'country' => 'US', 'cell_density' => '0'],
    'default_radio0' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio0', 'mode' => 'ap', 'network' => 'lan', 'ssid' => 'home', 'key' => 'lan pass', 'macaddr' => 'aa:bb:cc:dd:ee:01'] + $roaming,
    'radio1' => ['.type' => 'wifi-device', '.anonymous' => false, 'band' => '5g', 'channel' => '36', 'country' => 'US'],
    'default_radio1' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio1', 'mode' => 'ap', 'network' => 'lan', 'ssid' => 'home', 'key' => 'lan pass', 'basic_rate' => ['12000', '24000']] + $roaming,
    'wifinet3' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio0', 'mode' => 'ap', 'network' => 'iot', 'ssid' => 'home IoT', 'key' => "it's iot", 'ieee80211w' => '0'] + $roaming,
    'cfg0a1b2c' => ['.type' => 'wifi-iface', '.anonymous' => true, 'device' => 'radio1', 'network' => 'guest', 'ssid' => 'home guest', 'key' => 'guest pass'] + $roaming,
    'wifinet7' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio1', 'mode' => 'mesh', 'mesh_id' => 'secret-mesh', 'key' => 'mesh pass', 'ifname' => 'site-mesh'],
];

$freshTarget = [
    'radio0' => ['.type' => 'wifi-device', '.anonymous' => false, 'band' => '5g', 'channel' => '149', 'disabled' => '1'],
    'default_radio0' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio0', 'mode' => 'ap', 'network' => 'lan', 'ssid' => 'OpenWrt', 'encryption' => 'none'],
    'radio1' => ['.type' => 'wifi-device', '.anonymous' => false, 'band' => '2g', 'channel' => '1', 'disabled' => '1'],
    'default_radio1' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio1', 'mode' => 'ap', 'network' => 'lan', 'ssid' => 'OpenWrt', 'encryption' => 'none'],
    'wifinet3' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio0', 'mode' => 'mesh', 'mesh_id' => 'target-mesh'],
];

echo "=== 1. Fresh AP with swapped radio order ===\n";
$plan = ConfigCloner::buildPlan($source, $freshTarget);
$cmds = $plan['commands'];

check($cmds[0] === 'cp /etc/config/wireless /etc/config/wireless.bak-clone', 'backs up the target config first');
check(end($cmds) === 'uci commit wireless', 'commits last');
check($plan['replaced'] === 2, 'replaces the 2 default OpenWrt SSIDs');
check(hasCommand($cmds, "uci delete 'wireless.default_radio0'") && hasCommand($cmds, "uci delete 'wireless.default_radio1'"), 'deletes target default AP interfaces');
check(!hasCommand($cmds, "uci delete 'wireless.wifinet3'"), "keeps the target's own mesh interface");

check(hasCommand($cmds, "uci set 'wireless.default_radio0.device=radio1'"), '2g SSID lands on the target 2g radio (radio1)');
check(hasCommand($cmds, "uci set 'wireless.default_radio1.device=radio0'"), '5g SSID lands on the target 5g radio (radio0)');
check(hasCommand($cmds, "uci set 'wireless.wifinet1=wifi-iface'") && hasCommand($cmds, "uci set 'wireless.wifinet1.network=iot'"), 'name clash with target mesh section is renamed');
check(hasCommand($cmds, "uci set 'wireless.wifinet2.network=guest'"), 'anonymous source section gets a wifinetN name');
check(hasCommand($cmds, "uci set 'wireless.wifinet1.key=it'\\''s iot'"), 'values are shell-quoted');
check(hasCommand($cmds, "uci add_list 'wireless.default_radio1.basic_rate=12000'"), 'list options use add_list');

check(!hasCommand($cmds, 'macaddr') && !hasCommand($cmds, 'ifname') && !hasCommand($cmds, 'mesh_id') && !hasCommand($cmds, 'secret-mesh'), 'no MAC, ifname or mesh settings copied');
check(!hasCommand($cmds, '.channel='), 'channels are not copied');
check(hasCommand($cmds, "uci set 'wireless.radio1.country=US'") && hasCommand($cmds, "uci set 'wireless.radio0.country=US'"), 'radio country copied by band');
check(hasCommand($cmds, "uci set 'wireless.radio0.disabled=0'") && hasCommand($cmds, "uci set 'wireless.radio1.disabled=0'"), 'radios enabled like the source');

check(count($plan['copied']) === 4, 'copies the 4 client interfaces');
check(count($plan['skipped']) === 1 && strpos($plan['skipped'][0], 'wifinet7 (mesh') === 0, 'reports the skipped mesh interface by section name only');

echo "\n=== 2. Target without a 5 GHz radio ===\n";
$singleBand = [
    'radio0' => ['.type' => 'wifi-device', '.anonymous' => false, 'hwmode' => '11g'],
    'default_radio0' => ['.type' => 'wifi-iface', '.anonymous' => false, 'device' => 'radio0', 'mode' => 'ap', 'ssid' => 'OpenWrt'],
];
$plan2 = ConfigCloner::buildPlan($source, $singleBand);
check(count($plan2['copied']) === 2, 'copies only the 2g interfaces (legacy hwmode band detection)');
check(count($plan2['skipped']) === 3, 'reports 5g interfaces and the mesh as skipped');
check(hasCommand($plan2['commands'], "uci set 'wireless.default_radio0.device=radio0'"), 'reuses the source section name once the default is deleted');

echo "\nALL CONFIG CLONER TESTS PASSED!\n";
