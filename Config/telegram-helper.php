<?php

declare(strict_types=1);

require_once __DIR__ . '/telegram.php';

/**
 * Mengirim pesan teks melalui Telegram Bot API.
 *
 * @return array{
 *     success: bool,
 *     http_code: int,
 *     response: array<string, mixed>|null,
 *     error: string|null
 * }
 */
function sendTelegramMessage(string $message): array
{
    /*
    |--------------------------------------------------------------------------
    | Nilai hasil awal
    |--------------------------------------------------------------------------
    */
    $defaultResult = [
        'success' => false,
        'http_code' => 0,
        'response' => null,
        'error' => null
    ];

    /*
    |--------------------------------------------------------------------------
    | Memeriksa ekstensi cURL
    |--------------------------------------------------------------------------
    */
    if (!function_exists('curl_init')) {
        return array_merge($defaultResult, [
            'error' => 'Ekstensi cURL PHP belum aktif.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Memeriksa konfigurasi Telegram
    |--------------------------------------------------------------------------
    */
    if (
        !defined('TELEGRAM_BOT_TOKEN') ||
        !defined('TELEGRAM_CHAT_ID')
    ) {
        return array_merge($defaultResult, [
            'error' =>
                'Konstanta konfigurasi Telegram belum tersedia.'
        ]);
    }

    $botToken = trim(
        (string) TELEGRAM_BOT_TOKEN
    );

    $chatId = trim(
        (string) TELEGRAM_CHAT_ID
    );

    if ($botToken === '' || $chatId === '') {
        return array_merge($defaultResult, [
            'error' =>
                'Token bot atau Chat ID Telegram belum diisi.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Memeriksa isi pesan
    |--------------------------------------------------------------------------
    */
    $message = trim($message);

    if ($message === '') {
        return array_merge($defaultResult, [
            'error' => 'Pesan Telegram tidak boleh kosong.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Membatasi panjang pesan
    |--------------------------------------------------------------------------
    */
    $messageLength = function_exists('mb_strlen')
        ? mb_strlen($message, 'UTF-8')
        : strlen($message);

    if ($messageLength > 4096) {
        return array_merge($defaultResult, [
            'error' => 'Pesan Telegram terlalu panjang.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Membentuk alamat Telegram Bot API
    |--------------------------------------------------------------------------
    */
    $url = sprintf(
        'https://api.telegram.org/bot%s/sendMessage',
        rawurlencode($botToken)
    );

    /*
    |--------------------------------------------------------------------------
    | Data yang dikirim ke Telegram
    |--------------------------------------------------------------------------
    */
    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'disable_web_page_preview' => 'true'
    ];

    /*
    |--------------------------------------------------------------------------
    | Membuat koneksi cURL
    |--------------------------------------------------------------------------
    */
    $curl = curl_init();

    if ($curl === false) {
        return array_merge($defaultResult, [
            'error' => 'Gagal membuat koneksi cURL.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Konfigurasi cURL
    |--------------------------------------------------------------------------
    */
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS =>
            http_build_query(
                $postData,
                '',
                '&',
                PHP_QUERY_RFC3986
            ),

        CURLOPT_RETURNTRANSFER => true,

        /*
         * Waktu maksimum untuk membuat koneksi.
         */
        CURLOPT_CONNECTTIMEOUT => 10,

        /*
         * Waktu maksimum keseluruhan permintaan.
         */
        CURLOPT_TIMEOUT => 20,

        /*
         * Verifikasi sertifikat HTTPS Telegram.
         */
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        /*
         * Menggunakan HTTP/1.1 agar kompatibel dengan
         * sebagian besar layanan shared hosting.
         */
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]
    ]);

    /*
    |--------------------------------------------------------------------------
    | Mengirim permintaan
    |--------------------------------------------------------------------------
    */
    $rawResponse = curl_exec($curl);

    if ($rawResponse === false) {
        $curlErrorNumber = curl_errno($curl);
        $curlErrorMessage = curl_error($curl);

        curl_close($curl);

        return array_merge($defaultResult, [
            'error' => sprintf(
                'Koneksi Telegram gagal. cURL %d: %s',
                $curlErrorNumber,
                $curlErrorMessage
            )
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Mengambil kode status HTTP
    |--------------------------------------------------------------------------
    */
    $httpCode = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close($curl);

    /*
    |--------------------------------------------------------------------------
    | Mengubah respons JSON menjadi array
    |--------------------------------------------------------------------------
    */
    $response = json_decode(
        (string) $rawResponse,
        true
    );

    if (
        json_last_error() !== JSON_ERROR_NONE ||
        !is_array($response)
    ) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'response' => null,
            'error' =>
                'Respons Telegram bukan JSON yang valid.'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Memeriksa hasil Telegram
    |--------------------------------------------------------------------------
    */
    $telegramSuccess =
        ($response['ok'] ?? false) === true;

    $httpSuccess =
        $httpCode >= 200 &&
        $httpCode < 300;

    if (!$httpSuccess || !$telegramSuccess) {
        $telegramDescription = trim(
            (string) (
                $response['description']
                ?? 'Telegram menolak permintaan.'
            )
        );

        return [
            'success' => false,
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $telegramDescription
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Respons berhasil
    |--------------------------------------------------------------------------
    */
    return [
        'success' => true,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => null
    ];
}