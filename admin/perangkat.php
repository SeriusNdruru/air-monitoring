<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/device-data.php';

requireAdmin();

$database = getDatabaseConnection();
$adminName = adminDisplayName();
$errors = [];
$success = '';

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

const DEVICE_DATABASE_TIMEZONE = 'UTC';
const DEVICE_DISPLAY_TIMEZONE = 'Asia/Jakarta';

function formatDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable(
            $value,
            new DateTimeZone(DEVICE_DATABASE_TIMEZONE)
        ))->setTimezone(new DateTimeZone(DEVICE_DISPLAY_TIMEZONE))
            ->format('d M Y H:i:s');
    } catch (Throwable) {
        return $value;
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $deviceId = filter_var(
            $_POST['device_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $deviceName = trim((string) ($_POST['device_name'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));

        if ($deviceId === false) {
            $errors[] = 'Perangkat tidak valid.';
        }

        if ($deviceName === '' || mb_strlen($deviceName) > 100) {
            $errors[] = 'Nama perangkat wajib diisi dan maksimal 100 karakter.';
        }

        if ($location === '' || mb_strlen($location) > 150) {
            $errors[] = 'Lokasi wajib diisi dan maksimal 150 karakter.';
        }

        if ($errors === []) {
            try {
                $device = findDeviceById($database, (int) $deviceId);

                if ($device === null) {
                    $errors[] = 'Perangkat tidak ditemukan.';
                } else {
                    updateDeviceInformation(
                        $database,
                        (int) $deviceId,
                        $deviceName,
                        $location
                    );

                    $success = 'Informasi perangkat berhasil diperbarui.';
                }
            } catch (Throwable $exception) {
                error_log('Device update error: ' . $exception->getMessage());
                $errors[] = 'Informasi perangkat gagal diperbarui.';
            }
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['', 'active', 'online', 'aktif', 'inactive', 'offline', 'tidak aktif'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

try {
    $devices = getDevicesWithLatestReading($database, $search, $statusFilter);
} catch (Throwable $exception) {
    error_log('Device list error: ' . $exception->getMessage());
    $devices = [];
    $errors[] = 'Data perangkat belum dapat ditampilkan.';
}

$editDevice = null;
$editId = filter_var(
    $_GET['edit'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($editId !== false && $editId !== null) {
    try {
        $editDevice = findDeviceById($database, (int) $editId);
    } catch (Throwable $exception) {
        error_log('Find device error: ' . $exception->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perangkat | Dashboard Admin</title>
	<link
        rel="icon"
        type="image/png"
        href="/assets/images/AQM.png?v=1"
    >

    <link
        rel="apple-touch-icon"
        href="/assets/images/AQM.png?v=1"
    >
    <link rel="stylesheet" href="assets/css/admin.css?v=20260728-device-live-1">
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
                <a class="nav-link active" href="perangkat.php">Perangkat</a>
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
                    <p class="eyebrow">Pengelolaan perangkat monitoring</p>
                    <h1>Perangkat</h1>
                </div>

                <div class="admin-profile">
                    <div class="admin-avatar">A</div>
                    <div>
                        <strong><?= escapeHtml($adminName); ?></strong>
                        <span>Administrator</span>
                    </div>
                </div>
            </header>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= escapeHtml($success); ?></div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= escapeHtml($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="panel panel-full">
                <div class="panel-header">
                    <div>
                        <h2>Daftar Perangkat</h2>
                        <p>Menampilkan perangkat dan pembacaan MQ135 paling baru.</p>
                    </div>
                    <div>
                        <span class="record-count" id="device-record-count"><?= escapeHtml(count($devices)); ?> perangkat</span>
                        <small id="device-live-state" style="display:block; margin-top:6px; text-align:right;">Diperbarui otomatis setiap 10 detik</small>
                    </div>
                </div>

                <form class="filter-bar" method="get" action="perangkat.php">
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
                        <span>Status</span>
                        <select name="status">
                            <option value="">Semua status</option>
                            <option value="online" <?= normalizeDeviceStatusFilter($statusFilter) === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="offline" <?= normalizeDeviceStatusFilter($statusFilter) === 'offline' ? 'selected' : ''; ?>>Offline</option>
                        </select>
                    </label>

                    <div class="filter-actions">
                        <button class="button button-primary" type="submit">Terapkan</button>
                        <a class="button button-secondary" href="perangkat.php">Reset</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama Perangkat</th>
                                <th>Lokasi</th>
                                <th>Status Perangkat</th>
                                <th>Nilai ADC Terbaru</th>
                                <th>Status Udara</th>
                                <th>Terakhir Aktif</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="device-table-body">
                            <?php if ($devices === []): ?>
                                <tr>
                                    <td colspan="8" class="empty-cell">Data perangkat tidak ditemukan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $device): ?>
                                    <tr>
                                        <td><?= escapeHtml($device['device_code'] ?? '-'); ?></td>
                                        <td><?= escapeHtml($device['device_name'] ?? '-'); ?></td>
                                        <td><?= escapeHtml($device['location'] ?? '-'); ?></td>
                                        <td>
                                            <span class="<?= escapeHtml(badgeClass((string) ($device['status'] ?? ''))); ?>">
                                                <?= escapeHtml(ucfirst((string) ($device['status'] ?? '-'))); ?>
                                            </span>
                                        </td>
                                        <td><?= escapeHtml($device['mq135_raw'] ?? '-'); ?></td>
                                        <td>
                                            <span class="<?= escapeHtml(badgeClass((string) ($device['air_status'] ?? ''))); ?>">
                                                <?= escapeHtml($device['air_status'] ?? '-'); ?>
                                            </span>
                                        </td>
                                        <td><?= escapeHtml(formatDateTime((string) ($device['last_seen'] ?? ''))); ?></td>
                                        <td>
                                            <a class="table-action" href="perangkat.php?edit=<?= escapeHtml($device['id']); ?>">Ubah</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if (is_array($editDevice)): ?>
                <section class="panel panel-full edit-panel" id="ubah-perangkat">
                    <div class="panel-header">
                        <div>
                            <h2>Ubah Informasi Perangkat</h2>
                            <p>Kode perangkat tidak dapat diubah dari halaman ini.</p>
                        </div>
                        <a class="close-link" href="perangkat.php">Tutup</a>
                    </div>

                    <form class="admin-form" method="post" action="perangkat.php">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()); ?>">
                        <input type="hidden" name="device_id" value="<?= escapeHtml($editDevice['id']); ?>">

                        <div class="form-grid">
                            <label>
                                <span>Kode perangkat</span>
                                <input type="text" value="<?= escapeHtml($editDevice['device_code']); ?>" disabled>
                            </label>

                            <label>
                                <span>Nama perangkat</span>
                                <input
                                    type="text"
                                    name="device_name"
                                    maxlength="100"
                                    value="<?= escapeHtml($editDevice['device_name']); ?>"
                                    required
                                >
                            </label>

                            <label class="form-field-wide">
                                <span>Lokasi perangkat</span>
                                <input
                                    type="text"
                                    name="location"
                                    maxlength="150"
                                    value="<?= escapeHtml($editDevice['location']); ?>"
                                    required
                                >
                            </label>
                        </div>

                        <div class="form-actions">
                            <button class="button button-primary" type="submit">Simpan Perubahan</button>
                            <a class="button button-secondary" href="perangkat.php">Batal</a>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script>
        (() => {
            const tableBody = document.getElementById('device-table-body');
            const recordCount = document.getElementById('device-record-count');
            const liveState = document.getElementById('device-live-state');
            const search = <?= json_encode($search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const status = <?= json_encode(normalizeDeviceStatusFilter($statusFilter), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            function badgeClassFor(value) {
                const normalized = String(value || '').trim().toLowerCase();

                if (['normal', 'active', 'online', 'aktif', 'terkirim'].includes(normalized)) {
                    return 'badge badge-success';
                }

                if (['waspada', 'warning', 'perlu dicek'].includes(normalized)) {
                    return 'badge badge-warning';
                }

                if (['bahaya', 'danger', 'inactive', 'offline', 'tidak aktif', 'gagal'].includes(normalized)) {
                    return 'badge badge-danger';
                }

                return 'badge badge-neutral';
            }

            function appendCell(row, value) {
                const cell = document.createElement('td');
                cell.textContent = value === null || value === undefined || value === '' ? '-' : String(value);
                row.appendChild(cell);
                return cell;
            }

            function appendBadgeCell(row, value, capitalize = false) {
                const cell = document.createElement('td');
                const badge = document.createElement('span');
                const text = value === null || value === undefined || value === '' ? '-' : String(value);

                badge.className = badgeClassFor(text);
                badge.textContent = capitalize
                    ? text.charAt(0).toUpperCase() + text.slice(1).toLowerCase()
                    : text;

                cell.appendChild(badge);
                row.appendChild(cell);
            }

            function renderDevices(devices) {
                if (!tableBody) {
                    return;
                }

                tableBody.replaceChildren();

                if (!Array.isArray(devices) || devices.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 8;
                    cell.className = 'empty-cell';
                    cell.textContent = 'Data perangkat tidak ditemukan.';
                    row.appendChild(cell);
                    tableBody.appendChild(row);
                    return;
                }

                devices.forEach((device) => {
                    const row = document.createElement('tr');

                    appendCell(row, device.device_code);
                    appendCell(row, device.device_name);
                    appendCell(row, device.location);
                    appendBadgeCell(row, device.status, true);
                    appendCell(row, device.mq135_raw);
                    appendBadgeCell(row, device.air_status);
                    appendCell(row, device.last_seen_label);

                    const actionCell = document.createElement('td');
                    const editLink = document.createElement('a');
                    editLink.className = 'table-action';
                    editLink.href = `perangkat.php?edit=${encodeURIComponent(device.id)}`;
                    editLink.textContent = 'Ubah';
                    actionCell.appendChild(editLink);
                    row.appendChild(actionCell);

                    tableBody.appendChild(row);
                });
            }

            async function refreshDevices() {
                const query = new URLSearchParams({q: search, status});

                try {
                    const response = await fetch(`perangkat-data.php?${query.toString()}&_=${Date.now()}`, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {'Accept': 'application/json'}
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
                        throw new Error(payload.message || 'Data perangkat gagal dimuat.');
                    }

                    renderDevices(payload.devices);

                    if (recordCount) {
                        recordCount.textContent = `${payload.count} perangkat`;
                    }

                    if (liveState) {
                        liveState.textContent = `Terakhir diperbarui: ${payload.updated_at}`;
                    }
                } catch (error) {
                    console.error('Pembaruan perangkat gagal:', error);

                    if (liveState) {
                        liveState.textContent = 'Pembaruan otomatis gagal. Mencoba kembali...';
                    }
                }
            }

            refreshDevices();
            window.setInterval(refreshDevices, 10000);

            <?php if (is_array($editDevice)): ?>
                document.getElementById('ubah-perangkat')?.scrollIntoView({behavior: 'smooth'});
            <?php endif; ?>
        })();
    </script>
</body>
</html>
