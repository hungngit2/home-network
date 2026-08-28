<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Standards.php';
require_once __DIR__ . '/OpenWrtClient.php';
require_once __DIR__ . '/DeviceManager.php';

// Enforce Basic Auth on web requests (skip during CLI scripts)
if (php_sapi_name() !== 'cli') {
    enforce_auth();
}
