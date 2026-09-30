<?php
declare(strict_types=1);

/**
 * SMS handling: PDU parsing, multipart reassembly, transliteration, archive sync, and deletion.
 */

function getArchiveFilePath(): string {
    return __DIR__ . '/../sms_archive.json';
}

/**
 * Dynamic ASCII transliteration utility for GSM-7 compatibility.
 */
function sanitizeMessage(string $message): string {
    if (class_exists('Transliterator')) {
        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
        if ($transliterator !== null) {
            $res = $transliterator->transliterate($message);
            if ($res !== false) {
                return $res;
            }
        }
    }
    return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message) ?: $message;
}

/**
 * 3GPP PDU parser: extracts text, encoding, and UDH multipart concatenation headers.
 */
function parseSmsPdu(string $pduHex): array {
    $bin = @hex2bin($pduHex);
    if ($bin === false || strlen($bin) < 8) {
        return ['text' => null, 'concat' => null];
    }

    $offset = 0;
    $len = strlen($bin);

    // 1. SMSC info (length in octets)
    $smscLen = ord($bin[$offset++]);
    $offset += $smscLen;
    if ($offset >= $len) return ['text' => null, 'concat' => null];

    // 2. First octet of SMS-DELIVER
    $firstOctet = ord($bin[$offset++]);
    $hasUdhi = ($firstOctet & 0x40) !== 0;

    // 3. Sender address
    $addrDigits = ord($bin[$offset++]);
    $addrType = ord($bin[$offset++]);
    $addrBytes = (int)ceil($addrDigits / 2);
    $offset += $addrBytes;
    if ($offset >= $len) return ['text' => null, 'concat' => null];

    // 4. TP-PID (1 octet)
    $tpPid = ord($bin[$offset++]);
    // 5. TP-DCS (1 octet)
    $tpDcs = ord($bin[$offset++]);
    // 6. TP-SCTS (Service Center Time Stamp, 7 octets)
    $offset += 7;
    if ($offset >= $len) return ['text' => null, 'concat' => null];

    // 7. TP-UDL (User Data Length, 1 octet)
    $udl = ord($bin[$offset++]);
    $udData = substr($bin, $offset);

    $concat = null;
    $udOffset = 0;

    // 8. User Data Header (UDH) for multipart / concatenated SMS
    if ($hasUdhi && strlen($udData) > 0) {
        $udhLen = ord($udData[0]);
        $udhOffset = 1;
        while ($udhOffset < $udhLen + 1 && $udhOffset < strlen($udData)) {
            $iei = ord($udData[$udhOffset++]);
            $ieLen = ord($udData[$udhOffset++]);
            if ($iei === 0x00 && $ieLen === 3) {
                // 8-bit concat reference
                $ref = ord($udData[$udhOffset]);
                $total = ord($udData[$udhOffset + 1]);
                $seq = ord($udData[$udhOffset + 2]);
                $concat = ['ref' => 'c8_' . $ref, 'total' => $total, 'seq' => $seq];
            } elseif ($iei === 0x08 && $ieLen === 4) {
                // 16-bit concat reference
                $ref = (ord($udData[$udhOffset]) << 8) | ord($udData[$udhOffset + 1]);
                $total = ord($udData[$udhOffset + 2]);
                $seq = ord($udData[$udhOffset + 3]);
                $concat = ['ref' => 'c16_' . $ref, 'total' => $total, 'seq' => $seq];
            }
            $udhOffset += $ieLen;
        }
        $udOffset = $udhLen + 1;
    }

    $payload = substr($udData, $udOffset);
    $text = null;

    // Determine encoding from TP-DCS (UCS2 = 0x08)
    $isUcs2 = ($tpDcs === 0x08) || (($tpDcs & 0x0C) === 0x08);
    if ($isUcs2) {
        $decoded = @mb_convert_encoding($payload, 'UTF-8', 'UTF-16BE');
        if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
            $text = $decoded;
        }
    }

    return ['text' => $text, 'concat' => $concat, 'is_ucs2' => $isUcs2];
}

/**
 * Synchronize router inbox messages into sms_archive.json and remove them from router.
 */
