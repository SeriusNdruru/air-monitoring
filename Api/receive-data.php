<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi error
|--------------------------------------------------------------------------
| Detail error tidak ditampilkan kepada pengguna.
| Error tetap dicatat pada log server.
*/
error_reporting(E_ALL);
ini_set('display_errors', '0');

/*
|--------------------------------------------------------------------------
| Zona waktu PHP
|--------------------------------------------------------------------------
*/
date_default_timezone_set('Asia/Jakarta');

/*
|--------------------------------------------------------------------------
| Header respons
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=utf-8');
header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('Expires: 0');

/*
|--------------------------------------------------------------------------
| Memuat konfigurasi
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/telegram-helper.php';

/*
|--------------------------------------------------------------------------
| Konfigurasi API
|--------------------------------------------------------------------------
| Gunakan API key baru karena API key lama sudah pernah terlihat.
| Nilai ini harus sama dengan API key pada program ESP32.
*/
const DEVICE_API_KEY = 'AQM-ESP32-2026-SECRET';

/*
|--------------------------------------------------------------------------
| Batas ukuran permintaan
|--------------------------------------------------------------------------
| Data ESP32 sangat kecil. Batas ini mencegah permintaan berukuran
| terlalu besar diproses oleh server.
*/
const MAX_REQUEST_SIZE = 4096;

/**
 * Mengirim respons JSON dan menghentikan program.
 */
