<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/threshold-data.php';

requireAdmin();

$database = getDatabaseConnection();
$adminName = adminDisplayName();
$errors = [];
$successMessage = '';

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $thresholds = getThresholdConfiguration($database);
} catch (Throwable $exception) {
    error_log('Threshold configuration read error: ' . $exception->getMessage());

    $thresholds = [
        'normal_max' => 1499,
        'warning_max' => 2499,
        'normal_key' => NORMAL_THRESHOLD_KEY,
        'warning_key' => WARNING_THRESHOLD_KEY,
    ];

    $errors[] = 'Pengaturan ambang batas belum dapat dibaca dari basis data.';
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $successMessage = 'Ambang batas berhasil diperbarui.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman dan coba kembali.';
    }

    $normalMax = filter_var(
        $_POST['normal_max'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => 4093]]
    );

    $warningMax = filter_var(
        $_POST['warning_max'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 4094]]
    );

    if ($normalMax === false) {
        $errors[] = 'Batas maksimum Normal harus berada pada rentang 0 sampai 4093.';
    }

    if ($warningMax === false) {
        $errors[] = 'Batas maksimum Waspada harus berada pada rentang 1 sampai 4094.';
    }

    if (
        $normalMax !== false
        && $warningMax !== false
        && $normalMax >= $warningMax
    ) {
        $errors[] = 'Batas maksimum Normal harus lebih kecil dari batas maksimum Waspada.';
    }

    if ($errors === []) {
        try {
            saveThresholdConfiguration(
                $database,
                (int) $normalMax,
                (int) $warningMax,
                $thresholds
            );

            header('Location: ambang-batas.php?updated=1');
            exit;
        } catch (Throwable $exception) {
            error_log('Threshold configuration save error: ' . $exception->getMessage());
            $errors[] = 'Ambang batas gagal disimpan. Periksa struktur tabel system_settings.';
        }
    }

    if ($normalMax !== false) {
        $thresholds['normal_max'] = (int) $normalMax;
    }

    if ($warningMax !== false) {
        $thresholds['warning_max'] = (int) $warningMax;
    }
}

$normalMax = (int) $thresholds['normal_max'];
$warningMax = (int) $thresholds['warning_max'];
$warningMin = $normalMax + 1;
$dangerMin = $warningMax + 1;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ambang Batas | Dashboard Admin</title>
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
                <a class="nav-link active" href="ambang-batas.php">Ambang Batas</a>
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
                    <p class="eyebrow">Pengaturan kategori nilai ADC MQ135</p>
                    <h1>Ambang Batas</h1>
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
                <div class="alert alert-success">
                    <?= escapeHtml($successMessage); ?>
                </div>
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

            <section class="threshold-summary-grid" aria-label="Kategori kondisi udara">
                <article class="threshold-card threshold-card-normal">
                    <span class="status-dot status-normal"></span>
                    <div>
                        <span>Normal</span>
                        <strong>0 sampai <?= escapeHtml($normalMax); ?></strong>
                    </div>
                </article>

                <article class="threshold-card threshold-card-warning">
                    <span class="status-dot status-warning"></span>
                    <div>
                        <span>Waspada</span>
                        <strong><?= escapeHtml($warningMin); ?> sampai <?= escapeHtml($warningMax); ?></strong>
                    </div>
                </article>

                <article class="threshold-card threshold-card-danger">
                    <span class="status-dot status-danger"></span>
                    <div>
                        <span>Bahaya</span>
                        <strong><?= escapeHtml($dangerMin); ?> sampai 4095</strong>
                    </div>
                </article>
            </section>

            <section class="threshold-layout">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Ubah Ambang Batas</h2>
                            <p>Masukkan dua batas maksimum. Rentang lainnya dihitung otomatis.</p>
                        </div>
                    </div>

                    <form class="admin-form" method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()); ?>">

                        <div class="form-grid threshold-form-grid">
                            <label>
                                Batas maksimum Normal
                                <input
                                    type="number"
                                    name="normal_max"
                                    min="0"
                                    max="4093"
                                    value="<?= escapeHtml($normalMax); ?>"
                                    required
                                >
                                <small>Nilai Normal dimulai dari 0.</small>
                            </label>

                            <label>
                                Batas maksimum Waspada
                                <input
                                    type="number"
                                    name="warning_max"
                                    min="1"
                                    max="4094"
                                    value="<?= escapeHtml($warningMax); ?>"
                                    required
                                >
                                <small>Nilai Bahaya dimulai satu angka setelah batas ini.</small>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button class="button button-primary" type="submit">
                                Simpan Perubahan
                            </button>
                            <a class="button button-secondary" href="ambang-batas.php">
                                Batal
                            </a>
                        </div>
                    </form>
                </article>

                <aside class="panel threshold-note">
                    <div class="panel-header">
                        <div>
                            <h2>Ketentuan Nilai</h2>
                            <p>Validasi untuk rentang ADC ESP32.</p>
                        </div>
                    </div>

                    <ul>
                        <li>Rentang pembacaan ADC adalah 0 sampai 4095.</li>
                        <li>Batas Normal harus lebih kecil dari batas Waspada.</li>
                        <li>Setiap nilai hanya masuk ke satu kategori.</li>
                        <li>Nilai yang disimpan bukan AQI atau ppm.</li>
                    </ul>

                    <div class="setting-key-box">
                        <span>Kunci pengaturan aktif</span>
                        <code><?= escapeHtml($thresholds['normal_key']); ?></code>
                        <code><?= escapeHtml($thresholds['warning_key']); ?></code>
                    </div>
                </aside>
            </section>

            <div class="integration-notice">
                Perubahan disimpan pada tabel <code>system_settings</code>. Proses penentuan
                status pada server atau perangkat harus membaca pengaturan ini agar perubahan
                diterapkan pada data baru.
            </div>
        </main>
    </div>
</body>
</html>
