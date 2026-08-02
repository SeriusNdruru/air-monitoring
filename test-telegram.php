<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/telegram-helper.php';

$result = sendTelegramMessage(
    'Tes notifikasi sistem monitoring kualitas udara.'
);

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_PRETTY_PRINT
);