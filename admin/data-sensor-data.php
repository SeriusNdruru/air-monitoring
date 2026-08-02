<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sensor-data.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Sesi admin berakhir. Silakan login kembali.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

const SENSOR_API_DATABASE_TIMEZONE = 'UTC';
const SENSOR_API_DISPLAY_TIMEZONE = 'Asia/Jakarta';
const SENSOR_API_PER_PAGE = 25;

function sensorApiValidDate(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date instanceof DateTimeImmutable
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function sensorApiDateBoundary(string $date, bool $nextDay = false): string
{
    if ($date === '' || !sensorApiValidDate($date)) {
        return '';
    }

    $localDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone(SENSOR_API_DISPLAY_TIMEZONE)
    );

    if (!$localDate instanceof DateTimeImmutable) {
        return '';
    }

    if ($nextDay) {
        $localDate = $localDate->modify('+1 day');
    }

    return $localDate
        ->setTimezone(new DateTimeZone(SENSOR_API_DATABASE_TIMEZONE))
        ->format('Y-m-d H:i:s');
}

function sensorApiFormatDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable(
            trim($value),
            new DateTimeZone(SENSOR_API_DATABASE_TIMEZONE)
        ))
            ->setTimezone(new DateTimeZone(SENSOR_API_DISPLAY_TIMEZONE))
            ->format('d M Y H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

try {
    $search = trim((string) ($_GET['q'] ?? ''));
    $deviceIdInput = trim((string) ($_GET['device_id'] ?? ''));
    $airStatus = strtolower(trim((string) ($_GET['air_status'] ?? '')));
    $dateFromInput = trim((string) ($_GET['date_from'] ?? ''));
    $dateToInput = trim((string) ($_GET['date_to'] ?? ''));

    $pageInput = filter_var(
        $_GET['page'] ?? 1,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $page = $pageInput === false ? 1 : (int) $pageInput;

    $deviceId = null;
    if ($deviceIdInput !== '') {
        $validatedDeviceId = filter_var(
            $deviceIdInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($validatedDeviceId !== false) {
            $deviceId = (int) $validatedDeviceId;
        }
    }

    if (!in_array($airStatus, ['', 'normal', 'waspada', 'bahaya'], true)) {
        $airStatus = '';
    }

    if (!sensorApiValidDate($dateFromInput)) {
        $dateFromInput = '';
    }

    if (!sensorApiValidDate($dateToInput)) {
        $dateToInput = '';
    }

    if (
        $dateFromInput !== ''
        && $dateToInput !== ''
        && $dateFromInput > $dateToInput
    ) {
        $dateFromInput = '';
        $dateToInput = '';
    }

    $filters = [
        'search' => $search,
        'device_id' => $deviceId,
        'air_status' => $airStatus,
        'date_from' => sensorApiDateBoundary($dateFromInput),
        'date_to_exclusive' => sensorApiDateBoundary($dateToInput, true),
    ];

    $database = getDatabaseConnection();
    $summary = getSensorReadingSummary($database, $filters);

    $totalPages = max(
        1,
        (int) ceil(((int) $summary['total']) / SENSOR_API_PER_PAGE)
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * SENSOR_API_PER_PAGE;
    $rows = getSensorReadings(
        $database,
        $filters,
        SENSOR_API_PER_PAGE,
        $offset
    );

    $readings = array_map(
        static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'device_code' => (string) ($row['device_code'] ?? '-'),
                'device_name' => (string) ($row['device_name'] ?? '-'),
                'location' => (string) ($row['location'] ?? '-'),
                'mq135_raw' => max(0, min(4095, (int) ($row['mq135_raw'] ?? 0))),
                'air_status' => (string) ($row['air_status'] ?? '-'),
                'recorded_at_label' => sensorApiFormatDateTime(
                    (string) ($row['recorded_at'] ?? '')
                ),
                'created_at_label' => sensorApiFormatDateTime(
                    (string) ($row['created_at'] ?? '')
                ),
            ];
        },
        $rows
    );

    $total = (int) ($summary['total'] ?? 0);
    $startRecord = $total === 0 ? 0 : $offset + 1;
    $endRecord = min($offset + count($readings), $total);

    $updatedAt = (new DateTimeImmutable('now', new DateTimeZone(SENSOR_API_DISPLAY_TIMEZONE)))
        ->format('H:i:s');

    echo json_encode([
        'success' => true,
        'updated_at' => $updatedAt . ' WIB',
        'poll_interval_seconds' => 10,
        'summary' => [
            'total' => $total,
            'normal' => (int) ($summary['normal'] ?? 0),
            'waspada' => (int) ($summary['waspada'] ?? 0),
            'bahaya' => (int) ($summary['bahaya'] ?? 0),
        ],
        'readings' => $readings,
        'pagination' => [
            'page' => $page,
            'per_page' => SENSOR_API_PER_PAGE,
            'total_pages' => $totalPages,
            'total' => $total,
            'offset' => $offset,
            'start_record' => $startRecord,
            'end_record' => $endRecord,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Sensor live data error: ' . $exception->getMessage());
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Data sensor real-time gagal dimuat.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
