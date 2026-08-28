<?php

namespace OpenWrt;

class OpenWrtClient {
    private $host;
    private $username;
    private $sshKey;
    private $port;
    private $lastError;

    /**
     * @param string $urlOrHost IP, hostname, or URL (e.g. "http://10.0.0.200" or "10.0.0.200")
     * @param string $username SSH username (default: "root")
     * @param string|null $sshKey Path to private key (optional, auto-discovers default keys if null)
     * @param int $port SSH port (default: 22)
     */
    public function __construct($urlOrHost, $username = 'root', $sshKey = null, $port = 22) {
        // Extract host if full URL passed
        $host = parse_url($urlOrHost, PHP_URL_HOST);
        if (!$host) {
            $host = preg_replace('/^https?:\/\//i', '', $urlOrHost);
            $host = preg_replace('/:\d+$/', '', $host);
        }
        $this->host = trim($host, '/');
        $this->username = $username ?: 'root';
        $this->port = (int)$port ?: 22;
        $this->lastError = '';

        // Resolve SSH key path
        if ($sshKey && file_exists($sshKey)) {
            $this->sshKey = $sshKey;
        } else {
            $this->sshKey = $this->discoverDefaultSshKey();
        }
    }

    /**
     * Discover standard SSH private key location on system
     */
    private function discoverDefaultSshKey() {
        $candidates = [
            __DIR__ . '/../.ssh/id_ed25519',
            __DIR__ . '/../.ssh/id_rsa',
            (getenv('HOME') ?: '/root') . '/.ssh/id_ed25519',
            (getenv('HOME') ?: '/root') . '/.ssh/id_rsa',
            '/var/www/.ssh/id_ed25519',
            '/var/www/.ssh/id_rsa',
            '/root/.ssh/id_ed25519',
            '/root/.ssh/id_rsa',
        ];

        foreach ($candidates as $keyPath) {
            if (file_exists($keyPath) && is_readable($keyPath)) {
                return $keyPath;
            }
        }
        return null;
    }

    /**
     * Execute a command on the remote OpenWrt router via SSH
     */
    public function execCommand($cmd) {
        $user = escapeshellarg($this->username);
        $target = escapeshellarg($this->host);
        $port = escapeshellarg($this->port);

        $keyOption = '';
        if ($this->sshKey && file_exists($this->sshKey)) {
            $keyOption = '-i ' . escapeshellarg($this->sshKey) . ' ';
        }

        $sshCmd = "ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=5 -p {$port} {$keyOption}{$user}@{$target} " . escapeshellarg($cmd) . " 2>&1";

        $output = [];
        $returnVar = 0;
        exec($sshCmd, $output, $returnVar);
        $outStr = implode("\n", $output);

        if ($returnVar !== 0) {
            $this->lastError = "SSH error (code $returnVar): " . $outStr;
            return null;
        }

        return $outStr;
    }

    /**
     * Verify SSH connectivity to router
     */
    public function login() {
        $result = $this->execCommand('echo "OPENWRT_SSH_OK"');
        if ($result !== null && strpos($result, 'OPENWRT_SSH_OK') !== false) {
            return true;
        }
        return false;
    }

    public function getLastError() {
        return $this->lastError ?? null;
    }

    /**
     * Get system and hardware info
     */
    public function getSystemInfo() {
        $infoJson = $this->execCommand("ubus call system info 2>/dev/null");
        $model = $this->execCommand("cat /tmp/sysinfo/model 2>/dev/null || cat /proc/cpuinfo | grep 'machine' | head -n 1");
        $release = $this->execCommand("cat /etc/openwrt_release 2>/dev/null | grep 'DISTRIB_DESCRIPTION' | cut -d\"'\" -f2");

        $data = json_decode($infoJson ?: '{}', true) ?: [];
        $data['model'] = trim($model ?: 'OpenWrt Device');
        $data['release'] = trim($release ?: 'OpenWrt');
        return $data;
    }

    /**
     * Get complete wireless configuration as structured array
     */
    public function getWirelessConfig() {
        $json = $this->execCommand("ubus call uci get '{\"config\":\"wireless\"}' 2>/dev/null");
        if ($json) {
            $decoded = json_decode($json, true);
            if (isset($decoded['values'])) {
                return ['values' => $decoded['values']];
            }
        }
        return ['values' => []];
    }

