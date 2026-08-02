<?php

declare(strict_types=1);

/**
 * Membaca struktur tabel maintenance_logs secara adaptif.
 *
 * @return array<string,mixed>
 */
function getMaintenanceLogSchema(PDO $database): array
{
    if (!maintenanceTableExists($database, 'maintenance_logs')) {
        throw new RuntimeException('Tabel maintenance_logs belum tersedia pada basis data.');
    }

    $columns = describeMaintenanceTable($database, 'maintenance_logs');

    if ($columns === []) {
        throw new RuntimeException('Tabel maintenance_logs tidak memiliki struktur kolom.');
    }

    $idColumn = findMaintenanceColumn($columns, [
        'id',
        'maintenance_id',
        'maintenance_log_id',
        'log_id',
    ]);

    if ($idColumn === null) {
        $available = implode(', ', array_map(
            static fn (array $column): string => (string) $column['field'],
            array_values($columns)
        ));

        throw new RuntimeException(
            'Struktur maintenance_logs belum didukung. Kolom ID tidak ditemukan. Kolom yang tersedia: '
            . $available
        );
    }

    $maintenanceAt = findMaintenanceColumn($columns, [
        'maintenance_at',
        'maintenance_date',
        'performed_at',
        'activity_date',
        'scheduled_at',
        'tanggal_pemeliharaan',
        'tanggal',
        'date',
        'created_at',
    ]);

    $devicesColumns = maintenanceTableExists($database, 'devices')
        ? describeMaintenanceTable($database, 'devices')
        : [];

    $deviceIdColumn = findMaintenanceColumn($columns, ['device_id', 'perangkat_id']);
    $deviceJoinId = findMaintenanceColumn($devicesColumns, ['id', 'device_id']);

    $createdAt = findMaintenanceColumn($columns, ['created_at', 'created_on', 'added_at']);
    if ($createdAt === $maintenanceAt) {
        $createdAt = null;
    }

    return [
        'columns' => $columns,
        'id' => $idColumn,
        'device_id' => $deviceIdColumn,
        'device_code' => findMaintenanceColumn($columns, [
            'device_code',
            'kode_perangkat',
        ]),
        'device_name' => findMaintenanceColumn($columns, [
            'device_name',
            'nama_perangkat',
        ]),
        'activity' => findMaintenanceColumn($columns, [
            'activity',
            'maintenance_type',
            'activity_type',
            'maintenance_activity',
            'maintenance_action',
            'kegiatan',
            'action',
            'title',
            'jenis_pemeliharaan',
        ]),
        'notes' => findMaintenanceColumn($columns, [
            'notes',
            'note',
            'description',
            'details',
            'problem_description',
            'catatan',
            'keterangan',
        ]),
        'performed_by' => findMaintenanceColumn($columns, [
            'performed_by',
            'technician',
            'technician_name',
            'admin_name',
            'petugas',
            'pelaksana',
            'created_by_name',
        ]),
        'admin_id' => findMaintenanceColumn($columns, [
            'admin_id',
            'admin_user_id',
            'created_by',
            'performed_by_id',
        ]),
        'status' => findMaintenanceColumn($columns, [
            'status',
            'maintenance_status',
            'activity_status',
        ]),
        'maintenance_at' => $maintenanceAt,
        'created_at' => $createdAt,
        'updated_at' => findMaintenanceColumn($columns, [
            'updated_at',
            'modified_at',
            'updated_on',
        ]),
        'device_join' => [
            'enabled' => $deviceIdColumn !== null && $deviceJoinId !== null,
            'id' => $deviceJoinId,
            'code' => findMaintenanceColumn($devicesColumns, [
                'device_code',
                'kode_perangkat',
                'code',
            ]),
            'name' => findMaintenanceColumn($devicesColumns, [
                'device_name',
                'nama_perangkat',
                'name',
            ]),
            'location' => findMaintenanceColumn($devicesColumns, [
                'location',
                'lokasi',
            ]),
        ],
    ];
}

