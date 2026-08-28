<?php

// Load auth configuration if exists
$authConfigFile = __DIR__ . '/../auth.php';
if (file_exists($authConfigFile)) {
    require_once $authConfigFile;
} else {
    // Default fallback if auth.php is missing
    if (!defined('AUTH_USERNAME')) define('AUTH_USERNAME', '');
    if (!defined('AUTH_PASSWORD')) define('AUTH_PASSWORD', '');
    if (!defined('FORCE_AUTH_FOR_LOCAL')) define('FORCE_AUTH_FOR_LOCAL', false);
}

/**
 * Check if the given client IP address is from a private local network
 */
function is_private_local_ip(string $ip): bool {
    if (empty($ip)) {
        return false;
    }

    if ($ip === '::1' || $ip === '127.0.0.1') {
        return true;
    }

    // Check IPv6 ULA (fd00::/8) and Link-Local (fe80::/10)
    if (strpos($ip, ':') !== false) {
        $firstWord = strtolower(explode(':', $ip)[0]);
        if (substr($firstWord, 0, 2) === 'fd' || substr($firstWord, 0, 2) === 'fc' || substr($firstWord, 0, 4) === 'fe80') {
            return true;
        }
        return false;
    }

    $long = ip2long($ip);
    if ($long === false) {
        return false;
    }

    $ranges = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['127.0.0.0', 8],
    ];

    foreach ($ranges as [$base, $bits]) {
        $mask = -1 << (32 - $bits);
        if (($long & $mask) === (ip2long($base) & $mask)) {
            return true;
        }
    }

    return false;
}

/**
 * Enforce HTTP Basic Authentication
 */
function enforce_auth(): void {
    // If no credentials configured, allow access
    if (AUTH_USERNAME === '' && AUTH_PASSWORD === '') {
        return;
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $needsAuth = FORCE_AUTH_FOR_LOCAL || !is_private_local_ip($clientIp);
    if (!$needsAuth) {
        return;
    }

    $suppliedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $suppliedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if (hash_equals(AUTH_USERNAME, $suppliedUser) && hash_equals(AUTH_PASSWORD, $suppliedPass)) {
        return;
    }

    header('WWW-Authenticate: Basic realm="WiFi Config Tool"');
    http_response_code(401);
    echo '<!DOCTYPE html><html><head><title>401 Unauthorized</title></head><body><h1>401 Unauthorized</h1><p>Authentication required to access WiFi Config Tool.</p></body></html>';
    exit;
}
