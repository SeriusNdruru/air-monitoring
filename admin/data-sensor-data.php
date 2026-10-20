<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sensor-data.php';


header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


/*
|--------------------------------------------------------------------------
| Pemeriksaan Sesi Admin
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
| Konfigurasi
|--------------------------------------------------------------------------
| PHP dan koneksi MySQL sudah menggunakan WIB / UTC+7.
| Karena itu waktu dari database tidak perlu dikonversi lagi dari UTC.
|--------------------------------------------------------------------------
*/
const SENSOR_API_DISPLAY_TIMEZONE = 'Asia/Jakarta';
const SENSOR_API_PER_PAGE = 25;


/*
|--------------------------------------------------------------------------
| Validasi Input Tanggal
|--------------------------------------------------------------------------
*/
function sensorApiValidDate(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value,
        new DateTimeZone(SENSOR_API_DISPLAY_TIMEZONE)
    );

    $errors = DateTimeImmutable::getLastErrors();

    return $date instanceof DateTimeImmutable
        && (
            $errors === false
            || (
                $errors['warning_count'] === 0
                && $errors['error_count'] === 0
            )
        )
        && $date->format('Y-m-d') === $value;
}


/*
|--------------------------------------------------------------------------
| Membuat Batas Tanggal untuk Query
|--------------------------------------------------------------------------
| Tanggal pengguna sudah menggunakan WIB.
| Koneksi MySQL juga menggunakan UTC+7 sehingga tidak ada konversi UTC.
|--------------------------------------------------------------------------
*/
function sensorApiDateBoundary(
    string $date,
    bool $nextDay = false
): string {
    if (
        $date === ''
        || !sensorApiValidDate($date)
    ) {
        return '';
    }

    $localDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone(SENSOR_API_DISPLAY_TIMEZONE)
    );

    if (!$localDate instanceof DateTimeImmutable) {
        return '';
    }

    if ($nextDay) {
        $localDate = $localDate->modify('+1 day');
    }

    return $localDate->format('Y-m-d H:i:s');
}


/*
|--------------------------------------------------------------------------
| Format Waktu
|--------------------------------------------------------------------------
| Nilai hasil query database dianggap sudah menggunakan WIB.
| Tidak dilakukan konversi UTC -> WIB lagi agar tidak terjadi +7 jam
| sebanyak dua kali.
|--------------------------------------------------------------------------
*/
function sensorApiFormatDateTime(?string $value): string
{
    if (
        $value === null
        || trim($value) === ''
    ) {
        return '-';
    }

    try {
        $dateTime = new DateTimeImmutable(
            trim($value),
            new DateTimeZone(
                SENSOR_API_DISPLAY_TIMEZONE
            )
        );

        return $dateTime->format(
            'd M Y H:i:s'
        );
    } catch (Throwable) {
        return $value;
    }
}


