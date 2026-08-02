<?php

declare(strict_types=1);

const DEFAULT_NORMAL_MAX = 1499;
const DEFAULT_WARNING_MAX = 2499;

/**
 * @return array{normal_max:int,warning_max:int}
 */
function loadMonitoringThresholds(PDO $database): array
{
    $aliases = [
        'normal' => [
            'mq135_normal_max',
            'normal_max',
            'normal_threshold',
            'threshold_normal',
            'batas_normal',
        ],
        'warning' => [
            'mq135_warning_max',
            'warning_max',
            'waspada_max',
            'warning_threshold',
            'threshold_warning',
            'batas_waspada',
        ],
    ];

    $rows = $database
        ->query('SELECT setting_key, setting_value FROM system_settings')
        ->fetchAll();

    $settings = [];

    foreach ($rows as $row) {
        $key = strtolower(trim((string) ($row['setting_key'] ?? '')));

        if ($key === '') {
            continue;
        }

        $settings[$key] = trim((string) ($row['setting_value'] ?? ''));
    }

    $normalMax = findIntegerSetting(
        $settings,
        $aliases['normal'],
        DEFAULT_NORMAL_MAX
    );

    $warningMax = findIntegerSetting(
        $settings,
        $aliases['warning'],
        DEFAULT_WARNING_MAX
    );

    $normalMax = max(0, min(4093, $normalMax));
    $warningMax = max($normalMax + 1, min(4094, $warningMax));

    return [
        'normal_max' => $normalMax,
        'warning_max' => $warningMax,
    ];
}

/**
 * @param array<string,string> $settings
 * @param list<string> $aliases
 */
function findIntegerSetting(
    array $settings,
    array $aliases,
    int $defaultValue
): int {
    foreach ($aliases as $alias) {
        $normalizedAlias = strtolower($alias);

        if (!array_key_exists($normalizedAlias, $settings)) {
            continue;
        }

        $value = $settings[$normalizedAlias];

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
    }

    return $defaultValue;
}

function determineAirStatus(
    int $mq135Raw,
    int $normalMax,
    int $warningMax
): string {
    if ($mq135Raw <= $normalMax) {
        return 'Normal';
    }

    if ($mq135Raw <= $warningMax) {
        return 'Waspada';
    }

    return 'Bahaya';
}

/** @return array<string,mixed> */
function findDeviceByCode(PDO $database, string $deviceCode): array
{
    $statement = $database->prepare(
        'SELECT id, device_code, device_name, location, status, last_seen
         FROM devices
         WHERE device_code = :device_code
         LIMIT 1'
    );

    $statement->execute([
        'device_code' => $deviceCode,
    ]);

    $device = $statement->fetch();

    if (!is_array($device)) {
        throw new RuntimeException('Perangkat tidak ditemukan.');
    }

    return $device;
}

function getPreviousAirStatus(PDO $database, int $deviceId): ?string
{
    $statement = $database->prepare(
        'SELECT air_status
         FROM sensor_readings
         WHERE device_id = :device_id
         ORDER BY recorded_at DESC, id DESC
         LIMIT 1
         FOR UPDATE'
    );

    $statement->execute([
        'device_id' => $deviceId,
    ]);

    $value = $statement->fetchColumn();

    if ($value === false || $value === null || trim((string) $value) === '') {
        return null;
    }

    return trim((string) $value);
}

function insertSensorReading(
    PDO $database,
    int $deviceId,
    int $mq135Raw,
    string $airStatus
): int {
    $statement = $database->prepare(
        'INSERT INTO sensor_readings
            (device_id, mq135_raw, air_status, recorded_at)
         VALUES
            (:device_id, :mq135_raw, :air_status, NOW())'
    );

    $statement->execute([
        'device_id' => $deviceId,
        'mq135_raw' => $mq135Raw,
        'air_status' => $airStatus,
    ]);

    return (int) $database->lastInsertId();
}