/** @return array<string,array<string,mixed>> */
function describeMaintenanceTable(PDO $database, string $table): array
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

function maintenanceTableExists(PDO $database, string $table): bool
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
function findMaintenanceColumn(array $columns, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $normalized = strtolower($alias);

        if (isset($columns[$normalized])) {
            return (string) $columns[$normalized]['field'];
        }
    }

    return null;
}

function quoteMaintenanceIdentifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Nama kolom basis data tidak valid.');
    }

    return '`' . $identifier . '`';
}

/** @param array<string,mixed> $schema */
function maintenanceSelectExpression(
    array $schema,
    string $schemaKey,
    string $alias,
    string $tableAlias = 'ml'
): string {
    $column = $schema[$schemaKey] ?? null;

    if (!is_string($column) || $column === '') {
        return 'NULL AS ' . quoteMaintenanceIdentifier($alias);
    }

    return quoteMaintenanceIdentifier($tableAlias)
        . '.' . quoteMaintenanceIdentifier($column)
        . ' AS ' . quoteMaintenanceIdentifier($alias);
}

/** @param array<string,mixed> $schema */
function maintenanceJoinSql(array $schema): string
{
    if (($schema['device_join']['enabled'] ?? false) !== true) {
        return '';
    }

    return ' LEFT JOIN `devices` d ON `ml`.'
        . quoteMaintenanceIdentifier((string) $schema['device_id'])
        . ' = `d`.'
        . quoteMaintenanceIdentifier((string) $schema['device_join']['id']);
}

/** @param array<string,mixed> $schema */
function maintenanceDeviceExpression(array $schema): string
{
    $expressions = [];

    foreach (['device_name', 'device_code'] as $key) {
        if (is_string($schema[$key] ?? null)) {
            $expressions[] = '`ml`.' . quoteMaintenanceIdentifier((string) $schema[$key]);
        }
    }

    if (($schema['device_join']['enabled'] ?? false) === true) {
        foreach (['name', 'code'] as $key) {
            if (is_string($schema['device_join'][$key] ?? null)) {
                $expressions[] = '`d`.' . quoteMaintenanceIdentifier((string) $schema['device_join'][$key]);
            }
        }
    }

    if (is_string($schema['device_id'] ?? null)) {
        $expressions[] = "CONCAT('ID ', CAST(`ml`."
            . quoteMaintenanceIdentifier((string) $schema['device_id'])
            . ' AS CHAR))';
    }

    if ($expressions === []) {
        return 'NULL AS `device_label`';
    }

    return 'COALESCE(' . implode(', ', $expressions) . ') AS `device_label`';
}

/** @param array<string,mixed> $schema */
function maintenanceDeviceLocationExpression(array $schema): string
{
    if (
        ($schema['device_join']['enabled'] ?? false) === true
        && is_string($schema['device_join']['location'] ?? null)
    ) {
        return '`d`.'
            . quoteMaintenanceIdentifier((string) $schema['device_join']['location'])
            . ' AS `device_location`';
    }

    return 'NULL AS `device_location`';
}

/** @param array<string,mixed> $schema */
function maintenanceBaseSelect(array $schema): string
{
    $expressions = [
        maintenanceSelectExpression($schema, 'id', 'id'),
        maintenanceSelectExpression($schema, 'device_id', 'device_id_raw'),
        maintenanceSelectExpression($schema, 'device_code', 'device_code_raw'),
        maintenanceSelectExpression($schema, 'device_name', 'device_name_raw'),
        maintenanceSelectExpression($schema, 'activity', 'activity'),
        maintenanceSelectExpression($schema, 'notes', 'notes'),
        maintenanceSelectExpression($schema, 'performed_by', 'performed_by'),
        maintenanceSelectExpression($schema, 'admin_id', 'admin_id'),
        maintenanceSelectExpression($schema, 'status', 'status'),
        maintenanceSelectExpression($schema, 'maintenance_at', 'maintenance_at'),
        maintenanceSelectExpression($schema, 'created_at', 'created_at'),
        maintenanceSelectExpression($schema, 'updated_at', 'updated_at'),
        maintenanceDeviceExpression($schema),
        maintenanceDeviceLocationExpression($schema),
    ];

    return implode(', ', $expressions);
}

