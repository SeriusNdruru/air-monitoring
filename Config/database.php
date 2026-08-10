<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

/**
 * Membuat dan mengembalikan koneksi PDO ke database.
 *
 * Koneksi disimpan dalam variabel statis agar hanya dibuat
 * satu kali selama satu proses permintaan PHP.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /*
    |--------------------------------------------------------------------------
    | Konfigurasi database
    |--------------------------------------------------------------------------
    | Ganti nilai berikut menggunakan data database dari Plesk.
    */
    $host = 'localhost';
    $port = 3306;

    $database = 'seriusndruru_air_quality_monitoring';
    $username = 'seriusndruru_airuser';
    $password = 'A~n6vLFk0~qik3de';

    /*
    |--------------------------------------------------------------------------
    | Data Source Name
    |--------------------------------------------------------------------------
    */
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    try {
        /*
        |--------------------------------------------------------------------------
        | Membuat koneksi PDO
        |--------------------------------------------------------------------------
        */
        $pdo = new PDO(
            $dsn,
            $username,
            $password,
            [
                /*
                 * PDO akan melempar exception ketika terjadi
                 * kesalahan database.
                 */
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                /*
                 * Hasil query secara bawaan berupa array asosiatif.
                 */
                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,

                /*
                 * Menggunakan prepared statement asli dari MySQL.
                 */
                PDO::ATTR_EMULATE_PREPARES =>
                    false,

                /*
                 * Nilai angka tidak selalu diubah menjadi string.
                 */
                PDO::ATTR_STRINGIFY_FETCHES =>
                    false
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Zona waktu sesi MySQL
        |--------------------------------------------------------------------------
        | Seluruh penggunaan NOW(), recorded_at, dan last_seen
        | menggunakan waktu UTC+7.
        */
        $pdo->exec("SET time_zone = '+07:00'");

        return $pdo;
    } catch (PDOException $exception) {
        /*
         * Detail kesalahan hanya disimpan pada log server.
         * Jangan menampilkan username, password, atau detail koneksi
         * kepada pengguna.
         */
        error_log(
            '[database.php] Koneksi database gagal: '
            . $exception->getMessage()
        );

        /*
         * Kesalahan diteruskan kepada file API yang memanggil db().
         * File seperti latest.php, history.php, dan receive-data.php
         * akan menghasilkan respons JSON masing-masing.
         */
        throw new RuntimeException(
            'Koneksi database gagal.',
            0,
            $exception
        );
    }
}