<?php

declare(strict_types=1);

/**
 * Mengambil daftar perangkat untuk pilihan filter.
 */
function getSensorDeviceOptions(PDO $database): array
{
    $statement = $database->query(
        'SELECT id, device_code, device_name
         FROM devices
         ORDER BY device_name ASC, device_code ASC'
    );

    $rows = $statement->fetchAll();

    return is_array($rows) ? $rows : [];
}

/**
 * Menyusun bagian WHERE dan parameter pencarian data sensor.
 *
 * @return array{sql: string, parameters: array<string, mixed>}
 */
function buildSensorReadingFilter(array $filters): array
{
    $conditions = [];
    $parameters = [];

    $search = trim((string) ($filters['search'] ?? ''));
    $deviceId = $filters['device_id'] ?? null;
    $airStatus = strtolower(trim((string) ($filters['air_status'] ?? '')));
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateToExclusive = trim((string) ($filters['date_to_exclusive'] ?? ''));

    if ($search !== '') {
        $conditions[] = '(
            d.device_code LIKE :search_code
            OR d.device_name LIKE :search_name
            OR d.location LIKE :search_location
        )';
        $wildcard = '%' . $search . '%';
        $parameters['search_code'] = $wildcard;
        $parameters['search_name'] = $wildcard;
        $parameters['search_location'] = $wildcard;
    }

    if (is_int($deviceId) && $deviceId > 0) {
        $conditions[] = 'sr.device_id = :device_id';
        $parameters['device_id'] = $deviceId;
    }

    if (in_array($airStatus, ['normal', 'waspada', 'bahaya'], true)) {
        $conditions[] = 'LOWER(sr.air_status) = :air_status';
        $parameters['air_status'] = $airStatus;
    }

    if ($dateFrom !== '') {
        $conditions[] = 'sr.recorded_at >= :date_from';
        $parameters['date_from'] = $dateFrom;
    }

    if ($dateToExclusive !== '') {
        $conditions[] = 'sr.recorded_at < :date_to_exclusive';
        $parameters['date_to_exclusive'] = $dateToExclusive;
    }

    return [
        'sql' => $conditions === []
            ? ''
            : 'WHERE ' . implode(' AND ', $conditions),
        'parameters' => $parameters,
    ];
}

/**
 * Mengambil ringkasan jumlah data berdasarkan filter yang aktif.
 *
 * @return array{total: int, normal: int, waspada: int, bahaya: int}
 */
function getSensorReadingSummary(PDO $database, array $filters): array
{
    $filter = buildSensorReadingFilter($filters);

    $sql = sprintf(
        'SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN sr.mq135_ppm IS NOT NULL AND LOWER(sr.air_status) = \'normal\' THEN 1 ELSE 0 END), 0) AS normal,
            COALESCE(SUM(CASE WHEN sr.mq135_ppm IS NOT NULL AND LOWER(sr.air_status) = \'waspada\' THEN 1 ELSE 0 END), 0) AS waspada,
            COALESCE(SUM(CASE WHEN sr.mq135_ppm IS NOT NULL AND LOWER(sr.air_status) = \'bahaya\' THEN 1 ELSE 0 END), 0) AS bahaya
         FROM sensor_readings sr
         INNER JOIN devices d ON d.id = sr.device_id
         %s',
        $filter['sql']
    );

    $statement = $database->prepare($sql);
    $statement->execute($filter['parameters']);
    $row = $statement->fetch();

    if (!is_array($row)) {
        return ['total' => 0, 'normal' => 0, 'waspada' => 0, 'bahaya' => 0];
    }

    return [
        'total' => (int) ($row['total'] ?? 0),
        'normal' => (int) ($row['normal'] ?? 0),
        'waspada' => (int) ($row['waspada'] ?? 0),
        'bahaya' => (int) ($row['bahaya'] ?? 0),
    ];
}

/**
 * Mengambil data sensor dengan pagination.
 */
function getSensorReadings(
    PDO $database,
    array $filters,
    int $limit,
    int $offset
): array {
    $filter = buildSensorReadingFilter($filters);

    $sql = sprintf(
        'SELECT
            sr.id,
            sr.device_id,
            sr.mq135_raw,
                sr.mq135_ppm,
            sr.air_status,
            sr.recorded_at,
            sr.created_at,
            d.device_code,
            d.device_name,
            d.location
         FROM sensor_readings sr
         INNER JOIN devices d ON d.id = sr.device_id
         %s
         ORDER BY sr.id DESC
         LIMIT :limit OFFSET :offset',
        $filter['sql']
    );

    $statement = $database->prepare($sql);

    foreach ($filter['parameters'] as $name => $value) {
        $parameterType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $statement->bindValue(':' . $name, $value, $parameterType);
    }

    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll();

    return is_array($rows) ? $rows : [];
}
