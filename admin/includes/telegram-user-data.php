<?php

declare(strict_types=1);

/**
 * Membaca struktur tabel telegram_users dan memetakan nama kolom aktual.
 *
 * @return array{
 *   columns:array<string,array<string,mixed>>,
 *   id:string,
 *   chat_id:string,
 *   name:?string,
 *   username:?string,
 *   status:?string,
 *   created_at:?string,
 *   updated_at:?string,
 *   active_value:int|string,
 *   inactive_value:int|string
 * }
 */
function getTelegramUserSchema(PDO $database): array
{
    $statement = $database->query('DESCRIBE telegram_users');
    $rows = $statement->fetchAll();

    if ($rows === []) {
        throw new RuntimeException('Tabel telegram_users tidak memiliki struktur kolom.');
    }

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

    $idColumn = findTelegramColumn($columns, [
        'id',
        'telegram_user_id',
        'user_id',
    ]);

    $chatIdColumn = findTelegramColumn($columns, [
        'chat_id',
        'telegram_chat_id',
        'telegram_id',
        'chatid',
    ]);

    if ($idColumn === null || $chatIdColumn === null) {
        $available = implode(', ', array_map(
            static fn (array $column): string => (string) $column['field'],
            array_values($columns)
        ));

        throw new RuntimeException(
            'Struktur telegram_users belum didukung. Kolom yang tersedia: ' . $available
        );
    }

    $statusColumn = findTelegramColumn($columns, [
        'status',
        'is_active',
        'active',
        'enabled',
    ]);

    [$activeValue, $inactiveValue] = resolveTelegramStatusValues(
        $statusColumn === null ? null : $columns[strtolower($statusColumn)]
    );

    return [
        'columns' => $columns,
        'id' => $idColumn,
        'chat_id' => $chatIdColumn,
        'name' => findTelegramColumn($columns, [
            'full_name',
            'name',
            'display_name',
            'nama',
        ]),
        'username' => findTelegramColumn($columns, [
            'telegram_username',
            'username',
            'user_name',
        ]),
        'status' => $statusColumn,
        'created_at' => findTelegramColumn($columns, [
            'created_at',
            'registered_at',
            'added_at',
            'created_on',
        ]),
        'updated_at' => findTelegramColumn($columns, [
            'updated_at',
            'modified_at',
            'updated_on',
        ]),
        'active_value' => $activeValue,
        'inactive_value' => $inactiveValue,
    ];
}

/**
 * @param array<string,array<string,mixed>> $columns
 * @param list<string> $aliases
 */
function findTelegramColumn(array $columns, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $normalized = strtolower($alias);

        if (isset($columns[$normalized])) {
            return (string) $columns[$normalized]['field'];
        }
    }

    return null;
}

/**
 * @param array<string,mixed>|null $column
 * @return array{0:int|string,1:int|string}
 */
function resolveTelegramStatusValues(?array $column): array
{
    if ($column === null) {
        return ['active', 'inactive'];
    }

    $type = strtolower((string) ($column['type'] ?? ''));

    if (
        str_contains($type, 'tinyint')
        || str_contains($type, 'smallint')
        || str_contains($type, 'int')
        || str_contains($type, 'bool')
        || str_contains($type, 'bit')
    ) {
        return [1, 0];
    }

    if (preg_match('/^enum\((.*)\)$/i', $type, $matches) === 1) {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $valueMatches);
        $values = array_map(
            static fn (string $value): string => stripcslashes($value),
            $valueMatches[1] ?? []
        );

        if ($values !== []) {
            $activeCandidates = ['active', 'aktif', 'enabled', 'on', '1'];
            $inactiveCandidates = ['inactive', 'tidak aktif', 'nonaktif', 'disabled', 'off', '0'];

            $active = findStatusEnumValue($values, $activeCandidates) ?? $values[0];
            $inactive = findStatusEnumValue($values, $inactiveCandidates)
                ?? ($values[1] ?? $values[0]);

            return [$active, $inactive];
        }
    }

    return ['active', 'inactive'];
}

/**
 * @param list<string> $values
 * @param list<string> $candidates
 */
function findStatusEnumValue(array $values, array $candidates): ?string
{
    foreach ($values as $value) {
        if (in_array(strtolower(trim($value)), $candidates, true)) {
            return $value;
        }
    }

    return null;
}

function quoteTelegramIdentifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Nama kolom basis data tidak valid.');
    }

    return '`' . $identifier . '`';
}

/** @param array<string,mixed> $schema */
function telegramSelectExpression(
    array $schema,
    string $schemaKey,
    string $alias
): string {
    $column = $schema[$schemaKey] ?? null;

    if (!is_string($column) || $column === '') {
        return 'NULL AS ' . quoteTelegramIdentifier($alias);
    }

    return quoteTelegramIdentifier($column)
        . ' AS '
        . quoteTelegramIdentifier($alias);
}

/**
 * @param array<string,mixed> $schema
 * @return list<array<string,mixed>>
 */
