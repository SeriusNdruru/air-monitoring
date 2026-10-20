<?php

declare(strict_types=1);

const DEVICE_ONLINE_TIMEOUT_SECONDS = 35;

function tableExists(PDO $database, string $tableName): bool
{
    $statement = $database->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );

    $statement->execute(['table_name' => $tableName]);

    return (int) $statement->fetchColumn() > 0;
}

function columnExists(PDO $database, string $tableName, string $columnName): bool
{
    $statement = $database->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );

    $statement->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function safeScalar(
    PDO $database,
    string $sql,
    array $parameters = [],
    int|string|null $fallback = 0
): int|string|null {
    try {
        $statement = $database->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();

        return $value === false ? $fallback : $value;
    } catch (Throwable $exception) {
        error_log('Dashboard scalar query error: ' . $exception->getMessage());
        return $fallback;
    }
}

function safeRows(PDO $database, string $sql, array $parameters = []): array
{
    try {
        $statement = $database->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    } catch (Throwable $exception) {
        error_log('Dashboard rows query error: ' . $exception->getMessage());
        return [];
    }
}

/**
 * Mengambil tepat 30 data terakhir berdasarkan urutan ID penyimpanan,
 * lalu mengurutkannya kembali dari data terlama ke terbaru untuk grafik.
 */
function getLatestChartReadings(PDO $database, int $limit = 30): array
{
    $safeLimit = max(2, min(100, $limit));

    return safeRows(
        $database,
        sprintf(
            'SELECT
                recent.id,
                recent.mq135_raw,
                recent.air_status,
                recent.recorded_at,
                recent.created_at
             FROM (
                 SELECT
                    sr.id,
                    sr.mq135_raw,
                sr.mq135_ppm,
                    sr.air_status,
                    sr.recorded_at,
                    sr.created_at
                 FROM sensor_readings sr
                 ORDER BY sr.id DESC
                 LIMIT %d
             ) AS recent
             ORDER BY recent.id ASC',
            $safeLimit
        )
    );
}

function getNotificationCountToday(PDO $database): int
{
    if (!tableExists($database, 'notification_logs')) {
        return 0;
    }

    foreach (['created_at', 'sent_at', 'notified_at', 'recorded_at'] as $timeColumn) {
        if (columnExists($database, 'notification_logs', $timeColumn)) {
            return (int) safeScalar(
                $database,
                sprintf(
                    'SELECT COUNT(*)
                     FROM notification_logs
                     WHERE DATE(%s) = CURDATE()',
                    $timeColumn
                ),
                [],
                0
            );
        }
    }

    return 0;
}

function getDashboardData(PDO $database): array
{
    $totalDevices = (int) safeScalar(
        $database,
        'SELECT COUNT(*) FROM devices',
        [],
        0
    );

    $activeDevices = (int) safeScalar(
        $database,
        sprintf(
            'SELECT COUNT(*)
             FROM devices
             WHERE last_seen IS NOT NULL
               AND last_seen >= DATE_SUB(NOW(), INTERVAL %d SECOND)',
            DEVICE_ONLINE_TIMEOUT_SECONDS
        ),
        [],
        0
    );

    $latestReadingRows = safeRows(
        $database,
        sprintf(
            "SELECT
                sr.id,
                sr.mq135_raw,
                sr.mq135_ppm,
                sr.air_status,
                sr.recorded_at,
                sr.created_at,
                d.device_code,
                d.device_name,
                d.location,
                CASE
                    WHEN d.last_seen IS NOT NULL
                     AND d.last_seen >= DATE_SUB(NOW(), INTERVAL %d SECOND)
                        THEN 'online'
                    ELSE 'offline'
                END AS device_status
             FROM sensor_readings sr
             LEFT JOIN devices d ON d.id = sr.device_id
             ORDER BY sr.id DESC
             LIMIT 1",
            DEVICE_ONLINE_TIMEOUT_SECONDS
        )
    );

    $latestReading = $latestReadingRows[0] ?? null;

    $recentReadings = safeRows(
        $database,
        'SELECT
            sr.id,
            sr.mq135_raw,
                sr.mq135_ppm,
            sr.air_status,
            sr.recorded_at,
            sr.created_at,
            d.device_code,
            d.device_name,
            d.location
         FROM sensor_readings sr
         LEFT JOIN devices d ON d.id = sr.device_id
         ORDER BY sr.id DESC
         LIMIT 10'
    );

    $chartReadings = getLatestChartReadings($database, 30);

    $devices = safeRows(
        $database,
        sprintf(
            "SELECT
                id,
                device_code,
                device_name,
                location,
                status AS stored_status,
                CASE
                    WHEN last_seen IS NOT NULL
                     AND last_seen >= DATE_SUB(NOW(), INTERVAL %d SECOND)
                        THEN 'online'
                    ELSE 'offline'
                END AS status,
                last_seen
             FROM devices
             ORDER BY last_seen DESC, id DESC
             LIMIT 8",
            DEVICE_ONLINE_TIMEOUT_SECONDS
        )
    );

    $notificationCountToday = getNotificationCountToday($database);

    $systemStatus = 'Tidak Aktif';

    if ($totalDevices > 0 && $activeDevices === $totalDevices) {
        $systemStatus = 'Normal';
    } elseif ($activeDevices > 0) {
        $systemStatus = 'Perlu Dicek';
    }

    return [
        'total_devices' => $totalDevices,
        'active_devices' => $activeDevices,
        'notification_count_today' => $notificationCountToday,
        'system_status' => $systemStatus,
        'latest_reading' => $latestReading,
        'recent_readings' => $recentReadings,
        'chart_readings' => $chartReadings,
        'devices' => $devices,
    ];
}