/**
 * @param array<string,mixed> $schema
 * @return list<array<string,mixed>>
 */
function getMaintenanceDevices(PDO $database, array $schema): array
{
    if (($schema['device_join']['enabled'] ?? false) !== true) {
        return [];
    }

    $id = (string) $schema['device_join']['id'];
    $name = $schema['device_join']['name'] ?? null;
    $code = $schema['device_join']['code'] ?? null;
    $location = $schema['device_join']['location'] ?? null;

    $select = [
        quoteMaintenanceIdentifier($id) . ' AS `id`',
        is_string($name)
            ? quoteMaintenanceIdentifier($name) . ' AS `device_name`'
            : 'NULL AS `device_name`',
        is_string($code)
            ? quoteMaintenanceIdentifier($code) . ' AS `device_code`'
            : 'NULL AS `device_code`',
        is_string($location)
            ? quoteMaintenanceIdentifier($location) . ' AS `location`'
            : 'NULL AS `location`',
    ];

    $orderColumn = is_string($name) ? $name : (is_string($code) ? $code : $id);
    $statement = $database->query(
        'SELECT ' . implode(', ', $select)
        . ' FROM `devices` ORDER BY '
        . quoteMaintenanceIdentifier($orderColumn)
        . ' ASC'
    );

    return $statement->fetchAll();
}

/** @param array<string,mixed> $schema */
function getMaintenanceStatuses(PDO $database, array $schema): array
{
    if (!is_string($schema['status'] ?? null)) {
        return [];
    }

    $statement = $database->query(
        'SELECT DISTINCT '
        . quoteMaintenanceIdentifier((string) $schema['status'])
        . ' AS `status` FROM `maintenance_logs`'
        . ' WHERE '
        . quoteMaintenanceIdentifier((string) $schema['status'])
        . ' IS NOT NULL'
        . ' ORDER BY '
        . quoteMaintenanceIdentifier((string) $schema['status'])
        . ' ASC'
    );

    $statuses = [];

    foreach ($statement->fetchAll() as $row) {
        $status = trim((string) ($row['status'] ?? ''));

        if ($status !== '') {
            $statuses[] = $status;
        }
    }

    return array_values(array_unique($statuses));
}

/** @param array<string,mixed> $schema */
function maintenanceStatusOptions(array $schema, array $existingStatuses = []): array
{
    $statusColumn = $schema['status'] ?? null;

    if (!is_string($statusColumn) || $statusColumn === '') {
        return [];
    }

    $column = $schema['columns'][strtolower($statusColumn)] ?? null;
    $options = [];

    if (is_array($column)) {
        $type = strtolower((string) ($column['type'] ?? ''));

        if (preg_match('/^enum\((.*)\)$/i', $type, $matches) === 1) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $valueMatches);
            $options = array_map(
                static fn (string $value): string => stripcslashes($value),
                $valueMatches[1] ?? []
            );
        }
    }

    if ($options === []) {
        $options = array_merge($existingStatuses, ['Dijadwalkan', 'Diproses', 'Selesai', 'Ditunda']);
    }

    return array_values(array_unique(array_filter(
        array_map(static fn (mixed $value): string => trim((string) $value), $options),
        static fn (string $value): bool => $value !== ''
    )));
}

/**
 * @param array<string,mixed> $schema
 * @return array{total:int,today:int,month:int,devices:int}
 */
