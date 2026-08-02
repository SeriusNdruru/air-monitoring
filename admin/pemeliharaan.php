<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/maintenance-log-data.php';

requireAdmin();

$database = getDatabaseConnection();
$adminName = adminDisplayName();

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatMaintenanceDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d M Y H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

function maintenanceFormDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return (new DateTimeImmutable())->format('Y-m-d\TH:i');
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return (new DateTimeImmutable())->format('Y-m-d\TH:i');
    }
}

function maintenanceBadgeClass(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    if (in_array($normalized, ['selesai', 'completed', 'complete', 'done', 'berhasil'], true)) {
        return 'badge badge-success';
    }

    if (in_array($normalized, ['dijadwalkan', 'scheduled', 'diproses', 'in progress', 'pending'], true)) {
        return 'badge badge-warning';
    }

    if (in_array($normalized, ['ditunda', 'gagal', 'cancelled', 'canceled', 'failed'], true)) {
        return 'badge badge-danger';
    }

    return 'badge badge-neutral';
}

function buildMaintenancePageUrl(int $page): string
{
    $parameters = $_GET;
    unset($parameters['edit']);
    $parameters['page'] = $page;

    return 'pemeliharaan.php?' . http_build_query($parameters);
}

function isValidMaintenanceDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function isValidMaintenanceDateTime(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d\TH:i') === $value;
}

