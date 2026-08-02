<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notification-log-data.php';

requireAdmin();

$database = getDatabaseConnection();
$adminName = adminDisplayName();

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatNotificationDateTime(?string $value): string
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

function notificationBadgeClass(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    if (in_array($normalized, [
        'sent',
        'terkirim',
        'success',
        'successful',
        'delivered',
        'sukses',
    ], true)) {
        return 'badge badge-success';
    }

    if (in_array($normalized, [
        'pending',
        'queued',
        'menunggu',
        'diproses',
    ], true)) {
        return 'badge badge-warning';
    }

    if (in_array($normalized, [
        'failed',
        'gagal',
        'error',
        'undelivered',
        'tidak terkirim',
    ], true)) {
        return 'badge badge-danger';
    }

    return 'badge badge-neutral';
}

function buildNotificationPageUrl(int $page): string
{
    $parameters = $_GET;
    $parameters['page'] = $page;

    return 'riwayat-notifikasi.php?' . http_build_query($parameters);
}

function isValidDateInput(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$errors = [];
$summary = ['total' => 0, 'today' => 0, 'sent' => 0, 'failed' => 0];
$statuses = [];
$result = ['rows' => [], 'total' => 0, 'total_pages' => 1, 'page' => 1];

if ($dateFrom !== '' && !isValidDateInput($dateFrom)) {
    $errors[] = 'Tanggal awal tidak valid.';
    $dateFrom = '';
}

if ($dateTo !== '' && !isValidDateInput($dateTo)) {
    $errors[] = 'Tanggal akhir tidak valid.';
    $dateTo = '';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'Tanggal awal tidak boleh melebihi tanggal akhir.';
    $dateFrom = '';
    $dateTo = '';
}

try {
    $schema = getNotificationLogSchema($database);
    $summary = getNotificationSummary($database, $schema);
    $statuses = getNotificationStatuses($database, $schema);
    $result = getNotificationLogs(
        $database,
        $schema,
        [
            'search' => $search,
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
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Notifikasi | Dashboard Admin</title>
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
                <a class="nav-link" href="index.php">Beranda</a>
                <a class="nav-link" href="perangkat.php">Perangkat</a>
                <a class="nav-link" href="data-sensor.php">Data Sensor</a>
                <a class="nav-link" href="ambang-batas.php">Ambang Batas</a>
                <a class="nav-link" href="pengguna-telegram.php">Pengguna Telegram</a>
                <a class="nav-link active" href="riwayat-notifikasi.php">Riwayat Notifikasi</a>
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
                    <p class="eyebrow">Catatan pengiriman pesan Telegram</p>
                    <h1>Riwayat Notifikasi</h1>
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

            <section class="summary-grid" aria-label="Ringkasan riwayat notifikasi">
                <article class="summary-card">
                    <span class="summary-label">Total Notifikasi</span>
                    <strong><?= escapeHtml($summary['total']); ?></strong>
                    <small>Seluruh catatan pengiriman</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Hari Ini</span>
                    <strong><?= escapeHtml($summary['today']); ?></strong>
                    <small>Notifikasi pada tanggal berjalan</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Terkirim</span>
                    <strong><?= escapeHtml($summary['sent']); ?></strong>
                    <small>Pengiriman berhasil</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Gagal</span>
                    <strong><?= escapeHtml($summary['failed']); ?></strong>
                    <small>Pengiriman bermasalah</small>
                </article>
            </section>

            <section class="panel panel-full">
                <div class="panel-header">
                    <div>
                        <h2>Daftar Riwayat Notifikasi</h2>
                        <p>Notifikasi dikirim ketika status kondisi udara berubah.</p>
                    </div>
                    <span class="record-count"><?= escapeHtml($result['total']); ?> data</span>
                </div>

                <form class="filter-bar notification-filter-bar" method="get" action="riwayat-notifikasi.php">
                    <label>
                        <span>Pencarian</span>
                        <input
                            type="search"
                            name="q"
                            value="<?= escapeHtml($search); ?>"
                            placeholder="Perangkat, penerima, pesan, atau status"
                        >
                    </label>

                    <label>
                        <span>Status pengiriman</span>
                        <select name="status">
                            <option value="">Semua status</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= escapeHtml($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>>
                                    <?= escapeHtml($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

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
                        <a class="button button-secondary" href="riwayat-notifikasi.php">Reset</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Perangkat</th>
                                <th>Penerima</th>
                                <th>Pesan</th>
                                <th>Perubahan Status</th>
                                <th>Nilai ADC</th>
                                <th>Status Kirim</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result['rows'] === []): ?>
                                <tr>
                                    <td colspan="7" class="empty-table">Riwayat notifikasi belum tersedia.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($result['rows'] as $row): ?>
                                    <?php
                                    $previousStatus = trim((string) ($row['previous_status'] ?? ''));
                                    $airStatus = trim((string) ($row['air_status'] ?? ''));
                                    $statusChange = '-';

                                    if ($previousStatus !== '' && $airStatus !== '') {
                                        $statusChange = $previousStatus . ' → ' . $airStatus;
                                    } elseif ($airStatus !== '') {
                                        $statusChange = $airStatus;
                                    }

                                    $message = trim((string) ($row['message'] ?? ''));
                                    $errorMessage = trim((string) ($row['error_message'] ?? ''));
                                    $deliveryStatus = trim((string) ($row['status'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="nowrap">
                                            <?= escapeHtml(formatNotificationDateTime((string) ($row['sent_at'] ?? ''))); ?>
                                        </td>
                                        <td><?= escapeHtml($row['device_label'] ?? '-'); ?></td>
                                        <td><?= escapeHtml($row['recipient_label'] ?? '-'); ?></td>
                                        <td class="notification-message-cell">
                                            <span><?= escapeHtml($message !== '' ? $message : '-'); ?></span>
                                            <?php if ($errorMessage !== ''): ?>
                                                <small><?= escapeHtml($errorMessage); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= escapeHtml($statusChange); ?></td>
                                        <td>
                                            <?= ($row['mq135_raw'] ?? null) === null
                                                ? '-'
                                                : escapeHtml($row['mq135_raw']); ?>
                                        </td>
                                        <td>
                                            <span class="<?= escapeHtml(notificationBadgeClass($deliveryStatus)); ?>">
                                                <?= escapeHtml($deliveryStatus !== '' ? $deliveryStatus : '-'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($result['total_pages'] > 1): ?>
                    <div class="table-footer">
                        <span>
                            Halaman <?= escapeHtml($result['page']); ?> dari <?= escapeHtml($result['total_pages']); ?>
                        </span>

                        <nav class="pagination" aria-label="Navigasi halaman">
                            <?php if ($result['page'] > 1): ?>
                                <a href="<?= escapeHtml(buildNotificationPageUrl($result['page'] - 1)); ?>">Sebelumnya</a>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $result['page'] - 2);
                            $endPage = min($result['total_pages'], $result['page'] + 2);
                            ?>

                            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                <a
                                    class="<?= $pageNumber === $result['page'] ? 'active' : ''; ?>"
                                    href="<?= escapeHtml(buildNotificationPageUrl($pageNumber)); ?>"
                                >
                                    <?= escapeHtml($pageNumber); ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($result['page'] < $result['total_pages']): ?>
                                <a href="<?= escapeHtml(buildNotificationPageUrl($result['page'] + 1)); ?>">Berikutnya</a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            </section>

            <div class="integration-notice">
                Halaman ini hanya menampilkan catatan dari tabel <code>notification_logs</code>. Data riwayat tidak diubah atau dihapus melalui dashboard admin.
            </div>
        </main>
    </div>
</body>
</html>
