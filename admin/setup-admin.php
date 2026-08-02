<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

$errors = [];
$success = '';
$databaseReady = true;
$adminExists = false;

try {
    $database = getDatabaseConnection();

    $adminCount = (int) $database
        ->query('SELECT COUNT(*) FROM admin_users')
        ->fetchColumn();

    $adminExists = $adminCount > 0;
} catch (Throwable $exception) {
    $databaseReady = false;
    $errors[] = 'Tabel admin_users atau koneksi basis data belum siap.';
    error_log($exception->getMessage());
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $databaseReady
    && !$adminExists
) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman.';
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation =
        (string) ($_POST['password_confirmation'] ?? '');

    if (
        !preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username)
    ) {
        $errors[] =
            'Username harus terdiri dari 4 sampai 50 karakter.';
    }

    if (
        strlen($fullName) < 3
        || strlen($fullName) > 100
    ) {
        $errors[] =
            'Nama lengkap harus terdiri dari 3 sampai 100 karakter.';
    }

    if (strlen($password) < 10) {
        $errors[] =
            'Kata sandi harus memiliki minimal 10 karakter.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Konfirmasi kata sandi tidak sesuai.';
    }

    if (empty($errors)) {
        try {
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new RuntimeException(
                    'Pembuatan hash kata sandi gagal.'
                );
            }

            $statement = $database->prepare(
                'INSERT INTO admin_users
                    (username, password, full_name, status)
                 VALUES
                    (:username, :password, :full_name, :status)'
            );

            $statement->execute([
                'username' => $username,
                'password' => $passwordHash,
                'full_name' => $fullName,
                'status' => 'active',
            ]);

            $success =
                'Akun admin berhasil dibuat. '
                . 'Hapus file setup-admin.php setelah login berhasil.';

            $adminExists = true;
        } catch (PDOException $exception) {
            error_log($exception->getMessage());

            if ((string) $exception->getCode() === '23000') {
                $errors[] = 'Username sudah digunakan.';
            } else {
                $errors[] = 'Akun admin gagal disimpan.';
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $errors[] = 'Terjadi kesalahan saat membuat akun.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Setup Admin</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .card {
            width: min(100%, 460px);
            padding: 30px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.1);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 26px;
        }

        .description {
            margin: 0 0 24px;
            color: #64748b;
            line-height: 1.6;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
        }

        input {
            width: 100%;
            margin-bottom: 18px;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #bfdbfe;
            border-color: #2563eb;
        }

        button,
        .login-link {
            display: block;
            width: 100%;
            padding: 13px;
            border: 0;
            border-radius: 9px;
            text-align: center;
            font-size: 15px;
            font-weight: bold;
            text-decoration: none;
        }

        button {
            cursor: pointer;
            background: #2563eb;
            color: #ffffff;
        }

        .login-link {
            background: #2563eb;
            color: #ffffff;
        }

        .alert {
            margin-bottom: 18px;
            padding: 13px;
            border-radius: 9px;
            line-height: 1.5;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        ul {
            margin: 0;
            padding-left: 20px;
        }
    </style>
</head>

<body>
    <main class="card">
        <h1>Setup Akun Admin</h1>

        <p class="description">
            Buat akun pertama untuk mengakses dashboard admin.
        </p>

        <?php if (!empty($errors)): ?>
            <div class="alert error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li>
                            <?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert success">
                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </div>

            <a class="login-link" href="login.php">
                Buka Halaman Login
            </a>

        <?php elseif ($adminExists): ?>
            <div class="alert success">
                Akun admin sudah tersedia. Halaman setup tidak dapat
                digunakan kembali.
            </div>

            <a class="login-link" href="login.php">
                Buka Halaman Login
            </a>

        <?php elseif ($databaseReady): ?>
            <form method="post" autocomplete="off">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        csrfToken(),
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                >

                <label for="full_name">Nama Lengkap</label>
                <input
                    id="full_name"
                    name="full_name"
                    type="text"
                    maxlength="100"
                    required
                >

                <label for="username">Username</label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    maxlength="50"
                    autocomplete="username"
                    required
                >

                <label for="password">Kata Sandi</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    minlength="10"
                    autocomplete="new-password"
                    required
                >

                <label for="password_confirmation">
                    Konfirmasi Kata Sandi
                </label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    minlength="10"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">
                    Buat Akun Admin
                </button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>