<?php

namespace OpenWrt;

class DeviceManager {
    private $configFile;

    public function __construct($configFile) {
        $this->configFile = $configFile;
    }

    public function getDevices(): array {
        if (!file_exists($this->configFile)) {
            return [];
        }
        $content = file_get_contents($this->configFile);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }
        return array_map(function($device) {
            $device['region'] = !empty($device['region']) ? trim($device['region']) : 'Default';
            return $device;
        }, $data);
    }

    public function getRegions(): array {
        $devices = $this->getDevices();
        $regions = [];
        foreach ($devices as $device) {
            $region = $device['region'] ?? 'Default';
            if (!in_array($region, $regions)) {
                $regions[] = $region;
            }
        }
        sort($regions);
        return $regions;
    }

    public function getDevicesGroupedByRegion(): array {
        $devices = $this->getDevices();
        $grouped = [];
        foreach ($devices as $device) {
            $region = $device['region'] ?? 'Default';
            if (!isset($grouped[$region])) {
                $grouped[$region] = [];
            }
            $grouped[$region][] = $device;
        }
        ksort($grouped);
        return $grouped;
    }

    public function addDevice($name, $url, $username = 'root', $sshKey = null, $port = 22, $region = 'Default') {
        $devices = $this->getDevices();
        
        // Remove existing device with same name if exists (upsert)
        $devices = array_filter($devices, function($device) use ($name) {
            return $device['name'] !== $name;
        });
        
        $devices[] = [
            'name' => $name,
            'url' => $url,
            'region' => !empty($region) ? trim($region) : 'Default',
            'username' => $username ?: 'root',
            'ssh_key' => $sshKey ?: '',
            'port' => (int)$port ?: 22
        ];
        // Re-index array
        $this->saveDevices(array_values($devices));
    }

    public function updateDeviceRegion($name, $region) {
        $devices = $this->getDevices();
        foreach ($devices as &$device) {
            if ($device['name'] === $name) {
                $device['region'] = !empty($region) ? trim($region) : 'Default';
            }
        }
        $this->saveDevices(array_values($devices));
    }

    public function removeDevice($name) {
        $devices = $this->getDevices();
        $devices = array_filter($devices, function($device) use ($name) {
            return $device['name'] !== $name;
        });
        $this->saveDevices(array_values($devices));
    }

    public function getDevice($name) {
        $devices = $this->getDevices();
        foreach ($devices as $device) {
            if ($device['name'] === $name) {
                return $device;
            }
        }
        return null;
    }

    private function saveDevices($devices) {
        file_put_contents($this->configFile, json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