function getMaintenanceSummary(PDO $database, array $schema): array
{
    $total = (int) $database->query('SELECT COUNT(*) FROM `maintenance_logs`')->fetchColumn();
    $today = 0;
    $month = 0;
    $devices = 0;

    if (is_string($schema['maintenance_at'] ?? null)) {
        $dateColumn = quoteMaintenanceIdentifier((string) $schema['maintenance_at']);
        $today = (int) $database->query(
            'SELECT COUNT(*) FROM `maintenance_logs` WHERE DATE('
            . $dateColumn . ') = CURDATE()'
        )->fetchColumn();
        $month = (int) $database->query(
            'SELECT COUNT(*) FROM `maintenance_logs` WHERE YEAR('
            . $dateColumn . ') = YEAR(CURDATE()) AND MONTH('
            . $dateColumn . ') = MONTH(CURDATE())'
        )->fetchColumn();
    }

    $deviceColumn = null;
    foreach (['device_id', 'device_code', 'device_name'] as $key) {
        if (is_string($schema[$key] ?? null)) {
            $deviceColumn = (string) $schema[$key];
            break;
        }
    }

    if ($deviceColumn !== null) {
        $devices = (int) $database->query(
            'SELECT COUNT(DISTINCT '
            . quoteMaintenanceIdentifier($deviceColumn)
            . ') FROM `maintenance_logs` WHERE '
            . quoteMaintenanceIdentifier($deviceColumn)
            . ' IS NOT NULL'
        )->fetchColumn();
    }

    return [
        'total' => $total,
        'today' => $today,
        'month' => $month,
        'devices' => $devices,
    ];
}

/**
 * @param array<string,mixed> $schema
 * @param array{search:string,device_id:string,status:string,date_from:string,date_to:string} $filters
 * @return array{rows:list<array<string,mixed>>,total:int,total_pages:int,page:int}
 */
