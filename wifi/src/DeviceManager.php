<?php

namespace OpenWrt;

class DeviceManager {
    private $configFile;

    public function __construct($configFile) {
        $this->configFile = $configFile;
    }

    public function getDevices() {
        if (!file_exists($this->configFile)) {
            return [];
        }
        $content = file_get_contents($this->configFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    public function addDevice($name, $url, $username = 'root', $sshKey = null, $port = 22) {
        $devices = $this->getDevices();
        
        // Remove existing device with same name if exists (upsert)
        $devices = array_filter($devices, function($device) use ($name) {
            return $device['name'] !== $name;
        });
        
        $devices[] = [
            'name' => $name,
            'url' => $url,
            'username' => $username ?: 'root',
            'ssh_key' => $sshKey ?: '',
            'port' => (int)$port ?: 22
        ];
        // Re-index array
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
        file_put_contents($this->configFile, json_encode($devices, JSON_PRETTY_PRINT));
    }
}