try {

    /*
    |--------------------------------------------------------------------------
    | Membaca Filter
    |--------------------------------------------------------------------------
    */
    $search = trim(
        (string) ($_GET['q'] ?? '')
    );

    $deviceIdInput = trim(
        (string) ($_GET['device_id'] ?? '')
    );

    $airStatus = strtolower(
        trim(
            (string) (
                $_GET['air_status']
                ?? ''
            )
        )
    );

    $dateFromInput = trim(
        (string) (
            $_GET['date_from']
            ?? ''
        )
    );

    $dateToInput = trim(
        (string) (
            $_GET['date_to']
            ?? ''
        )
    );


    /*
    |--------------------------------------------------------------------------
    | Halaman
    |--------------------------------------------------------------------------
    */
    $pageInput = filter_var(
        $_GET['page'] ?? 1,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    $page = $pageInput === false
        ? 1
        : (int) $pageInput;


    /*
    |--------------------------------------------------------------------------
    | Filter Perangkat
    |--------------------------------------------------------------------------
    */
    $deviceId = null;

    if ($deviceIdInput !== '') {

        $validatedDeviceId = filter_var(
            $deviceIdInput,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if ($validatedDeviceId !== false) {
            $deviceId =
                (int) $validatedDeviceId;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Filter Status Udara
    |--------------------------------------------------------------------------
    */
    if (
        !in_array(
            $airStatus,
            [
                '',
                'normal',
                'waspada',
                'bahaya'
            ],
            true
        )
    ) {
        $airStatus = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Validasi Tanggal
    |--------------------------------------------------------------------------
    */
    if (
        !sensorApiValidDate(
            $dateFromInput
        )
    ) {
        $dateFromInput = '';
    }

    if (
        !sensorApiValidDate(
            $dateToInput
        )
    ) {
        $dateToInput = '';
    }

    if (
        $dateFromInput !== ''
        && $dateToInput !== ''
        && $dateFromInput > $dateToInput
    ) {
        $dateFromInput = '';
        $dateToInput = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Menyiapkan Filter Query
    |--------------------------------------------------------------------------
    */
    $filters = [
        'search' => $search,
        'device_id' => $deviceId,
        'air_status' => $airStatus,

        'date_from' =>
            sensorApiDateBoundary(
                $dateFromInput
            ),

        'date_to_exclusive' =>
            sensorApiDateBoundary(
                $dateToInput,
                true
            ),
    ];


    /*
    |--------------------------------------------------------------------------
    | Koneksi Database
    |--------------------------------------------------------------------------
    */
    $database =
        getDatabaseConnection();


    /*
    |--------------------------------------------------------------------------
    | Ringkasan Data
    |--------------------------------------------------------------------------
    */
    $summary =
        getSensorReadingSummary(
            $database,
            $filters
        );


    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    $totalPages = max(
        1,
        (int) ceil(
            (
                (int) $summary['total']
            )
            / SENSOR_API_PER_PAGE
        )
    );


    if ($page > $totalPages) {
        $page = $totalPages;
    }


    $offset =
        ($page - 1)
        * SENSOR_API_PER_PAGE;


    /*
    |--------------------------------------------------------------------------
    | Membaca Data Sensor
    |--------------------------------------------------------------------------
    */
    $rows =
        getSensorReadings(
            $database,
            $filters,
            SENSOR_API_PER_PAGE,
            $offset
        );


    /*
    |--------------------------------------------------------------------------
    | Menyiapkan Data untuk JSON
    |--------------------------------------------------------------------------
    */
    $readings = array_map(
        static function (
            array $row
        ): array {

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

                'mq135_ppm' => $row['mq135_ppm'] === null ? null : (float)$row['mq135_ppm'],
                'air_status' =>
                    ($row['mq135_ppm'] === null ? 'Belum Terkalibrasi' : (string)($row['air_status'] ?? '-')),

                /*
                |--------------------------------------------------------------
                | Waktu Pembacaan
                |--------------------------------------------------------------
                | Langsung diformat sebagai WIB.
                */
                'recorded_at_label' =>
                    sensorApiFormatDateTime(
                        (string) (
                            $row[
                                'recorded_at'
                            ]
                            ?? ''
                        )
                    ),

                /*
                |--------------------------------------------------------------
                | Waktu Tersimpan
                |--------------------------------------------------------------
                | Nilai TIMESTAMP yang dibaca melalui koneksi MySQL dengan
                | session time_zone +07:00 juga sudah dikembalikan dalam WIB.
                */
                'created_at_label' =>
                    sensorApiFormatDateTime(
                        (string) (
                            $row[
                                'created_at'
                            ]
                            ?? ''
                        )
                    ),
            ];
        },
        $rows
    );


    /*
    |--------------------------------------------------------------------------
    | Informasi Pagination
    |--------------------------------------------------------------------------
    */
    $total =
        (int) (
            $summary['total']
            ?? 0
        );

    $startRecord =
        $total === 0
            ? 0
            : $offset + 1;

    $endRecord = min(
        $offset + count(
            $readings
        ),
        $total
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
                    SENSOR_API_DISPLAY_TIMEZONE
                )
            )
        )->format('H:i:s');


    /*
    |--------------------------------------------------------------------------
    | Respons JSON
    |--------------------------------------------------------------------------
    */
    echo json_encode(
        [
            'success' => true,

            'updated_at' =>
                $updatedAt
                . ' WIB',

            'poll_interval_seconds' =>
                10,

            'summary' => [
                'total' =>
                    $total,

                'normal' =>
                    (int) (
                        $summary['normal']
                        ?? 0
                    ),

                'waspada' =>
                    (int) (
                        $summary['waspada']
                        ?? 0
                    ),

                'bahaya' =>
                    (int) (
                        $summary['bahaya']
                        ?? 0
                    ),
            ],

            'readings' =>
                $readings,

            'pagination' => [
                'page' =>
                    $page,

                'per_page' =>
                    SENSOR_API_PER_PAGE,

                'total_pages' =>
                    $totalPages,

                'total' =>
                    $total,

                'offset' =>
                    $offset,

                'start_record' =>
                    $startRecord,

                'end_record' =>
                    $endRecord,
            ],
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );

} catch (Throwable $exception) {

    /*
    |--------------------------------------------------------------------------
    | Penanganan Kesalahan
    |--------------------------------------------------------------------------
    */
    error_log(
        'Sensor live data error: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' =>
                'Data sensor real-time gagal dimuat.',
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}