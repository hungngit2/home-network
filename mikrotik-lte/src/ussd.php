<?php
declare(strict_types=1);

/**
 * USSD response decoding: parses AT+CUSD strings and decodes UCS2 / hex.
 */

function parseUssdResponse(string $output): array {
    $result = [
        'raw' => $output,
        'decoded' => null,
        'status' => null,
        'status_text' => null,
        'dcs' => null
    ];

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
        $result['decoded'] = trim((string)$clean);
    }

    return $result;
}
