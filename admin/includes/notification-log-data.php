<?php

declare(strict_types=1);

/**
 * Membaca struktur tabel notification_logs dan memetakan nama kolom aktual.
 *
 * @return array<string,mixed>
 */
function getNotificationLogSchema(PDO $database): array
{
    $columns = describeNotificationTable($database, 'notification_logs');

    if ($columns === []) {
        throw new RuntimeException('Tabel notification_logs tidak memiliki struktur kolom.');
    }

    $idColumn = findNotificationColumn($columns, [
        'id',
        'notification_id',
        'notification_log_id',
        'log_id',
    ]);

    $timeColumn = findNotificationColumn($columns, [
        'sent_at',
        'created_at',
        'notified_at',
        'notification_time',
        'sent_on',
        'recorded_at',
        'updated_at',
    ]);

    if ($idColumn === null && $timeColumn === null) {
        $available = implode(', ', array_map(
            static fn (array $column): string => (string) $column['field'],
            array_values($columns)
        ));

        throw new RuntimeException(
            'Struktur notification_logs belum didukung. Kolom yang tersedia: ' . $available
        );
    }

    $deviceColumns = notificationTableExists($database, 'devices')
        ? describeNotificationTable($database, 'devices')
        : [];

    $telegramColumns = notificationTableExists($database, 'telegram_users')
        ? describeNotificationTable($database, 'telegram_users')
        : [];

    $deviceIdColumn = findNotificationColumn($columns, ['device_id', 'perangkat_id']);
    $telegramUserIdColumn = findNotificationColumn($columns, [
        'telegram_user_id',
        'user_id',
        'recipient_id',
    ]);

    $deviceJoinId = findNotificationColumn($deviceColumns, ['id', 'device_id']);
    $telegramJoinId = findNotificationColumn($telegramColumns, [
        'id',
        'telegram_user_id',
        'user_id',
    ]);

    return [
        'columns' => $columns,
        'id' => $idColumn,
        'message' => findNotificationColumn($columns, [
            'message',
            'notification_message',
            'pesan',
            'content',
            'text',
            'message_text',
        ]),
        'status' => findNotificationColumn($columns, [
            'status',
            'delivery_status',
            'send_status',
            'sent_status',
            'notification_status',
        ]),
        'sent_at' => $timeColumn,
        'device_id' => $deviceIdColumn,
        'device_code' => findNotificationColumn($columns, [
            'device_code',
            'kode_perangkat',
        ]),
        'device_name' => findNotificationColumn($columns, [
            'device_name',
            'nama_perangkat',
        ]),
        'telegram_user_id' => $telegramUserIdColumn,
        'chat_id' => findNotificationColumn($columns, [
            'chat_id',
            'telegram_chat_id',
            'telegram_id',
        ]),
        'recipient_name' => findNotificationColumn($columns, [
            'recipient_name',
            'user_name',
            'full_name',
            'name',
            'nama',
        ]),
        'mq135_raw' => findNotificationColumn($columns, [
            'mq135_raw',
            'adc_value',
            'sensor_value',
            'nilai_adc',
        ]),
        'air_status' => findNotificationColumn($columns, [
            'air_status',
            'new_status',
            'current_status',
            'status_udara',
        ]),
        'previous_status' => findNotificationColumn($columns, [
            'previous_status',
            'old_status',
            'status_before',
            'status_sebelumnya',
        ]),
        'error_message' => findNotificationColumn($columns, [
            'error_message',
            'error',
            'response_message',
            'failure_reason',
            'notes',
            'note',
        ]),
        'device_join' => [
            'enabled' => $deviceIdColumn !== null && $deviceJoinId !== null,
            'id' => $deviceJoinId,
            'code' => findNotificationColumn($deviceColumns, [
                'device_code',
                'kode_perangkat',
                'code',
            ]),
            'name' => findNotificationColumn($deviceColumns, [
                'device_name',
                'nama_perangkat',
                'name',
            ]),
            'location' => findNotificationColumn($deviceColumns, [
                'location',
                'lokasi',
            ]),
        ],
        'telegram_join' => [
            'enabled' => $telegramUserIdColumn !== null && $telegramJoinId !== null,
            'id' => $telegramJoinId,
            'chat_id' => findNotificationColumn($telegramColumns, [
                'chat_id',
                'telegram_chat_id',
                'telegram_id',
                'chatid',
            ]),
            'name' => findNotificationColumn($telegramColumns, [
                'full_name',
                'name',
                'display_name',
                'nama',
            ]),
            'username' => findNotificationColumn($telegramColumns, [
                'telegram_username',
                'username',
                'user_name',
            ]),
        ],
    ];
}

