<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

/*
|--------------------------------------------------------------------------
| Konfigurasi Basis Data
|--------------------------------------------------------------------------
| Ganti DB_NAME, DB_USER, dan DB_PASSWORD sesuai informasi di Plesk.
| Jangan membagikan data ini kepada orang lain.
*/

define('DB_HOST', getenv('AQM_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('AQM_DB_PORT') ?: '3306');
define('DB_NAME', getenv('AQM_DB_NAME') ?: 'seriusndruru_air_quality_monitoring');
define('DB_USER', getenv('AQM_DB_USER') ?: '');
define('DB_PASSWORD', getenv('AQM_DB_PASSWORD') ?: '');

function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $connection = new PDO(
            $dsn,
            DB_USER,
            DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $connection->exec("SET time_zone = '+07:00'");

        return $connection;
    } catch (PDOException $exception) {
        error_log('Database connection error: ' . $exception->getMessage());

        throw new RuntimeException(
            'Koneksi basis data gagal. Periksa konfigurasi basis data.'
        );
    }
}