function getMaintenanceLogs(
    PDO $database,
    array $schema,
    array $filters,
    int $page = 1,
    int $perPage = 25
): array {
    $where = [];
    $parameters = [];
    $join = maintenanceJoinSql($schema);

    if ($filters['search'] !== '') {
        $searchExpressions = [];

        foreach (['activity', 'notes', 'performed_by', 'status', 'device_code', 'device_name'] as $key) {
            if (is_string($schema[$key] ?? null)) {
                $searchExpressions[] = 'CAST(`ml`.'
                    . quoteMaintenanceIdentifier((string) $schema[$key])
                    . ' AS CHAR) LIKE :search';
            }
        }

        if (($schema['device_join']['enabled'] ?? false) === true) {
            foreach (['name', 'code', 'location'] as $key) {
                if (is_string($schema['device_join'][$key] ?? null)) {
                    $searchExpressions[] = 'CAST(`d`.'
                        . quoteMaintenanceIdentifier((string) $schema['device_join'][$key])
                        . ' AS CHAR) LIKE :search';
                }
            }
        }

        if ($searchExpressions !== []) {
            $where[] = '(' . implode(' OR ', $searchExpressions) . ')';
            $parameters['search'] = '%' . $filters['search'] . '%';
        }
    }

    if ($filters['device_id'] !== '' && is_string($schema['device_id'] ?? null)) {
        $where[] = '`ml`.'
            . quoteMaintenanceIdentifier((string) $schema['device_id'])
            . ' = :device_id';
        $parameters['device_id'] = (int) $filters['device_id'];
    }

    if ($filters['status'] !== '' && is_string($schema['status'] ?? null)) {
        $where[] = '`ml`.'
            . quoteMaintenanceIdentifier((string) $schema['status'])
            . ' = :status';
        $parameters['status'] = $filters['status'];
    }

    if (is_string($schema['maintenance_at'] ?? null)) {
        $dateExpression = '`ml`.'
            . quoteMaintenanceIdentifier((string) $schema['maintenance_at']);

        if ($filters['date_from'] !== '') {
            $where[] = 'DATE(' . $dateExpression . ') >= :date_from';
            $parameters['date_from'] = $filters['date_from'];
        }

        if ($filters['date_to'] !== '') {
            $where[] = 'DATE(' . $dateExpression . ') <= :date_to';
            $parameters['date_to'] = $filters['date_to'];
        }
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStatement = $database->prepare(
        'SELECT COUNT(*) FROM `maintenance_logs` ml'
        . $join
        . $whereSql
    );
    $countStatement->execute($parameters);
    $total = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    $orderColumn = is_string($schema['maintenance_at'] ?? null)
        ? (string) $schema['maintenance_at']
        : (string) $schema['id'];

    $sql = 'SELECT '
        . maintenanceBaseSelect($schema)
        . ' FROM `maintenance_logs` ml'
        . $join
        . $whereSql
        . ' ORDER BY `ml`.'
        . quoteMaintenanceIdentifier($orderColumn)
        . ' DESC, `ml`.'
        . quoteMaintenanceIdentifier((string) $schema['id'])
        . ' DESC LIMIT :limit OFFSET :offset';

    $statement = $database->prepare($sql);

    foreach ($parameters as $key => $value) {
        $statement->bindValue(':' . $key, $value);
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
function findMaintenanceLogById(PDO $database, array $schema, int $id): ?array
{
    $statement = $database->prepare(
        'SELECT '
        . maintenanceBaseSelect($schema)
        . ' FROM `maintenance_logs` ml'
        . maintenanceJoinSql($schema)
        . ' WHERE `ml`.'
        . quoteMaintenanceIdentifier((string) $schema['id'])
        . ' = :id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/** @param array<string,mixed> $schema */
function getMaintenanceRequiredUnmappedColumns(array $schema): array
{
    $mapped = [];

    foreach ([
        'id', 'device_id', 'device_code', 'device_name', 'activity', 'notes',
        'performed_by', 'admin_id', 'status', 'maintenance_at', 'created_at', 'updated_at',
    ] as $key) {
        if (is_string($schema[$key] ?? null)) {
            $mapped[strtolower((string) $schema[$key])] = true;
        }
    }

    $unmapped = [];

    foreach ($schema['columns'] as $normalized => $column) {
        if (isset($mapped[$normalized])) {
            continue;
        }

        $isAuto = str_contains((string) ($column['extra'] ?? ''), 'auto_increment');
        $hasDefault = ($column['default'] ?? null) !== null;
        $allowsNull = (bool) ($column['null'] ?? false);

        if (!$isAuto && !$hasDefault && !$allowsNull) {
            $unmapped[] = (string) $column['field'];
        }
    }

    return $unmapped;
}

/** @param array<string,mixed> $schema */
function maintenanceDateDatabaseValue(array $schema, string $dateTime): string
{
    $columnName = $schema['maintenance_at'] ?? null;

    if (!is_string($columnName)) {
        return $dateTime;
    }

    $column = $schema['columns'][strtolower($columnName)] ?? null;
    $type = is_array($column) ? strtolower((string) ($column['type'] ?? '')) : '';

    if (str_starts_with($type, 'date') && !str_starts_with($type, 'datetime')) {
        return substr($dateTime, 0, 10);
    }

    return $dateTime;
}

/** @param array<string,mixed> $schema */
function getMaintenanceDeviceById(PDO $database, array $schema, int $deviceId): ?array
{
    if (($schema['device_join']['enabled'] ?? false) !== true) {
        return null;
    }

    $id = (string) $schema['device_join']['id'];
    $code = $schema['device_join']['code'] ?? null;
    $name = $schema['device_join']['name'] ?? null;

    $statement = $database->prepare(
        'SELECT '
        . quoteMaintenanceIdentifier($id) . ' AS `id`, '
        . (is_string($code)
            ? quoteMaintenanceIdentifier($code) . ' AS `device_code`'
            : 'NULL AS `device_code`')
        . ', '
        . (is_string($name)
            ? quoteMaintenanceIdentifier($name) . ' AS `device_name`'
            : 'NULL AS `device_name`')
        . ' FROM `devices` WHERE '
        . quoteMaintenanceIdentifier($id)
        . ' = :id LIMIT 1'
    );
    $statement->execute(['id' => $deviceId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @param array<string,mixed> $schema
 * @param array<string,mixed> $data
 */
function saveMaintenanceLog(
    PDO $database,
    array $schema,
    ?int $id,
    array $data,
    ?int $adminId = null
): int {
    if ($id === null) {
        $unmapped = getMaintenanceRequiredUnmappedColumns($schema);

        if ($unmapped !== []) {
            throw new RuntimeException(
                'Data belum dapat disimpan karena terdapat kolom wajib yang belum dikenali: '
                . implode(', ', $unmapped)
            );
        }
    }

    $values = [];
    $deviceId = isset($data['device_id']) && $data['device_id'] !== ''
        ? (int) $data['device_id']
        : null;
    $selectedDevice = $deviceId === null
        ? null
        : getMaintenanceDeviceById($database, $schema, $deviceId);

    if (is_string($schema['device_id'] ?? null) && $deviceId !== null) {
        $values[(string) $schema['device_id']] = $deviceId;
    }

    if (is_string($schema['device_code'] ?? null)) {
        $deviceCode = trim((string) ($data['device_code'] ?? ''));
        if (is_array($selectedDevice) && trim((string) ($selectedDevice['device_code'] ?? '')) !== '') {
            $deviceCode = trim((string) $selectedDevice['device_code']);
        }
        $values[(string) $schema['device_code']] = $deviceCode === '' ? null : $deviceCode;
    }

    if (is_string($schema['device_name'] ?? null)) {
        $deviceName = trim((string) ($data['device_name'] ?? ''));
        if (is_array($selectedDevice) && trim((string) ($selectedDevice['device_name'] ?? '')) !== '') {
            $deviceName = trim((string) $selectedDevice['device_name']);
        }
        $values[(string) $schema['device_name']] = $deviceName === '' ? null : $deviceName;
    }

    if (is_string($schema['activity'] ?? null)) {
        $values[(string) $schema['activity']] = trim((string) ($data['activity'] ?? ''));
    }

    if (is_string($schema['notes'] ?? null)) {
        $notes = trim((string) ($data['notes'] ?? ''));
        $values[(string) $schema['notes']] = $notes === '' ? null : $notes;
    }

    if (is_string($schema['performed_by'] ?? null)) {
        $performedBy = trim((string) ($data['performed_by'] ?? ''));
        $values[(string) $schema['performed_by']] = $performedBy === '' ? null : $performedBy;
    }

    if (is_string($schema['admin_id'] ?? null) && $adminId !== null) {
        $values[(string) $schema['admin_id']] = $adminId;
    }

    if (is_string($schema['status'] ?? null)) {
        $status = trim((string) ($data['status'] ?? ''));
        $values[(string) $schema['status']] = $status === '' ? null : $status;
    }

    if (is_string($schema['maintenance_at'] ?? null)) {
        $dateTime = trim((string) ($data['maintenance_at'] ?? ''));
        $values[(string) $schema['maintenance_at']] = maintenanceDateDatabaseValue($schema, $dateTime);
    }

    if ($values === []) {
        throw new RuntimeException('Tidak ada kolom yang dapat disimpan pada tabel maintenance_logs.');
    }

    if ($id === null) {
        $columns = [];
        $placeholders = [];
        $parameters = [];

        foreach ($values as $column => $value) {
            $parameter = 'value_' . count($parameters);
            $columns[] = quoteMaintenanceIdentifier($column);
            $placeholders[] = ':' . $parameter;
            $parameters[$parameter] = $value;
        }

        $statement = $database->prepare(
            'INSERT INTO `maintenance_logs` ('
            . implode(', ', $columns)
            . ') VALUES ('
            . implode(', ', $placeholders)
            . ')'
        );
        $statement->execute($parameters);

        return (int) $database->lastInsertId();
    }

    $assignments = [];
    $parameters = ['id' => $id];

    foreach ($values as $column => $value) {
        $parameter = 'value_' . count($assignments);
        $assignments[] = quoteMaintenanceIdentifier($column) . ' = :' . $parameter;
        $parameters[$parameter] = $value;
    }

    $statement = $database->prepare(
        'UPDATE `maintenance_logs` SET '
        . implode(', ', $assignments)
        . ' WHERE '
        . quoteMaintenanceIdentifier((string) $schema['id'])
        . ' = :id'
    );
    $statement->execute($parameters);

    return $id;
}