/** @return array<string,array<string,mixed>> */
function describeNotificationTable(PDO $database, string $table): array
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        throw new InvalidArgumentException('Nama tabel basis data tidak valid.');
    }

    $rows = $database->query('DESCRIBE `' . $table . '`')->fetchAll();
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
            'key' => strtoupper((string) ($row['Key'] ?? '')),
            'default' => $row['Default'] ?? null,
            'extra' => strtolower((string) ($row['Extra'] ?? '')),
        ];
    }

    return $columns;
}

function notificationTableExists(PDO $database, string $table): bool
{
    $statement = $database->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $statement->execute(['table_name' => $table]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * @param array<string,array<string,mixed>> $columns
 * @param list<string> $aliases
 */
function findNotificationColumn(array $columns, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $normalized = strtolower($alias);

        if (isset($columns[$normalized])) {
            return (string) $columns[$normalized]['field'];
        }
    }

    return null;
}

function quoteNotificationIdentifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Nama kolom basis data tidak valid.');
    }

    return '`' . $identifier . '`';
}

/** @param array<string,mixed> $schema */
function notificationColumnExpression(
    array $schema,
    string $schemaKey,
    string $alias,
    string $tableAlias = 'nl'
): string {
    $column = $schema[$schemaKey] ?? null;

    if (!is_string($column) || $column === '') {
        return 'NULL AS ' . quoteNotificationIdentifier($alias);
    }

    return quoteNotificationIdentifier($tableAlias)
        . '.' . quoteNotificationIdentifier($column)
        . ' AS ' . quoteNotificationIdentifier($alias);
}

/** @param array<string,mixed> $schema */
function notificationJoinSql(array $schema): string
{
    $joins = [];

    if (($schema['device_join']['enabled'] ?? false) === true) {
        $joins[] = 'LEFT JOIN `devices` d ON `nl`.'
            . quoteNotificationIdentifier((string) $schema['device_id'])
            . ' = `d`.'
            . quoteNotificationIdentifier((string) $schema['device_join']['id']);
    }

    if (($schema['telegram_join']['enabled'] ?? false) === true) {
        $joins[] = 'LEFT JOIN `telegram_users` tu ON `nl`.'
            . quoteNotificationIdentifier((string) $schema['telegram_user_id'])
            . ' = `tu`.'
            . quoteNotificationIdentifier((string) $schema['telegram_join']['id']);
    }

    return $joins === [] ? '' : ' ' . implode(' ', $joins);
}

/** @param array<string,mixed> $schema */
function notificationDeviceExpression(array $schema): string
{
    $expressions = [];

    foreach (['device_name', 'device_code'] as $key) {
        if (is_string($schema[$key] ?? null)) {
            $expressions[] = '`nl`.' . quoteNotificationIdentifier((string) $schema[$key]);
        }
    }

    if (($schema['device_join']['enabled'] ?? false) === true) {
        foreach (['name', 'code'] as $key) {
            if (is_string($schema['device_join'][$key] ?? null)) {
                $expressions[] = '`d`.' . quoteNotificationIdentifier((string) $schema['device_join'][$key]);
            }
        }
    }

    if (is_string($schema['device_id'] ?? null)) {
        $expressions[] = "CONCAT('ID ', CAST(`nl`."
            . quoteNotificationIdentifier((string) $schema['device_id'])
            . ' AS CHAR))';
    }

    if ($expressions === []) {
        return 'NULL AS `device_label`';
    }

    return 'COALESCE(' . implode(', ', $expressions) . ') AS `device_label`';
}

