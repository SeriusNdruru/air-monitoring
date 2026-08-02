<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

redirectLoggedAdmin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Username dan kata sandi wajib diisi.';
        } else {
            try {
                $database = getDatabaseConnection();

                $statement = $database->prepare(
                    'SELECT
                        id,
                        username,
                        password,
                        full_name,
                        status
                     FROM admin_users
                     WHERE username = :username
                     LIMIT 1'
                );

                $statement->execute([
                    'username' => $username,
                ]);

                $admin = $statement->fetch();

                $loginValid =
                    is_array($admin)
                    && ($admin['status'] ?? '') === 'active'
                    && password_verify(
                        $password,
                        (string) ($admin['password'] ?? '')
                    );

                if ($loginValid) {
                    session_regenerate_id(false);

                    $_SESSION['admin_id'] = (int) $admin['id'];
                    $_SESSION['admin_username'] =
                        (string) $admin['username'];
                    $_SESSION['admin_full_name'] =
                        (string) $admin['full_name'];
                    $_SESSION['admin_login_time'] = time();

                    if (
                        password_needs_rehash(
                            (string) $admin['password'],
                            PASSWORD_DEFAULT
                        )
                    ) {
                        $newPasswordHash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        if ($newPasswordHash !== false) {
                            $rehashStatement = $database->prepare(
                                'UPDATE admin_users
                                 SET password = :password
                                 WHERE id = :id'
                            );

                            $rehashStatement->execute([
                                'password' => $newPasswordHash,
                                'id' => (int) $admin['id'],
                            ]);
                        }
                    }

                    $updateStatement = $database->prepare(
                        'UPDATE admin_users
                         SET last_login = NOW()
                         WHERE id = :id'
                    );

                    $updateStatement->execute([
                        'id' => (int) $admin['id'],
                    ]);

                    header('Location: index.php');
                    exit;
                }

                $error = 'Username atau kata sandi tidak sesuai.';
            } catch (Throwable $exception) {
                error_log($exception->getMessage());
                $error = 'Login gagal. Silakan coba kembali.';
            }
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

    <title>Login Dashboard Admin</title>
	<link
        rel="icon"
        type="image/png"
        href="/assets/images/AQM.png?v=1"
    >

    <link
        rel="apple-touch-icon"
        href="/assets/images/AQM.png?v=1"
    >
	
	<link
		rel="stylesheet"
		href="assets/css/admin.css?v=20260728-3"
	>
	<style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background:
                linear-gradient(135deg, #eff6ff, #f8fafc);
            color: #1e293b;
        }

        .login-card {
            width: min(100%, 430px);
            padding: 34px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 15px 40px rgba(15, 23, 42, 0.11);
        }

        .logo {
            width: 58px;
            height: 58px;
            display: grid;
            place-items: center;
            margin-bottom: 18px;
            border-radius: 15px;
            background: #2563eb;
            color: #ffffff;
            font-weight: bold;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 26px;
			text-align: center !important;
        }

        .description {
            margin: 0 0 25px;
            color: #64748b;
            line-height: 1.5;
			text-align: center !important;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        input {
            width: 100%;
            margin-bottom: 19px;
            padding: 13px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #bfdbfe;
            border-color: #2563eb;
        }

        button {
            width: 100%;
            padding: 13px;
            cursor: pointer;
            border: 0;
            border-radius: 9px;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: bold;
        }

        .error {
            margin-bottom: 18px;
            padding: 13px;
            border-radius: 9px;
            background: #fee2e2;
            color: #991b1b;
            line-height: 1.5;
        }
    </style>
</head>

<body>
    <main class="login-card">
        <img
			src="/assets/images/AQM.png?v=1"
			alt="Logo AQM"
			class="login-logo"
		>
        <h1>Login Admin</h1>

        <p class="description">
            Masuk untuk mengelola
			<br>sistem monitoring kualitas udara.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    csrfToken(),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>"
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
                autocomplete="current-password"
                required
            >

            <button type="submit">
                Masuk ke Dashboard
            </button>
        </form>
    </main>
</body>
</html>