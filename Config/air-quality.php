<?php
declare(strict_types=1);
/**
 * Kategori aplikasi berbasis estimasi CO2 ekuivalen (ppm), BUKAN klasifikasi ISPU.
 * Data ADC historis sengaja tidak dipetakan menjadi ppm.
 * Batas 300/700 ppm hanya contoh konfigurasi internal dan harus disetujui peneliti.
 */
const DEFAULT_WARNING_START_PPM = 300.0;
const DEFAULT_DANGER_START_PPM = 700.0;
const AIR_STATUS_PENDING = 'Belum Terkalibrasi';

function airThresholds(PDO $pdo): array
{
    $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('mq135_warning_start_ppm', 'mq135_danger_start_ppm')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $warning = isset($rows['mq135_warning_start_ppm']) ? (float) $rows['mq135_warning_start_ppm'] : DEFAULT_WARNING_START_PPM;
    $danger = isset($rows['mq135_danger_start_ppm']) ? (float) $rows['mq135_danger_start_ppm'] : DEFAULT_DANGER_START_PPM;
    if (!is_finite($warning) || !is_finite($danger) || $warning <= 0 || $danger <= $warning || $danger > 100000) {
        throw new RuntimeException('Ambang PPM tidak valid.');
    }
    return ['warning_start_ppm' => $warning, 'danger_start_ppm' => $danger];
}

function airCategory(?float $ppm, array $thresholds): string
{
    if ($ppm === null) return AIR_STATUS_PENDING;
    if (!is_finite($ppm) || $ppm < 0 || $ppm > 1000000) {
        throw new InvalidArgumentException('Nilai ppm di luar rentang yang diterima.');
    }
    if ($ppm >= $thresholds['danger_start_ppm']) return 'Bahaya';
    if ($ppm >= $thresholds['warning_start_ppm']) return 'Waspada';
    return 'Normal';
}

/** API menerima null selama perangkat belum dikalibrasi. */
function parsePpm(mixed $value): ?float
{
    if ($value === null) return null;
    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
        throw new InvalidArgumentException('mq135_ppm harus angka atau null.');
    }
    $ppm = (float) $value;
    if (!is_finite($ppm) || $ppm < 0 || $ppm > 1000000) {
        throw new InvalidArgumentException('mq135_ppm harus 0 sampai 1000000.');
    }
    return round($ppm, 2);
}
