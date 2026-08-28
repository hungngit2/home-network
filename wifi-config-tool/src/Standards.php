<?php

namespace OpenWrt;

class Standards {
    private static $file = __DIR__ . '/../standards.json';
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
                'bss_transition' => '1'
            ],
            'fast_roaming' => [
                'ieee80211r' => '1',
                'ieee80211k' => '1',
                'ieee80211v' => '1',
                'ft_over_ds' => '1',
                'ft_psk_generate_local' => '1'
            ],
            'network_defaults' => [
                'lan' => ['ieee80211w' => '1'],
                'guest' => ['ieee80211w' => '1'],
                'iot' => ['ieee80211w' => '0']
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
    public static function buildInterfaceOptions(string $ssid, string $key, string $network, string $mfp, bool $roaming, string $mobilityDomain, ?string $device = null): array {
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
        $options['ieee80211w'] = $mfp;

        if ($roaming) {
            $roamingConfig = $standards['fast_roaming'] ?? [];
            foreach ($roamingConfig as $k => $v) {
                $options[$k] = (string)$v;
            }
            if (!empty($mobilityDomain)) {
                $options['mobility_domain'] = $mobilityDomain;
            }
        } else {
            $options['ieee80211r'] = '0';
            $options['ieee80211k'] = '0';
            $options['ieee80211v'] = '0';
            $options['mobility_domain'] = '';
        }

        return $options;
    }
}
