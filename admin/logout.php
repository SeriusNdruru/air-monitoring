<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode tidak diizinkan.');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParameters = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $cookieParameters['path'],
            'domain' => $cookieParameters['domain'],
            'secure' => (bool) $cookieParameters['secure'],
            'httponly' => (bool) $cookieParameters['httponly'],
            'samesite' => 'Lax',
        ]
    );
}

session_destroy();

header('Location: login.php');
exit;