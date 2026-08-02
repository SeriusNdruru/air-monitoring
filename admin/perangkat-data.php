<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/device-data.php';

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

const DEVICE_DATABASE_TIMEZONE = 'UTC';
const DEVICE_DISPLAY_TIMEZONE = 'Asia/Jakarta';

function deviceApiLocalDateTime(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable(
            $value,
            new DateTimeZone(DEVICE_DATABASE_TIMEZONE)
        ))->setTimezone(new DateTimeZone(DEVICE_DISPLAY_TIMEZONE));
    } catch (Throwable) {
        return null;
    }
}

function deviceApiDateTime(?string $value): string
{
    $date = deviceApiLocalDateTime($value);

    return $date instanceof DateTimeImmutable
        ? $date->format('d M Y H:i:s')
        : '-';
}

try {
    $database = getDatabaseConnection();
    $search = trim((string) ($_GET['q'] ?? ''));
    $status = normalizeDeviceStatusFilter((string) ($_GET['status'] ?? ''));
    $devices = getDevicesWithLatestReading($database, $search, $status);

    $payload = array_map(
        static function (array $device): array {
            $computedStatus = strtolower((string) ($device['status'] ?? 'offline'));

            return [
                'id' => (int) ($device['id'] ?? 0),
                'device_code' => (string) ($device['device_code'] ?? '-'),
                'device_name' => (string) ($device['device_name'] ?? '-'),
                'location' => (string) ($device['location'] ?? '-'),
                'status' => $computedStatus === 'online' ? 'online' : 'offline',
                'mq135_raw' => isset($device['mq135_raw'])
                    ? (int) $device['mq135_raw']
                    : null,
                'air_status' => (string) ($device['air_status'] ?? '-'),
                'last_seen' => (string) ($device['last_seen'] ?? ''),
                'last_seen_label' => deviceApiDateTime(
                    isset($device['last_seen'])
                        ? (string) $device['last_seen']
                        : null
                ),
            ];
        },
        $devices
    );

    echo json_encode([
        'success' => true,
        'count' => count($payload),
        'devices' => $payload,
        'online_timeout_seconds' => DEVICE_ONLINE_TIMEOUT_SECONDS,
        'updated_at' => (new DateTimeImmutable('now', new DateTimeZone(DEVICE_DISPLAY_TIMEZONE)))
            ->format('d M Y H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Device live data error: ' . $exception->getMessage());
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Data perangkat gagal dimuat.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