function syncSms(?bool $deleteFromRouter = null): array {
    $cfg = loadConfig();
    $deleteFromRouter = $deleteFromRouter ?? ($cfg['auto_delete'] ?? true);
    $archiveFile = getArchiveFilePath();

    $fp = fopen($archiveFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return ['ok' => false, 'error' => 'Could not lock archive file'];
    }

    $content = stream_get_contents($fp);
    $archive = !empty($content) ? (json_decode($content, true) ?? []) : [];

    $res = mikrotikRequest('GET', 'tool/sms/inbox');
    if (!$res['ok']) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['ok' => false, 'error' => $res['error']];
    }

    $messages = is_array($res['data']) ? $res['data'] : [];
    if (empty($messages)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['ok' => true, 'synced' => 0];
    }

    $synced = 0;
    $multipartGroups = [];
    $standalone = [];

    foreach ($messages as $m) {
        $id = $m['.id'] ?? '';
        $phone = $m['phone'] ?? $m['phone-number'] ?? '';
        $timestamp = $m['timestamp'] ?? '';
        $rawMsg = $m['message'] ?? '';
        $pdu = $m['pdu'] ?? null;

        $parsedText = null;
        $concat = null;

        if (!empty($pdu)) {
            $pduRes = parseSmsPdu($pdu);
            $parsedText = $pduRes['text'];
            $concat = $pduRes['concat'];
        }

        $finalMsg = ($parsedText !== null && $parsedText !== '') ? $parsedText : $rawMsg;

        if ($concat !== null) {
            $groupKey = $phone . '_' . $concat['ref'] . '_' . $concat['total'];
            if (!isset($multipartGroups[$groupKey])) {
                $multipartGroups[$groupKey] = [
                    'phone' => $phone,
                    'timestamp' => $timestamp,
                    'total' => $concat['total'],
                    'parts' => [],
                    'ids' => [],
                ];
            }
            $multipartGroups[$groupKey]['parts'][$concat['seq']] = $finalMsg;
            $multipartGroups[$groupKey]['ids'][] = $id;
            if (!empty($timestamp)) {
                $multipartGroups[$groupKey]['timestamp'] = $timestamp;
            }
        } else {
            $standalone[] = [
                'id' => $id,
                'part_ids' => [$id],
                'phone' => $phone,
                'timestamp' => $timestamp,
                'message' => $finalMsg,
            ];
        }

        // Delete raw part from router with literal * preserved in URL path
        if ($deleteFromRouter && !empty($id)) {
            mikrotikRequest('DELETE', 'tool/sms/inbox/' . encodeRouterOsId($id));
        }
        $synced++;
    }

    // Append standalone messages
    foreach ($standalone as $s) {
        $exists = false;
        foreach ($archive as $a) {
            if ($a['id'] === $s['id'] || in_array($s['id'], $a['part_ids'] ?? [], true)) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $archive[] = $s;
        }
    }

    // Reassemble and append multipart messages
    foreach ($multipartGroups as $gKey => $g) {
        ksort($g['parts']);
        $combinedMsg = implode('', $g['parts']);
        $primaryId = $g['ids'][0] ?? ('mp_' . md5($gKey));

        $existsIdx = -1;
        foreach ($archive as $idx => $a) {
            $partIds = $a['part_ids'] ?? [$a['id']];
            if ($a['id'] === $primaryId || in_array($a['id'], $g['ids'], true) || !empty(array_intersect($partIds, $g['ids']))) {
                $existsIdx = $idx;
                break;
            }
        }

        if ($existsIdx >= 0) {
            if (strlen($combinedMsg) > strlen($archive[$existsIdx]['message'] ?? '')) {
                $archive[$existsIdx]['message'] = $combinedMsg;
            }
            $archive[$existsIdx]['part_ids'] = array_values(array_unique(array_merge($archive[$existsIdx]['part_ids'] ?? [], $g['ids'])));
        } else {
            $archive[] = [
                'id' => $primaryId,
                'part_ids' => array_values(array_unique($g['ids'])),
                'phone' => $g['phone'],
                'timestamp' => $g['timestamp'],
                'message' => $combinedMsg,
            ];
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);

    return ['ok' => true, 'synced' => $synced];
}

/**
 * Save an outgoing SMS message to the local archive.
 */