function markDeviceOnline(PDO $database, int $deviceId): void
{
    $activeValue = defined('DEVICE_ACTIVE_VALUE')
        ? trim((string) DEVICE_ACTIVE_VALUE)
        : '';

    if ($activeValue !== '') {
        try {
            $statement = $database->prepare(
                'UPDATE devices
                 SET status = :status, last_seen = NOW()
                 WHERE id = :id'
            );

            $statement->execute([
                'status' => $activeValue,
                'id' => $deviceId,
            ]);

            return;
        } catch (PDOException $exception) {
            error_log(
                'Status perangkat tidak dapat diperbarui: '
                . $exception->getMessage()
            );
        }
    }

    $statement = $database->prepare(
        'UPDATE devices
         SET last_seen = NOW()
         WHERE id = :id'
    );

    $statement->execute([
        'id' => $deviceId,
    ]);
}

/**
 * @return array{
 *   columns:array<string,array<string,mixed>>,
 *   id:?string,
 *   chat_id:string,
 *   name:?string,
 *   username:?string,
 *   status:?string,
 *   active_value:int|string
 * }
 */
function getTelegramRecipientSchema(PDO $database): array
{
    $columns = describeDatabaseTable($database, 'telegram_users');

    $chatIdColumn = findDatabaseColumn($columns, [
        'chat_id',
        'telegram_chat_id',
        'telegram_id',
        'chatid',
    ]);

    if ($chatIdColumn === null) {
        throw new RuntimeException(
            'Kolom Chat ID tidak ditemukan pada tabel telegram_users.'
        );
    }

    $statusColumn = findDatabaseColumn($columns, [
        'status',
        'is_active',
        'active',
        'enabled',
    ]);

    return [
        'columns' => $columns,
        'id' => findDatabaseColumn($columns, [
            'id',
            'telegram_user_id',
            'user_id',
        ]),
        'chat_id' => $chatIdColumn,
        'name' => findDatabaseColumn($columns, [
            'full_name',
            'name',
            'display_name',
            'nama',
        ]),
        'username' => findDatabaseColumn($columns, [
            'telegram_username',
            'username',
            'user_name',
        ]),
        'status' => $statusColumn,
        'active_value' => resolveActiveDatabaseValue(
            $statusColumn === null
                ? null
                : $columns[strtolower($statusColumn)]
        ),
    ];
}

/**
 * @param array<string,mixed> $schema
 * @return list<array{id:int|null,chat_id:string,name:string,username:string}>
 */
function getActiveTelegramRecipients(
    PDO $database,
    array $schema
): array {
    $select = [
        databaseColumnExpression($schema['id'] ?? null, 'recipient_id'),
        databaseColumnExpression($schema['chat_id'], 'chat_id'),
        databaseColumnExpression($schema['name'] ?? null, 'recipient_name'),
        databaseColumnExpression($schema['username'] ?? null, 'username'),
    ];

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM telegram_users';
    $parameters = [];

    if (is_string($schema['status'] ?? null)) {
        $sql .= ' WHERE '
            . quoteDatabaseIdentifier((string) $schema['status'])
            . ' = :active_value';
        $parameters['active_value'] = $schema['active_value'];
    }

    $statement = $database->prepare($sql);
    $statement->execute($parameters);

    $recipients = [];

    foreach ($statement->fetchAll() as $row) {
        $chatId = trim((string) ($row['chat_id'] ?? ''));

        if ($chatId === '') {
            continue;
        }

        $recipientId = $row['recipient_id'] ?? null;

        $recipients[] = [
            'id' => is_numeric($recipientId) ? (int) $recipientId : null,
            'chat_id' => $chatId,
            'name' => trim((string) ($row['recipient_name'] ?? '')),
            'username' => trim((string) ($row['username'] ?? '')),
        ];
    }

    return $recipients;
}

/**
 * @return array<string,array<string,mixed>>
 */