$search = trim((string) ($_GET['q'] ?? ''));
$deviceFilter = trim((string) ($_GET['device_id'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$editId = max(0, (int) ($_GET['edit'] ?? 0));
$errors = [];
$successMessage = isset($_GET['saved']) ? 'Catatan pemeliharaan berhasil disimpan.' : '';
$schema = null;
$devices = [];
$statuses = [];
$statusOptions = [];
$summary = ['total' => 0, 'today' => 0, 'month' => 0, 'devices' => 0];
$result = ['rows' => [], 'total' => 0, 'total_pages' => 1, 'page' => 1];
$editLog = null;

if ($dateFrom !== '' && !isValidMaintenanceDate($dateFrom)) {
    $errors[] = 'Tanggal awal tidak valid.';
    $dateFrom = '';
}

if ($dateTo !== '' && !isValidMaintenanceDate($dateTo)) {
    $errors[] = 'Tanggal akhir tidak valid.';
    $dateTo = '';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'Tanggal awal tidak boleh melebihi tanggal akhir.';
    $dateFrom = '';
    $dateTo = '';
}

try {
    $schema = getMaintenanceLogSchema($database);
    $devices = getMaintenanceDevices($database, $schema);
    $statuses = getMaintenanceStatuses($database, $schema);
    $statusOptions = maintenanceStatusOptions($schema, $statuses);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Permintaan tidak valid. Muat ulang halaman.');
        }

        $logId = trim((string) ($_POST['log_id'] ?? ''));
        $id = $logId === '' ? null : max(1, (int) $logId);
        $maintenanceAtInput = trim((string) ($_POST['maintenance_at'] ?? ''));
        $activity = trim((string) ($_POST['activity'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $performedBy = trim((string) ($_POST['performed_by'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? ''));
        $deviceId = trim((string) ($_POST['device_id'] ?? ''));
        $deviceCode = trim((string) ($_POST['device_code'] ?? ''));
        $deviceName = trim((string) ($_POST['device_name'] ?? ''));

        if (is_string($schema['maintenance_at'] ?? null) && !isValidMaintenanceDateTime($maintenanceAtInput)) {
            $errors[] = 'Tanggal dan waktu pemeliharaan tidak valid.';
        }

        if (is_string($schema['activity'] ?? null) && ($activity === '' || mb_strlen($activity) > 150)) {
            $errors[] = 'Tindakan pemeliharaan wajib diisi dan maksimal 150 karakter.';
        }

        if (is_string($schema['notes'] ?? null) && mb_strlen($notes) > 1000) {
            $errors[] = 'Deskripsi masalah maksimal 1.000 karakter.';
        }

        if (is_string($schema['performed_by'] ?? null) && mb_strlen($performedBy) > 100) {
            $errors[] = 'Nama petugas maksimal 100 karakter.';
        }

        if (is_string($schema['device_id'] ?? null) && $deviceId === '') {
            $deviceColumn = $schema['columns'][strtolower((string) $schema['device_id'])] ?? null;
            if (is_array($deviceColumn) && !($deviceColumn['null'] ?? true) && ($deviceColumn['default'] ?? null) === null) {
                $errors[] = 'Perangkat wajib dipilih.';
            }
        }

        if ($status !== '' && $statusOptions !== [] && !in_array($status, $statusOptions, true)) {
            $errors[] = 'Status pemeliharaan tidak valid.';
        }

        if ($errors === []) {
            $savedId = saveMaintenanceLog(
                $database,
                $schema,
                $id,
                [
                    'device_id' => $deviceId,
                    'device_code' => $deviceCode,
                    'device_name' => $deviceName,
                    'activity' => $activity,
                    'notes' => $notes,
                    'performed_by' => $performedBy,
                    'status' => $status,
                    'maintenance_at' => str_replace('T', ' ', $maintenanceAtInput) . ':00',
                ],
                isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null
            );

            header('Location: pemeliharaan.php?saved=1&edit=' . $savedId);
            exit;
        }
    }

    if ($editId > 0) {
        $editLog = findMaintenanceLogById($database, $schema, $editId);

        if ($editLog === null) {
            $errors[] = 'Catatan pemeliharaan tidak ditemukan.';
        }
    }

    $summary = getMaintenanceSummary($database, $schema);
    $result = getMaintenanceLogs(
        $database,
        $schema,
        [
            'search' => $search,
            'device_id' => $deviceFilter,
            'status' => $statusFilter,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ],
        $page,
        25
    );
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $errors[] = $exception->getMessage();
}

$form = [
    'id' => is_array($editLog) ? (string) ($editLog['id'] ?? '') : '',
    'device_id' => is_array($editLog) ? (string) ($editLog['device_id_raw'] ?? '') : '',
    'device_code' => is_array($editLog) ? (string) ($editLog['device_code_raw'] ?? '') : '',
    'device_name' => is_array($editLog) ? (string) ($editLog['device_name_raw'] ?? '') : '',
    'activity' => is_array($editLog) ? (string) ($editLog['activity'] ?? '') : '',
    'notes' => is_array($editLog) ? (string) ($editLog['notes'] ?? '') : '',
    'performed_by' => is_array($editLog)
        ? (string) ($editLog['performed_by'] ?? '')
        : $adminName,
    'status' => is_array($editLog) ? (string) ($editLog['status'] ?? '') : ($statusOptions[0] ?? ''),
    'maintenance_at' => maintenanceFormDateTime(
        is_array($editLog) ? (string) ($editLog['maintenance_at'] ?? '') : null
    ),
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemeliharaan | Dashboard Admin</title>
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
    <style>
        /* Perbaikan tata letak filter Pemeliharaan.
           Filter dibuat tiga kolom agar tombol Reset tidak keluar panel. */
        .maintenance-filter-bar {
            grid-template-columns:
                minmax(240px, 1.4fr)
                minmax(170px, 0.8fr)
                minmax(190px, 0.8fr);
        }

        .maintenance-filter-bar > label,
        .maintenance-filter-bar .filter-actions {
            min-width: 0;
        }

        .maintenance-filter-bar input,
        .maintenance-filter-bar select {
            min-width: 0;
            max-width: 100%;
        }

        .maintenance-filter-bar .filter-actions {
            grid-column: auto;
            display: flex;
            flex-wrap: nowrap;
            justify-content: flex-start;
        }

        @media (max-width: 1380px) {
            .maintenance-layout {
                grid-template-columns: 1fr;
            }

            .maintenance-form-panel {
                position: static;
            }

            .maintenance-filter-bar {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .maintenance-filter-bar .filter-actions {
                grid-column: auto;
            }
        }

        @media (max-width: 820px) {
            .maintenance-filter-bar {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .maintenance-filter-bar .filter-actions {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 560px) {
            .maintenance-filter-bar {
                grid-template-columns: 1fr;
            }

            .maintenance-filter-bar .filter-actions {
                grid-column: auto;
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
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
                <a class="nav-link" href="data-sensor.php">Data Sensor</a>
                <a class="nav-link" href="ambang-batas.php">Ambang Batas</a>
                <a class="nav-link" href="pengguna-telegram.php">Pengguna Telegram</a>
                <a class="nav-link" href="riwayat-notifikasi.php">Riwayat Notifikasi</a>
                <a class="nav-link active" href="pemeliharaan.php">Pemeliharaan</a>
            </nav>

            <form class="logout-form" method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()); ?>">
                <button type="submit" class="logout-button">Keluar</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <p class="eyebrow">Pencatatan pemeriksaan dan perawatan perangkat</p>
                    <h1>Pemeliharaan</h1>
                </div>

                <div class="admin-profile">
                    <div class="admin-avatar">A</div>
                    <div>
                        <strong><?= escapeHtml($adminName); ?></strong>
                        <span>Administrator</span>
                    </div>
                </div>
            </header>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success"><?= escapeHtml($successMessage); ?></div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach (array_unique($errors) as $error): ?>
                            <li><?= escapeHtml($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="summary-grid" aria-label="Ringkasan pemeliharaan">
                <article class="summary-card">
                    <span class="summary-label">Total Catatan</span>
                    <strong><?= escapeHtml($summary['total']); ?></strong>
                    <small>Seluruh kegiatan pemeliharaan</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Hari Ini</span>
                    <strong><?= escapeHtml($summary['today']); ?></strong>
                    <small>Kegiatan pada tanggal berjalan</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Bulan Ini</span>
                    <strong><?= escapeHtml($summary['month']); ?></strong>
                    <small>Kegiatan pada bulan berjalan</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Perangkat Tercatat</span>
                    <strong><?= escapeHtml($summary['devices']); ?></strong>
                    <small>Perangkat pernah dipelihara</small>
                </article>
            </section>

            <section class="maintenance-layout">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Riwayat Pemeliharaan</h2>
                            <p>Catatan pemeriksaan, perbaikan, dan perawatan perangkat.</p>
                        </div>
                        <span class="record-count"><?= escapeHtml($result['total']); ?> data</span>
                    </div>

                    <form class="filter-bar maintenance-filter-bar" method="get" action="pemeliharaan.php">
                        <label>
                            <span>Pencarian</span>
                            <input
                                type="search"
                                name="q"
                                value="<?= escapeHtml($search); ?>"
                                placeholder="Kegiatan, catatan, petugas, atau perangkat"
                            >
                        </label>

                        <?php if ($devices !== []): ?>
                            <label>
                                <span>Perangkat</span>
                                <select name="device_id">
                                    <option value="">Semua perangkat</option>
                                    <?php foreach ($devices as $device): ?>
                                        <?php
                                        $deviceLabel = trim((string) ($device['device_name'] ?? ''));
                                        $deviceCode = trim((string) ($device['device_code'] ?? ''));
                                        if ($deviceLabel === '') {
                                            $deviceLabel = $deviceCode !== '' ? $deviceCode : 'ID ' . $device['id'];
                                        } elseif ($deviceCode !== '') {
                                            $deviceLabel .= ' (' . $deviceCode . ')';
                                        }
                                        ?>
                                        <option
                                            value="<?= escapeHtml($device['id']); ?>"
                                            <?= $deviceFilter === (string) $device['id'] ? 'selected' : ''; ?>
                                        ><?= escapeHtml($deviceLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>

                        <?php if ($statuses !== []): ?>
                            <label>
                                <span>Status</span>
                                <select name="status">
                                    <option value="">Semua status</option>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?= escapeHtml($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>>
                                            <?= escapeHtml($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>

                        <label>
                            <span>Tanggal awal</span>
                            <input type="date" name="date_from" value="<?= escapeHtml($dateFrom); ?>">
                        </label>

                        <label>
                            <span>Tanggal akhir</span>
                            <input type="date" name="date_to" value="<?= escapeHtml($dateTo); ?>">
                        </label>

                        <div class="filter-actions">
                            <button class="button button-primary" type="submit">Terapkan</button>
                            <a class="button button-secondary" href="pemeliharaan.php">Reset</a>
                        </div>
                    </form>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Perangkat</th>
                                    <th>Tindakan Pemeliharaan</th>
                                    <th>Deskripsi Masalah</th>
                                    <th>Petugas</th>
                                    <?php if (is_array($schema) && is_string($schema['status'] ?? null)): ?>
                                        <th>Status</th>
                                    <?php endif; ?>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result['rows'] === []): ?>
                                    <tr>
                                        <td colspan="7" class="empty-table">Catatan pemeliharaan belum tersedia.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($result['rows'] as $row): ?>
                                        <?php $rowStatus = trim((string) ($row['status'] ?? '')); ?>
                                        <tr>
                                            <td class="nowrap"><?= escapeHtml(formatMaintenanceDateTime((string) ($row['maintenance_at'] ?? $row['created_at'] ?? ''))); ?></td>
                                            <td>
                                                <strong><?= escapeHtml($row['device_label'] ?? '-'); ?></strong>
                                                <?php if (trim((string) ($row['device_location'] ?? '')) !== ''): ?>
                                                    <small class="table-secondary-text"><?= escapeHtml($row['device_location']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= escapeHtml(($row['activity'] ?? '') !== '' ? $row['activity'] : '-'); ?></td>
                                            <td class="maintenance-notes-cell"><?= escapeHtml(($row['notes'] ?? '') !== '' ? $row['notes'] : '-'); ?></td>
                                            <td><?= escapeHtml(($row['performed_by'] ?? '') !== '' ? $row['performed_by'] : '-'); ?></td>
                                            <?php if (is_array($schema) && is_string($schema['status'] ?? null)): ?>
                                                <td>
                                                    <span class="<?= escapeHtml(maintenanceBadgeClass($rowStatus)); ?>">
                                                        <?= escapeHtml($rowStatus !== '' ? $rowStatus : '-'); ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <a class="table-action" href="pemeliharaan.php?edit=<?= escapeHtml($row['id']); ?>">Ubah</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($result['total_pages'] > 1): ?>
                        <div class="table-footer">
                            <span>Halaman <?= escapeHtml($result['page']); ?> dari <?= escapeHtml($result['total_pages']); ?></span>

                            <nav class="pagination" aria-label="Navigasi halaman">
                                <?php if ($result['page'] > 1): ?>
                                    <a href="<?= escapeHtml(buildMaintenancePageUrl($result['page'] - 1)); ?>">Sebelumnya</a>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $result['page'] - 2);
                                $endPage = min($result['total_pages'], $result['page'] + 2);
                                ?>

                                <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                    <a
                                        class="<?= $pageNumber === $result['page'] ? 'active' : ''; ?>"
                                        href="<?= escapeHtml(buildMaintenancePageUrl($pageNumber)); ?>"
                                    ><?= escapeHtml($pageNumber); ?></a>
                                <?php endfor; ?>

                                <?php if ($result['page'] < $result['total_pages']): ?>
                                    <a href="<?= escapeHtml(buildMaintenancePageUrl($result['page'] + 1)); ?>">Berikutnya</a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    <?php endif; ?>
                </article>

                <aside class="panel maintenance-form-panel">
                    <div class="panel-header">
                        <div>
                            <h2><?= is_array($editLog) ? 'Ubah Catatan' : 'Tambah Catatan'; ?></h2>
                            <p>Simpan kegiatan pemeliharaan perangkat.</p>
                        </div>

                        <?php if (is_array($editLog)): ?>
                            <a class="close-link" href="pemeliharaan.php">Batal</a>
                        <?php endif; ?>
                    </div>

                    <?php if (is_array($schema)): ?>
                        <form class="admin-form" method="post" action="pemeliharaan.php" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()); ?>">
                            <input type="hidden" name="log_id" value="<?= escapeHtml($form['id']); ?>">

                            <div class="form-grid maintenance-form-grid">
                                <?php if (is_string($schema['maintenance_at'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        Tanggal dan waktu
                                        <input
                                            type="datetime-local"
                                            name="maintenance_at"
                                            value="<?= escapeHtml($form['maintenance_at']); ?>"
                                            required
                                        >
                                    </label>
                                <?php endif; ?>

                                <?php if (is_string($schema['device_id'] ?? null) && $devices !== []): ?>
                                    <label class="form-field-wide">
                                        Perangkat
                                        <select name="device_id">
                                            <option value="">Pilih perangkat</option>
                                            <?php foreach ($devices as $device): ?>
                                                <?php
                                                $deviceLabel = trim((string) ($device['device_name'] ?? ''));
                                                $deviceCode = trim((string) ($device['device_code'] ?? ''));
                                                if ($deviceLabel === '') {
                                                    $deviceLabel = $deviceCode !== '' ? $deviceCode : 'ID ' . $device['id'];
                                                } elseif ($deviceCode !== '') {
                                                    $deviceLabel .= ' (' . $deviceCode . ')';
                                                }
                                                ?>
                                                <option
                                                    value="<?= escapeHtml($device['id']); ?>"
                                                    <?= $form['device_id'] === (string) $device['id'] ? 'selected' : ''; ?>
                                                ><?= escapeHtml($deviceLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php elseif (is_string($schema['device_id'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        ID perangkat
                                        <input type="number" min="1" name="device_id" value="<?= escapeHtml($form['device_id']); ?>">
                                    </label>
                                <?php endif; ?>

                                <?php if (!is_string($schema['device_id'] ?? null) && is_string($schema['device_code'] ?? null)): ?>
                                    <label>
                                        Kode perangkat
                                        <input type="text" name="device_code" maxlength="60" value="<?= escapeHtml($form['device_code']); ?>">
                                    </label>
                                <?php endif; ?>

                                <?php if (!is_string($schema['device_id'] ?? null) && is_string($schema['device_name'] ?? null)): ?>
                                    <label>
                                        Nama perangkat
                                        <input type="text" name="device_name" maxlength="100" value="<?= escapeHtml($form['device_name']); ?>">
                                    </label>
                                <?php endif; ?>

                                <?php if (is_string($schema['activity'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        Tindakan pemeliharaan
                                        <input
                                            type="text"
                                            name="activity"
                                            maxlength="150"
                                            value="<?= escapeHtml($form['activity']); ?>"
                                            placeholder="Contoh: Pembersihan dan pemeriksaan sensor"
                                            required
                                        >
                                    </label>
                                <?php endif; ?>

                                <?php if (is_string($schema['notes'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        Deskripsi masalah
                                        <textarea
                                            name="notes"
                                            rows="5"
                                            maxlength="1000"
                                            placeholder="Jelaskan masalah atau kondisi perangkat yang ditemukan"
                                        ><?= escapeHtml($form['notes']); ?></textarea>
                                    </label>
                                <?php endif; ?>

                                <?php if (is_string($schema['performed_by'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        Petugas
                                        <input
                                            type="text"
                                            name="performed_by"
                                            maxlength="100"
                                            value="<?= escapeHtml($form['performed_by']); ?>"
                                        >
                                    </label>
                                <?php endif; ?>

                                <?php if (is_string($schema['status'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        Status
                                        <select name="status">
                                            <option value="">Pilih status</option>
                                            <?php foreach ($statusOptions as $status): ?>
                                                <option value="<?= escapeHtml($status); ?>" <?= $form['status'] === $status ? 'selected' : ''; ?>>
                                                    <?= escapeHtml($status); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>
                            </div>

                            <div class="form-actions">
                                <button class="button button-primary" type="submit">Simpan Catatan</button>
                                <?php if (is_array($editLog)): ?>
                                    <a class="button button-secondary" href="pemeliharaan.php">Batal</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="empty-state">Struktur tabel maintenance_logs belum dapat dibaca.</div>
                    <?php endif; ?>
                </aside>
            </section>

            <div class="integration-notice">
                Catatan disimpan pada tabel <code>maintenance_logs</code>. Halaman ini mendukung penambahan dan perubahan data, tetapi tidak menyediakan penghapusan permanen agar riwayat pemeliharaan tetap terjaga.
            </div>
        </main>
    </div>
</body>
</html>
