<?php

declare(strict_types=1);

/**
 * @return array{success:bool,http_code:int,error:string,response:array<string,mixed>|null}
 */
function sendTelegramText(string $chatId, string $message): array
{
    if (!defined('TELEGRAM_BOT_TOKEN')) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'TELEGRAM_BOT_TOKEN belum didefinisikan.',
            'response' => null,
        ];
    }

    $token = trim((string) TELEGRAM_BOT_TOKEN);

    if ($token === '' || str_starts_with($token, 'GANTI_')) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Token Telegram belum diisi.',
            'response' => null,
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Ekstensi cURL PHP tidak tersedia.',
            'response' => null,
        ];
    }

    $url = 'https://api.telegram.org/bot'
        . rawurlencode($token)
        . '/sendMessage';

    $handle = curl_init($url);

    if ($handle === false) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Gagal memulai koneksi cURL.',
            'response' => null,
        ];
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => true,
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => defined('TELEGRAM_CONNECT_TIMEOUT')
            ? (int) TELEGRAM_CONNECT_TIMEOUT
            : 8,
        CURLOPT_TIMEOUT => defined('TELEGRAM_REQUEST_TIMEOUT')
            ? (int) TELEGRAM_REQUEST_TIMEOUT
            : 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $rawResponse = curl_exec($handle);
    $curlError = curl_error($handle);
    $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    $decoded = null;

    if (is_string($rawResponse) && $rawResponse !== '') {
        $parsed = json_decode($rawResponse, true);

        if (is_array($parsed)) {
            $decoded = $parsed;
        }
    }

    $apiOk = is_array($decoded) && ($decoded['ok'] ?? false) === true;

    if ($apiOk && $httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'http_code' => $httpCode,
            'error' => '',
            'response' => $decoded,
        ];
    }

    $description = is_array($decoded)
        ? trim((string) ($decoded['description'] ?? ''))
        : '';

    $error = $description !== ''
        ? $description
        : ($curlError !== '' ? $curlError : 'Telegram menolak permintaan.');

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $error,
        'response' => $decoded,
    ];
}

/** @param array<string,mixed> $device */
function buildAirStatusNotification(
    array $device,
    int $mq135Raw,
    ?string $previousStatus,
    string $airStatus
): string {
    $jakartaTime = new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Jakarta')
    );

$formattedTime = $jakartaTime->format('d-m-Y H:i:s');
    $deviceName = trim((string) ($device['device_name'] ?? 'Perangkat'));
    $deviceCode = trim((string) ($device['device_code'] ?? '-'));
    $location = trim((string) ($device['location'] ?? '-'));
    $oldStatus = $previousStatus ?? 'Belum tersedia';

    return implode("\n", [
        'PERUBAHAN STATUS KONDISI UDARA',
        '',
        'Perangkat: ' . $deviceName,
        'Kode: ' . $deviceCode,
        'Lokasi: ' . ($location !== '' ? $location : '-'),
        'Nilai ADC MQ135: ' . $mq135Raw,
        'Status sebelumnya: ' . $oldStatus,
        'Status terbaru: ' . $airStatus,
        'Waktu: ' . $formattedTime . ' WIB'
    ]);
}