function archiveSentMessage(string $phone, string $message): bool {
    $archiveFile = getArchiveFilePath();
    $fp = fopen($archiveFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return false;
    }

    $content = stream_get_contents($fp);
    $archive = !empty($content) ? (json_decode($content, true) ?? []) : [];

    $archive[] = [
        'id' => 'sent_' . time() . '_' . substr(md5($phone . $message . microtime()), 0, 8),
        'part_ids' => [],
        'phone' => $phone,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'type' => 'sent'
    ];

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Delete a single message from archive and router.
 */
function deleteMessage(string $id): bool {
    $archiveFile = getArchiveFilePath();
    $fp = fopen($archiveFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        if (strpos($id, 'sent_') !== 0 && strpos($id, 'mp_') !== 0) {
            mikrotikRequest('DELETE', 'tool/sms/inbox/' . encodeRouterOsId($id));
        }
        return true;
    }

    $content = stream_get_contents($fp);
    $archive = !empty($content) ? (json_decode($content, true) ?? []) : [];
    $newArchive = [];
    $idsToDeleteFromRouter = [$id];

    foreach ($archive as $m) {
        $partIds = $m['part_ids'] ?? [$m['id']];
        $matches = ($m['id'] === $id) || in_array($id, $partIds, true);
        if ($matches) {
            $idsToDeleteFromRouter = array_merge($idsToDeleteFromRouter, $partIds);
            continue;
        }
        $newArchive[] = $m;
    }

    foreach (array_unique($idsToDeleteFromRouter) as $delId) {
        if (!empty($delId) && strpos($delId, 'sent_') !== 0 && strpos($delId, 'mp_') !== 0) {
            mikrotikRequest('DELETE', 'tool/sms/inbox/' . encodeRouterOsId($delId));
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($newArchive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Bulk delete messages by ID array from archive and router.
 */
function bulkDeleteMessages(array $ids): bool {
    if (empty($ids)) return false;
    $archiveFile = getArchiveFilePath();
    $fp = fopen($archiveFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        foreach (array_unique($ids) as $delId) {
            if (!empty($delId) && strpos($delId, 'sent_') !== 0 && strpos($delId, 'mp_') !== 0) {
                mikrotikRequest('DELETE', 'tool/sms/inbox/' . encodeRouterOsId($delId));
            }
        }
        return true;
    }

    $content = stream_get_contents($fp);
    $archive = !empty($content) ? (json_decode($content, true) ?? []) : [];
    $newArchive = [];
    $idsToDeleteFromRouter = $ids;

    foreach ($archive as $m) {
        $partIds = $m['part_ids'] ?? [$m['id']];
        $matches = in_array($m['id'], $ids, true) || !empty(array_intersect($partIds, $ids));
        if ($matches) {
            $idsToDeleteFromRouter = array_merge($idsToDeleteFromRouter, $partIds);
            continue;
        }
        $newArchive[] = $m;
    }

    foreach (array_unique($idsToDeleteFromRouter) as $delId) {
        if (!empty($delId) && strpos($delId, 'sent_') !== 0 && strpos($delId, 'mp_') !== 0) {
            mikrotikRequest('DELETE', 'tool/sms/inbox/' . encodeRouterOsId($delId));
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($newArchive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Get all messages grouped into conversations and sorted chronologically.
 */
function getInboxGrouped(): array {
    $archiveFile = getArchiveFilePath();
    $archive = file_exists($archiveFile) ? (json_decode(file_get_contents($archiveFile), true) ?? []) : [];

    $grouped = [];
    foreach ($archive as $m) {
        $phone = $m['phone'] ?? '';
        if ($phone === '') continue;
        $grouped[$phone][] = [
            'id' => $m['id'] ?? '',
            'part_ids' => $m['part_ids'] ?? [$m['id'] ?? ''],
            'phone' => $phone,
            'timestamp' => $m['timestamp'] ?? '',
            'type' => $m['type'] ?? 'received',
            'message' => $m['message'] ?? '',
        ];
    }

    // Sort messages inside each conversation chronologically (oldest at top, newest at bottom)
    foreach ($grouped as $phone => &$msgs) {
        usort($msgs, function($a, $b) {
            return strtotime($a['timestamp'] ?? '') <=> strtotime($b['timestamp'] ?? '');
        });
    }
    unset($msgs);

    // Sort conversation threads in sidebar by most recent message descending (newest thread at top)
    uksort($grouped, function($a, $b) use ($grouped) {
        $timeA = end($grouped[$a])['timestamp'] ?? '';
        $timeB = end($grouped[$b])['timestamp'] ?? '';
        return strtotime($timeB) <=> strtotime($timeA);
    });

    return $grouped;
}
