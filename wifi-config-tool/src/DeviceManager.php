<?php

namespace OpenWrt;

class DeviceManager {
    private $configFile;

    public function __construct($configFile) {
        $this->configFile = $configFile;
    }

    /**
     * Load raw configuration data, normalizing legacy flat arrays into region-grouped dictionary
     */
    private function loadGroupedData(): array {
        if (!file_exists($this->configFile)) {
            return [];
        }
        $content = file_get_contents($this->configFile);
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data)) {
            return [];
        }

        // Check if data is already grouped by region: {"RegionName": [ {...}, ... ]}
        $isAssociative = (array_keys($data) !== range(0, count($data) - 1));
        if ($isAssociative) {
            $grouped = [];
            foreach ($data as $regName => $devList) {
                $regName = trim((string)$regName) ?: 'Default';
                if (!is_array($devList)) continue;
                $grouped[$regName] = [];
                foreach ($devList as $dev) {
                    if (is_array($dev) && !empty($dev['name'])) {
                        $dev['region'] = $regName;
                        $grouped[$regName][] = $dev;
                    }
                }
            }
            return $grouped;
        }

        // Legacy flat array format: [ {"name": "...", "region": "..."}, ... ]
        $grouped = [];
        foreach ($data as $dev) {
            if (!is_array($dev) || empty($dev['name'])) continue;
            $regName = !empty($dev['region']) ? trim($dev['region']) : 'Default';
            if (!isset($grouped[$regName])) {
                $grouped[$regName] = [];
            }
            $dev['region'] = $regName;
            $grouped[$regName][] = $dev;
        }
        return $grouped;
    }

    /**
     * Get all devices as a flat list
     */
    public function getDevices(): array {
        $grouped = $this->loadGroupedData();
        $flat = [];
        foreach ($grouped as $regName => $devList) {
            foreach ($devList as $dev) {
                $dev['region'] = $regName;
                $flat[] = $dev;
            }
        }
        return $flat;
    }

    /**
     * Get all unique region names
     */
    public function getRegions(): array {
        $grouped = $this->loadGroupedData();
        $regions = array_keys($grouped);
        return $regions;
    }

    /**
     * Get devices grouped by region: {"RegionName": [ {...}, ... ]}
     */
    public function getDevicesGroupedByRegion(): array {
        return $this->loadGroupedData();
    }

    /**
     * Add or update a device
     */
    public function addDevice($name, $url, $username = 'root', $sshKey = null, $port = 22, $region = null) {
        $grouped = $this->loadGroupedData();
        
        // Determine region
        if (empty($region)) {
            $existingRegions = array_keys($grouped);
            $region = !empty($existingRegions) ? $existingRegions[0] : 'Default';
        } else {
            $region = trim($region);
        }

        // Remove any existing device with the same name across all regions
        foreach ($grouped as $regKey => &$devList) {
            $devList = array_values(array_filter($devList, function($d) use ($name) {
                return ($d['name'] ?? '') !== $name;
            }));
        }
        unset($devList);

        if (!isset($grouped[$region])) {
            $grouped[$region] = [];
        }

        $grouped[$region][] = [
            'name' => $name,
            'url' => $url,
            'username' => $username ?: 'root',
            'ssh_key' => $sshKey ?: '',
            'port' => (int)$port ?: 22
        ];

        $this->saveGroupedData($grouped);
    }

    /**
     * Move a device to another region
     */
    public function updateDeviceRegion($name, $newRegion) {
        $newRegion = trim($newRegion) ?: 'Default';
        $grouped = $this->loadGroupedData();
        $targetDevice = null;

        foreach ($grouped as $regKey => &$devList) {
            foreach ($devList as $idx => $dev) {
                if (($dev['name'] ?? '') === $name) {
                    $targetDevice = $dev;
                    unset($devList[$idx]);
                    $devList = array_values($devList);
                    break 2;
                }
            }
        }
        unset($devList);

        if ($targetDevice) {
            if (!isset($grouped[$newRegion])) {
                $grouped[$newRegion] = [];
            }
            unset($targetDevice['region']);
            $grouped[$newRegion][] = $targetDevice;
            $this->saveGroupedData($grouped);
        }
    }

    /**
     * Remove a device by name
     */
    public function removeDevice($name) {
        $grouped = $this->loadGroupedData();
        foreach ($grouped as $regKey => &$devList) {
            $devList = array_values(array_filter($devList, function($d) use ($name) {
                return ($d['name'] ?? '') !== $name;
            }));
        }
        unset($devList);

        // Remove empty regions if multiple existed
        if (count($grouped) > 1) {
            $grouped = array_filter($grouped, function($list) {
                return !empty($list);
            });
        }

        $this->saveGroupedData($grouped);
    }

    /**
     * Get a single device by name
     */
    public function getDevice($name) {
        $devices = $this->getDevices();
        foreach ($devices as $device) {
            if (($device['name'] ?? '') === $name) {
                return $device;
            }
        }
        return null;
    }

    /**
     * Persist grouped region JSON structure
     */
    private function saveGroupedData(array $grouped) {
        $cleanGrouped = [];
        foreach ($grouped as $regName => $devList) {
            $cleanGrouped[$regName] = [];
            foreach ($devList as $dev) {
                $cleanGrouped[$regName][] = [
                    'name' => $dev['name'] ?? '',
                    'url' => $dev['url'] ?? '',
                    'username' => $dev['username'] ?? 'root',
                    'ssh_key' => $dev['ssh_key'] ?? '',
                    'port' => (int)($dev['port'] ?? 22)
                ];
            }
        }
        file_put_contents($this->configFile, json_encode($cleanGrouped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
