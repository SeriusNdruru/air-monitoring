<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sensor-data.php';

requireAdmin();

$database = getDatabaseConnection();
$adminName = adminDisplayName();
$errors = [];

// Waktu pada basis data disimpan dalam UTC dan ditampilkan sebagai WIB.
const DATABASE_TIMEZONE = 'UTC';
const DISPLAY_TIMEZONE = 'Asia/Jakarta';

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        $databaseTime = new DateTimeImmutable(
            trim($value),
            new DateTimeZone(DATABASE_TIMEZONE)
        );

        return $databaseTime
            ->setTimezone(new DateTimeZone(DISPLAY_TIMEZONE))
            ->format('d M Y H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

/**
 * Mengubah batas tanggal yang dipilih dalam WIB menjadi UTC untuk kueri basis data.
 */
function localDateBoundaryToDatabase(string $date, bool $nextDay = false): string
{
    if ($date === '') {
        return '';
    }

    $localDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone(DISPLAY_TIMEZONE)
    );

    if (!$localDate instanceof DateTimeImmutable) {
        return '';
    }

    if ($nextDay) {
        $localDate = $localDate->modify('+1 day');
    }

    return $localDate
        ->setTimezone(new DateTimeZone(DATABASE_TIMEZONE))
        ->format('Y-m-d H:i:s');
}

function badgeClass(string $status): string
{
    return match (strtolower(trim($status))) {
        'normal' => 'badge badge-success',
        'waspada' => 'badge badge-warning',
        'bahaya' => 'badge badge-danger',
        default => 'badge badge-neutral',
    };
}

