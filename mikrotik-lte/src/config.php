<?php
declare(strict_types=1);

/**
 * Configuration handling and persistence for MikroTik LTE Manager.
 */

const CONFIG_DEFAULTS = [
    'host' => '192.168.88.1',
    'username' => 'admin',
    'password' => '',
    'port' => 'lte1',
    'https' => true,
    'ssl_verify' => false,
    'timeout' => 10,
    'auto_delete' => true,
    'sync_schedule' => '0 3 * * *',
    'sms_transliterate_accents' => true,
];

const PASSWORD_MASK = '••••••••';

function getConfigPath(): string {
    $custom = __DIR__ . '/../configs/config.json';
    if (file_exists($custom)) {
        return $custom;
    }
    return __DIR__ . '/../config.json';
}

function loadConfig(): array {
    $path = getConfigPath();
    if (!file_exists($path)) {
        return CONFIG_DEFAULTS;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return CONFIG_DEFAULTS;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? array_merge(CONFIG_DEFAULTS, $data) : CONFIG_DEFAULTS;
}

function saveConfig(array $cfg): bool {
    $path = getConfigPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = @file_put_contents($path, $json, LOCK_EX);
    if ($ok !== false) {
        @chmod($path, 0600);
        return true;
    }
    return false;
}

function maskedConfig(array $cfg): array {
    $out = $cfg;
    if (!empty($out['password'])) {
        $out['password'] = PASSWORD_MASK;
    }
    return $out;
}
