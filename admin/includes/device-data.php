<?php

declare(strict_types=1);

/**
 * Perangkat dianggap online selama last_seen masih berada dalam batas ini.
 * ESP32 mengirim data setiap 10 detik, sehingga 35 detik memberi toleransi
 * terhadap beberapa pengiriman yang terlambat.
 */
const DEVICE_ONLINE_TIMEOUT_SECONDS = 35;

function normalizeDeviceStatusFilter(string $status): string
{
    $normalized = strtolower(trim($status));

    if (in_array($normalized, ['active', 'online', 'aktif'], true)) {
        return 'online';
    }

    if (in_array($normalized, ['inactive', 'offline', 'tidak aktif'], true)) {
        return 'offline';
    }

    return '';
}

/**
 * Mengambil daftar perangkat beserta pembacaan MQ135 paling baru.
 * Status online/offline dihitung dari last_seen, bukan hanya membaca kolom status.
 */
function getDevicesWithLatestReading(
    PDO $database,
    string $search = '',
    string $status = ''
): array {
    $conditions = [];
    $parameters = [];
    $normalizedStatus = normalizeDeviceStatusFilter($status);

    if ($search !== '') {
        $conditions[] = '(
            d.device_code LIKE :search
            OR d.device_name LIKE :search
            OR d.location LIKE :search
        )';
        $parameters['search'] = '%' . $search . '%';
    }

    if ($normalizedStatus !== '') {
        $conditions[] = sprintf(
            "CASE
                WHEN d.last_seen IS NOT NULL
                 AND d.last_seen >= DATE_SUB(NOW(), INTERVAL %d SECOND)
                    THEN 'online'
                ELSE 'offline'
             END = :computed_status",
            DEVICE_ONLINE_TIMEOUT_SECONDS
        );
        $parameters['computed_status'] = $normalizedStatus;
    }

    $whereClause = $conditions === []
        ? ''
        : 'WHERE ' . implode(' AND ', $conditions);

    $sql = sprintf(
        "SELECT
            d.id,
            d.device_code,
            d.device_name,
            d.location,
            d.status AS stored_status,
            CASE
                WHEN d.last_seen IS NOT NULL
                 AND d.last_seen >= DATE_SUB(NOW(), INTERVAL %d SECOND)
                    THEN 'online'
                ELSE 'offline'
            END AS status,
            d.last_seen,
            sr.mq135_raw,
            sr.air_status,
            sr.recorded_at,
            sr.created_at
         FROM devices d
         LEFT JOIN sensor_readings sr
            ON sr.id = (
                SELECT sr2.id
                FROM sensor_readings sr2
                WHERE sr2.device_id = d.id
                ORDER BY sr2.id DESC
                LIMIT 1
            )
         %s
         ORDER BY d.device_name ASC, d.id ASC",
        DEVICE_ONLINE_TIMEOUT_SECONDS,
        $whereClause
    );

    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    $rows = $statement->fetchAll();

    return is_array($rows) ? $rows : [];
}

/**
 * Mengambil satu perangkat berdasarkan ID.
 */
function findDeviceById(PDO $database, int $deviceId): ?array
{
    $statement = $database->prepare(
        'SELECT
            id,
            device_code,
            device_name,
            location,
            status,
            last_seen
         FROM devices
         WHERE id = :id
         LIMIT 1'
    );

    $statement->execute(['id' => $deviceId]);
    $device = $statement->fetch();

    return is_array($device) ? $device : null;
}

/**
 * Memperbarui nama dan lokasi perangkat.
 */
function updateDeviceInformation(
    PDO $database,
    int $deviceId,
    string $deviceName,
    string $location
): bool {
    $statement = $database->prepare(
        'UPDATE devices
         SET device_name = :device_name,
             location = :location
         WHERE id = :id'
    );

    return $statement->execute([
        'device_name' => $deviceName,
        'location' => $location,
        'id' => $deviceId,
    ]);
}
