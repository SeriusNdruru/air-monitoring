<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mencegah akses langsung
|--------------------------------------------------------------------------
| File ini hanya boleh dimuat oleh file PHP lain menggunakan require_once.
*/
$currentScript = realpath(
    (string) ($_SERVER['SCRIPT_FILENAME'] ?? '')
);

$currentFile = realpath(__FILE__);

if (
    $currentScript !== false &&
    $currentFile !== false &&
    $currentScript === $currentFile
) {
    http_response_code(404);
    exit;
}

/*
|--------------------------------------------------------------------------
| Konfigurasi Telegram
|--------------------------------------------------------------------------
| Token bot harus diganti karena token sebelumnya sudah terekspos.
| Jangan membagikan token bot, API key, atau password database.
*/
const TELEGRAM_BOT_TOKEN = '8850998083:AAHfNulU6Gqo96y1kXPuSabubtTLXSIiSz8';
const TELEGRAM_CHAT_ID = '8252493585';