<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard-data.php';


/*
|--------------------------------------------------------------------------
| Header Respons
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


/*
|--------------------------------------------------------------------------
| Pemeriksaan Sesi Administrator
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['admin_id'])) {

    http_response_code(401);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Sesi admin berakhir. Silakan login kembali.',
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Zona Waktu
|--------------------------------------------------------------------------
| PHP dan sesi koneksi MySQL sudah menggunakan WIB / UTC+7.
| Oleh karena itu waktu hasil query database langsung diperlakukan
| sebagai Asia/Jakarta dan tidak dikonversi lagi dari UTC.
|--------------------------------------------------------------------------
*/
const DISPLAY_TIMEZONE = 'Asia/Jakarta';


/*
|--------------------------------------------------------------------------
| Membaca Waktu dari Database
|--------------------------------------------------------------------------
*/
function apiLocalDateTime(
    ?string $value
): ?DateTimeImmutable {

    if (
        $value === null
        || trim($value) === ''
    ) {
        return null;
    }

    try {

        return new DateTimeImmutable(
            trim($value),
            new DateTimeZone(
                DISPLAY_TIMEZONE
            )
        );

    } catch (Throwable) {

        return null;
    }
}


/*
|--------------------------------------------------------------------------
| Format Waktu untuk Tampilan
|--------------------------------------------------------------------------
*/
function apiDateTime(
    ?string $value
): string {

    $date = apiLocalDateTime(
        $value
    );

    return $date instanceof DateTimeImmutable
        ? $date->format(
            'd M Y H:i:s'
        )
        : (
            $value === null
            || trim($value) === ''
                ? '-'
                : $value
        );
}


