<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/device-data.php';


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
| PHP dan koneksi MySQL sudah menggunakan WIB / UTC+7.
| Nilai waktu dari hasil query database tidak dikonversi lagi dari UTC.
|--------------------------------------------------------------------------
*/
const DEVICE_DISPLAY_TIMEZONE = 'Asia/Jakarta';


/*
|--------------------------------------------------------------------------
| Mengubah Nilai Database Menjadi DateTime WIB
|--------------------------------------------------------------------------
*/
function deviceApiLocalDateTime(
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
                DEVICE_DISPLAY_TIMEZONE
            )
        );

    } catch (Throwable) {

        return null;
    }
}


/*
|--------------------------------------------------------------------------
| Format Tanggal dan Waktu
|--------------------------------------------------------------------------
*/
function deviceApiDateTime(
    ?string $value
): string {

    $date =
        deviceApiLocalDateTime(
            $value
        );

    return $date instanceof DateTimeImmutable
        ? $date->format(
            'd M Y H:i:s'
        )
        : '-';
}


try {

    /*
    |--------------------------------------------------------------------------
    | Koneksi Database
    |--------------------------------------------------------------------------
    */
    $database =
        getDatabaseConnection();


    /*
    |--------------------------------------------------------------------------
    | Filter
    |--------------------------------------------------------------------------
    */
    $search = trim(
        (string) (
            $_GET['q']
            ?? ''
        )
    );

    $status =
        normalizeDeviceStatusFilter(
            (string) (
                $_GET['status']
                ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | Mengambil Data Perangkat
    |--------------------------------------------------------------------------
    */
    $devices =
        getDevicesWithLatestReading(
            $database,
            $search,
            $status
        );


    /*
    |--------------------------------------------------------------------------
    | Menyiapkan Payload Perangkat
    |--------------------------------------------------------------------------
    */
    $payload = array_map(
        static function (
            array $device
        ): array {

            $computedStatus =
                strtolower(
                    trim(
                        (string) (
                            $device['status']
                            ?? 'offline'
                        )
                    )
                );


            return [

                /*
                |--------------------------------------------------------------------------
                | Identitas Perangkat
                |--------------------------------------------------------------------------
                */
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
                    $computedStatus === 'online'
                        ? 'online'
                        : 'offline',


                /*
                |--------------------------------------------------------------------------
                | Nilai Sensor Terbaru
                |--------------------------------------------------------------------------
                */
                'mq135_raw' =>
                    isset(
                        $device['mq135_raw']
                    )
                        ? (int) $device[
                            'mq135_raw'
                        ]
                        : null,


                /*
                |--------------------------------------------------------------------------
                | Status Kondisi Udara
                |--------------------------------------------------------------------------
                */
                'air_status' =>
                    (string) (
                        $device[
                            'air_status'
                        ]
                        ?? '-'
                    ),


                /*
                |--------------------------------------------------------------------------
                | Waktu Terakhir Perangkat Aktif
                |--------------------------------------------------------------------------
                | Nilai dari database sudah menggunakan WIB.
                */
                'last_seen' =>
                    (string) (
                        $device[
                            'last_seen'
                        ]
                        ?? ''
                    ),

                'last_seen_label' =>
                    deviceApiDateTime(
                        isset(
                            $device[
                                'last_seen'
                            ]
                        )
                            ? (string) $device[
                                'last_seen'
                            ]
                            : null
                    ),
            ];
        },
        $devices
    );


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
                    DEVICE_DISPLAY_TIMEZONE
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

            'count' =>
                count($payload),

            'devices' =>
                $payload,

            'online_timeout_seconds' =>
                DEVICE_ONLINE_TIMEOUT_SECONDS,

            'updated_at' =>
                $updatedAt,
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
        'Device live data error: '
        . $exception->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | Respons Kesalahan
    |--------------------------------------------------------------------------
    */
    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' =>
                'Data perangkat gagal dimuat.',
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}