function describeDatabaseTable(PDO $database, string $table): array
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        throw new InvalidArgumentException('Nama tabel basis data tidak valid.');
    }

    $rows = $database
        ->query('DESCRIBE ' . quoteDatabaseIdentifier($table))
        ->fetchAll();

    $columns = [];

    foreach ($rows as $row) {
        $field = (string) ($row['Field'] ?? '');

        if ($field === '') {
            continue;
        }

        $columns[strtolower($field)] = [
            'field' => $field,
            'type' => strtolower((string) ($row['Type'] ?? '')),
            'null' => strtoupper((string) ($row['Null'] ?? '')) === 'YES',
            'default' => $row['Default'] ?? null,
            'extra' => strtolower((string) ($row['Extra'] ?? '')),
        ];
    }

    return $columns;
}

/**
 * @param array<string,array<string,mixed>> $columns
 * @param list<string> $aliases
 */
function findDatabaseColumn(array $columns, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $normalized = strtolower($alias);

        if (isset($columns[$normalized])) {
            return (string) $columns[$normalized]['field'];
        }
    }

    return null;
}

function quoteDatabaseIdentifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Nama kolom basis data tidak valid.');
    }

    return '`' . $identifier . '`';
}

function databaseColumnExpression(?string $column, string $alias): string
{
    if ($column === null || $column === '') {
        return 'NULL AS ' . quoteDatabaseIdentifier($alias);
    }

    return quoteDatabaseIdentifier($column)
        . ' AS '
        . quoteDatabaseIdentifier($alias);
}

/** @param array<string,mixed>|null $column */
function resolveActiveDatabaseValue(?array $column): int|string
{
    if ($column === null) {
        return 'active';
    }

    $type = strtolower((string) ($column['type'] ?? ''));

    if (
        str_contains($type, 'int')
        || str_contains($type, 'bool')
        || str_contains($type, 'bit')
    ) {
        return 1;
    }

    if (preg_match('/^enum\((.*)\)$/i', $type, $matches) === 1) {
        preg_match_all(
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            $matches[1],
            $valueMatches
        );

        $values = array_map(
            static fn (string $value): string => stripcslashes($value),
            $valueMatches[1] ?? []
        );

        foreach (['active', 'aktif', 'enabled', 'on', '1'] as $candidate) {
            foreach ($values as $value) {
                if (strtolower(trim($value)) === $candidate) {
                    return $value;
                }
            }
        }

        if ($values !== []) {
            return $values[0];
        }
    }

    return 'active';
}

/**
 * @return array<string,mixed>
 */
function getNotificationLogInsertSchema(PDO $database): array
{
    $columns = describeDatabaseTable($database, 'notification_logs');

    if ($columns === []) {
        throw new RuntimeException(
            'Tabel notification_logs tidak memiliki kolom.'
        );
    }

    $statusColumn = findDatabaseColumn($columns, [
        'status',
        'delivery_status',
        'send_status',
        'sent_status',
        'notification_status',
    ]);

    return [
        'columns' => $columns,
        'message' => findDatabaseColumn($columns, [
            'message',
            'notification_message',
            'pesan',
            'content',
            'text',
            'message_text',
        ]),
        'status' => $statusColumn,
        'sent_at' => findDatabaseColumn($columns, [
            'sent_at',
            'created_at',
            'notified_at',
            'notification_time',
            'sent_on',
            'recorded_at',
        ]),
        'device_id' => findDatabaseColumn($columns, [
            'device_id',
            'perangkat_id',
        ]),
        'device_code' => findDatabaseColumn($columns, [
            'device_code',
            'kode_perangkat',
        ]),
        'device_name' => findDatabaseColumn($columns, [
            'device_name',
            'nama_perangkat',
        ]),
        'telegram_user_id' => findDatabaseColumn($columns, [
            'telegram_user_id',
            'user_id',
            'recipient_id',
        ]),
        'chat_id' => findDatabaseColumn($columns, [
            'chat_id',
            'telegram_chat_id',
            'telegram_id',
        ]),
        'recipient_name' => findDatabaseColumn($columns, [
            'recipient_name',
            'user_name',
            'full_name',
            'name',
            'nama',
        ]),
        'mq135_raw' => findDatabaseColumn($columns, [
            'mq135_raw',
            'adc_value',
            'sensor_value',
            'nilai_adc',
        ]),
        'air_status' => findDatabaseColumn($columns, [
            'air_status',
            'new_status',
            'current_status',
            'status_udara',
        ]),
        'previous_status' => findDatabaseColumn($columns, [
            'previous_status',
            'old_status',
            'status_before',
            'status_sebelumnya',
        ]),
        'error_message' => findDatabaseColumn($columns, [
            'error_message',
            'error',
            'response_message',
            'failure_reason',
            'notes',
            'note',
        ]),
        'sent_value' => resolveDeliveryDatabaseValue(
            $statusColumn === null
                ? null
                : $columns[strtolower($statusColumn)],
            true
        ),
        'failed_value' => resolveDeliveryDatabaseValue(
            $statusColumn === null
                ? null
                : $columns[strtolower($statusColumn)],
            false
        ),
    ];
}