/** @param array<string,mixed> $schema */
function notificationRecipientExpression(array $schema): string
{
    $expressions = [];

    foreach (['recipient_name', 'chat_id'] as $key) {
        if (is_string($schema[$key] ?? null)) {
            $expressions[] = '`nl`.' . quoteNotificationIdentifier((string) $schema[$key]);
        }
    }

    if (($schema['telegram_join']['enabled'] ?? false) === true) {
        foreach (['name', 'username', 'chat_id'] as $key) {
            if (is_string($schema['telegram_join'][$key] ?? null)) {
                $expressions[] = '`tu`.' . quoteNotificationIdentifier((string) $schema['telegram_join'][$key]);
            }
        }
    }

    if (is_string($schema['telegram_user_id'] ?? null)) {
        $expressions[] = "CONCAT('ID ', CAST(`nl`."
            . quoteNotificationIdentifier((string) $schema['telegram_user_id'])
            . ' AS CHAR))';
    }

    if ($expressions === []) {
        return 'NULL AS `recipient_label`';
    }

    return 'COALESCE(' . implode(', ', $expressions) . ') AS `recipient_label`';
}

/**
 * @param array<string,mixed> $schema
 * @param array{search:string,status:string,date_from:string,date_to:string} $filters
 * @return array{sql:string,parameters:array<string,mixed>}
 */
