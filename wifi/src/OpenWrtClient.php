<?php

namespace OpenWrt;

class OpenWrtClient {
    private $url;
    private $username;
    private $password;
    private $token;
    private $lastError;

    public function __construct($url, $username, $password) {
        $this->url = rtrim($url, '/');
        $this->username = $username;
        // Do not store password if not needed, but for now we keep it for login
        $this->password = $password;
        // Initialize lastError
        $this->lastError = '';
    }

    public function login() {
        $response = $this->rpcRequest('auth', 'login', [$this->username, $this->password]);
        
        if (isset($response['result']) && $response['result']) {
            $this->token = $response['result'];
            return true;
        }
        
        // Store last error for debugging
        $error = $response['error'] ?? null;
        if (is_array($error)) {
             $this->lastError = $error['message'] ?? json_encode($error);
        } elseif ($error) {
             $this->lastError = $error;
        } else {
             $this->lastError = 'Unknown login error (Empty result)';
        }

        if (isset($response['raw_response'])) {
             $this->lastError .= ' (Raw: ' . substr($response['raw_response'], 0, 100) . '...)';
        }
        return false;
    }

    public function getLastError() {
        return $this->lastError ?? null;
    }

    public function getSystemInfo() {
        return $this->rpcRequest('sys', 'system');
    }

    public function getWirelessConfig() {
        // Use get_all to retrieve the full configuration package
        return $this->rpcRequest('uci', 'get_all', ['wireless'], true);
    }

    /**
     * Get available network interfaces from the device
     * @return array List of network interface names
     */
    public function getNetworkInterfaces() {
        $result = $this->rpcRequest('uci', 'get_all', ['network'], true);
        $networks = [];
        
        if (isset($result['values'])) {
            $configData = $result['values'];
        } elseif (isset($result['result'])) {
            $configData = $result['result'];
        } else {
            $configData = $result;
        }
        
        if (is_array($configData)) {
            foreach ($configData as $key => $section) {
                // Only include interface sections, not devices or other types
                if (isset($section['.type']) && $section['.type'] === 'interface' && $key !== 'loopback') {
                    $networks[] = $key;
                }
            }
        }
        
        return $networks;
    }

    public function getNetworkConfig() {
        return $this->rpcRequest('uci', 'get_all', ['network'], true);
    }

    public function addWirelessInterface($device, $ssid, $key, $network, $encryption = 'psk2+ccmp', $roaming = false, $mobilityDomain = '', $mfp = '1') {
        $values = [
            'device' => $device,
            'mode' => 'ap',
            'ssid' => $ssid,
            'key' => $key,
            'network' => $network,
            'encryption' => $encryption,
            'ieee80211w' => $mfp
        ];

        if ($roaming) {
            $values['ieee80211r'] = '1';
            // ... (rest is same)
            if ($mobilityDomain) {
                $values['mobility_domain'] = $mobilityDomain;
            }
            $values['ft_over_ds'] = '1';
            $values['ft_psk_generate_local'] = '1';
        }

        // Generate a new named section 'wifinetX' to avoid anonymous section migration issues in LuCI
        $existingConfig = $this->getWirelessConfig();
        $configData = $existingConfig['values'] ?? $existingConfig['result'] ?? $existingConfig;
        
        $maxIndex = 0;
        if (is_array($configData)) {
            foreach ($configData as $key => $section) {
                if (preg_match('/^wifinet(\d+)$/', $key, $matches)) {
                    $index = (int)$matches[1];
                    if ($index > $maxIndex) {
                        $maxIndex = $index;
                    }
                }
            }
        }
        
        $newIndex = $maxIndex + 1;
        $sectionName = 'wifinet' . $newIndex;

        // Create the named section
        // uci set wireless.wifinetX=wifi-iface
        $this->rpcRequest('uci', 'set', ['wireless', $sectionName, 'wifi-iface'], true);
        
        // Now set the values individually as bulk set might not be supported
        foreach ($values as $option => $value) {
             $this->rpcRequest('uci', 'set', ['wireless', $sectionName, $option, $value], true);
        }
        return true;
    }

    public function deleteWirelessInterface($sectionName) {
         return $this->rpcRequest('uci', 'delete', ['wireless', $sectionName], true);
    }

    public function setWirelessConfig($config, $section, $option, $value) {
        // uci set config section option value
        // but via rpc usually uci set config section values
        // Let's try simpler form: set(config, section, option, value)
        // If that fails, we might need named params or specific structure.
        return $this->rpcRequest('uci', 'set', [$config, $section, $option, $value], true);
    }

    public function commit($config) {
        return $this->rpcRequest('uci', 'commit', [$config], true);
    }

    public function applyWifi() {
        // 'apply' in uci rpc usually attempts to apply the config changes.
        // It might be 'apply' with rollback handling, but for now we try this.
        return $this->rpcRequest('uci', 'apply', ['wireless'], true);
    }

    public function callUbus($object, $method, $params = []) {
        // ...
        return $this->rpcRequest('ubus', 'call', [$this->token, $object, $method, $params]); 
    }

    private function rpcRequest($module, $method, $params = [], $auth = false) {
        $endpoint = $this->url . '/cgi-bin/luci/rpc/' . $module;
        
        $rpcParams = $params;
        if ($auth && $this->token) {
            // Standard LuCI RPC (via ubus) usually doesn't need token in params if cookie is present.
            // If we send both, it might take token as the first argument (e.g. config name).
            // So let's NOT prepend token to params.
            // array_unshift($rpcParams, $this->token);
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $rpcParams,
            'id' => 1
        ];

        // ... rest of the function ...
        $headers = ['Content-Type: application/json'];
        if ($auth && $this->token) {
            // Also add sysauth cookie for LuCI session
            $headers[] = 'Cookie: sysauth=' . $this->token;
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
             // Return a special error structure or log it
             return ['error' => 'Curl error: ' . $curlError];
        }
        
        // Check if response is HTML (login page usually means RPC is missing)
        if (strpos(trim($result), '<!DOCTYPE html') === 0 || strpos($result, '<html') !== false) {
             return ['error' => 'Received HTML page instead of JSON. This usually means `luci-mod-rpc` is not installed on the router. Please run `opkg update && opkg install luci-mod-rpc` on the router via SSH.', 'raw_response' => $result];
        }

        $decoded = json_decode($result, true);
        if ($decoded === null) {
             return ['error' => 'JSON decode error', 'raw_response' => $result, 'http_code' => $httpCode];
        }

        return $decoded;
    }

    /**
     * Check if luci-mod-rpc is installed, and install it if not
     * @return array Result with 'success' boolean and 'message' string
     */
    public function ensureRpcPackageInstalled() {
        // Check if package is installed
        $checkResult = $this->execCommand('opkg list-installed | grep luci-mod-rpc');
        
        // If grep returns something, package is installed
        if (isset($checkResult['result']) && !empty($checkResult['result'])) {
            return ['success' => true, 'message' => 'Package luci-mod-rpc is already installed'];
        }
        
        // Package not installed, try to install it
        // First update package list
        $updateResult = $this->execCommand('opkg update');
        
        if (!isset($updateResult['result'])) {
            return ['success' => false, 'message' => 'Failed to update package list'];
        }
        
        // Then install the package
        $installResult = $this->execCommand('opkg install luci-mod-rpc');
        
        if (isset($installResult['result'])) {
            return ['success' => true, 'message' => 'Successfully installed luci-mod-rpc package'];
        }
        
        return ['success' => false, 'message' => 'Failed to install luci-mod-rpc package'];
    }
}
