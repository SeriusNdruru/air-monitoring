<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/air-quality.php';
const NORMAL_THRESHOLD_KEY='mq135_warning_start_ppm';
const WARNING_THRESHOLD_KEY='mq135_danger_start_ppm';
function getThresholdConfiguration(PDO $database): array {
    $c=airThresholds($database);
    return ['normal_max'=>$c['warning_start_ppm'], 'warning_max'=>$c['danger_start_ppm'],
        'normal_key'=>NORMAL_THRESHOLD_KEY,'warning_key'=>WARNING_THRESHOLD_KEY];
}
function saveThresholdConfiguration(PDO $database, float $normalMax, float $warningMax, array $currentConfiguration): void {
    if (!is_finite($normalMax) || !is_finite($warningMax) || $normalMax<=0 || $warningMax<=$normalMax || $warningMax>100000) {
        throw new InvalidArgumentException('Batas PPM harus positif dan meningkat.');
    }
    $database->beginTransaction();
    try {
        foreach ([NORMAL_THRESHOLD_KEY=>$normalMax,WARNING_THRESHOLD_KEY=>$warningMax] as $k=>$v) {
            $statement=$database->prepare('INSERT INTO system_settings (setting_key,setting_value) VALUES (:k,:v) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $statement->execute(['k'=>$k,'v'=>(string)$v]);
        }
        $database->commit();
    } catch (Throwable $e) { if ($database->inTransaction()) $database->rollBack(); throw $e; }
}
