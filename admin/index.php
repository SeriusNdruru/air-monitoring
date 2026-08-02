<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard-data.php';

requireAdmin();

$database = getDatabaseConnection();
$dashboard = getDashboardData($database);
$adminName = adminDisplayName();

const DATABASE_TIMEZONE = 'UTC';
const DISPLAY_TIMEZONE = 'Asia/Jakarta';

function localDateTime(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $databaseTimezone = new DateTimeZone(DATABASE_TIMEZONE);
        $displayTimezone = new DateTimeZone(DISPLAY_TIMEZONE);

        return (new DateTimeImmutable($value, $databaseTimezone))
            ->setTimezone($displayTimezone);
    } catch (Throwable) {
        return null;
    }
}

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatDateTime(?string $value): string
{
    $date = localDateTime($value);

    return $date instanceof DateTimeImmutable
        ? $date->format('d M Y H:i:s')
        : ($value === null || trim($value) === '' ? '-' : $value);
}

function badgeClass(string $status): string
{
    $normalized = strtolower(trim($status));

    if (in_array($normalized, ['normal', 'active', 'online', 'aktif', 'terkirim'], true)) {
        return 'badge badge-success';
    }

    if (in_array($normalized, ['waspada', 'warning', 'perlu dicek'], true)) {
        return 'badge badge-warning';
    }

    if (in_array($normalized, ['bahaya', 'danger', 'inactive', 'offline', 'tidak aktif', 'gagal'], true)) {
        return 'badge badge-danger';
    }

    return 'badge badge-neutral';
}

function prepareChartReadings(array $rows): array
{
    $prepared = [];

    foreach ($rows as $row) {
        $rawTime = (string) ($row['recorded_at'] ?? $row['created_at'] ?? '');
        $label = '-';
        $fullLabel = '-';

        if ($rawTime !== '') {
            $date = localDateTime($rawTime);

            if ($date instanceof DateTimeImmutable) {
                $label = $date->format('H:i:s');
                $fullLabel = $date->format('d M Y H:i:s');
            } else {
                $label = $rawTime;
                $fullLabel = $rawTime;
            }
        }

        $prepared[] = [
            'id' => (int) ($row['id'] ?? 0),
            'mq135_raw' => max(0, min(4095, (int) ($row['mq135_raw'] ?? 0))),
            'air_status' => (string) ($row['air_status'] ?? '-'),
            'label' => $label,
            'full_label' => $fullLabel,
        ];
    }

    return $prepared;
}