try {

    /*
    |--------------------------------------------------------------------------
    | Koneksi Basis Data
    |--------------------------------------------------------------------------
    */
    $database =
        getDatabaseConnection();


    /*
    |--------------------------------------------------------------------------
    | Mengambil Data Dashboard
    |--------------------------------------------------------------------------
    */
    $dashboard =
        getDashboardData(
            $database
        );


    /*
    |--------------------------------------------------------------------------
    | Data Grafik
    |--------------------------------------------------------------------------
    */
    $rows =
        $dashboard[
            'chart_readings'
        ];


    /*
    |--------------------------------------------------------------------------
    | Menyiapkan Data Grafik
    |--------------------------------------------------------------------------
    */
    $readings = array_map(

        static function (
            array $row
        ): array {

            $rawTime = (string) (
                $row['recorded_at']
                ?? $row['created_at']
                ?? ''
            );

            $timestamp = null;
            $label = '-';
            $fullLabel = '-';


            /*
            |--------------------------------------------------------------------------
            | Format Waktu
            |--------------------------------------------------------------------------
            | Waktu hasil query sudah WIB.
            */
            if ($rawTime !== '') {

                $date =
                    apiLocalDateTime(
                        $rawTime
                    );

                if (
                    $date instanceof
                    DateTimeImmutable
                ) {

                    /*
                    |--------------------------------------------------------------
                    | Format ISO 8601
                    |--------------------------------------------------------------
                    | Contoh:
                    | 2026-08-11T03:37:00+07:00
                    */
                    $timestamp =
                        $date->format(
                            DateTimeInterface::ATOM
                        );


                    /*
                    |--------------------------------------------------------------
                    | Label Pendek untuk Grafik
                    |--------------------------------------------------------------
                    */
                    $label =
                        $date->format(
                            'H:i:s'
                        );


                    /*
                    |--------------------------------------------------------------
                    | Label Lengkap
                    |--------------------------------------------------------------
                    */
                    $fullLabel =
                        $date->format(
                            'd M Y H:i:s'
                        );

                } else {

                    $timestamp =
                        $rawTime;

                    $label =
                        $rawTime;

                    $fullLabel =
                        $rawTime;
                }
            }


            return [

                'id' =>
                    (int) (
                        $row['id']
                        ?? 0
                    ),

                'mq135_raw' =>
                    max(
                        0,
                        min(
                            4095,
                            (int) (
                                $row[
                                    'mq135_raw'
                                ]
                                ?? 0
                            )
                        )
                    ),

                'air_status' =>
                    (string) (
                        $row[
                            'air_status'
                        ]
                        ?? '-'
                    ),

                'timestamp' =>
                    $timestamp,

                'label' =>
                    $label,

                'full_label' =>
                    $fullLabel,
            ];
        },

        $rows
    );


    /*
    |--------------------------------------------------------------------------
    | Data Perangkat
    |--------------------------------------------------------------------------
    */
    $devices = array_map(

        static function (
            array $device
        ): array {

            $deviceStatus =
                strtolower(
                    trim(
                        (string) (
                            $device['status']
                            ?? 'offline'
                        )
                    )
                );


            return [

                'id' =>
                    (int) (
                        $device['id']
                        ?? 0
                    ),

                'device_code' =>
                    (string) (
                        $device[
                            'device_code'
                        ]
                        ?? '-'
                    ),

                'device_name' =>
                    (string) (
                        $device[
                            'device_name'
                        ]
                        ?? '-'
                    ),

                'location' =>
                    (string) (
                        $device[
                            'location'
                        ]
                        ?? '-'
                    ),


                /*
                |--------------------------------------------------------------------------
                | Status Online / Offline
                |--------------------------------------------------------------------------
                */
                'status' =>
                    $deviceStatus === 'online'
                        ? 'online'
                        : 'offline',


                /*
                |--------------------------------------------------------------------------
                | Waktu Terakhir Aktif
                |--------------------------------------------------------------------------
                */
                'last_seen' =>
                    (string) (
                        $device[
                            'last_seen'
                        ]
                        ?? ''
                    ),

                'last_seen_label' =>
                    apiDateTime(
                        (string) (
                            $device[
                                'last_seen'
                            ]
                            ?? ''
                        )
                    ),
            ];
        },

        $dashboard[
            'devices'
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Sepuluh Pembacaan Terbaru
    |--------------------------------------------------------------------------
    */
    $recentReadings = array_map(

        static function (
            array $row
        ): array {

            $rawTime = (string) (
                $row['recorded_at']
                ?? $row['created_at']
                ?? ''
            );

            $timestamp = null;
            $fullLabel = '-';


            if ($rawTime !== '') {

                $date =
                    apiLocalDateTime(
                        $rawTime
                    );

                if (
                    $date instanceof
                    DateTimeImmutable
                ) {

                    $timestamp =
                        $date->format(
                            DateTimeInterface::ATOM
                        );

                    $fullLabel =
                        $date->format(
                            'd M Y H:i:s'
                        );

                } else {

                    $timestamp =
                        $rawTime;

                    $fullLabel =
                        $rawTime;
                }
            }


            return [

                'id' =>
                    (int) (
                        $row['id']
                        ?? 0
                    ),

                'device_code' =>
                    (string) (
                        $row[
                            'device_code'
                        ]
                        ?? '-'
                    ),

                'device_name' =>
                    (string) (
                        $row[
                            'device_name'
                        ]
                        ?? '-'
                    ),

                'location' =>
                    (string) (
                        $row[
                            'location'
                        ]
                        ?? '-'
                    ),

                'mq135_raw' =>
                    max(
                        0,
                        min(
                            4095,
                            (int) (
                                $row[
                                    'mq135_raw'
                                ]
                                ?? 0
                            )
                        )
                    ),

                'air_status' =>
                    (string) (
                        $row[
                            'air_status'
                        ]
                        ?? '-'
                    ),

                'timestamp' =>
                    $timestamp,

                'full_label' =>
                    $fullLabel,
            ];
        },

        $dashboard[
            'recent_readings'
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Pembacaan Terbaru
    |--------------------------------------------------------------------------
    */
    $latest =
        $readings === []
            ? null
            : $readings[
                array_key_last(
                    $readings
                )
            ];


    /*
    |--------------------------------------------------------------------------
    | Waktu Pembaruan API
    |--------------------------------------------------------------------------
    */
    $updatedAt =
        (
            new DateTimeImmutable(
                'now',
                new DateTimeZone(
                    DISPLAY_TIMEZONE
                )
            )
        )->format(
            'd M Y H:i:s'
        );


    /*
    |--------------------------------------------------------------------------
    | Respons JSON
    |--------------------------------------------------------------------------
    */
    echo json_encode(
        [
            'success' => true,

            /*
            |--------------------------------------------------------------------------
            | Informasi Pembaruan
            |--------------------------------------------------------------------------
            */
            'updated_at' =>
                $updatedAt
                . ' WIB',

            /*
            |--------------------------------------------------------------------------
            | Data Grafik
            |--------------------------------------------------------------------------
            */
            'count' =>
                count(
                    $readings
                ),

            'limit' =>
                30,

            'latest' =>
                $latest,

            'readings' =>
                $readings,


            /*
            |--------------------------------------------------------------------------
            | Riwayat Terbaru
            |--------------------------------------------------------------------------
            */
            'recent_readings' =>
                $recentReadings,


            /*
            |--------------------------------------------------------------------------
            | Ringkasan Dashboard
            |--------------------------------------------------------------------------
            */
            'summary' => [

                'total_devices' =>
                    (int) (
                        $dashboard[
                            'total_devices'
                        ]
                        ?? 0
                    ),

                'active_devices' =>
                    (int) (
                        $dashboard[
                            'active_devices'
                        ]
                        ?? 0
                    ),

                'system_status' =>
                    (string) (
                        $dashboard[
                            'system_status'
                        ]
                        ?? 'Tidak Aktif'
                    ),

                'notification_count_today' =>
                    (int) (
                        $dashboard[
                            'notification_count_today'
                        ]
                        ?? 0
                    ),
            ],


            /*
            |--------------------------------------------------------------------------
            | Status Perangkat
            |--------------------------------------------------------------------------
            */
            'devices' =>
                $devices,

            'online_timeout_seconds' =>
                DEVICE_ONLINE_TIMEOUT_SECONDS,
        ],

        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );

} catch (Throwable $exception) {

    /*
    |--------------------------------------------------------------------------
    | Log Kesalahan
    |--------------------------------------------------------------------------
    */
    error_log(
        'Dashboard live data error: '
        . $exception->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | Respons Kesalahan
    |--------------------------------------------------------------------------
    */
    http_response_code(
        500
    );


    echo json_encode(
        [
            'success' => false,

            'message' =>
                'Data dashboard gagal dimuat.',
        ],

        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}