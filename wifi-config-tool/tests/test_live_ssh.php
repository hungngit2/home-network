<?php
require_once __DIR__ . '/../src/bootstrap.php';

$aps = [
    'redmi-rm2100-f0' => '10.0.0.200',
    'jcg-q20-f1'      => '10.0.0.201',
    'jcg-q20-f2'      => '10.0.0.202',
    'jcg-q20-f3'      => '10.0.0.203',
];

foreach ($aps as $name => $ip) {
    echo "========================================\n";
    echo "Testing $name ($ip)...\n";
    $client = new \OpenWrt\OpenWrtClient($ip, 'root');
    if ($client->login()) {
        $sys = $client->getSystemInfo();
        $wifi = $client->getWirelessConfig();
        $interfaces = count($wifi['values'] ?? []);
        $nets = implode(', ', $client->getNetworkInterfaces());
        echo "  Status:  SUCCESS\n";
        echo "  Model:   {$sys['model']}\n";
        echo "  Release: {$sys['release']}\n";
        echo "  Radios/Interfaces: $interfaces sections\n";
        echo "  Networks: $nets\n";
    } else {
        echo "  Status: FAILED - " . $client->getLastError() . "\n";
    }
}
echo "========================================\n";
