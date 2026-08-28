<?php

namespace OpenWrt;

class Standards {
    private static $file = __DIR__ . '/../configs/standards.json';
    private static $cache = null;

    /**
     * Load standards from standards.json with sensible built-in fallbacks
     */
    public static function get(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defaults = [
            'wifi_standards' => [
                'encryption' => 'psk2+ccmp',
                'wpa_disable_eapol_key_retries' => '1',
                'multicast_to_unicast_all' => '1',
                'mcast_rate' => '24000',
                'basic_rate' => '12000 24000',
                'ocv' => '0',
                'time_advertisement' => '2',
                'bss_transition' => '1',
                'ieee80211w' => '1',
                'ieee80211r' => '1',
                'ieee80211k' => '1',
                'ieee80211v' => '1',
                'ft_over_ds' => '1',
                'ft_psk_generate_local' => '1'
            ],
            'ssid_overrides' => [
                '*IoT*' => [
                    'ieee80211w' => '0',
                    'ieee80211r' => '0'
                ],
                '*iot*' => [
                    'ieee80211w' => '0',
                    'ieee80211r' => '0'
                ]
            ]
        ];

        if (file_exists(self::$file)) {
            $content = file_get_contents(self::$file);
            $json = json_decode($content, true);
            if (is_array($json)) {
                self::$cache = array_replace_recursive($defaults, $json);
                return self::$cache;
            }
        }

        self::$cache = $defaults;
        return self::$cache;
    }

    /**
     * Build full UCI options array for a wifi-iface section based on standard config
     */
    public static function buildInterfaceOptions(
        string $ssid,
        string $key,
        string $network = 'lan',
        ?string $mfp = null,
        ?bool $roaming = null,
        ?string $mobilityDomain = null,
        ?string $device = null
    ): array {
        $standards = self::get();
        $options = $standards['wifi_standards'] ?? [];

        if ($device !== null) {
            $options['device'] = $device;
            $options['mode'] = 'ap';
        }

        $options['ssid'] = $ssid;
        $options['key'] = !empty($key) ? $key : '';
        $options['encryption'] = !empty($key) ? ($options['encryption'] ?? 'psk2+ccmp') : 'none';
        $options['network'] = $network;

        // 1. Apply SSID-specific overrides (e.g. "*IoT*", "lotus IoT", etc.)
        if (isset($standards['ssid_overrides']) && is_array($standards['ssid_overrides'])) {
            foreach ($standards['ssid_overrides'] as $pattern => $overrideOpts) {
                if (fnmatch($pattern, $ssid, FNM_CASEFOLD) || strcasecmp($pattern, $ssid) === 0) {
                    if (is_array($overrideOpts)) {
                        foreach ($overrideOpts as $k => $v) {
                            $options[$k] = (string)$v;
                        }
                    }
                }
            }
        }

        // 2. Apply user-explicit overrides if specified
        if ($mfp !== null && $mfp !== '') {
            $options['ieee80211w'] = (string)$mfp;
        }

        if ($roaming !== null) {
            $options['ieee80211r'] = $roaming ? '1' : '0';
            $options['ieee80211k'] = $roaming ? '1' : '0';
            $options['ieee80211v'] = $roaming ? '1' : '0';
            $options['ft_over_ds'] = $roaming ? '1' : '0';
            $options['ft_psk_generate_local'] = $roaming ? '1' : '0';
        }

        // 3. Handle Mobility Domain for Fast Roaming
        $isRoamingEnabled = (($options['ieee80211r'] ?? '0') === '1');
        if ($isRoamingEnabled) {
            if (!empty($mobilityDomain)) {
                $options['mobility_domain'] = $mobilityDomain;
            } elseif (empty($options['mobility_domain'])) {
                // Auto-generate consistent 4-hex mobility domain from SSID name
                $options['mobility_domain'] = substr(md5($ssid), 0, 4);
            }
        } else {
            $options['mobility_domain'] = '';
            $options['ieee80211r'] = '0';
            $options['ieee80211k'] = '0';
            $options['ieee80211v'] = '0';
            $options['ft_over_ds'] = '0';
            $options['ft_psk_generate_local'] = '0';
        }

        return $options;
    }
}
