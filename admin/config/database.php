<?php

declare(strict_types=1);

// Satu sumber koneksi DB: API, dashboard publik, dan admin.
// File konfigurasi utama telah menggunakan kredensial privat hosting.
require_once __DIR__ . '/../../config/database.php';

function getDatabaseConnection(): PDO
{
    return db();
}
