<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi Basis Data
|--------------------------------------------------------------------------
| Ganti DB_NAME, DB_USER, dan DB_PASSWORD sesuai informasi di Plesk.
| Jangan membagikan data ini kepada orang lain.
*/

const DB_HOST = 'localhost';
const DB_PORT = '3306';
const DB_NAME = 'seriusndruru_air_quality_monitoring';
const DB_USER = 'seriusndruru_airuser';
const DB_PASSWORD = 'A~n6vLFk0~qik3de';

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

        return $connection;
    } catch (PDOException $exception) {
        error_log('Database connection error: ' . $exception->getMessage());

        throw new RuntimeException(
            'Koneksi basis data gagal. Periksa konfigurasi basis data.'
        );
    }
}