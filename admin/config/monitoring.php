<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi Endpoint Monitoring
|--------------------------------------------------------------------------
| Salin file ini menjadi monitoring.php, lalu isi nilainya.
| Jangan unggah isi token atau API key ke repositori publik.
*/

const MONITORING_API_KEY = '72c0a7d0139cb770890528d80b523adf3e57c80ed3e2cfe69ea043f9a1454f79';
const TELEGRAM_BOT_TOKEN = '8707149342:AAEBG-E3AjSz-kV6VzIlbEPJCEXnalNfXN8';

/* Nilai status perangkat. Kosongkan jika kolom status tidak ingin diperbarui. */
const DEVICE_ACTIVE_VALUE = 'active';

/* Notifikasi pertama biasanya dimatikan agar bot tidak mengirim saat data awal masuk. */
const NOTIFY_ON_FIRST_READING = false;

/* Batas waktu koneksi Telegram dalam detik. */
const TELEGRAM_CONNECT_TIMEOUT = 8;
const TELEGRAM_REQUEST_TIMEOUT = 15;
