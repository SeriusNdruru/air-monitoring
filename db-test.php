<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config/database.php';

    $pdo = db();

    $result = $pdo->query('SELECT 1 AS hasil')->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Koneksi database berhasil.',
        'data' => $result
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal.',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}