<?php

declare(strict_types=1);

const NORMAL_THRESHOLD_KEY = 'mq135_normal_max';
const WARNING_THRESHOLD_KEY = 'mq135_warning_max';

/**
 * @return array{
 *     normal_max:int,
 *     warning_max:int,
 *     normal_key:string,
 *     warning_key:string
 * }
 */
function getThresholdConfiguration(PDO $database): array
{
    $normalAliases = [
        NORMAL_THRESHOLD_KEY,
        'normal_max',
        'normal_threshold',
        'threshold_normal',
        'batas_normal',
    ];

    $warningAliases = [
        WARNING_THRESHOLD_KEY,
        'warning_max',
        'waspada_max',
        'warning_threshold',
        'threshold_warning',
        'batas_waspada',
    ];

    $statement = $database->query(
        'SELECT setting_key, setting_value FROM system_settings'
    );

    $rows = $statement->fetchAll();
    $settings = [];

    foreach ($rows as $row) {
        $originalKey = trim((string) ($row['setting_key'] ?? ''));

        if ($originalKey === '') {
            continue;
        }

        $settings[strtolower($originalKey)] = [
            'key' => $originalKey,
            'value' => trim((string) ($row['setting_value'] ?? '')),
        ];
    }

    $normal = findThresholdSetting($settings, $normalAliases, 1499);
    $warning = findThresholdSetting($settings, $warningAliases, 2499);

    $normalMax = max(0, min(4093, $normal['value']));
    $warningMax = max($normalMax + 1, min(4094, $warning['value']));

    return [
        'normal_max' => $normalMax,
        'warning_max' => $warningMax,
        'normal_key' => $normal['key'],
        'warning_key' => $warning['key'],
    ];
}

/**
 * @param array<string, array{key:string,value:string}> $settings
 * @param list<string> $aliases
 * @return array{key:string,value:int}
 */
function findThresholdSetting(
    array $settings,
    array $aliases,
    int $defaultValue
): array {
    foreach ($aliases as $alias) {
        $normalizedAlias = strtolower($alias);

        if (!isset($settings[$normalizedAlias])) {
            continue;
        }

        $rawValue = $settings[$normalizedAlias]['value'];

        if (preg_match('/^-?\d+$/', $rawValue) !== 1) {
            continue;
        }

        return [
            'key' => $settings[$normalizedAlias]['key'],
            'value' => (int) $rawValue,
        ];
    }

    return [
        'key' => $aliases[0],
        'value' => $defaultValue,
    ];
}

/**
 * @param array{normal_key:string,warning_key:string} $currentConfiguration
 */
function saveThresholdConfiguration(
    PDO $database,
    int $normalMax,
    int $warningMax,
    array $currentConfiguration
): void {
    $database->beginTransaction();

    try {
        saveSystemSetting(
            $database,
            $currentConfiguration['normal_key'],
            (string) $normalMax
        );

        saveSystemSetting(
            $database,
            $currentConfiguration['warning_key'],
            (string) $warningMax
        );

        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

function saveSystemSetting(
    PDO $database,
    string $settingKey,
    string $settingValue
): void {
    $checkStatement = $database->prepare(
        'SELECT setting_key
         FROM system_settings
         WHERE setting_key = :setting_key
         LIMIT 1'
    );

    $checkStatement->execute([
        'setting_key' => $settingKey,
    ]);

    if ($checkStatement->fetchColumn() !== false) {
        $updateStatement = $database->prepare(
            'UPDATE system_settings
             SET setting_value = :setting_value
             WHERE setting_key = :setting_key'
        );

        $updateStatement->execute([
            'setting_value' => $settingValue,
            'setting_key' => $settingKey,
        ]);

        return;
    }

    $insertStatement = $database->prepare(
        'INSERT INTO system_settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)'
    );

    $insertStatement->execute([
        'setting_key' => $settingKey,
        'setting_value' => $settingValue,
    ]);
}
