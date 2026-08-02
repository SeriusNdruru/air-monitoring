<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$documentRoot = dirname(__DIR__);
$databaseConfig = $documentRoot . '/admin/config/database.php';
$monitoringConfig = $documentRoot . '/admin/config/monitoring.php';
$monitoringService = $documentRoot . '/admin/includes/monitoring-service.php';
$telegramService = $documentRoot . '/admin/includes/telegram-service.php';

foreach (
    [$databaseConfig, $monitoringConfig, $monitoringService, $telegramService]
    as $requiredFile
) {
    if (!is_file($requiredFile)) {
        respondJson(500, [
            'success' => false,
            'message' => 'Konfigurasi endpoint belum lengkap.',
            'missing_file' => basename($requiredFile),
        ]);
    }

    require_once $requiredFile;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respondJson(405, [
        'success' => false,
        'message' => 'Gunakan metode POST.',
    ]);
}

try {
    $input = readRequestInput();
    verifyApiKey($input);

    $deviceCode = trim((string) ($input['device_code'] ?? ''));
    $rawValue = $input['mq135_raw'] ?? null;

    if (
        $deviceCode === ''
        || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $deviceCode) !== 1
    ) {
        respondJson(422, [
            'success' => false,
            'message' => 'device_code tidak valid.',
        ]);
    }

    if (
        filter_var(
            $rawValue,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 4095]]
        ) === false
    ) {
        respondJson(422, [
            'success' => false,
            'message' => 'mq135_raw harus berupa bilangan 0 sampai 4095.',
        ]);
    }

    $mq135Raw = (int) $rawValue;
    $database = getDatabaseConnection();
    $thresholds = loadMonitoringThresholds($database);
    $airStatus = determineAirStatus(
        $mq135Raw,
        $thresholds['normal_max'],
        $thresholds['warning_max']
    );

    $database->beginTransaction();

    try {
        $device = findDeviceByCode($database, $deviceCode);
        $deviceId = (int) $device['id'];
        $previousStatus = getPreviousAirStatus($database, $deviceId);

        $readingId = insertSensorReading(
            $database,
            $deviceId,
            $mq135Raw,
            $airStatus
        );

        markDeviceOnline($database, $deviceId);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }

    $statusChanged = $previousStatus === null
        ? (defined('NOTIFY_ON_FIRST_READING') && NOTIFY_ON_FIRST_READING)
        : strcasecmp($previousStatus, $airStatus) !== 0;

    $notificationSummary = [
        'attempted' => 0,
        'sent' => 0,
        'failed' => 0,
        'log_errors' => 0,
    ];

    if ($statusChanged) {
        $notificationSummary = notifyTelegramRecipients(
            $database,
            $device,
            $mq135Raw,
            $previousStatus,
            $airStatus
        );
    }

    respondJson(201, [
        'success' => true,
        'message' => 'Data sensor berhasil disimpan.',
        'data' => [
            'reading_id' => $readingId,
            'device_code' => $deviceCode,
            'mq135_raw' => $mq135Raw,
            'air_status' => $airStatus,
            'previous_status' => $previousStatus,
            'status_changed' => $statusChanged,
            'thresholds' => [
                'normal' => [0, $thresholds['normal_max']],
                'waspada' => [
                    $thresholds['normal_max'] + 1,
                    $thresholds['warning_max'],
                ],
                'bahaya' => [
                    $thresholds['warning_max'] + 1,
                    4095,
                ],
            ],
            'notifications' => $notificationSummary,
        ],
    ]);
} catch (RuntimeException $exception) {
    error_log('Monitoring runtime error: ' . $exception->getMessage());

    $statusCode = $exception->getMessage() === 'Perangkat tidak ditemukan.'
        ? 404
        : 500;

    respondJson($statusCode, [
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    error_log('Monitoring endpoint error: ' . $exception->getMessage());

    respondJson(500, [
        'success' => false,
        'message' => 'Terjadi kesalahan pada server.',
    ]);
}

/** @return array<string,mixed> */
function readRequestInput(): array
{
    $contentType = strtolower(
        trim((string) ($_SERVER['CONTENT_TYPE'] ?? ''))
    );

    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            respondJson(400, [
                'success' => false,
                'message' => 'JSON tidak valid.',
            ]);
        }

        return $decoded;
    }

    return $_POST;
}

/** @param array<string,mixed> $input */
function verifyApiKey(array $input): void
{
    if (!defined('MONITORING_API_KEY')) {
        respondJson(500, [
            'success' => false,
            'message' => 'MONITORING_API_KEY belum dikonfigurasi.',
        ]);
    }

    $expected = trim((string) MONITORING_API_KEY);

    if ($expected === '' || str_starts_with($expected, 'GANTI_')) {
        respondJson(500, [
            'success' => false,
            'message' => 'API key server belum diisi.',
        ]);
    }

    $provided = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));

    if ($provided === '') {
        $provided = trim((string) ($input['api_key'] ?? ''));
    }

    if ($provided === '' || !hash_equals($expected, $provided)) {
        respondJson(401, [
            'success' => false,
            'message' => 'API key tidak valid.',
        ]);
    }
}

/**
 * @param array<string,mixed> $device
 * @return array{attempted:int,sent:int,failed:int,log_errors:int}
 */
function notifyTelegramRecipients(
    PDO $database,
    array $device,
    int $mq135Raw,
    ?string $previousStatus,
    string $airStatus
): array {
    $summary = [
        'attempted' => 0,
        'sent' => 0,
        'failed' => 0,
        'log_errors' => 0,
    ];

    try {
        $recipientSchema = getTelegramRecipientSchema($database);
        $recipients = getActiveTelegramRecipients(
            $database,
            $recipientSchema
        );
    } catch (Throwable $exception) {
        error_log(
            'Penerima Telegram tidak dapat dibaca: '
            . $exception->getMessage()
        );

        return $summary;
    }

    try {
        $logSchema = getNotificationLogInsertSchema($database);
    } catch (Throwable $exception) {
        $logSchema = [];
        error_log(
            'Struktur notification_logs tidak dapat dibaca: '
            . $exception->getMessage()
        );
    }

    $message = buildAirStatusNotification(
        $device,
        $mq135Raw,
        $previousStatus,
        $airStatus
    );

    foreach ($recipients as $recipient) {
        $summary['attempted']++;
        $result = sendTelegramText($recipient['chat_id'], $message);

        if ($result['success']) {
            $summary['sent']++;
        } else {
            $summary['failed']++;
        }

        if ($logSchema === []) {
            continue;
        }

        try {
            insertNotificationLog($database, $logSchema, [
                'message' => $message,
                'status' => $result['success']
                    ? $logSchema['sent_value']
                    : $logSchema['failed_value'],
                'device_id' => (int) ($device['id'] ?? 0),
                'device_code' => (string) ($device['device_code'] ?? ''),
                'device_name' => (string) ($device['device_name'] ?? ''),
                'telegram_user_id' => $recipient['id'],
                'chat_id' => $recipient['chat_id'],
                'recipient_name' => $recipient['name'],
                'mq135_raw' => $mq135Raw,
                'air_status' => $airStatus,
                'previous_status' => $previousStatus,
                'error_message' => $result['error'],
            ]);
        } catch (Throwable $exception) {
            $summary['log_errors']++;
            error_log(
                'Log notifikasi gagal disimpan: '
                . $exception->getMessage()
            );
        }
    }

    return $summary;
}

/** @param array<string,mixed> $payload */
function respondJson(int $statusCode, array $payload): never
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    exit;
}
