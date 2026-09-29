<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Load auth configuration if exists
$authConfigFile = file_exists(__DIR__ . '/configs/auth.php')
    ? __DIR__ . '/configs/auth.php'
    : __DIR__ . '/auth.php';

if (file_exists($authConfigFile)) {
    require_once $authConfigFile;
} else {
    if (!defined('AUTH_USERNAME')) define('AUTH_USERNAME', '');
    if (!defined('AUTH_PASSWORD')) define('AUTH_PASSWORD', '');
}

/**
 * Enforce HTTP Basic Authentication
 */
/**
 * Check if the provided IP address is private local.
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
 * Enforce HTTP Basic Authentication
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

enforce_auth();


$CONFIG_PATH = __DIR__ . '/config.json';
$DEFAULTS = [
    'host' => '192.168.88.1',
    'username' => 'admin',
    'password' => '',
    'port' => 'lte1',
    'https' => true,
    'ssl_verify' => false,
    'timeout' => 10,
    'auto_delete' => true,
    'sync_schedule' => '0 3 * * *',
];
$PASSWORD_MASK = '••••••••';

function loadConfig(): array {
    global $CONFIG_PATH, $DEFAULTS;
    if (!file_exists($CONFIG_PATH)) return $DEFAULTS;
    $data = json_decode(file_get_contents($CONFIG_PATH), true);
    return is_array($data) ? array_merge($DEFAULTS, $data) : $DEFAULTS;
}

function saveConfig(array $cfg): bool {
    global $CONFIG_PATH;
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = file_put_contents($CONFIG_PATH, $json, LOCK_EX);
    if ($ok !== false) @chmod($CONFIG_PATH, 0600);
    return $ok !== false;
}

function maskedConfig(array $cfg): array {
    global $PASSWORD_MASK;
    $out = $cfg;
    if (!empty($out['password'])) $out['password'] = $PASSWORD_MASK;
    return $out;
}

function jsonResponse(bool $success, $data = null, ?string $error = null, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeHost(string $h): string {
    $h = trim($h);
    $h = preg_replace('#^https?://#i', '', $h);
    return rtrim($h, '/');
}

function mikrotikRequest(string $method, string $path, ?array $payload = null, ?array $cfgOverride = null): array {
    $cfg = $cfgOverride ?? loadConfig();
    $host = normalizeHost($cfg['host'] ?? '192.168.88.1');
    $scheme = !empty($cfg['https']) ? 'https' : 'http';
    $url = $scheme . '://' . $host . '/rest/' . ltrim($path, '/');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => ($cfg['username'] ?? 'admin') . ':' . ($cfg['password'] ?? ''),
        CURLOPT_CONNECTTIMEOUT => min((int)($cfg['timeout'] ?? 10), 30),
        CURLOPT_TIMEOUT => min((int)($cfg['timeout'] ?? 10) * 3, 90),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if (empty($cfg['ssl_verify'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    if ($payload !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        $msg = match ($errno) {
            CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST => "Cannot connect to $host — check IP/hostname and ensure the router is reachable.",
            CURLE_OPERATION_TIMEDOUT => "Connection timed out after {$cfg['timeout']}s.",
            default => "cURL error $errno: $errstr",
        };
        return ['ok' => false, 'error' => $msg, 'http' => 0];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $body = ($raw === '' || $raw === false) ? null : json_decode($raw, true);
        return ['ok' => true, 'data' => $body, 'http' => $httpCode];
    }

    $detail = '';
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json) && !empty($json['detail'])) {
            $detail = $json['detail'];
        } elseif (is_array($json) && isset($json['error'])) {
            $detail = is_string($json['error']) ? $json['error'] : ($json['message'] ?? json_encode($json));
        } elseif (is_array($json) && isset($json['message'])) {
            $detail = $json['message'];
        } else {
            $detail = substr(strip_tags((string)$raw), 0, 200);
        }
    }

    $msg = match ($httpCode) {
        401 => 'Authentication failed — check username and password.',
        403 => 'Permission denied — user may lack API access.',
        404 => "Resource not found (404). $detail",
        default => "HTTP $httpCode. $detail",
    };

    return ['ok' => false, 'error' => $msg, 'http' => $httpCode];
}

function parseUssdResponse(string $output): array {
    $result = ['raw' => $output, 'decoded' => null, 'status' => null, 'status_text' => null, 'dcs' => null];
    if (preg_match('/\+CUSD:\s*([0-5])\s*,\s*"([^"]*)"\s*(?:,\s*(\d+))?/i', $output, $m)) {
        $result['status'] = (int)$m[1];
        $rawText = $m[2];
        $result['dcs'] = isset($m[3]) ? (int)$m[3] : 15;
        $statusTexts = [
            0 => 'No further action required',
            1 => 'Further action required (session open)',
            2 => 'USSD terminated by network',
            3 => 'Other local client responded',
            4 => 'Operation not supported',
            5 => 'Network timeout',
        ];
        $result['status_text'] = $statusTexts[$result['status']] ?? 'Unknown';

        if (ctype_xdigit($rawText) && strlen($rawText) >= 4 && strlen($rawText) % 2 === 0) {
            $bin = @hex2bin($rawText);
            if ($bin !== false) {
                if ($result['dcs'] === 72 || $result['dcs'] === 8 || strlen($rawText) % 4 === 0) {
                    $decoded = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
                    if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                        $result['decoded'] = $decoded;
                    }
                }
                if ($result['decoded'] === null) {
                    $result['decoded'] = $bin;
                    if (!mb_check_encoding($result['decoded'], 'UTF-8')) {
                        $result['decoded'] = $rawText;
                    }
                }
            }
        }
        if ($result['decoded'] === null) {
            $result['decoded'] = $rawText;
        }
    } else {
        $clean = trim(str_replace(["\r\n", "\r"], "\n", $output));
        $clean = preg_replace('/^OK$/m', '', $clean);
        $result['decoded'] = trim($clean);
    }
    return $result;
}

function sanitizeMessage(string $message): string {
    if (class_exists('Transliterator')) {
        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
        return $transliterator->transliterate($message);
    }
    return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message);
}

function syncSms(?bool $deleteFromRouter = null): array {
    $cfg = loadConfig();
    $deleteFromRouter = $deleteFromRouter ?? ($cfg['auto_delete'] ?? true);
    $archiveFile = __DIR__ . '/sms_archive.json';
    $fp = fopen($archiveFile, 'c+');
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['ok' => false, 'error' => 'Could not lock archive file'];
    }

    $content = stream_get_contents($fp);
    $archive = !empty($content) ? (json_decode($content, true) ?? []) : [];
    
    $res = mikrotikRequest('GET', 'tool/sms/inbox');
    if (!$res['ok']) {
        flock($fp, LOCK_UN); fclose($fp);
        return ['ok' => false, 'error' => $res['error']];
    }
    
    $messages = is_array($res['data']) ? $res['data'] : [];
    if (empty($messages)) {
        flock($fp, LOCK_UN); fclose($fp);
        return ['ok' => true, 'synced' => 0];
    }

    $synced = 0;
    foreach ($messages as $m) {
        $id = $m['.id'] ?? '';
        
        $exists = false;
        foreach ($archive as $a) {
            if ($a['id'] === $id) { $exists = true; break; }
        }
        
        // Always delete from device if auto_delete is ON, regardless of existence
        if ($deleteFromRouter) {
            mikrotikRequest('DELETE', 'tool/sms/inbox/' . rawurlencode($id));
        }

        if ($exists) {
            $synced++; continue;
        }

        $msg = $m['message'] ?? '';
        if (isset($m['pdu'])) {
            $pdu = $m['pdu'];
            // Search for potential payload start patterns: common UCS-2 00+ASCII chars
            $possiblePayloads = [];
            $patterns = ['0054', '0068', '0041', '0042', '0061']; 
            foreach ($patterns as $pattern) {
                $pos = strpos($pdu, $pattern);
                if ($pos !== false && $pos > 30) {
                    $possiblePayloads[] = substr($pdu, $pos);
                }
            }

            foreach ($possiblePayloads as $payload) {
                $decoded = @mb_convert_encoding(@hex2bin($payload), 'UTF-8', 'UTF-16BE');
                if ($decoded && mb_check_encoding($decoded, 'UTF-8') && preg_match('/[ăâđêôơư]/u', $decoded)) {
                     $msg = $decoded; break;
                }
                $decoded = @mb_convert_encoding(@hex2bin($payload), 'UTF-8', 'UTF-16LE');
                if ($decoded && mb_check_encoding($decoded, 'UTF-8') && preg_match('/[ăâđêôơư]/u', $decoded)) {
                     $msg = $decoded; break;
                }
            }
        }

        $archive[] = [
            'id' => $id,
            'phone' => $m['phone'] ?? $m['phone-number'] ?? '',
            'timestamp' => $m['timestamp'] ?? '',
            'message' => $msg,
        ];
        if ($deleteFromRouter) {
            $delRes = mikrotikRequest('DELETE', 'tool/sms/inbox/' . rawurlencode($id));
            // Log deletion status
            error_log("Attempted to delete $id from router: " . ($delRes['ok'] ? 'Success' : 'Fail ('.$delRes['error'].')'));
        }

        $synced++;
    }


    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return ['ok' => true, 'synced' => $synced];
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonInput = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonInput)) $action = $jsonInput['action'] ?? null;
}

if ($action) {
    $input = array_merge($_GET, $_POST);
    if (empty($input['action'])) {
        $jsonBody = json_decode(file_get_contents('php://input'), true);
        if (is_array($jsonBody)) $input = array_merge($input, $jsonBody);
    }

    switch ($action) {
        case 'status':
            $res = mikrotikRequest('GET', 'system/resource');
            if (!$res['ok']) jsonResponse(false, null, $res['error']);
            $d = $res['data'] ?? [];
            jsonResponse(true, [
                'board' => $d['board-name'] ?? 'Unknown',
                'version' => $d['version'] ?? '',
                'uptime' => $d['uptime'] ?? '',
                'cpu' => $d['cpu'] ?? '',
                'arch' => $d['architecture-name'] ?? '',
                'memory_free' => $d['free-memory'] ?? 0,
                'memory_total' => $d['total-memory'] ?? 0,
            ]);

        case 'sync':
            $res = syncSms();
            jsonResponse($res['ok'], $res);

        case 'inbox':
            syncSms();
            // Read archive only
            $archiveFile = __DIR__ . '/sms_archive.json';
            $archive = file_exists($archiveFile) ? (json_decode(file_get_contents($archiveFile), true) ?? []) : [];
            
            $out = [];
            $grouped = [];
            foreach (array_reverse($archive) as $m) {
                 $out[] = ['id'=>$m['id'], 'phone'=>$m['phone'], 'timestamp'=>$m['timestamp'], 'type'=>'archived', 'message'=>$m['message']];
            $grouped = [];
            foreach ($out as $o) {
                $grouped[$o['phone']][] = $o;
            }
            }
            jsonResponse(true, $grouped);

                case 'send':
            $phone = trim($input['phone'] ?? '');
            $message = trim($input['message'] ?? '');
            if ($phone === '' || $message === '') jsonResponse(false, null, 'Phone number and message are required.', 400);

            $cfg = loadConfig();
            
            $resId = mikrotikRequest('GET', 'interface/lte');
            $ifaceId = null;
            if ($resId['ok'] && is_array($resId['data'])) {
                foreach ($resId['data'] as $if) {
                    if (($if['name'] ?? '') === ($cfg['port'] ?? 'lte1')) {
                        $ifaceId = $if['.id'] ?? null;
                        break;
                    }
                }
            }

            if ($ifaceId) mikrotikRequest('POST', 'interface/lte/at-chat', ['.id' => $ifaceId, 'input' => 'AT+CSCS="UCS2"', 'wait' => 'yes']);
            $res = mikrotikRequest('POST', 'tool/sms/send', [
                'phone-number' => $phone,
                'message' => mb_convert_encoding($message, 'UTF-8', 'UTF-8'),
                'port' => $cfg['port'] ?? 'lte1',
            ]);
            if ($ifaceId) mikrotikRequest('POST', 'interface/lte/at-chat', ['.id' => $ifaceId, 'input' => 'AT+CSCS="GSM"', 'wait' => 'yes']);

            if (!$res['ok']) jsonResponse(false, null, $res['error']);
            jsonResponse(true, ['sent' => true]);

        case 'delete':
            $id = trim($input['id'] ?? '');
            if ($id === '') jsonResponse(false, null, 'Message ID is required.', 400);
            
            // 1. Attempt delete from router (only if exists, ignore 404)
            mikrotikRequest('DELETE', 'tool/sms/inbox/' . rawurlencode($id));
            
            // 2. Delete from archive
            $archiveFile = __DIR__ . '/sms_archive.json';
            $fp = fopen($archiveFile, 'c+');
            if (flock($fp, LOCK_EX)) {
                $content = stream_get_contents($fp);
                $archive = !empty($content) ? (json_decode($content, true) ?? []) : [];
                $newArchive = [];
                foreach ($archive as $m) {
                    if ($m['id'] === $id) continue;
                    $newArchive[] = $m;
                }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($newArchive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                flock($fp, LOCK_UN);
                fclose($fp);
            } else {
                fclose($fp);
            }
            
            jsonResponse(true, ['deleted' => true]);

        case 'bulk_delete':
            $jsonInput = json_decode(file_get_contents('php://input'), true);
            $ids = $jsonInput['ids'] ?? [];
            if (empty($ids)) jsonResponse(false, null, 'Message IDs are required.', 400);

            $archiveFile = __DIR__ . '/sms_archive.json';
            $fp = fopen($archiveFile, 'c+');
            if (flock($fp, LOCK_EX)) {
                $content = stream_get_contents($fp);
                $archive = !empty($content) ? (json_decode($content, true) ?? []) : [];
                $newArchive = [];
                foreach ($archive as $m) {
                    if (in_array($m['id'], $ids)) continue;
                    $newArchive[] = $m;
                }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($newArchive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                flock($fp, LOCK_UN);
                fclose($fp);
            } else {
                fclose($fp);
            }
            jsonResponse(true, ['deleted' => true]);


        case 'ussd':
            $code = trim($input['code'] ?? '');
            if ($code === '') jsonResponse(false, null, 'USSD code is required.', 400);
            if (preg_match('/^\*[\d*#]+#$/', $code)) {
                $atCmd = 'AT+CUSD=1,"' . $code . '",15';
            } elseif (stripos($code, 'AT') === 0) {
                $atCmd = $code;
            } else {
                $atCmd = 'AT+CUSD=1,"' . $code . '",15';
            }
            $cfg = loadConfig();
            // Get interface ID
            $resId = mikrotikRequest('GET', 'interface/lte');
            $ifaceId = null;
            if ($resId['ok'] && is_array($resId['data'])) {
                foreach ($resId['data'] as $if) {
                    if (($if['name'] ?? '') === ($cfg['port'] ?? 'lte1')) {
                        $ifaceId = $if['.id'] ?? null;
                        break;
                    }
                }
            }
            if (!$ifaceId) jsonResponse(false, null, 'Interface not found or ID unknown.', 400);

            $res = mikrotikRequest('POST', 'interface/lte/at-chat', [
                '.id' => $ifaceId,
                'input' => $atCmd,
                'wait' => 'yes',
            ]);
            if (!$res['ok']) jsonResponse(false, null, $res['error']);
            $output = '';
            $d = $res['data'];
            if (is_string($d)) $output = $d;
            elseif (is_array($d)) $output = $d['output'] ?? $d['ret'] ?? ($d[0]['output'] ?? json_encode($d));
            $parsed = parseUssdResponse($output);
            jsonResponse(true, $parsed);
            break;

        case 'ussd_cancel':
            $cfg = loadConfig();
            $res = mikrotikRequest('POST', 'interface/lte/at-chat', [
                'numbers' => $cfg['port'] ?? 'lte1',
                'input' => 'AT+CUSD=2',
            ]);
            jsonResponse(true, ['cancelled' => true]);

        case 'get_config':
            jsonResponse(true, maskedConfig(loadConfig()));

        case 'save_config':
            $cfg = loadConfig();
            $newCfg = [
                'host' => normalizeHost($input['host'] ?? $cfg['host']),
                'username' => trim($input['username'] ?? $cfg['username']),
                'password' => $cfg['password'],
                'port' => trim($input['port'] ?? $cfg['port']),
                'https' => filter_var($input['https'] ?? $cfg['https'], FILTER_VALIDATE_BOOLEAN),
                'ssl_verify' => filter_var($input['ssl_verify'] ?? $cfg['ssl_verify'], FILTER_VALIDATE_BOOLEAN),
                'timeout' => max(1, min(60, (int)($input['timeout'] ?? $cfg['timeout']))),
                'auto_delete' => filter_var($input['auto_delete'] ?? ($cfg['auto_delete'] ?? true), FILTER_VALIDATE_BOOLEAN),
                'sync_schedule' => trim($input['sync_schedule'] ?? ($cfg['sync_schedule'] ?? '0 3 * * *')),
            ];
            $newPass = $input['password'] ?? '';
            global $PASSWORD_MASK;
            if ($newPass !== '' && $newPass !== $PASSWORD_MASK) {
                $newCfg['password'] = $newPass;
            }
            $force = filter_var($input['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$force) {
                $testRes = mikrotikRequest('GET', 'system/resource', null, $newCfg);
                if (!$testRes['ok']) {
                    jsonResponse(false, ['needs_force' => true], 'Connection test failed: ' . $testRes['error']);
                }
            }
            if (!saveConfig($newCfg)) jsonResponse(false, null, 'Failed to write config file.', 500);
            jsonResponse(true, maskedConfig($newCfg));

        case 'test_connection':
            $testCfg = [
                'host' => normalizeHost($input['host'] ?? ''),
                'username' => trim($input['username'] ?? ''),
                'password' => '',
                'port' => trim($input['port'] ?? 'lte1'),
                'https' => filter_var($input['https'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'ssl_verify' => filter_var($input['ssl_verify'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'timeout' => max(1, min(60, (int)($input['timeout'] ?? 10))),
            ];
            $pw = $input['password'] ?? '';
            if ($pw === '' || $pw === $PASSWORD_MASK) {
                $saved = loadConfig();
                $testCfg['password'] = $saved['password'];
            } else {
                $testCfg['password'] = $pw;
            }
            $res = mikrotikRequest('GET', 'system/resource', null, $testCfg);
            if (!$res['ok']) jsonResponse(false, null, $res['error']);
            $d = $res['data'] ?? [];
            jsonResponse(true, [
                'board' => $d['board-name'] ?? 'Unknown',
                'version' => $d['version'] ?? '',
                'uptime' => $d['uptime'] ?? '',
                'cpu' => $d['cpu'] ?? '',
                'arch' => $d['architecture-name'] ?? '',
            ]);


        case 'lte_monitor':
            $cfg = loadConfig();
            $resId = mikrotikRequest('GET', 'interface/lte');
            $ifaceId = null;
            if ($resId['ok'] && is_array($resId['data'])) {
                foreach ($resId['data'] as $if) {
                    if (($if['name'] ?? '') === ($cfg['port'] ?? 'lte1')) {
                        $ifaceId = $if['.id'] ?? null;
                        break;
                    }
                }
            }
            if (!$ifaceId) jsonResponse(false, null, 'Interface not found', 400);

            $res = mikrotikRequest('POST', 'interface/lte/monitor', [
                '.id' => $ifaceId,
                'once' => 'yes'
            ]);
            if (!$res['ok']) jsonResponse(false, null, $res['error']);
            
            $data = is_array($res['data']) ? $res['data'] : [];
            jsonResponse(true, isset($data[0]) ? $data[0] : $data);
            break;

        default:
            jsonResponse(false, null, "Unknown action: $action", 400);
    }
}

$initConfig = json_encode(maskedConfig(loadConfig()));
?><!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MikroTik LTE — SMS & USSD</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:'class',theme:{extend:{}}}</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
body{font-family:'Inter',sans-serif}
.font-mono{font-family:'JetBrains Mono',monospace}
[x-cloak]{display:none!important}
.toast-enter{animation:slideIn .3s ease}
.toast-leave{animation:slideOut .3s ease forwards}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes slideOut{from{transform:translateX(0);opacity:1}to{transform:translateX(100%);opacity:0}}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}
.animate-pulse-dot{animation:pulse-dot 1.5s ease-in-out infinite}
.scrollbar-thin::-webkit-scrollbar{width:6px}
.scrollbar-thin::-webkit-scrollbar-track{background:transparent}
.scrollbar-thin::-webkit-scrollbar-thumb{background:#475569;border-radius:3px}
.dialpad-btn{transition:all .15s ease}
.dialpad-btn:active{transform:scale(.92)}
</style>
</head>
<body class="bg-slate-950 text-slate-200 min-h-screen" x-data="smsApp()" x-init="init()">

<!-- Toasts -->
<div class="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full" x-cloak>
  <template x-for="(t,i) in toasts" :key="t.id">
    <div class="toast-enter rounded-lg px-4 py-3 shadow-xl border flex items-start gap-3 cursor-pointer"
         :class="{
           'bg-emerald-900/90 border-emerald-700 text-emerald-100': t.type==='success',
           'bg-red-900/90 border-red-700 text-red-100': t.type==='error',
           'bg-amber-900/90 border-amber-700 text-amber-100': t.type==='warning',
           'bg-blue-900/90 border-blue-700 text-blue-100': t.type==='info'
         }"
         @click="removeToast(t.id)">
      <i class="fas mt-0.5"
         :class="{'fa-check-circle':t.type==='success','fa-exclamation-circle':t.type==='error','fa-exclamation-triangle':t.type==='warning','fa-info-circle':t.type==='info'}"></i>
      <span class="text-sm flex-1" x-text="t.message"></span>
    </div>
  </template>
</div>

<!-- Delete Confirmation Modal -->
<div x-show="deleteModal.show" x-cloak class="fixed inset-0 z-40 flex items-center justify-center p-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     @keydown.escape.window="deleteModal.show=false">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="deleteModal.show=false"></div>
  <div class="relative bg-slate-800 border border-slate-700 rounded-xl shadow-2xl max-w-md w-full p-6"
       x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 rounded-full bg-red-900/50 flex items-center justify-center"><i class="fas fa-trash-alt text-red-400"></i></div>
      <h3 class="text-lg font-semibold text-slate-100">Delete Message</h3>
    </div>
    <div class="bg-slate-900/50 rounded-lg p-3 mb-4 border border-slate-700">
      <div class="text-xs text-slate-400 mb-1">From: <span class="text-slate-300" x-text="deleteModal.phone"></span></div>
      <p class="text-sm text-slate-300 line-clamp-2" x-text="deleteModal.preview"></p>
    </div>
    <p class="text-sm text-slate-400 mb-5">This action cannot be undone. The message will be permanently removed from the router.</p>
    <div class="flex gap-3 justify-end">
      <button @click="deleteModal.show=false" class="px-4 py-2 text-sm rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 transition">Cancel</button>
      <button @click="confirmDelete()" :disabled="deleteModal.deleting"
              class="px-4 py-2 text-sm rounded-lg bg-red-600 hover:bg-red-500 text-white transition flex items-center gap-2 disabled:opacity-50">
        <i x-show="deleteModal.deleting" class="fas fa-spinner fa-spin"></i>
        <span x-text="deleteModal.deleting?'Deleting...':'Delete'"></span>
      </button>
    </div>
  </div>
</div>

<!-- Header -->
<header class="sticky top-0 z-30 bg-slate-900/95 backdrop-blur border-b border-slate-800">
  <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-lg bg-indigo-600 flex items-center justify-center"><i class="fas fa-tower-cell text-white text-sm"></i></div>
      <div>
        <h1 class="text-base font-semibold text-white leading-tight">MikroTik LTE</h1>
        <p class="text-xs text-slate-400" x-text="connected ? (lteStatus?.['current-operator'] || 'Loading...') : 'Disconnected'"></p>
        </div>
    </div>
    <div class="flex items-center gap-4 text-xs font-mono text-slate-400">
        <div x-show="lteStatus" class="flex items-center gap-1 cursor-pointer group relative">
            <div class="flex items-end gap-0.5 h-3">
                <template x-for="i in 5">
                   <div class="w-1 rounded-sm" :class="i <= lteStatus.signalStrength.bars ? lteStatus.signalStrength.color : 'bg-slate-700'" :style="'height:' + (i * 20) + '%'"></div>
                </template>
            </div>
            <span x-text="(lteStatus?.rssi || 'N/A')"></span>
            
            <!-- Details Popover -->
            <div class="absolute right-0 top-full mt-2 w-64 bg-slate-800 border border-slate-700 rounded-lg p-3 shadow-xl hidden group-hover:block z-50">
                 <div class="space-y-1 text-slate-300">
                    <p><b>Model:</b> <span x-text="lteStatus?.model"></span></p>
                    <p><b>IMEI:</b> <span x-text="lteStatus?.imei"></span></p>
                    <p><b>Uptime:</b> <span x-text="lteStatus?.['session-uptime']"></span></p>
                 </div>
            </div>
        </div>
        <span x-show="lteStatus" class="px-2 py-0.5 rounded-full text-[10px] uppercase font-bold" 
            :class="lteStatus?.['data-class'] === 'LTE' ? 'bg-emerald-900 text-emerald-300' : 'bg-blue-900 text-blue-300'"
            x-text="lteStatus?.['data-class']"></span>
        <button @click="fetchLteStatus()" class="hover:text-white transition"><i class="fas fa-sync-alt" :class="{'fa-spin':statusLoading}"></i></button>
    </div>
  </div>
  <!-- Tabs -->
  <div class="max-w-5xl mx-auto px-4">
    <nav class="flex gap-1 -mb-px overflow-x-auto scrollbar-thin">
      <template x-for="t in tabs" :key="t.id">
        <button @click="switchTab(t.id)"
                class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition whitespace-nowrap"
                :class="tab===t.id ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600'">
          <i :class="t.icon" class="text-xs"></i>
          <span x-text="t.label"></span>
          <span x-show="t.id==='inbox' && messages.length" class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-indigo-600 text-white" x-text="messages.length"></span>
        </button>
      </template>
    </nav>
  </div>
</header>

<!-- Main Content -->
<main class="max-w-5xl mx-auto px-4 py-6">

  <!-- INBOX TAB -->
    <!-- Unified Inbox Layout -->
    <div x-show="tab==='inbox'" x-cloak class="flex h-[calc(100vh-120px)] border border-slate-700 rounded-xl overflow-hidden bg-slate-900">
      
      <!-- Left Sidebar: Contacts -->
      <div class="w-full md:w-1/3 border-r border-slate-700 flex flex-col" :class="activePhone ? 'hidden md:flex' : 'flex'">
        <div class="p-4 border-b border-slate-700 flex justify-between items-center bg-slate-800">
          <h3 class="font-semibold text-white">Threads</h3>
          <button @click="activePhone='NEW'" class="text-indigo-400 hover:text-white"><i class="fas fa-plus"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto">
          <template x-for="(messages, phone) in groupedMessages()" :key="phone">
            <div @click="activePhone=phone" class="p-4 cursor-pointer border-b border-slate-700 hover:bg-slate-800 transition"
                 :class="activePhone===phone ? 'bg-slate-800' : ''">
              <p class="font-semibold text-white" x-text="phone"></p>
              <p class="text-xs text-slate-400 truncate" x-text="messages[messages.length-1].message"></p>
            </div>
          </template>
        </div>
      </div>

      <!-- Right Main: Thread -->
      <div class="flex-1 flex flex-col bg-slate-950" :class="activePhone ? 'flex' : 'hidden md:flex'">
        <div class="p-4 border-b border-slate-700 flex justify-between items-center bg-slate-900">
          <button @click="activePhone=null" class="md:hidden text-slate-400"><i class="fas fa-arrow-left"></i></button>
          <h3 class="font-semibold text-white" x-text="activePhone === 'NEW' ? 'New Message' : (activePhone || 'Select a thread')"></h3>
          <button x-show="activePhone && activePhone !== 'NEW'" @click="bulkDeleteThread(activePhone)" class="text-red-400 hover:text-red-300 text-sm"><i class="fas fa-trash"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto p-4 space-y-4">
          <template x-if="activePhone && activePhone !== 'NEW'">
            <template x-for="m in messages[activePhone]" :key="m.id">
              <div class="flex items-start gap-2" :class="m.type==='received' ? 'flex-row' : 'flex-row-reverse'">
                <div class="max-w-[70%] p-3 rounded-xl text-sm" :class="m.type==='received' ? 'bg-slate-800 text-white' : 'bg-indigo-600 text-white'">
                  <p x-text="m.message"></p>
                  <p class="text-[10px] opacity-70 mt-1" x-text="m.timestamp"></p>
                </div>
                <button @click="deleteSingle(m.id)" class="text-slate-600 hover:text-red-400"><i class="fas fa-trash text-xs"></i></button>
              </div>
            </template>
          </template>
        </div>
        <!-- Compose Area -->
        <div class="p-4 bg-slate-900 border-t border-slate-700">
            <div class="flex gap-2">
                <input x-show="!activePhone || activePhone === 'NEW'" x-model="compose.phone" type="text" placeholder="Phone Number" class="w-1/4 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white">
                <input x-model="compose.message" type="text" :placeholder="activePhone && activePhone !== 'NEW' ? 'Reply to ' + activePhone + '...' : 'Type a message...'" class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white" @keydown.enter="sendSms()">
                <button @click="sendSms()" :disabled="compose.sending" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm disabled:opacity-50">
                    <span x-text="compose.sending ? 'Sending...' : 'Send'"></span>
                </button>
            </div>
        </div>
      </div>
    </div>



  <!-- COMPOSE TAB -->
  <div x-show="tab==='compose'" x-cloak>
    <div class="max-w-lg mx-auto">
      <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-6">
        <h2 class="text-lg font-semibold text-slate-100 mb-4 flex items-center gap-2"><i class="fas fa-pen-to-square text-indigo-400"></i> Compose SMS</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Recipient</label>
            <div class="relative">
              <i class="fas fa-phone absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm"></i>
              <input x-model="compose.phone" type="tel" placeholder="+84 912 345 678"
                     class="w-full pl-10 pr-10 py-2.5 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition text-sm">
              <button x-show="compose.phone" @click="compose.phone=''" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                <i class="fas fa-times text-xs"></i>
              </button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Message</label>
            <textarea x-model="compose.message" rows="4" maxlength="640" placeholder="Type your message..."
                      class="w-full px-4 py-2.5 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition text-sm resize-none"></textarea>
            <div class="flex items-center justify-between mt-1.5">
              <div class="flex items-center gap-2">
                <span class="text-xs font-mono" :class="compose.message.length>160?'text-amber-400':'text-slate-500'"
                      x-text="compose.message.length + ' / ' + (compose.message.length>160?'640':'160')"></span>
                <span x-show="compose.message.length>160" class="text-xs text-amber-400/70"
                      x-text="'(' + Math.ceil(compose.message.length/153) + ' parts)'"></span>
              </div>
              <div class="h-1 w-24 bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all"
                     :class="compose.message.length>160?'bg-amber-500':'bg-indigo-500'"
                     :style="'width:'+Math.min(100,compose.message.length/(compose.message.length>160?640:160)*100)+'%'"></div>
              </div>
            </div>
          </div>
          <div class="flex gap-3">
            <button @click="sendSms()" :disabled="!compose.phone.trim()||!compose.message.trim()||compose.sending"
                    class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed">
              <i :class="compose.sending?'fas fa-spinner fa-spin':'fas fa-paper-plane'" class="text-xs"></i>
              <span x-text="compose.sending?'Sending...':'Send SMS'"></span>
            </button>
            <button @click="compose.phone='';compose.message=''" class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm transition">
              Clear
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- USSD TAB -->
  <div x-show="tab==='ussd'" x-cloak>
    <div class="grid md:grid-cols-2 gap-4">
      <!-- Dial Pad -->
      <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-5">
        <h2 class="text-lg font-semibold text-slate-100 mb-4 flex items-center gap-2"><i class="fas fa-hashtag text-indigo-400"></i> USSD Terminal</h2>
        <div class="relative mb-4">
          <input x-model="ussd.code" type="text" placeholder="*101#"
                 @keydown.enter="sendUssd()"
                 class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-xl text-center font-mono text-slate-100 placeholder-slate-600 focus:outline-none focus:border-indigo-500 transition tracking-widest">
        </div>
        <!-- Presets -->
        <div class="flex flex-wrap gap-2 mb-4">
          <template x-for="p in ussd.presets" :key="p.code">
            <button @click="ussd.code=p.code"
                    class="px-3 py-1.5 text-xs bg-slate-700/80 hover:bg-indigo-600/30 border border-slate-600 hover:border-indigo-500 rounded-lg text-slate-300 hover:text-indigo-300 transition font-mono">
              <span x-text="p.code"></span>
              <span x-show="p.label" class="ml-1 text-slate-500 font-sans" x-text="'— '+p.label"></span>
            </button>
          </template>
        </div>
        <!-- Keypad -->
        <div class="grid grid-cols-3 gap-2 mb-4">
          <template x-for="k in dialKeys" :key="k.v">
            <button @click="ussd.code+=k.v" class="dialpad-btn py-3 bg-slate-700/60 hover:bg-slate-600 rounded-lg text-center transition">
              <span class="block text-lg font-semibold text-slate-100" x-text="k.v"></span>
              <span class="block text-[10px] text-slate-500 leading-none" x-text="k.sub||''"></span>
            </button>
          </template>
        </div>
        <div class="grid grid-cols-3 gap-2">
          <button @click="ussd.code=ussd.code.slice(0,-1)" class="dialpad-btn py-2.5 bg-slate-700/40 hover:bg-slate-600 rounded-lg text-sm text-slate-400 transition">
            <i class="fas fa-delete-left"></i>
          </button>
          <button @click="sendUssd()" :disabled="!ussd.code.trim()||ussd.loading"
                  class="dialpad-btn py-2.5 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm text-white font-medium transition disabled:opacity-40 flex items-center justify-center gap-2">
            <i :class="ussd.loading?'fas fa-spinner fa-spin':'fas fa-paper-plane'" class="text-xs"></i> Send
          </button>
          <button @click="ussd.code=''" class="dialpad-btn py-2.5 bg-slate-700/40 hover:bg-slate-600 rounded-lg text-sm text-slate-400 transition">
            Clear
          </button>
        </div>
      </div>

      <!-- Console -->
      <div class="bg-slate-900 border border-slate-700 rounded-xl flex flex-col min-h-[400px]">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-800">
          <div class="flex items-center gap-2">
            <div class="flex gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-red-500/80"></span><span class="w-2.5 h-2.5 rounded-full bg-amber-500/80"></span><span class="w-2.5 h-2.5 rounded-full bg-emerald-500/80"></span></div>
            <span class="text-xs text-slate-500 font-mono ml-2">ussd-terminal</span>
          </div>
          <div class="flex items-center gap-1">
            <button @click="cancelUssd()" title="Cancel session" class="p-1.5 text-slate-500 hover:text-red-400 transition"><i class="fas fa-stop text-xs"></i></button>
            <button @click="copyConsole()" title="Copy output" class="p-1.5 text-slate-500 hover:text-slate-200 transition"><i class="fas fa-copy text-xs"></i></button>
            <button @click="ussd.console=[]" title="Clear console" class="p-1.5 text-slate-500 hover:text-slate-200 transition"><i class="fas fa-eraser text-xs"></i></button>
          </div>
        </div>
        <div class="flex-1 p-4 overflow-y-auto scrollbar-thin font-mono text-sm space-y-2" x-ref="ussdConsole">
          <div x-show="ussd.console.length===0" class="text-slate-600 text-center py-8">
            <i class="fas fa-terminal text-2xl mb-2"></i>
            <p>Enter a USSD code to begin</p>
          </div>
          <template x-for="(line,i) in ussd.console" :key="i">
            <div>
              <div x-show="line.type==='cmd'" class="text-indigo-400"><span class="text-slate-600 text-xs" x-text="line.time+' '"></span>$ <span x-text="line.text"></span></div>
              <div x-show="line.type==='out'" class="text-emerald-300 pl-4 whitespace-pre-wrap select-text" x-text="line.text"></div>
              <div x-show="line.type==='raw'" class="text-slate-500 pl-4 text-xs whitespace-pre-wrap select-text" x-text="'[raw] '+line.text"></div>
              <div x-show="line.type==='status'" class="text-amber-400/80 pl-4 text-xs" x-text="line.text"></div>
              <div x-show="line.type==='error'" class="text-red-400 pl-4" x-text="line.text"></div>
              <div x-show="line.type==='info'" class="text-slate-500 pl-4 text-xs" x-text="line.text"></div>
            </div>
          </template>
          <div x-show="ussd.loading" class="text-indigo-400 animate-pulse flex items-center gap-2 pl-4">
            <i class="fas fa-spinner fa-spin text-xs"></i> Waiting for response...
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- SETTINGS TAB -->
  <div x-show="tab==='settings'" x-cloak>
    <div class="max-w-lg mx-auto">
      <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-6 mb-4">
        <h2 class="text-lg font-semibold text-slate-100 mb-5 flex items-center gap-2"><i class="fas fa-info-circle text-indigo-400"></i> Router Status</h2>
        <div class="flex items-center gap-3 p-4 rounded-lg border text-sm"
             :class="connected ? 'bg-emerald-900/30 border-emerald-700 text-emerald-200' : 'bg-red-900/30 border-red-700 text-red-200'">
          <span class="w-3 h-3 rounded-full" :class="connected ? 'bg-emerald-400' : 'bg-red-400'"></span>
          <span x-text="connected ? routerInfo : 'Disconnected'"></span>
          <button @click="checkStatus()" class="ml-auto text-xs px-2 py-1 bg-slate-700 hover:bg-slate-600 rounded">Refresh</button>
        </div>
      </div>
      <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-6">
        <h2 class="text-lg font-semibold text-slate-100 mb-5 flex items-center gap-2"><i class="fas fa-gear text-indigo-400"></i> Router Settings</h2>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-300 mb-1">Router Address</label>
              <input x-model="settings.host" type="text" placeholder="192.168.88.1"
                     class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
            </div>
            <div class="col-span-2 sm:col-span-1">
              <label class="block text-sm font-medium text-slate-300 mb-1">LTE Interface</label>
              <input x-model="settings.port" type="text" placeholder="lte1"
                     class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1">Username</label>
              <input x-model="settings.username" type="text" placeholder="admin"
                     class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1">Password</label>
              <div class="relative">
                <input :type="showPassword?'text':'password'" x-model="settings.password" placeholder="••••••••"
                       class="w-full px-3 py-2 pr-9 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
                <button @click="showPassword=!showPassword" type="button" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                  <i :class="showPassword?'fas fa-eye-slash':'fas fa-eye'" class="text-xs"></i>
                </button>
              </div>
            </div>
          </div>
          <div class="flex flex-wrap gap-x-6 gap-y-2">
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
              <input type="checkbox" x-model="settings.https" class="rounded border-slate-600 bg-slate-900 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0">
              Use HTTPS
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
              <input type="checkbox" x-model="settings.ssl_verify" class="rounded border-slate-600 bg-slate-900 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0">
              Verify SSL
            </label>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1">Timeout (seconds)</label>
            <input x-model.number="settings.timeout" type="number" min="1" max="60"
                   class="w-24 px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition">
          </div>
          <div class="flex flex-wrap gap-x-6 gap-y-2">
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
              <input type="checkbox" x-model="settings.auto_delete" class="rounded border-slate-600 bg-slate-900 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0">
              Auto-delete from router
            </label>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1">Sync Schedule (Cron)</label>
            <input x-model="settings.sync_schedule" type="text" placeholder="0 3 * * *"
                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
          </div>
          <!-- Test Result -->
          <div x-show="settings.testResult" x-cloak class="rounded-lg p-3 border text-sm"
               :class="settings.testResult?.ok ? 'bg-emerald-900/30 border-emerald-700 text-emerald-200' : 'bg-red-900/30 border-red-700 text-red-200'">
            <template x-if="settings.testResult?.ok">
              <div class="space-y-1">
                <div class="font-medium"><i class="fas fa-check-circle mr-1"></i> Connected</div>
                <div class="text-xs text-emerald-300/70" x-text="'Board: '+(settings.testResult.data?.board||'')"></div>
                <div class="text-xs text-emerald-300/70" x-text="'RouterOS: '+(settings.testResult.data?.version||'')+' ('+( settings.testResult.data?.arch||'')+')'"></div>
                <div class="text-xs text-emerald-300/70" x-text="'Uptime: '+(settings.testResult.data?.uptime||'')"></div>
              </div>
            </template>
            <template x-if="!settings.testResult?.ok">
              <div><i class="fas fa-times-circle mr-1"></i> <span x-text="settings.testResult?.error||'Connection failed'"></span></div>
            </template>
          </div>
          <div class="flex gap-3 pt-2">
            <button @click="testConnection()" :disabled="settings.testing"
                    class="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm transition disabled:opacity-50">
              <i :class="settings.testing?'fas fa-spinner fa-spin':'fas fa-plug'" class="text-xs"></i>
              <span x-text="settings.testing?'Testing...':'Test Connection'"></span>
            </button>
            <button @click="saveSettings()" :disabled="settings.saving"
                    class="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium transition disabled:opacity-50">
              <i :class="settings.saving?'fas fa-spinner fa-spin':'fas fa-save'" class="text-xs"></i>
              <span x-text="settings.saving?'Saving...':'Save Settings'"></span>
            </button>
          </div>
        </div>
        <div class="mt-5 pt-4 border-t border-slate-700">
          <p class="text-xs text-slate-500"><i class="fas fa-info-circle mr-1"></i> Ensure the REST API is enabled on your router: <code class="text-slate-400">IP > Services > www-ssl</code> (or <code class="text-slate-400">www</code> for HTTP). The <code class="text-slate-400">api</code> service is not required.</p>
        </div>
      </div>
    </div>
  </div>

</main>

<footer class="text-center py-4 text-xs text-slate-600 border-t border-slate-800/50">
  MikroTik LTE SMS & USSD Manager — RouterOS v7 REST API
</footer>

<script>
function smsApp() {
  return {
    tab: "inbox",
    tabs: [
      {id:"inbox", label:"SMS", icon:"fas fa-comments"},
      {id:"ussd", label:"USSD", icon:"fas fa-hashtag"},
      {id:"settings", label:"Settings", icon:"fas fa-gear"},
    ],
    connected: false,
    routerInfo: "",
    statusLoading: false,
    messages: {},
    inboxLoading: false,
    inboxSearch: "",
    compose: {phone:"", message:"", sending:false},
    ussd: {
      code: "",
      loading: false,
      console: [],
      presets: [
        {code:"*101#", label:"Balance"},
        {code:"*102#", label:"Number"},
        {code:"*111#", label:"Menu"},
        {code:"*098#", label:"Info"},
      ],
    },
    dialKeys: [
      {v:"1",sub:""},{v:"2",sub:"ABC"},{v:"3",sub:"DEF"},
      {v:"4",sub:"GHI"},{v:"5",sub:"JKL"},{v:"6",sub:"MNO"},
      {v:"7",sub:"PQRS"},{v:"8",sub:"TUV"},{v:"9",sub:"WXYZ"},
      {v:"*",sub:""},{v:"0",sub:"+"},{v:"#",sub:""},
    ],
    settings: {host:"",username:"",password:"",port:"",https:true,ssl_verify:false,timeout:10,auto_delete:true,sync_schedule:"0 3 * * *",testing:false,saving:false,testResult:null},
    showPassword: false,
    deleteModal: {show:false,id:"",phone:"",preview:"",deleting:false},
    toasts: [],
    _toastId: 0,
    activePhone: null,
    selectedIds: [],
    
    init() {
      this.fetchLteStatus();
      setInterval(() => this.fetchLteStatus(), 10000);
      this.checkStatus();
      this.fetchInbox().then(() => {
        const phones = Object.keys(this.messages);
        if (phones.length > 0) this.activePhone = phones[0];
      });
      this.loadSettings();
    },

    async deleteSingle(id) {
        if(!confirm("Delete this message?")) return;
        try {
            const r = await this.api("delete", {id: id});
            if(r.success) {
                this.fetchInbox();
                this.showToast("success", "Deleted");
            } else {
                this.showToast("error", r.error || "Delete failed");
            }
        } catch(e) { this.showToast("error", "Delete failed"); }
    },

    async bulkDeleteThread(phone) {
        if(!confirm("Delete all messages in this thread?")) return;
        const ids = this.messages[phone].map(m => m.id);
        try {
            const r = await this.api("bulk_delete", {ids: ids});
            if(r.success) {
                this.activePhone = null;
                this.fetchInbox();
                this.showToast("success", "Thread deleted");
            }
        } catch(e) { this.showToast("error", "Delete failed"); }
    },

    switchTab(id) { this.tab = id; if (id === "inbox") this.fetchInbox(); },
    showToast(type, message) { const id = ++this._toastId; this.toasts.push({id, type, message}); setTimeout(() => this.removeToast(id), 4000); },
    removeToast(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
    async api(action, data={}) { const body = {action, ...data}; const res = await fetch(window.location.pathname, { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify(body), }); return res.json(); },
    async checkStatus() { this.statusLoading = true; try { const r = await this.api("status"); if (r.success) { this.connected = true; const d = r.data; this.routerInfo = (d.board || "Router") + " · v" + (d.version || "?"); } else { this.connected = false; this.routerInfo = ""; } } catch(e) { this.connected = false; this.routerInfo = ""; } this.statusLoading = false; },
    async fetchInbox() { this.inboxLoading = true; try { const r = await this.api("inbox"); if (r.success) { this.messages = r.data || {}; } else { this.showToast("error", r.error || "Failed to fetch inbox"); } } catch(e) { this.showToast("error", "Network error fetching inbox"); } this.inboxLoading = false; },
    groupedMessages() { if (!this.inboxSearch.trim()) return this.messages; const q = this.inboxSearch.toLowerCase(); const filtered = {}; for (const phone in this.messages) { if (phone.toLowerCase().includes(q)) { filtered[phone] = this.messages[phone]; } else { const sub = this.messages[phone].filter(m => m.message.toLowerCase().includes(q)); if (sub.length) filtered[phone] = sub; } } return filtered; },
    replyTo(m) { this.compose.phone = m.phone || ""; this.compose.message = ""; this.showToast("info", "Replying to " + m.phone); },
    async sendSms() {
      if (this.compose.sending) return;
      this.compose.sending = true;
      try {
        const phone = (this.activePhone && this.activePhone !== 'NEW') ? this.activePhone : this.compose.phone.trim();
        const message = this.compose.message.trim();
        if (!phone || !message) {
            this.showToast('error', 'Phone number and message are required.');
            this.compose.sending = false;
            return;
        }
        const r = await this.api('send', {phone, message});
        if (r.success) {
          this.showToast('success', 'SMS sent');
          this.compose.message = '';
          await this.fetchInbox();
          this.activePhone = phone;
        } else {
          this.showToast('error', r.error || 'Failed to send SMS');
        }
      } catch(e) {
        this.showToast('error', 'Network error sending SMS');
      }
      this.compose.sending = false;
    },
    timeNow() { return new Date().toLocaleTimeString("en-GB", {hour:"2-digit",minute:"2-digit",second:"2-digit"}); },
    async sendUssd() { if (!this.ussd.code.trim() || this.ussd.loading) return; const code = this.ussd.code.trim(); this.ussd.console.push({type:"cmd", text:code, time:this.timeNow()}); this.ussd.loading = true; try { const r = await this.api("ussd", {code}); if (r.success && r.data) { const d = r.data; if (d.decoded) this.ussd.console.push({type:"out", text:d.decoded, time:this.timeNow()}); if (d.raw && d.raw !== d.decoded) this.ussd.console.push({type:"raw", text:d.raw, time:this.timeNow()}); } else { this.ussd.console.push({type:"error", text: r.error || "Failed", time:this.timeNow()}); } } catch(e) { this.ussd.console.push({type:"error", text:"Network error", time:this.timeNow()}); } this.ussd.loading = false; },
    async cancelUssd() { try { await this.api("ussd_cancel"); this.ussd.console.push({type:"info", text:"USSD session cancelled", time:this.timeNow()}); } catch(e) {} },
    copyConsole() { const text = this.ussd.console.map(l => (l.time||"")+" "+l.text).join("\n"); navigator.clipboard.writeText(text).then(() => this.showToast("info","Copied to console output")); },
    async loadSettings() { try { const r = await this.api("get_config"); if (r.success && r.data) { Object.assign(this.settings, { host: r.data.host || "", username: r.data.username || "", password: r.data.password || "", port: r.data.port || "", https: !!r.data.https, ssl_verify: !!r.data.ssl_verify, timeout: r.data.timeout || 10, auto_delete: !!r.data.auto_delete, sync_schedule: r.data.sync_schedule || "0 3 * * *", }); } } catch(e) {} },

    lteStatus: null,
    async fetchLteStatus() {
        try {
            const r = await this.api('lte_monitor');
            if(r.success) {
                this.lteStatus = r.data;
                this.lteStatus.signalStrength = this.calculateSignalBars(this.lteStatus.rssi);
            }
        } catch(e) { console.error('LTE monitor failed', e); }
    },
    
    calculateSignalBars(rssi) {
        if (!rssi) return { bars: 0, label: 'No Signal', color: 'bg-slate-600' };
        const val = parseInt(rssi);
        if (val >= -70) return { bars: 5, label: 'Excellent', color: 'bg-emerald-500' };
        if (val >= -85) return { bars: 4, label: 'Good', color: 'bg-emerald-400' };
        if (val >= -100) return { bars: 3, label: 'Fair', color: 'bg-amber-500' };
        return { bars: 1, label: 'Poor', color: 'bg-red-500' };
    },

    async testConnection() { this.settings.testing = true; this.settings.testResult = null; try { const r = await this.api("test_connection", { host: this.settings.host, username: this.settings.username, password: this.settings.password, port: this.settings.port, https: this.settings.https, ssl_verify: this.settings.ssl_verify, timeout: this.settings.timeout, }); this.settings.testResult = r.success ? {ok:true, data:r.data} : {ok:false, error:r.error}; } catch(e) { this.settings.testResult = {ok:false, error:"Network error"}; } this.settings.testing = false; },
    async saveSettings() { this.settings.saving = true; try { const r = await this.api("save_config", { host: this.settings.host, username: this.settings.username, password: this.settings.password, port: this.settings.port, https: this.settings.https, ssl_verify: this.settings.ssl_verify, timeout: this.settings.timeout, auto_delete: this.settings.auto_delete, sync_schedule: this.settings.sync_schedule, }); if (r.success) { this.showToast("success", "Settings saved"); this.checkStatus(); } else { if (r.data?.needs_force) { if (confirm("Connection test failed: " + (r.error||"") + "\n\nSave anyway?")) { const r2 = await this.api("save_config", { host:this.settings.host, username:this.settings.username, password:this.settings.password, port:this.settings.port, https:this.settings.https, ssl_verify:this.settings.ssl_verify, timeout:this.settings.timeout, auto_delete:this.settings.auto_delete, sync_schedule:this.settings.sync_schedule, force:true, }); if (r2.success) { this.showToast("warning","Settings saved"); this.checkStatus(); } else { this.showToast("error", r2.error||"Failed"); } } } else { this.showToast("error", r.error||"Failed"); } } } catch(e) { this.showToast("error","Network error saving"); } this.settings.saving = false; },
  };
}</script>
</body>
</html>
