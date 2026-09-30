<?php
declare(strict_types=1);

/**
 * MikroTik RouterOS v7 REST API Client Wrapper.
 */

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

/**
 * RouterOS REST API expects internal IDs with literal '*' (e.g. *1, *2, *37, *A).
 * rawurlencode converts '*' to '%2A', which RouterOS rejects with 400 'no such command prefix'.
 */
function encodeRouterOsId(string $id): string {
    return str_replace('%2A', '*', rawurlencode($id));
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
            CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST => "Cannot connect to $host — check IP/hostname and ensure router is reachable.",
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

function getLteInterfaceId(string $portName = 'lte1'): ?string {
    $res = mikrotikRequest('GET', 'interface/lte');
    if ($res['ok'] && is_array($res['data'])) {
        foreach ($res['data'] as $if) {
            if (($if['name'] ?? '') === $portName) {
                return $if['.id'] ?? null;
            }
        }
    }
    return null;
}
