<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/air-quality.php';
$key = getenv('AQM_API_KEY') ?: '';
if ($key === '' || !hash_equals($key, (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
    http_response_code(401); echo json_encode(['success'=>false]); exit;
}
try {
    echo json_encode(['success'=>true,'thresholds'=>airThresholds(db()),'unit'=>'ppm','basis'=>'internal - bukan ISPU']);
} catch (Throwable $e) {
    error_log($e->getMessage()); http_response_code(500);
    echo json_encode(['success'=>false]);
}
