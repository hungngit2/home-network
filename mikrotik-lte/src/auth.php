<?php
declare(strict_types=1);

/**
 * Authentication management for MikroTik LTE Manager.
 * Checks for configs/auth.php or auth.php and enforces Basic Authentication.
 */

$authConfigFile = file_exists(__DIR__ . '/../configs/auth.php')
    ? __DIR__ . '/../configs/auth.php'
    : (file_exists(__DIR__ . '/../auth.php') ? __DIR__ . '/../auth.php' : null);

if ($authConfigFile !== null) {
    require_once $authConfigFile;
} else {
    if (!defined('AUTH_USERNAME')) define('AUTH_USERNAME', '');
    if (!defined('AUTH_PASSWORD')) define('AUTH_PASSWORD', '');
}

/**
 * Check if the provided IP address is private or loopback.
 */
function is_private_local_ip(string $ip): bool {
    if ($ip === '::1' || $ip === '127.0.0.1' || $ip === 'localhost') {
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
    if ($long === false) return false;
    $ranges = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['127.0.0.0', 8],
    ];
    foreach ($ranges as [$base, $bits]) {
        $mask = -1 << (32 - $bits);
        if (($long & $mask) === (ip2long($base) & $mask)) return true;
    }
    return false;
}

/**
 * Enforce HTTP Basic Authentication if configured.
 */
function enforce_auth(): void {
    if (AUTH_USERNAME === '' && AUTH_PASSWORD === '') {
        return;
    }
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $forceAuthLocal = defined('FORCE_AUTH_FOR_LOCAL') ? FORCE_AUTH_FOR_LOCAL : false;
    $needsAuth = $forceAuthLocal || !is_private_local_ip($clientIp);
    if (!$needsAuth) return;

    $suppliedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $suppliedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if (hash_equals(AUTH_USERNAME, $suppliedUser) && hash_equals(AUTH_PASSWORD, $suppliedPass)) {
        return;
    }

    header('WWW-Authenticate: Basic realm="MikroTik LTE Manager"');
    http_response_code(401);
    echo 'Authentication required.';
    exit;
}