function sendJson(int $statusCode, array $response): void
{
    http_response_code($statusCode);

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Validasi metode HTTP
|--------------------------------------------------------------------------
*/
$requestMethod = strtoupper(
    (string) ($_SERVER['REQUEST_METHOD'] ?? '')
);

if ($requestMethod !== 'POST') {
    header('Allow: POST');

    sendJson(405, [
        'success' => false,
        'message' => 'Metode yang diizinkan hanya POST.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Validasi API key
|--------------------------------------------------------------------------
| ESP32 mengirim API key melalui header:
| X-API-KEY: API_KEY
*/
$receivedApiKey = trim(
    (string) ($_SERVER['HTTP_X_API_KEY'] ?? '')
);

if (
    $receivedApiKey === '' ||
    !hash_equals(DEVICE_API_KEY, $receivedApiKey)
) {
    sendJson(401, [
        'success' => false,
        'message' => 'API key tidak valid.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Membaca isi permintaan
|--------------------------------------------------------------------------
*/
$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    sendJson(400, [
        'success' => false,
        'message' => 'Data permintaan tidak ditemukan.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Membatasi ukuran permintaan
|--------------------------------------------------------------------------
*/
if (strlen($rawBody) > MAX_REQUEST_SIZE) {
    sendJson(413, [
        'success' => false,
        'message' => 'Ukuran data permintaan terlalu besar.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Mengubah JSON menjadi array PHP
|--------------------------------------------------------------------------
*/
$data = json_decode($rawBody, true);

if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($data)
) {
    sendJson(400, [
        'success' => false,
        'message' => 'Format JSON tidak valid.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Mengambil data perangkat dan sensor
|--------------------------------------------------------------------------
*/
$deviceCode = trim(
    (string) ($data['device_code'] ?? '')
);

$mq135Raw = filter_var(
    $data['mq135_raw'] ?? null,
    FILTER_VALIDATE_INT
);

/*
|--------------------------------------------------------------------------
| Validasi kode perangkat
|--------------------------------------------------------------------------
*/
if ($deviceCode === '') {
    sendJson(422, [
        'success' => false,
        'message' => 'device_code wajib diisi.'
    ]);
}

if (strlen($deviceCode) > 50) {
    sendJson(422, [
        'success' => false,
        'message' => 'device_code maksimal 50 karakter.'
    ]);
}

if (!preg_match('/^[A-Za-z0-9_-]+$/', $deviceCode)) {
    sendJson(422, [
        'success' => false,
        'message' =>
            'device_code hanya boleh berisi huruf, angka, garis bawah, dan tanda hubung.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Validasi nilai ADC MQ135
|--------------------------------------------------------------------------
| ESP32 menggunakan ADC 12-bit dengan rentang 0 sampai 4095.
*/
if (
    $mq135Raw === false ||
    $mq135Raw < 0 ||
    $mq135Raw > 4095
) {
    sendJson(422, [
        'success' => false,
        'message' =>
            'mq135_raw harus berupa bilangan bulat antara 0 dan 4095.'
    ]);
}

try {
    /*
    |--------------------------------------------------------------------------
    | Membuka koneksi database
    |--------------------------------------------------------------------------
    */
    $pdo = db();

    /*
    |--------------------------------------------------------------------------
    | Menetapkan zona waktu sesi MySQL
    |--------------------------------------------------------------------------
    | Ini membantu menyamakan NOW(), recorded_at, dan last_seen
    | dengan waktu Indonesia bagian barat.
    */
    $pdo->exec("SET time_zone = '+07:00'");

    /*
    |--------------------------------------------------------------------------
    | Mengambil konfigurasi ambang batas
    |--------------------------------------------------------------------------
    */
    $settingsStatement = $pdo->query("
        SELECT
            setting_key,
            setting_value
        FROM system_settings
        WHERE setting_key IN (
            'warning_threshold',
            'danger_threshold'
        )
    ");

    $settings = [];

    foreach (
        $settingsStatement->fetchAll(PDO::FETCH_ASSOC)
        as $setting
    ) {
        $settingKey = (string) $setting['setting_key'];
        $settingValue = (int) $setting['setting_value'];

        $settings[$settingKey] = $settingValue;
    }

    /*
    |--------------------------------------------------------------------------
    | Nilai bawaan ambang batas
    |--------------------------------------------------------------------------
    */
    $warningThreshold =
        $settings['warning_threshold'] ?? 1500;

    $dangerThreshold =
        $settings['danger_threshold'] ?? 2500;

    /*
    |--------------------------------------------------------------------------
    | Validasi konfigurasi ambang batas
    |--------------------------------------------------------------------------
    */
    if (
        $warningThreshold < 0 ||
        $warningThreshold > 4095 ||
        $dangerThreshold < 0 ||
        $dangerThreshold > 4095 ||
        $warningThreshold >= $dangerThreshold
    ) {
        throw new RuntimeException(
            'Konfigurasi ambang batas tidak valid.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Menentukan status kualitas udara
    |--------------------------------------------------------------------------
    */
    if ($mq135Raw >= $dangerThreshold) {
        $airStatus = 'Bahaya';
    } elseif ($mq135Raw >= $warningThreshold) {
        $airStatus = 'Waspada';
    } else {
        $airStatus = 'Normal';
    }

    /*
    |--------------------------------------------------------------------------
    | Memeriksa perangkat berdasarkan device_code
    |--------------------------------------------------------------------------
    */
    $deviceStatement = $pdo->prepare("
        SELECT
            id,
            device_code,
            device_name,
            location
        FROM devices
        WHERE device_code = :device_code
        LIMIT 1
    ");

    $deviceStatement->execute([
        'device_code' => $deviceCode
    ]);

    $device = $deviceStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$device) {
        sendJson(404, [
            'success' => false,
            'message' => 'Perangkat tidak terdaftar.'
        ]);
    }

    $deviceId = (int) $device['id'];

    /*
    |--------------------------------------------------------------------------
    | Mengambil status pembacaan sebelumnya
    |--------------------------------------------------------------------------
    | ID terbesar digunakan sebagai data terakhir yang benar-benar
    | dimasukkan ke database.
    */
    $previousStatusStatement = $pdo->prepare("
        SELECT
            air_status
        FROM sensor_readings
        WHERE device_id = :device_id
        ORDER BY id DESC
        LIMIT 1
    ");

    $previousStatusStatement->execute([
        'device_id' => $deviceId
    ]);

    $previousReading =
        $previousStatusStatement->fetch(
            PDO::FETCH_ASSOC
        );

    $previousStatus = $previousReading
        ? (string) $previousReading['air_status']
        : null;

    /*
    |--------------------------------------------------------------------------
    | Memulai transaksi database
    |--------------------------------------------------------------------------
    */
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Menyimpan pembacaan MQ135
    |--------------------------------------------------------------------------
    */
    $insertStatement = $pdo->prepare("
        INSERT INTO sensor_readings (
            device_id,
            mq135_raw,
            air_status,
            recorded_at
        ) VALUES (
            :device_id,
            :mq135_raw,
            :air_status,
            NOW()
        )
    ");

    $insertStatement->execute([
        'device_id' => $deviceId,
        'mq135_raw' => $mq135Raw,
        'air_status' => $airStatus
    ]);

    $readingId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Memperbarui status dan waktu terakhir perangkat
    |--------------------------------------------------------------------------
    */
    $updateDeviceStatement = $pdo->prepare("
        UPDATE devices
        SET
            status = 'online',
            last_seen = NOW()
        WHERE id = :device_id
    ");

    $updateDeviceStatement->execute([
        'device_id' => $deviceId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Menyimpan transaksi
    |--------------------------------------------------------------------------
    */
    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Menentukan apakah Telegram perlu dikirim
    |--------------------------------------------------------------------------
    | Telegram dikirim ketika:
    | 1. Status berubah.
    | 2. Data pertama langsung berstatus Waspada atau Bahaya.
    */
    $statusChanged =
        $previousStatus !== null &&
        $previousStatus !== $airStatus;

    $firstWarningOrDanger =
        $previousStatus === null &&
        in_array(
            $airStatus,
            ['Waspada', 'Bahaya'],
            true
        );

    $telegramSent = false;

    /*
    |--------------------------------------------------------------------------
    | Mengirim notifikasi Telegram
    |--------------------------------------------------------------------------
    | Kegagalan Telegram tidak membatalkan data yang sudah tersimpan.
    */
    if ($statusChanged || $firstWarningOrDanger) {
        $deviceName = trim(
            (string) ($device['device_name'] ?? '')
        );

        $deviceLocation = trim(
            (string) ($device['location'] ?? '')
        );

        $deviceLabel = $deviceName !== ''
            ? "{$deviceName} ({$deviceCode})"
            : $deviceCode;

        $currentTime = new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Jakarta')
        );

        $message =
            "NOTIFIKASI KUALITAS UDARA\n\n"
            . "Perangkat: {$deviceLabel}\n"
            . "Lokasi: "
            . ($deviceLocation !== ''
                ? $deviceLocation
                : '-')
            . "\n"
            . "Nilai MQ135: {$mq135Raw}\n"
            . "Status sebelumnya: "
            . ($previousStatus ?? '-')
            . "\n"
            . "Status sekarang: {$airStatus}\n"
            . "Waktu: "
            . $currentTime->format('d-m-Y H:i:s')
            . " WIB";

        try {
            $telegramResult =
                sendTelegramMessage($message);

            $telegramSent = (bool) (
                $telegramResult['success'] ?? false
            );

            if (!$telegramSent) {
                $telegramError =
                    $telegramResult['error']
                    ?? $telegramResult['response']['description']
                    ?? 'Telegram gagal mengirim pesan.';

                error_log(
                    '[receive-data.php] Telegram gagal: '
                    . (string) $telegramError
                );
            }
        } catch (Throwable $telegramException) {
            /*
             * Kesalahan Telegram hanya dicatat.
             * Data sensor tetap dianggap berhasil disimpan.
             */
            error_log(
                '[receive-data.php] Kesalahan Telegram: '
                . $telegramException->getMessage()
            );

            $telegramSent = false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Respons berhasil
    |--------------------------------------------------------------------------
    */
    sendJson(201, [
        'success' => true,
        'message' => 'Data sensor berhasil disimpan.',
        'data' => [
            'reading_id' => $readingId,
            'device_code' => $deviceCode,
            'mq135_raw' => $mq135Raw,
            'previous_status' => $previousStatus,
            'air_status' => $airStatus,
            'warning_threshold' => $warningThreshold,
            'danger_threshold' => $dangerThreshold,
            'telegram_sent' => $telegramSent
        ]
    ]);
} catch (Throwable $exception) {
    /*
    |--------------------------------------------------------------------------
    | Membatalkan transaksi jika masih aktif
    |--------------------------------------------------------------------------
    */
    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    /*
    |--------------------------------------------------------------------------
    | Mencatat detail kesalahan pada log server
    |--------------------------------------------------------------------------
    */
    error_log(
        sprintf(
            '[receive-data.php] %s pada %s:%d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    /*
    |--------------------------------------------------------------------------
    | Respons kesalahan
    |--------------------------------------------------------------------------
    */
    sendJson(500, [
        'success' => false,
        'message' => 'Data sensor gagal disimpan.'
    ]);
}