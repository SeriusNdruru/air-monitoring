<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard-data.php';

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

const DATABASE_TIMEZONE = 'UTC';
const DISPLAY_TIMEZONE = 'Asia/Jakarta';

function apiLocalDateTime(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $databaseTimezone = new DateTimeZone(DATABASE_TIMEZONE);
        $displayTimezone = new DateTimeZone(DISPLAY_TIMEZONE);

        return (new DateTimeImmutable($value, $databaseTimezone))
            ->setTimezone($displayTimezone);
    } catch (Throwable) {
        return null;
    }
}

function apiDateTime(?string $value): string
{
    $date = apiLocalDateTime($value);

    return $date instanceof DateTimeImmutable
        ? $date->format('d M Y H:i:s')
        : ($value === null || trim($value) === '' ? '-' : $value);
}

try {
    $database = getDatabaseConnection();
    $dashboard = getDashboardData($database);
    $rows = $dashboard['chart_readings'];

    $readings = array_map(
        static function (array $row): array {
            $rawTime = (string) ($row['recorded_at'] ?? $row['created_at'] ?? '');
            $timestamp = null;
            $label = '-';
            $fullLabel = '-';

            if ($rawTime !== '') {
                $date = apiLocalDateTime($rawTime);

                if ($date instanceof DateTimeImmutable) {
                    $timestamp = $date->format(DateTimeInterface::ATOM);
                    $label = $date->format('H:i:s');
                    $fullLabel = $date->format('d M Y H:i:s');
                } else {
                    $timestamp = $rawTime;
                    $label = $rawTime;
                    $fullLabel = $rawTime;
                }
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'mq135_raw' => max(0, min(4095, (int) ($row['mq135_raw'] ?? 0))),
                'air_status' => (string) ($row['air_status'] ?? '-'),
                'timestamp' => $timestamp,
                'label' => $label,
                'full_label' => $fullLabel,
            ];
        },
        $rows
    );

    $devices = array_map(
        static function (array $device): array {
            return [
                'id' => (int) ($device['id'] ?? 0),
                'device_code' => (string) ($device['device_code'] ?? '-'),
                'device_name' => (string) ($device['device_name'] ?? '-'),
                'location' => (string) ($device['location'] ?? '-'),
                'status' => strtolower((string) ($device['status'] ?? 'offline')) === 'online'
                    ? 'online'
                    : 'offline',
                'last_seen' => (string) ($device['last_seen'] ?? ''),
                'last_seen_label' => apiDateTime((string) ($device['last_seen'] ?? '')),
            ];
        },
        $dashboard['devices']
    );

    $recentReadings = array_map(
        static function (array $row): array {
            $rawTime = (string) ($row['recorded_at'] ?? $row['created_at'] ?? '');
            $timestamp = null;
            $fullLabel = '-';

            if ($rawTime !== '') {
                $date = apiLocalDateTime($rawTime);

                if ($date instanceof DateTimeImmutable) {
                    $timestamp = $date->format(DateTimeInterface::ATOM);
                    $fullLabel = $date->format('d M Y H:i:s');
                } else {
                    $timestamp = $rawTime;
                    $fullLabel = $rawTime;
                }
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'device_code' => (string) ($row['device_code'] ?? '-'),
                'device_name' => (string) ($row['device_name'] ?? '-'),
                'location' => (string) ($row['location'] ?? '-'),
                'mq135_raw' => max(0, min(4095, (int) ($row['mq135_raw'] ?? 0))),
                'air_status' => (string) ($row['air_status'] ?? '-'),
                'timestamp' => $timestamp,
                'full_label' => $fullLabel,
            ];
        },
        $dashboard['recent_readings']
    );

    $latest = $readings === [] ? null : $readings[array_key_last($readings)];

    echo json_encode([
        'success' => true,
        'count' => count($readings),
        'limit' => 30,
        'latest' => $latest,
        'readings' => $readings,
        'recent_readings' => $recentReadings,
        'summary' => [
            'total_devices' => (int) $dashboard['total_devices'],
            'active_devices' => (int) $dashboard['active_devices'],
            'system_status' => (string) $dashboard['system_status'],
            'notification_count_today' => (int) $dashboard['notification_count_today'],
        ],
        'devices' => $devices,
        'online_timeout_seconds' => DEVICE_ONLINE_TIMEOUT_SECONDS,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Dashboard live data error: ' . $exception->getMessage());
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Data dashboard gagal dimuat.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
