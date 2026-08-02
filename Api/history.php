<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../config/database.php';

const DATABASE_TIMEZONE = 'UTC';
const DISPLAY_TIMEZONE = 'Asia/Jakarta';

/**
 * Mengubah waktu UTC dari database menjadi data waktu WIB.
 *
 * @return array{display:string,time:string,iso:?string}
 */
function formatHistoryTimestamp(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return [
            'display' => '-',
            'time' => '-',
            'iso' => null,
        ];
    }

    $utcDate = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $value,
        new DateTimeZone(DATABASE_TIMEZONE)
    );

    if (!$utcDate instanceof DateTimeImmutable) {
        return [
            'display' => $value,
            'time' => $value,
            'iso' => null,
        ];
    }

    $localDate = $utcDate->setTimezone(
        new DateTimeZone(DISPLAY_TIMEZONE)
    );

    return [
        'display' => $localDate->format('d M Y H:i:s') . ' WIB',
        'time' => $localDate->format('H:i:s'),
        'iso' => $localDate->format(DateTimeInterface::ATOM),
    ];
}

try {
    $limit = filter_input(
        INPUT_GET,
        'limit',
        FILTER_VALIDATE_INT
    );

    if ($limit === false || $limit === null) {
        $limit = 30;
    }

    $limit = max(1, min($limit, 200));

    $sql = "
        SELECT
            sr.id,
            sr.mq135_raw,
            sr.air_status,
            COALESCE(sr.recorded_at, sr.created_at) AS recorded_at,
            d.device_code,
            d.device_name,
            d.location
        FROM sensor_readings AS sr
        INNER JOIN devices AS d
            ON d.id = sr.device_id
        ORDER BY sr.id DESC
        LIMIT :limit
    ";

    $statement = db()->prepare($sql);

    $statement->bindValue(
        ':limit',
        $limit,
        PDO::PARAM_INT
    );

    $statement->execute();

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $data = [];

    foreach ($rows as $row) {
        $timestamp = formatHistoryTimestamp(
            isset($row['recorded_at'])
                ? (string) $row['recorded_at']
                : null
        );

        $data[] = [
            'id' => (int) $row['id'],
            'mq135_raw' => (int) $row['mq135_raw'],
            'air_status' => (string) $row['air_status'],
            'device_code' => (string) $row['device_code'],
            'device_name' => (string) $row['device_name'],
            'location' => (string) $row['location'],

            /*
             * Field lama dipertahankan agar kompatibel.
             */
            'recorded_at' => (string) $row['recorded_at'],

            /*
             * Field tampilan WIB.
             */
            'recorded_at_display' => $timestamp['display'],
            'recorded_time_display' => $timestamp['time'],
            'recorded_at_iso' => $timestamp['iso'],
        ];
    }

    echo json_encode(
        [
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $exception) {
    error_log(
        'history.php: ' . $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Riwayat data gagal diambil.',
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
}
