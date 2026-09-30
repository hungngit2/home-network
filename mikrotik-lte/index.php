<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Bootstrap services
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/client.php';
require_once __DIR__ . '/src/sms.php';
require_once __DIR__ . '/src/ussd.php';

// Enforce HTTP Basic Authentication if configured
enforce_auth();

// Extract API action if present
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonInput = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonInput)) {
        $action = $jsonInput['action'] ?? null;
    }
}

// Handle API requests
if ($action !== null) {
    $input = array_merge($_GET, $_POST);
    if (empty($input['action'])) {
        $jsonBody = json_decode(file_get_contents('php://input'), true);
        if (is_array($jsonBody)) {
            $input = array_merge($input, $jsonBody);
        }
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
            break;

        case 'sync':
            $res = syncSms();
            jsonResponse($res['ok'], $res);
            break;

        case 'inbox':
            syncSms();
            jsonResponse(true, getInboxGrouped());
            break;

        case 'send':
            $phone = trim($input['phone'] ?? '');
            $message = trim($input['message'] ?? '');
            if ($phone === '' || $message === '') {
                jsonResponse(false, null, 'Phone number and message are required.', 400);
            }

            $cfg = loadConfig();
            $sentText = $message;
            if (($cfg['sms_transliterate_accents'] ?? true)) {
                $sentText = sanitizeMessage($message);
            }

            $res = mikrotikRequest('POST', 'tool/sms/send', [
                'phone-number' => $phone,
                'message' => $sentText,
                'port' => $cfg['port'] ?? 'lte1',
            ]);

            if (!$res['ok']) jsonResponse(false, null, $res['error']);

            archiveSentMessage($phone, $message);
            jsonResponse(true, ['sent' => true]);
            break;

        case 'delete':
            $id = trim($input['id'] ?? '');
            if ($id === '') jsonResponse(false, null, 'Message ID is required.', 400);
            deleteMessage($id);
            jsonResponse(true, ['deleted' => true]);
            break;

        case 'bulk_delete':
            $ids = $input['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                $jsonInput = json_decode(file_get_contents('php://input'), true);
                if (is_array($jsonInput) && !empty($jsonInput['ids'])) {
                    $ids = $jsonInput['ids'];
                }
            }
            if (empty($ids)) jsonResponse(false, null, 'Message IDs are required.', 400);
            bulkDeleteMessages($ids);
            jsonResponse(true, ['deleted' => true]);
            break;

        case 'ussd':
            $code = trim($input['code'] ?? '');
            if ($code === '') jsonResponse(false, null, 'USSD code is required.', 400);
            $atCmd = 'AT+CUSD=1,"' . $code . '",15';

            $cfg = loadConfig();
            $ifaceId = getLteInterfaceId($cfg['port'] ?? 'lte1');
            if (!$ifaceId) jsonResponse(false, null, 'LTE interface not found.', 400);

            $res = mikrotikRequest('POST', 'interface/lte/at-chat', [
                '.id' => $ifaceId,
                'input' => $atCmd,
                'wait' => 'yes',
            ]);
            if (!$res['ok']) jsonResponse(false, null, $res['error']);

            $d = $res['data'];
            $output = is_string($d) ? $d : ($d['output'] ?? $d['ret'] ?? ($d[0]['output'] ?? json_encode($d)));
            jsonResponse(true, parseUssdResponse($output));
            break;

        case 'ussd_cancel':
            $cfg = loadConfig();
            $ifaceId = getLteInterfaceId($cfg['port'] ?? 'lte1');
            if ($ifaceId) {
                mikrotikRequest('POST', 'interface/lte/at-chat', [
                    '.id' => $ifaceId,
                    'input' => 'AT+CUSD=2',
                ]);
            }
            jsonResponse(true, ['cancelled' => true]);
            break;

        case 'lte_monitor':
            $cfg = loadConfig();
            $ifaceId = getLteInterfaceId($cfg['port'] ?? 'lte1');
            if (!$ifaceId) jsonResponse(false, null, 'LTE interface not found.', 400);

            $res = mikrotikRequest('POST', 'interface/lte/monitor', [
                '.id' => $ifaceId,
                'once' => 'yes',
            ]);
            if (!$res['ok']) jsonResponse(false, null, $res['error']);

            $data = is_array($res['data']) ? $res['data'] : [];
            jsonResponse(true, isset($data[0]) ? $data[0] : $data);
            break;

        case 'get_config':
            jsonResponse(true, maskedConfig(loadConfig()));
            break;

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
                'sms_transliterate_accents' => filter_var($input['sms_transliterate_accents'] ?? ($cfg['sms_transliterate_accents'] ?? true), FILTER_VALIDATE_BOOLEAN),
            ];

            $newPass = $input['password'] ?? '';
            if ($newPass !== '' && $newPass !== PASSWORD_MASK) {
                $newCfg['password'] = $newPass;
            }

            $force = filter_var($input['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$force) {
                $testRes = mikrotikRequest('GET', 'system/resource', null, $newCfg);
                if (!$testRes['ok']) {
                    jsonResponse(false, ['needs_force' => true], 'Connection test failed: ' . $testRes['error']);
                }
            }

            if (!saveConfig($newCfg)) {
                jsonResponse(false, null, 'Failed to write config file.', 500);
            }
            jsonResponse(true, maskedConfig($newCfg));
            break;

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
            if ($pw === '' || $pw === PASSWORD_MASK) {
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
            ]);
            break;

        default:
            jsonResponse(false, null, "Unknown action: $action", 400);
            break;
    }
}
?><!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MikroTik LTE &mdash; SMS & USSD</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class', theme: { extend: {} } };</script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-950 text-slate-200 min-h-screen" x-data="smsApp()" x-init="init()">

  <!-- Toast Notification Overlay -->
  <div class="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full" x-cloak>
    <template x-for="t in toasts" :key="t.id">
      <div class="toast-enter rounded-xl px-4 py-3 shadow-2xl border flex items-start gap-3 cursor-pointer"
           :class="{
             'bg-emerald-900/90 border-emerald-700 text-emerald-100': t.type === 'success',
             'bg-red-900/90 border-red-700 text-red-100': t.type === 'error',
             'bg-amber-900/90 border-amber-700 text-amber-100': t.type === 'warning',
             'bg-blue-900/90 border-blue-700 text-blue-100': t.type === 'info'
           }"
           @click="removeToast(t.id)">
        <i class="fas mt-0.5"
           :class="{
             'fa-check-circle': t.type === 'success',
             'fa-exclamation-circle': t.type === 'error',
             'fa-exclamation-triangle': t.type === 'warning',
             'fa-info-circle': t.type === 'info'
           }"></i>
        <span class="text-sm flex-1 leading-snug" x-text="t.message"></span>
      </div>
    </template>
  </div>

  <?php require __DIR__ . '/views/header.php'; ?>

  <!-- Main View Container -->
  <main class="max-w-5xl mx-auto px-4 py-6">
    <?php require __DIR__ . '/views/tabs/sms.php'; ?>
    <?php require __DIR__ . '/views/tabs/ussd.php'; ?>
    <?php require __DIR__ . '/views/tabs/settings.php'; ?>
  </main>

  <?php require __DIR__ . '/views/footer.php'; ?>

  <script src="assets/js/app.js"></script>
</body>
</html>
