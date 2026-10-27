<?php

/** Build an EMVCo PromptPay QR payload for a static transfer. */
function buildPromptPayPayload(string $promptPayId, float $amount): string {
    $id = preg_replace('/\D+/', '', $promptPayId);
    if (strlen($id) === 12 && str_starts_with($id, '66')) {
        // Accept international-format numbers in environment configuration too.
        $id = '00' . $id;
        $proxyType = '01';
    } elseif (strlen($id) === 10 && $id[0] === '0') {
        // PromptPay mobile proxy is encoded as country code 66 without its trunk 0.
        $id = '0066' . substr($id, 1);
        $proxyType = '01';
    } elseif (strlen($id) === 13) {
        $proxyType = '02';
    } else {
        throw new InvalidArgumentException('PromptPay ID must be a Thai mobile number or 13-digit national/tax ID.');
    }
    if (!is_finite($amount) || $amount <= 0) {
        throw new InvalidArgumentException('PromptPay amount must be greater than zero.');
    }

    $tlv = static function (string $tag, string $value): string {
        return $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    };
    $merchantAccount = $tlv('00', 'A000000677010111') . $tlv($proxyType, $id);
    $payload = $tlv('00', '01')
        . $tlv('01', '12')
        . $tlv('29', $merchantAccount)
        . $tlv('53', '764')
        . $tlv('54', number_format($amount, 2, '.', ''))
        . $tlv('58', 'TH')
        . $tlv('59', 'FLIXO')
        . $tlv('60', 'BANGKOK')
        . $tlv('62', $tlv('05', 'ORDER'))
        . '6304';

    return $payload . strtoupper(str_pad(dechex(promptPayCrc16($payload)), 4, '0', STR_PAD_LEFT));
}

/** CRC-16/CCITT-FALSE used by EMVCo payloads. */
function promptPayCrc16(string $data): int {
    $crc = 0xFFFF;
    for ($i = 0, $length = strlen($data); $i < $length; $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return $crc;
}

/** Return a QR image URL for a locally-generated payload. */
function promptPayQrImageUrl(string $payload, int $size = 250): string {
    $size = max(150, min(600, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . rawurlencode($payload);
}