function buildNotificationConditions(array $schema, array $filters): array
{
    $conditions = [];
    $parameters = [];

    if ($filters['search'] !== '') {
        $searchColumns = [];

        foreach ([
            'message',
            'status',
            'device_code',
            'device_name',
            'recipient_name',
            'chat_id',
            'air_status',
        ] as $key) {
            if (is_string($schema[$key] ?? null)) {
                $searchColumns[] = '`nl`.'
                    . quoteNotificationIdentifier((string) $schema[$key]);
            }
        }

        if (($schema['device_join']['enabled'] ?? false) === true) {
            foreach (['name', 'code', 'location'] as $key) {
                if (is_string($schema['device_join'][$key] ?? null)) {
                    $searchColumns[] = '`d`.'
                        . quoteNotificationIdentifier((string) $schema['device_join'][$key]);
                }
            }
        }

        if (($schema['telegram_join']['enabled'] ?? false) === true) {
            foreach (['name', 'username', 'chat_id'] as $key) {
                if (is_string($schema['telegram_join'][$key] ?? null)) {
                    $searchColumns[] = '`tu`.'
                        . quoteNotificationIdentifier((string) $schema['telegram_join'][$key]);
                }
            }
        }

        if ($searchColumns !== []) {
            $parts = [];

            foreach (array_unique($searchColumns) as $index => $column) {
                $parameter = 'search_' . $index;
                $parts[] = 'CAST(' . $column . ' AS CHAR) LIKE :' . $parameter;
                $parameters[$parameter] = '%' . $filters['search'] . '%';
            }

            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    if ($filters['status'] !== '' && is_string($schema['status'] ?? null)) {
        $conditions[] = '`nl`.'
            . quoteNotificationIdentifier((string) $schema['status'])
            . ' = :status';
        $parameters['status'] = $filters['status'];
    }

    if (is_string($schema['sent_at'] ?? null)) {
        $timeColumn = '`nl`.'
            . quoteNotificationIdentifier((string) $schema['sent_at']);

        if ($filters['date_from'] !== '') {
            $conditions[] = $timeColumn . ' >= :date_from';
            $parameters['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if ($filters['date_to'] !== '') {
            $conditions[] = $timeColumn . ' <= :date_to';
            $parameters['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
    }

    return [
        'sql' => $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
        'parameters' => $parameters,
    ];
}

/**
 * @param array<string,mixed> $schema
 * @param array{search:string,status:string,date_from:string,date_to:string} $filters
 * @return array{rows:list<array<string,mixed>>,total:int,total_pages:int,page:int}
 */
function getNotificationLogs(
    PDO $database,
    array $schema,
    array $filters,
    int $page = 1,
    int $perPage = 25
): array {
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $joinSql = notificationJoinSql($schema);
    $conditions = buildNotificationConditions($schema, $filters);

    $countStatement = $database->prepare(
        'SELECT COUNT(*) FROM `notification_logs` nl'
        . $joinSql
        . $conditions['sql']
    );
    $countStatement->execute($conditions['parameters']);
    $total = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $select = [
        notificationColumnExpression($schema, 'id', 'id'),
        notificationColumnExpression($schema, 'message', 'message'),
        notificationColumnExpression($schema, 'status', 'status'),
        notificationColumnExpression($schema, 'sent_at', 'sent_at'),
        notificationColumnExpression($schema, 'mq135_raw', 'mq135_raw'),
        notificationColumnExpression($schema, 'air_status', 'air_status'),
        notificationColumnExpression($schema, 'previous_status', 'previous_status'),
        notificationColumnExpression($schema, 'error_message', 'error_message'),
        notificationDeviceExpression($schema),
        notificationRecipientExpression($schema),
    ];

    $orderColumn = is_string($schema['sent_at'] ?? null)
        ? '`nl`.' . quoteNotificationIdentifier((string) $schema['sent_at'])
        : '`nl`.' . quoteNotificationIdentifier((string) $schema['id']);

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM `notification_logs` nl'
        . $joinSql
        . $conditions['sql']
        . ' ORDER BY ' . $orderColumn . ' DESC'
        . ' LIMIT :limit OFFSET :offset';

    $statement = $database->prepare($sql);

    foreach ($conditions['parameters'] as $name => $value) {
        $statement->bindValue(':' . $name, $value);
    }

    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    return [
        'rows' => $statement->fetchAll(),
        'total' => $total,
        'total_pages' => $totalPages,
        'page' => $page,
    ];
}

/** @param array<string,mixed> $schema */
function getNotificationStatuses(PDO $database, array $schema): array
{
    if (!is_string($schema['status'] ?? null)) {
        return [];
    }

    $column = quoteNotificationIdentifier((string) $schema['status']);
    $statement = $database->query(
        'SELECT DISTINCT ' . $column . ' AS status'
        . ' FROM `notification_logs`'
        . ' WHERE ' . $column . ' IS NOT NULL'
        . ' ORDER BY ' . $column . ' ASC'
    );

    return array_values(array_filter(array_map(
        static fn (array $row): string => trim((string) ($row['status'] ?? '')),
        $statement->fetchAll()
    ), static fn (string $status): bool => $status !== ''));
}

/** @param array<string,mixed> $schema */
function getNotificationSummary(PDO $database, array $schema): array
{
    $total = (int) $database
        ->query('SELECT COUNT(*) FROM `notification_logs`')
        ->fetchColumn();
    $today = 0;

    if (is_string($schema['sent_at'] ?? null)) {
        $todayStatement = $database->query(
            'SELECT COUNT(*) FROM `notification_logs` WHERE DATE('
            . quoteNotificationIdentifier((string) $schema['sent_at'])
            . ') = CURRENT_DATE()'
        );
        $today = (int) $todayStatement->fetchColumn();
    }

    $sent = 0;
    $failed = 0;

    if (is_string($schema['status'] ?? null)) {
        $statusColumn = quoteNotificationIdentifier((string) $schema['status']);
        $statement = $database->query(
            'SELECT ' . $statusColumn . ' AS status, COUNT(*) AS total'
            . ' FROM `notification_logs`'
            . ' GROUP BY ' . $statusColumn
        );

        foreach ($statement->fetchAll() as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $count = (int) ($row['total'] ?? 0);

            if (in_array($status, [
                'sent',
                'terkirim',
                'success',
                'successful',
                'delivered',
                'sukses',
            ], true)) {
                $sent += $count;
            }

            if (in_array($status, [
                'failed',
                'gagal',
                'error',
                'undelivered',
                'tidak terkirim',
            ], true)) {
                $failed += $count;
            }
        }
    }

    return [
        'total' => $total,
        'today' => $today,
        'sent' => $sent,
        'failed' => $failed,
    ];
}