/** @param array<string,mixed>|null $column */
function resolveDeliveryDatabaseValue(
    ?array $column,
    bool $success
): int|string {
    if ($column === null) {
        return $success ? 'sent' : 'failed';
    }

    $type = strtolower((string) ($column['type'] ?? ''));

    if (
        str_contains($type, 'int')
        || str_contains($type, 'bool')
        || str_contains($type, 'bit')
    ) {
        return $success ? 1 : 0;
    }

    if (preg_match('/^enum\((.*)\)$/i', $type, $matches) === 1) {
        preg_match_all(
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            $matches[1],
            $valueMatches
        );

        $values = array_map(
            static fn (string $value): string => stripcslashes($value),
            $valueMatches[1] ?? []
        );

        $candidates = $success
            ? ['sent', 'terkirim', 'success', 'berhasil', 'delivered']
            : ['failed', 'gagal', 'error', 'tidak terkirim'];

        foreach ($candidates as $candidate) {
            foreach ($values as $value) {
                if (strtolower(trim($value)) === $candidate) {
                    return $value;
                }
            }
        }

        if ($values !== []) {
            return $success
                ? $values[0]
                : ($values[1] ?? $values[0]);
        }
    }

    return $success ? 'sent' : 'failed';
}

/**
 * @param array<string,mixed> $schema
 * @param array<string,mixed> $data
 */
function insertNotificationLog(
    PDO $database,
    array $schema,
    array $data
): void {
    $mapping = [
        'message' => 'message',
        'status' => 'status',
        'device_id' => 'device_id',
        'device_code' => 'device_code',
        'device_name' => 'device_name',
        'telegram_user_id' => 'telegram_user_id',
        'chat_id' => 'chat_id',
        'recipient_name' => 'recipient_name',
        'mq135_raw' => 'mq135_raw',
        'air_status' => 'air_status',
        'previous_status' => 'previous_status',
        'error_message' => 'error_message',
    ];

    $columns = [];
    $placeholders = [];
    $parameters = [];

    foreach ($mapping as $schemaKey => $dataKey) {
        $column = $schema[$schemaKey] ?? null;

        if (!is_string($column) || $column === '') {
            continue;
        }

        $columns[] = quoteDatabaseIdentifier($column);
        $placeholder = ':' . $schemaKey;
        $placeholders[] = $placeholder;
        $parameters[$schemaKey] = $data[$dataKey] ?? null;
    }

    $sentAtColumn = $schema['sent_at'] ?? null;

    if (is_string($sentAtColumn) && $sentAtColumn !== '') {
        $columns[] = quoteDatabaseIdentifier($sentAtColumn);
        $placeholders[] = 'NOW()';
    }

    if ($columns === []) {
        throw new RuntimeException(
            'Tidak ada kolom notification_logs yang dapat diisi.'
        );
    }

    $statement = $database->prepare(
        'INSERT INTO notification_logs ('
        . implode(', ', $columns)
        . ') VALUES ('
        . implode(', ', $placeholders)
        . ')'
    );

    $statement->execute($parameters);
}