function getTelegramUsers(
    PDO $database,
    array $schema,
    string $search = ''
): array {
    $select = [
        telegramSelectExpression($schema, 'id', 'id'),
        telegramSelectExpression($schema, 'chat_id', 'chat_id'),
        telegramSelectExpression($schema, 'name', 'full_name'),
        telegramSelectExpression($schema, 'username', 'username'),
        telegramSelectExpression($schema, 'status', 'status'),
        telegramSelectExpression($schema, 'created_at', 'created_at'),
        telegramSelectExpression($schema, 'updated_at', 'updated_at'),
    ];

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM telegram_users';
    $parameters = [];

    if ($search !== '') {
        $searchColumns = [$schema['chat_id']];

        foreach (['name', 'username'] as $key) {
            if (is_string($schema[$key] ?? null)) {
                $searchColumns[] = $schema[$key];
            }
        }

        $conditions = [];

        foreach (array_unique($searchColumns) as $index => $column) {
            $parameterName = 'search_' . $index;
            $conditions[] = 'CAST(' . quoteTelegramIdentifier((string) $column) . ' AS CHAR) LIKE :' . $parameterName;
            $parameters[$parameterName] = '%' . $search . '%';
        }

        $sql .= ' WHERE (' . implode(' OR ', $conditions) . ')';
    }

    if (is_string($schema['created_at'] ?? null)) {
        $sql .= ' ORDER BY ' . quoteTelegramIdentifier($schema['created_at']) . ' DESC';
    } else {
        $sql .= ' ORDER BY ' . quoteTelegramIdentifier($schema['id']) . ' DESC';
    }

    $statement = $database->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll();
}

/**
 * @param array<string,mixed> $schema
 * @return array<string,mixed>|null
 */
function findTelegramUserById(
    PDO $database,
    array $schema,
    int $id
): ?array {
    $select = [
        telegramSelectExpression($schema, 'id', 'id'),
        telegramSelectExpression($schema, 'chat_id', 'chat_id'),
        telegramSelectExpression($schema, 'name', 'full_name'),
        telegramSelectExpression($schema, 'username', 'username'),
        telegramSelectExpression($schema, 'status', 'status'),
        telegramSelectExpression($schema, 'created_at', 'created_at'),
        telegramSelectExpression($schema, 'updated_at', 'updated_at'),
    ];

    $statement = $database->prepare(
        'SELECT ' . implode(', ', $select) . '
         FROM telegram_users
         WHERE ' . quoteTelegramIdentifier($schema['id']) . ' = :id
         LIMIT 1'
    );

    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/** @param array<string,mixed> $schema */
function telegramChatIdExists(
    PDO $database,
    array $schema,
    string $chatId,
    ?int $ignoreId = null
): bool {
    $sql = 'SELECT COUNT(*)
            FROM telegram_users
            WHERE ' . quoteTelegramIdentifier($schema['chat_id']) . ' = :chat_id';

    $parameters = ['chat_id' => $chatId];

    if ($ignoreId !== null) {
        $sql .= ' AND ' . quoteTelegramIdentifier($schema['id']) . ' <> :ignore_id';
        $parameters['ignore_id'] = $ignoreId;
    }

    $statement = $database->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn() > 0;
}

/** @param array<string,mixed> $schema */
function saveTelegramUser(
    PDO $database,
    array $schema,
    ?int $id,
    string $chatId,
    string $fullName,
    string $username
): int {
    $values = [
        $schema['chat_id'] => $chatId,
    ];

    if (is_string($schema['name'] ?? null)) {
        $values[$schema['name']] = $fullName;
    }

    if (is_string($schema['username'] ?? null)) {
        $values[$schema['username']] = $username === '' ? null : $username;
    }

    if ($id === null && is_string($schema['status'] ?? null)) {
        $values[$schema['status']] = $schema['active_value'];
    }

    if ($id === null) {
        $columns = [];
        $placeholders = [];
        $parameters = [];

        foreach ($values as $column => $value) {
            $parameterName = 'value_' . count($parameters);
            $columns[] = quoteTelegramIdentifier((string) $column);
            $placeholders[] = ':' . $parameterName;
            $parameters[$parameterName] = $value;
        }

        $statement = $database->prepare(
            'INSERT INTO telegram_users (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);

        return (int) $database->lastInsertId();
    }

    $assignments = [];
    $parameters = ['id' => $id];

    foreach ($values as $column => $value) {
        $parameterName = 'value_' . count($assignments);
        $assignments[] = quoteTelegramIdentifier((string) $column) . ' = :' . $parameterName;
        $parameters[$parameterName] = $value;
    }

    if (is_string($schema['updated_at'] ?? null)) {
        $assignments[] = quoteTelegramIdentifier($schema['updated_at']) . ' = NOW()';
    }

    $statement = $database->prepare(
        'UPDATE telegram_users
         SET ' . implode(', ', $assignments) . '
         WHERE ' . quoteTelegramIdentifier($schema['id']) . ' = :id'
    );
    $statement->execute($parameters);

    return $id;
}

/** @param array<string,mixed> $schema */
function toggleTelegramUserStatus(
    PDO $database,
    array $schema,
    int $id
): void {
    if (!is_string($schema['status'] ?? null)) {
        throw new RuntimeException('Tabel telegram_users tidak memiliki kolom status.');
    }

    $user = findTelegramUserById($database, $schema, $id);

    if ($user === null) {
        throw new RuntimeException('Pengguna Telegram tidak ditemukan.');
    }

    $newValue = telegramStatusIsActive($user['status'] ?? null, $schema)
        ? $schema['inactive_value']
        : $schema['active_value'];

    $assignments = [quoteTelegramIdentifier($schema['status']) . ' = :status'];

    if (is_string($schema['updated_at'] ?? null)) {
        $assignments[] = quoteTelegramIdentifier($schema['updated_at']) . ' = NOW()';
    }

    $statement = $database->prepare(
        'UPDATE telegram_users
         SET ' . implode(', ', $assignments) . '
         WHERE ' . quoteTelegramIdentifier($schema['id']) . ' = :id'
    );

    $statement->execute([
        'status' => $newValue,
        'id' => $id,
    ]);
}

/** @param array<string,mixed> $schema */
function telegramStatusIsActive(mixed $status, array $schema): bool
{
    if (!is_string($schema['status'] ?? null)) {
        return true;
    }

    if (is_int($schema['active_value'])) {
        return (int) $status === $schema['active_value'];
    }

    return strtolower(trim((string) $status))
        === strtolower(trim((string) $schema['active_value']));
}
