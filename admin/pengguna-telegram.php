<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/telegram-user-data.php';

requireAdmin();

$database = getDatabaseConnection();
$adminName = adminDisplayName();
$errors = [];
$successMessage = '';
$schema = null;

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatTelegramDateTime(?string $value): string
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

function normalizeTelegramUsername(string $username): string
{
    return ltrim(trim($username), '@');
}

try {
    $schema = getTelegramUserSchema($database);
} catch (Throwable $exception) {
    error_log('Telegram user schema error: ' . $exception->getMessage());
    $errors[] = $exception->getMessage();
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $successMessage = 'Data pengguna Telegram berhasil disimpan.';
}

if (isset($_GET['status']) && $_GET['status'] === '1') {
    $successMessage = 'Status pengguna Telegram berhasil diperbarui.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($schema)) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman dan coba kembali.';
    } else {
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'toggle') {
            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($userId === false) {
                $errors[] = 'Pengguna Telegram tidak valid.';
            } elseif (!is_string($schema['status'] ?? null)) {
                $errors[] = 'Kolom status belum tersedia pada tabel telegram_users.';
            } else {
                try {
                    toggleTelegramUserStatus($database, $schema, (int) $userId);
                    header('Location: pengguna-telegram.php?status=1');
                    exit;
                } catch (Throwable $exception) {
                    error_log('Telegram user status update error: ' . $exception->getMessage());
                    $errors[] = 'Status pengguna Telegram gagal diperbarui.';
                }
            }
        } elseif ($action === 'save') {
            $userIdInput = trim((string) ($_POST['user_id'] ?? ''));
            $userId = null;

            if ($userIdInput !== '') {
                $validatedId = filter_var(
                    $userIdInput,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if ($validatedId === false) {
                    $errors[] = 'Pengguna Telegram tidak valid.';
                } else {
                    $userId = (int) $validatedId;
                }
            }

            $chatId = trim((string) ($_POST['chat_id'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = normalizeTelegramUsername((string) ($_POST['username'] ?? ''));

            if (preg_match('/^-?\d{1,20}$/', $chatId) !== 1) {
                $errors[] = 'Chat ID harus berupa angka dengan panjang maksimal 20 digit.';
            }

            if (is_string($schema['name'] ?? null)) {
                if ($fullName === '' || mb_strlen($fullName) > 100) {
                    $errors[] = 'Nama pengguna wajib diisi dan maksimal 100 karakter.';
                }
            }

            if (
                is_string($schema['username'] ?? null)
                && $username !== ''
                && (
                    mb_strlen($username) > 100
                    || preg_match('/^[A-Za-z0-9_]+$/', $username) !== 1
                )
            ) {
                $errors[] = 'Username Telegram hanya boleh berisi huruf, angka, dan garis bawah.';
            }

            if ($errors === []) {
                try {
                    if (telegramChatIdExists($database, $schema, $chatId, $userId)) {
                        $errors[] = 'Chat ID sudah digunakan oleh pengguna lain.';
                    } else {
                        saveTelegramUser(
                            $database,
                            $schema,
                            $userId,
                            $chatId,
                            $fullName,
                            $username
                        );

                        header('Location: pengguna-telegram.php?saved=1');
                        exit;
                    }
                } catch (Throwable $exception) {
                    error_log('Telegram user save error: ' . $exception->getMessage());
                    $errors[] = 'Data pengguna Telegram gagal disimpan.';
                }
            }
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$users = [];
$editUser = null;

if (is_array($schema)) {
    try {
        $users = getTelegramUsers($database, $schema, $search);
    } catch (Throwable $exception) {
        error_log('Telegram user list error: ' . $exception->getMessage());
        $errors[] = 'Data pengguna Telegram belum dapat ditampilkan.';
    }

    $editId = filter_var(
        $_GET['edit'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId !== false && $editId !== null) {
        try {
            $editUser = findTelegramUserById($database, $schema, (int) $editId);

            if ($editUser === null) {
                $errors[] = 'Pengguna Telegram yang akan diubah tidak ditemukan.';
            }
        } catch (Throwable $exception) {
            error_log('Telegram user find error: ' . $exception->getMessage());
            $errors[] = 'Data pengguna Telegram belum dapat dibuka.';
        }
    }
}

$activeCount = 0;
$inactiveCount = 0;

if (is_array($schema)) {
    foreach ($users as $user) {
        if (telegramStatusIsActive($user['status'] ?? null, $schema)) {
            $activeCount++;
        } else {
            $inactiveCount++;
        }
    }
}

$formUser = is_array($editUser) ? $editUser : [
    'id' => '',
    'chat_id' => '',
    'full_name' => '',
    'username' => '',
    'status' => null,
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengguna Telegram | Dashboard Admin</title>
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
                <a class="nav-link active" href="pengguna-telegram.php">Pengguna Telegram</a>
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
                    <p class="eyebrow">Pengelolaan penerima notifikasi Telegram</p>
                    <h1>Pengguna Telegram</h1>
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

            <section class="summary-grid telegram-summary-grid" aria-label="Ringkasan pengguna Telegram">
                <article class="summary-card">
                    <span class="summary-label">Total Pengguna</span>
                    <strong><?= escapeHtml(count($users)); ?></strong>
                    <small>Penerima terdaftar</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Pengguna Aktif</span>
                    <strong><?= escapeHtml($activeCount); ?></strong>
                    <small>Dapat menerima notifikasi</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Pengguna Nonaktif</span>
                    <strong><?= escapeHtml($inactiveCount); ?></strong>
                    <small>Tidak menerima notifikasi</small>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Tabel Basis Data</span>
                    <strong class="summary-status">telegram_users</strong>
                    <small>Struktur dibaca otomatis</small>
                </article>
            </section>

            <section class="telegram-layout">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Daftar Pengguna Telegram</h2>
                            <p>Kelola akun yang menerima notifikasi perubahan status udara.</p>
                        </div>
                        <span class="record-count"><?= escapeHtml(count($users)); ?> pengguna</span>
                    </div>

                    <form class="filter-bar telegram-filter-bar" method="get" action="pengguna-telegram.php">
                        <label>
                            <span>Cari pengguna</span>
                            <input
                                type="search"
                                name="q"
                                value="<?= escapeHtml($search); ?>"
                                placeholder="Chat ID, nama, atau username"
                            >
                        </label>

                        <div class="filter-actions">
                            <button class="button button-primary" type="submit">Cari</button>
                            <a class="button button-secondary" href="pengguna-telegram.php">Reset</a>
                        </div>
                    </form>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Chat ID</th>
                                    <th>Nama Pengguna</th>
                                    <th>Username</th>
                                    <th>Status</th>
                                    <th>Terdaftar</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users === []): ?>
                                    <tr>
                                        <td colspan="6" class="empty-cell">
                                            Data pengguna Telegram belum tersedia.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $isActive = is_array($schema)
                                            ? telegramStatusIsActive($user['status'] ?? null, $schema)
                                            : true;
                                        ?>
                                        <tr>
                                            <td><code><?= escapeHtml($user['chat_id'] ?? '-'); ?></code></td>
                                            <td><?= escapeHtml(($user['full_name'] ?? '') !== '' ? $user['full_name'] : '-'); ?></td>
                                            <td>
                                                <?= ($user['username'] ?? '') !== ''
                                                    ? '@' . escapeHtml(ltrim((string) $user['username'], '@'))
                                                    : '-'; ?>
                                            </td>
                                            <td>
                                                <span class="<?= $isActive ? 'badge badge-success' : 'badge badge-neutral'; ?>">
                                                    <?= $isActive ? 'Aktif' : 'Nonaktif'; ?>
                                                </span>
                                            </td>
                                            <td><?= escapeHtml(formatTelegramDateTime((string) ($user['created_at'] ?? ''))); ?></td>
                                            <td>
                                                <div class="table-actions-inline">
                                                    <a
                                                        class="table-action"
                                                        href="pengguna-telegram.php?edit=<?= escapeHtml($user['id']); ?>"
                                                    >Ubah</a>

                                                    <?php if (is_string($schema['status'] ?? null)): ?>
                                                        <form method="post" action="pengguna-telegram.php">
                                                            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()); ?>">
                                                            <input type="hidden" name="action" value="toggle">
                                                            <input type="hidden" name="user_id" value="<?= escapeHtml($user['id']); ?>">
                                                            <button
                                                                class="table-action table-action-muted"
                                                                type="submit"
                                                            >
                                                                <?= $isActive ? 'Nonaktifkan' : 'Aktifkan'; ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <aside class="panel telegram-form-panel">
                    <div class="panel-header">
                        <div>
                            <h2><?= is_array($editUser) ? 'Ubah Pengguna' : 'Tambah Pengguna'; ?></h2>
                            <p>Chat ID digunakan sebagai tujuan pengiriman pesan bot.</p>
                        </div>

                        <?php if (is_array($editUser)): ?>
                            <a class="close-link" href="pengguna-telegram.php">Batal</a>
                        <?php endif; ?>
                    </div>

                    <?php if (is_array($schema)): ?>
                        <form class="admin-form" method="post" action="pengguna-telegram.php" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()); ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="user_id" value="<?= escapeHtml($formUser['id'] ?? ''); ?>">

                            <div class="form-grid telegram-form-grid">
                                <label class="form-field-wide">
                                    Chat ID Telegram
                                    <input
                                        type="text"
                                        name="chat_id"
                                        maxlength="20"
                                        inputmode="numeric"
                                        value="<?= escapeHtml($formUser['chat_id'] ?? ''); ?>"
                                        placeholder="Contoh: 123456789"
                                        required
                                    >
                                    <small>Masukkan ID percakapan pengguna atau grup Telegram.</small>
                                </label>

                                <?php if (is_string($schema['name'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        Nama Pengguna
                                        <input
                                            type="text"
                                            name="full_name"
                                            maxlength="100"
                                            value="<?= escapeHtml($formUser['full_name'] ?? ''); ?>"
                                            required
                                        >
                                    </label>
                                <?php else: ?>
                                    <input type="hidden" name="full_name" value="">
                                <?php endif; ?>

                                <?php if (is_string($schema['username'] ?? null)): ?>
                                    <label class="form-field-wide">
                                        Username Telegram
                                        <input
                                            type="text"
                                            name="username"
                                            maxlength="100"
                                            value="<?= escapeHtml($formUser['username'] ?? ''); ?>"
                                            placeholder="Tanpa tanda @"
                                        >
                                    </label>
                                <?php else: ?>
                                    <input type="hidden" name="username" value="">
                                <?php endif; ?>
                            </div>

                            <div class="form-actions">
                                <button class="button button-primary" type="submit">
                                    <?= is_array($editUser) ? 'Simpan Perubahan' : 'Tambah Pengguna'; ?>
                                </button>

                                <?php if (is_array($editUser)): ?>
                                    <a class="button button-secondary" href="pengguna-telegram.php">Batal</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="empty-state">
                            Form belum dapat digunakan karena struktur tabel belum dikenali.
                        </div>
                    <?php endif; ?>

                    <div class="telegram-help-box">
                        <strong>Catatan</strong>
                        <p>
                            Pengguna berstatus aktif dapat dipilih oleh proses notifikasi.
                            Token bot tidak ditampilkan atau disimpan melalui halaman ini.
                        </p>
                    </div>
                </aside>
            </section>
        </main>
    </div>
</body>
</html>