$latestReading = $dashboard['latest_reading'];
$latestAdc = is_array($latestReading) ? (int) ($latestReading['mq135_raw'] ?? 0) : null;
$latestAirStatus = is_array($latestReading) ? (string) ($latestReading['air_status'] ?? '-') : '-';
$latestRecordedAt = is_array($latestReading) ? formatDateTime((string) ($latestReading['recorded_at'] ?? '')) : '-';
$initialChartReadings = prepareChartReadings($dashboard['chart_readings']);
$initialChartJson = json_encode(
    $initialChartReadings,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

if ($initialChartJson === false) {
    $initialChartJson = '[]';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin Monitoring Kualitas Udara</title>
	<link
        rel="icon"
        type="image/png"
        href="/assets/images/AQM.png?v=1"
    >

    <link
        rel="apple-touch-icon"
        href="/assets/images/AQM.png?v=1"
    >
    <link rel="stylesheet" href="assets/css/admin.css">
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
                <a class="nav-link active" href="index.php">Beranda</a>
                <a class="nav-link" href="perangkat.php">Perangkat</a>
                <a class="nav-link" href="data-sensor.php">Data Sensor</a>
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
                    <p class="eyebrow">Sistem Monitoring Kualitas Udara Berbasis IoT</p>
                    <h1>Dashboard Admin</h1>
                </div>

                <div class="admin-profile">
                    <div class="admin-avatar">A</div>
                    <div>
                        <strong><?= escapeHtml($adminName); ?></strong>
                        <span>Administrator</span>
                    </div>
                </div>
            </header>

            <section class="summary-grid" aria-label="Ringkasan sistem">
                <article class="summary-card">
                    <span class="summary-label">Total Perangkat</span>
                    <strong id="total-devices-summary"><?= escapeHtml($dashboard['total_devices']); ?></strong>
                    <small>Perangkat terdaftar</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Perangkat Aktif</span>
                    <strong id="active-devices-summary"><?= escapeHtml($dashboard['active_devices']); ?></strong>
                    <small>Perangkat terhubung</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Nilai ADC Terbaru</span>
                    <strong id="latest-adc-summary"><?= $latestAdc === null ? '-' : escapeHtml($latestAdc); ?></strong>
                    <small id="latest-status-summary"><?= escapeHtml($latestAirStatus); ?></small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Status Sistem</span>
                    <strong id="system-status-summary" class="summary-status"><?= escapeHtml($dashboard['system_status']); ?></strong>
                    <small id="notification-count-summary"><?= escapeHtml($dashboard['notification_count_today']); ?> notifikasi hari ini</small>
                </article>
            </section>

            <section class="content-grid">
                <article class="panel panel-wide">
                    <div class="panel-header chart-panel-header">
                        <div>
                            <h2>Grafik Nilai ADC MQ135</h2>
                            <p>Menampilkan maksimal 30 pembacaan terakhir, dari data terlama ke data terbaru.</p>
                        </div>
                        <div class="chart-meta" aria-live="polite">
                            <span id="chart-count" class="record-count"><?= escapeHtml(count($initialChartReadings)); ?>/30 data</span>
                            <span id="chart-update-time" class="update-time">Terbaru: <?= escapeHtml($latestRecordedAt); ?></span>
                        </div>
                    </div>

                    <div class="chart-wrap chart-wrap-live">
                        <svg
                            id="adc-chart"
                            class="line-chart"
                            viewBox="0 0 1000 330"
                            role="img"
                            aria-label="Grafik 30 pembacaan terakhir nilai ADC MQ135"
                            preserveAspectRatio="xMidYMid meet"
                        ></svg>
                        <div id="chart-empty" class="empty-state" hidden>Data grafik belum tersedia.</div>
                    </div>
                    <p class="chart-note">Grafik diperbarui otomatis setiap 10 detik. Titik paling kanan merupakan pembacaan terbaru.</p>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Pembacaan Terbaru</h2>
                            <p>Nilai ADC MQ135 terakhir.</p>
                        </div>
                    </div>

                    <?php if (is_array($latestReading)): ?>
                        <div class="latest-reading">
                            <strong id="latest-reading-value"><?= escapeHtml($latestAdc); ?></strong>
                            <span id="latest-reading-status" class="<?= escapeHtml(badgeClass($latestAirStatus)); ?>">
                                <?= escapeHtml($latestAirStatus); ?>
                            </span>
                            <dl>
                                <div>
                                    <dt>Perangkat</dt>
                                    <dd><?= escapeHtml($latestReading['device_name'] ?? '-'); ?></dd>
                                </div>
                                <div>
                                    <dt>Lokasi</dt>
                                    <dd><?= escapeHtml($latestReading['location'] ?? '-'); ?></dd>
                                </div>
                                <div>
                                    <dt>Waktu</dt>
                                    <dd id="latest-reading-time"><?= escapeHtml($latestRecordedAt); ?></dd>
                                </div>
                            </dl>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Belum ada pembacaan sensor.</div>
                    <?php endif; ?>
                </article>

                <article class="panel panel-wide">
                    <div class="panel-header">
                        <div>
                            <h2>Status Perangkat</h2>
                            <p>Daftar perangkat yang terdaftar pada sistem.</p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Nama Perangkat</th>
                                    <th>Lokasi</th>
                                    <th>Status</th>
                                    <th>Terakhir Aktif</th>
                                </tr>
                            </thead>
                            <tbody id="device-status-body">
                                <?php if ($dashboard['devices'] === []): ?>
                                    <tr>
                                        <td colspan="5" class="empty-cell">Data perangkat belum tersedia.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($dashboard['devices'] as $device): ?>
                                        <tr>
                                            <td><?= escapeHtml($device['device_code'] ?? '-'); ?></td>
                                            <td><?= escapeHtml($device['device_name'] ?? '-'); ?></td>
                                            <td><?= escapeHtml($device['location'] ?? '-'); ?></td>
                                            <td>
                                                <span class="<?= escapeHtml(badgeClass((string) ($device['status'] ?? ''))); ?>">
                                                    <?= escapeHtml($device['status'] ?? '-'); ?>
                                                </span>
                                            </td>
                                            <td><?= escapeHtml(formatDateTime((string) ($device['last_seen'] ?? ''))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Ambang Batas</h2>
                            <p>Kategori internal sistem.</p>
                        </div>
                    </div>

                    <div class="threshold-list">
                        <div>
                            <span class="status-dot status-normal"></span>
                            <strong>Normal</strong>
                            <small>0 sampai 1499</small>
                        </div>
                        <div>
                            <span class="status-dot status-warning"></span>
                            <strong>Waspada</strong>
                            <small>1500 sampai 2499</small>
                        </div>
                        <div>
                            <span class="status-dot status-danger"></span>
                            <strong>Bahaya</strong>
                            <small>2500 sampai 4095</small>
                        </div>
                    </div>
                </article>

                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2>Riwayat Pembacaan Sensor</h2>
                            <p>Sepuluh pembacaan terbaru dari basis data.</p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Perangkat</th>
                                    <th>Nilai ADC MQ135</th>
                                    <th>Status Udara</th>
                                    <th>Lokasi</th>
                                    <th>Waktu Pembacaan</th>
                                </tr>
                            </thead>
                            <tbody id="recent-readings-body">
                                <?php if ($dashboard['recent_readings'] === []): ?>
                                    <tr>
                                        <td colspan="5" class="empty-cell">Riwayat pembacaan belum tersedia.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($dashboard['recent_readings'] as $reading): ?>
                                        <tr>
                                            <td><?= escapeHtml($reading['device_name'] ?? $reading['device_code'] ?? '-'); ?></td>
                                            <td><?= escapeHtml($reading['mq135_raw'] ?? '-'); ?></td>
                                            <td>
                                                <span class="<?= escapeHtml(badgeClass((string) ($reading['air_status'] ?? ''))); ?>">
                                                    <?= escapeHtml($reading['air_status'] ?? '-'); ?>
                                                </span>
                                            </td>
                                            <td><?= escapeHtml($reading['location'] ?? '-'); ?></td>
                                            <td><?= escapeHtml(formatDateTime((string) ($reading['recorded_at'] ?? ''))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <script>
        (() => {
            'use strict';

            const initialReadings = <?= $initialChartJson; ?>;
            const chartElement = document.getElementById('adc-chart');
            const emptyElement = document.getElementById('chart-empty');
            const countElement = document.getElementById('chart-count');
            const updateTimeElement = document.getElementById('chart-update-time');
            const latestAdcSummary = document.getElementById('latest-adc-summary');
            const latestStatusSummary = document.getElementById('latest-status-summary');
            const latestReadingValue = document.getElementById('latest-reading-value');
            const latestReadingStatus = document.getElementById('latest-reading-status');
            const latestReadingTime = document.getElementById('latest-reading-time');
            const totalDevicesSummary = document.getElementById('total-devices-summary');
            const activeDevicesSummary = document.getElementById('active-devices-summary');
            const systemStatusSummary = document.getElementById('system-status-summary');
            const notificationCountSummary = document.getElementById('notification-count-summary');
            const deviceStatusBody = document.getElementById('device-status-body');
            const recentReadingsBody = document.getElementById('recent-readings-body');
            const svgNamespace = 'http://www.w3.org/2000/svg';

            function createSvgElement(tagName, attributes = {}, textContent = null) {
                const element = document.createElementNS(svgNamespace, tagName);

                Object.entries(attributes).forEach(([name, value]) => {
                    element.setAttribute(name, String(value));
                });

                if (textContent !== null) {
                    element.textContent = String(textContent);
                }

                return element;
            }

            function statusBadgeClass(status) {
                const normalized = String(status || '').trim().toLowerCase();

                if (normalized === 'normal') {
                    return 'badge badge-success';
                }

                if (normalized === 'waspada' || normalized === 'warning') {
                    return 'badge badge-warning';
                }

                if (normalized === 'bahaya' || normalized === 'danger') {
                    return 'badge badge-danger';
                }

                return 'badge badge-neutral';
            }

            function deviceBadgeClass(status) {
                const normalized = String(status || '').trim().toLowerCase();

                if (normalized === 'online' || normalized === 'active' || normalized === 'aktif') {
                    return 'badge badge-success';
                }

                return 'badge badge-danger';
            }

            function appendCell(row, text) {
                const cell = document.createElement('td');
                cell.textContent = String(text ?? '-');
                row.appendChild(cell);
                return cell;
            }

            function renderLiveStatus(payload) {
                const summary = payload && typeof payload.summary === 'object'
                    ? payload.summary
                    : {};

                if (totalDevicesSummary) {
                    totalDevicesSummary.textContent = String(summary.total_devices ?? 0);
                }

                if (activeDevicesSummary) {
                    activeDevicesSummary.textContent = String(summary.active_devices ?? 0);
                }

                if (systemStatusSummary) {
                    systemStatusSummary.textContent = String(summary.system_status ?? 'Tidak Aktif');
                }

                if (notificationCountSummary) {
                    notificationCountSummary.textContent = `${summary.notification_count_today ?? 0} notifikasi hari ini`;
                }

                if (!deviceStatusBody) {
                    return;
                }

                const devices = Array.isArray(payload.devices) ? payload.devices : [];
                deviceStatusBody.replaceChildren();

                if (devices.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 5;
                    cell.className = 'empty-cell';
                    cell.textContent = 'Data perangkat belum tersedia.';
                    row.appendChild(cell);
                    deviceStatusBody.appendChild(row);
                    return;
                }

                devices.forEach((device) => {
                    const row = document.createElement('tr');
                    appendCell(row, device.device_code || '-');
                    appendCell(row, device.device_name || '-');
                    appendCell(row, device.location || '-');

                    const statusCell = document.createElement('td');
                    const badge = document.createElement('span');
                    badge.className = deviceBadgeClass(device.status);
                    badge.textContent = String(device.status || 'offline');
                    statusCell.appendChild(badge);
                    row.appendChild(statusCell);

                    appendCell(row, device.last_seen_label || '-');
                    deviceStatusBody.appendChild(row);
                });
            }

            function renderRecentReadings(readings) {
                if (!recentReadingsBody) {
                    return;
                }

                const rows = Array.isArray(readings) ? readings.slice(0, 10) : [];
                recentReadingsBody.replaceChildren();

                if (rows.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 5;
                    cell.className = 'empty-cell';
                    cell.textContent = 'Riwayat pembacaan belum tersedia.';
                    row.appendChild(cell);
                    recentReadingsBody.appendChild(row);
                    return;
                }

                rows.forEach((reading) => {
                    const row = document.createElement('tr');
                    appendCell(
                        row,
                        reading.device_name || reading.device_code || '-'
                    );
                    appendCell(row, reading.mq135_raw ?? '-');

                    const statusCell = document.createElement('td');
                    const badge = document.createElement('span');
                    badge.className = statusBadgeClass(reading.air_status);
                    badge.textContent = String(reading.air_status || '-');
                    statusCell.appendChild(badge);
                    row.appendChild(statusCell);

                    appendCell(row, reading.location || '-');
                    appendCell(row, reading.full_label || '-');
                    recentReadingsBody.appendChild(row);
                });
            }

            function renderChart(readings) {
                const validReadings = Array.isArray(readings)
                    ? readings.slice(-30).map((reading) => ({
                        id: Number(reading.id || 0),
                        mq135_raw: Math.max(0, Math.min(4095, Number(reading.mq135_raw || 0))),
                        air_status: String(reading.air_status || '-'),
                        label: String(reading.label || '-'),
                        full_label: String(reading.full_label || reading.label || '-'),
                    }))
                    : [];

                chartElement.replaceChildren();
                countElement.textContent = `${validReadings.length}/30 data`;

                if (validReadings.length === 0) {
                    chartElement.hidden = true;
                    emptyElement.hidden = false;
                    return;
                }

                chartElement.hidden = false;
                emptyElement.hidden = true;

                const width = 1000;
                const height = 330;
                const margin = { top: 24, right: 28, bottom: 58, left: 62 };
                const plotWidth = width - margin.left - margin.right;
                const plotHeight = height - margin.top - margin.bottom;
                const yTicks = [0, 1000, 2000, 3000, 4095];
                const lastIndex = validReadings.length - 1;

                yTicks.forEach((tick) => {
                    const y = margin.top + plotHeight - ((tick / 4095) * plotHeight);

                    chartElement.appendChild(createSvgElement('line', {
                        x1: margin.left,
                        y1: y,
                        x2: width - margin.right,
                        y2: y,
                        class: 'chart-grid',
                    }));

                    chartElement.appendChild(createSvgElement('text', {
                        x: margin.left - 12,
                        y: y + 4,
                        class: 'chart-axis-label chart-axis-label-y',
                        'text-anchor': 'end',
                    }, tick));
                });

                chartElement.appendChild(createSvgElement('line', {
                    x1: margin.left,
                    y1: margin.top,
                    x2: margin.left,
                    y2: height - margin.bottom,
                    class: 'chart-axis',
                }));

                chartElement.appendChild(createSvgElement('line', {
                    x1: margin.left,
                    y1: height - margin.bottom,
                    x2: width - margin.right,
                    y2: height - margin.bottom,
                    class: 'chart-axis',
                }));

                const points = validReadings.map((reading, index) => {
                    const x = validReadings.length === 1
                        ? margin.left + (plotWidth / 2)
                        : margin.left + ((plotWidth / lastIndex) * index);
                    const y = margin.top + plotHeight - ((reading.mq135_raw / 4095) * plotHeight);

                    return { ...reading, x, y, index };
                });

                const areaPoints = [
                    `${points[0].x},${height - margin.bottom}`,
                    ...points.map((point) => `${point.x},${point.y}`),
                    `${points[points.length - 1].x},${height - margin.bottom}`,
                ].join(' ');

                chartElement.appendChild(createSvgElement('polygon', {
                    points: areaPoints,
                    class: 'chart-area',
                }));

                chartElement.appendChild(createSvgElement('polyline', {
                    points: points.map((point) => `${point.x},${point.y}`).join(' '),
                    class: 'chart-line',
                }));

                const labelIndexes = new Set([0, lastIndex]);
                const labelStep = Math.max(1, Math.ceil(validReadings.length / 6));

                for (let index = 0; index < validReadings.length; index += labelStep) {
                    labelIndexes.add(index);
                }

                points.forEach((point) => {
                    const circle = createSvgElement('circle', {
                        cx: point.x,
                        cy: point.y,
                        r: point.index === lastIndex ? 6 : 4,
                        class: point.index === lastIndex
                            ? 'chart-point chart-point-latest'
                            : 'chart-point',
                        tabindex: 0,
                        'aria-label': `Nilai ADC ${point.mq135_raw}, status ${point.air_status}, waktu ${point.full_label}`,
                    });

                    circle.appendChild(createSvgElement('title', {},
                        `${point.full_label} | ADC ${point.mq135_raw} | ${point.air_status}`
                    ));

                    chartElement.appendChild(circle);

                    if (labelIndexes.has(point.index)) {
                        chartElement.appendChild(createSvgElement('text', {
                            x: point.x,
                            y: height - margin.bottom + 24,
                            class: 'chart-axis-label chart-axis-label-x',
                            'text-anchor': point.index === 0
                                ? 'start'
                                : (point.index === lastIndex ? 'end' : 'middle'),
                        }, point.label));
                    }
                });

                const latest = validReadings[lastIndex];
                updateTimeElement.textContent = `Terbaru: ${latest.full_label}`;
                latestAdcSummary.textContent = String(latest.mq135_raw);
                latestStatusSummary.textContent = latest.air_status;

                if (latestReadingValue) {
                    latestReadingValue.textContent = String(latest.mq135_raw);
                }

                if (latestReadingStatus) {
                    latestReadingStatus.textContent = latest.air_status;
                    latestReadingStatus.className = statusBadgeClass(latest.air_status);
                }

                if (latestReadingTime) {
                    latestReadingTime.textContent = latest.full_label;
                }
            }

            async function refreshChart() {
                try {
                    const response = await fetch(`chart-data.php?t=${Date.now()}`, {
                        method: 'GET',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.status === 401) {
                        window.location.href = 'login.php';
                        return;
                    }

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();

                    if (!payload.success || !Array.isArray(payload.readings)) {
                        throw new Error(payload.message || 'Data grafik tidak valid.');
                    }

                    renderChart(payload.readings);
                    renderLiveStatus(payload);
                    renderRecentReadings(payload.recent_readings);
                } catch (error) {
                    console.error('Gagal memperbarui dashboard:', error);
                    updateTimeElement.textContent = 'Pembaruan dashboard gagal';
                }
            }

            renderChart(initialReadings);
            refreshChart();
            window.setInterval(refreshChart, 10000);
        })();
    </script>

</body>
</html>