function validDateInput(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date instanceof DateTimeImmutable
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function pageUrl(int $page, array $parameters): string
{
    $parameters['page'] = $page;

    return 'data-sensor.php?' . http_build_query(
        array_filter(
            $parameters,
            static fn (mixed $value): bool => $value !== '' && $value !== null
        )
    );
}

$search = trim((string) ($_GET['q'] ?? ''));
$deviceIdInput = trim((string) ($_GET['device_id'] ?? ''));
$airStatus = strtolower(trim((string) ($_GET['air_status'] ?? '')));
$dateFromInput = trim((string) ($_GET['date_from'] ?? ''));
$dateToInput = trim((string) ($_GET['date_to'] ?? ''));
$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$page = $page === false ? 1 : (int) $page;
$perPage = 25;

$deviceId = null;
if ($deviceIdInput !== '') {
    $validatedDeviceId = filter_var(
        $deviceIdInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($validatedDeviceId === false) {
        $errors[] = 'Pilihan perangkat tidak valid.';
    } else {
        $deviceId = (int) $validatedDeviceId;
    }
}

if (!in_array($airStatus, ['', 'normal', 'waspada', 'bahaya'], true)) {
    $airStatus = '';
    $errors[] = 'Status udara tidak valid.';
}

if (!validDateInput($dateFromInput)) {
    $errors[] = 'Tanggal mulai tidak valid.';
    $dateFromInput = '';
}

if (!validDateInput($dateToInput)) {
    $errors[] = 'Tanggal akhir tidak valid.';
    $dateToInput = '';
}

if ($dateFromInput !== '' && $dateToInput !== '' && $dateFromInput > $dateToInput) {
    $errors[] = 'Tanggal mulai tidak boleh lebih besar dari tanggal akhir.';
    $dateFromInput = '';
    $dateToInput = '';
}

$dateFrom = localDateBoundaryToDatabase($dateFromInput);
$dateToExclusive = localDateBoundaryToDatabase($dateToInput, true);

$filters = [
    'search' => $search,
    'device_id' => $deviceId,
    'air_status' => $airStatus,
    'date_from' => $dateFrom,
    'date_to_exclusive' => $dateToExclusive,
];

try {
    $deviceOptions = getSensorDeviceOptions($database);
    $summary = getSensorReadingSummary($database, $filters);

    $totalPages = max(1, (int) ceil($summary['total'] / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;
    $readings = getSensorReadings($database, $filters, $perPage, $offset);
} catch (Throwable $exception) {
    error_log('Sensor data page error: ' . $exception->getMessage());
    $deviceOptions = [];
    $summary = ['total' => 0, 'normal' => 0, 'waspada' => 0, 'bahaya' => 0];
    $totalPages = 1;
    $offset = 0;
    $readings = [];
    $errors[] = 'Data sensor belum dapat ditampilkan.';
}

$queryParameters = [
    'q' => $search,
    'device_id' => $deviceIdInput,
    'air_status' => $airStatus,
    'date_from' => $dateFromInput,
    'date_to' => $dateToInput,
];

$startRecord = $summary['total'] === 0 ? 0 : $offset + 1;
$endRecord = min($offset + count($readings), $summary['total']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Sensor | Dashboard Admin</title>
	<link
        rel="icon"
        type="image/png"
        href="/assets/images/AQM.png?v=1"
    >

    <link
        rel="apple-touch-icon"
        href="/assets/images/AQM.png?v=1"
    >
    <link rel="stylesheet" href="assets/css/admin.css?v=20260728-1">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-mark">IoT</div>
                <div>
                    <strong>Monitoring</strong>
                    <span>Kualitas Udara</span>
                </div>
            </div>

            <nav class="sidebar-nav" aria-label="Navigasi dashboard admin">
                <a class="nav-link" href="index.php">Beranda</a>
                <a class="nav-link" href="perangkat.php">Perangkat</a>
                <a class="nav-link active" href="data-sensor.php">Data Sensor</a>
                <a class="nav-link" href="ambang-batas.php">Ambang Batas</a>
                <a class="nav-link" href="pengguna-telegram.php">Pengguna Telegram</a>
                <a class="nav-link" href="riwayat-notifikasi.php">Riwayat Notifikasi</a>
                <a class="nav-link" href="pemeliharaan.php">Pemeliharaan</a>
            </nav>

            <form class="logout-form" method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()); ?>">
                <button type="submit" class="logout-button">Keluar</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <p class="eyebrow">Riwayat pembacaan nilai ADC MQ135</p>
                    <h1>Data Sensor</h1>
                </div>

                <div class="admin-profile">
                    <div class="admin-avatar">A</div>
                    <div>
                        <strong><?= escapeHtml($adminName); ?></strong>
                        <span>Administrator</span>
                    </div>
                </div>
            </header>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= escapeHtml($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="summary-grid sensor-summary-grid" aria-label="Ringkasan data sensor">
                <article class="summary-card">
                    <span class="summary-label">Total Data</span>
                    <strong id="summary-total"><?= escapeHtml($summary['total']); ?></strong>
                    <small>Sesuai filter aktif</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Status Normal</span>
                    <strong id="summary-normal"><?= escapeHtml($summary['normal']); ?></strong>
                    <small>Nilai ADC 0 sampai 1499</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Status Waspada</span>
                    <strong id="summary-waspada"><?= escapeHtml($summary['waspada']); ?></strong>
                    <small>Nilai ADC 1500 sampai 2499</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Status Bahaya</span>
                    <strong id="summary-bahaya"><?= escapeHtml($summary['bahaya']); ?></strong>
                    <small>Nilai ADC 2500 sampai 4095</small>
                </article>
            </section>

            <section class="panel panel-full">
                <div class="panel-header">
                    <div>
                        <h2>Riwayat Pembacaan Sensor</h2>
                        <p>Data ditampilkan dari pembacaan terbaru ke terlama. Waktu menggunakan WIB.</p>
                    </div>
                    <span class="record-count" id="sensor-live-status"><?= escapeHtml($summary['total']); ?> data · real-time aktif</span>
                </div>

                <form class="filter-bar sensor-filter-bar" method="get" action="data-sensor.php">
                    <label>
                        <span>Cari perangkat</span>
                        <input
                            type="search"
                            name="q"
                            value="<?= escapeHtml($search); ?>"
                            placeholder="Kode, nama, atau lokasi"
                        >
                    </label>

                    <label>
                        <span>Perangkat</span>
                        <select name="device_id">
                            <option value="">Semua perangkat</option>
                            <?php foreach ($deviceOptions as $device): ?>
                                <option
                                    value="<?= escapeHtml($device['id'] ?? ''); ?>"
                                    <?= $deviceId === (int) ($device['id'] ?? 0) ? 'selected' : ''; ?>
                                >
                                    <?= escapeHtml(
                                        ($device['device_name'] ?? '-')
                                        . ' (' . ($device['device_code'] ?? '-') . ')'
                                    ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Status udara</span>
                        <select name="air_status">
                            <option value="">Semua status</option>
                            <option value="normal" <?= $airStatus === 'normal' ? 'selected' : ''; ?>>Normal</option>
                            <option value="waspada" <?= $airStatus === 'waspada' ? 'selected' : ''; ?>>Waspada</option>
                            <option value="bahaya" <?= $airStatus === 'bahaya' ? 'selected' : ''; ?>>Bahaya</option>
                        </select>
                    </label>

                    <label>
                        <span>Tanggal mulai</span>
                        <input type="date" name="date_from" value="<?= escapeHtml($dateFromInput); ?>">
                    </label>

                    <label>
                        <span>Tanggal akhir</span>
                        <input type="date" name="date_to" value="<?= escapeHtml($dateToInput); ?>">
                    </label>

                    <div class="filter-actions">
                        <button class="button button-primary" type="submit">Terapkan</button>
                        <a class="button button-secondary" href="data-sensor.php">Reset</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Kode Perangkat</th>
                                <th>Nama Perangkat</th>
                                <th>Lokasi</th>
                                <th>Nilai ADC MQ135</th>
                                <th>Status Udara</th>
                                <th>Waktu Pembacaan</th>
                                <th>Waktu Tersimpan</th>
                            </tr>
                        </thead>
                        <tbody id="sensor-reading-body">
                            <?php if ($readings === []): ?>
                                <tr>
                                    <td colspan="8" class="empty-cell">Data pembacaan sensor tidak ditemukan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($readings as $index => $reading): ?>
                                    <tr>
                                        <td><?= escapeHtml($offset + $index + 1); ?></td>
                                        <td><?= escapeHtml($reading['device_code'] ?? '-'); ?></td>
                                        <td><?= escapeHtml($reading['device_name'] ?? '-'); ?></td>
                                        <td><?= escapeHtml($reading['location'] ?? '-'); ?></td>
                                        <td><strong><?= escapeHtml($reading['mq135_raw'] ?? '-'); ?></strong></td>
                                        <td>
                                            <span class="<?= escapeHtml(badgeClass((string) ($reading['air_status'] ?? ''))); ?>">
                                                <?= escapeHtml($reading['air_status'] ?? '-'); ?>
                                            </span>
                                        </td>
                                        <td><?= escapeHtml(formatDateTime((string) ($reading['recorded_at'] ?? ''))); ?></td>
                                        <td><?= escapeHtml(formatDateTime((string) ($reading['created_at'] ?? ''))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer" id="sensor-table-footer">
                    <p>
                        Menampilkan <?= escapeHtml($startRecord); ?> sampai <?= escapeHtml($endRecord); ?>
                        dari <?= escapeHtml($summary['total']); ?> data.
                    </p>

                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination" aria-label="Halaman data sensor">
                            <?php if ($page > 1): ?>
                                <a href="<?= escapeHtml(pageUrl($page - 1, $queryParameters)); ?>">Sebelumnya</a>
                            <?php endif; ?>

                            <?php
                            $firstPage = max(1, $page - 2);
                            $lastPage = min($totalPages, $page + 2);
                            ?>

                            <?php for ($pageNumber = $firstPage; $pageNumber <= $lastPage; $pageNumber++): ?>
                                <a
                                    class="<?= $pageNumber === $page ? 'active' : ''; ?>"
                                    href="<?= escapeHtml(pageUrl($pageNumber, $queryParameters)); ?>"
                                >
                                    <?= escapeHtml($pageNumber); ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?= escapeHtml(pageUrl($page + 1, $queryParameters)); ?>">Berikutnya</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <script>
        (() => {
            const POLL_INTERVAL = 10000;
            const endpoint = 'data-sensor-data.php';
            const tableBody = document.getElementById('sensor-reading-body');
            const tableFooter = document.getElementById('sensor-table-footer');
            const liveStatus = document.getElementById('sensor-live-status');
            const summaryElements = {
                total: document.getElementById('summary-total'),
                normal: document.getElementById('summary-normal'),
                waspada: document.getElementById('summary-waspada'),
                bahaya: document.getElementById('summary-bahaya')
            };

            let requestRunning = false;
            let timerId = null;

            const escapeHtml = (value) => String(value ?? '-')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

            const badgeClass = (status) => {
                switch (String(status ?? '').trim().toLowerCase()) {
                    case 'normal': return 'badge badge-success';
                    case 'waspada': return 'badge badge-warning';
                    case 'bahaya': return 'badge badge-danger';
                    default: return 'badge badge-neutral';
                }
            };

            const buildEndpointUrl = () => {
                const params = new URLSearchParams(window.location.search);
                params.set('_', Date.now().toString());
                return `${endpoint}?${params.toString()}`;
            };

            const createPageUrl = (page) => {
                const params = new URLSearchParams(window.location.search);
                params.set('page', String(page));
                params.delete('_');
                return `data-sensor.php?${params.toString()}`;
            };

            const renderReadings = (readings, offset) => {
                if (!Array.isArray(readings) || readings.length === 0) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="8" class="empty-cell">Data pembacaan sensor tidak ditemukan.</td>
                        </tr>`;
                    return;
                }

                tableBody.innerHTML = readings.map((reading, index) => `
                    <tr>
                        <td>${Number(offset) + index + 1}</td>
                        <td>${escapeHtml(reading.device_code)}</td>
                        <td>${escapeHtml(reading.device_name)}</td>
                        <td>${escapeHtml(reading.location)}</td>
                        <td><strong>${escapeHtml(reading.mq135_raw)}</strong></td>
                        <td>
                            <span class="${badgeClass(reading.air_status)}">
                                ${escapeHtml(reading.air_status)}
                            </span>
                        </td>
                        <td>${escapeHtml(reading.recorded_at_label)}</td>
                        <td>${escapeHtml(reading.created_at_label)}</td>
                    </tr>`).join('');
            };

            const renderFooter = (pagination) => {
                const page = Number(pagination.page || 1);
                const totalPages = Number(pagination.total_pages || 1);
                const total = Number(pagination.total || 0);
                const start = Number(pagination.start_record || 0);
                const end = Number(pagination.end_record || 0);

                let paginationHtml = '';

                if (totalPages > 1) {
                    const links = [];

                    if (page > 1) {
                        links.push(`<a href="${createPageUrl(page - 1)}">Sebelumnya</a>`);
                    }

                    const firstPage = Math.max(1, page - 2);
                    const lastPage = Math.min(totalPages, page + 2);

                    for (let pageNumber = firstPage; pageNumber <= lastPage; pageNumber += 1) {
                        links.push(`
                            <a class="${pageNumber === page ? 'active' : ''}"
                               href="${createPageUrl(pageNumber)}">${pageNumber}</a>`);
                    }

                    if (page < totalPages) {
                        links.push(`<a href="${createPageUrl(page + 1)}">Berikutnya</a>`);
                    }

                    paginationHtml = `
                        <nav class="pagination" aria-label="Halaman data sensor">
                            ${links.join('')}
                        </nav>`;
                }

                tableFooter.innerHTML = `
                    <p>Menampilkan ${start} sampai ${end} dari ${total} data.</p>
                    ${paginationHtml}`;
            };

            const updateDashboard = (payload) => {
                const summary = payload.summary || {};
                const pagination = payload.pagination || {};

                summaryElements.total.textContent = Number(summary.total || 0).toString();
                summaryElements.normal.textContent = Number(summary.normal || 0).toString();
                summaryElements.waspada.textContent = Number(summary.waspada || 0).toString();
                summaryElements.bahaya.textContent = Number(summary.bahaya || 0).toString();

                liveStatus.textContent = `${Number(summary.total || 0)} data · diperbarui ${payload.updated_at || '-'}`;

                renderReadings(payload.readings || [], pagination.offset || 0);
                renderFooter(pagination);
            };

            const refreshSensorData = async () => {
                if (requestRunning || document.hidden) {
                    return;
                }

                requestRunning = true;

                try {
                    const response = await fetch(buildEndpointUrl(), {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json'
                        }
                    });

                    if (response.status === 401) {
                        window.location.href = 'login.php';
                        return;
                    }

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();

                    if (!payload.success) {
                        throw new Error(payload.message || 'Data gagal dimuat.');
                    }

                    updateDashboard(payload);
                } catch (error) {
                    console.error('Pembaruan data sensor gagal:', error);
                    liveStatus.textContent = 'Pembaruan real-time tertunda';
                } finally {
                    requestRunning = false;
                }
            };

            const startPolling = () => {
                if (timerId !== null) {
                    window.clearInterval(timerId);
                }

                refreshSensorData();
                timerId = window.setInterval(refreshSensorData, POLL_INTERVAL);
            };

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    refreshSensorData();
                }
            });

            startPolling();
        })();
    </script>

</body>
</html>
