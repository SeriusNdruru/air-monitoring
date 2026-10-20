<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../config/database.php';

/*
 * Waktu pada database proyek disimpan sebagai UTC.
 * Semua waktu yang dikirim ke dashboard pelanggan dikonversi ke WIB.
 */
const DATABASE_TIMEZONE = 'Asia/Jakarta';
const DISPLAY_TIMEZONE = 'Asia/Jakarta';
const DEVICE_ONLINE_TIMEOUT_SECONDS = 35;

/**
 * Mengubah waktu UTC dari database menjadi DateTimeImmutable.
 */
function parseDatabaseUtc(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $value,
        new DateTimeZone(DATABASE_TIMEZONE)
    );

    return $date instanceof DateTimeImmutable ? $date : null;
}

/**
 * Menyusun data waktu untuk respons API.
 *
 * @return array{
 *     raw:?string,
 *     display:string,
 *     time:string,
 *     iso:?string
 * }
 */
function formatTimestampForDashboard(?string $value): array
{
    $utcDate = parseDatabaseUtc($value);

    if ($utcDate === null) {
        return [
            'raw' => $value,
            'display' => '-',
            'time' => '-',
            'iso' => null,
        ];
    }

    $localDate = $utcDate->setTimezone(
        new DateTimeZone(DISPLAY_TIMEZONE)
    );

    return [
        'raw' => $value,
        'display' => $localDate->format('d M Y H:i:s') . ' WIB',
        'time' => $localDate->format('H:i:s'),
        'iso' => $localDate->format(DateTimeInterface::ATOM),
    ];
}

try {
    $pdo = db();

    /*
     * ID terbesar digunakan sebagai data terbaru.
     * created_at dipakai sebagai cadangan jika recorded_at kosong.
     */
    $statement = $pdo->query("
        SELECT
            sr.id,
            sr.mq135_raw,
            sr.mq135_ppm,
            sr.air_status,
            COALESCE(sr.recorded_at, sr.created_at) AS recorded_at,

            d.id AS device_id,
            d.device_code,
            d.device_name,
            d.location,
            d.status AS stored_device_status,
            d.last_seen

        FROM sensor_readings AS sr

        INNER JOIN devices AS d
            ON d.id = sr.device_id

        ORDER BY sr.id DESC
        LIMIT 1
    ");

    $data = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(
            [
                'success' => true,
                'data' => null,
                'online_timeout_seconds' =>
                    DEVICE_ONLINE_TIMEOUT_SECONDS,
            ],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    $nowUtc = new DateTimeImmutable(
        'now',
        new DateTimeZone(DATABASE_TIMEZONE)
    );

    $lastSeenUtc = parseDatabaseUtc(
        isset($data['last_seen'])
            ? (string) $data['last_seen']
            : null
    );

    $secondsSinceLastSeen = null;
    $deviceStatus = 'offline';

    if ($lastSeenUtc !== null) {
        $secondsSinceLastSeen =
            $nowUtc->getTimestamp() - $lastSeenUtc->getTimestamp();

        /*
         * Selisih negatif kecil dapat terjadi karena perbedaan jam server.
         * Dalam kondisi tersebut perangkat tetap dianggap baru mengirim data.
         */
        $secondsSinceLastSeen = max(0, $secondsSinceLastSeen);

        if (
            $secondsSinceLastSeen
            <= DEVICE_ONLINE_TIMEOUT_SECONDS
        ) {
            $deviceStatus = 'online';
        }
    }

    /*
     * Menyamakan status tersimpan dengan status hasil perhitungan.
     * Dashboard tetap memakai hasil perhitungan, jadi perubahan langsung terlihat.
     */
    if (
        strtolower((string) $data['stored_device_status'])
        !== $deviceStatus
    ) {
        $updateStatement = $pdo->prepare("
            UPDATE devices
            SET status = :status
            WHERE id = :device_id
        ");

        $updateStatement->execute([
            'status' => $deviceStatus,
            'device_id' => (int) $data['device_id'],
        ]);
    }

    $recordedAt = formatTimestampForDashboard(
        isset($data['recorded_at'])
            ? (string) $data['recorded_at']
            : null
    );

    $lastSeen = formatTimestampForDashboard(
        isset($data['last_seen'])
            ? (string) $data['last_seen']
            : null
    );

    $responseData = [
        'id' => (int) $data['id'],
        'mq135_raw' => (int) $data['mq135_raw'],
        'mq135_ppm' => $data['mq135_ppm'] === null ? null : (float) $data['mq135_ppm'],
        'unit' => 'ppm',
        'classification_basis' => 'internal - bukan ISPU',
        'air_status' => (string) $data['air_status'],

        'device_code' => (string) $data['device_code'],
        'device_name' => (string) $data['device_name'],
        'location' => (string) $data['location'],
        'device_status' => $deviceStatus,

        /*
         * Field lama dipertahankan agar tetap kompatibel.
         */
        'recorded_at' => $recordedAt['raw'],
        'last_seen' => $lastSeen['raw'],

        /*
         * Field baru khusus tampilan dashboard.
         */
        'recorded_at_display' => $recordedAt['display'],
        'recorded_time_display' => $recordedAt['time'],
        'recorded_at_iso' => $recordedAt['iso'],

        'last_seen_display' => $lastSeen['display'],
        'last_seen_iso' => $lastSeen['iso'],

        'seconds_since_last_seen' => $secondsSinceLastSeen,
        'online_timeout_seconds' =>
            DEVICE_ONLINE_TIMEOUT_SECONDS,
    ];

    echo json_encode(
        [
            'success' => true,
            'data' => $responseData,
            'server_time_wib' => (
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone(DISPLAY_TIMEZONE)
                )
            )->format('d M Y H:i:s') . ' WIB',
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $exception) {
    error_log(
        'latest.php: ' . $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Data terbaru gagal diambil.',
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
}
