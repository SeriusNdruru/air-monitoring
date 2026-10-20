<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/air-quality.php';
require_once __DIR__ . '/../config/telegram.php';
require_once __DIR__ . '/../admin/includes/telegram-service.php';

function reply(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['success'=>false,'message'=>'Gunakan POST.']);
$key = getenv('AQM_API_KEY') ?: '';
$provided = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($key === '' || $provided === '' || !hash_equals($key,$provided)) reply(401, ['success'=>false,'message'=>'API key tidak valid atau belum dikonfigurasi.']);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 4096 || trim($raw)==='') reply(400, ['success'=>false,'message'=>'Payload kosong/terlalu besar.']);
$data = json_decode($raw,true);
if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) reply(400, ['success'=>false,'message'=>'JSON tidak valid.']);
$deviceCode = trim((string) ($data['device_code'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $deviceCode)) reply(422, ['success'=>false,'message'=>'device_code tidak valid.']);
$rawInput = $data['mq135_raw'] ?? null;
$adc = filter_var($rawInput,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>4095]]);
if ($adc === false) reply(422, ['success'=>false,'message'=>'mq135_raw harus bilangan 0–4095.']);
if (!array_key_exists('mq135_ppm', $data)) reply(422, ['success'=>false,'message'=>'Field mq135_ppm wajib disertakan (gunakan null jika belum kalibrasi).']);
try { $ppm = parsePpm($data['mq135_ppm']); }
catch (InvalidArgumentException $e) { reply(422,['success'=>false,'message'=>$e->getMessage()]); }
// ESP32 mengirim status sebagai informasi; server adalah sumber kebenaran kategori.
$clientStatus = isset($data['status']) ? trim((string)$data['status']) : '';
if ($clientStatus === '' || strlen($clientStatus) > 40) reply(422,['success'=>false,'message'=>'status wajib diisi.']);
try {
    $pdo = db();
    $thresholds = airThresholds($pdo);
    $status = airCategory($ppm, $thresholds);
    // Tolak drift konfigurasi perangkat vs server agar LCD dan dashboard tidak diam-diam berbeda.
    // Firmware menandai semua hasil kalibrasi sebagai estimasi, dan API tidak menganggapnya ISPU.
    $find = $pdo->prepare('SELECT id,device_name,device_code,location FROM devices WHERE device_code=:code LIMIT 1');
    $find->execute(['code'=>$deviceCode]);
    $device = $find->fetch(PDO::FETCH_ASSOC);
    if (!$device) reply(404,['success'=>false,'message'=>'Perangkat tidak ditemukan.']);
    $id = (int)$device['id'];
    $pdo->beginTransaction();
    $last = $pdo->prepare('SELECT air_status, mq135_ppm FROM sensor_readings WHERE device_id=:id ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $last->execute(['id'=>$id]);
    $previous = $last->fetch(PDO::FETCH_ASSOC) ?: null;
    $oldStatus = ($previous !== null && $previous['mq135_ppm'] !== null) ? (string)$previous['air_status'] : null;
    $ins=$pdo->prepare('INSERT INTO sensor_readings (device_id,mq135_raw,mq135_ppm,air_status,recorded_at) VALUES (:id,:raw,:ppm,:status,NOW())');
    $ins->execute(['id'=>$id,'raw'=>$adc,'ppm'=>$ppm,'status'=>$status]);
    $readingId=(int)$pdo->lastInsertId();
    $upd=$pdo->prepare("UPDATE devices SET status='online',last_seen=NOW() WHERE id=:id");
    $upd->execute(['id'=>$id]);
    $pdo->commit();
    $changed = $ppm !== null && $oldStatus !== null && $oldStatus !== $status;
    $notified = false;
    if ($changed) {
        $message = "PERUBAHAN STATUS UDARA (ESTIMASI)\n\n"
            . "Perangkat: {$device['device_name']} ({$deviceCode})\n"
            . "Lokasi: {$device['location']}\n"
            . "Estimasi CO2 ekuivalen: " . number_format($ppm,2,'.','') . " ppm\n"
            . "Status sebelumnya: {$oldStatus}\nStatus terbaru: {$status}\n"
            . "Ambang internal: Waspada >= {$thresholds['warning_start_ppm']} ppm; Bahaya >= {$thresholds['danger_start_ppm']} ppm\n"
            . "Catatan: bukan nilai ISPU resmi; MQ-135 tidak spesifik CO2.\n"
            . "Waktu: " . date('d-m-Y H:i:s') . " WIB";
        // Notifikasi ke semua subscriber aktif; kegagalan tidak membatalkan data.
        try {
            $recipients = $pdo->query('SELECT id,chat_id FROM telegram_users WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC);
            $log = $pdo->prepare("INSERT INTO notification_logs (sensor_reading_id,telegram_user_id,message,notification_type,delivery_status,error_message,sent_at) VALUES (:reading,:recipient,:message,'status_change',:delivery,:error,:sent_at)");
            foreach ($recipients as $recipient) {
                $result = sendTelegramText((string)$recipient['chat_id'], $message);
                $sent = (bool)($result['success'] ?? false);
                $notified = $notified || $sent;
                $log->execute(['reading'=>$readingId,'recipient'=>(int)$recipient['id'],'message'=>$message,
                    'delivery'=>$sent?'sent':'failed','error'=>$sent?null:(string)($result['error']??'Gagal'),
                    'sent_at'=>$sent?date('Y-m-d H:i:s'):null]);
            }
        } catch (Throwable $notificationError) {
            error_log('[telegram] '.$notificationError->getMessage());
        }
    }
    reply(201,['success'=>true,'data'=>['reading_id'=>$readingId,'mq135_raw'=>$adc,
        'mq135_ppm'=>$ppm,'air_status'=>$status,'status'=>$status,
        'status_mismatch'=>$clientStatus!==$status,
        'thresholds'=>$thresholds,'telegram_sent'=>$notified]]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[receive-data] '.$e->getMessage());
    reply(500,['success'=>false,'message'=>'Gagal menyimpan pembacaan. Periksa migrasi atau konfigurasi server.']);
}
