<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$status = false;
$message = '';

try {
    $database = getDatabaseConnection();

    $query = $database->query(
        'SELECT DATABASE() AS database_name'
    );

    $result = $query->fetch();

    $status = true;
    $message = sprintf(
        'Koneksi berhasil ke basis data: %s',
        $result['database_name'] ?? 'tidak diketahui'
    );
} catch (Throwable $exception) {
    $message = $exception->getMessage();
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

    <title>Pengujian Koneksi Basis Data</title>

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
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }

        .card {
            width: min(90%, 520px);
            padding: 32px;
            text-align: center;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
        }

        .status {
            display: inline-block;
            margin-bottom: 16px;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 24px;
        }

        p {
            margin: 0;
            line-height: 1.6;
            color: #64748b;
        }
    </style>
</head>

<body>
    <main class="card">
        <div class="status <?= $status ? 'success' : 'error'; ?>">
            <?= $status ? 'Koneksi berhasil' : 'Koneksi gagal'; ?>
        </div>

        <h1>Pengujian Basis Data</h1>

        <p>
            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </p>
    </main>
</body>
</html>