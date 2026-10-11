<?php

namespace OpenWrt;

/**
 * Copies the client Wi-Fi setup of one AP onto another, matching radios by band.
 * Mesh/STA interfaces, channels and per-AP identifiers stay as they are on the target.
 */
class ConfigCloner {
    const COPIED_MODES = ['ap'];
    const PER_AP_OPTIONS = ['device', 'macaddr', 'ifname', 'bssid', 'nasid', 'r1_key_holder'];
    const COPIED_RADIO_OPTIONS = ['country', 'cell_density'];

    public static function radioBands(array $wireless): array {
        $bands = [];
        foreach ($wireless as $name => $section) {
            if (($section['.type'] ?? '') === 'wifi-device') {
                $bands[$name] = self::bandOf($section);
            }
        }
        return $bands;
    }

    private static function bandOf(array $radio): string {
        if (!empty($radio['band'])) {
            return (string)$radio['band'];
        }
        $legacy = ['11a' => '5g', '11b' => '2g', '11g' => '2g'];
        return $legacy[$radio['hwmode'] ?? ''] ?? '';
    }

    private static function isCopiedIface(array $section): bool {
        return ($section['.type'] ?? '') === 'wifi-iface'
            && in_array($section['mode'] ?? 'ap', self::COPIED_MODES, true);
    }

    private static function uciSet(string $path, $value): string {
        return 'uci set ' . escapeshellarg("wireless.{$path}=" . $value);
    }

    /**
     * @return array{commands: string[], copied: string[], skipped: string[], replaced: int}
     */
    public static function buildPlan(array $source, array $target): array {
        $sourceBands = self::radioBands($source);
        $targetRadioByBand = [];
        foreach (self::radioBands($target) as $radio => $band) {
            if ($band !== '' && !isset($targetRadioByBand[$band])) {
                $targetRadioByBand[$band] = $radio;
            }
        }

        $commands = ['cp /etc/config/wireless /etc/config/wireless.bak-clone'];
        $copied = [];
        $skipped = [];
        $replaced = 0;
        $usedNames = [];

        foreach ($target as $name => $section) {
            if (self::isCopiedIface($section)) {
                $commands[] = 'uci delete ' . escapeshellarg("wireless.{$name}");
                $replaced++;
            } else {
                $usedNames[$name] = true;
            }
        }

        foreach ($source as $name => $section) {
            if (($section['.type'] ?? '') !== 'wifi-device') {
                continue;
            }
            $targetRadio = $targetRadioByBand[$sourceBands[$name]] ?? null;
            if ($targetRadio === null) {
                continue;
            }
            foreach (self::COPIED_RADIO_OPTIONS as $opt) {
                if (isset($section[$opt]) && $section[$opt] !== '') {
                    $commands[] = self::uciSet("{$targetRadio}.{$opt}", $section[$opt]);
                }
            }
            $commands[] = self::uciSet("{$targetRadio}.disabled", ($section['disabled'] ?? '0') === '1' ? '1' : '0');
        }

        $nextIndex = 1;
        foreach ($source as $name => $section) {
            if (($section['.type'] ?? '') !== 'wifi-iface') {
                continue;
            }
            $mode = $section['mode'] ?? 'ap';
            if (!self::isCopiedIface($section)) {
                $skipped[] = "{$name} ({$mode} interface, left to setup-openwrt-ap.sh)";
                continue;
            }

            $band = $sourceBands[$section['device'] ?? ''] ?? '';
            $label = ($section['ssid'] ?? '(no SSID)') . ' @ ' . ($band ?: '?');
            $targetRadio = $targetRadioByBand[$band] ?? null;
            if ($targetRadio === null) {
                $skipped[] = "{$label} (target has no " . ($band ?: 'matching') . " radio)";
                continue;
            }

            $newName = $name;
            if (!empty($section['.anonymous']) || isset($usedNames[$newName])) {
                while (isset($usedNames["wifinet{$nextIndex}"]) || isset($source["wifinet{$nextIndex}"])) {
                    $nextIndex++;
                }
                $newName = "wifinet{$nextIndex}";
            }
            $usedNames[$newName] = true;

            $commands[] = self::uciSet($newName, 'wifi-iface');
            $commands[] = self::uciSet("{$newName}.device", $targetRadio);
            foreach ($section as $opt => $value) {
                if ($opt[0] === '.' || in_array($opt, self::PER_AP_OPTIONS, true)) {
                    continue;
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $commands[] = 'uci add_list ' . escapeshellarg("wireless.{$newName}.{$opt}=" . $item);
                    }
                } else {
                    $commands[] = self::uciSet("{$newName}.{$opt}", $value);
                }
            }
            $copied[] = $label;
        }

        $commands[] = 'uci commit wireless';

        return ['commands' => $commands, 'copied' => $copied, 'skipped' => $skipped, 'replaced' => $replaced];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function cloneDevice(array $sourceDevice, array $targetDevice): array {
        $source = new OpenWrtClient($sourceDevice['url'], $sourceDevice['username'] ?? 'root', $sourceDevice['ssh_key'] ?? null, $sourceDevice['port'] ?? 22);
        $target = new OpenWrtClient($targetDevice['url'], $targetDevice['username'] ?? 'root', $targetDevice['ssh_key'] ?? null, $targetDevice['port'] ?? 22);

        if (!$source->login()) {
            return ['ok' => false, 'message' => "cannot reach source '{$sourceDevice['name']}': " . $source->getLastError()];
        }
        if (!$target->login()) {
            return ['ok' => false, 'message' => "cannot reach '{$targetDevice['name']}': " . $target->getLastError()];
        }

        $sourceConfig = $source->getWirelessConfig()['values'] ?? [];
        $targetConfig = $target->getWirelessConfig()['values'] ?? [];
        if (empty($sourceConfig) || empty($targetConfig)) {
            return ['ok' => false, 'message' => 'could not read the wireless config from one of the APs'];
        }

        $plan = self::buildPlan($sourceConfig, $targetConfig);
        if (empty($plan['copied'])) {
            return ['ok' => false, 'message' => 'nothing to copy: ' . (implode('; ', $plan['skipped']) ?: 'source has no client SSIDs')];
        }
        if (!$target->runBatch($plan['commands'])) {
            return ['ok' => false, 'message' => 'applying on the target failed: ' . $target->getLastError()];
        }
        $target->applyWifi();

        $message = sprintf(
            "copied %d Wi-Fi interface(s) from '%s' (%s), replacing %d existing",
            count($plan['copied']),
            $sourceDevice['name'],
            implode(', ', $plan['copied']),
            $plan['replaced']
        );
        if (!empty($plan['skipped'])) {
            $message .= '. Skipped: ' . implode('; ', $plan['skipped']);
        }
        return ['ok' => true, 'message' => $message];
    }
}