    /**
     * Get available network interface names from router
     */
    public function getNetworkInterfaces() {
        $json = $this->execCommand("ubus call uci get '{\"config\":\"network\"}' 2>/dev/null");
        $networks = [];

        if ($json) {
            $decoded = json_decode($json, true);
            $configData = $decoded['values'] ?? [];
            foreach ($configData as $key => $section) {
                if (isset($section['.type']) && $section['.type'] === 'interface' && $key !== 'loopback') {
                    $networks[] = $key;
                }
            }
        }

        return !empty($networks) ? $networks : ['lan', 'iot', 'guest'];
    }

    public function getNetworkConfig() {
        $json = $this->execCommand("ubus call uci get '{\"config\":\"network\"}' 2>/dev/null");
        return json_decode($json ?: '{}', true) ?: ['values' => []];
    }

    /**
     * Add a new wireless interface/SSID to a radio
     */
    public function addWirelessInterface($device, $ssid, $key, $network, $encryption = 'psk2+ccmp', $roaming = false, $mobilityDomain = '', $mfp = '1') {
        $existingConfig = $this->getWirelessConfig();
        $configData = $existingConfig['values'] ?? [];

        $maxIndex = 0;
        foreach ($configData as $k => $sec) {
            if (preg_match('/^wifinet(\d+)$/', $k, $matches)) {
                $idx = (int)$matches[1];
                if ($idx > $maxIndex) {
                    $maxIndex = $idx;
                }
            }
        }

        $sectionName = 'wifinet' . ($maxIndex + 1);

        $commands = [];
        $commands[] = "uci set wireless.{$sectionName}=wifi-iface";
        $commands[] = "uci set wireless.{$sectionName}.device=" . escapeshellarg($device);
        $commands[] = "uci set wireless.{$sectionName}.mode='ap'";
        $commands[] = "uci set wireless.{$sectionName}.ssid=" . escapeshellarg($ssid);
        $commands[] = "uci set wireless.{$sectionName}.network=" . escapeshellarg($network);
        $commands[] = "uci set wireless.{$sectionName}.encryption=" . escapeshellarg($encryption);

        if (!empty($key)) {
            $commands[] = "uci set wireless.{$sectionName}.key=" . escapeshellarg($key);
        }

        $commands[] = "uci set wireless.{$sectionName}.ieee80211w=" . escapeshellarg($mfp);

        if ($roaming) {
            $commands[] = "uci set wireless.{$sectionName}.ieee80211r='1'";
            $commands[] = "uci set wireless.{$sectionName}.ieee80211k='1'";
            $commands[] = "uci set wireless.{$sectionName}.ieee80211v='1'";
            $commands[] = "uci set wireless.{$sectionName}.bss_transition='1'";
            $commands[] = "uci set wireless.{$sectionName}.ft_over_ds='1'";
            $commands[] = "uci set wireless.{$sectionName}.ft_psk_generate_local='1'";
            if ($mobilityDomain) {
                $commands[] = "uci set wireless.{$sectionName}.mobility_domain=" . escapeshellarg($mobilityDomain);
            }
        }

        $commands[] = "uci commit wireless";
        $batchCmd = implode(" && ", $commands);

        $res = $this->execCommand($batchCmd);
        return $res !== null;
    }

    /**
     * Delete a wireless interface section
     */
    public function deleteWirelessInterface($sectionName) {
        $cmd = "uci delete wireless." . escapeshellarg($sectionName) . " && uci commit wireless";
        $res = $this->execCommand($cmd);
        return $res !== null;
    }

    /**
     * Set a UCI option
     */
    public function setWirelessConfig($config, $section, $option, $value) {
        $cmd = "uci set " . escapeshellarg("{$config}.{$section}.{$option}") . "=" . escapeshellarg($value);
        $res = $this->execCommand($cmd);
        return $res !== null;
    }

    /**
     * Commit UCI configuration
     */
    public function commit($config) {
        $cmd = "uci commit " . escapeshellarg($config);
        $res = $this->execCommand($cmd);
        return $res !== null;
    }

    /**
     * Reload wireless subsystem
     */
    public function applyWifi() {
        $cmd = "/sbin/wifi reload 2>/dev/null || ubus call network reload 2>/dev/null";
        $res = $this->execCommand($cmd);
        return $res !== null;
    }
}
